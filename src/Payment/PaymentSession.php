<?php

declare(strict_types=1);

namespace Uniundata\Books\Payment;

/**
 * Платёжная сессия, созданная у банка (результат PaymentProviderInterface::createSession()).
 *
 * Ничего секретного не содержит: redirect URL одноразовый и привязан к сессии банка.
 * В БД сохраняются только provider_payment_id, session_expires_at и provider_status
 * (redirect URL в схеме не хранится — см. CheckoutService::openSession()).
 */
final readonly class PaymentSession
{
    public function __construct(
        /** ID платежа/сессии у банка → wp_book_payments.provider_payment_id. */
        public string $providerPaymentId,
        /** Куда перенаправить покупателя (страница банка). Только https. */
        public string $redirectUrl,
        /** Когда банк перестанет принимать оплату по этой сессии (UTC). */
        public \DateTimeImmutable $expiresAt,
        /** Сырой статус банка в момент создания (для provider_status). */
        public string $providerStatus = 'created',
    ) {
        if (!preg_match('/^[\x21-\x7E]{1,128}$/', $providerPaymentId)) {
            throw new \InvalidArgumentException('providerPaymentId must be 1..128 printable ASCII chars');
        }
        if (!str_starts_with($redirectUrl, 'https://') || strlen($redirectUrl) > 2048) {
            throw new \InvalidArgumentException('redirectUrl must be an https URL');
        }
        if (!preg_match('/^[\x21-\x7E]{1,64}$/', $providerStatus)) {
            throw new \InvalidArgumentException('providerStatus must be 1..64 printable ASCII chars');
        }
    }

    /** Значение для DATETIME(6) в UTC. */
    public function expiresAtSql(): string
    {
        return $this->expiresAt->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
    }
}
