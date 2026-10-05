<?php

declare(strict_types=1);

namespace Uniundata\Books\Infrastructure;

use Uniundata\Books\Domain\DomainError;

/**
 * Доступ к БД поверх $wpdb: транзакции с повтором, настройки сессии только на время транзакции
 * и запросы, которые бросают исключение вместо `false`.
 *
 * Зачем обёртка: у $wpdb нет API транзакций, он не отдаёт errno, на ошибке возвращает false
 * (а get_row()/get_results() — пустой результат), при WP_DEBUG_DISPLAY печатает HTML прямо
 * в JSON-ответ REST, а при «MySQL server has gone away» молча переподключается и повторяет
 * запрос уже вне транзакции (поэтому на время транзакции переподключение запрещено).
 *
 * Соединение $wpdb общее с ядром и другими плагинами, поэтому Db не меняет сессию насовсем:
 * уровень изоляции задаётся на одну транзакцию (SET TRANSACTION …), а time_zone, sql_mode и
 * innodb_lock_wait_timeout сохраняются перед транзакцией и восстанавливаются после неё.
 * В SQL плагина время — только UTC_TIMESTAMP(6): NOW()/CURRENT_TIMESTAMP вне транзакции зависят
 * от time_zone сессии (у заказчика сервер в UTC+4). Рекомендация для сервера: default-time-zone='+00:00'.
 *
 * Ошибки MySQL бросаются как \mysqli_sql_exception (code = errno, message = текст MySQL) — тот же тип,
 * что бросил бы сам mysqli при MYSQLI_REPORT_STRICT (WordPress его выключает).
 */
final class Db
{
    public const ER_DUP_ENTRY = 1062;
    public const ER_LOCK_WAIT_TIMEOUT = 1205;
    public const ER_LOCK_DEADLOCK = 1213;
    public const ER_CHECK_CONSTRAINT_VIOLATED = 3819;
    public const CR_SERVER_GONE_ERROR = 2006;
    public const CR_SERVER_LOST = 2013;

    /** Повторов после 1213/1205 по умолчанию: REST-запрос (пользователь ждёт) и cron/CLI/прочее. */
    public const RETRIES_WEB = 2;
    public const RETRIES_BACKGROUND = 3;

    private const RETRYABLE_ERRNOS = [self::ER_LOCK_DEADLOCK, self::ER_LOCK_WAIT_TIMEOUT];
    private const CONNECTION_ERRNOS = [self::CR_SERVER_GONE_ERROR, self::CR_SERVER_LOST];

    /**
     * Таблицы плагина без $wpdb->prefix. Имя таблицы нельзя передать значением в prepare(),
     * поэтому оно подставляется в SQL только из этого белого списка.
     */
    private const TABLES = [
        'book_records', 'book_contributors', 'book_record_contributors', 'book_subjects',
        'book_record_subjects', 'book_identifiers', 'book_items', 'book_images',
        'book_customer_profiles', 'book_user_consents', 'book_carts', 'book_cart_items',
        'book_reservations', 'book_orders', 'book_order_items', 'book_payments',
        'book_payment_events', 'book_refunds', 'book_sales', 'book_sync_runs', 'book_audit_log',
    ];

    /** 0 — вне транзакции, 1 — внешний уровень, >1 — вложенные вызовы transaction(). */
    private int $depth = 0;

    /** Соединение, на котором открыта текущая транзакция (для обнаружения переподключения). */
    private ?object $txConnection = null;

    /**
     * Попытка транзакции обречена: повторяемая ошибка MySQL (1213 — InnoDB уже откатил транзакцию)
     * или исключение вложенного вызова, которое внешний код перехватил. COMMIT такой попытки запрещён.
     *
     * @var array{errno: int, cause: \Throwable}|null
     */
    private ?array $rollbackOnly = null;

    /**
     * @param int|null $maxRetries             Повторов после 1213/1205 (всего попыток = 1 + повторы). null — по
     *                                         контексту: RETRIES_WEB в REST-запросе, иначе RETRIES_BACKGROUND.
     * @param int      $lockWaitTimeoutSeconds innodb_lock_wait_timeout на время транзакции (по умолчанию в MySQL 50 с).
     * @param bool     $strictSqlMode          STRICT_TRANS_TABLES на время транзакции: WordPress снимает строгий
     *                                         режим, и MySQL молча обрезает/«чинит» значения до проверки CHECK.
     */
    public function __construct(
        private readonly \wpdb $wpdb,
        private readonly ?int $maxRetries = null,
        private readonly int $lockWaitTimeoutSeconds = 5,
        private readonly bool $strictSqlMode = true,
    ) {
        if ($maxRetries !== null && $maxRetries < 0) {
            throw new \InvalidArgumentException('maxRetries must be >= 0');
        }
        if ($lockWaitTimeoutSeconds < 1) {
            throw new \InvalidArgumentException('lockWaitTimeoutSeconds must be >= 1');
        }
    }

    public function wpdb(): \wpdb
    {
        return $this->wpdb;
    }

    // ---- Транзакции ------------------------------------------------------------------------------

    /**
     * Выполняет $fn в транзакции READ COMMITTED. COMMIT при успехе, ROLLBACK при любом исключении.
     *
     * Порядок на внешнем уровне:
     *   1. SET: запомнить time_zone / sql_mode / innodb_lock_wait_timeout сессии в @uniundata_*,
     *      выставить '+00:00', STRICT_TRANS_TABLES и короткий lock wait timeout;
     *   2. на каждую попытку: SET TRANSACTION ISOLATION LEVEL READ COMMITTED (только на ближайшую
     *      транзакцию) → START TRANSACTION → $fn() → COMMIT;
     *   3. при 1213 (deadlock) или 1205 (lock wait timeout) — ROLLBACK, пауза 50–200 мс и повтор всей
     *      транзакции; после исчерпания повторов — DomainError `uniundata_conflict_retry` (503);
     *   4. в finally — вернуть прежние значения сессии.
     * Если на соединении уже открыта чужая транзакция, SET TRANSACTION падает с 1568: Db не фиксирует
     * чужие изменения неявным COMMIT-ом, а бросает исключение.
     *
     * $fn может выполниться несколько раз, поэтому внутри — только запросы к БД: никаких HTTP-вызовов,
     * писем, do_action() сторонних плагинов и изменений внешнего состояния. Исключения MySQL внутри $fn
     * не перехватывать «молча»: после 1213 InnoDB уже откатил транзакцию, и следующие запросы шли бы
     * в autocommit. Db это страхует: после 1213/1205 любой следующий запрос через Db и COMMIT попытки
     * бросают исключение, и транзакция повторяется целиком (запросы мимо Db, «сырым» $wpdb, не страхуются).
     *
     * ВЛОЖЕННЫЙ ВЫЗОВ (transaction() внутри открытой транзакции) выполняет $fn в текущей транзакции:
     * счётчик глубины, без SAVEPOINT; COMMIT, ROLLBACK и повторы — только на внешнем уровне, $maxRetries
     * вложенного вызова игнорируется. Если вложенный $fn бросил исключение, транзакция помечается
     * rollback-only: даже если вызывающий код перехватит исключение, внешний уровень не сделает COMMIT —
     * следующий запрос через Db или выход из внешнего $fn бросит исключение и приведёт к ROLLBACK
     * (или к повтору, если причина — 1213/1205).
     *
     * @template T
     * @param callable(): T $fn
     * @param int|null      $maxRetries Повторов после 1213/1205 для этого вызова; null — из конструктора/контекста.
     * @return T
     */
    public function transaction(callable $fn, ?int $maxRetries = null): mixed
    {
        if ($this->depth > 0) {
            return $this->joinTransaction($fn);
        }

        $retries = $maxRetries ?? $this->maxRetries ?? self::contextRetries();
        if ($retries < 0) {
            throw new \InvalidArgumentException('maxRetries must be >= 0');
        }

        // HyperDB/LudicrousDB иначе отправят SELECT … FOR UPDATE на реплику.
        if (method_exists($this->wpdb, 'send_reads_to_masters')) {
            $this->wpdb->send_reads_to_masters();
        }
        // Ошибки по-прежнему пишутся в error_log, но не печатаются HTML-ом в ответ REST.
        $previousShowErrors = $this->wpdb->hide_errors();
        $previousReconnectRetries = null;
        $sessionConnection = null;

        try {
            // До транзакции переподключение безопасно (соединение могло умереть по wait_timeout, пока
            // процесс ходил в банк): wpdb повторит этот SET на новом соединении. Дальше — запрещено.
            $sessionConnection = $this->enterSession();
            $previousReconnectRetries = $this->disableReconnect();

            for ($attempt = 0; ; ++$attempt) {
                // Вне внутреннего try: при отказе (1568 — чужая открытая транзакция) ROLLBACK не нужен.
                $this->exec('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
                $this->exec('START TRANSACTION');
                $this->depth = 1;
                $this->txConnection = $this->wpdb->dbh;
                $this->rollbackOnly = null;

                try {
                    $result = $fn();
                    $this->assertNotRollbackOnly();
                    $this->exec('COMMIT');

                    return $result;
                } catch (\Throwable $e) {
                    $errno = $this->failureErrno($e); // до ROLLBACK, который перезапишет errno соединения
                    $this->rollbackQuietly();

                    if (!\in_array($errno, self::RETRYABLE_ERRNOS, true)) {
                        throw $e;
                    }
                    if ($attempt >= $retries) {
                        throw DomainError::conflictRetry(1, $e);
                    }
                } finally {
                    $this->depth = 0;
                    $this->txConnection = null;
                    $this->rollbackOnly = null;
                }

                usleep(random_int(50_000, 200_000));
            }
        } finally {
            // Транзакция уже закрыта: переподключение снова разрешено. Если соединение умерло после COMMIT,
            // wpdb повторит SET на новом соединении, где COALESCE оставит его настройки как есть.
            $this->restoreReconnect($previousReconnectRetries);
            $this->leaveSession($sessionConnection);
            $this->wpdb->show_errors($previousShowErrors);
        }
    }

    public function inTransaction(): bool
    {
        return $this->depth > 0;
    }

    /** Для методов, которые рассчитывают на уже взятые вызывающим кодом блокировки. */
    public function assertInTransaction(): void
    {
        if ($this->depth === 0) {
            throw new \LogicException('This operation must run inside Db::transaction()');
        }
    }

    // ---- Запросы ---------------------------------------------------------------------------------

    /**
     * Обёртка над $wpdb->prepare(). NULL как аргумент запрещён: prepare() превратил бы его в '' или 0,
     * поэтому NULL пишется в SQL литералом.
     */
    public function prepare(string $sql, int|string|float ...$args): string
    {
        if ($args === []) {
            return $sql; // prepare() без плейсхолдеров — _doing_it_wrong в WordPress.
        }

        $prepared = $this->wpdb->prepare($sql, ...$args);
        if (!\is_string($prepared) || $prepared === '') {
            throw new \InvalidArgumentException('wpdb::prepare() rejected the query: placeholder/argument mismatch');
        }

        return $prepared;
    }

    /**
     * INSERT/UPDATE/DELETE. Возвращает число ИЗМЕНЁННЫХ строк (UPDATE тем же значением даёт 0).
     * Вне транзакции запрос выполняется в отдельной Db::transaction() — с теми же UTC, strict и повтором.
     */
    public function execute(string $sql, int|string|float ...$args): int
    {
        $prepared = $this->prepare($sql, ...$args);
        if ($this->depth === 0) {
            return $this->transaction(fn (): int => $this->exec($prepared));
        }

        return $this->exec($prepared);
    }

    /** @return array<string, string|null>|null Строка как ассоциативный массив (значения — строки) или null. */
    public function getRow(string $sql, int|string|float ...$args): ?array
    {
        $prepared = $this->prepareRead($sql, $args);
        $row = $this->silently(fn (): mixed => $this->wpdb->get_row($prepared, ARRAY_A));
        $this->assertSucceeded(true);

        return \is_array($row) ? $row : null;
    }

    /** @return list<array<string, string|null>> */
    public function getResults(string $sql, int|string|float ...$args): array
    {
        $prepared = $this->prepareRead($sql, $args);
        $rows = $this->silently(fn (): mixed => $this->wpdb->get_results($prepared, ARRAY_A));
        $this->assertSucceeded(true);

        return \is_array($rows) ? array_values($rows) : [];
    }

    public function getVar(string $sql, int|string|float ...$args): ?string
    {
        $prepared = $this->prepareRead($sql, $args);
        $value = $this->silently(fn (): mixed => $this->wpdb->get_var($prepared));
        $this->assertSucceeded(true);

        return $value === null ? null : (string) $value;
    }

    /**
     * INSERT с плейсхолдерами по типу значения (int → %d, string → %s, null → NULL).
     * Колонки из $utcNowColumns получают UTC_TIMESTAMP(6) — время сервера БД, а не PHP.
     * $wpdb->insert() не используется: он не умеет SQL-выражения и при сбое своей проверки
     * кодировки возвращает false без errno. Вне транзакции — как execute().
     *
     * @param array<string, int|string|bool|null> $data
     * @param list<string>                        $utcNowColumns
     * @return int insert_id
     */
    public function insert(string $table, array $data, array $utcNowColumns = []): int
    {
        $columns = [];
        $values = [];
        $args = [];

        foreach ($data as $column => $value) {
            $columns[] = $this->identifier((string) $column);
            if ($value === null) {
                $values[] = 'NULL';
                continue;
            }
            if (\is_bool($value)) {
                $value = (int) $value;
            }
            $values[] = \is_int($value) ? '%d' : '%s';
            $args[] = $value;
        }
        foreach ($utcNowColumns as $column) {
            $columns[] = $this->identifier($column);
            $values[] = 'UTC_TIMESTAMP(6)';
        }

        $sql = $this->prepare(\sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            $this->table($table),
            implode(', ', $columns),
            implode(', ', $values),
        ), ...$args);
        $run = function () use ($sql): int {
            $this->exec($sql);

            return $this->lastInsertId();
        };

        return $this->depth === 0 ? $this->transaction($run) : $run();
    }

    public function lastInsertId(): int
    {
        return (int) $this->wpdb->insert_id;
    }

    /** Текущее время сервера БД в UTC, 'Y-m-d H:i:s.u'. Единый источник времени для всех узлов. */
    public function now(): string
    {
        return (string) $this->getVar('SELECT UTC_TIMESTAMP(6)');
    }

    /** Полное имя таблицы плагина: 'book_items' (или 'items') → '{$wpdb->prefix}book_items'. */
    public function table(string $name): string
    {
        $name = str_starts_with($name, 'book_') ? $name : 'book_' . $name;
        if (!\in_array($name, self::TABLES, true)) {
            throw new \InvalidArgumentException(\sprintf('Unknown plugin table "%s"', $name));
        }

        return $this->wpdb->prefix . $name;
    }

    /** Плейсхолдеры для IN-списка: placeholders(3) → '%d, %d, %d'. Пустой IN () — синтаксическая ошибка. */
    public function placeholders(int $count, string $placeholder = '%d'): string
    {
        if ($count < 1) {
            throw new \InvalidArgumentException('IN-list must not be empty');
        }
        if (!\in_array($placeholder, ['%d', '%s'], true)) {
            throw new \InvalidArgumentException('Placeholder must be %d or %s');
        }

        return implode(', ', array_fill(0, $count, $placeholder));
    }

    // ---- Именованные блокировки (GET_LOCK) -------------------------------------------------------

    /**
     * Имя GET_LOCK для всего плагина: имена общие для всего сервера MySQL, а на одном сервере живут
     * new.libsmr.ru и shop.libsmr.ru (и сайты мультисайта), поэтому к имени добавляется отпечаток
     * БД и префикса. 'expire_reservations' → 'uniundata_expire_reservations@1a2b3c4d5e6f' (≤ 64 символов).
     */
    public function lockName(string $name): string
    {
        if (preg_match('/^[a-z0-9_-]{1,41}$/', $name) !== 1) {
            throw new \InvalidArgumentException(\sprintf('Invalid lock name "%s"', $name));
        }
        $dbName = \defined('DB_NAME') ? (string) \constant('DB_NAME') : (string) $this->wpdb->dbname;

        return 'uniundata_' . $name . '@' . substr(md5($dbName . '|' . $this->wpdb->prefix), 0, 12);
    }

    /**
     * GET_LOCK(lockName($name), $timeoutSeconds). Блокировка принадлежит соединению: снимается
     * releaseLock() или сервером при обрыве соединения. Ждать её внутри транзакции нельзя (ожидание
     * GET_LOCK с удержанием строк InnoDB не видит детектор deadlock-ов).
     */
    public function getLock(string $name, int $timeoutSeconds = 0): bool
    {
        if ($this->depth > 0 && $timeoutSeconds > 0) {
            throw new \LogicException('Do not wait for GET_LOCK inside a transaction');
        }

        return $this->getVar('SELECT GET_LOCK(%s, %d)', $this->lockName($name), max(0, $timeoutSeconds)) === '1';
    }

    public function releaseLock(string $name): void
    {
        try {
            $this->getVar('SELECT RELEASE_LOCK(%s)', $this->lockName($name));
        } catch (\mysqli_sql_exception) {
            // Соединение потеряно — сервер уже снял блокировку.
        }
    }

    // ---- Ошибки MySQL ----------------------------------------------------------------------------

    /** errno последнего запроса текущего соединения ($wpdb его не хранит). */
    public function lastErrno(): int
    {
        $dbh = $this->wpdb->dbh;
        if (!$dbh instanceof \mysqli) {
            return 0;
        }
        try {
            return mysqli_errno($dbh);
        } catch (\Throwable) {
            return self::CR_SERVER_GONE_ERROR; // соединение уже закрыто
        }
    }

    public function lastError(): string
    {
        return (string) $this->wpdb->last_error;
    }

    /**
     * 1062 Duplicate entry, опционально по имени индекса ('uq_reservations_attempt'). Имена индексов
     * локальны для таблицы и не зависят от префикса; MySQL 8.0.19+ пишет 'таблица.индекс', более
     * ранние — просто 'индекс', учитываются оба. Без $e проверяется последняя ошибка соединения.
     */
    public function isDuplicateKey(?string $keyName = null, ?\Throwable $e = null): bool
    {
        $pattern = $keyName === null ? null : "/ for key '(?:[^']*\\.)?" . preg_quote($keyName, '/') . "'\\z/";

        return $this->matchesError(self::ER_DUP_ENTRY, $pattern, $e);
    }

    /**
     * 3819 Check constraint '<имя>' is violated — по СУФФИКСУ имени. Имена CHECK уникальны в пределах БД
     * и начинаются с имени таблицы с префиксом (wp_book_reservations_chk_attempt_range,
     * wp_2_book_reservations_chk_attempt_range), поэтому передаётся суффикс: '_chk_attempt_range'
     * или 'book_reservations_chk_attempt_range'. Суффикс обязан содержать '_chk_'.
     */
    public function isCheckViolation(?string $constraintSuffix = null, ?\Throwable $e = null): bool
    {
        if ($constraintSuffix !== null && !str_contains($constraintSuffix, '_chk_')) {
            throw new \InvalidArgumentException(\sprintf('Constraint suffix "%s" must contain "_chk_"', $constraintSuffix));
        }
        $pattern = $constraintSuffix === null
            ? null
            : "/^Check constraint '[^']*" . preg_quote($constraintSuffix, '/') . "' is violated/";

        return $this->matchesError(self::ER_CHECK_CONSTRAINT_VIOLATED, $pattern, $e);
    }

    // ---- Время -----------------------------------------------------------------------------------

    /**
     * Единый формат дат в API: DATETIME(6) из БД (UTC) → 'Y-m-d\TH:i:s.v\Z'.
     * '2026-10-05 12:00:00.123456' → '2026-10-05T12:00:00.123Z'; NULL/'' → null.
     */
    public static function toIso8601(?string $utcDatetime): ?string
    {
        if ($utcDatetime === null || $utcDatetime === '') {
            return null;
        }
        $utc = new \DateTimeZone('UTC');
        $dt = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s.u', $utcDatetime, $utc)
            ?: \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $utcDatetime, $utc);
        if ($dt === false) {
            throw new \UnexpectedValueException(\sprintf('Unexpected DATETIME value "%s"', $utcDatetime));
        }

        return $dt->format('Y-m-d\TH:i:s.v\Z');
    }

    // ---- Внутреннее ------------------------------------------------------------------------------

    /** @param array<int, int|string|float> $args */
    private function prepareRead(string $sql, array $args): string
    {
        if ($this->depth === 0 && preg_match('/\bFOR\s+(?:UPDATE|SHARE)\b|\bLOCK\s+IN\s+SHARE\s+MODE\b/i', $sql) === 1) {
            // В autocommit блокировка снимается сразу после SELECT — решение по ней было бы гонкой.
            throw new \LogicException('Locking read outside Db::transaction()');
        }

        return $this->prepare($sql, ...$args);
    }

    private function exec(string $sql): int
    {
        $result = $this->silently(fn (): mixed => $this->wpdb->query($sql));
        $this->assertSucceeded($result !== false);

        return (int) $result;
    }

    /**
     * @param callable(): mixed $query
     */
    private function silently(callable $query): mixed
    {
        $this->assertNotRollbackOnly();
        $previous = $this->wpdb->hide_errors();
        try {
            return $query();
        } finally {
            $this->wpdb->show_errors($previous);
        }
    }

    private function assertSucceeded(bool $ok): void
    {
        if ($this->txConnection !== null && $this->wpdb->dbh !== $this->txConnection) {
            // $wpdb переподключился и повторил запрос на новом соединении в autocommit: транзакции больше нет.
            throw new \mysqli_sql_exception('Database connection was lost inside a transaction', self::CR_SERVER_GONE_ERROR);
        }
        $error = (string) $this->wpdb->last_error;
        if ($ok && $error === '') {
            return;
        }
        $e = new \mysqli_sql_exception($error !== '' ? $error : 'Unknown database error', $this->lastErrno());
        if ($this->depth > 0 && \in_array($e->getCode(), self::RETRYABLE_ERRNOS, true)) {
            $this->rollbackOnly ??= ['errno' => (int) $e->getCode(), 'cause' => $e];
        }

        throw $e;
    }

    private function assertNotRollbackOnly(): void
    {
        if ($this->depth === 0 || $this->rollbackOnly === null) {
            return;
        }
        ['errno' => $errno, 'cause' => $cause] = $this->rollbackOnly;
        if (\in_array($errno, self::RETRYABLE_ERRNOS, true)) {
            throw new \mysqli_sql_exception(\sprintf('Transaction attempt was aborted by MySQL error %d', $errno), $errno, $cause);
        }

        throw new \LogicException('Transaction is rollback-only: an exception of a nested transaction() call was caught', 0, $cause);
    }

    /** @param callable(): mixed $fn */
    private function joinTransaction(callable $fn): mixed
    {
        ++$this->depth;
        try {
            return $fn();
        } catch (\Throwable $e) {
            $this->rollbackOnly ??= ['errno' => $this->failureErrno($e), 'cause' => $e];

            throw $e;
        } finally {
            --$this->depth;
        }
    }

    /**
     * errno, из-за которого упала транзакция. Кроме собственных \mysqli_sql_exception учитывается код,
     * который сам вызвал $wpdb и бросил своё исключение: errno последнего запроса соединения либо
     * errno, положенный в code исключения (`new \RuntimeException($msg, $db->lastErrno())`).
     */
    private function failureErrno(\Throwable $e): int
    {
        if ($e instanceof \mysqli_sql_exception) {
            return (int) $e->getCode();
        }
        $last = $this->lastErrno();
        if (\in_array($last, self::RETRYABLE_ERRNOS, true)) {
            return $last;
        }
        $code = (int) $e->getCode(); // у DomainError всегда 0

        return \in_array($code, self::RETRYABLE_ERRNOS, true) ? $code : $last;
    }

    private function rollbackQuietly(): void
    {
        try {
            $this->wpdb->query('ROLLBACK');
        } catch (\Throwable) {
            // Соединение потеряно — сервер уже откатил транзакцию сам.
        }
    }

    /**
     * Запоминает параметры сессии в переменных @uniundata_* и ставит свои — одним запросом
     * (присваивания SET выполняются слева направо, проверено на 8.0.46).
     *
     * @return object|null Соединение, на котором они выставлены.
     */
    private function enterSession(): ?object
    {
        $sql = "SET @uniundata_time_zone = @@SESSION.time_zone,"
            . " @uniundata_lock_wait_timeout = @@SESSION.innodb_lock_wait_timeout,"
            . " SESSION time_zone = '+00:00',"
            . " SESSION innodb_lock_wait_timeout = %d";
        if ($this->strictSqlMode) {
            $sql .= ", @uniundata_sql_mode = @@SESSION.sql_mode,"
                . " SESSION sql_mode = CONCAT_WS(',', NULLIF(@@SESSION.sql_mode, ''), 'STRICT_TRANS_TABLES')";
        }
        $this->exec($this->prepare($sql, $this->lockWaitTimeoutSeconds));

        return \is_object($this->wpdb->dbh) ? $this->wpdb->dbh : null;
    }

    /**
     * Возвращает параметры сессии. После обрыва соединения восстанавливать нечего: новое соединение
     * уже получило настройки WordPress, а переменные @uniundata_* пропали (COALESCE — на этот случай).
     */
    private function leaveSession(?object $connection): void
    {
        if ($connection === null || $this->wpdb->dbh !== $connection
            || \in_array($this->lastErrno(), self::CONNECTION_ERRNOS, true)) {
            return;
        }
        $sql = 'SET SESSION time_zone = COALESCE(@uniundata_time_zone, @@SESSION.time_zone),'
            . ' SESSION innodb_lock_wait_timeout = COALESCE(@uniundata_lock_wait_timeout, @@SESSION.innodb_lock_wait_timeout)';
        if ($this->strictSqlMode) {
            $sql .= ', SESSION sql_mode = COALESCE(@uniundata_sql_mode, @@SESSION.sql_mode)';
        }
        try {
            $this->exec($sql);
        } catch (\Throwable $e) {
            error_log('[uniundata] cannot restore MySQL session settings: ' . $e->getMessage());
        }
    }

    /**
     * После обрыва соединения wpdb::query() переподключается и повторяет тот же запрос уже на новом
     * соединении в autocommit — часть транзакции молча зафиксировалась бы (проверено на 8.0.46).
     * На время транзакции переподключение запрещено: wpdb завершит запрос через dead_db()/wp_die()
     * (в REST — JSON 500), сервер откатит транзакцию сам. Отказ лучше частичной фиксации.
     */
    private function disableReconnect(): ?int
    {
        if (!property_exists($this->wpdb, 'reconnect_retries')) {
            return null; // drop-in без этого свойства: остаётся проверка соединения в assertSucceeded()
        }
        $previous = (int) $this->wpdb->reconnect_retries;
        $this->wpdb->reconnect_retries = 0;

        return $previous;
    }

    private function restoreReconnect(?int $previous): void
    {
        if ($previous !== null) {
            $this->wpdb->reconnect_retries = $previous;
        }
    }

    /** Сколько повторов по умолчанию: REST-запрос ждёт пользователь (max_execution_time 30 с), cron/CLI — нет. */
    private static function contextRetries(): int
    {
        $rest = \function_exists('wp_is_serving_rest_request')
            ? (bool) wp_is_serving_rest_request()
            : \defined('REST_REQUEST') && \constant('REST_REQUEST');

        return $rest ? self::RETRIES_WEB : self::RETRIES_BACKGROUND;
    }

    private function identifier(string $name): string
    {
        if (preg_match('/^[a-z_][a-z0-9_]{0,63}$/', $name) !== 1) {
            throw new \InvalidArgumentException(\sprintf('Invalid column name "%s"', $name));
        }

        return '`' . $name . '`';
    }

    private function matchesError(int $errno, ?string $messagePattern, ?\Throwable $e): bool
    {
        if ($e === null) {
            $code = $this->lastErrno();
            $message = $this->lastError();
        } else {
            // Исходная ошибка MySQL может быть обёрнута (например, в DomainError) — ищем по цепочке.
            while ($e !== null && !$e instanceof \mysqli_sql_exception) {
                $e = $e->getPrevious();
            }
            if ($e === null) {
                return false;
            }
            $code = (int) $e->getCode();
            $message = $e->getMessage();
        }

        return $code === $errno && ($messagePattern === null || preg_match($messagePattern, $message) === 1);
    }
}
