<?php

declare(strict_types=1);

namespace Uniundata\Books\Domain;

/**
 * Статус платёжной попытки (`wp_book_payments.status`) и допустимые переходы по контракту.
 */
enum PaymentStatus: string
{
    case Created = 'created';
    case Pending = 'pending';
    case Processing = 'processing';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Cancelled = 'cancelled';
    case Expired = 'expired';
    case Refunded = 'refunded';
    case PartiallyRefunded = 'partially_refunded';

    /** @return list<self> */
    public function allowedTransitions(): array
    {
        return match ($this) {
            // Сессия у банка не открылась или заказ закрыт раньше: cancelled (отмена, сбой createSession)
            // или expired (истёк payment_due_at). failed — только ответ банка по открытой сессии.
            self::Created => [self::Pending, self::Cancelled, self::Expired],
            self::Pending => [self::Processing, self::Succeeded, self::Failed, self::Cancelled, self::Expired],
            self::Processing => [self::Succeeded, self::Failed, self::Expired],
            self::Succeeded => [self::Refunded, self::PartiallyRefunded],
            self::PartiallyRefunded => [self::Refunded],
            // Только позднее подтверждение банка (деньги всё-таки списаны).
            self::Failed, self::Expired, self::Cancelled => [self::Succeeded],
            self::Refunded => [],
        };
    }

    public function canTransitionTo(self $to): bool
    {
        return \in_array($to, $this->allowedTransitions(), true);
    }

    /** Сессия у банка ещё может завершиться успехом без нашего участия. */
    public function isOpen(): bool
    {
        return \in_array($this, [self::Created, self::Pending, self::Processing], true);
    }

    /** Деньги были получены; в БД требует succeeded_at (wp_book_payments_chk_succeeded). */
    public function isSuccessful(): bool
    {
        return \in_array($this, [self::Succeeded, self::Refunded, self::PartiallyRefunded], true);
    }

    /** Failed/expired/cancelled → succeeded: деньги пришли после того, как мы перестали их ждать. */
    public function isLateSuccess(self $to): bool
    {
        return $to === self::Succeeded && \in_array($this, [self::Failed, self::Expired, self::Cancelled], true);
    }
}
