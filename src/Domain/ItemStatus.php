<?php

declare(strict_types=1);

namespace Uniundata\Books\Domain;

/**
 * Локальный статус доступности экземпляра (`wp_book_items.availability_status`).
 *
 * Переходы — по контракту. В БД переход защищён условным UPDATE
 * (`WHERE id = %d AND availability_status = '<from>'` + проверка rows_affected), этот enum —
 * единая точка правды для кода и тестов.
 */
enum ItemStatus: string
{
    case Available = 'available';
    case Reserved = 'reserved';
    case CheckoutPending = 'checkout_pending';
    case Sold = 'sold';
    case Withdrawn = 'withdrawn';
    case SyncMissing = 'sync_missing';
    case Blocked = 'blocked';

    /** @return list<self> */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Available => [self::Reserved, self::Withdrawn, self::Blocked, self::SyncMissing],
            // Освобождение резерва: цель зависит от source_status (см. releaseTarget()).
            self::Reserved => [self::Available, self::SyncMissing, self::Withdrawn, self::CheckoutPending],
            self::CheckoutPending => [self::Sold, self::Available, self::SyncMissing, self::Withdrawn],
            self::Sold => [],
            self::Withdrawn => [self::Available],
            self::SyncMissing => [self::Available, self::Withdrawn],
            // Разблокировка администратором — тоже в цель освобождения по source_status (releaseTarget()).
            self::Blocked => [self::Available, self::SyncMissing, self::Withdrawn],
        };
    }

    public function canTransitionTo(self $to): bool
    {
        return \in_array($to, $this->allowedTransitions(), true);
    }

    public function isReservable(): bool
    {
        return $this === self::Available;
    }

    public function isTerminal(): bool
    {
        return $this === self::Sold;
    }

    /** Статусы, которые ежедневная синхронизация не перетирает (меняется только source_status). */
    public function isProtectedFromSync(): bool
    {
        return \in_array($this, [self::Reserved, self::CheckoutPending, self::Sold, self::Blocked], true);
    }

    /**
     * Особая ветка «поздний успешный платёж» (заказ уже payment_expired/cancelled): экземпляр можно
     * забрать и продать только из свободного статуса. Это не обычный переход, поэтому вне allowedTransitions().
     */
    public function isClaimableByLatePayment(): bool
    {
        return \in_array($this, [self::Available, self::SyncMissing, self::Withdrawn], true);
    }

    /**
     * Целевой статус при освобождении (резерв снят / заказ не оплачен / экземпляр разблокирован):
     * CASE source_status WHEN 'present' THEN 'available' WHEN 'missing' THEN 'sync_missing'
     * WHEN 'withdrawn' THEN 'withdrawn' END.
     */
    public static function releaseTarget(string $sourceStatus): self
    {
        return match ($sourceStatus) {
            'present' => self::Available,
            'missing' => self::SyncMissing,
            'withdrawn' => self::Withdrawn,
            default => throw new \UnexpectedValueException(\sprintf('Unknown source_status "%s"', $sourceStatus)),
        };
    }

    /**
     * Состояние для витрины и GET /catalog/availability: страница каталога кэшируется,
     * кнопка «Отложить» строится по этому значению.
     *
     * @return 'available'|'reserved'|'sold'|'unavailable'
     */
    public function publicState(): string
    {
        return match ($this) {
            self::Available => 'available',
            self::Reserved, self::CheckoutPending => 'reserved',
            self::Sold => 'sold',
            self::Withdrawn, self::SyncMissing, self::Blocked => 'unavailable',
        };
    }
}
