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
 *     processing → applyProviderResult() + однократное продление на grace и needs_attention.
 *  2. Транзакция items (asc) → order → payments: перепроверка (заказ всё ещё открыт и просрочен, успешных
 *     платежей нет), затем order → payment_expired, платежи → expired, экземпляры checkout_pending этого
 *     заказа → release target.
 *  3. После COMMIT — cancelSession у банка (best effort). Если деньги всё-таки придут — ветка позднего платежа.
 *
 * Гонка с webhook: обе стороны блокируют items → order; кто второй, перепроверяет статус под блокировкой.
 */
final class OrderExpiryService
{
    private const OPEN_ORDER_STATUSES = ['draft', 'pending_payment', 'payment_processing', 'payment_failed'];
    /** Бюджет одного запуска: HTTP-опрос банка не должен пережить таймаут задачи Action Scheduler. */
    private const TIME_BUDGET_SECONDS = 40;
    private const LOCK_NAME = 'uniundata_expire_orders';
    private const EXTENSION_AUDIT_ACTION = 'order.payment_due_extended';

    private \wpdb $wpdb;

    public function __construct(
        private readonly Db $db,
        private readonly AuditLog $audit,
        private readonly PaymentProviderInterface $provider,
        private readonly PaymentService $payments,
        ?\wpdb $wpdb = null,
    ) {
        $this->wpdb = $wpdb ?? $GLOBALS['wpdb'];
    }

    /**
     * @return int Число заказов, закрытых в этом запуске (payment_expired или paid по опросу банка).
     */
    public function expireDue(int $limit = 100): int
    {
        $limit = max(1, min(500, $limit));
        // Второй параллельный запуск (WP-CLI + AS) не должен дважды опрашивать банк. Корректность
        // обеспечивают guard-ы под блокировками; GET_LOCK — только экономия HTTP-запросов.
        if ((int) $this->scalar('SELECT GET_LOCK(%s, 0)', self::LOCK_NAME) !== 1) {
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
            $this->scalar('SELECT RELEASE_LOCK(%s)', self::LOCK_NAME);
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

        // 3. После COMMIT: закрыть сессии у банка (best effort).
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
     * @return array{expired: bool, cancel_sessions: list<array<string, mixed>>}
     */
    private function expireTx(int $orderId, array $itemIds, bool $bankProcessing, bool $bankUnknown, int $graceMinutes): array
    {
        $t = $this->tables();
        $noop = ['expired' => false, 'cancel_sessions' => []];

        // (2) Экземпляры заказа.
        $items = $itemIds === [] ? [] : $this->rowsById(
            'SELECT id, availability_status, source_status FROM %i WHERE id IN (' . $this->in($itemIds) . ') ORDER BY id FOR UPDATE',
            $t['items'],
            ...$itemIds
        );
        // (5) Заказ + перепроверка срока в БД.
        $order = $this->row(
            'SELECT id, status, needs_attention,
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
        if ($bankProcessing && !$this->wasExtended($orderId)) {
            $this->expectAffected($this->exec(
                "UPDATE %i
                    SET payment_due_at = UTC_TIMESTAMP(6) + INTERVAL %d MINUTE,
                        attention_reason = IF(needs_attention = 1, attention_reason, 'payment_processing_overdue'),
                        needs_attention = 1
                  WHERE id = %d AND status IN ('draft', 'pending_payment', 'payment_processing', 'payment_failed')",
                $t['orders'],
                max(1, $graceMinutes),
                $orderId
            ), 1, 'order payment_due_at extension');
            $this->audit->record(self::EXTENSION_AUDIT_ACTION, 'order', $orderId, $status, $status, [
                'reason' => 'bank_reports_processing', 'grace_minutes' => $graceMinutes,
            ], 'cron');

            return $noop;
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
            // По контракту: pending/processing → expired; created → failed (прямого created → expired нет).
            $to = match ($p['status']) {
                'pending', 'processing' => 'expired',
                'created' => 'failed',
                default => null,
            };
            if ($to === null) {
                continue;
            }
            $this->expectAffected($this->exec(
                'UPDATE %i SET failure_code = IF(%s = \'failed\', \'order_expired\', failure_code), status = %s
                  WHERE id = %d AND status = %s',
                $t['payments'],
                $to,
                $to,
                (int) $p['id'],
                (string) $p['status']
            ), 1, 'payment → ' . $to);
            $this->audit->record('payment.status_changed', 'payment', (int) $p['id'], (string) $p['status'], $to, ['reason' => 'order_expired'], 'cron');
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

        return ['expired' => true, 'cancel_sessions' => $cancel];
    }

    /** Продление делается один раз: признак — запись аудита (append-only, в той же транзакции, что и продление). */
    private function wasExtended(int $orderId): bool
    {
        return $this->scalar(
            'SELECT 1 FROM %i WHERE entity_type = %s AND entity_id = %d AND action = %s LIMIT 1',
            $this->db->table('book_audit_log'),
            'order',
            $orderId,
            self::EXTENSION_AUDIT_ACTION
        ) !== null;
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
    private function row(string $sql, mixed ...$args): ?array
    {
        $r = $this->wpdb->get_row($this->wpdb->prepare($sql, ...$args), ARRAY_A);
        $this->assertNoDbError();

        return is_array($r) ? $r : null;
    }

    /** @return list<array<string, mixed>> */
    private function rows(string $sql, mixed ...$args): array
    {
        $r = $this->wpdb->get_results($this->wpdb->prepare($sql, ...$args), ARRAY_A);
        $this->assertNoDbError();

        return is_array($r) ? $r : [];
    }

    /** @return array<int, array<string, mixed>> */
    private function rowsById(string $sql, mixed ...$args): array
    {
        $out = [];
        foreach ($this->rows($sql, ...$args) as $r) {
            $out[(int) $r['id']] = $r;
        }

        return $out;
    }

    /** @return list<string> */
    private function col(string $sql, mixed ...$args): array
    {
        $r = $this->wpdb->get_col($this->wpdb->prepare($sql, ...$args));
        $this->assertNoDbError();

        return array_values(array_map('strval', $r));
    }

    private function scalar(string $sql, mixed ...$args): ?string
    {
        $r = $this->wpdb->get_var($this->wpdb->prepare($sql, ...$args));
        $this->assertNoDbError();

        return $r === null ? null : (string) $r;
    }

    private function exec(string $sql, mixed ...$args): int
    {
        $r = $this->wpdb->query($this->wpdb->prepare($sql, ...$args));
        if ($r === false) {
            throw new \RuntimeException('DB error: ' . $this->wpdb->last_error, $this->db->lastErrno());
        }

        return (int) $r;
    }

    private function assertNoDbError(): void
    {
        if ($this->wpdb->last_error !== '') {
            throw new \RuntimeException('DB error: ' . $this->wpdb->last_error, $this->db->lastErrno());
        }
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
