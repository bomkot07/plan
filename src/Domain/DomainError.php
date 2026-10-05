<?php

declare(strict_types=1);

namespace Uniundata\Books\Domain;

/**
 * Ожидаемая бизнес-ошибка с кодом из контракта REST (`uniundata_*`) и HTTP-статусом.
 *
 * Сервисы бросают её внутри Db::transaction() — транзакция откатывается, REST-контроллер
 * превращает ошибку в WP_Error через toWpError(). Всё, что не DomainError, контроллер отдаёт
 * как 500 `uniundata_internal` без текста исключения (текст — только в лог).
 *
 * getCode() всегда 0: Db::transaction() различает повторяемые ошибки MySQL по errno, и HTTP-статус
 * в code исказил бы эту проверку. Тексты — через __() с литералами (извлекаются `wp i18n make-pot`).
 */
final class DomainError extends \RuntimeException
{
    /**
     * @param array<string, mixed> $data Дополнительные поля для `data` ответа (без PII и внутренних деталей).
     */
    public function __construct(
        private readonly string $errorCode,
        string $message,
        private readonly int $httpStatus,
        private readonly array $data = [],
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }

    public function httpStatus(): int
    {
        return $this->httpStatus;
    }

    /** @return array<string, mixed> */
    public function data(): array
    {
        return $this->data;
    }

    /** Формат WP REST: { code, message, data: { status, ... } }. */
    public function toWpError(): \WP_Error
    {
        return new \WP_Error($this->errorCode, $this->getMessage(), ['status' => $this->httpStatus] + $this->data);
    }

    // ---- Фабрики для кодов контракта: единые тексты и состав data -------------------------------

    public static function authRequired(): self
    {
        return new self('uniundata_auth_required', \__('Please log in to continue.', 'uniundata-books'), 401);
    }

    public static function forbidden(): self
    {
        return new self('uniundata_forbidden', \__('You are not allowed to do this.', 'uniundata-books'), 403);
    }

    public static function invalidParam(string $param, string $message): self
    {
        return new self('uniundata_invalid_param', $message, 400, ['param' => $param]);
    }

    public static function itemNotFound(int $itemId): self
    {
        return new self('uniundata_item_not_found', \__('The book was not found.', 'uniundata-books'), 404, ['book_item_id' => $itemId]);
    }

    public static function itemUnavailable(int $itemId, string $availabilityStatus, ?\Throwable $previous = null): self
    {
        return new self(
            'uniundata_item_unavailable',
            \__('This book is already reserved or no longer available.', 'uniundata-books'),
            409,
            ['book_item_id' => $itemId, 'availability_status' => $availabilityStatus],
            $previous,
        );
    }

    public static function reservationLimitReached(int $itemId, int $maxAttempts, ?\Throwable $previous = null): self
    {
        return new self(
            'uniundata_reservation_limit_reached',
            \__('You have used all reservation attempts for this book.', 'uniundata-books'),
            409,
            ['book_item_id' => $itemId, 'max_attempts' => $maxAttempts],
            $previous,
        );
    }

    /** Админский endpoint /admin/reservations/{id}/release: резерва с таким ID нет. */
    public static function reservationNotFound(int $reservationId): self
    {
        return new self('uniundata_reservation_not_found', \__('Reservation not found.', 'uniundata-books'), 404, ['reservation_id' => $reservationId]);
    }

    /** @param array<string, mixed> $data */
    public static function reservationExpired(array $data = []): self
    {
        return new self('uniundata_reservation_expired', \__('The reservation is no longer active.', 'uniundata-books'), 410, $data);
    }

    public static function cartEmpty(): self
    {
        return new self('uniundata_cart_empty', \__('Your cart is empty.', 'uniundata-books'), 409);
    }

    /** @param array<string, mixed> $data */
    public static function cartChanged(array $data = []): self
    {
        return new self('uniundata_cart_changed', \__('Your cart has changed. Please review it and confirm again.', 'uniundata-books'), 409, $data);
    }

    public static function orderNotFound(): self
    {
        // Одинаково для несуществующего и чужого заказа: не раскрываем существование.
        return new self('uniundata_order_not_found', \__('Order not found.', 'uniundata-books'), 404);
    }

    public static function orderNotPayable(string $orderStatus): self
    {
        return new self('uniundata_order_not_payable', \__('This order cannot be paid.', 'uniundata-books'), 409, ['order_status' => $orderStatus]);
    }

    public static function rateLimited(int $retryAfterSeconds): self
    {
        return new self('uniundata_rate_limited', \__('Too many requests. Please try again later.', 'uniundata-books'), 429, ['retry_after' => $retryAfterSeconds]);
    }

    /** 503 после исчерпания повторов при deadlock / lock wait timeout. Контроллер ставит Retry-After. */
    public static function conflictRetry(int $retryAfterSeconds = 1, ?\Throwable $previous = null): self
    {
        return new self(
            'uniundata_conflict_retry',
            \__('The service is busy. Please retry in a moment.', 'uniundata-books'),
            503,
            ['retry_after' => $retryAfterSeconds],
            $previous,
        );
    }

    public static function paymentProviderError(): self
    {
        return new self('uniundata_payment_provider_error', \__('The payment provider is unavailable. Please try again later.', 'uniundata-books'), 502);
    }

    public static function invalidSignature(): self
    {
        return new self('uniundata_invalid_signature', 'Invalid signature.', 401);
    }

    public static function internal(?\Throwable $previous = null): self
    {
        return new self('uniundata_internal', \__('Internal error.', 'uniundata-books'), 500, [], $previous);
    }
}
