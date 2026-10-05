<?php

declare(strict_types=1);

namespace Uniundata\Books\Service;

use Uniundata\Books\Domain\ReservationStatus;
use Uniundata\Books\Infrastructure\Db;

/**
 * Pass A: снятие истёкших активных резервов (Action Scheduler `uniundata_expire_reservations`, раз в минуту)
 * и закрытие заброшенных пустых корзин (`uniundata_abandon_carts`, раз в сутки).
 * Неоплаченные заказы (checkout_pending → payment_expired) снимает pass B — OrderExpiryService.
 *
 * Каждый резерв — своя короткая транзакция: блокировки держатся миллисекунды, а сбой одной строки
 * не откатывает и не останавливает остальные. Обработка идемпотентна: повтор после сбоя видит, что
 * резерв уже не active, и пропускает его. GET_LOCK (Db::lockName(), имя уникально для БД и префикса)
 * лишь не даёт двум раннерам (AS + WP-CLI) делать одну работу; корректность держат блокировки строк.
 */
final class ReservationExpiryService
{
    public const LOCK_EXPIRE = 'expire_reservations';
    public const LOCK_ABANDON = 'abandon_carts';

    public function __construct(
        private readonly Db $db,
        private readonly ReservationService $reservations,
        // Меньше таймаута claim-а Action Scheduler: недоделанное заберёт следующий запуск через минуту.
        private readonly float $timeBudgetSeconds = 20.0,
        private readonly string $actorType = 'cron',
    ) {
    }

    /**
     * @return int Сколько резервов переведено в expired. Если результат равен $limit, очередь, скорее всего,
     *             не исчерпана — планировщик может сразу поставить ещё один запуск.
     */
    public function expireDue(int $limit = 200): int
    {
        $limit = max(1, min($limit, 1000));
        if (!$this->db->getLock(self::LOCK_EXPIRE)) {
            return 0; // другой раннер уже работает; оставшееся заберёт следующий запуск
        }
        try {
            return $this->expireBatch($limit);
        } finally {
            $this->db->releaseLock(self::LOCK_EXPIRE);
        }
    }

    private function expireBatch(int $limit): int
    {
        $deadline = microtime(true) + $this->timeBudgetSeconds;

        // Кандидаты — обычным чтением без блокировок (ix_reservations_expiry: reservation_status, expires_at).
        // Всё, что здесь прочитано, перепроверяется под блокировкой.
        $candidates = $this->db->getResults(
            "SELECT id, cart_id, book_item_id
               FROM {$this->db->table('reservations')}
              WHERE reservation_status = 'active' AND expires_at <= UTC_TIMESTAMP(6)
              ORDER BY expires_at, id
              LIMIT %d",
            $limit,
        );

        $expired = 0;
        foreach ($candidates as $row) {
            if (microtime(true) >= $deadline) {
                break;
            }
            $reservationId = (int) $row['id'];
            try {
                if ($this->expireOne($reservationId, $row['cart_id'] !== null ? (int) $row['cart_id'] : null, (int) $row['book_item_id'])) {
                    ++$expired;
                }
            } catch (\Throwable $e) {
                // Включая uniundata_conflict_retry после исчерпания повторов: строка останется active
                // и будет обработана следующим запуском. В лог — ID, класс и текст ошибки (персональных
                // данных в этих запросах нет).
                error_log(\sprintf(
                    '[uniundata] expire reservation #%d failed: %s: %s',
                    $reservationId,
                    $e::class,
                    $e->getMessage(),
                ));
            }
        }

        return $expired;
    }

    /**
     * Пустые открытые корзины без активности $days дней → abandoned (контракт: «пустая и без активности
     * 30 дней»). Задача Action Scheduler `uniundata_abandon_carts`, раз в сутки. Корзина с активными
     * позициями не закрывается (у неё пересчитывается только expires_at).
     *
     * @return int Сколько корзин закрыто.
     */
    public function abandonStaleCarts(int $days = 30, int $limit = 500): int
    {
        $days = max(1, $days);
        $limit = max(1, min($limit, 5000));
        if (!$this->db->getLock(self::LOCK_ABANDON)) {
            return 0;
        }
        try {
            return $this->abandonBatch($days, $limit);
        } finally {
            $this->db->releaseLock(self::LOCK_ABANDON);
        }
    }

    private function abandonBatch(int $days, int $limit): int
    {
        $deadline = microtime(true) + $this->timeBudgetSeconds;

        // ix_carts_status_activity (status, last_activity_at).
        $candidates = $this->db->getResults(
            "SELECT id FROM {$this->db->table('carts')}
              WHERE status IN ('active', 'checkout_started')
                AND last_activity_at < UTC_TIMESTAMP(6) - INTERVAL %d DAY
              ORDER BY last_activity_at
              LIMIT %d",
            $days,
            $limit,
        );

        $closed = 0;
        foreach ($candidates as $row) {
            if (microtime(true) >= $deadline) {
                break;
            }
            $cartId = (int) $row['id'];
            try {
                $closed += $this->db->transaction(function () use ($cartId, $days): int {
                    $cart = $this->db->getRow(
                        "SELECT id, user_id, status, (last_activity_at < UTC_TIMESTAMP(6) - INTERVAL %d DAY) AS is_stale
                           FROM {$this->db->table('carts')}
                          WHERE id = %d
                            FOR UPDATE",
                        $days,
                        $cartId,
                    );
                    // Перепроверка: пользователь мог за это время что-то отложить (last_activity_at обновился).
                    if ($cart === null || $cart['is_stale'] !== '1'
                        || !\in_array($cart['status'], ['active', 'checkout_started'], true)) {
                        return 0;
                    }
                    $cartRow = ['id' => (int) $cart['id'], 'user_id' => (int) $cart['user_id'], 'status' => (string) $cart['status']];

                    // Закрывает только если активных позиций нет; иначе — лишь пересчёт expires_at.
                    return $this->reservations->refreshCartLocked($cartRow, $this->db->now(), false, 'abandoned', $this->actorType, null) ? 1 : 0;
                });
            } catch (\Throwable $e) {
                error_log(\sprintf('[uniundata] abandon cart #%d failed: %s: %s', $cartId, $e::class, $e->getMessage()));
            }
        }

        return $closed;
    }

    /**
     * Одна короткая транзакция. Порядок блокировок: carts → items → reservations → cart_items
     * (последний уровень берёт ReservationService::releaseLocked()).
     *
     * @return bool false — резерв уже обработан кем-то другим (checkout, удаление, параллельный cron).
     */
    private function expireOne(int $reservationId, ?int $cartId, int $itemId): bool
    {
        return $this->db->transaction(function () use ($reservationId, $cartId, $itemId): bool {
            $cart = $cartId !== null ? $this->reservations->lockCartById($cartId) : null; // 1. carts
            $item = $this->reservations->lockItem($itemId);                              // 2. items
            $reservation = $this->reservations->lockReservation($reservationId);         // 3. reservations

            // Перепроверка после блокировки: между чтением кандидатов и блокировкой резерв мог стать
            // converted_to_order (checkout), cancelled (удаление) или expired (параллельный запуск).
            if ($item === null || $reservation === null
                || $reservation['status'] !== ReservationStatus::Active->value
                || !$reservation['is_due']
                || $reservation['cart_id'] !== $cartId
                || $reservation['book_item_id'] !== $itemId) {
                return false;
            }

            $now = $this->db->now();
            // Экземпляр освобождается только из reserved (см. releaseLocked): sold/checkout_pending
            // означают, что по нему идёт или прошла оплата, — такой экземпляр не трогаем.
            $this->reservations->releaseLocked(
                $reservation,
                $item,
                ReservationStatus::Expired,
                'expired',
                $now,
                $this->actorType,
                null,
            );

            if ($cart !== null) {
                // Истекла последняя активная позиция → корзина expired; иначе пересчитать её expires_at.
                // last_activity_at не трогаем: это не действие пользователя.
                $this->reservations->refreshCartLocked($cart, $now, false, 'expired', $this->actorType, null);
            }

            return true;
        });
    }
}
