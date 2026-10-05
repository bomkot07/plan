<?php

declare(strict_types=1);

namespace Uniundata\Books\Service;

use Uniundata\Books\Infrastructure\AuditLog;
use Uniundata\Books\Infrastructure\Db;
use Uniundata\Books\Payment\PaymentProviderInterface;

/**
 * Закрытие неоплаченных заказов после payment_due_at (recurring action uniundata_expire_orders, раз в минуту).
 *
 * Для каждого просроченного заказа:
 *  1. ВНЕ транзакции спрашиваем банк о каждой открытой попытке (created/pending/processing):
 *     succeeded → PaymentService::applyProviderResult() (заказ станет paid, освобождать нечего);
 *     processing → applyProviderResult() + однократное продление на grace (orders.payment_due_extended_at)
 *     и needs_attention.
 *  2. Транзакция items (asc) → order → payments: перепроверка (заказ всё ещё открыт и просрочен, успешных
 *     платежей нет), затем order → payment_expired, платежи created|pending|processing → expired,
 *     экземпляры checkout_pending этого заказа → release target.
 *  3. После COMMIT — cancelSession у банка (best effort). Если деньги всё-таки придут — ветка позднего платежа.
 *
 * Гонка с webhook: обе стороны блокируют items → order; кто второй, перепроверяет статус под блокировкой.
 * Запуски на разных сайтах одного сервера MySQL не мешают друг другу: имя GET_LOCK — Db::lockName().
 */
final class OrderExpiryService
{
    private const OPEN_ORDER_STATUSES = ['draft', 'pending_payment', 'payment_processing', 'payment_failed'];
    /**
     * Бюджет одного запуска. max_execution_time на сервере — 30 с, HTTP-таймаут адаптера ≤ 10 с:
     * новый заказ берём, только пока до лимита остаётся запас на один опрос банка.
     */
    private const TIME_BUDGET_SECONDS = 15;
    private const LOCK_NAME = 'expire_orders';

    public function __construct(
        private readonly Db $db,
        private readonly AuditLog $audit,
        private readonly PaymentProviderInterface $provider,
        private readonly PaymentService $payments,
    ) {
    }

    /**
     * @return int Число заказов, закрытых в этом запуске (payment_expired или paid по опросу банка).
     */
    public function expireDue(int $limit = 100): int
    {
        $limit = max(1, min(500, $limit));
        // Второй параллельный запуск (WP-CLI + AS) не должен дважды опрашивать банк. Корректность
        // обеспечивают guard-ы под блокировками; GET_LOCK — только экономия HTTP-запросов.
        // Db::getLock() = GET_LOCK(Db::lockName('expire_orders'), 0): имя уникально для БД и префикса.
        if (!$this->db->getLock(self::LOCK_NAME)) {
            return 0;
        }
        try {
            // ix_orders_payment_due (status, payment_due_at): range по 4 статусам.
            // Заказы на ручном разборе (деньги пришли, но сумма/ссылки не сошлись) не освобождаем
            // автоматически и не опрашиваем банк каждую минуту — решение за менеджером.
            $ids = $this->col(
                "SELECT id FROM %i
                  WHERE status IN ('draft', 'pending_payment', 'payment_processing', 'payment_failed')
                    AND payment_due_at <= UTC_TIMESTAMP(6)
                    AND NOT (needs_attention = 1 AND attention_reason IN ('amount_mismatch', 'reference_mismatch'))
                  ORDER BY payment_due_at, id
                  LIMIT %d",
                $this->db->table('book_orders'),
                $limit
            );
            $closed = 0;
            $started = microtime(true);
            foreach ($ids as $id) {
                if (microtime(true) - $started > self::TIME_BUDGET_SECONDS) {
                    break; // остаток подберёт следующий запуск через минуту
                }
                try {
                    if ($this->expireOne((int) $id)) {
                        ++$closed;
                    }
                } catch (\Throwable $e) {
                    // Один «плохой» заказ не останавливает пакет. Без деталей исключения (могут быть данные банка).
                    error_log(sprintf('[uniundata] order expiry failed for order #%d: %s', (int) $id, $e::class));
                }
            }

            return $closed;
        } finally {
            $this->db->releaseLock(self::LOCK_NAME);
        }
    }

    private function expireOne(int $orderId): bool
    {
        $t = $this->tables();

        // 1. Опрос банка вне транзакции.
        $open = $this->rows(
            "SELECT id, status, provider_payment_id, idempotency_key FROM %i
              WHERE order_id = %d AND status IN ('created', 'pending', 'processing')
              ORDER BY attempt_no",
            $t['payments'],
            $orderId
        );
        $bankProcessing = false;
        $bankUnknown = false;
        $bankSessionIds = []; // payment id → provider_payment_id из ответа банка (для created без нашего id)
        foreach ($open as $p) {
            try {
                $res = $this->provider->fetchPayment($p['provider_payment_id'], (string) $p['idempotency_key']);
            } catch (\RuntimeException $e) {
                $bankUnknown = true; // банк недоступен: результат неизвестен
                continue;
            }
            if ($res === null) {
                continue; // банк достоверно не знает платежа: сессия не создавалась
            }
            $bankSessionIds[(int) $p['id']] = $res->providerPaymentId;
            $status = $res->status->value;
            if ($status === 'succeeded') {
                // Оплата прошла, webhook не дошёл (или ещё в пути): тот же путь, что и для webhook.
                $this->payments->applyProviderResult($res, null);

                return true;
            }
            if ($status === 'processing') {
                $this->payments->applyProviderResult($res, null);
                $bankProcessing = true;
            }
            // pending / failed / expired / cancelled — заказ закрываем.
        }

        [, $graceMinutes] = $this->paymentWindow();
        $itemIds = $this->ids($this->col(
            'SELECT book_item_id FROM %i WHERE order_id = %d ORDER BY book_item_id',
            $t['order_items'],
            $orderId
        ));

        // 2. Транзакция.
        $result = $this->db->transaction(
            fn (): array => $this->expireTx($orderId, $itemIds, $bankProcessing, $bankUnknown, $graceMinutes)
        );

        // 3. После COMMIT: уведомить менеджера о продлении и закрыть сессии у банка (best effort).
        if ($result['extended'] && \function_exists('as_enqueue_async_action')) {
            as_enqueue_async_action(PaymentService::HOOK_ATTENTION, ['order_id' => $orderId, 'reason' => 'payment_processing_overdue'], PaymentService::AS_GROUP, true);
        }
        foreach ($result['cancel_sessions'] as $p) {
            $providerPaymentId = $p['provider_payment_id'] ?? ($bankSessionIds[(int) $p['id']] ?? null);
            if ($providerPaymentId === null) {
                continue; // сессии у банка нет — закрывать нечего
            }
            try {
                $this->provider->cancelSession((string) $providerPaymentId, (string) $p['idempotency_key']);
            } catch (\Throwable $e) {
                error_log(sprintf('[uniundata] cancelSession failed for payment #%d: %s', (int) $p['id'], $e::class));
            }
        }

        return $result['expired'];
    }

    /**
     * @param list<int> $itemIds
     * @return array{expired: bool, extended: bool, cancel_sessions: list<array<string, mixed>>}
     */
    private function expireTx(int $orderId, array $itemIds, bool $bankProcessing, bool $bankUnknown, int $graceMinutes): array
    {
        $t = $this->tables();
        $noop = ['expired' => false, 'extended' => false, 'cancel_sessions' => []];

        // (2) Экземпляры заказа.
        $items = $itemIds === [] ? [] : $this->rowsById(
            'SELECT id, availability_status, source_status FROM %i WHERE id IN (' . $this->in($itemIds) . ') ORDER BY id FOR UPDATE',
            $t['items'],
            ...$itemIds
        );
        // (5) Заказ + перепроверка срока в БД.
        $order = $this->row(
            'SELECT id, status, needs_attention, payment_due_extended_at,
                    (payment_due_at <= UTC_TIMESTAMP(6)) AS is_due,
                    (payment_due_at <= UTC_TIMESTAMP(6) - INTERVAL %d MINUTE) AS is_overdue_by_grace
               FROM %i WHERE id = %d FOR UPDATE',
            $graceMinutes,
            $t['orders'],
            $orderId
        );
        if ($order === null || !in_array($order['status'], self::OPEN_ORDER_STATUSES, true) || (int) $order['is_due'] !== 1) {
            return $noop; // оплачен/отменён/продлён параллельно
        }
        $status = (string) $order['status'];

        // (6) Платежи.
        $payments = $this->rows(
            'SELECT id, status, provider_payment_id, idempotency_key FROM %i WHERE order_id = %d ORDER BY attempt_no FOR UPDATE',
            $t['payments'],
            $orderId
        );
        foreach ($payments as $p) {
            if (in_array($p['status'], ['succeeded', 'refunded', 'partially_refunded'], true)) {
                return $noop; // деньги получены — заказ разберёт applyProviderResult, не освобождаем
            }
        }
        // Банк недоступен: ждём ещё один grace-период, затем закрываем (поздний платёж не потеряется).
        if ($bankUnknown && (int) $order['is_overdue_by_grace'] !== 1) {
            return $noop;
        }

        // Банк обрабатывает платёж: однократное продление на grace + флаг для менеджера.
        // Признак однократности — orders.payment_due_extended_at (меняется под блокировкой заказа).
        if ($bankProcessing && $order['payment_due_extended_at'] === null) {
            $this->expectAffected($this->exec(
                "UPDATE %i
                    SET payment_due_at = UTC_TIMESTAMP(6) + INTERVAL %d MINUTE,
                        payment_due_extended_at = UTC_TIMESTAMP(6),
                        attention_reason = IF(needs_attention = 1, attention_reason, 'payment_processing_overdue'),
                        needs_attention = 1
                  WHERE id = %d AND payment_due_extended_at IS NULL
                    AND status IN ('draft', 'pending_payment', 'payment_processing', 'payment_failed')",
                $t['orders'],
                max(1, $graceMinutes),
                $orderId
            ), 1, 'order payment_due_at extension');
            $this->audit->record('order.payment_due_extended', 'order', $orderId, $status, $status, [
                'reason' => 'bank_reports_processing', 'grace_minutes' => $graceMinutes,
            ], 'cron');

            return ['expired' => false, 'extended' => true, 'cancel_sessions' => []];
        }

        // Закрываем заказ.
        $this->expectAffected($this->exec(
            "UPDATE %i SET status = 'payment_expired'
              WHERE id = %d AND status IN ('draft', 'pending_payment', 'payment_processing', 'payment_failed')",
            $t['orders'],
            $orderId
        ), 1, 'order → payment_expired');
        $this->audit->record('order.status_changed', 'order', $orderId, $status, 'payment_expired', ['reason' => 'payment_due_passed'], 'cron');

        $cancel = [];
        foreach ($payments as $p) {
            // Контракт v2: created|pending|processing → expired.
            if (!\in_array($p['status'], ['created', 'pending', 'processing'], true)) {
                continue;
            }
            $this->expectAffected($this->exec(
                "UPDATE %i SET failure_code = COALESCE(failure_code, 'order_expired'), status = 'expired'
                  WHERE id = %d AND status = %s",
                $t['payments'],
                (int) $p['id'],
                (string) $p['status']
            ), 1, 'payment → expired');
            $this->audit->record('payment.status_changed', 'payment', (int) $p['id'], (string) $p['status'], 'expired', ['reason' => 'order_expired'], 'cron');
            $cancel[] = $p; // provider_payment_id может быть null у created — дополняется ответом банка
        }

        // Экземпляры этого заказа: checkout_pending → release target.
        $pending = array_keys(array_filter($items, static fn (array $i): bool => $i['availability_status'] === 'checkout_pending'));
        if ($pending !== []) {
            $this->expectAffected($this->exec(
                "UPDATE %i
                    SET status_changed_at = UTC_TIMESTAMP(6),
                        availability_status = CASE source_status
                            WHEN 'present' THEN 'available'
                            WHEN 'missing' THEN 'sync_missing'
                            WHEN 'withdrawn' THEN 'withdrawn' END
                  WHERE id IN (" . $this->in($pending) . ") AND availability_status = 'checkout_pending'",
                $t['items'],
                ...$pending
            ), count($pending), 'items → release target');
            foreach ($pending as $id) {
                $to = match ((string) $items[$id]['source_status']) {
                    'missing' => 'sync_missing',
                    'withdrawn' => 'withdrawn',
                    default => 'available',
                };
                $this->audit->record('item.status_changed', 'item', $id, 'checkout_pending', $to, ['order_id' => $orderId, 'reason' => 'order_expired'], 'cron');
            }
        }

        return ['expired' => true, 'extended' => false, 'cancel_sessions' => $cancel];
    }

    /** @return array{0: int, 1: int} [payment_ttl_minutes, payment_grace_minutes] */
    private function paymentWindow(): array
    {
        $ttl = (int) get_option('uniundata_payment_ttl_minutes', 30);
        $grace = (int) get_option('uniundata_payment_grace_minutes', 10);

        return [max(5, min(180, $ttl)), max(0, min(60, $grace))];
    }

    // =================================================================================================
    // SQL-хелперы
    // =================================================================================================

    /** @return array<string, string> */
    private function tables(): array
    {
        return [
            'items' => $this->db->table('book_items'),
            'orders' => $this->db->table('book_orders'),
            'order_items' => $this->db->table('book_order_items'),
            'payments' => $this->db->table('book_payments'),
        ];
    }

    /** @return ?array<string, mixed> */
    private function row(string $sql, int|string|float ...$args): ?array
    {
        return $this->db->getRow($sql, ...$args);
    }

    /** @return list<array<string, mixed>> */
    private function rows(string $sql, int|string|float ...$args): array
    {
        return $this->db->getResults($sql, ...$args);
    }

    /** @return array<int, array<string, mixed>> */
    private function rowsById(string $sql, int|string|float ...$args): array
    {
        $out = [];
        foreach ($this->db->getResults($sql, ...$args) as $r) {
            $out[(int) $r['id']] = $r;
        }

        return $out;
    }

    /** @return list<string> Первая колонка результата. */
    private function col(string $sql, int|string|float ...$args): array
    {
        return array_map(static fn (array $r): string => (string) array_values($r)[0], $this->db->getResults($sql, ...$args));
    }

    private function scalar(string $sql, int|string|float ...$args): ?string
    {
        return $this->db->getVar($sql, ...$args);
    }

    /** INSERT/UPDATE: число изменённых строк (UPDATE теми же значениями даёт 0). Ошибка MySQL → исключение. */
    private function exec(string $sql, int|string|float ...$args): int
    {
        return $this->db->execute($sql, ...$args);
    }

    private function expectAffected(int $actual, int $expected, string $what): void
    {
        if ($actual !== $expected) {
            throw new \RuntimeException(sprintf('Unexpected affected rows for %s: %d instead of %d', $what, $actual, $expected));
        }
    }

    /** @param array<mixed> $values @return list<int> */
    private function ids(array $values): array
    {
        $ids = array_values(array_unique(array_map('intval', $values)));
        sort($ids);

        return $ids;
    }

    /** @param list<int> $ids */
    private function in(array $ids): string
    {
        return implode(',', array_fill(0, count($ids), '%d'));
    }
}
