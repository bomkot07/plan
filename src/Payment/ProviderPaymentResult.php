<?php

declare(strict_types=1);

namespace Uniundata\Books\Payment;

use Uniundata\Books\Domain\PaymentStatus;

/**
 * Нормализованное «мнение банка» о платеже. Один тип для двух источников:
 *  - webhook (PaymentProviderInterface::verifyWebhook()) — заполнены eventId/eventType/signedAt;
 *  - серверный опрос API (PaymentProviderInterface::fetchPayment()) — поля события пустые.
 * Оба идут в общий путь PaymentService::applyProviderResult().
 *
 * Адаптер банка обязан:
 *  - создавать объект ТОЛЬКО после проверки подписи (для webhook);
 *  - нормализовать статус банка в PaymentStatus (никогда не Created);
 *  - не класть сюда PAN, CVV, токены карт, секреты; redactedPayload — уже очищенный payload
 *    (PaymentService дополнительно прогоняет его через redact()).
 *
 * Сопоставление с нашим платежом: providerPaymentId → (provider, provider_payment_id); если банк
 * прислал событие раньше, чем мы сохранили provider_payment_id (ответ createSession ещё в пути), —
 * по idempotencyKey (наш payments.idempotency_key, который банк возвращает как merchant reference).
 * Событие возврата (refunded / partially_refunded) сопоставляется со строкой wp_book_refunds по
 * providerRefundId / refundIdempotencyKey, а если их нет — по приросту refundedAmount.
 */
final readonly class ProviderPaymentResult
{
    /**
     * @param array<string, mixed> $redactedPayload Безопасная для хранения копия события (payload_redacted).
     */
    public function __construct(
        /** Код провайдера = wp_book_payments.provider. */
        public string $provider,
        /** Нормализованный статус платежа у банка. */
        public PaymentStatus $status,
        /** Сырой статус банка (provider_status), ASCII ≤ 64. */
        public string $providerStatus,
        public ?string $providerPaymentId = null,
        /** Наш payments.idempotency_key, если банк его возвращает (merchant reference). */
        public ?string $idempotencyKey = null,
        /** orders.public_order_id из данных банка — сверяется с заказом. */
        public ?string $publicOrderId = null,
        /** Сумма в минимальных единицах, как её видит банк. */
        public ?int $amount = null,
        public ?string $currency = null,
        /** Накопленная сумма возвратов по платежу (для refunded/partially_refunded). */
        public ?int $refundedAmount = null,
        public ?string $cardBrand = null,
        /** Только последние 4 цифры (PCI DSS). */
        public ?string $cardLast4 = null,
        public ?string $failureCode = null,
        /** Короткое техническое описание без PII, ≤ 255. */
        public ?string $failureMessage = null,
        /** ID события у провайдера (webhook). Нет у результата опроса. */
        public ?string $eventId = null,
        public ?string $eventType = null,
        /** Unix-время из ПОДПИСАННОГО заголовка/тела события — для защиты от replay. */
        public ?int $signedAt = null,
        public array $redactedPayload = [],
        /** Событие возврата: ID возврата у банка (сопоставляется с wp_book_refunds.provider_refund_id). */
        public ?string $providerRefundId = null,
        /** Событие возврата: наш wp_book_refunds.idempotency_key, если банк его возвращает. */
        public ?string $refundIdempotencyKey = null,
    ) {
        if (!preg_match('/^[a-z0-9_]{1,32}$/', $provider)) {
            throw new \InvalidArgumentException('provider must match [a-z0-9_]{1,32}');
        }
        if ($status->value === 'created') {
            throw new \InvalidArgumentException('provider can not report status "created"');
        }
        self::assertAscii('providerStatus', $providerStatus, 64, false);
        self::assertAscii('providerPaymentId', $providerPaymentId, 128);
        self::assertAscii('eventId', $eventId, 128);
        self::assertAscii('eventType', $eventType, 64);
        self::assertAscii('failureCode', $failureCode, 64);
        self::assertAscii('providerRefundId', $providerRefundId, 128);
        foreach (['idempotencyKey' => $idempotencyKey, 'refundIdempotencyKey' => $refundIdempotencyKey] as $name => $key) {
            if ($key !== null && !preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $key)) {
                throw new \InvalidArgumentException($name . ' must be a lowercase UUID');
            }
        }
        if ($publicOrderId !== null && !preg_match('/^uniundata_[0-9a-f-]{36}$/', $publicOrderId)) {
            throw new \InvalidArgumentException('publicOrderId has invalid format');
        }
        if ($amount !== null && $amount < 0) {
            throw new \InvalidArgumentException('amount must be >= 0');
        }
        if ($refundedAmount !== null && $refundedAmount < 0) {
            throw new \InvalidArgumentException('refundedAmount must be >= 0');
        }
        if ($currency !== null && !preg_match('/^[A-Z]{3}$/', $currency)) {
            throw new \InvalidArgumentException('currency must be ISO 4217 alpha-3');
        }
        if ($cardLast4 !== null && !preg_match('/^[0-9]{4}$/', $cardLast4)) {
            throw new \InvalidArgumentException('cardLast4 must be exactly 4 digits');
        }
        if ($cardBrand !== null && !preg_match('/^[A-Za-z0-9_-]{1,16}$/', $cardBrand)) {
            throw new \InvalidArgumentException('cardBrand has invalid format');
        }
        if ($failureMessage !== null && mb_strlen($failureMessage) > 255) {
            throw new \InvalidArgumentException('failureMessage must be <= 255 chars');
        }
        if ($providerPaymentId === null && $idempotencyKey === null) {
            throw new \InvalidArgumentException('either providerPaymentId or idempotencyKey is required');
        }
    }

    /**
     * Результат опроса API, дополненный метаданными webhook-события (подтверждение webhook-а
     * через fetchPayment: статус/сумма берутся из API, событие — из webhook).
     */
    public function withEventOf(self $event): self
    {
        return new self(
            provider: $this->provider,
            status: $this->status,
            providerStatus: $this->providerStatus,
            providerPaymentId: $this->providerPaymentId ?? $event->providerPaymentId,
            idempotencyKey: $this->idempotencyKey ?? $event->idempotencyKey,
            publicOrderId: $this->publicOrderId ?? $event->publicOrderId,
            amount: $this->amount,
            currency: $this->currency,
            refundedAmount: $this->refundedAmount,
            cardBrand: $this->cardBrand ?? $event->cardBrand,
            cardLast4: $this->cardLast4 ?? $event->cardLast4,
            failureCode: $this->failureCode,
            failureMessage: $this->failureMessage,
            eventId: $event->eventId,
            eventType: $event->eventType,
            signedAt: $event->signedAt,
            redactedPayload: $event->redactedPayload,
            providerRefundId: $this->providerRefundId ?? $event->providerRefundId,
            refundIdempotencyKey: $this->refundIdempotencyKey ?? $event->refundIdempotencyKey,
        );
    }

    public function isFromWebhook(): bool
    {
        return $this->eventId !== null;
    }

    private static function assertAscii(string $name, ?string $value, int $maxLen, bool $nullable = true): void
    {
        if ($value === null && $nullable) {
            return;
        }
        // Идентификаторы банка хранятся в utf8mb4_bin-колонках и сравниваются побайтно: только печатный ASCII.
        if ($value === null || !preg_match('/^[\x21-\x7E]{1,' . $maxLen . '}$/', $value)) {
            throw new \InvalidArgumentException($name . ' must be 1..' . $maxLen . ' printable ASCII chars');
        }
    }
}
