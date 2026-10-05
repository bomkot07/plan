<?php

declare(strict_types=1);

namespace Uniundata\Books\Install;

/**
 * Версионные миграции схемы (option `uniundata_db_version`).
 *
 * Почему НЕ dbDelta(): dbDelta разбирает CREATE TABLE регулярными выражениями и сравнивает с
 * SHOW COLUMNS/SHOW INDEX. Он не понимает generated columns (`GENERATED ALWAYS AS … STORED`, на них держится
 * «уникальность только активных»), CHECK и FOREIGN KEY: на повторном запуске пытается «исправить» такие
 * таблицы лишними ALTER или падает, а переименования и преобразования данных не умеет вовсе. Поэтому —
 * явные миграции, каждая идемпотентна (повтор после частичного сбоя безопасен: DDL в MySQL делает неявный
 * COMMIT, «откатить половину миграции» нельзя).
 *
 * Миграция 1 выполняет каноническую sql/schema.sql (единственный источник правды о схеме) через
 * $wpdb->query():
 *   - `wp_book_` → `{$wpdb->prefix}book_` (мультисайт: wp_2_book_…);
 *   - CREATE TABLE → CREATE TABLE IF NOT EXISTS, ALTER … ADD CONSTRAINT — только если такого ограничения нет;
 *   - SET NAMES/SET time_zone из файла пропускаются: соединение $wpdb общее с ядром;
 *   - имена CONSTRAINT получают префикс таблиц, если он не `wp_`: в MySQL 8 имена CHECK и FOREIGN KEY
 *     уникальны в пределах СХЕМЫ (БД), а не таблицы. Второй сайт мультисайта (или вторая установка WP
 *     в той же БД) иначе получил бы ошибку 3822 «Duplicate check constraint name» (проверено на 8.0.46).
 *
 * Запуск: register_activation_hook и plugins_loaded при отставании версии (Plugin). Одновременные запросы
 * после деплоя сериализуются GET_LOCK; основной путь в продакшене — шаг деплоя `wp uniundata migrate`.
 *
 * Будущие миграции — expand → migrate → contract: m002 добавляет nullable-колонку
 * (`ALTER TABLE … ADD COLUMN …, ALGORITHM=INSTANT` после проверки columnExists()), данные переносятся
 * пакетами через Action Scheduler, удаление старого — только в следующем релизе.
 */
final class Migrator
{
    /** Целевая версия схемы = максимальный ключ MIGRATIONS. */
    public const VERSION = 1;
    public const VERSION_OPTION = 'uniundata_db_version';
    public const MIN_MYSQL_VERSION = '8.0.16'; // CHECK-ограничения работают с 8.0.16

    /** @var array<int, string> версия → метод */
    private const MIGRATIONS = [
        1 => 'm001InitialSchema',
    ];

    /** Таблицы, которые должны существовать после миграций (без префикса). */
    public const TABLES = [
        'book_records', 'book_contributors', 'book_record_contributors', 'book_subjects', 'book_record_subjects',
        'book_identifiers', 'book_items', 'book_images', 'book_customer_profiles', 'book_user_consents',
        'book_carts', 'book_reservations', 'book_cart_items', 'book_orders', 'book_order_items', 'book_payments',
        'book_payment_events', 'book_sales', 'book_sync_runs', 'book_audit_log',
    ];

    public function __construct(
        private readonly \wpdb $wpdb,
        private readonly string $schemaFile,
    ) {
    }

    /** Дёшево (autoload-option): нужна ли миграция. */
    public function needsMigration(): bool
    {
        return (int) get_option(self::VERSION_OPTION, 0) < self::VERSION;
    }

    /**
     * @param int $lockTimeoutSeconds Сколько ждать чужую миграцию (активация/WP-CLI — 30 с, веб-запрос — 0–5 с).
     * @return array{from: int, to: int, applied: list<int>}
     * @throws \RuntimeException окружение не подходит, блокировка занята или DDL не выполнился.
     */
    public function migrate(int $lockTimeoutSeconds = 30): array
    {
        $this->assertEnvironment();

        $lock = 'uniundata_migrate@' . substr(md5((string) $this->wpdb->dbname . '|' . $this->wpdb->prefix), 0, 8);
        if ((string) $this->wpdb->get_var($this->wpdb->prepare('SELECT GET_LOCK(%s, %d)', $lock, $lockTimeoutSeconds)) !== '1') {
            throw new \RuntimeException('Another process is migrating the Uniundata Books schema; try again later');
        }

        try {
            // Версию читаем из БД в обход кэша: пока мы ждали блокировку, миграцию мог выполнить другой процесс.
            $from = $this->storedVersion();
            $applied = [];
            foreach (self::MIGRATIONS as $version => $method) {
                if ($version <= $from) {
                    continue;
                }
                $this->{$method}();
                // Версия — после КАЖДОЙ миграции: сбой в m003 не заставит повторять m002.
                update_option(self::VERSION_OPTION, $version, true);
                $applied[] = $version;
            }
            $this->assertTables();

            return ['from' => $from, 'to' => max($from, self::VERSION), 'applied' => $applied];
        } finally {
            $this->wpdb->query($this->wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock));
        }
    }

    /**
     * MySQL ≥ 8.0.16 (CHECK), не MariaDB (другая семантика generated columns/CHECK/`FOR UPDATE OF`),
     * InnoDB доступен (без NO_ENGINE_SUBSTITUTION MySQL молча создал бы MyISAM — без транзакций и FK).
     */
    public function assertEnvironment(): void
    {
        $version = (string) $this->wpdb->get_var('SELECT VERSION()');
        if (stripos($version, 'mariadb') !== false) {
            throw new \RuntimeException(\sprintf('Uniundata Books requires MySQL %s+, MariaDB is not supported (%s)', self::MIN_MYSQL_VERSION, $version));
        }
        if (preg_match('/^(\d+\.\d+\.\d+)/', $version, $m) !== 1 || version_compare($m[1], self::MIN_MYSQL_VERSION, '<')) {
            throw new \RuntimeException(\sprintf('Uniundata Books requires MySQL %s+ (found %s)', self::MIN_MYSQL_VERSION, $version !== '' ? $version : 'unknown'));
        }
        $innodb = (string) $this->wpdb->get_var("SELECT SUPPORT FROM information_schema.ENGINES WHERE ENGINE = 'InnoDB'");
        if (!\in_array(strtoupper($innodb), ['YES', 'DEFAULT'], true)) {
            throw new \RuntimeException('Uniundata Books requires the InnoDB storage engine');
        }
        if (method_exists($this->wpdb, 'has_cap') && !$this->wpdb->has_cap('utf8mb4_520')) {
            throw new \RuntimeException('Uniundata Books requires utf8mb4 with utf8mb4_unicode_520_ci collation');
        }
    }

    public function storedVersion(): int
    {
        $value = $this->wpdb->get_var($this->wpdb->prepare(
            "SELECT option_value FROM {$this->wpdb->options} WHERE option_name = %s",
            self::VERSION_OPTION,
        ));

        return $value === null ? 0 : (int) $value;
    }

    // =============================================================================================
    // Миграции
    // =============================================================================================

    /** v1: каноническая схема sql/schema.sql. */
    private function m001InitialSchema(): void
    {
        foreach ($this->schemaStatements() as $sql) {
            if (preg_match('/^ALTER\s+TABLE\s+`?(\w+)`?\s+ADD\s+CONSTRAINT\s+`?(\w+)`?/i', $sql, $m) === 1
                && $this->constraintExists($m[1], $m[2])) {
                continue; // повтор после частичного сбоя
            }
            $this->exec($sql);
        }
    }

    // =============================================================================================
    // SQL-файл
    // =============================================================================================

    /**
     * Операторы schema.sql, готовые к выполнению на этом сайте.
     *
     * @return list<string>
     */
    public function schemaStatements(): array
    {
        if (!is_readable($this->schemaFile)) {
            throw new \RuntimeException(\sprintf('Schema file is missing: %s', $this->schemaFile));
        }
        $sql = (string) file_get_contents($this->schemaFile);
        $prefix = $this->wpdb->prefix;

        $out = [];
        foreach (self::splitStatements($sql) as $statement) {
            if (preg_match('/^SET\s/i', $statement) === 1) {
                continue;
            }
            $statement = (string) preg_replace('/^CREATE\s+TABLE\s+(?!IF\s+NOT\s+EXISTS)/i', 'CREATE TABLE IF NOT EXISTS ', $statement);
            // Только имена таблиц плагина: wp_users/wp_posts в комментариях не трогаем.
            $statement = (string) preg_replace('/\bwp_(book_[a-z0-9_]+)\b/', $prefix . '$1', $statement);
            if ($prefix !== 'wp_') {
                $statement = (string) preg_replace_callback(
                    '/\bCONSTRAINT\s+`?((?:ck|fk)_[a-z0-9_]+)`?/i',
                    static fn (array $m): string => 'CONSTRAINT ' . self::constraintName($prefix, $m[1]),
                    $statement,
                );
            }
            $out[] = $statement;
        }

        return $out;
    }

    /**
     * Имя ограничения для сайта с префиксом, отличным от wp_ (≤ 64 символов).
     * Для префикса wp_ имена канонические: на них опираются сообщения об ошибках (3819 «Check constraint
     * 'ck_reservations_attempt_range' is violated») в коде сервисов.
     */
    public static function constraintName(string $prefix, string $name): string
    {
        $candidate = $prefix . $name;
        if (\strlen($candidate) <= 64) {
            return $candidate;
        }

        return substr($name, 0, 55) . '_' . substr(md5($prefix), 0, 8);
    }

    /**
     * Делит SQL на операторы по `;` вне строк, идентификаторов в обратных кавычках и комментариев.
     * Комментарии `-- …` и `/* … *\/` отбрасываются (COMMENT '…' внутри CREATE TABLE остаётся — это строка).
     *
     * @return list<string>
     */
    public static function splitStatements(string $sql): array
    {
        $statements = [];
        $buf = '';
        $len = \strlen($sql);
        $quote = null;
        for ($i = 0; $i < $len; ++$i) {
            $ch = $sql[$i];
            $next = $sql[$i + 1] ?? '';
            if ($quote !== null) {
                $buf .= $ch;
                if ($ch === '\\' && $quote !== '`') {
                    $buf .= $next;
                    ++$i;
                } elseif ($ch === $quote) {
                    if ($next === $quote) { // '' внутри строки
                        $buf .= $next;
                        ++$i;
                    } else {
                        $quote = null;
                    }
                }
                continue;
            }
            if ($ch === '-' && $next === '-' && (($sql[$i + 2] ?? ' ') === ' ' || ($sql[$i + 2] ?? '') === "\t" || ($sql[$i + 2] ?? '') === "\n")) {
                $end = strpos($sql, "\n", $i);
                $i = $end === false ? $len : $end;
                $buf .= "\n";
                continue;
            }
            if ($ch === '#') {
                $end = strpos($sql, "\n", $i);
                $i = $end === false ? $len : $end;
                $buf .= "\n";
                continue;
            }
            if ($ch === '/' && $next === '*') {
                $end = strpos($sql, '*/', $i + 2);
                $i = $end === false ? $len : $end + 1;
                $buf .= ' ';
                continue;
            }
            if ($ch === "'" || $ch === '"' || $ch === '`') {
                $quote = $ch;
                $buf .= $ch;
                continue;
            }
            if ($ch === ';') {
                if (trim($buf) !== '') {
                    $statements[] = trim($buf);
                }
                $buf = '';
                continue;
            }
            $buf .= $ch;
        }
        if (trim($buf) !== '') {
            $statements[] = trim($buf);
        }

        return $statements;
    }

    // =============================================================================================
    // information_schema
    // =============================================================================================

    public function tableExists(string $table): bool
    {
        return (bool) $this->wpdb->get_var($this->wpdb->prepare(
            'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s',
            $table,
        ));
    }

    public function columnExists(string $table, string $column): bool
    {
        return (bool) $this->wpdb->get_var($this->wpdb->prepare(
            'SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = %s',
            $table,
            $column,
        ));
    }

    public function constraintExists(string $table, string $constraint): bool
    {
        return (bool) $this->wpdb->get_var($this->wpdb->prepare(
            'SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
              WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = %s AND CONSTRAINT_NAME = %s',
            $table,
            $constraint,
        ));
    }

    /** Все таблицы на месте и в InnoDB (CREATE TABLE IF NOT EXISTS не исправит чужую таблицу с тем же именем). */
    private function assertTables(): void
    {
        $names = array_map(fn (string $t): string => $this->wpdb->prefix . $t, self::TABLES);
        $placeholders = implode(', ', array_fill(0, \count($names), '%s'));
        $rows = $this->wpdb->get_results($this->wpdb->prepare(
            "SELECT TABLE_NAME AS name, ENGINE AS engine FROM information_schema.TABLES
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ({$placeholders})",
            ...$names,
        ), ARRAY_A);
        $found = [];
        foreach ((array) $rows as $row) {
            $found[(string) $row['name']] = strtolower((string) $row['engine']);
        }
        foreach ($names as $name) {
            if (!isset($found[$name])) {
                throw new \RuntimeException(\sprintf('Table %s was not created', $name));
            }
            if ($found[$name] !== 'innodb') {
                throw new \RuntimeException(\sprintf('Table %s uses %s instead of InnoDB', $name, $found[$name]));
            }
        }
    }

    private function exec(string $sql): void
    {
        // CREATE … не проходит проверку кодировок wpdb (strip_invalid_text_from_query их пропускает),
        // поэтому COMMENT на русском допустим. Ошибку не печатаем в ответ — бросаем исключение.
        $suppress = $this->wpdb->suppress_errors(true);
        try {
            $ok = $this->wpdb->query($sql);
            if ($ok === false) {
                $hint = str_contains((string) $this->wpdb->last_error, 'Duplicate')
                    ? ' (constraint name collision with another table in this database?)'
                    : '';
                throw new \RuntimeException(\sprintf(
                    'Migration statement failed: %s%s. Statement: %s',
                    $this->wpdb->last_error,
                    $hint,
                    mb_substr(preg_replace('/\s+/', ' ', $sql) ?? '', 0, 160),
                ));
            }
        } finally {
            $this->wpdb->suppress_errors($suppress);
        }
    }
}
