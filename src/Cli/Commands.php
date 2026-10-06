<?php

declare(strict_types=1);

namespace Uniundata\Books\Cli;

use Uniundata\Books\Infrastructure\Db;
use Uniundata\Books\Install\Migrator;
use Uniundata\Books\Install\Roles;
use Uniundata\Books\Plugin;
use Uniundata\Books\Service\OrderExpiryService;
use Uniundata\Books\Service\ReservationExpiryService;
use Uniundata\Books\Sync\SyncService;

/**
 * Обслуживание магазина уникальных книг из WP-CLI.
 *
 * WP-CLI — основной путь для долгих и критичных операций: нет лимитов веб-запроса (max_execution_time,
 * таймаут прокси), понятный код выхода для cron/CI, вывод в журнал деплоя.
 *
 *   wp uniundata sync run [--source=<name>] [--resume] [--allow-mass-missing]
 *   wp uniundata sync status [--source=<name>] [--limit=<n>] [--format=<format>]
 *   wp uniundata sync reextract --source=<name>
 *   wp uniundata expire [--limit=<n>]
 *   wp uniundata privacy-retention [--batch=<n>]
 *   wp uniundata migrate [--rebuild-fulltext]
 *   wp uniundata doctor [--format=<format>]
 */
final class Commands
{
    public function __construct(private readonly Plugin $plugin)
    {
    }

    /** Из Plugin::registerHooks() при WP_CLI. Публичные нестатические методы класса — подкоманды. */
    public static function register(Plugin $plugin): void
    {
        if (!class_exists(\WP_CLI::class)) {
            return;
        }
        \WP_CLI::add_command('uniundata', new self($plugin), [
            'shortdesc' => 'Uniundata Books: синхронизация каталога, снятие просроченных резервов, миграции, проверки.',
        ]);
    }

    /**
     * Синхронизация каталога с внешним источником.
     *
     * ## OPTIONS
     *
     * <action>
     * : Что сделать.
     * ---
     * options:
     *   - run
     *   - status
     *   - reextract
     * ---
     *
     * [--source=<name>]
     * : Источник (имя SourceClientInterface::name()). По умолчанию — все зарегистрированные.
     *
     * [--resume]
     * : run: продолжить с курсора упавшего/зависшего прогона или подхватить живой прогон цепочки
     * Action Scheduler между её шагами. Без флага — полный проход с начала (упавший прогон помечается aborted).
     *
     * [--allow-mass-missing]
     * : run: пометить пропавшие экземпляры, даже если их больше порога (10 % активных). Только осознанно:
     * например, источник действительно снял треть каталога.
     *
     * [--limit=<n>]
     * : status: сколько последних прогонов показать.
     * ---
     * default: 10
     * ---
     *
     * [--format=<format>]
     * : status: формат вывода.
     * ---
     * default: table
     * options:
     *   - table
     *   - json
     *   - csv
     *   - yaml
     * ---
     *
     * ## EXAMPLES
     *
     *     # Первичный импорт / ежедневный прогон вручную
     *     $ wp uniundata sync run --source=primary
     *
     *     # Продолжить после сбоя с сохранённого курсора
     *     $ wp uniundata sync run --source=primary --resume
     *
     *     # Журнал прогонов
     *     $ wp uniundata sync status --source=primary --limit=5
     *
     * @param list<string>          $args
     * @param array<string, string> $assoc
     */
    public function sync(array $args, array $assoc): void
    {
        $action = $args[0] ?? 'status';
        $sync = $this->plugin->get(SyncService::class);
        $source = isset($assoc['source']) ? (string) $assoc['source'] : null;

        if ($action === 'status') {
            $rows = $sync->recentRuns($source, (int) ($assoc['limit'] ?? 10));
            \WP_CLI\Utils\format_items($assoc['format'] ?? 'table', $rows, [
                'id', 'source_name', 'triggered_by', 'status', 'started_at', 'finished_at', 'heartbeat_at', 'is_stale',
                'resumed_from_run_id', 'pass_started_run_id', 'records_received', 'records_created', 'records_updated', 'records_skipped',
                'items_created', 'items_updated', 'items_skipped', 'items_conflicts', 'items_missing', 'items_withdrawn',
                'errors_count',
            ]);

            return;
        }

        $sources = $source !== null ? [$source] : $sync->sourceNames();
        if ($sources === []) {
            \WP_CLI::error('No sync sources registered (filter uniundata_sync_sources).');
        }
        foreach ($sources as $name) {
            if (!\in_array($name, $sync->sourceNames(), true)) {
                \WP_CLI::error(\sprintf('Unknown source "%s". Registered: %s', $name, implode(', ', $sync->sourceNames())));
            }
        }

        if ($action === 'reextract') {
            foreach ($sources as $name) {
                $done = $sync->reextract($name, static function (int $n): void {
                    \WP_CLI::log(\sprintf('  re-extracted %d records', $n));
                });
                if ($done < 0) {
                    \WP_CLI::warning(\sprintf('%s: a sync is running, try later', $name));
                    continue;
                }
                \WP_CLI::success(\sprintf('%s: %d records re-extracted from marc21_raw', $name, $done));
            }

            return;
        }

        $failed = false;
        foreach ($sources as $name) {
            \WP_CLI::log(\sprintf('Syncing %s…', $name));
            $started = microtime(true);
            // Без бюджета времени: WP-CLI держит GET_LOCK до конца прохода.
            $result = $sync->run(
                $name,
                'wp_cli',
                \WP_CLI\Utils\get_flag_value($assoc, 'resume', false),
                null,
                null,
                \WP_CLI\Utils\get_flag_value($assoc, 'allow-mass-missing', false),
            );
            $line = \sprintf('%s: run #%s %s in %.1f s — %s', $name, $result['run_id'] ?? '—', $result['status'],
                microtime(true) - $started, $result['message']);

            match ($result['status']) {
                'succeeded' => \WP_CLI::success($line),
                'partial', 'locked', 'busy' => \WP_CLI::warning($line),
                'misconfigured' => \WP_CLI::error($line),
                default => (function () use ($line, &$failed): void {
                    $failed = true;
                    \WP_CLI::warning($line);
                })(),
            };
            if ($result['run_id'] !== null) {
                $this->printRun($sync, $name, (int) $result['run_id']);
            }
        }
        if ($failed) {
            \WP_CLI::halt(1);
        }
    }

    /**
     * Снять истёкшие резервы и закрыть неоплаченные заказы (то же, что делают задачи Action Scheduler).
     *
     * ## OPTIONS
     *
     * [--limit=<n>]
     * : Сколько резервов за проход (заказов — вдвое меньше). Повторяется, пока очередь не исчерпана.
     * ---
     * default: 200
     * ---
     *
     * ## EXAMPLES
     *
     *     $ wp uniundata expire
     *
     * @param list<string>          $args
     * @param array<string, string> $assoc
     */
    public function expire(array $args, array $assoc): void
    {
        $limit = max(1, (int) ($assoc['limit'] ?? 200));

        $reservations = 0;
        $service = $this->plugin->get(ReservationExpiryService::class);
        do {
            $n = $service->expireDue($limit);
            $reservations += $n;
        } while ($n >= $limit);
        \WP_CLI::log(\sprintf('Reservations expired: %d', $reservations));

        try {
            $orders = 0;
            $orderService = $this->plugin->get(OrderExpiryService::class);
            $orderLimit = max(1, intdiv($limit, 2));
            do {
                $n = $orderService->expireDue($orderLimit);
                $orders += $n;
            } while ($n >= $orderLimit);
            \WP_CLI::log(\sprintf('Orders closed: %d', $orders));
        } catch (\RuntimeException $e) {
            \WP_CLI::warning('Orders were not processed: ' . $e->getMessage());
        }
        \WP_CLI::success('Done.');
    }

    /**
     * Обезличить персональные данные по срокам хранения (то же, что ежедневная задача uniundata_privacy_retention).
     *
     * ## OPTIONS
     *
     * [--batch=<n>]
     * : Размер пакета каждого шага. Повторяется, пока есть что обрабатывать.
     * ---
     * default: 500
     * ---
     *
     * ## EXAMPLES
     *
     *     $ wp uniundata privacy-retention
     *
     * @subcommand privacy-retention
     * @param list<string>          $args
     * @param array<string, string> $assoc
     */
    public function privacyRetention(array $args, array $assoc): void
    {
        $batch = max(1, (int) ($assoc['batch'] ?? 500));
        $total = ['consents' => 0, 'contacts' => 0, 'names' => 0];
        do {
            $r = $this->plugin->privacyRetention($batch);
            foreach ($r as $k => $n) {
                $total[$k] += $n;
            }
        } while (max($r) >= $batch);
        \WP_CLI::success(\sprintf(
            'Consents minimized: %d, order contacts erased: %d, order names anonymized: %d.',
            $total['consents'],
            $total['contacts'],
            $total['names'],
        ));
    }

    /**
     * Применить миграции схемы и привести роли к версии кода. Шаг деплоя.
     *
     * Проверяет MySQL ≥ 8.0.16 и права пользователя БД (нужен REFERENCES), сверяет существующие таблицы
     * со схемой (их могли создать вручную) и печатает предупреждения окружения (time_zone и т. п.).
     *
     * ## OPTIONS
     *
     * [--rebuild-fulltext]
     * : Перестроить FULLTEXT-индексы без стоп-слов (после смены innodb_ft_min_token_size или ручного импорта).
     * На время перестройки поиск по каталогу недоступен.
     *
     * ## EXAMPLES
     *
     *     $ wp uniundata migrate
     *
     * @param list<string>          $args
     * @param array<string, string> $assoc
     */
    public function migrate(array $args, array $assoc): void
    {
        $migrator = $this->plugin->get(Migrator::class);
        try {
            $r = $migrator->migrate(30, (bool) \WP_CLI\Utils\get_flag_value($assoc, 'rebuild-fulltext', false));
        } catch (\RuntimeException $e) {
            \WP_CLI::error($e->getMessage());
        }
        Roles::install();
        foreach ($migrator->environmentWarnings() as $warning) {
            \WP_CLI::warning($warning);
        }
        foreach ($this->plugin->configProblems() as $problem) {
            \WP_CLI::warning($problem);
        }
        if ($r['rebuilt'] !== []) {
            \WP_CLI::log('FULLTEXT rebuilt: ' . implode(', ', $r['rebuilt']));
        }
        if ($r['options_added'] !== []) {
            \WP_CLI::log('Default options added: ' . implode(', ', $r['options_added']));
        }
        \WP_CLI::success($r['applied'] === []
            ? \sprintf('Schema is up to date (version %d). Roles v%d.', $r['to'], Roles::VERSION)
            : \sprintf('Migrated schema %d → %d (applied: %s). Roles v%d.', $r['from'], $r['to'], implode(', ', $r['applied']), Roles::VERSION));
    }

    /**
     * Проверка схемы, настроек и инвариантов данных (должно быть 0 нарушений). Код выхода 1 при нарушениях —
     * для мониторинга.
     *
     * ## OPTIONS
     *
     * [--format=<format>]
     * : Формат вывода.
     * ---
     * default: table
     * options:
     *   - table
     *   - json
     * ---
     *
     * ## EXAMPLES
     *
     *     $ wp uniundata doctor
     *
     * @param list<string>          $args
     * @param array<string, string> $assoc
     */
    public function doctor(array $args, array $assoc): void
    {
        $db = $this->plugin->get(Db::class);
        $migrator = $this->plugin->get(Migrator::class);
        $problems = $migrator->verifySchema();
        foreach ($problems as $problem) {
            \WP_CLI::warning('schema: ' . $problem);
        }
        foreach (array_merge($migrator->environmentWarnings(), $this->plugin->configProblems()) as $warning) {
            \WP_CLI::warning($warning);
        }
        if ($problems !== []) {
            \WP_CLI::halt(1); // без таблиц проверять инварианты нечем
        }

        $t = static fn (string $name): string => $db->table($name);
        $openOrders = "'draft', 'pending_payment', 'payment_processing', 'payment_failed'";
        $stale = SyncService::STALE_AFTER_SECONDS;

        // Те же запросы, что в sql/queries.sql, раздел «Инварианты».
        $checks = [
            'item_reserved_without_active_reservation' =>
                "SELECT i.id FROM {$t('items')} i
                   LEFT JOIN {$t('reservations')} r ON r.active_book_item_id = i.id
                  WHERE i.availability_status = 'reserved' AND r.id IS NULL",
            'active_reservation_item_not_reserved' =>
                "SELECT r.id FROM {$t('reservations')} r
                   JOIN {$t('items')} i ON i.id = r.book_item_id
                  WHERE r.reservation_status = 'active' AND i.availability_status <> 'reserved'",
            'active_cart_item_without_active_reservation' =>
                "SELECT ci.id FROM {$t('cart_items')} ci
                   JOIN {$t('reservations')} r ON r.id = ci.reservation_id
                  WHERE ci.status = 'active' AND r.reservation_status <> 'active'",
            'checkout_pending_without_open_order' =>
                "SELECT i.id FROM {$t('items')} i
                  WHERE i.availability_status = 'checkout_pending'
                    AND NOT EXISTS (SELECT 1 FROM {$t('order_items')} oi JOIN {$t('orders')} o ON o.id = oi.order_id
                                     WHERE oi.book_item_id = i.id AND (o.status IN ({$openOrders}) OR o.needs_attention = 1))",
            'sold_without_sale' =>
                "SELECT i.id FROM {$t('items')} i
                   LEFT JOIN {$t('sales')} s ON s.book_item_id = i.id
                  WHERE i.availability_status = 'sold' AND s.id IS NULL",
            'sale_item_not_sold' =>
                "SELECT s.id FROM {$t('sales')} s
                   JOIN {$t('items')} i ON i.id = s.book_item_id
                  WHERE i.availability_status <> 'sold'",
            'paid_order_item_without_sale' =>
                "SELECT oi.id FROM {$t('orders')} o
                   JOIN {$t('order_items')} oi ON oi.order_id = o.id
                   LEFT JOIN {$t('sales')} s ON s.order_item_id = oi.id
                  WHERE o.status IN ('paid', 'fulfilled', 'completed') AND o.needs_attention = 0 AND s.id IS NULL",
            'payment_refunded_amount_mismatch' =>
                "SELECT p.id FROM {$t('payments')} p
                  WHERE p.refunded_amount <> (SELECT COALESCE(SUM(rf.amount), 0) FROM {$t('refunds')} rf
                                               WHERE rf.payment_id = p.id AND rf.status = 'succeeded')",
            'reservation_overdue_10min' =>
                "SELECT id FROM {$t('reservations')}
                  WHERE reservation_status = 'active' AND expires_at < UTC_TIMESTAMP(6) - INTERVAL 10 MINUTE",
            'order_overdue_10min' =>
                "SELECT id FROM {$t('orders')}
                  WHERE status IN ({$openOrders}) AND needs_attention = 0
                    AND payment_due_at < UTC_TIMESTAMP(6) - INTERVAL 10 MINUTE",
            'refund_stuck_1day' =>
                "SELECT id FROM {$t('refunds')}
                  WHERE status IN ('requested', 'pending') AND requested_at < UTC_TIMESTAMP(6) - INTERVAL 1 DAY",
            'sync_run_stale' =>
                "SELECT id FROM {$t('sync_runs')}
                  WHERE status = 'running' AND heartbeat_at < UTC_TIMESTAMP(6) - INTERVAL {$stale} SECOND",
            'orders_need_attention' =>
                "SELECT id FROM {$t('orders')} WHERE needs_attention = 1",
        ];

        // Не нарушение, а очередь ручной работы: показываем, но код выхода не меняем.
        $informational = ['orders_need_attention'];

        $rows = [];
        $violations = 0;
        foreach ($checks as $name => $sql) {
            $ids = array_column($db->getResults($sql . ' LIMIT 1000'), 'id');
            if (!\in_array($name, $informational, true)) {
                $violations += \count($ids);
            }
            $rows[] = [
                'check' => $name,
                'count' => \count($ids) >= 1000 ? '1000+' : (string) \count($ids),
                'sample_ids' => implode(',', \array_slice($ids, 0, 10)),
            ];
        }
        \WP_CLI\Utils\format_items($assoc['format'] ?? 'table', $rows, ['check', 'count', 'sample_ids']);
        if ($violations > 0) {
            \WP_CLI::halt(1);
        }
        \WP_CLI::success('All invariants hold.');
    }

    private function printRun(SyncService $sync, string $source, int $runId): void
    {
        foreach ($sync->recentRuns($source, 5) as $row) {
            if ((int) $row['id'] === $runId) {
                \WP_CLI::log(\sprintf(
                    '  records: received %s, created %s, updated %s, skipped %s | items: created %s, updated %s, skipped %s, conflicts %s, missing %s, withdrawn %s | errors %s',
                    $row['records_received'], $row['records_created'], $row['records_updated'], $row['records_skipped'],
                    $row['items_created'], $row['items_updated'], $row['items_skipped'], $row['items_conflicts'],
                    $row['items_missing'], $row['items_withdrawn'], $row['errors_count'],
                ));

                return;
            }
        }
    }
}
