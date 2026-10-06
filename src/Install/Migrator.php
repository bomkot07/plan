<?php

declare(strict_types=1);

namespace Uniundata\Books\Install;

use Uniundata\Books\Infrastructure\Db;
use Uniundata\Books\Payment\FiscalReceipt;
use Uniundata\Books\Service\ReservationService;

/**
 * Версионные миграции схемы (option `uniundata_db_version`).
 *
 * Почему НЕ dbDelta(): dbDelta разбирает CREATE TABLE регулярными выражениями и сравнивает с
 * SHOW COLUMNS/SHOW INDEX. Он не понимает generated columns (`GENERATED ALWAYS AS … STORED`, на них держится
 * «уникальность только активных»), CHECK и FOREIGN KEY: на повторном запуске пытается «исправить» такие
 * таблицы лишними ALTER или падает, а переименования и преобразования данных не умеет вовсе. Поэтому —
 * явные миграции, каждая идемпотентна (DDL в MySQL делает неявный COMMIT, «откатить половину миграции»
 * нельзя — повтор после частичного сбоя обязан быть безопасным).
 *
 * Миграция 1 выполняет каноническую sql/schema.sql (единственный источник правды о схеме):
 *   - `wp_book_…` → `{$wpdb->prefix}book_…` одной заменой по границе слова. Имена CHECK/FOREIGN KEY
 *     начинаются с имени таблицы (wp_book_items_chk_status), поэтому меняются той же заменой: в MySQL 8
 *     они уникальны в пределах БД, и мультисайт (wp_2_) или второй WordPress в той же БД не конфликтуют;
 *   - CREATE TABLE → CREATE TABLE IF NOT EXISTS; ALTER … ADD CONSTRAINT — только если ограничения нет;
 *   - SET из файла не выполняются: сессию задаёт сам мигратор (withDdlSession): SET NAMES utf8mb4 и
 *     innodb_ft_enable_stopword = OFF — настройка стоп-слов фиксируется в таблице при создании её
 *     FULLTEXT-индексов (без неё обязательное «+und»/«+the» обнуляет выдачу BOOLEAN MODE);
 *   - таблицы могли быть созданы заранее вручную (phpMyAdmin, импорт дампа): после выполнения схема
 *     сверяется с information_schema (колонки, utf8mb4, индексы и их уникальность, generated columns,
 *     CHECK/FK, InnoDB). Расхождение — исключение со списком, данные не трогаются. FULLTEXT-индексы
 *     заранее созданной таблицы перестраиваются: узнать, с какими стоп-словами они построены, без
 *     прав PROCESS/SYSTEM_VARIABLES_ADMIN нельзя.
 *
 * Перед DDL — проверка окружения: MySQL ≥ 8.0.16 (CHECK), не MariaDB, InnoDB, utf8mb4_unicode_520_ci и
 * права пользователя БД (SHOW GRANTS): без REFERENCES MySQL отвергает FOREIGN KEY (ошибка 1142).
 *
 * Запуск: register_activation_hook и plugins_loaded при отставании версии (Plugin). Одновременные запросы
 * сериализуются GET_LOCK(Db::lockName('migrate')); основной путь в продакшене — шаг деплоя
 * `wp uniundata migrate`. Каждый запуск добавляет недостающие options магазина (DEFAULT_OPTIONS, add_option:
 * значения, заданные администратором, не перезаписываются).
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

    /** Права пользователя БД: данные + DDL миграций. REFERENCES — для FOREIGN KEY. */
    public const REQUIRED_PRIVILEGES = ['SELECT', 'INSERT', 'UPDATE', 'DELETE', 'CREATE', 'ALTER', 'INDEX', 'REFERENCES'];

    private const LOCK = 'migrate';
    private const MAX_IDENTIFIER = 64;

    /** @var array<int, string> версия → метод */
    private const MIGRATIONS = [
        1 => 'm001InitialSchema',
    ];

    /**
     * Options по умолчанию: имя → [значение, autoload]. Валюта магазина shop.libsmr.ru — RUB (суммы в копейках);
     * ставка НДС для чеков 54-ФЗ — 'none' до решения бухгалтера (FiscalReceipt). Секретов здесь нет: ключи
     * банка и источника — в wp-config.php / окружении.
     */
    public const DEFAULT_OPTIONS = [
        'uniundata_currency' => ['RUB', true],
        'uniundata_max_active_reservations' => [ReservationService::DEFAULT_MAX_ACTIVE_RESERVATIONS, true],
        FiscalReceipt::OPTION_VAT => ['none', true],
        'uniundata_payment_ttl_minutes' => [30, true],
        'uniundata_payment_grace_minutes' => [10, true],
        'uniundata_reservation_minutes' => [60, true], // менять только вместе с бизнес-правилами
        'uniundata_sync_source' => ['primary', false],
        'uniundata_terms_versions' => [[], false], // заполняет администратор: version + sha256 оферты и политики
    ];

    /** Таблицы, которые должны существовать после миграций (без префикса). */
    public const TABLES = [
        'book_records', 'book_contributors', 'book_record_contributors', 'book_subjects', 'book_record_subjects',
        'book_identifiers', 'book_items', 'book_images', 'book_customer_profiles', 'book_user_consents',
        'book_carts', 'book_reservations', 'book_cart_items', 'book_orders', 'book_order_items', 'book_payments',
        'book_payment_events', 'book_refunds', 'book_sales', 'book_sync_runs', 'book_audit_log',
    ];

    /** @var array<string, array{columns: array<string, bool>, indexes: array<string, array{type: string, columns: string}>, constraints: list<string>}>|null */
    private ?array $expected = null;

    public function __construct(
        private readonly Db $db,
        private readonly string $schemaFile,
    ) {
    }

    /** Дёшево (autoload-option): нужна ли миграция. */
    public function needsMigration(): bool
    {
        return (int) get_option(self::VERSION_OPTION, 0) < self::VERSION;
    }

    /**
     * @param int  $lockTimeoutSeconds Сколько ждать чужую миграцию (активация/WP-CLI — 30 с, веб-запрос — 0–5 с).
     * @param bool $rebuildFulltext    Перестроить все FULLTEXT-индексы (`wp uniundata migrate --rebuild-fulltext`):
     *                                 после смены innodb_ft_min_token_size или для таблиц, созданных вручную.
     * @return array{from: int, to: int, applied: list<int>, rebuilt: list<string>, options_added: list<string>}
     * @throws \RuntimeException окружение не подходит, блокировка занята, DDL не выполнился или схема расходится.
     */
    public function migrate(int $lockTimeoutSeconds = 30, bool $rebuildFulltext = false): array
    {
        $this->assertEnvironment();

        if (!$this->db->getLock(self::LOCK, max(0, $lockTimeoutSeconds))) {
            throw new \RuntimeException('Another process is migrating the Uniundata Books schema; try again later');
        }

        try {
            // Версию читаем из БД в обход кэша: пока мы ждали блокировку, миграцию мог выполнить другой процесс.
            $from = $this->storedVersion();
            $applied = [];
            $rebuilt = [];
            foreach (self::MIGRATIONS as $version => $method) {
                if ($version <= $from) {
                    continue;
                }
                $rebuilt = array_merge($rebuilt, $this->{$method}());
                // Версия — после КАЖДОЙ миграции: сбой в m003 не заставит повторять m002.
                update_option(self::VERSION_OPTION, $version, true);
                $applied[] = $version;
            }
            if ($rebuildFulltext) {
                foreach (array_keys($this->fulltextIndexes()) as $table) {
                    if (!\in_array($table, $rebuilt, true)) {
                        $this->rebuildFulltext($table);
                        $rebuilt[] = $table;
                    }
                }
            }
            $this->assertSchema();
            $optionsAdded = $this->installDefaultOptions();

            return [
                'from' => $from, 'to' => max($from, self::VERSION), 'applied' => $applied, 'rebuilt' => $rebuilt,
                'options_added' => $optionsAdded,
            ];
        } finally {
            $this->db->releaseLock(self::LOCK);
        }
    }

    /**
     * add_option для отсутствующих DEFAULT_OPTIONS. Существующее значение (в том числе изменённое
     * администратором) не трогается.
     *
     * @return list<string> Добавленные options.
     */
    public function installDefaultOptions(): array
    {
        $added = [];
        foreach (self::DEFAULT_OPTIONS as $name => [$value, $autoload]) {
            if (add_option($name, $value, '', $autoload)) {
                $added[] = $name;
            }
        }

        return $added;
    }

    public function storedVersion(): int
    {
        $wpdb = $this->db->wpdb();
        $value = $this->db->getVar("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", self::VERSION_OPTION);

        return $value === null ? 0 : (int) $value;
    }

    // =============================================================================================
    // Окружение
    // =============================================================================================

    /**
     * MySQL ≥ 8.0.16 (CHECK), не MariaDB (другая семантика generated columns/CHECK), InnoDB доступен
     * (без NO_ENGINE_SUBSTITUTION MySQL молча создал бы MyISAM — без транзакций и FK), utf8mb4_unicode_520_ci,
     * права на DDL и REFERENCES.
     */
    public function assertEnvironment(): void
    {
        $version = (string) $this->db->getVar('SELECT VERSION()');
        if (stripos($version, 'mariadb') !== false) {
            throw new \RuntimeException(\sprintf('Uniundata Books requires MySQL %s+, MariaDB is not supported (%s)', self::MIN_MYSQL_VERSION, $version));
        }
        if (preg_match('/^(\d+\.\d+\.\d+)/', $version, $m) !== 1 || version_compare($m[1], self::MIN_MYSQL_VERSION, '<')) {
            throw new \RuntimeException(\sprintf('Uniundata Books requires MySQL %s+ (found %s)', self::MIN_MYSQL_VERSION, $version !== '' ? $version : 'unknown'));
        }
        $innodb = (string) $this->db->getVar("SELECT SUPPORT FROM information_schema.ENGINES WHERE ENGINE = 'InnoDB'");
        if (!\in_array(strtoupper($innodb), ['YES', 'DEFAULT'], true)) {
            throw new \RuntimeException('Uniundata Books requires the InnoDB storage engine');
        }
        if ($this->db->getVar("SELECT COUNT(*) FROM information_schema.COLLATIONS WHERE COLLATION_NAME = 'utf8mb4_unicode_520_ci'") !== '1') {
            throw new \RuntimeException('Uniundata Books requires utf8mb4 with utf8mb4_unicode_520_ci collation');
        }
        $grants = $this->missingPrivileges();
        if ($grants['certain'] && $grants['missing'] !== []) {
            throw new \RuntimeException(\sprintf(
                'The database user lacks privileges %s on `%s`. Ask the hosting provider: GRANT %s ON `%s`.* TO <wp user>; (REFERENCES is required for FOREIGN KEY)',
                implode(', ', $grants['missing']),
                $this->databaseName(),
                implode(', ', $grants['missing']),
                $this->databaseName(),
            ));
        }
    }

    /**
     * Какие из REQUIRED_PRIVILEGES не выданы пользователю БД на текущую базу (по SHOW GRANTS: прямые права
     * и права активных ролей). certain = false, если вывод нельзя оценить однозначно (права на отдельные
     * таблицы, нестандартный формат) — тогда мигратор не отказывает заранее, а DDL сам вернёт 1142
     * с понятной подсказкой.
     *
     * @return array{missing: list<string>, certain: bool}
     */
    public function missingPrivileges(): array
    {
        $wpdb = $this->db->wpdb();
        $suppress = $wpdb->suppress_errors(true);
        try {
            $lines = $wpdb->get_col('SHOW GRANTS');
        } finally {
            $wpdb->suppress_errors($suppress);
        }
        if (!\is_array($lines) || $lines === []) {
            return ['missing' => [], 'certain' => false];
        }

        $database = $this->databaseName();
        $granted = [];
        $certain = true;
        foreach ($lines as $line) {
            $line = (string) $line;
            // «GRANT `role`@`host` TO …» пропускаем: права АКТИВНЫХ ролей SHOW GRANTS уже показал строками
            // «GRANT … ON …», а права неактивных ролей соединению WordPress и не действуют.
            if (preg_match('/^GRANT\s+(.+?)\s+ON\s+(\S+)\s+TO\s/i', $line, $m) !== 1
                || preg_match('/^PROXY$/i', $m[1]) === 1) {
                continue;
            }
            [, $privileges, $scope] = $m;
            if (preg_match('/^(\*|`(?:[^`]|``)+`)\.(\*|`(?:[^`]|``)+`)$/', $scope, $s) !== 1) {
                $certain = false; // права на процедуры/функции и прочие нестандартные формы
                continue;
            }
            if ($s[1] !== '*' && !self::grantDatabaseMatches(substr($s[1], 1, -1), $database)) {
                continue;
            }
            if ($s[2] !== '*') {
                // Права на отдельные таблицы плагина оценивать не беремся; на чужие (wp_options) — не важны.
                if (str_starts_with(str_replace('``', '`', substr($s[2], 1, -1)), $this->wpdb()->prefix . 'book_')) {
                    $certain = false;
                }
                continue;
            }
            foreach (preg_split('/\s*,\s*/', strtoupper((string) preg_replace('/\([^)]*\)/', '', $privileges))) ?: [] as $p) {
                $granted[trim($p)] = true;
            }
        }
        if (isset($granted['ALL']) || isset($granted['ALL PRIVILEGES'])) {
            return ['missing' => [], 'certain' => true];
        }

        return [
            'missing' => array_values(array_filter(self::REQUIRED_PRIVILEGES, static fn (string $p): bool => !isset($granted[$p]))),
            'certain' => $certain,
        ];
    }

    /**
     * Не блокирует установку, но важно для эксплуатации (WP-CLI migrate/doctor, admin notice).
     *
     * @return list<string>
     */
    public function environmentWarnings(): array
    {
        $warnings = [];
        $row = $this->db->getRow(
            'SELECT @@GLOBAL.time_zone AS tz, @@system_time_zone AS system_tz, @@innodb_ft_min_token_size AS ft_min,
                    TIMESTAMPDIFF(SECOND, UTC_TIMESTAMP(), NOW()) AS offset_seconds'
        );
        if ($row !== null) {
            $utc = \in_array($row['tz'], ['+00:00', 'UTC', 'Etc/UTC'], true)
                || ($row['tz'] === 'SYSTEM' && \in_array($row['system_tz'], ['UTC', 'GMT'], true));
            if (!$utc) {
                $warnings[] = \sprintf(
                    'MySQL default time zone is %s (offset %+d s). The plugin sets +00:00 inside its transactions, but other writers would get local time in DEFAULT CURRENT_TIMESTAMP columns. Recommended: default-time-zone = \'+00:00\' in my.cnf.',
                    $row['tz'] === 'SYSTEM' ? 'SYSTEM/' . $row['system_tz'] : $row['tz'],
                    (int) $row['offset_seconds'],
                );
            }
            if ((int) $row['ft_min'] !== 3) {
                $warnings[] = \sprintf('innodb_ft_min_token_size = %d (search expects 3). After changing it run `wp uniundata migrate --rebuild-fulltext`.', (int) $row['ft_min']);
            }
        }
        $grants = $this->missingPrivileges();
        if (!$grants['certain']) {
            $warnings[] = 'Could not verify database privileges from SHOW GRANTS (table-level grants?); CREATE, ALTER, INDEX and REFERENCES are required.';
        }

        return $warnings;
    }

    // =============================================================================================
    // Миграции
    // =============================================================================================

    /**
     * v1: каноническая схема sql/schema.sql.
     *
     * @return list<string> Таблицы, у которых перестроены FULLTEXT-индексы.
     */
    private function m001InitialSchema(): array
    {
        $statements = $this->schemaStatements();
        // Заранее созданные таблицы (вручную или прошлой упавшей попыткой) — для перестройки FULLTEXT.
        $preexisting = array_values(array_filter(
            array_map(fn (string $t): string => $this->wpdb()->prefix . $t, self::TABLES),
            fn (string $table): bool => $this->tableExists($table),
        ));

        $this->withDdlSession(function () use ($statements): void {
            foreach ($statements as $sql) {
                if (preg_match('/^ALTER\s+TABLE\s+`?(\w+)`?\s+ADD\s+CONSTRAINT\s+`?(\w+)`?/i', $sql, $m) === 1
                    && $this->constraintExists($m[1], $m[2])) {
                    continue; // повтор после частичного сбоя или таблицы созданы вручную
                }
                $this->exec($sql);
            }
        });

        // Сначала сверка: перестраивать индексы чужой, не совпадающей со схемой таблицы нельзя.
        $this->assertSchema();
        $rebuilt = [];
        foreach (array_keys($this->fulltextIndexes()) as $table) {
            if (\in_array($table, $preexisting, true)) {
                $this->rebuildFulltext($table);
                $rebuilt[] = $table;
            }
        }

        return $rebuilt;
    }

    /**
     * Перестраивает ВСЕ FULLTEXT-индексы таблицы при innodb_ft_enable_stopword = OFF. Настройка стоп-слов
     * хранится на уровне таблицы и меняется, только если удалить все её FULLTEXT-индексы, а затем создать
     * их отдельными ALTER (по одному: ошибка 1795; «DROP INDEX x, ADD FULLTEXT x» одним ALTER оставляет
     * старую настройку — проверено на 8.0.46). Пока индексов нет, поиск по таблице отвечает ошибкой 1191.
     */
    public function rebuildFulltext(string $table): void
    {
        $indexes = $this->fulltextIndexes()[$table] ?? [];
        if ($indexes === []) {
            return;
        }
        $this->withDdlSession(function () use ($table, $indexes): void {
            foreach (array_keys($indexes) as $name) {
                if ($this->indexExists($table, $name)) {
                    $this->exec("ALTER TABLE `{$table}` DROP INDEX `{$name}`");
                }
            }
            foreach ($indexes as $name => $columns) {
                $this->exec("ALTER TABLE `{$table}` ADD FULLTEXT KEY `{$name}` ({$columns})");
            }
        });
    }

    // =============================================================================================
    // SQL-файл и ожидаемая схема
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
        $prefix = $this->wpdb()->prefix;
        if (preg_match('/^[A-Za-z0-9_]+$/', $prefix) !== 1) {
            throw new \RuntimeException('Unsupported table prefix');
        }

        $out = [];
        foreach (self::splitStatements((string) file_get_contents($this->schemaFile)) as $statement) {
            if (preg_match('/^SET\s/i', $statement) === 1) {
                continue; // сессию задаёт withDdlSession()
            }
            $statement = (string) preg_replace('/^CREATE\s+TABLE\s+(?!IF\s+NOT\s+EXISTS)/i', 'CREATE TABLE IF NOT EXISTS ', $statement);
            // Таблицы плагина и имена их ограничений (wp_book_items_chk_status). wp_users/wp_posts не трогаем.
            $statement = (string) preg_replace_callback(
                '/\bwp_(book_[a-z0-9_]+)\b/',
                static fn (array $m): string => $prefix . $m[1],
                $statement,
            );
            if (preg_match_all('/\b(?:CONSTRAINT|TABLE(?:\s+IF\s+NOT\s+EXISTS)?)\s+`?(\w+)`?/i', $statement, $names) > 0) {
                foreach ($names[1] as $name) {
                    if (\strlen($name) > self::MAX_IDENTIFIER) {
                        throw new \RuntimeException(\sprintf(
                            'Table prefix "%s" is too long: identifier %s exceeds %d characters',
                            $prefix,
                            $name,
                            self::MAX_IDENTIFIER,
                        ));
                    }
                }
            }
            $out[] = $statement;
        }

        return $out;
    }

    /**
     * Сверка существующих таблиц с schema.sql: колонки (utf8mb4, generated), индексы (уникальность,
     * FULLTEXT), CHECK/FOREIGN KEY, движок. Лишние колонки/индексы допускаются (будущие миграции).
     *
     * @return list<string> Расхождения; пустой список — схема совпадает.
     */
    public function verifySchema(): array
    {
        $expected = $this->expectedSchema();
        $tables = array_keys($expected);
        $in = $this->db->placeholders(\count($tables), '%s');
        $problems = [];

        $engines = [];
        foreach ($this->db->getResults(
            "SELECT TABLE_NAME AS t, ENGINE AS engine FROM information_schema.TABLES
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ({$in})",
            ...$tables,
        ) as $row) {
            $engines[(string) $row['t']] = strtolower((string) $row['engine']);
        }

        $columns = [];
        foreach ($this->db->getResults(
            "SELECT TABLE_NAME AS t, COLUMN_NAME AS c, CHARACTER_SET_NAME AS cs, EXTRA AS extra
               FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ({$in})",
            ...$tables,
        ) as $row) {
            $columns[(string) $row['t']][strtolower((string) $row['c'])] = $row;
        }

        $indexes = [];
        foreach ($this->db->getResults(
            "SELECT TABLE_NAME AS t, INDEX_NAME AS i, MIN(NON_UNIQUE) AS non_unique, MIN(INDEX_TYPE) AS type
               FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ({$in})
              GROUP BY TABLE_NAME, INDEX_NAME",
            ...$tables,
        ) as $row) {
            $indexes[(string) $row['t']][(string) $row['i']] = $row;
        }

        $constraints = [];
        foreach ($this->db->getResults(
            "SELECT TABLE_NAME AS t, CONSTRAINT_NAME AS n FROM information_schema.TABLE_CONSTRAINTS
              WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME IN ({$in}) AND CONSTRAINT_TYPE IN ('CHECK', 'FOREIGN KEY')",
            ...$tables,
        ) as $row) {
            $constraints[(string) $row['t']][(string) $row['n']] = true;
        }

        foreach ($expected as $table => $spec) {
            if (!isset($engines[$table])) {
                $problems[] = "{$table}: table is missing";
                continue;
            }
            if ($engines[$table] !== 'innodb') {
                $problems[] = "{$table}: engine {$engines[$table]} instead of InnoDB";
            }
            foreach ($spec['columns'] as $column => $generated) {
                $actual = $columns[$table][$column] ?? null;
                if ($actual === null) {
                    $problems[] = "{$table}: missing column {$column}";
                    continue;
                }
                if ($actual['cs'] !== null && $actual['cs'] !== 'utf8mb4') {
                    $problems[] = "{$table}.{$column}: character set {$actual['cs']} instead of utf8mb4";
                }
                if ($generated && stripos((string) $actual['extra'], 'STORED GENERATED') === false) {
                    $problems[] = "{$table}.{$column}: must be a STORED generated column";
                }
            }
            foreach ($spec['indexes'] as $name => $index) {
                $actual = $indexes[$table][$name] ?? null;
                if ($actual === null) {
                    $problems[] = "{$table}: missing index {$name}";
                } elseif ($index['type'] === 'unique' && $actual['non_unique'] !== '0') {
                    $problems[] = "{$table}: index {$name} must be UNIQUE";
                } elseif ($index['type'] === 'fulltext' && $actual['type'] !== 'FULLTEXT') {
                    $problems[] = "{$table}: index {$name} must be FULLTEXT";
                }
            }
            foreach ($spec['constraints'] as $name) {
                if (!isset($constraints[$table][$name])) {
                    $problems[] = "{$table}: missing constraint {$name}";
                }
            }
        }

        return $problems;
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
            if ($ch === '-' && $next === '-' && \in_array($sql[$i + 2] ?? "\n", [' ', "\t", "\n", "\r"], true)) {
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

    /**
     * Ожидаемая структура из schemaStatements(): разбор тела CREATE TABLE по запятым верхнего уровня.
     *
     * @return array<string, array{columns: array<string, bool>, indexes: array<string, array{type: string, columns: string}>, constraints: list<string>}>
     */
    private function expectedSchema(): array
    {
        if ($this->expected !== null) {
            return $this->expected;
        }
        $schema = [];
        foreach ($this->schemaStatements() as $sql) {
            if (preg_match('/^ALTER\s+TABLE\s+`?(\w+)`?\s+ADD\s+CONSTRAINT\s+`?(\w+)`?/i', $sql, $m) === 1) {
                $schema[$m[1]] = ($schema[$m[1]] ?? []) + ['columns' => [], 'indexes' => [], 'constraints' => []];
                $schema[$m[1]]['constraints'][] = $m[2];
                continue;
            }
            if (preg_match('/^CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?`?(\w+)`?\s*\(/i', $sql, $m, PREG_OFFSET_CAPTURE) !== 1) {
                continue;
            }
            $table = $m[1][0];
            $schema[$table] = ($schema[$table] ?? []) + ['columns' => [], 'indexes' => [], 'constraints' => []];
            foreach (self::topLevelParts($sql, $m[0][1] + \strlen($m[0][0])) as $part) {
                if (preg_match('/^PRIMARY\s+KEY\b/i', $part) === 1) {
                    $schema[$table]['indexes']['PRIMARY'] = ['type' => 'unique', 'columns' => ''];
                } elseif (preg_match('/^(UNIQUE|FULLTEXT)?\s*(?:KEY|INDEX)\s+`?(\w+)`?\s*\((.*)\)$/is', $part, $k) === 1) {
                    $schema[$table]['indexes'][$k[2]] = [
                        'type' => strtolower($k[1] !== '' ? $k[1] : 'key'),
                        'columns' => (string) preg_replace('/\s+/', ' ', trim($k[3])),
                    ];
                } elseif (preg_match('/^CONSTRAINT\s+`?(\w+)`?/i', $part, $k) === 1) {
                    $schema[$table]['constraints'][] = $k[1];
                } elseif (preg_match('/^`?(\w+)`?\s/', $part, $k) === 1) {
                    $schema[$table]['columns'][strtolower($k[1])] = stripos($part, 'GENERATED ALWAYS AS') !== false;
                }
            }
        }

        return $this->expected = $schema;
    }

    /** @return array<string, array<string, string>> таблица → [имя FULLTEXT-индекса → список колонок] */
    private function fulltextIndexes(): array
    {
        $out = [];
        foreach ($this->expectedSchema() as $table => $spec) {
            foreach ($spec['indexes'] as $name => $index) {
                if ($index['type'] === 'fulltext') {
                    $out[$table][$name] = $index['columns'];
                }
            }
        }

        return $out;
    }

    /**
     * Части тела CREATE TABLE от $offset до парной «)»: запятые внутри скобок и строк не делят.
     *
     * @return list<string>
     */
    private static function topLevelParts(string $sql, int $offset): array
    {
        $parts = [];
        $buf = '';
        $depth = 0;
        $quote = null;
        for ($i = $offset, $len = \strlen($sql); $i < $len; ++$i) {
            $ch = $sql[$i];
            if ($quote !== null) {
                $buf .= $ch;
                if ($ch === '\\') {
                    $buf .= $sql[++$i] ?? '';
                } elseif ($ch === $quote) {
                    $quote = null;
                }
                continue;
            }
            if ($ch === "'" || $ch === '"' || $ch === '`') {
                $quote = $ch;
            } elseif ($ch === '(') {
                ++$depth;
            } elseif ($ch === ')') {
                if ($depth === 0) {
                    break;
                }
                --$depth;
            } elseif ($ch === ',' && $depth === 0) {
                $parts[] = trim($buf);
                $buf = '';
                continue;
            }
            $buf .= $ch;
        }
        if (trim($buf) !== '') {
            $parts[] = trim($buf);
        }

        return $parts;
    }

    // =============================================================================================
    // information_schema
    // =============================================================================================

    public function tableExists(string $table): bool
    {
        return $this->db->getVar(
            'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s',
            $table,
        ) !== '0';
    }

    public function columnExists(string $table, string $column): bool
    {
        return $this->db->getVar(
            'SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = %s',
            $table,
            $column,
        ) !== '0';
    }

    public function indexExists(string $table, string $index): bool
    {
        return $this->db->getVar(
            'SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND INDEX_NAME = %s',
            $table,
            $index,
        ) !== '0';
    }

    public function constraintExists(string $table, string $constraint): bool
    {
        return $this->db->getVar(
            'SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
              WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = %s AND CONSTRAINT_NAME = %s',
            $table,
            $constraint,
        ) !== '0';
    }

    // =============================================================================================
    // Внутреннее
    // =============================================================================================

    private function assertSchema(): void
    {
        $problems = $this->verifySchema();
        if ($problems !== []) {
            throw new \RuntimeException(\sprintf(
                'Existing tables do not match sql/schema.sql (created manually or by another version?): %s%s',
                implode('; ', \array_slice($problems, 0, 10)),
                \count($problems) > 10 ? \sprintf('; … %d more', \count($problems) - 10) : '',
            ));
        }
    }

    /**
     * Сессия DDL на общем соединении $wpdb: utf8mb4 (комментарии и литералы CHECK по-русски) и FULLTEXT
     * без стоп-слов. После DDL — исходные значения: стоп-слова из переменной, кодировка — wpdb::set_charset().
     *
     * @template T
     * @param callable(): T $fn
     * @return T
     */
    private function withDdlSession(callable $fn): mixed
    {
        $wpdb = $this->wpdb();
        $this->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_520_ci");
        $this->exec('SET @uniundata_ft_stopword = @@SESSION.innodb_ft_enable_stopword, SESSION innodb_ft_enable_stopword = OFF');
        try {
            return $fn();
        } finally {
            $suppress = $wpdb->suppress_errors(true);
            $wpdb->query('SET SESSION innodb_ft_enable_stopword = COALESCE(@uniundata_ft_stopword, @@SESSION.innodb_ft_enable_stopword)');
            if ($wpdb->dbh instanceof \mysqli) {
                $wpdb->set_charset($wpdb->dbh);
            }
            $wpdb->suppress_errors($suppress);
        }
    }

    private function exec(string $sql): void
    {
        $wpdb = $this->wpdb();
        // Ошибку не печатаем в ответ — бросаем исключение.
        $suppress = $wpdb->suppress_errors(true);
        try {
            if ($wpdb->query($sql) === false) {
                $errno = $this->db->lastErrno();
                $hint = match (true) {
                    $errno === 1142 || $errno === 1044 => ' (missing privilege: CREATE, ALTER, INDEX and REFERENCES are required)',
                    $errno === 3822 || $errno === 1826 => ' (constraint name is used by another table in this database)',
                    $errno === 1059 => ' (identifier too long: shorten the table prefix)',
                    default => '',
                };
                throw new \RuntimeException(\sprintf(
                    'Migration statement failed: [%d] %s%s. Statement: %s',
                    $errno,
                    $wpdb->last_error,
                    $hint,
                    mb_substr(preg_replace('/\s+/', ' ', $sql) ?? '', 0, 160),
                ));
            }
        } finally {
            $wpdb->suppress_errors($suppress);
        }
    }

    private function wpdb(): \wpdb
    {
        return $this->db->wpdb();
    }

    private function databaseName(): string
    {
        return (string) $this->db->getVar('SELECT DATABASE()');
    }

    /** Имя БД в GRANT — шаблон: `_` и `%` — подстановочные символы, `\_` и `\%` — буквальные. */
    private static function grantDatabaseMatches(string $pattern, string $database): bool
    {
        $pattern = str_replace('``', '`', $pattern);
        $regex = '';
        for ($i = 0, $len = \strlen($pattern); $i < $len; ++$i) {
            $ch = $pattern[$i];
            if ($ch === '\\' && $i + 1 < $len) {
                $regex .= preg_quote($pattern[++$i], '/');
            } elseif ($ch === '%') {
                $regex .= '.*';
            } elseif ($ch === '_') {
                $regex .= '.';
            } else {
                $regex .= preg_quote($ch, '/');
            }
        }

        return preg_match('/^' . $regex . '$/si', $database) === 1;
    }
}
