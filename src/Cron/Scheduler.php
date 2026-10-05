<?php

declare(strict_types=1);

namespace Uniundata\Books\Cron;

use Uniundata\Books\Infrastructure\AuditLog;
use Uniundata\Books\Infrastructure\Db;
use Uniundata\Books\Payment\PaymentProviderInterface;
use Uniundata\Books\Plugin;
use Uniundata\Books\Service\OrderExpiryService;
use Uniundata\Books\Service\PaymentService;
use Uniundata\Books\Service\ReservationExpiryService;
use Uniundata\Books\Sync\SyncService;

/**
 * Фоновые задачи на Action Scheduler (standalone-библиотека, composer woocommerce/action-scheduler ^4.2).
 *
 * WP-Cron здесь — только запасной триггер очереди. Рекомендуемая схема (docs/01 § 1.7.2):
 *   wp-config.php:  define('DISABLE_WP_CRON', true);
 *   crontab (пользователь веб-сервера):
 *     * * * * * cd /var/www/site && flock -n /run/lock/uu-critical.lock wp action-scheduler run --hooks=uniundata_expire_reservations,uniundata_expire_orders --quiet
 *     * * * * * cd /var/www/site && flock -n /run/lock/uu-queue.lock    wp action-scheduler run --group=uniundata --quiet
 *     * * * * * cd /var/www/site && flock -n /run/lock/wp-cron.lock     wp cron event run --due-now --quiet
 *   Минимум — одна строка `* * * * * wp action-scheduler run --group=uniundata`; отдельный раннер для expiry
 *   нужен, чтобы долгий шаг синхронизации не задерживал освобождение резервов.
 *
 * Корректность не зависит от точности cron: сроки резервов и заказов сравниваются с UTC_TIMESTAMP(6)
 * в каждой операции; задача лишь освобождает экземпляры для других покупателей.
 *
 * Recurring-задачи ставятся идемпотентно (as_has_scheduled_action + unique) на action_scheduler_init:
 * при активации плагина хранилище AS может быть ещё не готово. Смена расписания в коде —
 * увеличить SCHEDULE_VERSION: задачи пересоздаются один раз.
 *
 * Action Scheduler вызывает обработчик как do_action_ref_array($hook, array_values($args)) — аргументы
 * приходят ПОЗИЦИОННО, поэтому сигнатуры ниже повторяют порядок ключей в местах постановки.
 */
final class Scheduler
{
    public const GROUP = 'uniundata';

    public const HOOK_EXPIRE_RESERVATIONS = 'uniundata_expire_reservations';
    public const HOOK_EXPIRE_ORDERS = 'uniundata_expire_orders';
    /** args: [triggered_by = 'cron', source = '' (все), attempt = 0]; ручной запуск — AdminController ['admin', source]. */
    public const HOOK_SYNC_DAILY = 'uniundata_sync_daily';
    public const HOOK_ABANDON_CARTS = 'uniundata_abandon_stale_carts';

    public const RECURRING_HOOKS = [
        self::HOOK_EXPIRE_RESERVATIONS, self::HOOK_EXPIRE_ORDERS, self::HOOK_SYNC_DAILY, self::HOOK_ABANDON_CARTS,
    ];

    public const SCHEDULE_VERSION = 1;
    public const SCHEDULE_VERSION_OPTION = 'uniundata_schedule_version';

    /** Ежедневная синхронизация: 03:15 UTC (Action Scheduler считает cron-выражения в UTC). */
    public const SYNC_CRON = '15 3 * * *';
    public const ABANDON_CARTS_CRON = '40 4 * * *';
    public const EXPIRY_INTERVAL_SECONDS = 60;

    /** Бюджет одного шага синхронизации: меньше 30 с — таймаута claim-а раннера WP-Cron. */
    private const SYNC_STEP_SECONDS = 25.0;
    private const SYNC_RETRY_DELAY_SECONDS = 900;
    private const MAX_SYNC_RETRIES = 3;
    private const REFUND_RETRY_DELAY_SECONDS = 600;
    private const MAX_REFUND_ATTEMPTS = 5;
    private const CHECK_TRANSIENT = 'uniundata_schedule_checked';

    public function __construct(private readonly Plugin $plugin)
    {
    }

    public function register(): void
    {
        if (did_action('action_scheduler_init')) {
            $this->ensureRecurring();
        } else {
            add_action('action_scheduler_init', [$this, 'ensureRecurring']);
        }

        add_action(self::HOOK_EXPIRE_RESERVATIONS, [$this, 'expireReservations'], 10, 1);
        add_action(self::HOOK_EXPIRE_ORDERS, [$this, 'expireOrders'], 10, 1);
        add_action(self::HOOK_SYNC_DAILY, [$this, 'syncDaily'], 10, 3);
        add_action(SyncService::HOOK_CONTINUE, [$this, 'syncContinue'], 10, 2);
        add_action(SyncService::HOOK_ALERT, [$this, 'syncAlert'], 10, 2);
        add_action(self::HOOK_ABANDON_CARTS, [$this, 'abandonStaleCarts'], 10, 0);
        add_action(PaymentService::HOOK_ORDER_PAID, [$this, 'orderPaid'], 10, 1);
        add_action(PaymentService::HOOK_REFUND, [$this, 'refundPayment'], 10, 4);
        add_action(PaymentService::HOOK_ATTENTION, [$this, 'orderNeedsAttention'], 10, 2);

        // Две строки crontab + штатный раннер AS: по умолчанию одновременно разрешён 1 claim.
        // Параллельные раннеры безопасны: задачу забирает один claim, сервисы перепроверяют всё под FOR UPDATE.
        add_filter('action_scheduler_queue_runner_concurrent_batches', static fn (mixed $n): int => max((int) $n, 3));
    }

    /**
     * Идемпотентная постановка recurring-задач. Вызывается на каждом запросе (action_scheduler_init), поэтому
     * проверка в БД — не чаще раза в 10 минут (transient), а при смене SCHEDULE_VERSION — пересоздание.
     */
    public function ensureRecurring(): void
    {
        if (!\function_exists('as_has_scheduled_action')) {
            return;
        }
        $versionChanged = (int) get_option(self::SCHEDULE_VERSION_OPTION, 0) !== self::SCHEDULE_VERSION;
        if (!$versionChanged && get_transient(self::CHECK_TRANSIENT) !== false) {
            return;
        }
        if ($versionChanged) {
            foreach (self::RECURRING_HOOKS as $hook) {
                as_unschedule_all_actions($hook, [], self::GROUP);
            }
        }

        $now = time();
        foreach ([self::HOOK_EXPIRE_RESERVATIONS, self::HOOK_EXPIRE_ORDERS] as $hook) {
            if (!as_has_scheduled_action($hook, [], self::GROUP)) {
                as_schedule_recurring_action($now + self::EXPIRY_INTERVAL_SECONDS, self::EXPIRY_INTERVAL_SECONDS, $hook, [], self::GROUP, true);
            }
        }
        foreach ([self::HOOK_SYNC_DAILY => self::SYNC_CRON, self::HOOK_ABANDON_CARTS => self::ABANDON_CARTS_CRON] as $hook => $cron) {
            if (!as_has_scheduled_action($hook, [], self::GROUP)) {
                as_schedule_cron_action($now, $cron, $hook, [], self::GROUP, true);
            }
        }

        if ($versionChanged) {
            update_option(self::SCHEDULE_VERSION_OPTION, self::SCHEDULE_VERSION, true);
        }
        set_transient(self::CHECK_TRANSIENT, 1, 10 * MINUTE_IN_SECONDS);
    }

    // =============================================================================================
    // Обработчики
    // =============================================================================================

    /** Каждую минуту. Если очередь не исчерпана — сразу ещё один проход (args отличаются от recurring). */
    public function expireReservations(mixed $backlog = null): void
    {
        $limit = 200;
        $expired = $this->plugin->get(ReservationExpiryService::class)->expireDue($limit);
        if ($expired >= $limit) {
            as_enqueue_async_action(self::HOOK_EXPIRE_RESERVATIONS, ['backlog' => 1], self::GROUP, true);
        }
    }

    public function expireOrders(mixed $backlog = null): void
    {
        try {
            $service = $this->plugin->get(OrderExpiryService::class);
        } catch (\RuntimeException $e) {
            // Банк не настроен — заказов с оплатой быть не может; не засоряем журнал AS ошибкой каждую минуту.
            error_log('[uniundata] expire_orders skipped: ' . $e->getMessage());

            return;
        }
        $limit = 100;
        if ($service->expireDue($limit) >= $limit) {
            as_enqueue_async_action(self::HOOK_EXPIRE_ORDERS, ['backlog' => 1], self::GROUP, true);
        }
    }

    /**
     * Ежедневный запуск (cron, args пустые), ручной (AdminController: ['admin', source]) или повтор после
     * сбоя (['retry', source, attempt]). Каждый вызов — один шаг ≤ 25 с; продолжение — HOOK_CONTINUE.
     */
    public function syncDaily(mixed $triggeredBy = 'cron', mixed $source = '', mixed $attempt = 0): void
    {
        $trigger = \in_array($triggeredBy, ['cron', 'admin', 'retry'], true) ? (string) $triggeredBy : 'cron';
        $sync = $this->plugin->get(SyncService::class);
        $sources = \is_string($source) && $source !== '' ? [$source] : $sync->sourceNames();
        if ($sources === []) {
            error_log('[uniundata] sync: no sources registered (filter uniundata_sync_sources)');

            return;
        }

        $failed = [];
        foreach ($sources as $name) {
            if (!\in_array($name, $sync->sourceNames(), true)) {
                $failed[] = $name . ': unknown source';
                continue;
            }
            // resume = true: после сбоя продолжаем с курсора, а не начинаем полный проход заново.
            $result = $sync->run($name, $trigger, true, self::SYNC_STEP_SECONDS);
            if (!$this->afterSyncStep($name, $result, (int) $attempt)) {
                $failed[] = $name . ': ' . $result['message'];
            }
        }
        if ($failed !== []) {
            // Исключение помечает задачу AS как failed — это видно в «Инструменты → Запланированные действия».
            throw new \RuntimeException('Sync failed: ' . implode('; ', $failed));
        }
    }

    /** Следующий шаг того же прогона. */
    public function syncContinue(mixed $source, mixed $runId): void
    {
        $result = $this->plugin->get(SyncService::class)->run((string) $source, 'cron', true, self::SYNC_STEP_SECONDS, (int) $runId);
        if (!$this->afterSyncStep((string) $source, $result, 0)) {
            throw new \RuntimeException('Sync step failed: ' . $result['message']);
        }
    }

    /**
     * @param array{run_id: int|null, status: string, continue: bool, message: string} $result
     * @return bool false — шаг завершился ошибкой (повтор уже запланирован)
     */
    private function afterSyncStep(string $source, array $result, int $attempt): bool
    {
        switch ($result['status']) {
            case 'running':
                if ($result['continue'] && $result['run_id'] !== null) {
                    as_enqueue_async_action(SyncService::HOOK_CONTINUE, ['source' => $source, 'run_id' => $result['run_id']], self::GROUP, true);
                }

                return true;
            case 'failed':
            case 'lock_lost':
                if ($attempt < self::MAX_SYNC_RETRIES) {
                    // Через 15 минут: прогон без heartbeat станет «зависшим», повтор продолжит с его курсора.
                    as_schedule_single_action(time() + self::SYNC_RETRY_DELAY_SECONDS, self::HOOK_SYNC_DAILY,
                        ['retry', $source, $attempt + 1], self::GROUP, true);
                }

                return $result['status'] !== 'failed';
            default:
                // succeeded | partial (алерт поставил SyncService) | locked | busy | not_running
                return true;
        }
    }

    /** Алерт оператору: порог «пропавших» превышен или прогон упал. Без PII: только счётчики. */
    public function syncAlert(mixed $runId, mixed $code): void
    {
        $db = $this->plugin->get(Db::class);
        $run = $db->getRow(
            "SELECT id, source_name, status, started_at, finished_at, records_received, items_created, items_updated,
                    items_conflicts, items_missing, items_withdrawn, errors_count
               FROM {$db->table('sync_runs')} WHERE id = %d",
            (int) $runId,
        );
        if ($run === null) {
            return;
        }
        do_action('uniundata_alert', 'sync', (string) $code, $run); // интеграция с мониторингом (Slack, Sentry…)

        $lines = [];
        foreach ($run as $k => $v) {
            $lines[] = $k . ': ' . ($v ?? '—');
        }
        $this->mailOps(
            \sprintf('[%s] Sync alert: %s (run #%d, %s)', wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES), (string) $code, (int) $runId, (string) $run['source_name']),
            implode("\n", $lines) . "\n\nwp uniundata sync status --source=" . $run['source_name'],
        );
    }

    public function abandonStaleCarts(): void
    {
        $this->plugin->get(ReservationExpiryService::class)->abandonStaleCarts(30, 500);
    }

    /**
     * Письмо покупателю об оплате (после COMMIT, at-least-once). Повтор задачи не шлёт второе письмо:
     * отметка — запись аудита notification.order_paid (ix_audit_entity).
     */
    public function orderPaid(mixed $orderId): void
    {
        $orderId = (int) $orderId;
        $db = $this->plugin->get(Db::class);
        if ($this->alreadyDone($db, 'notification.order_paid', 'order', $orderId)) {
            return;
        }
        $order = $db->getRow(
            "SELECT id, public_order_id, status, currency, total_amount, customer_email, customer_first_name
               FROM {$db->table('orders')} WHERE id = %d",
            $orderId,
        );
        if ($order === null || !\in_array($order['status'], ['paid', 'fulfilled', 'completed'], true)) {
            return;
        }
        $titles = array_column($db->getResults(
            "SELECT title_snapshot FROM {$db->table('order_items')} WHERE order_id = %d ORDER BY id",
            $orderId,
        ), 'title_snapshot');

        $email = (string) $order['customer_email'];
        if (str_ends_with($email, '.invalid') || !is_email($email)) {
            return; // обезличенный заказ (docs/07 § 7.11)
        }
        $mail = apply_filters('uniundata_order_paid_email', [
            /* translators: %s: public order number */
            'subject' => \sprintf(__('Order %s is paid', 'uniundata-books'), $order['public_order_id']),
            'body' => \sprintf(
                /* translators: 1: first name, 2: order number, 3: list of books, 4: amount */
                __("Hello %1\$s,\n\nwe have received the payment for order %2\$s.\n\n%3\$s\n\nTotal: %4\$s\n", 'uniundata-books'),
                $order['customer_first_name'],
                $order['public_order_id'],
                implode("\n", array_map(static fn (string $t): string => '— ' . $t, $titles)),
                number_format(((int) $order['total_amount']) / 100, 2, '.', ' ') . ' ' . $order['currency'],
            ),
        ], $order);
        if (!wp_mail($email, (string) $mail['subject'], (string) $mail['body'])) {
            throw new \RuntimeException(\sprintf('wp_mail failed for order #%d', $orderId)); // задача AS → failed
        }
        $this->plugin->get(AuditLog::class)->record('notification.order_paid', 'order', $orderId, null, null, [], 'system');
        do_action('uniundata_order_paid_effects', $orderId); // документы, интеграции — свои идемпотентные обработчики
    }

    /**
     * Возврат денег (duplicate_payment, late_payment_conflict). Идемпотентность — ключ возврата,
     * детерминированный от (payment, amount, reason): повтор задачи не создаёт второй возврат у банка.
     * Итоговый статус возврата приходит webhook-ом и применяется PaymentService.
     */
    public function refundPayment(mixed $paymentId, mixed $amount, mixed $reason, mixed $attempt = 0): void
    {
        $paymentId = (int) $paymentId;
        $amount = (int) $amount;
        $reason = preg_match('/^[a-z_]{1,48}$/', (string) $reason) === 1 ? (string) $reason : 'refund';
        $db = $this->plugin->get(Db::class);
        $key = self::uuidFromHash('refund|' . $paymentId . '|' . $amount . '|' . $reason);
        if ($this->alreadyDone($db, 'payment.refund_requested', 'payment', $paymentId, $key)) {
            return;
        }
        $payment = $db->getRow(
            "SELECT id, order_id, provider_payment_id, currency, amount FROM {$db->table('payments')} WHERE id = %d",
            $paymentId,
        );
        if ($payment === null || $payment['provider_payment_id'] === null || $amount <= 0 || $amount > (int) $payment['amount']) {
            throw new \RuntimeException(\sprintf('Refund for payment #%d cannot be requested', $paymentId));
        }

        try {
            $refundId = $this->plugin->get(PaymentProviderInterface::class)
                ->refund((string) $payment['provider_payment_id'], $amount, (string) $payment['currency'], $key);
        } catch (\Throwable $e) {
            if ((int) $attempt + 1 < self::MAX_REFUND_ATTEMPTS) {
                as_schedule_single_action(time() + self::REFUND_RETRY_DELAY_SECONDS, PaymentService::HOOK_REFUND,
                    ['payment_id' => $paymentId, 'amount' => $amount, 'reason' => $reason, 'attempt' => (int) $attempt + 1], self::GROUP);
            }
            throw new \RuntimeException(\sprintf('Refund request for payment #%d failed: %s', $paymentId, $e::class), 0, $e);
        }

        $this->plugin->get(AuditLog::class)->record('payment.refund_requested', 'payment', $paymentId, null, null, [
            'order_id' => (int) $payment['order_id'],
            'amount' => $amount,
            'reason' => $reason,
            'refund_key' => $key,
            'provider_refund_id' => $refundId,
        ], 'system');
    }

    /** Уведомление менеджеру заказов: needs_attention (late_payment_conflict, duplicate_payment, amount_mismatch…). */
    public function orderNeedsAttention(mixed $orderId, mixed $reason): void
    {
        $db = $this->plugin->get(Db::class);
        $order = $db->getRow(
            "SELECT id, public_order_id, status, attention_reason FROM {$db->table('orders')} WHERE id = %d",
            (int) $orderId,
        );
        if ($order === null) {
            return;
        }
        do_action('uniundata_alert', 'order', (string) $reason, $order);
        $this->mailOps(
            \sprintf('[%s] Order %s needs attention: %s', wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES), $order['public_order_id'], (string) $reason),
            \sprintf("Order: %s\nStatus: %s\nReason: %s\n", $order['public_order_id'], $order['status'], $order['attention_reason'] ?? (string) $reason),
        );
    }

    // =============================================================================================
    // Утилиты
    // =============================================================================================

    private function alreadyDone(Db $db, string $action, string $entityType, int $entityId, ?string $refundKey = null): bool
    {
        // ix_audit_entity (entity_type, entity_id, occurred_at)
        $rows = $db->getResults(
            "SELECT context FROM {$db->table('audit_log')}
              WHERE entity_type = %s AND entity_id = %d AND action = %s",
            $entityType,
            $entityId,
            $action,
        );
        if ($refundKey === null) {
            return $rows !== [];
        }
        foreach ($rows as $row) {
            $ctx = json_decode((string) $row['context'], true);
            if (\is_array($ctx) && ($ctx['refund_key'] ?? null) === $refundKey) {
                return true;
            }
        }

        return false;
    }

    private function mailOps(string $subject, string $body): void
    {
        $to = apply_filters('uniundata_ops_email', get_option('admin_email'));
        if (\is_string($to) && is_email($to)) {
            wp_mail($to, $subject, $body);
        }
    }

    /** Детерминированный UUID (формат v4 для провайдеров, которые проверяют версию) из хэша строки. */
    private static function uuidFromHash(string $value): string
    {
        $h = substr(hash('sha256', $value), 0, 32);
        $h[12] = '4';
        $h[16] = dechex((hexdec($h[16]) & 0x3) | 0x8);

        return \sprintf('%s-%s-%s-%s-%s', substr($h, 0, 8), substr($h, 8, 4), substr($h, 12, 4), substr($h, 16, 4), substr($h, 20, 12));
    }
}
