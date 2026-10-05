<?php

declare(strict_types=1);

namespace Uniundata\Books\Rest;

use Uniundata\Books\Domain\DomainError;
use Uniundata\Books\Infrastructure\AuditLog;
use Uniundata\Books\Payment\WebhookResult;
use Uniundata\Books\Service\PaymentService;

/**
 * POST /payment/webhook — серверный callback банка. Единственный источник факта оплаты (второй путь к тому
 * же PaymentService::applyProviderResult() — опрос банка cron-ом).
 *
 * Аутентификация — подпись банка, а не WordPress:
 *  - у банка нет WordPress-сессии и nonce; cookie он не присылает, get_current_user_id() = 0 и не используется;
 *  - permission_callback (permitWebhook) делает только дешёвые транспортные проверки без побочных
 *    эффектов: необязательный IP allowlist приложения (константа UNIUNDATA_WEBHOOK_ALLOWED_IPS);
 *  - криптографическая проверка подписи — первый шаг PaymentService::handleWebhook() в callback, ДО любой
 *    записи в БД. Почему не в permission_callback: verifyWebhook() одновременно проверяет и разбирает событие
 *    (одна проверка, один разбор), а коды ответа банку (401 подпись, 400 тело, 413 размер, 5xx «повторите»)
 *    задаёт единая политика WebhookResult.
 *
 * Подпись считается по СЫРОМУ телу $request->get_body(): get_json_params() — уже пересобранный массив.
 * Если банк кладёт подпись в заголовок Authorization, на Apache + PHP-FPM он доходит до PHP только при
 * `RewriteRule .* - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]` или `CGIPassAuth On` (docs/06 § 6.5.4).
 *
 * Отклонённые доставки (401/400/413) считаются по IP клиента в транзиентах (RestController::bumpCounter —
 * работает и без постоянного объектного кэша): после DEFAULT_RATE_LIMITS['webhook_rejected'] за окно
 * следующие запросы с этого IP получают 429 без проверки подписи и без записи в БД/аудит. Принятые
 * доставки не считаются, поэтому банк с верной подписью лимит не исчерпывает. При включённом allowlist
 * лимит не применяется: чужие IP и так получают 403.
 *
 * Коды ответа (банки повторяют доставку при любом не-2xx):
 *   200 — обработано, дубль (provider_event_id уже processed) или событие не требует действий;
 *   400 — подпись верна, но тело не разбирается (повтор бессмыслен, банк увидит ошибку);
 *   401 — подпись/timestamp не прошли; в БД ничего не записано;
 *   403 — IP не из allowlist (если allowlist включён);
 *   413 — тело больше 64 КБ;
 *   429 — с этого IP слишком много отклонённых доставок (Retry-After);
 *   500/503 — временная ошибка (deadlock после повторов, API банка недоступно): событие помечено failed,
 *             банк повторит, обработка продолжится с того же места.
 */
final class PaymentWebhookController extends RestController
{
    public function __construct(AuditLog $audit, private readonly PaymentService $payments)
    {
        parent::__construct($audit);
    }

    public function register_routes(): void
    {
        $this->addCommonHooks();

        register_rest_route(self::NAMESPACE, '/payment/webhook', [
            'methods' => \WP_REST_Server::CREATABLE,
            'callback' => [$this, 'handle'],
            'permission_callback' => [$this, 'permitWebhook'],
            // Тело — формат банка; его поля проверяются адаптером ПОСЛЕ подписи, поэтому args пусты.
            'args' => [],
            'show_in_index' => false,
        ]);
    }

    /**
     * Транспортный фильтр без побочных эффектов. Срабатывает после разрешения маршрута WordPress-ом, поэтому
     * закрывает все варианты URL (/wp-json/…, ?rest_route=…). Allowlist на Apache — дополнительный слой.
     */
    public function permitWebhook(\WP_REST_Request $request): bool|\WP_Error
    {
        $networks = self::allowedNetworks();
        if ($networks === []) {
            return true; // allowlist выключен: аутентичность обеспечивает подпись в callback
        }
        $ip = $this->clientIp();
        foreach ($networks as $cidr) {
            if ($ip !== null && self::ipInCidr($ip, $cidr)) {
                return true;
            }
        }
        error_log(\sprintf('[uniundata] request_id=%s webhook rejected: ip %s not in allowlist', $this->audit->requestId(), $ip ?? '-'));

        return DomainError::forbidden()->toWpError();
    }

    public function handle(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        $started = microtime(true);
        $limit = $this->rejectLimit();
        if ($limit !== null && $this->readCounter($limit['subject'], $limit['window']) >= $limit['max']) {
            $retryAfter = self::secondsToWindowEnd($limit['window']);
            error_log(\sprintf('[uniundata] request_id=%s webhook outcome=rate_limited http=429 ip=%s', $this->audit->requestId(), $this->clientIp() ?? '-'));

            return DomainError::rateLimited($retryAfter)->toWpError(); // Retry-After ставит rest_post_dispatch
        }

        try {
            $result = $this->payments->handleWebhook($request->get_body(), $request->get_headers());
        } catch (\Throwable $e) {
            // Не 2xx: банк повторит доставку, а inbox (UNIQUE provider + provider_event_id) не даст применить дважды.
            $this->logThrowable($request, $e, 'webhook_unhandled');

            return new \WP_REST_Response(['received' => false], 500);
        }

        $this->logResult($result, $started);
        if ($limit !== null && \in_array($result->outcome, [WebhookResult::OUTCOME_INVALID_SIGNATURE, WebhookResult::OUTCOME_BAD_REQUEST], true)) {
            $this->bumpCounter($limit['subject'], $limit['window']);
        }

        if ($result->httpStatus === 401) {
            return DomainError::invalidSignature()->toWpError();
        }
        $response = new \WP_REST_Response($result->responseBody(), $result->httpStatus);
        if ($result->outcome === WebhookResult::OUTCOME_RETRY) {
            $response->header('Retry-After', '30');
        }

        return $response;
    }

    /**
     * Лимит отклонённых доставок с IP клиента; null — не применяется (включён allowlist, IP неизвестен
     * или лимит отключён фильтром uniundata_rate_limits).
     *
     * @return array{subject: string, max: int, window: int}|null
     */
    private function rejectLimit(): ?array
    {
        $ip = $this->clientIp();
        $ipLimit = self::rateLimits('webhook_rejected')['ip'] ?? null;
        if ($ip === null || $ipLimit === null || self::allowedNetworks() !== []) {
            return null;
        }

        return ['subject' => 'webhook_rejected|ip|' . $ip, 'max' => $ipLimit[0], 'window' => $ipLimit[1]];
    }

    /**
     * Одна строка на доставку: корреляция, исход, ID события в inbox, длительность, IP отправителя (IP банка — не
     * персональные данные покупателя). НЕ пишем: тело, заголовки подписи, секреты, данные карты, e-mail.
     */
    private function logResult(WebhookResult $result, float $started): void
    {
        error_log(\sprintf(
            '[uniundata] request_id=%s webhook outcome=%s http=%d event_id=%s ms=%d ip=%s%s',
            $this->audit->requestId(),
            $result->outcome,
            $result->httpStatus,
            $result->eventId !== null ? (string) $result->eventId : '-',
            (int) round((microtime(true) - $started) * 1000),
            $this->clientIp() ?? '-',
            $result->note !== '' ? ' note="' . addcslashes(mb_substr($result->note, 0, 200), "\"\\\r\n") . '"' : '',
        ));
    }

    /** @return list<string> IP/CIDR из UNIUNDATA_WEBHOOK_ALLOWED_IPS ("203.0.113.0/24, 2001:db8::/32"). */
    private static function allowedNetworks(): array
    {
        if (!\defined('UNIUNDATA_WEBHOOK_ALLOWED_IPS')) {
            return [];
        }
        $raw = \constant('UNIUNDATA_WEBHOOK_ALLOWED_IPS');

        return \is_string($raw) ? array_values(array_filter(array_map('trim', explode(',', $raw)), 'strlen')) : [];
    }

    /** IPv4/IPv6 в сети CIDR (или равен адресу без маски). */
    public static function ipInCidr(string $ip, string $cidr): bool
    {
        [$network, $bits] = str_contains($cidr, '/') ? explode('/', $cidr, 2) : [$cidr, null];
        if (filter_var($ip, FILTER_VALIDATE_IP) === false || filter_var($network, FILTER_VALIDATE_IP) === false) {
            return false;
        }
        $ipBin = inet_pton($ip);
        $netBin = inet_pton($network);
        if ($ipBin === false || $netBin === false || \strlen($ipBin) !== \strlen($netBin)) {
            return false; // IPv4 против IPv6
        }
        $maxBits = \strlen($ipBin) * 8;
        if ($bits !== null && !ctype_digit($bits)) {
            return false;
        }
        $prefix = $bits === null ? $maxBits : (int) $bits;
        if ($prefix > $maxBits) {
            return false;
        }

        $fullBytes = intdiv($prefix, 8);
        if (strncmp($ipBin, $netBin, $fullBytes) !== 0) {
            return false;
        }
        $restBits = $prefix % 8;
        if ($restBits === 0) {
            return true;
        }
        $mask = (0xFF << (8 - $restBits)) & 0xFF;

        return (\ord($ipBin[$fullBytes]) & $mask) === (\ord($netBin[$fullBytes]) & $mask);
    }
}
