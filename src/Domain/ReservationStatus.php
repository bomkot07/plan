<?php

declare(strict_types=1);

namespace Uniundata\Books\Domain;

/**
 * Статус резерва (`wp_book_reservations.reservation_status`).
 * Единственный нефинальный статус — active; все переходы только из него.
 */
enum ReservationStatus: string
{
    case Active = 'active';
    case Expired = 'expired';
    case Cancelled = 'cancelled';
    case ConvertedToOrder = 'converted_to_order';
    case ReleasedByAdmin = 'released_by_admin';

    public function isFinal(): bool
    {
        return $this !== self::Active;
    }

    public function canTransitionTo(self $to): bool
    {
        return $this === self::Active && $to !== self::Active;
    }

    /**
     * Попытка для лимита «3 резерва на экземпляр» — любой резерв, кроме снятого администратором.
     * В БД: attempt_no IS NULL ⇔ released_by_admin (wp_book_reservations_chk_attempt_admin).
     */
    public function countsAsAttempt(): bool
    {
        return $this !== self::ReleasedByAdmin;
    }

    /** Во что переводится связанная позиция корзины (`wp_book_cart_items.status`), когда резерв уходит в этот статус. */
    public function cartItemStatus(): string
    {
        return match ($this) {
            self::Active => 'active',
            self::Expired => 'expired',
            self::Cancelled, self::ReleasedByAdmin => 'removed',
            self::ConvertedToOrder => 'converted_to_order',
        };
    }
}
