<?php

declare(strict_types=1);

namespace Uniundata\Books\Infrastructure;

/**
 * Append-only журнал `wp_book_audit_log`: переходы статусов и важные действия.
 *
 * Вызывается ВНУТРИ той же Db::transaction(), что и само изменение: запись аудита фиксируется
 * или откатывается вместе с ним, поэтому журнал не врёт о несостоявшихся переходах.
 * Ошибка записи аудита откатывает бизнес-операцию — это осознанно. Событие без бизнес-транзакции
 * (отклонённый webhook) можно записать и вне её: Db::insert() сам откроет короткую транзакцию.
 *
 * В context не кладём секреты, PAN/CVV и лишние PII (email, адрес, телефон): только ID и коды.
 * Ключи, похожие на секреты, дополнительно маскируются.
 */
final class AuditLog
{
    private const ACTOR_TYPES = ['user', 'admin', 'system', 'cron', 'webhook', 'sync', 'cli'];
    private const MAX_CONTEXT_BYTES = 16_384;
    private const MAX_CONTEXT_DEPTH = 8;
    private const REDACTED = '[redacted]';
    private const SENSITIVE_KEY = '/(^|[_-])(password|passwd|secret|token|api_?key|authorization|signature|cookie|nonce|card_?number|pan|cvv2?|cvc2?|iban)($|[_-])/i';

    private static ?string $processRequestId = null;

    private readonly string $requestId;

    /** @param string|null $requestId UUID корреляции; по умолчанию один на PHP-запрос (или из X-Request-Id). */
    public function __construct(private readonly Db $db, ?string $requestId = null)
    {
        $this->requestId = $requestId !== null && self::isUuid($requestId)
            ? strtolower($requestId)
            : self::processRequestId();
    }

    /**
     * @param string               $action      'reservation.created', 'item.status_changed', 'order.paid'…
     * @param string               $entityType  'reservation', 'item', 'cart', 'order', 'payment'…
     * @param int|null             $entityId    null — у события нет сущности (отклонённый webhook, запрос
     *                                          синхронизации до создания прогона); 0 тоже пишется как NULL.
     * @param array<string, mixed> $context
     * @param string               $actorType   user|admin|system|cron|webhook|sync|cli
     */
    public function record(
        string $action,
        string $entityType,
        ?int $entityId,
        ?string $from,
        ?string $to,
        array $context = [],
        string $actorType = 'user',
        ?int $actorUserId = null,
    ): void {
        if (!\in_array($actorType, self::ACTOR_TYPES, true)) {
            throw new \InvalidArgumentException(\sprintf('Unknown audit actor type "%s"', $actorType));
        }
        if ($entityId !== null && $entityId < 0) {
            throw new \InvalidArgumentException(\sprintf('Invalid audit entity id %d', $entityId));
        }
        self::assertCode($action, 64, 'action');
        self::assertCode($entityType, 32, 'entity type');
        if ($from !== null) {
            self::assertCode($from, 24, 'from status');
        }
        if ($to !== null) {
            self::assertCode($to, 24, 'to status');
        }

        $this->db->insert('book_audit_log', [
            'actor_type' => $actorType,
            'actor_user_id' => $actorUserId !== null && $actorUserId > 0 ? $actorUserId : null,
            'action' => $action,
            'entity_type' => $entityType,
            'entity_id' => $entityId !== null && $entityId > 0 ? $entityId : null,
            'from_status' => $from,
            'to_status' => $to,
            'request_id' => $this->requestId,
            'context' => $context === [] ? null : self::encodeContext($context),
        ], ['occurred_at']); // явный UTC_TIMESTAMP(6): не зависит от time_zone сессии
    }

    public function requestId(): string
    {
        return $this->requestId;
    }

    /** @param array<string, mixed> $context */
    private static function encodeContext(array $context): string
    {
        try {
            // Без JSON_UNESCAPED_UNICODE: запрос остаётся ASCII, и $wpdb пропускает для этого INSERT
            // проверку кодировок (check_safe_collation / strip_invalid_text_from_query).
            $json = json_encode(
                self::redact($context, 0),
                JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR,
            );
        } catch (\JsonException) {
            return '{"_error":"context_not_encodable"}';
        }

        if (\strlen($json) > self::MAX_CONTEXT_BYTES) {
            return \sprintf('{"_truncated":true,"_bytes":%d}', \strlen($json));
        }

        return $json;
    }

    /**
     * @param array<array-key, mixed> $data
     * @return array<array-key, mixed>
     */
    private static function redact(array $data, int $depth): array
    {
        if ($depth >= self::MAX_CONTEXT_DEPTH) {
            return ['_truncated' => true];
        }
        foreach ($data as $key => $value) {
            if (\is_string($key) && preg_match(self::SENSITIVE_KEY, $key) === 1) {
                $data[$key] = self::REDACTED;
            } elseif (\is_array($value)) {
                $data[$key] = self::redact($value, $depth + 1);
            } elseif ($value instanceof \BackedEnum) {
                $data[$key] = $value->value;
            } elseif (\is_object($value)) {
                $data[$key] = $value instanceof \JsonSerializable ? $value : $value::class;
            }
        }

        return $data;
    }

    private static function assertCode(string $value, int $maxLength, string $what): void
    {
        if (preg_match('/^[a-z0-9_.:-]+$/', $value) !== 1 || \strlen($value) > $maxLength) {
            throw new \InvalidArgumentException(\sprintf('Invalid audit %s "%s"', $what, $value));
        }
    }

    private static function processRequestId(): string
    {
        if (self::$processRequestId === null) {
            // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- значение проверяется регуляркой UUID ниже.
            $header = isset($_SERVER['HTTP_X_REQUEST_ID']) && \is_string($_SERVER['HTTP_X_REQUEST_ID'])
                ? trim($_SERVER['HTTP_X_REQUEST_ID'])
                : '';
            self::$processRequestId = self::isUuid($header) ? strtolower($header) : self::uuid4();
        }

        return self::$processRequestId;
    }

    private static function isUuid(string $value): bool
    {
        return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $value) === 1;
    }

    private static function uuid4(): string
    {
        if (\function_exists('wp_generate_uuid4')) {
            return wp_generate_uuid4();
        }
        $bytes = random_bytes(16);
        $bytes[6] = \chr((\ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = \chr((\ord($bytes[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
