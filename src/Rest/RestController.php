<?php

declare(strict_types=1);

namespace Uniundata\Books\Rest;

use Uniundata\Books\Domain\DomainError;
use Uniundata\Books\Infrastructure\AuditLog;

/**
 * Общая база REST-контроллеров плагина (namespace `uniundata/v1`).
 *
 * Что делает база:
 *  - respond(): единый перевод исключений в ответ. DomainError → WP_Error с кодом из контракта
 *    (`{code, message, data: {status, …}}`), любое другое исключение → 500 `uniundata_internal`.
 *    Текст исключения клиенту не отдаётся: он может содержать SQL, значения строк или ответ банка;
 *  - для всех ответов нашего namespace (и ошибок самого WordPress: 401/403 из permission_callback,
 *    400 rest_invalid_param, 404 rest_no_route) фильтр `rest_post_dispatch` ставит
 *    `Cache-Control: no-store`, `X-Request-Id`, добавляет `data.request_id` в тело ошибки и
 *    `Retry-After` для 429/503;
 *  - permission_callback для пользовательских маршрутов: 401 `uniundata_auth_required`, если
 *    пользователь не определён (в том числе cookie без `X-WP-Nonce`), 403 `uniundata_forbidden`, если
 *    нет capability;
 *  - rate limit по user_id и IP: счётчики в транзиентах (работают без постоянного объектного кэша —
 *    тогда это строки wp_options), при Redis/Memcached — атомарный wp_cache_incr.
 *
 * user_id берётся только из get_current_user_id(), никогда из параметров запроса.
 */
abstract class RestController
{
    public const NAMESPACE = 'uniundata/v1';

    /**
     * Верхняя граница ID в args. 2^53 − 1 — наибольшее целое, которое JavaScript передаёт без потери
     * точности; BIGINT UNSIGNED в БД шире, но таких ID у магазина не будет.
     */
    public const MAX_ID = 9007199254740991;

    /**
     * Лимиты по умолчанию: действие => ['user' => [запросов, окно в секундах], 'ip' => [...]].
     * Меняются фильтром `uniundata_rate_limits`. Лимит — защита от перебора и случайных циклов в JS;
     * корректность (один резерв на экземпляр, лимит 3 попыток) обеспечивают транзакции и UNIQUE.
     */
    public const DEFAULT_RATE_LIMITS = [
        'reserve' => ['user' => [20, 60], 'ip' => [60, 60]],
        'remove_item' => ['user' => [30, 60], 'ip' => [90, 60]],
        'checkout' => ['user' => [5, 60], 'ip' => [20, 60]],
        'pay' => ['user' => [10, 60], 'ip' => [30, 60]],
        'cancel' => ['user' => [10, 60], 'ip' => [30, 60]],
        // Публичный endpoint: за NAT офиса/кампуса много покупателей делят один IP — лимит щедрый.
        'availability' => ['ip' => [300, 60]],
        'admin_write' => ['user' => [60, 60]],
        // Webhook: считаются только ОТКЛОНЁННЫЕ доставки (подпись/тело) с IP; принятые не считаются.
        'webhook_rejected' => ['ip' => [20, 60]],
    ];

    private const RATE_LIMIT_GROUP = 'uniundata_rl';
    private const NO_STORE = 'no-store, no-cache, must-revalidate, max-age=0, private';

    private static bool $commonHooksAdded = false;

    public function __construct(protected readonly AuditLog $audit)
    {
    }

    /** Вызывается на `rest_api_init`. Каждый контроллер регистрирует только свои маршруты. */
    abstract public function register_routes(): void;

    // =============================================================================================
    // Общие хуки ответа
    // =============================================================================================

    /** Один раз на запрос: заголовки no-store / X-Request-Id и request_id в теле ошибок. */
    protected function addCommonHooks(): void
    {
        if (self::$commonHooksAdded) {
            return;
        }
        self::$commonHooksAdded = true;

        $requestId = $this->audit->requestId();
        add_filter(
            'rest_post_dispatch',
            static fn (mixed $response, mixed $server, mixed $request): mixed => $request instanceof \WP_REST_Request
                ? self::decorateResponse($response, $request, $requestId)
                : $response,
            10,
            3,
        );
    }

    /**
     * @internal Публичный только ради фильтра. Работает для любых ответов нашего namespace, включая
     *           ошибки, которые WordPress формирует сам (permission_callback, валидация args, 404).
     */
    public static function decorateResponse(mixed $response, \WP_REST_Request $request, string $requestId): mixed
    {
        if (!$response instanceof \WP_HTTP_Response || !self::isOwnRoute($request->get_route())) {
            return $response;
        }

        // Ответы пользовательские или «живые» (статус экземпляра): ни браузер, ни CDN, ни page cache
        // не должны их хранить. WordPress сам шлёт no-cache только залогиненным (rest_send_nocache_headers),
        // а availability читают и гости.
        foreach (wp_get_nocache_headers() as $name => $value) {
            if (\is_string($value) && $value !== '') {
                $response->header($name, $value);
            }
        }
        $response->header('Cache-Control', self::NO_STORE);
        $response->header('X-Request-Id', $requestId);

        $status = $response->get_status();
        $data = $response->get_data();
        if ($status >= 400 && \is_array($data) && isset($data['code'])) {
            $errorData = isset($data['data']) && \is_array($data['data']) ? $data['data'] : ['status' => $status];
            $errorData['request_id'] = $requestId;
            $data['data'] = $errorData;
            $response->set_data($data);

            if (($status === 429 || $status === 503) && isset($errorData['retry_after']) && \is_int($errorData['retry_after'])) {
                $response->header('Retry-After', (string) max(1, $errorData['retry_after']));
            }
        }

        return $response;
    }

    private static function isOwnRoute(string $route): bool
    {
        return $route === '/' . self::NAMESPACE || str_starts_with($route, '/' . self::NAMESPACE . '/');
    }

    // =============================================================================================
    // Выполнение callback и ошибки
    // =============================================================================================

    /**
     * Обёртка callback-а: DomainError → WP_Error (код и HTTP-статус из контракта), прочее → 500.
     *
     * @param callable(): (\WP_REST_Response|array<string, mixed>) $fn
     */
    protected function respond(\WP_REST_Request $request, callable $fn): \WP_REST_Response|\WP_Error
    {
        try {
            $result = $fn();

            return $result instanceof \WP_REST_Response ? $result : new \WP_REST_Response($result, 200);
        } catch (DomainError $e) {
            if ($e->httpStatus() >= 500) {
                // 502/503: причина (deadlock, банк) — в лог; клиенту — только код контракта.
                $this->logThrowable($request, $e->getPrevious() ?? $e, $e->errorCode());
            }

            return $e->toWpError();
        } catch (\Throwable $e) {
            $this->logThrowable($request, $e, 'uniundata_internal');

            return DomainError::internal()->toWpError();
        }
    }

    /**
     * Строка в error_log без PII: класс, код, файл:строка. Текст исключения — только при WP_DEBUG:
     * сообщения MySQL содержат значения строк («Duplicate entry '…'»), ответы банка — данные платежа.
     */
    protected function logThrowable(\WP_REST_Request $request, \Throwable $e, string $code): void
    {
        $line = \sprintf(
            '[uniundata] request_id=%s user=%d %s %s -> %s: %s(code %d) at %s:%d',
            $this->audit->requestId(),
            get_current_user_id(),
            $request->get_method(),
            $request->get_route(),
            $code,
            $e::class,
            (int) $e->getCode(),
            basename($e->getFile()),
            $e->getLine(),
        );
        if (\defined('WP_DEBUG') && WP_DEBUG) {
            $line .= ' - ' . mb_substr($e->getMessage(), 0, 500);
        }
        error_log($line);
    }

    /** @param array<string, mixed> $data */
    protected function created(array $data, ?string $location = null): \WP_REST_Response
    {
        $response = new \WP_REST_Response($data, 201);
        if ($location !== null) {
            $response->header('Location', $location);
        }

        return $response;
    }

    // =============================================================================================
    // Права
    // =============================================================================================

    /**
     * permission_callback для маршрутов, где нужна capability.
     *
     * @return \Closure(\WP_REST_Request): (bool|\WP_Error)
     */
    protected function requireCapability(string $capability): \Closure
    {
        return fn (\WP_REST_Request $request): bool|\WP_Error => $this->checkCapability($capability);
    }

    /** permission_callback «только вход»: права на объект проверяются в callback (например, чужой заказ → 404). */
    public function requireLogin(\WP_REST_Request $request): bool|\WP_Error
    {
        return is_user_logged_in() ? true : $this->authRequiredError();
    }

    protected function checkCapability(string $capability): bool|\WP_Error
    {
        if (!is_user_logged_in()) {
            return $this->authRequiredError();
        }
        if (!current_user_can($capability)) {
            return DomainError::forbidden()->toWpError();
        }

        return true;
    }

    /**
     * 401. Если браузер прислал cookie входа, но не прислал X-WP-Nonce, WordPress (rest_cookie_check_errors)
     * обнулил пользователя — подсказываем клиенту причину, чтобы фронтенд не гадал.
     */
    private function authRequiredError(): \WP_Error
    {
        $e = DomainError::authRequired();
        $data = ['status' => 401];
        if (self::looksLikeMissingNonce()) {
            $data['reason'] = 'missing_nonce';
        }

        return new \WP_Error($e->errorCode(), $e->getMessage(), $data);
    }

    private static function looksLikeMissingNonce(): bool
    {
        return \defined('LOGGED_IN_COOKIE')
            && !empty($_COOKIE[LOGGED_IN_COOKIE])
            && empty($_SERVER['HTTP_X_WP_NONCE'])
            && !isset($_REQUEST['_wpnonce']); // phpcs:ignore WordPress.Security.NonceVerification -- только проверка наличия.
    }

    /** ID текущего пользователя; 0 здесь означает ошибку маршрута (permission_callback пропустил гостя). */
    protected function currentUserId(): int
    {
        $userId = get_current_user_id();
        if ($userId <= 0) {
            throw DomainError::authRequired();
        }

        return $userId;
    }

    // =============================================================================================
    // Rate limit
    // =============================================================================================

    /**
     * Фиксированное окно по user_id и по IP. Вызывается в начале callback (после permission_callback):
     * permission_callback должен быть без побочных эффектов, а счётчик — побочный эффект.
     *
     * @throws DomainError 429 uniundata_rate_limited (data.retry_after → заголовок Retry-After)
     */
    protected function enforceRateLimit(string $action): void
    {
        $limits = self::rateLimits($action);
        if ($limits === null) {
            return;
        }

        $userId = get_current_user_id();
        if ($userId > 0 && isset($limits['user'])) {
            $this->hit($action . '|u|' . $userId, ...$limits['user']);
        }
        $ip = $this->clientIp();
        if ($ip !== null && isset($limits['ip'])) {
            $this->hit($action . '|ip|' . $ip, ...$limits['ip']);
        }
    }

    /**
     * Действующие лимиты действия после фильтра `uniundata_rate_limits`; null — лимита нет
     * (фильтр может отключить действие, вернув для него null).
     *
     * @return array{user?: array{0: int, 1: int}, ip?: array{0: int, 1: int}}|null
     */
    protected static function rateLimits(string $action): ?array
    {
        $all = apply_filters('uniundata_rate_limits', self::DEFAULT_RATE_LIMITS);
        $limits = \is_array($all) && isset($all[$action]) && \is_array($all[$action]) ? $all[$action] : null;
        if ($limits === null) {
            return null;
        }
        $out = [];
        foreach (['user', 'ip'] as $subject) {
            $limit = $limits[$subject] ?? null;
            if (\is_array($limit) && \is_int($limit[0] ?? null) && \is_int($limit[1] ?? null) && $limit[0] > 0 && $limit[1] > 0) {
                $out[$subject] = [$limit[0], $limit[1]];
            }
        }

        return $out === [] ? null : $out;
    }

    /** +1 к счётчику окна; превышение → 429. */
    private function hit(string $subject, int $limit, int $windowSeconds): void
    {
        if ($this->bumpCounter($subject, $windowSeconds) > $limit) {
            throw DomainError::rateLimited(self::secondsToWindowEnd($windowSeconds));
        }
    }

    /**
     * Фиксированное окно: +1 и новое значение. Ключ — wp_hash (HMAC с солью сайта), поэтому в Redis и
     * wp_options не лежат IP и user_id в открытом виде.
     *
     * Транзиенты работают в любой конфигурации: без постоянного объектного кэша это строки wp_options
     * (read-modify-write не атомарен — при гонке счётчик может недосчитать несколько запросов, для мягкого
     * лимита допустимо), с Redis/Memcached — атомарные wp_cache_add + wp_cache_incr.
     */
    protected function bumpCounter(string $subject, int $windowSeconds): int
    {
        $key = self::counterKey($subject, $windowSeconds);
        $ttl = $windowSeconds + 5;

        if (wp_using_ext_object_cache()) {
            wp_cache_add($key, 0, self::RATE_LIMIT_GROUP, $ttl);
            $count = wp_cache_incr($key, 1, self::RATE_LIMIT_GROUP);
            if ($count === false) {
                wp_cache_set($key, 1, self::RATE_LIMIT_GROUP, $ttl);
                $count = 1;
            }

            return (int) $count;
        }

        $count = (int) get_transient($key) + 1;
        set_transient($key, $count, $ttl);

        return $count;
    }

    /** Текущее значение счётчика окна без увеличения. */
    protected function readCounter(string $subject, int $windowSeconds): int
    {
        $key = self::counterKey($subject, $windowSeconds);

        return (int) (wp_using_ext_object_cache()
            ? wp_cache_get($key, self::RATE_LIMIT_GROUP)
            : get_transient($key));
    }

    protected static function secondsToWindowEnd(int $windowSeconds): int
    {
        $now = time();

        return max(1, (intdiv($now, $windowSeconds) + 1) * $windowSeconds - $now);
    }

    private static function counterKey(string $subject, int $windowSeconds): string
    {
        // ≤ 172 символов имени транзиента: 'uniundata_rl_' + 24 + '_' + номер окна.
        return 'uniundata_rl_' . substr(wp_hash($subject . '|' . $windowSeconds), 0, 24) . '_' . intdiv(time(), $windowSeconds);
    }

    /**
     * IP клиента. По умолчанию — только REMOTE_ADDR: X-Forwarded-For и CF-Connecting-IP подделываются
     * клиентом. За доверенным reverse proxy / CDN реальный IP возвращает фильтр `uniundata_client_ip`
     * (и только если REMOTE_ADDR принадлежит этому прокси).
     */
    protected function clientIp(): ?string
    {
        $remote = isset($_SERVER['REMOTE_ADDR']) && \is_string($_SERVER['REMOTE_ADDR']) ? trim($_SERVER['REMOTE_ADDR']) : '';
        $ip = filter_var($remote, FILTER_VALIDATE_IP) !== false ? $remote : null;
        $filtered = apply_filters('uniundata_client_ip', $ip);

        return \is_string($filtered) && filter_var($filtered, FILTER_VALIDATE_IP) !== false ? $filtered : null;
    }

    // =============================================================================================
    // Описания args
    // =============================================================================================

    /**
     * Положительный целочисленный ID. validate_callback задан явно: при собственном sanitize_callback
     * WordPress не применяет схему (type/minimum) автоматически.
     *
     * @return array<string, mixed>
     */
    protected static function idArg(string $description, bool $required = true): array
    {
        return [
            'description' => $description,
            'type' => 'integer',
            'minimum' => 1,
            'maximum' => self::MAX_ID,
            'required' => $required,
            'validate_callback' => 'rest_validate_request_arg',
            'sanitize_callback' => 'absint',
        ];
    }

    /** @return array<string, array<string, mixed>> */
    protected static function paginationArgs(int $maxPerPage, int $defaultPerPage = 20): array
    {
        return [
            'page' => [
                'description' => 'Номер страницы, с 1.',
                'type' => 'integer',
                'minimum' => 1,
                'maximum' => 10000,
                'default' => 1,
                'validate_callback' => 'rest_validate_request_arg',
                'sanitize_callback' => 'absint',
            ],
            'per_page' => [
                'description' => \sprintf('Записей на странице, 1–%d.', $maxPerPage),
                'type' => 'integer',
                'minimum' => 1,
                'maximum' => $maxPerPage,
                'default' => $defaultPerPage,
                'validate_callback' => 'rest_validate_request_arg',
                'sanitize_callback' => 'absint',
            ],
        ];
    }

    /** @return array<string, mixed> Необязательная причина действия администратора (свободный текст в аудит). */
    protected static function reasonArg(): array
    {
        return [
            'description' => 'Причина (код вида admin_release или короткий текст до 255 символов) — пишется в аудит.',
            'type' => 'string',
            'maxLength' => 255,
            'default' => '',
            'validate_callback' => 'rest_validate_request_arg',
            'sanitize_callback' => 'sanitize_text_field',
        ];
    }

    /** Заголовки пагинации в стиле ядра WordPress (X-WP-Total, X-WP-TotalPages). */
    protected static function withPagination(\WP_REST_Response $response, int $total, int $perPage): \WP_REST_Response
    {
        $response->header('X-WP-Total', (string) $total);
        $response->header('X-WP-TotalPages', (string) max(1, (int) ceil($total / max(1, $perPage))));

        return $response;
    }
}
