<?php

declare(strict_types=1);

namespace Uniundata\Books\Infrastructure;

use Uniundata\Books\Domain\DomainError;

/**
 * Доступ к БД поверх $wpdb: транзакции с повтором, единые настройки сессии и запросы,
 * которые бросают исключение вместо `false`.
 *
 * Зачем обёртка: у $wpdb нет API транзакций, он не отдаёт errno, на ошибке возвращает false
 * (а get_row()/get_results() — пустой результат), при WP_DEBUG_DISPLAY печатает HTML прямо
 * в JSON-ответ REST, а при «MySQL server has gone away» молча переподключается и повторяет
 * запрос уже вне транзакции (поэтому на время транзакции переподключение запрещено).
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

    private const RETRYABLE_ERRNOS = [self::ER_LOCK_DEADLOCK, self::ER_LOCK_WAIT_TIMEOUT];

    /**
     * Таблицы плагина без $wpdb->prefix. Имя таблицы нельзя передать значением в prepare(),
     * поэтому оно подставляется в SQL только из этого белого списка.
     */
    private const TABLES = [
        'book_records', 'book_contributors', 'book_record_contributors', 'book_subjects',
        'book_record_subjects', 'book_identifiers', 'book_items', 'book_images',
        'book_customer_profiles', 'book_user_consents', 'book_carts', 'book_cart_items',
        'book_reservations', 'book_orders', 'book_order_items', 'book_payments',
        'book_payment_events', 'book_sales', 'book_sync_runs', 'book_audit_log',
    ];

    private int $depth = 0;

    /** @var \WeakReference<object>|null Соединение, для которого уже выполнены SET сессии. */
    private ?\WeakReference $sessionConnection = null;

    /** Соединение, на котором открыта текущая транзакция (для обнаружения переподключения). */
    private ?object $txConnection = null;

    /**
     * @param int  $maxRetries             Повторов после 1213/1205 (всего попыток = 1 + $maxRetries).
     * @param int  $lockWaitTimeoutSeconds innodb_lock_wait_timeout сессии: пользователь не ждёт 50 с по умолчанию.
     * @param bool $strictSqlMode          STRICT_TRANS_TABLES на время транзакций плагина: WordPress снимает
     *                                     строгий режим, и MySQL молча обрезает/«чинит» значения до CHECK.
     */
    public function __construct(
        private readonly \wpdb $wpdb,
        private readonly int $maxRetries = 3,
        private readonly int $lockWaitTimeoutSeconds = 5,
        private readonly bool $strictSqlMode = true,
    ) {
    }

    public function wpdb(): \wpdb
    {
        return $this->wpdb;
    }

    // ---- Транзакции ------------------------------------------------------------------------------

    /**
     * Выполняет $fn в транзакции READ COMMITTED. COMMIT при успехе, ROLLBACK при любом исключении.
     * При deadlock (1213) или lock wait timeout (1205) вся транзакция повторяется с паузой 50–200 мс;
     * после исчерпания повторов — DomainError `uniundata_conflict_retry` (503).
     *
     * $fn может выполниться несколько раз, поэтому внутри — только запросы к БД: никаких HTTP-вызовов,
     * писем, do_action() сторонних плагинов и изменений внешнего состояния.
     * Вложенный вызов работает в транзакции внешнего уровня (без SAVEPOINT и без собственного повтора).
     *
     * @template T
     * @param callable(): T $fn
     * @return T
     */
    public function transaction(callable $fn): mixed
    {
        if ($this->depth > 0) {
            ++$this->depth;
            try {
                return $fn();
            } finally {
                --$this->depth;
            }
        }

        $this->ensureSession();
        // HyperDB/LudicrousDB иначе отправят SELECT … FOR UPDATE на реплику.
        if (method_exists($this->wpdb, 'send_reads_to_masters')) {
            $this->wpdb->send_reads_to_masters();
        }
        // Ошибки по-прежнему пишутся в error_log, но не печатаются HTML-ом в ответ REST.
        $previousShowErrors = $this->wpdb->hide_errors();
        $previousReconnectRetries = $this->disableReconnect();
        $strict = false;

        try {
            $strict = $this->enterStrictMode();

            for ($attempt = 1; ; ++$attempt) {
                $this->exec('START TRANSACTION');
                $this->depth = 1;
                $this->txConnection = $this->wpdb->dbh;

                try {
                    $result = $fn();
                    $this->exec('COMMIT');

                    return $result;
                } catch (\Throwable $e) {
                    $errno = $this->failureErrno($e); // до ROLLBACK, который перезапишет errno соединения
                    $this->rollbackQuietly();

                    if (!\in_array($errno, self::RETRYABLE_ERRNOS, true)) {
                        throw $e;
                    }
                    if ($attempt > $this->maxRetries) {
                        throw DomainError::conflictRetry(1, $e);
                    }
                } finally {
                    $this->depth = 0;
                    $this->txConnection = null;
                }

                usleep(random_int(50_000, 200_000));
            }
        } finally {
            $this->restoreReconnect($previousReconnectRetries);
            $this->leaveStrictMode($strict);
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

    /**
     * Один раз на соединение: UTC (DEFAULT CURRENT_TIMESTAMP(6) и NOW() пишут UTC), READ COMMITTED,
     * короткий lock wait timeout. Повторяется автоматически после переподключения $wpdb.
     */
    public function ensureSession(): void
    {
        $dbh = $this->wpdb->dbh;
        if (\is_object($dbh) && $this->sessionConnection?->get() === $dbh) {
            return;
        }

        $this->exec("SET time_zone = '+00:00'");
        $this->exec('SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED');
        $this->exec($this->prepare('SET SESSION innodb_lock_wait_timeout = %d', $this->lockWaitTimeoutSeconds));

        // Ссылку берём после запросов: $wpdb мог переподключиться на первом из них.
        $this->sessionConnection = \is_object($this->wpdb->dbh) ? \WeakReference::create($this->wpdb->dbh) : null;
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

    /** INSERT/UPDATE/DELETE. Возвращает число ИЗМЕНЁННЫХ строк (UPDATE тем же значением даёт 0). */
    public function execute(string $sql, int|string|float ...$args): int
    {
        $this->ensureSession();

        return $this->exec($this->prepare($sql, ...$args));
    }

    /** @return array<string, string|null>|null Строка как ассоциативный массив (значения — строки) или null. */
    public function getRow(string $sql, int|string|float ...$args): ?array
    {
        $this->ensureSession();
        $row = $this->wpdb->get_row($this->prepare($sql, ...$args), ARRAY_A);
        $this->assertSucceeded(true);

        return \is_array($row) ? $row : null;
    }

    /** @return list<array<string, string|null>> */
    public function getResults(string $sql, int|string|float ...$args): array
    {
        $this->ensureSession();
        $rows = $this->wpdb->get_results($this->prepare($sql, ...$args), ARRAY_A);
        $this->assertSucceeded(true);

        return \is_array($rows) ? array_values($rows) : [];
    }

    public function getVar(string $sql, int|string|float ...$args): ?string
    {
        $this->ensureSession();
        $value = $this->wpdb->get_var($this->prepare($sql, ...$args));
        $this->assertSucceeded(true);

        return $value === null ? null : (string) $value;
    }

    /**
     * INSERT с плейсхолдерами по типу значения (int → %d, string → %s, null → NULL).
     * Колонки из $utcNowColumns получают UTC_TIMESTAMP(6) — время сервера БД, а не PHP.
     * $wpdb->insert() не используется: он не умеет SQL-выражения и при сбое своей проверки
     * кодировки возвращает false без errno.
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

        $sql = \sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            $this->table($table),
            implode(', ', $columns),
            implode(', ', $values),
        );
        $this->execute($sql, ...$args);

        return $this->lastInsertId();
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
     * 1062 Duplicate entry, опционально по конкретному ключу. MySQL 8.0.19+ пишет имя ключа как
     * 'таблица.ключ', более ранние — просто 'ключ'; учитываются оба варианта.
     * Без $e проверяется последняя ошибка соединения (сразу после неудачного вызова $wpdb).
     */
    public function isDuplicateKey(?string $keyName = null, ?\Throwable $e = null): bool
    {
        $pattern = $keyName === null ? null : "/ for key '(?:[^']*\\.)?" . preg_quote($keyName, '/') . "'/";

        return $this->matchesError(self::ER_DUP_ENTRY, $pattern, $e);
    }

    /** 3819 Check constraint '<name>' is violated. */
    public function isCheckViolation(?string $constraintName = null, ?\Throwable $e = null): bool
    {
        $pattern = $constraintName === null ? null : "/Check constraint '" . preg_quote($constraintName, '/') . "' is violated/";

        return $this->matchesError(self::ER_CHECK_CONSTRAINT_VIOLATED, $pattern, $e);
    }

    // ---- Время -----------------------------------------------------------------------------------

    /** DATETIME(6) из БД (UTC) → ISO 8601 для API: '2026-10-05 12:00:00.123456' → '2026-10-05T12:00:00.123Z'. */
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

    private function exec(string $sql): int
    {
        $result = $this->wpdb->query($sql);
        $this->assertSucceeded($result !== false);

        return (int) $result;
    }

    private function assertSucceeded(bool $ok): void
    {
        if ($this->txConnection !== null && $this->wpdb->dbh !== $this->txConnection) {
            // $wpdb переподключился и повторил запрос на новом соединении в autocommit: транзакции больше нет.
            throw new \mysqli_sql_exception('Database connection was lost inside a transaction', self::CR_SERVER_GONE_ERROR);
        }
        $error = (string) $this->wpdb->last_error;
        if (!$ok || $error !== '') {
            throw new \mysqli_sql_exception($error !== '' ? $error : 'Unknown database error', $this->lastErrno());
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

    /** Строгий режим только на время транзакции; прежний sql_mode WordPress сохраняется в переменной сессии. */
    private function enterStrictMode(): bool
    {
        if (!$this->strictSqlMode) {
            return false;
        }
        $this->exec(
            "SET @uniundata_sql_mode = @@SESSION.sql_mode, "
            . "SESSION sql_mode = CONCAT_WS(',', NULLIF(@@SESSION.sql_mode, ''), 'STRICT_TRANS_TABLES')"
        );

        return true;
    }

    private function leaveStrictMode(bool $entered): void
    {
        if (!$entered) {
            return;
        }
        // Если соединение потеряно, новое уже получило sql_mode от WordPress — восстанавливать нечего.
        $this->wpdb->query('SET SESSION sql_mode = @uniundata_sql_mode');
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
