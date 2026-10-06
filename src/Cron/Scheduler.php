<?php

declare(strict_types=1);

namespace Uniundata\Books\Cron;

use Uniundata\Books\Infrastructure\AuditLog;
use Uniundata\Books\Infrastructure\Db;
use Uniundata\Books\Plugin;
use Uniundata\Books\Service\OrderExpiryService;
use Uniundata\Books\Service\PaymentService;
use Uniundata\Books\Service\ReservationExpiryService;
use Uniundata\Books\Sync\SyncService;

/**
 * Фоновые задачи на Action Scheduler (standalone-библиотека, composer woocommerce/action-scheduler ^4.2),
 * группа 'uniundata'. Здесь — постановка recurring-задач и обработчики ВСЕХ задач плагина:
 *
 *   recurring  uniundata_expire_reservations  60 с        ReservationExpiryService::expireDue()
 *              uniundata_expire_orders        60 с        OrderExpiryService::expireDue()
 *              uniundata_sync_daily           03:15 UTC  SyncService::run() шагами ≤ 25 с
 *              uniundata_abandon_carts        04:40 UTC  ReservationExpiryService::abandonStaleCarts()
 *              uniundata_privacy_retention    02:10 UTC  Plugin::privacyRetention()
 *   async      uniundata_order_paid             {order_id}          письмо покупателю + do_action('uniundata_after_order_paid')
 *              uniundata_refund_payment         {refund_id}         PaymentService::processRefund()
 *              uniundata_order_needs_attention  {order_id, reason}  письмо менеджеру + do_action('uniundata_alert')
 *              uniundata_user_deleted_cleanup   {user_id}           Plugin::cleanupDeletedUser()
 *              uniundata_sync_continue          {source, run_id}    следующий шаг прогона
 *              uniundata_sync_alert             {run_id, code}      алерт оператору
 *
 * WP-Cron — только запасной триггер очереди. Рекомендуемая схема (docs/11 § 11.2.3): DISABLE_WP_CRON и
 * системный cron раз в минуту `wp action-scheduler run --group=uniundata`.
 *
 * Корректность не зависит от точности cron: сроки резервов и заказов сравниваются с UTC_TIMESTAMP(6)
 * в каждой операции; задача лишь освобождает экземпляры для других покупателей.
 *
 * Дубли задач. Нужен AS ≥ 4.2: там unique сравнивает hook + group + args среди pending/running (в 3.x — без
 * args, и задача по другому заказу молча терялась бы). unique лишь экономит дубли; защита от них — идемпотентные
 * обработчики: при повторе они перепроверяют состояние в БД (возврат — по статусу строки wp_book_refunds,
 * письмо — по записи аудита). Версию AS проверяет Plugin::configProblems().
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
    public const HOOK_ABANDON_CARTS = 'uniundata_abandon_carts';
    public const HOOK_PRIVACY_RETENTION = 'uniundata_privacy_retention';
    public const HOOK_ORDER_PAID = PaymentService::HOOK_ORDER_PAID;
    public const HOOK_REFUND = PaymentService::HOOK_REFUND;
    public const HOOK_ATTENTION = PaymentService::HOOK_ATTENTION;
    public const HOOK_USER_CLEANUP = 'uniundata_user_deleted_cleanup';

    public const RECURRING_HOOKS = [
        self::HOOK_EXPIRE_RESERVATIONS, self::HOOK_EXPIRE_ORDERS, self::HOOK_SYNC_DAILY,
        self::HOOK_ABANDON_CARTS, self::HOOK_PRIVACY_RETENTION,
    ];
    /** Разовые задачи. Хуки группы вне RECURRING_HOOKS + ASYNC_HOOKS считаются снятыми (имена прежних версий). */
    public const ASYNC_HOOKS = [
        self::HOOK_ORDER_PAID, self::HOOK_REFUND, self::HOOK_ATTENTION, self::HOOK_USER_CLEANUP,
        SyncService::HOOK_CONTINUE, SyncService::HOOK_ALERT,
    ];

    /** Минимальная версия Action Scheduler: unique с учётом args. */
    public const MIN_ACTION_SCHEDULER_VERSION = '4.2.0';

    /** Поднимать при смене набора/расписания recurring-задач: они пересоздаются, снятые хуки отменяются. */
    public const SCHEDULE_VERSION = 3;
    public const SCHEDULE_VERSION_OPTION = 'uniundata_schedule_version';

    /** Action Scheduler считает cron-выражения в UTC. */
    public const SYNC_CRON = '15 3 * * *';
    public const ABANDON_CARTS_CRON = '40 4 * * *';
    public const PRIVACY_RETENTION_CRON = '10 2 * * *';
    public const EXPIRY_INTERVAL_SECONDS = 60;
    /** Корзина без активности дольше — abandoned (контракт). */
    public const ABANDON_CART_DAYS = 30;

    /** Бюджет одного шага синхронизации: меньше 30 с — max_execution_time и таймаута claim-а раннера WP-Cron. */
    private const SYNC_STEP_SECONDS = 25.0;
    private const SYNC_RETRY_DELAY_SECONDS = 900;
    private const MAX_SYNC_RETRIES = 3;
    /** Схема отстаёт (идёт деплой) — async-задача откладывается, а не теряется. */
    private const SCHEMA_WAIT_SECONDS = 300;
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
        add_action(self::HOOK_ABANDON_CARTS, [$this, 'abandonCarts'], 10, 0);
        add_action(self::HOOK_PRIVACY_RETENTION, [$this, 'privacyRetention'], 10, 0);
        add_action(self::HOOK_ORDER_PAID, [$this, 'orderPaid'], 10, 1);
        add_action(self::HOOK_REFUND, [$this, 'refundPayment'], 10, 1);
        add_action(self::HOOK_ATTENTION, [$this, 'orderNeedsAttention'], 10, 2);
        add_action(self::HOOK_USER_CLEANUP, [$this, 'userDeletedCleanup'], 10, 1);

        // Параллельные раннеры безопасны: задачу забирает один claim, сервисы перепроверяют всё под FOR UPDATE,
        // а expiry и синхронизация дополнительно сериализованы GET_LOCK(Db::lockName(...)).
        add_filter('action_scheduler_queue_runner_concurrent_batches', static fn (mixed $n): int => max((int) $n, 3));
    }

    /**
     * Идемпотентная постановка recurring-задач. Вызывается на каждом запросе (action_scheduler_init), поэтому
     * проверка в БД — не чаще раза в 10 минут (transient). При смене SCHEDULE_VERSION recurring-задачи
     * пересоздаются, а ожидающие задачи группы со снятыми хуками (например, переименованная в v2 задача
     * брошенных корзин) отменяются. При отстающей схеме не ставится ничего.
     */
    public function ensureRecurring(): void
    {
        if (!\function_exists('as_has_scheduled_action') || !$this->plugin->isSchemaReady()) {
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
            $this->cancelRetiredActions();
        }

        $now = time();
        foreach ([self::HOOK_EXPIRE_RESERVATIONS, self::HOOK_EXPIRE_ORDERS] as $hook) {
            if (!as_has_scheduled_action($hook, [], self::GROUP)) {
                as_schedule_recurring_action($now + self::EXPIRY_INTERVAL_SECONDS, self::EXPIRY_INTERVAL_SECONDS, $hook, [], self::GROUP, true);
            }
        }
        foreach ([
            self::HOOK_SYNC_DAILY => self::SYNC_CRON,
            self::HOOK_ABANDON_CARTS => self::ABANDON_CARTS_CRON,
            self::HOOK_PRIVACY_RETENTION => self::PRIVACY_RETENTION_CRON,
        ] as $hook => $cron) {
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
    // Recurring
    // =============================================================================================

    /** Каждую минуту. Если очередь не исчерпана — сразу ещё один проход (args отличаются от recurring). */
    public function expireReservations(mixed $backlog = null): void
    {
        if (!$this->schemaReady()) {
            return;
        }
        $limit = 200;
        if ($this->plugin->get(ReservationExpiryService::class)->expireDue($limit) >= $limit) {
            as_enqueue_async_action(self::HOOK_EXPIRE_RESERVATIONS, ['backlog' => 1], self::GROUP, true);
        }
    }

    public function expireOrders(mixed $backlog = null): void
    {
        if (!$this->schemaReady()) {
            return;
        }
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
        if (!$this->schemaReady()) {
            return;
        }
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
        if (!$this->schemaReady(SyncService::HOOK_CONTINUE, ['source' => (string) $source, 'run_id' => (int) $runId])) {
            return;
        }
        $result = $this->plugin->get(SyncService::class)->run((string) $source, 'cron', true, self::SYNC_STEP_SECONDS, (int) $runId);
        if (!$this->afterSyncStep((string) $source, $result, 0)) {
            throw new \RuntimeException('Sync step failed: ' . $result['message']);
        }
    }

    /**
     * @param array{run_id: int|null, status: string, continue: bool, message: string} $result
     * @return bool false — шаг завершился ошибкой
     */
    private function afterSyncStep(string $source, array $result, int $attempt): bool
    {
        switch ($result['status']) {
            case 'running':
                if ($result['continue'] && $result['run_id'] !== null) {
                    // Без unique: дубль шага безвреден (второй получит locked/busy и цепочку не продолжит).
                    as_enqueue_async_action(SyncService::HOOK_CONTINUE, ['source' => $source, 'run_id' => $result['run_id']], self::GROUP);
                }

                return true;
            case 'failed':
            case 'lock_lost':
                if ($attempt < self::MAX_SYNC_RETRIES) {
                    // Через 15 минут: прогон без heartbeat станет «зависшим», повтор продолжит с его курсора.
                    as_schedule_single_action(time() + self::SYNC_RETRY_DELAY_SECONDS, self::HOOK_SYNC_DAILY,
                        ['retry', $source, $attempt + 1], self::GROUP);
                }

                return $result['status'] !== 'failed';
            case 'misconfigured':
                return false; // повтор не поможет: нужна настройка (валюта магазина)
            default:
                // succeeded | partial (алерт поставил SyncService) | locked | busy | not_running
                return true;
        }
    }

    /** Алерт оператору: порог «пропавших» превышен или прогон упал. Без ПДн: только счётчики. */
    public function syncAlert(mixed $runId, mixed $code): void
    {
        if (!$this->schemaReady(SyncService::HOOK_ALERT, ['run_id' => (int) $runId, 'code' => (string) $code])) {
            return;
        }
        $db = $this->plugin->get(Db::class);
        $run = $db->getRow(
            "SELECT id, source_name, status, started_at, finished_at, resumed_from_run_id, pass_started_run_id,
                    records_received, items_created, items_updated, items_skipped, items_conflicts, items_missing,
                    items_withdrawn, errors_count
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

    /** Ежедневно: пустые корзины без активности ABANDON_CART_DAYS → abandoned. */
    public function abandonCarts(): void
    {
        if ($this->schemaReady()) {
            $this->plugin->get(ReservationExpiryService::class)->abandonStaleCarts(self::ABANDON_CART_DAYS, 500);
        }
    }

    /** Ежедневно: сроки хранения ПДн (orders.pii_erased_at, IP в согласиях). Пакет за запуск; хвост — завтра. */
    public function privacyRetention(): void
    {
        if ($this->schemaReady()) {
            $this->plugin->privacyRetention();
        }
    }

    // =============================================================================================
    // Async
    // =============================================================================================

    /**
     * Письмо покупателю об оплате (после COMMIT, at-least-once). Повтор задачи не шлёт второе письмо:
     * отметка — запись аудита notification.order_paid (ix_audit_entity). Документы, CRM и т. п. —
     * свои идемпотентные обработчики WP-хука uniundata_after_order_paid.
     */
    public function orderPaid(mixed $orderId): void
    {
        $orderId = (int) $orderId;
        if (!$this->schemaReady(self::HOOK_ORDER_PAID, ['order_id' => $orderId])) {
            return;
        }
        $db = $this->plugin->get(Db::class);
        if ($this->alreadyDone($db, 'notification.order_paid', 'order', $orderId)) {
            return;
        }
        $order = $db->getRow(
            "SELECT id, public_order_id, status, currency, total_amount, customer_email, customer_first_name, pii_erased_at
               FROM {$db->table('orders')} WHERE id = %d",
            $orderId,
        );
        if ($order === null || !\in_array($order['status'], ['paid', 'fulfilled', 'completed'], true)) {
            return;
        }
        $email = (string) $order['customer_email'];
        if ($order['pii_erased_at'] === null && is_email($email)) {
            $titles = array_column($db->getResults(
                "SELECT title_snapshot FROM {$db->table('order_items')} WHERE order_id = %d ORDER BY id",
                $orderId,
            ), 'title_snapshot');
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
        }
        $this->plugin->get(AuditLog::class)->record('notification.order_paid', 'order', $orderId, null, null, [], 'system');
        do_action('uniundata_after_order_paid', $orderId);
    }

    /**
     * Возврат {refund_id}: вся логика — в PaymentService::processRefund() (блокирует строку возврата, вызывает банк
     * вне транзакции с idempotency_key возврата, сам ставит следующую проверку, пока статус не финальный).
     */
    public function refundPayment(mixed $refundId): void
    {
        if (!$this->schemaReady(self::HOOK_REFUND, ['refund_id' => (int) $refundId])) {
            return;
        }
        $this->plugin->get(PaymentService::class)->processRefund((int) $refundId);
    }

    /** Уведомление менеджеру заказов: needs_attention (late_payment_conflict, duplicate_payment, amount_mismatch…). */
    public function orderNeedsAttention(mixed $orderId, mixed $reason): void
    {
        if (!$this->schemaReady(self::HOOK_ATTENTION, ['order_id' => (int) $orderId, 'reason' => (string) $reason])) {
            return;
        }
        $db = $this->plugin->get(Db::class);
        $order = $db->getRow(
            "SELECT id, public_order_id, status, attention_reason FROM {$db->table('orders')} WHERE id = %d",
            (int) $orderId,
        );
        if ($order === null) {
            return;
        }
        do_action('uniundata_alert', 'order', (string) $reason, $order);
        // Только номер и статус: адрес и телефон в письма менеджерам не попадают (docs/07 § 7.10).
        $this->mailOps(
            \sprintf('[%s] Order %s needs attention: %s', wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES), $order['public_order_id'], (string) $reason),
            \sprintf("Order: %s\nStatus: %s\nReason: %s\n", $order['public_order_id'], $order['status'], $order['attention_reason'] ?? (string) $reason),
        );
    }

    public function userDeletedCleanup(mixed $userId): void
    {
        if ($this->schemaReady(self::HOOK_USER_CLEANUP, ['user_id' => (int) $userId])) {
            $this->plugin->cleanupDeletedUser((int) $userId);
        }
    }

    // =============================================================================================
    // Утилиты
    // =============================================================================================

    /**
     * Схема на версии кода? Если нет (идёт деплой, миграция упала) — recurring-задача просто пропускает
     * запуск, а разовая (передан $hook) переставляется через 5 минут, чтобы не потерять письмо или возврат.
     *
     * @param array<string, int|string> $args
     */
    private function schemaReady(?string $hook = null, array $args = []): bool
    {
        if ($this->plugin->isSchemaReady()) {
            return true;
        }
        if ($hook !== null && \function_exists('as_schedule_single_action')) {
            as_schedule_single_action(time() + self::SCHEMA_WAIT_SECONDS, $hook, $args, self::GROUP);
        }
        error_log(\sprintf('[uniundata] %s postponed: database schema is not up to date', $hook ?? 'recurring task'));

        return false;
    }

    /**
     * Отменяет ожидающие задачи группы, чьих хуков в этой версии нет: обработчик у них не зарегистрирован,
     * и AS выполнял бы их вхолостую (recurring — бесконечно). Вызывается только при смене SCHEDULE_VERSION.
     */
    private function cancelRetiredActions(): void
    {
        if (!class_exists(\ActionScheduler_Store::class)) {
            return;
        }
        $known = array_merge(self::RECURRING_HOOKS, self::ASYNC_HOOKS);
        $store = \ActionScheduler_Store::instance();
        $ids = as_get_scheduled_actions(
            ['group' => self::GROUP, 'status' => \ActionScheduler_Store::STATUS_PENDING, 'per_page' => 1000],
            'ids',
        );
        foreach ($ids as $id) {
            $hook = $store->fetch_action((int) $id)->get_hook();
            if (!\in_array($hook, $known, true)) {
                $store->cancel_action((int) $id);
                error_log(\sprintf('[uniundata] retired action #%d (%s) cancelled', (int) $id, $hook));
            }
        }
    }

    private function alreadyDone(Db $db, string $action, string $entityType, int $entityId): bool
    {
        // ix_audit_entity (entity_type, entity_id, occurred_at)
        return $db->getVar(
            "SELECT EXISTS (SELECT 1 FROM {$db->table('audit_log')}
                             WHERE entity_type = %s AND entity_id = %d AND action = %s)",
            $entityType,
            $entityId,
            $action,
        ) === '1';
    }

    private function mailOps(string $subject, string $body): void
    {
        $to = apply_filters('uniundata_ops_email', get_option('admin_email'));
        if (\is_string($to) && is_email($to)) {
            wp_mail($to, $subject, $body);
        }
    }
}
