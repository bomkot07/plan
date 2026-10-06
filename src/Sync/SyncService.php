<?php

declare(strict_types=1);

namespace Uniundata\Books\Sync;

use Uniundata\Books\Domain\DomainError;
use Uniundata\Books\Infrastructure\AuditLog;
use Uniundata\Books\Infrastructure\Db;

/**
 * Ежедневная синхронизация каталога: идемпотентный upsert пакетами по 200 записей.
 *
 * Защита от параллельных запусков — два слоя:
 *   1. GET_LOCK(Db::lockName('sync_<source>'), 0) — пока процесс работает. Блокировка принадлежит
 *      соединению MySQL: умер процесс или оборвалось соединение — она снята сервером. Db::lockName() добавляет
 *      отпечаток БД и префикса: имена GET_LOCK общие для всего сервера MySQL (new.libsmr.ru и shop.libsmr.ru).
 *   2. Строка wp_book_sync_runs со status = 'running' + UNIQUE(running_source) — «аренда» прогона между
 *      процессами (цепочка задач Action Scheduler держит прогон, но не блокировку, между шагами).
 *      heartbeat_at обновляется каждым пакетом; прогон без heartbeat дольше STALE_AFTER_SECONDS (900 с)
 *      считается зависшим: следующий запуск помечает его aborted и продолжает с его source_cursor.
 *
 * Состояние прохода — в колонках прогона:
 *   pass_started_run_id — первый прогон полного прохода (для свежего прогона — он сам): экземпляры с
 *                         last_seen_sync_run_id < pass_started_run_id не встречались в этом проходе;
 *   resumed_from_run_id — упавший/зависший прогон, который продолжает этот;
 *   source_cursor       — NULL: читать источник с начала; курсор источника: продолжить с него;
 *                         '' (пустая строка): источник прочитан полностью, идёт проход «пропавших».
 *
 * Транзакции пакета (HTTP к источнику — только вне транзакций):
 *   A. записи: строка прогона → wp_book_records (FOR UPDATE по возрастанию id, INSERT/UPDATE) →
 *      идентификаторы, персоны, рубрики; счётчики records_*;
 *   B. экземпляры: строка прогона → wp_book_items (FOR UPDATE по возрастанию id, INSERT/UPDATE); счётчики
 *      items_*, ошибки и курсор — в той же транзакции, поэтому повтор пакета после падения ничего не удваивает.
 * X-блокировки записей и экземпляров НИКОГДА не берутся в одной транзакции (глобальный порядок docs/08:
 * 0) sync_runs и records, затем items). В B есть только неявная S-блокировка записи при проверке FK
 * для INSERT нового экземпляра — та же, что у checkout/webhook при INSERT order_items/sales; X на запись в
 * этот момент никто не держит (транзакцию A той же синхронизации сериализует GET_LOCK).
 * Сбой между A и B безопасен: при повторе записи совпадут по checksum, экземпляры догонятся в B.
 *
 * Локальные статусы reserved / checkout_pending / sold / blocked синхронизация не меняет никогда:
 * меняется только source_status (что говорит источник), растёт items_conflicts. При освобождении резерва
 * или заказа экземпляр получает release target по source_status (контракт).
 *
 * Экземпляр с валютой, отличной от валюты магазина (option uniundata_currency), не импортируется и не
 * обновляется (только отметка «есть в источнике»): ошибка currency_mismatch в error_log прогона.
 */
final class SyncService
{
    public const BATCH_SIZE = 200;
    /** Прогон без heartbeat дольше — зависший (то же значение использует AdminController). */
    public const STALE_AFTER_SECONDS = 900;
    /** Имя источника (SourceClientInterface::name(), REST-параметр source): ≤ 32, чтобы имя GET_LOCK было ≤ 64. */
    public const SOURCE_NAME_PATTERN = '^[a-z0-9_-]{1,32}$';
    /** Порог безопасности прохода «пропавших»: доля активных экземпляров источника. */
    public const MISSING_THRESHOLD = 0.10;

    public const AS_GROUP = 'uniundata';
    /** Продолжение прогона следующим шагом Action Scheduler. Аргументы: [source, run_id]. */
    public const HOOK_CONTINUE = 'uniundata_sync_continue';
    /** Алерт оператору (порог missing, failed). Аргументы: [run_id, code]. */
    public const HOOK_ALERT = 'uniundata_sync_alert';

    /** source_cursor = '' — источник прочитан полностью, остался проход «пропавших». */
    private const CURSOR_READ_DONE = '';

    private const RESUME_WINDOW_HOURS = 36;
    private const MAX_ERROR_LOG = 50;
    private const FETCH_ATTEMPTS = 3;
    /** Предел размера одного multi-row INSERT (max_allowed_packet по умолчанию 64 МБ). */
    private const INSERT_CHUNK_BYTES = 4_000_000;

    private const PROTECTED_STATUSES = ['reserved', 'checkout_pending', 'sold', 'blocked'];
    private const TRIGGERS = ['cron', 'wp_cli', 'admin', 'retry'];

    /** Коды «служебных» исключений: прогон остановлен извне или потеряна блокировка. */
    private const E_RUN_STOPPED = 990_001;

    private const COUNTERS = [
        'records_received', 'records_created', 'records_updated', 'records_skipped',
        'items_created', 'items_updated', 'items_skipped', 'items_conflicts', 'items_missing', 'items_withdrawn',
        'errors_count',
    ];

    private const RECORD_COLUMNS = [
        'marc_control_number', 'marc_control_org', 'record_type', 'bib_level', 'title', 'title_sort', 'subtitle',
        'responsibility_statement', 'authors_text', 'main_author_sort', 'isbn_primary', 'publisher',
        'publication_place', 'publication_year', 'publication_date_text', 'edition_statement', 'language_code',
        'physical_description', 'series_title', 'subjects_text', 'description', 'contents_note',
        'cover_url', 'source_url',
    ];

    /** @var array<string, SourceClientInterface> */
    private array $sources = [];

    /** Что изменилось за вызов run() — для сброса кэша каталога после COMMIT. */
    private bool $catalogChanged = false;

    /** Валюта магазина на время run(). */
    private string $currency = '';

    /**
     * @param iterable<SourceClientInterface> $sources
     * @param float       $missingThreshold Доля активных экземпляров, выше которой пропавшие НЕ помечаются.
     * @param string|null $shopCurrency     null — option uniundata_currency в начале каждого run().
     */
    public function __construct(
        private readonly Db $db,
        private readonly AuditLog $audit,
        private readonly MarcExtractor $extractor,
        iterable $sources,
        private readonly float $missingThreshold = self::MISSING_THRESHOLD,
        private readonly ?string $shopCurrency = null,
    ) {
        foreach ($sources as $source) {
            $name = $source->name();
            if (preg_match('/' . self::SOURCE_NAME_PATTERN . '/', $name) !== 1) {
                throw new \InvalidArgumentException(\sprintf('Invalid source name "%s"', $name));
            }
            $this->sources[$name] = $source;
        }
    }

    /** @return list<string> */
    public function sourceNames(): array
    {
        return array_keys($this->sources);
    }

    // =============================================================================================
    // Запуск
    // =============================================================================================

    /**
     * Запускает или продолжает прогон источника.
     *
     * @param string     $triggeredBy       cron | wp_cli | admin | retry (wp_book_sync_runs.triggered_by)
     * @param bool       $resume            Продолжить с курсора упавшего/зависшего прогона (иначе — полный проход
     *                                      с начала). Для wp_cli также «подхватить» живой running-прогон
     *                                      цепочки Action Scheduler между её шагами.
     * @param float|null $timeBudgetSeconds Бюджет времени (шаг Action Scheduler ≈ 25 с). null — до конца (WP-CLI).
     * @param int|null   $continueRunId     Шаг цепочки: продолжить именно этот running-прогон.
     * @param bool       $allowMassMissing  Оператор осознанно разрешает пометить > 10 % каталога пропавшими.
     *
     * @return array{run_id: int|null, status: string, continue: bool, message: string}
     *   status: succeeded | partial | failed | running (бюджет исчерпан, continue = true) |
     *           locked (идёт другой процесс) | busy (живая цепочка другого запуска) | not_running | lock_lost |
     *           misconfigured (не задана валюта магазина)
     */
    public function run(
        string $sourceName,
        string $triggeredBy = 'cron',
        bool $resume = true,
        ?float $timeBudgetSeconds = null,
        ?int $continueRunId = null,
        bool $allowMassMissing = false,
    ): array {
        $client = $this->sources[$sourceName] ?? throw new \InvalidArgumentException(\sprintf('Unknown sync source "%s"', $sourceName));
        if (!\in_array($triggeredBy, self::TRIGGERS, true)) {
            throw new \InvalidArgumentException(\sprintf('Invalid trigger "%s"', $triggeredBy));
        }
        $this->currency = $this->shopCurrency ?? strtoupper(trim((string) get_option('uniundata_currency', '')));
        if (preg_match('/^[A-Z]{3}$/', $this->currency) !== 1) {
            return self::result(null, 'misconfigured', false, 'Option uniundata_currency (ISO 4217 shop currency) is not set');
        }
        $deadline = $timeBudgetSeconds !== null ? microtime(true) + max(1.0, $timeBudgetSeconds) : null;
        $lock = self::lockKey($sourceName);
        $this->catalogChanged = false;

        if (!$this->db->getLock($lock)) {
            return self::result(null, 'locked', false, 'Another process is syncing this source');
        }

        $runId = null;
        try {
            $open = $this->openRun($sourceName, $triggeredBy, $resume, $continueRunId);
            if ($open['skip'] !== null) {
                return self::result($open['run_id'], $open['skip'], false, $open['skip'] === 'busy'
                    ? 'A running sync chain of this source is alive (heartbeat < 15 min)'
                    : 'Run is no longer running');
            }
            $runId = $open['run_id'];

            return $this->process($client, $runId, $open['pass'], $open['cursor'], $lock, $deadline, $allowMassMissing);
        } catch (\Throwable $e) {
            if ($e->getCode() === self::E_RUN_STOPPED) {
                return self::result($runId, 'not_running', false, $e->getMessage());
            }
            if ($runId !== null) {
                $this->failRun($runId, $e);
                $this->enqueue(self::HOOK_ALERT, ['run_id' => $runId, 'code' => 'run_failed']);
            }
            error_log(\sprintf('[uniundata] sync %s failed: %s', $sourceName, self::safeMessage($e)));

            return self::result($runId, 'failed', false, self::safeMessage($e));
        } finally {
            $this->db->releaseLock($lock);
            if ($this->catalogChanged) {
                self::bumpCatalogVersion();
            }
        }
    }

    /**
     * Последние прогоны (WP-CLI `sync status`, админка).
     *
     * @return list<array<string, string|null>>
     */
    public function recentRuns(?string $sourceName = null, int $limit = 10): array
    {
        $limit = max(1, min($limit, 100));
        $cols = 'id, source_name, triggered_by, status, started_at, heartbeat_at, finished_at, resumed_from_run_id, '
            . 'pass_started_run_id, ' . implode(', ', self::COUNTERS) . ', (status = \'running\' AND heartbeat_at < UTC_TIMESTAMP(6) - INTERVAL '
            . self::STALE_AFTER_SECONDS . ' SECOND) AS is_stale';

        return $sourceName === null
            ? $this->db->getResults("SELECT {$cols} FROM {$this->t('sync_runs')} ORDER BY id DESC LIMIT %d", $limit)
            // ix_sync_runs_source (source_name, started_at)
            : $this->db->getResults(
                "SELECT {$cols} FROM {$this->t('sync_runs')} WHERE source_name = %s ORDER BY started_at DESC, id DESC LIMIT %d",
                $sourceName,
                $limit,
            );
    }

    /**
     * Повторное извлечение полей из marc21_raw без обращения к источнику (изменились правила MarcExtractor).
     * source_checksum не меняется: он считается от записи, а не от правил. Под той же блокировкой источника;
     * транзакции трогают только записи и производные таблицы (не экземпляры).
     *
     * @param (callable(int $done): void)|null $progress
     * @return int Сколько записей обновлено, или -1, если источник сейчас синхронизируется.
     */
    public function reextract(string $sourceName, ?callable $progress = null): int
    {
        $lock = self::lockKey($sourceName);
        if (!$this->db->getLock($lock)) {
            return -1;
        }
        $done = 0;
        try {
            $lastId = 0;
            do {
                $rows = $this->db->getResults(
                    "SELECT id, marc21_format, marc21_raw FROM {$this->t('records')}
                      WHERE source_name = %s AND id > %d ORDER BY id LIMIT %d",
                    $sourceName,
                    $lastId,
                    self::BATCH_SIZE,
                );
                $extracted = [];
                foreach ($rows as $row) {
                    $lastId = (int) $row['id'];
                    try {
                        $parsed = $row['marc21_format'] === 'marc_json'
                            ? $this->extractor->parseMarcJson((string) $row['marc21_raw'])
                            : $this->extractor->parseMarcXml((string) $row['marc21_raw']);
                        $x = $this->extractor->extract($parsed);
                        // URL обложки/страницы обычно даёт интеграция, а не 856 — их не перетираем.
                        unset($x['record']['cover_url'], $x['record']['source_url']);
                        $extracted[(int) $row['id']] = $x;
                    } catch (\Throwable $e) {
                        error_log(\sprintf('[uniundata] reextract record #%d: %s', $lastId, self::safeMessage($e)));
                    }
                }
                if ($extracted !== []) {
                    $this->db->transaction(function () use ($extracted): void {
                        foreach ($extracted as $recordId => $x) { // ключи по возрастанию id
                            $this->updateRecordColumns($recordId, $x['record'], null);
                        }
                        $this->writeRecordLinks($extracted, true);
                    });
                    $done += \count($extracted);
                    $this->catalogChanged = true;
                    if ($progress !== null) {
                        $progress($done);
                    }
                }
            } while (\count($rows) === self::BATCH_SIZE);
        } finally {
            $this->db->releaseLock($lock);
            if ($this->catalogChanged) {
                self::bumpCatalogVersion();
            }
        }

        return $done;
    }

    // =============================================================================================
    // Прогон: открытие, цикл пакетов, завершение
    // =============================================================================================

    /**
     * Вызывается под GET_LOCK. Решает: продолжить текущий running, пометить зависший aborted и начать
     * новый (с его курсора и порога прохода), или выйти.
     *
     * @return array{skip: ?string, run_id: ?int, pass: int, cursor: ?string}
     */
    private function openRun(string $source, string $triggeredBy, bool $resume, ?int $continueRunId): array
    {
        return $this->db->transaction(function () use ($source, $triggeredBy, $resume, $continueRunId): array {
            $runs = $this->t('sync_runs');
            // Поиск по UNIQUE(running_source); FOR UPDATE — чтобы решение и INSERT/UPDATE были атомарны.
            $current = $this->db->getRow(
                "SELECT id, status, source_cursor, pass_started_run_id, heartbeat_at,
                        (heartbeat_at < UTC_TIMESTAMP(6) - INTERVAL %d SECOND) AS is_stale
                   FROM {$runs} WHERE running_source = %s FOR UPDATE",
                self::STALE_AFTER_SECONDS,
                $source,
            );
            $resumeFrom = null;

            if ($current !== null) {
                $currentId = (int) $current['id'];
                $adopt = $continueRunId === $currentId || ($resume && $triggeredBy === 'wp_cli' && $current['is_stale'] !== '1');
                if ($adopt) {
                    // Тот же логический прогон продолжает процесс, который сейчас держит GET_LOCK.
                    $this->db->execute("UPDATE {$runs} SET heartbeat_at = UTC_TIMESTAMP(6) WHERE id = %d", $currentId);

                    return ['skip' => null, 'run_id' => $currentId, 'pass' => (int) ($current['pass_started_run_id'] ?? $currentId), 'cursor' => $current['source_cursor']];
                }
                if ($current['is_stale'] !== '1') {
                    return ['skip' => 'busy', 'run_id' => $currentId, 'pass' => 0, 'cursor' => null];
                }
                // Зависший прогон: процесс умер (GET_LOCK мы получили), heartbeat старше 15 минут.
                $log = self::appendErrors(null, [self::error(null, 'stale_heartbeat', 'No heartbeat since ' . $current['heartbeat_at'])]);
                $this->db->execute(
                    "UPDATE {$runs} SET status = 'aborted', finished_at = UTC_TIMESTAMP(6), error_log = %s
                      WHERE id = %d AND status = 'running'",
                    $log,
                    $currentId,
                );
                $this->audit->record('sync.run_aborted', 'sync_run', $currentId, 'running', 'aborted', [
                    'source' => $source, 'reason' => 'stale_heartbeat',
                ], 'sync');
                $resumeFrom = $resume ? $current : null;
            } elseif ($continueRunId !== null) {
                return ['skip' => 'not_running', 'run_id' => $continueRunId, 'pass' => 0, 'cursor' => null];
            } elseif ($resume) {
                // Последний прогон упал/был прерван недавно — продолжаем его проход, а не начинаем заново.
                $last = $this->db->getRow(
                    "SELECT id, status, source_cursor, pass_started_run_id,
                            (started_at > UTC_TIMESTAMP(6) - INTERVAL %d HOUR) AS is_recent
                       FROM {$runs} WHERE source_name = %s ORDER BY started_at DESC, id DESC LIMIT 1",
                    self::RESUME_WINDOW_HOURS,
                    $source,
                );
                if ($last !== null && \in_array($last['status'], ['aborted', 'failed'], true)
                    && $last['source_cursor'] !== null && $last['is_recent'] === '1') {
                    $resumeFrom = $last;
                }
            }

            // Продолжение — только если у прерванного прогона есть куда продолжать (курсор или «прочитано»).
            if ($resumeFrom !== null && $resumeFrom['source_cursor'] === null) {
                $resumeFrom = null;
            }
            $cursor = $resumeFrom !== null ? (string) $resumeFrom['source_cursor'] : null;

            try {
                $runId = $this->db->insert('sync_runs', [
                    'source_name' => $source,
                    'triggered_by' => $triggeredBy,
                    'status' => 'running',
                    'source_cursor' => $cursor,
                    'resumed_from_run_id' => $resumeFrom !== null ? (int) $resumeFrom['id'] : null,
                    'pass_started_run_id' => $resumeFrom !== null
                        ? (int) ($resumeFrom['pass_started_run_id'] ?? $resumeFrom['id'])
                        : null,
                ], ['started_at', 'heartbeat_at']);
            } catch (\mysqli_sql_exception $e) {
                if ($this->db->isDuplicateKey('uq_sync_runs_one_running', $e)) {
                    return ['skip' => 'busy', 'run_id' => null, 'pass' => 0, 'cursor' => null];
                }
                throw $e;
            }
            $pass = $resumeFrom !== null ? (int) ($resumeFrom['pass_started_run_id'] ?? $resumeFrom['id']) : $runId;
            if ($resumeFrom === null) {
                // Новый проход начинается этим прогоном.
                $this->db->execute("UPDATE {$runs} SET pass_started_run_id = id WHERE id = %d", $runId);
            }
            $this->audit->record('sync.run_started', 'sync_run', $runId, null, 'running', [
                'source' => $source,
                'triggered_by' => $triggeredBy,
                'resumed_from_run_id' => $resumeFrom !== null ? (int) $resumeFrom['id'] : null,
                'pass_started_run_id' => $pass,
            ], 'sync');

            return ['skip' => null, 'run_id' => $runId, 'pass' => $pass, 'cursor' => $cursor];
        });
    }

    /**
     * Чтение источника страницами, затем проход «пропавших».
     *
     * Признак «полный снимок» берётся со страниц (SourceBatch::$fullSnapshot одинаков в пределах прохода).
     * Последняя страница полного прохода записывает source_cursor = '' (дальше — проход «пропавших», даже
     * если он займёт несколько шагов); последняя страница инкрементальной выгрузки — NULL, и прогон
     * закрывается сразу: продолжать после сбоя нечего, а помечать пропавшие по неполному набору нельзя.
     *
     * @return array{run_id: int|null, status: string, continue: bool, message: string}
     */
    private function process(SourceClientInterface $client, int $runId, int $pass, ?string $cursor, string $lock, ?float $deadline, bool $allowMassMissing): array
    {
        $incremental = false;
        while ($cursor !== self::CURSOR_READ_DONE) {
            if (!$this->holdsLock($lock)) {
                // $wpdb переподключился — блокировка потеряна. Прогон остаётся running без heartbeat:
                // его подхватит следующий запуск (через 15 минут — aborted + resume).
                return self::result($runId, 'lock_lost', false, 'GET_LOCK was lost (reconnect?)');
            }
            if ($deadline !== null && microtime(true) >= $deadline) {
                $this->heartbeat($runId);

                return self::result($runId, 'running', true, 'Time budget exhausted; continue with the next step');
            }

            $batch = $this->fetchWithRetry($client, $cursor);
            $incremental = $incremental || !$batch->fullSnapshot;
            $next = match (true) {
                !$batch->isLast => $batch->nextCursor,
                $incremental => null,
                default => self::CURSOR_READ_DONE,
            };
            $this->applyBatch($runId, $client->name(), $batch, $next);
            $this->freeMemory();
            if ($batch->isLast && $incremental) {
                return $this->closeRun($runId, 'skipped');
            }
            $cursor = $next;
        }

        return $this->finalize($runId, $client->name(), $pass, $lock, $deadline, $allowMassMissing);
    }

    /**
     * Проход «пропавших» и закрытие прогона. Повторный вход (следующий шаг, resume после сбоя) пересчитывает
     * порог: уже помеченные экземпляры выбывают и из кандидатов, и из активных, поэтому одобренный проход
     * остаётся одобренным, а «блокированный» прогон закрывается в том же шаге.
     *
     * @return array{run_id: int|null, status: string, continue: bool, message: string}
     */
    private function finalize(int $runId, string $source, int $pass, string $lock, ?float $deadline, bool $allowMassMissing): array
    {
        [$active, $candidates] = $this->missingCounts($source, $pass);
        if ($active > 0 && $candidates > $this->missingThreshold * $active && !$allowMassMissing) {
            $this->db->transaction(function () use ($runId, $candidates, $active): void {
                $run = $this->lockRun($runId);
                $this->updateRun($runId, [], [self::error(null, 'missing_threshold_exceeded', \sprintf(
                    '%d of %d active items are absent (> %d%%); sync_missing NOT applied',
                    $candidates,
                    $active,
                    (int) round($this->missingThreshold * 100),
                ))], $run['error_log']);
            });
            $result = $this->closeRun($runId, 'blocked');
            $this->enqueue(self::HOOK_ALERT, ['run_id' => $runId, 'code' => 'missing_threshold_exceeded']);

            return $result;
        }

        $lastId = 0;
        while (true) {
            if (!$this->holdsLock($lock)) {
                return self::result($runId, 'lock_lost', false, 'GET_LOCK was lost (reconnect?)');
            }
            if ($deadline !== null && microtime(true) >= $deadline) {
                $this->heartbeat($runId);

                return self::result($runId, 'running', true, 'Time budget exhausted during the missing pass');
            }
            // Кандидаты — обычным чтением (ix_items_sync), решение — под FOR UPDATE ниже.
            $ids = array_map('intval', array_column($this->db->getResults(
                "SELECT id FROM {$this->t('items')}
                  WHERE source_name = %s
                    AND (last_seen_sync_run_id IS NULL OR last_seen_sync_run_id < %d)
                    AND source_status = 'present' AND availability_status <> 'sold'
                    AND external_item_id IS NOT NULL
                    AND id > %d
                  ORDER BY id LIMIT %d",
                $source,
                $pass,
                $lastId,
                self::BATCH_SIZE,
            ), 'id'));
            if ($ids === []) {
                break;
            }
            $lastId = max($ids);
            $this->db->transaction(fn () => $this->markMissingLocked($runId, $source, $pass, $ids));
        }

        return $this->closeRun($runId, 'approved');
    }

    /**
     * @param 'approved'|'blocked'|'skipped' $missingPass
     * @return array{run_id: int|null, status: string, continue: bool, message: string}
     */
    private function closeRun(int $runId, string $missingPass): array
    {
        $final = $this->db->transaction(function () use ($runId, $missingPass): string {
            $run = $this->lockRun($runId);
            $status = $missingPass === 'blocked' || (int) $run['errors_count'] > 0 ? 'partial' : 'succeeded';
            $this->db->execute(
                "UPDATE {$this->t('sync_runs')}
                    SET status = %s, finished_at = UTC_TIMESTAMP(6), heartbeat_at = UTC_TIMESTAMP(6)
                  WHERE id = %d AND status = 'running'",
                $status,
                $runId,
            );
            $this->audit->record('sync.run_finished', 'sync_run', $runId, 'running', $status, [
                'missing_pass' => $missingPass,
            ], 'sync');

            return $status;
        });

        return self::result($runId, $final, false, match ($missingPass) {
            'blocked' => 'Too many items disappeared from the source; sync_missing was not applied',
            'skipped' => 'Done (incremental export: missing items are not detected)',
            default => 'Done',
        });
    }

    /** @return array{0: int, 1: int} [активные экземпляры источника, кандидаты в пропавшие] */
    private function missingCounts(string $source, int $pass): array
    {
        $row = $this->db->getRow(
            "SELECT COUNT(*) AS active,
                    COALESCE(SUM(last_seen_sync_run_id IS NULL OR last_seen_sync_run_id < %d), 0) AS missing
               FROM {$this->t('items')}
              WHERE source_name = %s AND source_status = 'present' AND availability_status <> 'sold'
                AND external_item_id IS NOT NULL",
            $pass,
            $source,
        );

        return [(int) ($row['active'] ?? 0), (int) ($row['missing'] ?? 0)];
    }

    /** @param list<int> $ids */
    private function markMissingLocked(int $runId, string $source, int $pass, array $ids): void
    {
        $run = $this->lockRun($runId);
        sort($ids);
        $rows = $this->db->getResults(
            "SELECT id, availability_status, source_status, last_seen_sync_run_id
               FROM {$this->t('items')}
              WHERE id IN ({$this->db->placeholders(\count($ids))})
              ORDER BY id
                FOR UPDATE",
            ...$ids,
        );
        $now = $this->db->now();
        $c = self::zeroCounters();

        foreach ($rows as $row) {
            $id = (int) $row['id'];
            $status = (string) $row['availability_status'];
            // Перепроверка под блокировкой: пакет синхронизации или резерв могли изменить строку.
            $seen = $row['last_seen_sync_run_id'] !== null && (int) $row['last_seen_sync_run_id'] >= $pass;
            if ($seen || $row['source_status'] !== 'present' || $status === 'sold') {
                continue;
            }

            if ($status === 'available') {
                $this->expectOne($this->db->execute(
                    "UPDATE {$this->t('items')}
                        SET availability_status = 'sync_missing', source_status = 'missing', status_changed_at = %s
                      WHERE id = %d AND availability_status = 'available'",
                    $now,
                    $id,
                ), $id);
                $this->audit->record('item.status_changed', 'item', $id, 'available', 'sync_missing', [
                    'source' => $source, 'run_id' => $runId, 'reason' => 'absent_in_source',
                ], 'sync');
            } else {
                // reserved / checkout_pending / blocked: локальный статус главнее. При освобождении
                // экземпляр получит sync_missing (release target по source_status).
                $this->expectOne($this->db->execute(
                    "UPDATE {$this->t('items')} SET source_status = 'missing' WHERE id = %d AND availability_status = %s",
                    $id,
                    $status,
                ), $id);
                if (\in_array($status, self::PROTECTED_STATUSES, true)) {
                    ++$c['items_conflicts'];
                    $this->audit->record('sync.conflict', 'item', $id, $status, $status, [
                        'source' => $source, 'run_id' => $runId, 'source_status' => 'missing',
                    ], 'sync');
                }
            }
            ++$c['items_missing'];
            $this->catalogChanged = true;
        }

        $this->updateRun($runId, $c, [], $run['error_log']);
    }

    // =============================================================================================
    // Пакет
    // =============================================================================================

    /**
     * Пакет: разбор (CPU) → транзакция записей → транзакция экземпляров (с курсором). Ошибка данных в одной
     * записи (1062/1366/3819…) не срывает пакет: фаза повторяется по одной сущности в транзакции.
     *
     * @param string|null $nextCursor Что записать в source_cursor вместе с экземплярами пакета.
     */
    private function applyBatch(int $runId, string $source, SourceBatch $batch, ?string $nextCursor): void
    {
        $errors = [];
        $touchIds = []; // есть в источнике, но не обрабатываются — только last_seen (не считать пропавшими)
        $skipped = 0;
        foreach ($batch->rejected as $r) {
            $errors[] = self::error($r['external_item_id'], $r['code'], $r['message']);
            if ($r['external_item_id'] !== null) {
                $touchIds[] = $r['external_item_id'];
            }
        }

        // Существующие экземпляры — обычным чтением: создаёт их только синхронизация этого источника под GET_LOCK.
        $extIds = array_values(array_unique(array_map(static fn (array $e): string => $e['external_item_id'], $batch->entries)));
        $existing = [];
        if ($extIds !== []) {
            foreach ($this->db->getResults(
                "SELECT external_item_id FROM {$this->t('items')}
                  WHERE source_name = %s AND external_item_id IN ({$this->db->placeholders(\count($extIds), '%s')})",
                $source,
                ...$extIds,
            ) as $row) {
                $existing[(string) $row['external_item_id']] = true;
            }
        }

        // Разбор MARC и checksum — до транзакций (только CPU).
        $prepared = [];
        foreach ($batch->entries as $entry) {
            $ext = $entry['external_item_id'];
            if ($entry['currency'] !== $this->currency) {
                $errors[] = self::error($ext, 'currency_mismatch', \sprintf('Item currency %s differs from the shop currency %s; skipped', $entry['currency'], $this->currency));
                $touchIds[] = $ext;
                ++$skipped;
                continue;
            }
            if (!isset($existing[$ext]) && $entry['status'] === 'withdrawn') {
                ++$skipped; // новые снятые экземпляры не заводим: в продаже их не было и не будет
                continue;
            }
            try {
                $in = $this->extractor->normalizeInput($entry['marc'], $entry['marc_format']);
                $extracted = $this->extractor->extract($in['record']);
                $cover = $entry['cover_url'] ?? $extracted['record']['cover_url'];
                $url = $entry['source_url'] ?? $extracted['record']['source_url'];
                $extracted['record']['cover_url'] = $cover;
                $extracted['record']['source_url'] = $url;
                if (isset($prepared[$ext])) {
                    $errors[] = self::error($ext, 'duplicate_in_batch', 'The same external_item_id twice in one page; the last one wins');
                }
                $prepared[$ext] = [
                    'entry' => $entry,
                    'input' => $in,
                    'extracted' => $extracted,
                    'record_checksum' => $this->extractor->checksum($in['record'], ['cover_url' => $cover, 'source_url' => $url]),
                    'item_checksum' => self::itemChecksum($entry),
                ];
            } catch (\Throwable $e) {
                $errors[] = self::error($ext, 'marc_invalid', self::safeMessage($e));
                $touchIds[] = $ext;
            }
        }

        // --- A. Записи ---------------------------------------------------------------------------------
        $byRecord = [];
        foreach ($prepared as $p) {
            $rid = $p['entry']['source_record_id'];
            if (!isset($byRecord[$rid])) {
                $byRecord[$rid] = $p;
            } elseif ($byRecord[$rid]['record_checksum'] !== $p['record_checksum']) {
                $errors[] = self::error($p['entry']['external_item_id'], 'record_conflict_in_batch',
                    'Items of one source_record_id carry different MARC; the first one is used');
            }
        }
        $recordIds = []; // source_record_id → wp_book_records.id
        if ($byRecord !== []) {
            try {
                $recordIds = $this->db->transaction(fn (): array => $this->writeRecords($runId, $source, $byRecord));
            } catch (\Throwable $e) {
                if (self::isInfrastructureError($e)) {
                    throw $e; // deadlock после повторов, потеря соединения, прогон остановлен: пакет повторит следующий запуск
                }
                error_log(\sprintf('[uniundata] sync records fallback to per-record mode: %s', self::safeMessage($e)));
                foreach ($byRecord as $rid => $p) {
                    try {
                        $recordIds += $this->db->transaction(fn (): array => $this->writeRecords($runId, $source, [$rid => $p]));
                    } catch (\Throwable $e2) {
                        if (self::isInfrastructureError($e2)) {
                            throw $e2;
                        }
                        $errors[] = self::error((string) $rid, 'record_write_failed', self::safeMessage($e2));
                    }
                }
            }
        }

        // Экземпляры, чья запись не записалась, только отмечаются как «есть в источнике».
        $work = [];
        foreach ($prepared as $ext => $p) {
            $p['record_id'] = $recordIds[$p['entry']['source_record_id']] ?? null;
            if ($p['record_id'] === null) {
                $touchIds[] = (string) $ext;
                continue;
            }
            $work[] = $p;
        }

        // --- B. Экземпляры (+ курсор, счётчики, ошибки) ------------------------------------------------
        try {
            $this->db->transaction(fn () => $this->writeItems(
                $runId, $source, $work, $touchIds, $errors, $skipped, true, $nextCursor, $batch->receivedCount,
            ));

            return;
        } catch (\Throwable $e) {
            if (self::isInfrastructureError($e)) {
                throw $e;
            }
            error_log(\sprintf('[uniundata] sync items fallback to per-item mode: %s', self::safeMessage($e)));
        }

        foreach ($work as $p) {
            try {
                $this->db->transaction(fn () => $this->writeItems($runId, $source, [$p], [], [], 0, false, null, 0));
            } catch (\Throwable $e) {
                if (self::isInfrastructureError($e)) {
                    throw $e;
                }
                $errors[] = self::error($p['entry']['external_item_id'], 'item_write_failed', self::safeMessage($e));
                $touchIds[] = $p['entry']['external_item_id'];
            }
        }
        // Курсор, records_received и ошибки — одной финальной транзакцией.
        $this->db->transaction(fn () => $this->writeItems(
            $runId, $source, [], $touchIds, $errors, $skipped, true, $nextCursor, $batch->receivedCount,
        ));
    }

    /**
     * Транзакция A: записи пакета. Блокировки: строка прогона → существующие записи FOR UPDATE по возрастанию
     * id → новые записи → производные таблицы. Экземпляры не трогаются.
     *
     * @param array<string, array<string, mixed>> $byRecord source_record_id → подготовленная запись
     * @return array<string, int> source_record_id → id
     */
    private function writeRecords(int $runId, string $source, array $byRecord): array
    {
        $run = $this->lockRun($runId);
        $now = $this->db->now();
        $c = self::zeroCounters();
        $rids = array_map('strval', array_keys($byRecord));

        $records = []; // source_record_id → ['id' => int, 'source_checksum' => string]
        foreach ($this->db->getResults(
            "SELECT id, source_record_id, source_checksum FROM {$this->t('records')}
              WHERE source_name = %s AND source_record_id IN ({$this->db->placeholders(\count($rids), '%s')})
              ORDER BY id
                FOR UPDATE",
            $source,
            ...$rids,
        ) as $row) {
            $records[(string) $row['source_record_id']] = ['id' => (int) $row['id'], 'source_checksum' => (string) $row['source_checksum']];
        }

        // Новые записи: multi-row INSERT, затем ID по UNIQUE(source_name, source_record_id).
        $linkSets = []; // record_id → extracted (для identifiers/contributors/subjects)
        $newRecords = array_diff_key($byRecord, $records);
        if ($newRecords !== []) {
            $columns = array_merge(['source_name', 'source_record_id', 'source_format', 'marc21_format', 'marc21_raw',
                'source_checksum'], self::RECORD_COLUMNS, ['is_active', 'last_seen_sync_run_id', 'last_synced_at']);
            $rows = [];
            foreach ($newRecords as $rid => $p) {
                $rows[] = array_merge(
                    [$source, (string) $rid, $p['input']['source_format'], $p['input']['marc21_format'],
                     $p['input']['marc21_raw'], $p['record_checksum']],
                    array_map(static fn (string $col): int|string|null => $p['extracted']['record'][$col], self::RECORD_COLUMNS),
                    [1, $runId, $now],
                );
            }
            $this->insertRows('records', $columns, $rows);
            $newIds = array_map('strval', array_keys($newRecords));
            foreach ($this->db->getResults(
                "SELECT id, source_record_id FROM {$this->t('records')}
                  WHERE source_name = %s AND source_record_id IN ({$this->db->placeholders(\count($newIds), '%s')})",
                $source,
                ...$newIds,
            ) as $row) {
                $records[(string) $row['source_record_id']] = ['id' => (int) $row['id'], 'source_checksum' => '-new-'];
                $linkSets[(int) $row['id']] = $newRecords[(string) $row['source_record_id']]['extracted'];
            }
            $c['records_created'] += \count($newRecords);
        }

        // Существующие: UPDATE изменённых по возрастанию id, touch неизменённых.
        uasort($records, static fn (array $a, array $b): int => $a['id'] <=> $b['id']);
        $touchRecordIds = [];
        foreach ($records as $rid => $rec) {
            $p = $byRecord[$rid];
            if ($rec['source_checksum'] === '-new-') {
                continue;
            }
            if ($rec['source_checksum'] === $p['record_checksum']) {
                $touchRecordIds[] = $rec['id'];
                ++$c['records_skipped'];
                continue;
            }
            $this->updateRecordColumns($rec['id'], $p['extracted']['record'], [
                'source_format' => $p['input']['source_format'],
                'marc21_format' => $p['input']['marc21_format'],
                'marc21_raw' => $p['input']['marc21_raw'],
                'source_checksum' => $p['record_checksum'],
                'last_seen_sync_run_id' => $runId,
                'last_synced_at' => $now,
            ]);
            $linkSets[$rec['id']] = $p['extracted'];
            ++$c['records_updated'];
        }
        if ($touchRecordIds !== []) {
            $this->db->execute(
                "UPDATE {$this->t('records')} SET last_seen_sync_run_id = %d, last_synced_at = %s
                  WHERE id IN ({$this->db->placeholders(\count($touchRecordIds))})",
                $runId,
                $now,
                ...$touchRecordIds,
            );
        }
        if ($linkSets !== []) {
            $this->writeRecordLinks($linkSets, $c['records_updated'] > 0);
            $this->catalogChanged = true;
        }

        $this->updateRun($runId, $c, [], $run['error_log']);

        return array_map(static fn (array $r): int => $r['id'], $records);
    }

    /**
     * Транзакция B: экземпляры пакета. Все решения — по строкам, прочитанным FOR UPDATE.
     *
     * @param list<array<string, mixed>>  $work            Подготовленные записи с record_id.
     * @param list<string>                $touchExternalIds Есть в источнике, но не обработаны — только last_seen.
     * @param list<array<string, mixed>>  $errors
     */
    private function writeItems(
        int $runId,
        string $source,
        array $work,
        array $touchExternalIds,
        array $errors,
        int $skipped,
        bool $setCursor,
        ?string $cursor,
        int $received,
    ): void {
        $run = $this->lockRun($runId);
        $now = $this->db->now();
        $c = self::zeroCounters();
        $c['records_received'] = $received;
        $c['items_skipped'] = $skipped;

        // --- Существующие экземпляры: ID обычным чтением, затем FOR UPDATE по возрастанию id -------
        $extIds = array_values(array_unique(array_merge(
            array_map(static fn (array $p): string => $p['entry']['external_item_id'], $work),
            $touchExternalIds,
        )));
        $items = []; // external_item_id → строка под блокировкой
        if ($extIds !== []) {
            $ids = array_map('intval', array_column($this->db->getResults(
                "SELECT id FROM {$this->t('items')}
                  WHERE source_name = %s AND external_item_id IN ({$this->db->placeholders(\count($extIds), '%s')})",
                $source,
                ...$extIds,
            ), 'id'));
            sort($ids);
            if ($ids !== []) {
                foreach ($this->db->getResults(
                    "SELECT id, book_record_id, external_item_id, availability_status, source_status, source_checksum
                       FROM {$this->t('items')}
                      WHERE id IN ({$this->db->placeholders(\count($ids))})
                      ORDER BY id
                        FOR UPDATE",
                    ...$ids,
                ) as $row) {
                    $items[(string) $row['external_item_id']] = $row;
                }
            }
        }
        $work = $this->resolveInventoryConflicts($work, $items, $errors);

        $newItems = [];
        $touchItemIds = [];
        foreach ($work as $p) {
            $e = $p['entry'];
            $item = $items[$e['external_item_id']] ?? null;
            if ($item === null) {
                if ($e['status'] === 'withdrawn') {
                    ++$c['items_skipped'];
                    continue;
                }
                $newItems[] = [
                    $p['record_id'], $source, $e['external_item_id'], $e['inventory_number'],
                    $e['price_amount'], $e['currency'], $e['condition_code'], $e['condition_note'], $e['cover_url'],
                    $e['source_url'], 'available', $now, 1, 'present', $p['item_checksum'], $runId, $now,
                ];
                continue;
            }
            $this->applyItem($runId, $source, $item, $p, $now, $c, $errors, $touchItemIds);
        }
        if ($newItems !== []) {
            $this->insertRows('items', ['book_record_id', 'source_name', 'external_item_id', 'inventory_number',
                'price_amount', 'currency', 'condition_code', 'condition_note', 'cover_url', 'source_url',
                'availability_status', 'status_changed_at', 'is_active', 'source_status', 'source_checksum',
                'last_seen_sync_run_id', 'last_synced_at'], $newItems);
            $c['items_created'] += \count($newItems);
            $this->catalogChanged = true;
        }
        foreach ($touchExternalIds as $ext) {
            if (isset($items[$ext])) {
                $touchItemIds[] = (int) $items[$ext]['id'];
            }
        }
        if ($touchItemIds !== []) {
            $touchItemIds = array_values(array_unique($touchItemIds));
            sort($touchItemIds);
            $this->db->execute(
                "UPDATE {$this->t('items')} SET last_seen_sync_run_id = %d, last_synced_at = %s
                  WHERE id IN ({$this->db->placeholders(\count($touchItemIds))})",
                $runId,
                $now,
                ...$touchItemIds,
            );
        }

        $this->updateRun($runId, $c, $errors, $run['error_log'], $setCursor, $cursor);
        if ($c['items_created'] + $c['items_updated'] > 0) {
            $this->audit->record('sync.batch_applied', 'sync_run', $runId, null, null, array_filter($c), 'sync');
        }
    }

    /**
     * Существующий экземпляр под блокировкой: поля + статус по правилам защиты локальных статусов.
     *
     * @param array<string, string|null>  $item
     * @param array<string, mixed>        $p
     * @param array<string, int>          $c
     * @param list<array<string, mixed>>  $errors
     * @param list<int>                   $touch
     */
    private function applyItem(int $runId, string $source, array $item, array $p, string $now, array &$c, array &$errors, array &$touch): void
    {
        $e = $p['entry'];
        $id = (int) $item['id'];
        $local = (string) $item['availability_status'];
        $d = self::decide($local, (string) $item['source_status'], $e['status']);
        $fieldsChanged = $item['source_checksum'] !== $p['item_checksum'];
        $statusChanged = $d['availability'] !== $local;
        $sourceChanged = $d['source_status'] !== $item['source_status'];

        if ($d['conflict']) {
            ++$c['items_conflicts'];
            if ($local === 'sold' && \count($errors) < self::MAX_ERROR_LOG) {
                // Не ошибка (errors_count не растёт), но оператору нужен список: источник продаёт проданное.
                $errors[] = self::error($e['external_item_id'], 'conflict_sold', 'Source still offers an item sold locally', false);
            }
        }
        // Перегруппировку экземпляра в другую запись (сменился source_record_id) синхронизация не делает:
        // на запись ссылаются order_items и sales. book_record_id остаётся прежним.

        if (!$fieldsChanged && !$statusChanged && !$sourceChanged) {
            $touch[] = $id;
            ++$c['items_skipped'];

            return;
        }

        $sets = ['last_seen_sync_run_id = %d', 'last_synced_at = %s', 'source_status = %s', 'source_checksum = %s'];
        $args = [$runId, $now, $d['source_status'], $p['item_checksum']];
        if ($fieldsChanged && $local !== 'sold') {
            // Цена в корзине/заказе — снимок, поэтому смена цены зарезервированного экземпляра безопасна.
            foreach (['price_amount' => '%d', 'currency' => '%s', 'condition_code' => '%s', 'condition_note' => '%s',
                      'inventory_number' => '%s', 'cover_url' => '%s', 'source_url' => '%s'] as $col => $ph) {
                if ($e[$col] === null) {
                    $sets[] = "{$col} = NULL";
                } else {
                    $sets[] = "{$col} = {$ph}";
                    $args[] = $e[$col];
                }
            }
        }
        if ($statusChanged) {
            $sets[] = 'availability_status = %s';
            $sets[] = 'status_changed_at = %s';
            $args[] = $d['availability'];
            $args[] = $now;
        }
        $args[] = $id;
        $args[] = $local;
        // Условие по прочитанному под блокировкой статусу — страховка от ошибок порядка блокировок.
        $this->expectOne($this->db->execute(
            "UPDATE {$this->t('items')} SET " . implode(', ', $sets) . ' WHERE id = %d AND availability_status = %s',
            ...$args,
        ), $id);
        ++$c['items_updated'];
        $this->catalogChanged = true;

        if ($statusChanged) {
            if ($d['availability'] === 'withdrawn') {
                ++$c['items_withdrawn'];
            }
            $this->audit->record('item.status_changed', 'item', $id, $local, $d['availability'], [
                'source' => $source, 'run_id' => $runId,
                'reason' => $d['availability'] === 'withdrawn' ? 'withdrawn_in_source' : 'returned_in_source',
            ], 'sync');
        } elseif ($d['conflict'] && ($sourceChanged || $fieldsChanged)) {
            $this->audit->record('sync.conflict', 'item', $id, $local, $local, [
                'source' => $source, 'run_id' => $runId, 'source_status' => $d['source_status'],
            ], 'sync');
        }
    }

    /**
     * Чистая функция решения по статусу (покрывается unit-тестами).
     *
     * @param string $local       availability_status под блокировкой
     * @param string $localSource source_status под блокировкой
     * @param 'present'|'withdrawn' $incoming что прислал источник
     * @return array{availability: string, source_status: string, conflict: bool}
     */
    public static function decide(string $local, string $localSource, string $incoming): array
    {
        if (\in_array($local, self::PROTECTED_STATUSES, true)) {
            return [
                'availability' => $local, // никогда не перетираем
                'source_status' => $incoming,
                // sold + «в продаже» у источника — расхождение, которое должен исправить источник;
                // reserved/checkout_pending/blocked + источник только что снял — освобождение даст withdrawn.
                'conflict' => $local === 'sold'
                    ? $incoming === 'present'
                    : $incoming === 'withdrawn' && $localSource !== 'withdrawn',
            ];
        }

        if ($incoming === 'withdrawn') {
            // available → withdrawn; sync_missing → withdrawn (контракт: sync_missing→withdrawn).
            return ['availability' => 'withdrawn', 'source_status' => 'withdrawn', 'conflict' => false];
        }

        // incoming = present
        return match ($local) {
            'available' => ['availability' => 'available', 'source_status' => 'present', 'conflict' => false],
            'sync_missing' => ['availability' => 'available', 'source_status' => 'present', 'conflict' => false],
            // withdrawn по решению источника — возвращаем; withdrawn при source_status = present поставлен
            // локально (не синхронизацией) — не трогаем.
            'withdrawn' => $localSource === 'present'
                ? ['availability' => 'withdrawn', 'source_status' => 'present', 'conflict' => true]
                : ['availability' => 'available', 'source_status' => 'present', 'conflict' => false],
            default => throw new \UnexpectedValueException(\sprintf('Unknown availability_status "%s"', $local)),
        };
    }

    /**
     * UNIQUE(inventory_number) — один номер у двух экземпляров дал бы 1062 и сорвал весь пакет.
     * Конфликтный номер не записывается, экземпляр импортируется без него, ошибка — в журнал.
     *
     * @param list<array<string, mixed>>        $work
     * @param array<string, array<string, ?string>> $items
     * @param list<array<string, mixed>>        $errors
     * @return list<array<string, mixed>>
     */
    private function resolveInventoryConflicts(array $work, array $items, array &$errors): array
    {
        $wanted = [];
        foreach ($work as $p) {
            if ($p['entry']['inventory_number'] !== null) {
                $wanted[] = $p['entry']['inventory_number'];
            }
        }
        if ($wanted === []) {
            return $work;
        }
        // Collation utf8mb4_unicode_520_ci: 'A-1' и 'a-1' — один номер.
        $owners = [];
        foreach ($this->db->getResults(
            "SELECT id, inventory_number FROM {$this->t('items')}
              WHERE inventory_number IN ({$this->db->placeholders(\count($wanted), '%s')})",
            ...array_values(array_unique($wanted)),
        ) as $row) {
            $owners[mb_strtolower((string) $row['inventory_number'])] = (int) $row['id'];
        }
        $claimed = [];
        foreach ($work as $i => $p) {
            $inv = $p['entry']['inventory_number'];
            if ($inv === null) {
                continue;
            }
            $key = mb_strtolower($inv);
            $selfId = isset($items[$p['entry']['external_item_id']]) ? (int) $items[$p['entry']['external_item_id']]['id'] : null;
            $owner = $owners[$key] ?? null;
            if (isset($claimed[$key]) || ($owner !== null && $owner !== $selfId)) {
                $errors[] = self::error($p['entry']['external_item_id'], 'inventory_number_conflict',
                    'inventory_number is already used by another item; imported without it');
                $work[$i]['entry']['inventory_number'] = null;
                continue;
            }
            $claimed[$key] = true;
        }

        return $work;
    }

    /**
     * @param array<string, int|string|null>      $columns Извлечённые поля (RECORD_COLUMNS)
     * @param array<string, int|string>|null      $extra   marc21_raw, checksum, last_seen… (null — только поля)
     */
    private function updateRecordColumns(int $recordId, array $columns, ?array $extra): void
    {
        $sets = [];
        $args = [];
        foreach (array_merge(array_intersect_key($columns, array_flip(self::RECORD_COLUMNS)), $extra ?? []) as $col => $value) {
            if ($value === null) {
                $sets[] = "{$col} = NULL";
            } else {
                $sets[] = \is_int($value) ? "{$col} = %d" : "{$col} = %s";
                $args[] = $value;
            }
        }
        $args[] = $recordId;
        $this->db->execute("UPDATE {$this->t('records')} SET " . implode(', ', $sets) . ' WHERE id = %d', ...$args);
    }

    /**
     * Производные таблицы записи (идентификаторы, персоны, рубрики) — перезапись целиком для изменённых
     * записей. Это не бизнес-данные (они однозначно выводятся из marc21_raw), поэтому DELETE допустим.
     * Справочники персон и рубрик — upsert по ключу в порядке возрастания ключа (одинаковый порядок
     * блокировок у параллельных синхронизаций разных источников).
     *
     * @param array<int, array<string, mixed>> $sets record_id → результат MarcExtractor::extract()
     */
    private function writeRecordLinks(array $sets, bool $deleteOld): void
    {
        ksort($sets);
        $recordIds = array_keys($sets);
        if ($deleteOld) {
            foreach (['identifiers', 'record_contributors', 'record_subjects'] as $table) {
                $this->db->execute(
                    "DELETE FROM {$this->t($table)} WHERE book_record_id IN ({$this->db->placeholders(\count($recordIds))})",
                    ...$recordIds,
                );
            }
        }

        // Идентификаторы.
        $rows = [];
        foreach ($sets as $rid => $x) {
            foreach ($x['identifiers'] as $idf) {
                $rows[] = [$rid, $idf['id_type'], $idf['id_value'], $idf['raw_value'], $idf['is_cancelled']];
            }
        }
        if ($rows !== []) {
            $this->insertRows('identifiers', ['book_record_id', 'id_type', 'id_value', 'raw_value', 'is_cancelled'], $rows);
        }

        // Персоны.
        $contributorIds = $this->upsertDictionary('contributors', 'contributor_key',
            ['contributor_key', 'name_display', 'name_sort', 'entity_type', 'dates', 'authority_id'],
            array_merge(...array_values(array_map(static fn (array $x): array => $x['contributors'], $sets))));
        $rows = [];
        foreach ($sets as $rid => $x) {
            foreach ($x['contributors'] as $p) {
                $rows[] = [$rid, $contributorIds[$p['contributor_key']], $p['role_code'], $p['marc_tag'], $p['position']];
            }
        }
        if ($rows !== []) {
            $this->insertRows('record_contributors', ['book_record_id', 'contributor_id', 'role_code', 'marc_tag', 'position'], $rows);
        }

        // Рубрики.
        $subjectIds = $this->upsertDictionary('subjects', 'subject_key',
            ['subject_key', 'heading', 'heading_sort', 'marc_tag', 'thesaurus', 'authority_id'],
            array_merge(...array_values(array_map(static fn (array $x): array => $x['subjects'], $sets))));
        $rows = [];
        foreach ($sets as $rid => $x) {
            foreach ($x['subjects'] as $s) {
                $rows[] = [$rid, $subjectIds[$s['subject_key']], $s['position']];
            }
        }
        if ($rows !== []) {
            $this->insertRows('record_subjects', ['book_record_id', 'subject_id', 'position'], $rows);
        }
    }

    /**
     * @param list<string>                     $columns
     * @param list<array<string, mixed>>       $entries
     * @return array<string, int> ключ → id
     */
    private function upsertDictionary(string $table, string $keyColumn, array $columns, array $entries): array
    {
        $byKey = [];
        foreach ($entries as $e) {
            $byKey[$e[$keyColumn]] ??= $e;
        }
        if ($byKey === []) {
            return [];
        }
        ksort($byKey, SORT_STRING);
        $keys = array_map('strval', array_keys($byKey));
        $ids = $this->dictionaryIds($table, $keyColumn, $keys);

        $missing = array_diff_key($byKey, $ids);
        if ($missing !== []) {
            // ON DUPLICATE KEY — на случай гонки с синхронизацией другого источника; первая форма имени остаётся.
            $this->insertRows($table, $columns, array_values(array_map(
                static fn (array $e): array => array_map(static fn (string $col): mixed => $e[$col], $columns),
                $missing,
            )), " ON DUPLICATE KEY UPDATE {$keyColumn} = {$keyColumn}");
            $ids += $this->dictionaryIds($table, $keyColumn, array_map('strval', array_keys($missing)));
        }

        return $ids;
    }

    /**
     * @param list<string> $keys
     * @return array<string, int>
     */
    private function dictionaryIds(string $table, string $keyColumn, array $keys): array
    {
        $ids = [];
        foreach ($this->db->getResults(
            "SELECT id, {$keyColumn} AS k FROM {$this->t($table)} WHERE {$keyColumn} IN ({$this->db->placeholders(\count($keys), '%s')})",
            ...$keys,
        ) as $row) {
            $ids[(string) $row['k']] = (int) $row['id'];
        }

        return $ids;
    }

    // =============================================================================================
    // Строка прогона
    // =============================================================================================

    /** @return array<string, string|null> */
    private function lockRun(int $runId): array
    {
        $run = $this->db->getRow(
            "SELECT id, status, errors_count, error_log FROM {$this->t('sync_runs')} WHERE id = %d FOR UPDATE",
            $runId,
        );
        if ($run === null || $run['status'] !== 'running') {
            // Прогон закрыт другим процессом (aborted по heartbeat): дальше не пишем.
            throw new \RuntimeException(\sprintf('Sync run #%d is not running any more', $runId), self::E_RUN_STOPPED);
        }

        return $run;
    }

    /**
     * Счётчики — инкрементом в той же транзакции, что и данные: повтор пакета их не удваивает.
     *
     * @param array<string, int>          $c
     * @param list<array<string, mixed>>  $errors
     * @param bool                        $setCursor false — source_cursor не трогать.
     */
    private function updateRun(int $runId, array $c, array $errors, ?string $currentLog, bool $setCursor = false, ?string $cursor = null): void
    {
        $c['errors_count'] = ($c['errors_count'] ?? 0) + \count(array_filter($errors, static fn (array $e): bool => $e['counted']));
        $sets = ['heartbeat_at = UTC_TIMESTAMP(6)'];
        $args = [];
        foreach (self::COUNTERS as $col) {
            if (($c[$col] ?? 0) > 0) {
                $sets[] = "{$col} = {$col} + %d";
                $args[] = $c[$col];
            }
        }
        if ($setCursor) {
            if ($cursor === null) {
                $sets[] = 'source_cursor = NULL';
            } else {
                $sets[] = 'source_cursor = %s';
                $args[] = $cursor;
            }
        }
        if ($errors !== []) {
            $sets[] = 'error_log = %s';
            $args[] = self::appendErrors($currentLog, $errors);
        }
        $args[] = $runId;
        $this->db->execute("UPDATE {$this->t('sync_runs')} SET " . implode(', ', $sets) . ' WHERE id = %d', ...$args);
    }

    private function heartbeat(int $runId): void
    {
        $this->db->execute(
            "UPDATE {$this->t('sync_runs')} SET heartbeat_at = UTC_TIMESTAMP(6) WHERE id = %d AND status = 'running'",
            $runId,
        );
    }

    /** Не бросает: вызывается из обработчика ошибки. Курсор сохраняется — следующий запуск продолжит с него. */
    private function failRun(int $runId, \Throwable $e): void
    {
        try {
            $this->db->transaction(function () use ($runId, $e): void {
                $run = $this->db->getRow("SELECT status, error_log FROM {$this->t('sync_runs')} WHERE id = %d FOR UPDATE", $runId);
                if ($run === null || $run['status'] !== 'running') {
                    return;
                }
                $this->db->execute(
                    "UPDATE {$this->t('sync_runs')}
                        SET status = 'failed', finished_at = UTC_TIMESTAMP(6), errors_count = errors_count + 1, error_log = %s
                      WHERE id = %d AND status = 'running'",
                    self::appendErrors($run['error_log'], [self::error(null, 'run_failed', self::safeMessage($e))]),
                    $runId,
                );
                $this->audit->record('sync.run_failed', 'sync_run', $runId, 'running', 'failed', [
                    'error' => $e::class,
                ], 'sync');
            });
        } catch (\Throwable $inner) {
            error_log(\sprintf('[uniundata] cannot mark sync run #%d failed: %s', $runId, self::safeMessage($inner)));
        }
    }

    // =============================================================================================
    // Источник, блокировки, утилиты
    // =============================================================================================

    private function fetchWithRetry(SourceClientInterface $client, ?string $cursor): SourceBatch
    {
        for ($attempt = 1; ; ++$attempt) {
            try {
                return $client->fetchBatch($cursor, self::BATCH_SIZE);
            } catch (\UnexpectedValueException $e) {
                throw $e; // нарушение контракта страницы — повтор не поможет
            } catch (\RuntimeException $e) {
                if ($attempt >= self::FETCH_ATTEMPTS) {
                    throw $e;
                }
                sleep($attempt * 2); // 2 с, 4 с — вне транзакции, блокировок не держим
            }
        }
    }

    /** Короткое имя для Db::getLock()/lockName(): 'sync_' + источник (≤ 37 символов). */
    private static function lockKey(string $source): string
    {
        return 'sync_' . $source;
    }

    private function holdsLock(string $key): bool
    {
        return $this->db->getVar('SELECT IS_USED_LOCK(%s) = CONNECTION_ID()', $this->db->lockName($key)) === '1';
    }

    /**
     * @param list<string>                          $columns
     * @param list<list<int|string|bool|null>>      $rows
     */
    private function insertRows(string $table, array $columns, array $rows, string $suffix = ''): void
    {
        $head = "INSERT INTO {$this->t($table)} (" . implode(', ', $columns) . ') VALUES ';
        $tuples = [];
        $args = [];
        $bytes = 0;
        $flush = function () use (&$tuples, &$args, &$bytes, $head, $suffix): void {
            if ($tuples !== []) {
                $this->db->execute($head . implode(', ', $tuples) . $suffix, ...$args);
            }
            $tuples = [];
            $args = [];
            $bytes = 0;
        };
        foreach ($rows as $row) {
            $ph = [];
            $rowArgs = [];
            $rowBytes = 0;
            foreach ($row as $value) {
                if ($value === null) {
                    $ph[] = 'NULL'; // Db::prepare() не принимает null: он превратился бы в ''
                    continue;
                }
                if (\is_bool($value)) {
                    $value = (int) $value;
                }
                $ph[] = \is_int($value) ? '%d' : '%s';
                $rowArgs[] = $value;
                $rowBytes += \is_string($value) ? \strlen($value) : 8;
            }
            if ($tuples !== [] && $bytes + $rowBytes > self::INSERT_CHUNK_BYTES) {
                $flush();
            }
            $tuples[] = '(' . implode(', ', $ph) . ')';
            array_push($args, ...$rowArgs);
            $bytes += $rowBytes;
        }
        $flush();
    }

    private function expectOne(int $affected, int $itemId): void
    {
        if ($affected !== 1) {
            throw new \LogicException(\sprintf('Item #%d changed under lock (affected rows: %d)', $itemId, $affected));
        }
    }

    private function t(string $name): string
    {
        return $this->db->table($name);
    }

    private function enqueue(string $hook, array $args): void
    {
        if (\function_exists('as_enqueue_async_action')) {
            as_enqueue_async_action($hook, $args, self::AS_GROUP);
        } else {
            error_log(\sprintf('[uniundata] Action Scheduler is not loaded; %s %s', $hook, wp_json_encode($args)));
        }
    }

    private function freeMemory(): void
    {
        // Долгий прогон в WP-CLI: SAVEQUERIES и runtime-кэш объектов растут без ограничений.
        $wpdb = $this->db->wpdb();
        if (\defined('SAVEQUERIES') && SAVEQUERIES) {
            $wpdb->queries = [];
        }
        if (\function_exists('wp_cache_flush_runtime')) {
            wp_cache_flush_runtime();
        }
    }

    private static function bumpCatalogVersion(): void
    {
        // Ключи карточек/списков содержат catalog_ver: старые перестают читаться без массового удаления.
        if (\function_exists('wp_cache_incr') && wp_cache_incr('catalog_ver', 1, 'uniundata') === false) {
            wp_cache_set('catalog_ver', time(), 'uniundata');
        }
    }

    /** @param array<string, mixed> $e */
    private static function itemChecksum(array $e): string
    {
        return hash('sha256', json_encode([
            'v' => 1,
            'record' => $e['source_record_id'],
            'status' => $e['status'],
            'price' => $e['price_amount'],
            'currency' => $e['currency'],
            'condition' => $e['condition_code'],
            'note' => $e['condition_note'],
            'inventory' => $e['inventory_number'],
            'cover' => $e['cover_url'],
            'url' => $e['source_url'],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    /** @return array<string, int> */
    private static function zeroCounters(): array
    {
        return array_fill_keys(self::COUNTERS, 0);
    }

    /** @return array{at: string, external_id: ?string, code: string, message: string, counted: bool} */
    private static function error(?string $externalId, string $code, string $message, bool $counted = true): array
    {
        return [
            'at' => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.v\Z'), // как Db::toIso8601()
            'external_id' => $externalId,
            'code' => $code,
            'message' => mb_substr($message, 0, 300),
            'counted' => $counted,
        ];
    }

    /**
     * Не более MAX_ERROR_LOG последних записей; битый UTF-8 из источника заменяется (JSON_INVALID_UTF8_SUBSTITUTE).
     *
     * @param list<array<string, mixed>> $new
     */
    private static function appendErrors(?string $currentLog, array $new): string
    {
        $log = $currentLog !== null ? json_decode($currentLog, true) : [];
        $log = \is_array($log) ? array_values($log) : [];
        foreach ($new as $e) {
            unset($e['counted']);
            $log[] = $e;
        }

        return (string) json_encode(\array_slice($log, -self::MAX_ERROR_LOG), JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    }

    /** Текст исключения для журнала: без query string URL (там бывают токены) и не длиннее 300 символов. */
    private static function safeMessage(\Throwable $e): string
    {
        $msg = $e instanceof DomainError ? $e->errorCode() : $e->getMessage();
        $msg = (string) preg_replace('~(https?://[^\s?#]+)[?#]\S*~i', '$1?…', $msg);

        return mb_substr($e::class . ': ' . $msg, 0, 300);
    }

    /** Ошибки, при которых пакет нельзя «изолировать по записям»: их лечит повтор всего пакета позже. */
    private static function isInfrastructureError(\Throwable $e): bool
    {
        if ($e->getCode() === self::E_RUN_STOPPED || $e instanceof DomainError) {
            return true; // DomainError здесь — только uniundata_conflict_retry (deadlock после повторов)
        }
        for ($x = $e; $x !== null; $x = $x->getPrevious()) {
            if ($x instanceof \mysqli_sql_exception
                && \in_array((int) $x->getCode(), [Db::ER_LOCK_DEADLOCK, Db::ER_LOCK_WAIT_TIMEOUT, Db::CR_SERVER_GONE_ERROR, Db::CR_SERVER_LOST], true)) {
                return true;
            }
        }

        return false;
    }

    /** @return array{run_id: int|null, status: string, continue: bool, message: string} */
    private static function result(?int $runId, string $status, bool $continue, string $message): array
    {
        return ['run_id' => $runId, 'status' => $status, 'continue' => $continue, 'message' => $message];
    }
}
