<?php

declare(strict_types=1);

namespace Uniundata\Books\Domain;

/**
 * Статус заказа (`wp_book_orders.status`) и допустимые переходы по контракту.
 */
enum OrderStatus: string
{
    case Draft = 'draft';
    case PendingPayment = 'pending_payment';
    case PaymentProcessing = 'payment_processing';
    case Paid = 'paid';
    case PaymentFailed = 'payment_failed';
    case PaymentExpired = 'payment_expired';
    case Cancelled = 'cancelled';
    case Refunded = 'refunded';
    case PartiallyRefunded = 'partially_refunded';
    case Fulfilled = 'fulfilled';
    case Completed = 'completed';

    /** @return list<self> */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Draft => [self::PendingPayment, self::PaymentFailed, self::PaymentExpired, self::Cancelled],
            self::PendingPayment => [self::PaymentProcessing, self::Paid, self::PaymentFailed, self::PaymentExpired, self::Cancelled],
            self::PaymentProcessing => [self::Paid, self::PaymentFailed, self::PaymentExpired],
            self::PaymentFailed => [self::PendingPayment, self::Paid, self::PaymentExpired, self::Cancelled],
            // Поздний успешный платёж — особая ветка PaymentService (см. isLatePayment()).
            self::PaymentExpired, self::Cancelled => [self::Paid],
            self::Paid => [self::Fulfilled, self::Refunded, self::PartiallyRefunded],
            self::Fulfilled => [self::Completed, self::Refunded, self::PartiallyRefunded],
            self::Completed => [self::Refunded, self::PartiallyRefunded],
            self::PartiallyRefunded => [self::Refunded],
            self::Refunded => [],
        };
    }

    public function canTransitionTo(self $to): bool
    {
        return \in_array($to, $this->allowedTransitions(), true);
    }

    /** Переход в paid из уже закрытого неоплаченного заказа: экземпляры могли уйти другим. */
    public function isLatePayment(self $to): bool
    {
        return $to === self::Paid && ($this === self::PaymentExpired || $this === self::Cancelled);
    }

    /** Деньги получены; в БД такие статусы требуют paid_at (ck_orders_paid_at). */
    public function isPaid(): bool
    {
        return \in_array($this, [self::Paid, self::Fulfilled, self::Completed, self::Refunded, self::PartiallyRefunded], true);
    }

    /** Экземпляры заказа удерживаются в checkout_pending до оплаты или истечения payment_due_at. */
    public function holdsItems(): bool
    {
        return \in_array($this, [self::Draft, self::PendingPayment, self::PaymentProcessing, self::PaymentFailed], true);
    }

    /** POST /orders/{id}/pay: новая платёжная попытка допустима (плюс проверка payment_due_at). */
    public function acceptsNewPaymentAttempt(): bool
    {
        return \in_array($this, [self::Draft, self::PendingPayment, self::PaymentFailed], true);
    }

    /** Покупатель может отменить заказ сам (банк ещё не обрабатывает платёж). */
    public function isCancellableByCustomer(): bool
    {
        return \in_array($this, [self::Draft, self::PendingPayment, self::PaymentFailed], true);
    }
}
