<?php

declare(strict_types=1);

namespace Uniundata\Books\Payment;

/**
 * Итог обработки webhook-а для PaymentWebhookController: HTTP-код ответа банку + исход для логов.
 *
 * Политика кодов (банки повторяют доставку на любой не-2xx):
 *  - 200 — событие применено, уже было обработано (дубль) или валидно, но не требует действий;
 *  - 400 — тело не разбирается ПОСЛЕ успешной подписи (повтор бессмыслен, но банк увидит ошибку);
 *  - 401 — подпись/время не прошли; в payment_events ничего не записано;
 *  - 413 — тело больше лимита;
 *  - 500/503 — временная ошибка (deadlock после повторов, банк недоступен для подтверждения):
 *    событие помечено failed, банк повторит доставку, и обработка продолжится с того же места.
 *
 * Тело ответа банку минимально и не раскрывает внутренних деталей.
 */
final readonly class WebhookResult
{
    public const OUTCOME_PROCESSED = 'processed';
    public const OUTCOME_IGNORED = 'ignored';
    public const OUTCOME_DUPLICATE = 'duplicate';
    public const OUTCOME_INVALID_SIGNATURE = 'invalid_signature';
    public const OUTCOME_BAD_REQUEST = 'bad_request';
    public const OUTCOME_RETRY = 'retry';

    public function __construct(
        public int $httpStatus,
        public string $outcome,
        /** wp_book_payment_events.id, если событие сохранено. */
        public ?int $eventId = null,
        /** Внутреннее пояснение для лога/аудита (без секретов и PII). Банку не отдаётся. */
        public string $note = '',
    ) {
    }

    public static function processed(int $eventId): self
    {
        return new self(200, self::OUTCOME_PROCESSED, $eventId);
    }

    public static function ignored(int $eventId, string $note): self
    {
        return new self(200, self::OUTCOME_IGNORED, $eventId, $note);
    }

    public static function duplicate(int $eventId): self
    {
        return new self(200, self::OUTCOME_DUPLICATE, $eventId, 'already processed');
    }

    public static function invalidSignature(string $note = 'signature or timestamp check failed'): self
    {
        return new self(401, self::OUTCOME_INVALID_SIGNATURE, null, $note);
    }

    public static function badRequest(string $note, int $httpStatus = 400): self
    {
        return new self($httpStatus, self::OUTCOME_BAD_REQUEST, null, $note);
    }

    /** Временная ошибка: банк должен повторить доставку. */
    public static function retryLater(?int $eventId, string $note, int $httpStatus = 503): self
    {
        return new self($httpStatus, self::OUTCOME_RETRY, $eventId, $note);
    }

    public function isSuccess(): bool
    {
        return $this->httpStatus >= 200 && $this->httpStatus < 300;
    }

    /**
     * Тело ответа банку. Для 401 контроллер может вернуть WP_Error uniundata_invalid_signature.
     *
     * @return array{received: bool}
     */
    public function responseBody(): array
    {
        return ['received' => $this->isSuccess()];
    }
}
