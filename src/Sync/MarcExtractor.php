<?php

declare(strict_types=1);

namespace Uniundata\Books\Sync;

/**
 * Разбор MARC 21 и извлечение полей витрины/поиска для `wp_book_records` и связанных таблиц.
 *
 * Не зависит от WordPress и БД: чистые функции над массивом, поэтому покрывается PHPUnit без WP.
 *
 * Внутреннее представление записи (одинаковое для MARCXML и MARC-in-JSON):
 *   ['leader' => string(24), 'fields' => list<
 *       array{tag: string, value: string}                                        — контрольное поле 00X
 *     | array{tag: string, ind1: string, ind2: string, subfields: list<array{0: string, 1: string}>}  — поле данных
 *   >]
 *
 * Форматы:
 *   - marcxml   — MARC21 slim (namespace http://www.loc.gov/MARC21/slim или без namespace);
 *   - marc_json — MARC-in-JSON (http://dilettantes.code4lib.org/blog/2010/09/a-proposal-to-serialize-marc-in-json/);
 *   - iso2709 / mrk — конвертируются в MARCXML внешним конвертером (см. __construct), полный парсер ISO 2709
 *     в плагин сознательно не встроен.
 *
 * Правила извлечения — docs/03-tables-and-indexes.md, раздел 4.
 *
 * @phpstan-type MarcField array{tag: string, value?: string, ind1?: string, ind2?: string, subfields?: list<array{0: string, 1: string}>}
 * @phpstan-type MarcRecord array{leader: string, fields: list<MarcField>}
 */
final class MarcExtractor
{
    /** Версия канонизации для checksum. Менять только осознанно: смена версии = полное обновление каталога. */
    public const CANONICAL_VERSION = 'uniundata-marc-c14n-v1';

    public const MARCXML_NS = 'http://www.loc.gov/MARC21/slim';

    /** Предел размера одной записи: ISO 2709 ограничен 99 999 байтами, MARCXML в ~3–5 раз больше. */
    public const MAX_RAW_BYTES = 1_048_576;

    private const UNTITLED = '[Без заглавия]';

    /** Допустимый год публикации (CHECK wp_book_records_chk_year): вне диапазона — NULL, а не ошибка 3819 пакета. */
    public const YEAR_MIN = 1400;
    public const YEAR_MAX = 2100;

    /** Длины колонок схемы (символы для VARCHAR, байты для TEXT). */
    private const LEN = [
        'title' => 1000, 'title_sort' => 255, 'subtitle' => 1000, 'responsibility_statement' => 1000,
        'authors_text' => 1000, 'main_author_sort' => 255, 'publisher' => 500, 'publication_place' => 500,
        'publication_date_text' => 100, 'edition_statement' => 500, 'physical_description' => 1000,
        'series_title' => 1000, 'marc_control_number' => 64, 'marc_control_org' => 32,
        'name_display' => 500, 'name_sort' => 255, 'dates' => 100, 'authority_id' => 255,
        'heading' => 1000, 'heading_sort' => 255, 'url' => 2048, 'raw_value' => 255,
    ];
    /** TEXT = 65 535 байт; с запасом под многобайтовые символы и многоточие. */
    private const TEXT_BYTES = 65_000;

    /** Тезаурус по второму индикатору 6XX (ind2 = 7 → $2, ind2 = 4 → не указан). */
    private const THESAURUS_BY_IND2 = [
        '0' => 'lcsh', '1' => 'lcshac', '2' => 'mesh', '3' => 'nal', '5' => 'cash', '6' => 'rvm',
    ];

    /** $e → relator code (подмножество; неизвестные роли без $4 — 'ctb'). */
    private const RELATOR_BY_TERM = [
        'author' => 'aut', 'автор' => 'aut', 'авт' => 'aut',
        'editor' => 'edt', 'ed' => 'edt', 'редактор' => 'edt', 'ред' => 'edt',
        'translator' => 'trl', 'tr' => 'trl', 'trans' => 'trl', 'переводчик' => 'trl', 'пер' => 'trl',
        'illustrator' => 'ill', 'ill' => 'ill', 'иллюстратор' => 'ill', 'ил' => 'ill', 'худож' => 'ill', 'художник' => 'ill',
        'compiler' => 'com', 'comp' => 'com', 'составитель' => 'com', 'сост' => 'com',
        'commentator' => 'cwt', 'комментатор' => 'cwt', 'коммент' => 'cwt',
        'author of introduction' => 'aui', 'предисл' => 'aui', 'вступ. ст' => 'aui',
        'photographer' => 'pht', 'фотограф' => 'pht',
        'publisher' => 'pbl', 'издатель' => 'pbl',
        'printer' => 'prt', 'печатник' => 'prt', 'типограф' => 'prt',
        'former owner' => 'fmo', 'бывший владелец' => 'fmo',
        'dedicatee' => 'dte', 'адресат посвящения' => 'dte',
        'contributor' => 'ctb',
    ];

    /** Сокращения, после которых завершающая точка — часть слова, а не ISBD-пунктуация. */
    private const ABBREVIATIONS = [
        'ил', 'т', 'с', 'изд', 'испр', 'доп', 'перераб', 'пер', 'ред', 'сост', 'вып', 'кн', 'ч', 'г', 'гг',
        'в', 'вв', 'англ', 'нем', 'фр', 'лат', 'рус', 'обл', 'тип', 'им', 'н', 'э', 'л', 'стб',
        'ed', 'eds', 'ill', 'illus', 'vol', 'vols', 'v', 'p', 'pp', 'jr', 'sr', 'co', 'inc', 'ltd', 'no',
        'etc', 'rev', 'enl', 'col', 'facsim', 'port', 'ports', 'b', 'd', 'ca', 'fl', 'st', 'mr', 'dr',
    ];

    /**
     * @param (\Closure(string $payload, string $format): string)|null $toMarcXml Конвертер ISO 2709 / MRK → MARCXML.
     *        Пример на pear/file_marc (composer `pear/file_marc`):
     *          static function (string $payload, string $format): string {
     *              $marc = new \File_MARC($payload, \File_MARC::SOURCE_STRING);   // ISO 2709
     *              $record = $marc->next() ?: throw new \UnexpectedValueException('Empty ISO 2709 payload');
     *              return $record->toXML('UTF-8', false, true);
     *          }
     *        scriptotek/marc (обёртка над File_MARC): `Record::fromString($payload)` → `->toXML()`.
     *        File_MARC не перекодирует MARC-8 (Leader/09 = ' '): такие выгрузки сначала прогоняются через
     *        `yaz-marcdump -f MARC-8 -t UTF-8 -o marcxml` на стороне SourceClient.
     */
    public function __construct(private readonly ?\Closure $toMarcXml = null)
    {
    }

    // =============================================================================================
    // Вход: формат источника → хранимый формат + внутреннее представление
    // =============================================================================================

    /**
     * @param 'marcxml'|'marc_json'|'iso2709'|'mrk' $sourceFormat
     * @return array{source_format: string, marc21_format: 'marcxml'|'marc_json', marc21_raw: string, record: MarcRecord}
     */
    public function normalizeInput(string $payload, string $sourceFormat): array
    {
        if (\strlen($payload) > self::MAX_RAW_BYTES) {
            throw new \UnexpectedValueException(\sprintf('MARC payload is too large (%d bytes)', \strlen($payload)));
        }

        switch ($sourceFormat) {
            case 'marcxml':
                return ['source_format' => 'marcxml', 'marc21_format' => 'marcxml', 'marc21_raw' => $payload,
                        'record' => $this->parseMarcXml($payload)];
            case 'marc_json':
                return ['source_format' => 'marc_json', 'marc21_format' => 'marc_json', 'marc21_raw' => $payload,
                        'record' => $this->parseMarcJson($payload)];
            case 'iso2709':
            case 'mrk':
                if ($this->toMarcXml === null) {
                    throw new \LogicException(\sprintf('No converter configured for "%s" (see MarcExtractor::__construct)', $sourceFormat));
                }
                $xml = ($this->toMarcXml)($payload, $sourceFormat);
                // Хранится MARCXML: бинарный ISO 2709 неудобен в MEDIUMTEXT и для повторного разбора.
                return ['source_format' => $sourceFormat, 'marc21_format' => 'marcxml', 'marc21_raw' => $xml,
                        'record' => $this->parseMarcXml($xml)];
            default:
                throw new \InvalidArgumentException(\sprintf('Unsupported MARC format "%s"', $sourceFormat));
        }
    }

    /**
     * MARCXML → внутреннее представление. Безопасность: LIBXML_NONET (никаких сетевых загрузок),
     * без LIBXML_NOENT/LIBXML_DTDLOAD (внешние сущности не подставляются), документ с DOCTYPE отвергается
     * целиком (XXE, «billion laughs»), размер ограничен MAX_RAW_BYTES.
     *
     * @return MarcRecord
     */
    public function parseMarcXml(string $xml): array
    {
        if (\strlen($xml) > self::MAX_RAW_BYTES) {
            throw new \UnexpectedValueException('MARCXML is too large');
        }
        // Дешёвая проверка до парсера; окончательная — по $doc->doctype ниже.
        if (preg_match('/<!DOCTYPE|<!ENTITY/i', $xml) === 1) {
            throw new \UnexpectedValueException('MARCXML with DOCTYPE/ENTITY is not accepted');
        }

        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();
        try {
            $doc = new \DOMDocument();
            $doc->resolveExternals = false;
            $doc->substituteEntities = false;
            $ok = $doc->loadXML($xml, LIBXML_NONET | LIBXML_NOCDATA | LIBXML_COMPACT);
            $error = libxml_get_last_error();
            if ($ok === false || $doc->documentElement === null) {
                throw new \UnexpectedValueException('Invalid MARCXML: ' . ($error !== false ? trim($error->message) : 'parse error'));
            }
            if ($doc->doctype !== null) {
                throw new \UnexpectedValueException('MARCXML with DOCTYPE is not accepted');
            }
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        // local-name(): одинаково для записей с namespace MARC21/slim, с префиксом marc: и без namespace.
        $xp = new \DOMXPath($doc);
        $records = $xp->query('//*[local-name()="record"]');
        if ($records === false || $records->length === 0) {
            throw new \UnexpectedValueException('MARCXML contains no <record>');
        }
        if ($records->length > 1) {
            throw new \UnexpectedValueException('MARCXML must contain exactly one <record> per source entry');
        }
        $recordEl = $records->item(0);
        \assert($recordEl instanceof \DOMElement);

        $leader = '';
        $fields = [];
        foreach ($recordEl->childNodes as $node) {
            if (!$node instanceof \DOMElement) {
                continue;
            }
            switch ($node->localName) {
                case 'leader':
                    $leader = $node->textContent;
                    break;
                case 'controlfield':
                    $fields[] = ['tag' => self::tag($node->getAttribute('tag')), 'value' => $node->textContent];
                    break;
                case 'datafield':
                    $subfields = [];
                    foreach ($node->childNodes as $sf) {
                        if ($sf instanceof \DOMElement && $sf->localName === 'subfield') {
                            $subfields[] = [self::code($sf->getAttribute('code')), $sf->textContent];
                        }
                    }
                    $fields[] = [
                        'tag' => self::tag($node->getAttribute('tag')),
                        'ind1' => self::indicator($node->getAttribute('ind1')),
                        'ind2' => self::indicator($node->getAttribute('ind2')),
                        'subfields' => $subfields,
                    ];
                    break;
            }
        }

        return ['leader' => self::leader($leader), 'fields' => $fields];
    }

    /**
     * MARC-in-JSON: {"leader": "...", "fields": [{"001": "..."}, {"245": {"ind1": "1", "ind2": "0",
     * "subfields": [{"a": "..."}, {"c": "..."}]}}]}.
     *
     * @return MarcRecord
     */
    public function parseMarcJson(string $json): array
    {
        if (\strlen($json) > self::MAX_RAW_BYTES) {
            throw new \UnexpectedValueException('MARC-in-JSON is too large');
        }
        try {
            $data = json_decode($json, true, 16, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
        } catch (\JsonException $e) {
            throw new \UnexpectedValueException('Invalid MARC-in-JSON: ' . $e->getMessage(), 0, $e);
        }
        if (!\is_array($data) || !isset($data['fields']) || !\is_array($data['fields'])) {
            throw new \UnexpectedValueException('MARC-in-JSON must have "fields"');
        }

        $fields = [];
        foreach ($data['fields'] as $entry) {
            if (!\is_array($entry) || \count($entry) !== 1) {
                throw new \UnexpectedValueException('MARC-in-JSON field must be an object with one tag');
            }
            $tag = self::tag((string) array_key_first($entry));
            $body = $entry[array_key_first($entry)];
            if (\is_string($body) || \is_int($body)) {
                $fields[] = ['tag' => $tag, 'value' => (string) $body];
                continue;
            }
            if (!\is_array($body) || !isset($body['subfields']) || !\is_array($body['subfields'])) {
                throw new \UnexpectedValueException(\sprintf('MARC-in-JSON field %s has no subfields', $tag));
            }
            $subfields = [];
            foreach ($body['subfields'] as $sf) {
                if (!\is_array($sf) || \count($sf) !== 1) {
                    throw new \UnexpectedValueException(\sprintf('MARC-in-JSON field %s: bad subfield', $tag));
                }
                $code = (string) array_key_first($sf);
                $value = $sf[$code];
                if (!\is_string($value) && !\is_int($value)) {
                    throw new \UnexpectedValueException(\sprintf('MARC-in-JSON field %s: bad subfield value', $tag));
                }
                $subfields[] = [self::code($code), (string) $value];
            }
            $fields[] = [
                'tag' => $tag,
                'ind1' => self::indicator((string) ($body['ind1'] ?? ' ')),
                'ind2' => self::indicator((string) ($body['ind2'] ?? ' ')),
                'subfields' => $subfields,
            ];
        }

        return ['leader' => self::leader(\is_string($data['leader'] ?? null) ? $data['leader'] : ''), 'fields' => $fields];
    }

    // =============================================================================================
    // Канонизация и checksum
    // =============================================================================================

    /**
     * Стабильная сериализация для checksum: не зависит от формата (MARCXML/JSON), порядка атрибутов,
     * пробелов и формы Unicode. Убирается то, что меняется без изменения смысла:
     *   - поле 005 (дата последней правки: часть источников переписывает его при каждой выгрузке);
     *   - Leader/00–04 (длина записи) и Leader/12–16 (базовый адрес) — зависят от сериализации;
     *   - порядок полей стабилизируется сортировкой по тегу (внутри тега — порядок записи);
     *   - строки подполей: NFC, схлопнутые пробелы, trim. Контрольные поля — только NFC (позиции 008 значимы).
     */
    public static function canonicalize(array $record): string
    {
        $leader = self::leader((string) ($record['leader'] ?? ''));
        $leader = '00000' . substr($leader, 5, 7) . '00000' . substr($leader, 17);

        $fields = [];
        foreach ($record['fields'] ?? [] as $i => $f) {
            if ($f['tag'] === '005') {
                continue;
            }
            if (\array_key_exists('value', $f)) {
                $fields[] = [$f['tag'], $i, ['v' => self::nfc((string) $f['value'])]];
                continue;
            }
            $subfields = [];
            foreach ($f['subfields'] ?? [] as [$code, $value]) {
                $subfields[] = [$code, self::squash((string) $value)];
            }
            $fields[] = [$f['tag'], $i, ['i' => ($f['ind1'] ?? ' ') . ($f['ind2'] ?? ' '), 's' => $subfields]];
        }
        // Стабильная сортировка по тегу: при равных тегах сохраняется исходный порядок ($i).
        usort($fields, static fn (array $a, array $b): int => [$a[0], $a[1]] <=> [$b[0], $b[1]]);

        return json_encode(
            ['c14n' => self::CANONICAL_VERSION, 'leader' => $leader,
             'fields' => array_map(static fn (array $f): array => [$f[0], $f[2]], $fields)],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        );
    }

    /**
     * SHA-256 канонизированной записи + данных уровня записи от интеграции (cover_url, source_url):
     * их изменение тоже должно обновить строку wp_book_records.
     *
     * @param MarcRecord                          $record
     * @param array<string, string|int|null>      $extra
     */
    public function checksum(array $record, array $extra = []): string
    {
        ksort($extra);

        return hash('sha256', self::canonicalize($record) . "\n"
            . json_encode($extra, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    // =============================================================================================
    // Извлечение полей
    // =============================================================================================

    /**
     * @param MarcRecord $record
     * @return array{
     *   record: array<string, string|int|null>,
     *   contributors: list<array{contributor_key: string, name_display: string, name_sort: string, entity_type: string,
     *                            dates: ?string, authority_id: ?string, role_code: string, marc_tag: string, position: int}>,
     *   subjects: list<array{subject_key: string, heading: string, heading_sort: string, marc_tag: string,
     *                        thesaurus: ?string, authority_id: ?string, position: int}>,
     *   identifiers: list<array{id_type: string, id_value: string, raw_value: ?string, is_cancelled: int}>
     * }
     */
    public function extract(array $record): array
    {
        $leader = self::leader((string) ($record['leader'] ?? ''));
        $fields = $record['fields'] ?? [];
        $f008 = self::controlValue($fields, '008');

        // --- 245: заглавие ----------------------------------------------------------------------
        $f245 = self::first($fields, '245');
        $title = null;
        $titleSort = '';
        $subtitle = null;
        $responsibility = null;
        if ($f245 !== null) {
            $a = self::sub($f245, 'a');
            $title = $a !== null ? self::clean($a) : null;
            $parts = array_map([self::class, 'clean'], self::subs($f245, ['n', 'p']));
            if ($parts !== []) {
                // «$a. $n, $p»: иначе тома многотомника неотличимы в каталоге.
                $title = ($title !== null && $title !== '' ? $title . '. ' : '') . implode(', ', $parts);
            }
            $subtitle = self::nullIfEmpty(self::clean(implode(' ', self::subs($f245, ['b']))));
            $responsibility = self::nullIfEmpty(self::clean(implode(' ', self::subs($f245, ['c']))));
            $skip = ctype_digit($f245['ind2'] ?? '') ? (int) $f245['ind2'] : 0;
            $titleSort = self::sortKey(($a ?? '') . ($parts !== [] ? ' ' . implode(' ', $parts) : ''), $skip);
        }
        if ($title === null || $title === '') {
            $title = self::UNTITLED;
        }
        if ($titleSort === '') {
            $titleSort = self::sortKey($title);
        }

        // --- 1XX/7XX: персоны и организации ----------------------------------------------------
        $contributors = $this->contributors($fields);
        $authorsText = [];
        foreach ($contributors as $c) {
            // Одна строка на поле 1XX/7XX, даже если у персоны несколько ролей ($4 повторяется).
            $authorsText[$c['position']] ??= $c['name_display'] . ($c['role_label'] !== null ? ' (' . $c['role_label'] . ')' : '');
        }
        $main = self::firstOf($fields, ['100', '110', '111']);
        $mainAuthorSort = $main !== null && ($ma = self::sub($main, 'a')) !== null ? self::nullIfEmpty(self::sortKey($ma)) : null;

        // --- 020/022/010/035/024: идентификаторы ------------------------------------------------
        [$identifiers, $isbnPrimary] = $this->identifiers($fields);

        // --- 264/260: публикация --------------------------------------------------------------
        $pub = self::publicationField($fields);
        $publisher = $pub !== null ? self::nullIfEmpty(implode('; ', array_map([self::class, 'clean'], self::subs($pub, ['b'])))) : null;
        $place = $pub !== null ? self::nullIfEmpty(implode('; ', array_map([self::class, 'clean'], self::subs($pub, ['a'])))) : null;
        $dateText = $pub !== null ? self::sub($pub, 'c') : null;
        if ($dateText === null) {
            foreach (self::all($fields, '264') as $f) {
                if (($f['ind2'] ?? '') === '4' && ($c = self::sub($f, 'c')) !== null) {
                    $dateText = $c; // © — только как источник года, если других дат нет
                    break;
                }
            }
        }
        $dateText = $dateText !== null ? self::nullIfEmpty(rtrim(self::squash($dateText), ' .,;:')) : null;
        $year = self::yearInRange(self::extractYear($dateText)) ?? self::yearFrom008($f008);

        // --- 250, 041/008, 300, 490/830 ---------------------------------------------------------
        $f250 = self::first($fields, '250');
        $edition = $f250 !== null ? self::nullIfEmpty(self::clean(implode(' ', self::subs($f250, ['a', 'b'])))) : null;

        $physical = [];
        foreach (self::all($fields, '300') as $f) {
            $physical[] = self::clean(implode(' ', self::subs($f, ['a', 'b', 'c', 'e'])));
        }

        // --- 6XX: рубрики -------------------------------------------------------------------
        [$subjects, $subjectsText] = $this->subjects($fields);

        // --- 520 / 505 ---------------------------------------------------------------------
        $descriptions = [];
        foreach (self::all($fields, '520') as $f) {
            $descriptions[] = self::clean(implode(' ', self::subs($f, ['a', 'b'])), false);
        }
        $contents = [];
        foreach (self::all($fields, '505') as $f) {
            $a = self::subs($f, ['a']);
            if ($a !== []) {
                $contents[] = self::clean(implode(' ', $a), false);
                continue;
            }
            // Расширенное 505: $g $t $r повторяются; каждое произведение — «$t / $r», через « -- ».
            $piece = '';
            $pieces = [];
            foreach ($f['subfields'] ?? [] as [$code, $value]) {
                if (!\in_array($code, ['g', 't', 'r'], true)) {
                    continue;
                }
                if ($code === 't' && $piece !== '') {
                    $pieces[] = $piece;
                    $piece = '';
                }
                $value = self::clean($value);
                $piece = $piece === '' ? $value : $piece . ($code === 'r' ? ' / ' : ' ') . $value;
            }
            if ($piece !== '') {
                $pieces[] = $piece;
            }
            $contents[] = implode(' -- ', $pieces);
        }

        // --- 856: URL (резерв, если интеграция их не дала) -------------------------------------
        [$coverUrl, $sourceUrl] = self::urlsFrom856($fields);

        $record = [
            'record_type' => self::leaderCode($leader, 6),
            'bib_level' => self::leaderCode($leader, 7),
            'marc_control_number' => self::truncate(self::nullIfEmpty(trim((string) self::controlValue($fields, '001'))), self::LEN['marc_control_number']),
            'marc_control_org' => self::truncate(self::nullIfEmpty(trim((string) self::controlValue($fields, '003'))), self::LEN['marc_control_org']),
            'title' => self::truncate($title, self::LEN['title']),
            'title_sort' => $titleSort,
            'subtitle' => self::truncate($subtitle, self::LEN['subtitle']),
            'responsibility_statement' => self::truncate($responsibility, self::LEN['responsibility_statement']),
            'authors_text' => self::truncate(self::nullIfEmpty(implode('; ', $authorsText)), self::LEN['authors_text']),
            'main_author_sort' => $mainAuthorSort,
            'isbn_primary' => $isbnPrimary,
            'publisher' => self::truncate($publisher, self::LEN['publisher']),
            'publication_place' => self::truncate($place, self::LEN['publication_place']),
            'publication_year' => $year,
            'publication_date_text' => self::truncate($dateText, self::LEN['publication_date_text']),
            'edition_statement' => self::truncate($edition, self::LEN['edition_statement']),
            'language_code' => self::language($fields, $f008),
            'physical_description' => self::truncate(self::nullIfEmpty(implode('; ', array_filter($physical))), self::LEN['physical_description']),
            'series_title' => self::truncate(self::series($fields), self::LEN['series_title']),
            'subjects_text' => self::truncateBytes(self::nullIfEmpty($subjectsText)),
            'description' => self::truncateBytes(self::nullIfEmpty(implode("\n\n", array_filter($descriptions)))),
            'contents_note' => self::truncateBytes(self::nullIfEmpty(implode(' -- ', array_filter($contents)))),
            'cover_url' => $coverUrl,
            'source_url' => $sourceUrl,
        ];

        return [
            'record' => $record,
            'contributors' => array_map(static function (array $c): array {
                unset($c['role_label']);

                return $c;
            }, $contributors),
            'subjects' => $subjects,
            'identifiers' => $identifiers,
        ];
    }

    // =============================================================================================
    // Нормализация (публичные: покрываются unit-тестами)
    // =============================================================================================

    /**
     * 020$a → ISBN-13 или null при неверной контрольной цифре.
     * '0-306-40615-2' → '9780306406157'; '080442957X' → '9780804429573'; '9780306406158' → null.
     */
    public static function normalizeIsbn(string $raw): ?string
    {
        // Уточнения в скобках («(в пер.)») старая практика писала прямо в $a.
        $s = strtoupper((string) preg_replace('/[^0-9X]/i', '', (string) preg_replace('/\(.*?\)/u', '', $raw)));
        if (preg_match('/^\d{9}[\dX]$/', $s) === 1) {
            $sum = 0;
            for ($i = 0; $i < 10; ++$i) {
                $sum += (10 - $i) * ($s[$i] === 'X' ? 10 : (int) $s[$i]);
            }
            if ($sum % 11 !== 0) {
                return null;
            }
            $core = '978' . substr($s, 0, 9);
        } elseif (preg_match('/^97[89]\d{10}$/', $s) === 1) {
            $core = substr($s, 0, 12);
        } else {
            return null;
        }
        $sum = 0;
        for ($i = 0; $i < 12; ++$i) {
            $sum += (int) $core[$i] * ($i % 2 === 0 ? 1 : 3);
        }
        $isbn13 = $core . (string) ((10 - $sum % 10) % 10);

        return \strlen($s) === 13 && $isbn13 !== $s ? null : $isbn13;
    }

    /** ISSN: 8 символов, контрольная цифра mod 11. '0317-8471' → '03178471'. */
    public static function normalizeIssn(string $raw): ?string
    {
        $s = strtoupper((string) preg_replace('/[^0-9X]/i', '', $raw));
        if (preg_match('/^\d{7}[\dX]$/', $s) !== 1) {
            return null;
        }
        $sum = 0;
        for ($i = 0; $i < 7; ++$i) {
            $sum += (8 - $i) * (int) $s[$i];
        }
        $check = (11 - $sum % 11) % 11;

        return ($check === 10 ? 'X' : (string) $check) === $s[7] ? $s : null;
    }

    /**
     * Год из 264$c / 260$c: '[1905?]' → 1905, 'c1999' → 1999, '1890-1895' → 1890, 'MDCCCXII' → null,
     * '1350' / '2150' → null (вне YEAR_MIN..YEAR_MAX). `\b` не годится: в 'c1999' между 'c' и '1' нет границы слова.
     */
    public static function extractYear(?string $dateText): ?int
    {
        return $dateText !== null && preg_match('/(?<!\d)(1[4-9]\d{2}|20\d{2}|2100)(?!\d)/', $dateText, $m) === 1
            ? self::yearInRange((int) $m[1])
            : null;
    }

    /** Год вне YEAR_MIN..YEAR_MAX → NULL: CHECK wp_book_records_chk_year иначе сорвал бы запись (3819). */
    public static function yearInRange(?int $year): ?int
    {
        return $year !== null && $year >= self::YEAR_MIN && $year <= self::YEAR_MAX ? $year : null;
    }

    /**
     * Ключ сортировки: пропустить $skip незначащих символов (245 ind2), вырезать фрагменты NSB…NSE,
     * NFC → нижний регистр → всё, кроме букв и цифр, в пробел → схлопнуть → первые 255 символов.
     */
    public static function sortKey(string $value, int $skip = 0): string
    {
        $value = self::nfc($value);
        // NSB/NSE: U+0088/U+0089 (из MARC-8) и U+0098…U+009C (SOS…ST), которыми часть каталогов
        // помечает незначащие артикли вместо ind2.
        $value = (string) preg_replace(['/\x{0088}.*?\x{0089}/su', '/\x{0098}.*?\x{009C}/su'], '', $value);
        $value = (string) preg_replace('/[\x{0080}-\x{009F}]/u', '', $value);
        if ($skip > 0 && $skip <= 9) {
            $value = mb_substr($value, $skip);
        }
        $value = mb_strtolower($value, 'UTF-8');
        $value = trim((string) preg_replace('/[^\p{L}\p{N}]+/u', ' ', $value));

        return mb_substr($value, 0, 255);
    }

    // =============================================================================================
    // Внутреннее: поля
    // =============================================================================================

    /**
     * @param list<MarcField> $fields
     * @return list<array{contributor_key: string, name_display: string, name_sort: string, entity_type: string,
     *                    dates: ?string, authority_id: ?string, role_code: string, marc_tag: string, position: int,
     *                    role_label: ?string}>
     */
    private function contributors(array $fields): array
    {
        $out = [];
        $seen = [];
        $position = 0;
        foreach ($fields as $f) {
            $tag = $f['tag'];
            if (!\in_array($tag, ['100', '110', '111', '700', '710', '711'], true) || !isset($f['subfields'])) {
                continue;
            }
            if (self::sub($f, 't') !== null) {
                continue; // имя/заглавие (аналитика, связанные произведения) — не участник издания
            }
            $kind = substr($tag, 1); // 00 | 10 | 11
            $nameCodes = match ($kind) {
                '00' => ['a', 'b', 'c', 'q', 'd'],
                '10' => ['a', 'b'],
                default => ['a', 'n', 'd', 'c'],
            };
            $display = self::clean(implode(' ', self::subs($f, $nameCodes)));
            $a = self::sub($f, 'a');
            if ($display === '' || $a === null) {
                continue;
            }
            $dates = self::nullIfEmpty(self::clean((string) self::sub($f, 'd')));
            $authority = self::authorityId($f);

            $roleLabel = self::nullIfEmpty(self::clean(implode(', ', self::subs($f, ['e']))));
            $roles = self::roleCodes($f, $tag);
            $entity = match ($kind) {
                '00' => 'person',
                '10' => 'corporate',
                default => 'meeting',
            };
            $key = hash('sha256', $authority !== null
                ? 'auth:' . mb_strtolower($authority)
                : 'name:' . $entity . ':' . self::sortKey($a) . '|' . self::sortKey((string) $dates));

            foreach ($roles as $role) {
                if (isset($seen[$key . '|' . $role])) {
                    continue; // PK (record, contributor, role)
                }
                $seen[$key . '|' . $role] = true;
                $out[] = [
                    'contributor_key' => $key,
                    'name_display' => (string) self::truncate($display, self::LEN['name_display']),
                    // Для указателя A–Я — без дат ($d): даты различают однофамильцев только в ключе.
                    'name_sort' => self::sortKey(implode(' ', self::subs($f, array_values(array_diff($nameCodes, ['d']))))) ?: self::sortKey($a),
                    'entity_type' => $entity,
                    'dates' => self::truncate($dates, self::LEN['dates']),
                    'authority_id' => self::truncate($authority, self::LEN['authority_id']),
                    'role_code' => $role,
                    'marc_tag' => $tag,
                    'position' => $position,
                    'role_label' => $role === $roles[0] ? $roleLabel : null,
                ];
            }
            ++$position;
        }

        return $out;
    }

    /** @return list<string> */
    private static function roleCodes(array $f, string $tag): array
    {
        $codes = [];
        foreach (self::subs($f, ['4']) as $v) {
            // $4 бывает URI: http://id.loc.gov/vocabulary/relators/aut
            $v = strtolower(trim((string) preg_replace('~^.*/~', '', trim($v))));
            if (preg_match('/^[a-z]{3}$/', $v) === 1) {
                $codes[] = $v;
            }
        }
        if ($codes === []) {
            foreach (self::subs($f, ['e', 'j']) as $term) {
                $term = mb_strtolower(trim(self::clean($term), " .,;:"), 'UTF-8');
                if (isset(self::RELATOR_BY_TERM[$term])) {
                    $codes[] = self::RELATOR_BY_TERM[$term];
                }
            }
        }
        if ($codes === []) {
            $codes[] = $tag[0] === '1' ? 'aut' : 'ctb';
        }

        return array_values(array_unique($codes));
    }

    /**
     * @param list<MarcField> $fields
     * @return array{0: list<array{subject_key: string, heading: string, heading_sort: string, marc_tag: string,
     *                              thesaurus: ?string, authority_id: ?string, position: int}>, 1: string}
     */
    private function subjects(array $fields): array
    {
        $subjects = [];
        $texts = [];
        $position = 0;
        foreach ($fields as $f) {
            $tag = $f['tag'];
            if ($tag === '653' && isset($f['subfields'])) {
                foreach (self::subs($f, ['a']) as $kw) {
                    $kw = self::clean($kw);
                    if ($kw !== '') {
                        $texts[mb_strtolower($kw)] = $kw; // неконтролируемые ключевые слова — только в текст
                    }
                }
                continue;
            }
            if (!\in_array($tag, ['600', '610', '611', '630', '648', '650', '651', '655'], true) || !isset($f['subfields'])) {
                continue;
            }
            $mainCodes = match ($tag) {
                '600' => ['a', 'b', 'c', 'q', 'd', 't'],
                '610' => ['a', 'b', 't'],
                '611' => ['a', 'n', 'd', 'c', 't'],
                '630' => ['a', 'n', 'p'],
                '650' => ['a', 'b'],
                default => ['a'],
            };
            $main = self::clean(implode(' ', self::subs($f, $mainCodes)));
            if ($main === '') {
                continue;
            }
            $parts = [$main];
            foreach ($f['subfields'] as [$code, $value]) {
                if (\in_array($code, ['v', 'x', 'y', 'z'], true) && ($v = self::clean($value)) !== '') {
                    $parts[] = $v;
                }
            }
            $heading = (string) self::truncate(implode(' -- ', $parts), self::LEN['heading']);

            $ind2 = $f['ind2'] ?? ' ';
            $thesaurus = self::THESAURUS_BY_IND2[$ind2] ?? null;
            if ($ind2 === '7') {
                $src = strtolower(trim((string) self::sub($f, '2')));
                $thesaurus = preg_match('/^[a-z0-9._-]{1,32}$/', $src) === 1 ? $src : null;
            }
            $sort = self::sortKey($heading);
            $key = hash('sha256', ($thesaurus ?? '-') . '|' . $sort);
            $texts[mb_strtolower($heading)] = $heading;
            if (isset($subjects[$key])) {
                continue;
            }
            $subjects[$key] = [
                'subject_key' => $key,
                'heading' => $heading,
                'heading_sort' => $sort,
                'marc_tag' => $tag,
                'thesaurus' => $thesaurus,
                'authority_id' => self::truncate(self::authorityId($f), self::LEN['authority_id']),
                'position' => $position++,
            ];
        }

        return [array_values($subjects), implode('; ', $texts)];
    }

    /**
     * @param list<MarcField> $fields
     * @return array{0: list<array{id_type: string, id_value: string, raw_value: ?string, is_cancelled: int}>, 1: ?string}
     */
    private function identifiers(array $fields): array
    {
        $ids = [];
        $isbnPrimary = null;
        $add = static function (string $type, ?string $value, ?string $raw, bool $cancelled) use (&$ids): void {
            if ($value === null || $value === '' || mb_strlen($value) > 64 || preg_match('/^[^\p{C}\s]+$/u', $value) !== 1) {
                return; // id_value — VARCHAR(64) utf8mb4_bin: длиннее, с пробелами или управляющими символами — не идентификатор
            }
            $k = $type . '|' . $value;
            if (isset($ids[$k])) {
                return;
            }
            $ids[$k] = [
                'id_type' => $type,
                'id_value' => $value,
                'raw_value' => self::truncate($raw !== null ? self::squash($raw) : null, self::LEN['raw_value']),
                'is_cancelled' => $cancelled ? 1 : 0,
            ];
        };

        foreach ($fields as $f) {
            if (!isset($f['subfields'])) {
                continue;
            }
            switch ($f['tag']) {
                case '020':
                    $q = self::subs($f, ['q']);
                    foreach ($f['subfields'] as [$code, $value]) {
                        if ($code !== 'a' && $code !== 'z') {
                            continue;
                        }
                        $raw = trim($value . ($q !== [] ? ' (' . implode('; ', $q) . ')' : ''));
                        $isbn = self::normalizeIsbn($value);
                        if ($code === 'a' && $isbn !== null) {
                            $isbnPrimary ??= $isbn; // первый ВАЛИДНЫЙ $a
                        }
                        // Невалидный номер сохраняем «как есть» (только цифры и X): по нему тоже ищут.
                        $add('isbn', $isbn ?? self::digitsX($value), $raw, $code === 'z');
                    }
                    break;
                case '022':
                    foreach ($f['subfields'] as [$code, $value]) {
                        if ($code === 'a' || $code === 'z' || $code === 'y') {
                            $add('issn', self::normalizeIssn($value) ?? self::digitsX($value), $value, $code !== 'a');
                        }
                    }
                    break;
                case '010':
                    foreach (self::subs($f, ['a']) as $value) {
                        $add('lccn', self::nullIfEmpty((string) preg_replace('/\s+/', '', $value)), $value, false);
                    }
                    break;
                case '035':
                    foreach (self::subs($f, ['a']) as $value) {
                        if (preg_match('/^\(OCoLC\)\s*(?:ocm|ocn|on)?0*(\d+)$/i', trim($value), $m) === 1) {
                            $add('oclc', $m[1], $value, false);
                        } else {
                            $add('other', self::nullIfEmpty((string) preg_replace('/\s+/', '', $value)), $value, false);
                        }
                    }
                    break;
                case '024':
                    $type = match ($f['ind1'] ?? ' ') {
                        '3' => 'ean',
                        '2' => 'ismn',
                        default => 'other',
                    };
                    foreach ($f['subfields'] as [$code, $value]) {
                        if ($code === 'a' || $code === 'z') {
                            $add($type, self::nullIfEmpty((string) preg_replace('/[\s-]+/', '', $value)), $value, $code === 'z');
                        }
                    }
                    break;
            }
        }

        return [array_values($ids), $isbnPrimary];
    }

    /**
     * Приоритет: 264 ind2 = 1 (публикация) → 260 → 264 ind2 = 0/2/3. 264 ind2 = 4 — только год.
     *
     * @param list<MarcField> $fields
     * @return MarcField|null
     */
    private static function publicationField(array $fields): ?array
    {
        $f264 = self::all($fields, '264');
        foreach ($f264 as $f) {
            if (($f['ind2'] ?? '') === '1') {
                return $f;
            }
        }
        $f260 = self::first($fields, '260');
        if ($f260 !== null) {
            return $f260;
        }
        foreach ($f264 as $f) {
            if (\in_array($f['ind2'] ?? '', ['0', '2', '3'], true)) {
                return $f;
            }
        }

        return null;
    }

    /** 008/07–10 (Date1), если 008/06 не b/n/| и Date1 — четыре цифры в диапазоне YEAR_MIN..YEAR_MAX. */
    private static function yearFrom008(?string $f008): ?int
    {
        if ($f008 === null || \strlen($f008) < 11 || \in_array($f008[6], ['b', 'n', '|'], true)) {
            return null;
        }
        $date1 = substr($f008, 7, 4);
        if (preg_match('/^\d{4}$/', $date1) !== 1) {
            return null; // 19uu, '    ' и т. п.
        }
        return self::yearInRange((int) $date1);
    }

    /** 041$a (первые 3 символа), при ind2 = 7 (не коды MARC) или без 041 — 008/35–37. */
    private static function language(array $fields, ?string $f008): ?string
    {
        $f041 = self::first($fields, '041');
        if ($f041 !== null && ($f041['ind2'] ?? ' ') !== '7') {
            $a = self::sub($f041, 'a');
            if ($a !== null) {
                $code = strtolower(substr(trim($a), 0, 3));
                if (preg_match('/^[a-z]{3}$/', $code) === 1) {
                    return $code;
                }
            }
        }
        if ($f008 !== null && \strlen($f008) >= 38) {
            $code = strtolower(substr($f008, 35, 3));
            if (preg_match('/^[a-z]{3}$/', $code) === 1) {
                return $code;
            }
        }

        return null;
    }

    /** 490 $a ; $v (как на издании), иначе 830 $a $n $p ; $v (авторитетная форма). */
    private static function series(array $fields): ?string
    {
        $out = [];
        foreach (['490', '830'] as $tag) {
            foreach (self::all($fields, $tag) as $f) {
                $codes = $tag === '490' ? ['a'] : ['a', 'n', 'p'];
                $name = self::clean(implode(' ', self::subs($f, $codes)));
                if ($name === '') {
                    continue;
                }
                $v = self::clean(implode(' ', self::subs($f, ['v'])));
                $out[] = $v !== '' ? $name . ' ; ' . $v : $name;
            }
            if ($out !== []) {
                break;
            }
        }

        return self::nullIfEmpty(implode('; ', $out));
    }

    /** @return array{0: ?string, 1: ?string} [cover_url, source_url] из 856 $u */
    private static function urlsFrom856(array $fields): array
    {
        $cover = null;
        $source = null;
        foreach (self::all($fields, '856') as $f) {
            $u = self::sub($f, 'u');
            if ($u === null || !self::isHttpUrl($u = trim($u))) {
                continue;
            }
            $label = mb_strtolower(implode(' ', self::subs($f, ['3', 'y', 'z', 'q'])));
            $isImage = preg_match('/cover|обложк|image\/|\.(jpe?g|png|webp|gif)(\?|$)/iu', $label . ' ' . $u) === 1;
            if ($isImage) {
                $cover ??= $u;
            } elseif (\in_array($f['ind2'] ?? ' ', ['0', '1', ' '], true)) {
                $source ??= $u;
            }
        }

        return [$cover, $source];
    }

    private static function authorityId(array $f): ?string
    {
        foreach (self::subs($f, ['0', '1']) as $v) {
            $v = trim($v);
            if ($v !== '') {
                return $v;
            }
        }

        return null;
    }

    // =============================================================================================
    // Внутреннее: строки
    // =============================================================================================

    /**
     * Подполе → строка витрины: NFC, схлопнутые пробелы, без завершающей ISBD-пунктуации
     * (« /», « :», « ;», « =», «,») и финальной точки, если она не часть сокращения («ил.», «Т. 1.»).
     */
    public static function clean(string $value, bool $stripFinalPeriod = true): string
    {
        $value = self::squash($value);
        do {
            $before = $value;
            $value = (string) preg_replace('/\s*(?:[\/:;=,]+|--)$/u', '', $value);
            $value = rtrim($value);
        } while ($value !== $before);

        if ($stripFinalPeriod && str_ends_with($value, '.') && !str_ends_with($value, '..')) {
            $lastWord = preg_match('/(?:^|[\s(\[])([\p{L}]+)\.$/u', $value, $m) === 1 ? mb_strtolower($m[1]) : null;
            $isInitialOrAbbr = $lastWord !== null
                && (mb_strlen($lastWord) === 1 || \in_array($lastWord, self::ABBREVIATIONS, true));
            if (!$isInitialOrAbbr) {
                $value = rtrim(substr($value, 0, -1));
            }
        }

        return $value;
    }

    private static function squash(string $value): string
    {
        $value = self::nfc($value);
        // Управляющие символы C0/C1 (кроме NSB/NSE, их вырезает sortKey) и пробелы → один пробел.
        $value = (string) preg_replace('/[\x{0000}-\x{001F}\x{007F}]+/u', ' ', $value);

        return trim((string) preg_replace('/\s+/u', ' ', $value));
    }

    private static function nfc(string $value): string
    {
        if (!mb_check_encoding($value, 'UTF-8')) {
            $value = mb_convert_encoding($value, 'UTF-8', 'UTF-8'); // невалидные байты → '?'
        }
        if (class_exists(\Normalizer::class)) {
            $n = \Normalizer::normalize($value, \Normalizer::FORM_C);
            if (\is_string($n)) {
                return $n;
            }
        }

        return $value;
    }

    private static function truncate(?string $value, int $chars): ?string
    {
        if ($value === null) {
            return null;
        }

        return mb_strlen($value) > $chars ? rtrim(mb_substr($value, 0, $chars - 1)) . '…' : $value;
    }

    private static function truncateBytes(?string $value): ?string
    {
        if ($value === null || \strlen($value) <= self::TEXT_BYTES) {
            return $value;
        }

        return mb_strcut($value, 0, self::TEXT_BYTES - 3, 'UTF-8') . '…';
    }

    private static function nullIfEmpty(?string $value): ?string
    {
        return $value === null || trim($value) === '' ? null : $value;
    }

    private static function digitsX(string $value): ?string
    {
        return self::nullIfEmpty(strtoupper((string) preg_replace('/[^0-9X]/i', '', (string) preg_replace('/\(.*?\)/u', '', $value))));
    }

    private static function isHttpUrl(string $url): bool
    {
        return \strlen($url) <= self::LEN['url']
            && preg_match('~^https?://[\x21-\x7E]+$~i', $url) === 1
            && filter_var($url, FILTER_VALIDATE_URL) !== false;
    }

    // =============================================================================================
    // Внутреннее: доступ к полям
    // =============================================================================================

    private static function leader(string $leader): string
    {
        return substr(str_pad($leader, 24), 0, 24);
    }

    private static function leaderCode(string $leader, int $pos): ?string
    {
        $c = strtolower($leader[$pos] ?? ' ');

        return preg_match('/^[a-z]$/', $c) === 1 ? $c : null;
    }

    private static function tag(string $tag): string
    {
        $tag = trim($tag);
        if (preg_match('/^[0-9A-Za-z]{3}$/', $tag) !== 1) {
            throw new \UnexpectedValueException(\sprintf('Invalid MARC tag "%s"', substr($tag, 0, 10)));
        }

        return strtoupper($tag);
    }

    private static function indicator(string $ind): string
    {
        $ind = $ind === '' ? ' ' : $ind;
        if (\strlen($ind) !== 1) {
            throw new \UnexpectedValueException('Invalid MARC indicator');
        }

        return $ind === '#' ? ' ' : $ind;
    }

    private static function code(string $code): string
    {
        if (preg_match('/^[0-9a-z]$/i', $code) !== 1) {
            throw new \UnexpectedValueException(\sprintf('Invalid MARC subfield code "%s"', substr($code, 0, 5)));
        }

        return strtolower($code);
    }

    /** @return list<MarcField> */
    private static function all(array $fields, string $tag): array
    {
        return array_values(array_filter($fields, static fn (array $f): bool => $f['tag'] === $tag && isset($f['subfields'])));
    }

    private static function first(array $fields, string $tag): ?array
    {
        return self::all($fields, $tag)[0] ?? null;
    }

    /** @param list<string> $tags */
    private static function firstOf(array $fields, array $tags): ?array
    {
        foreach ($fields as $f) {
            if (\in_array($f['tag'], $tags, true) && isset($f['subfields'])) {
                return $f;
            }
        }

        return null;
    }

    private static function controlValue(array $fields, string $tag): ?string
    {
        foreach ($fields as $f) {
            if ($f['tag'] === $tag && \array_key_exists('value', $f)) {
                return (string) $f['value'];
            }
        }

        return null;
    }

    private static function sub(array $field, string $code): ?string
    {
        foreach ($field['subfields'] ?? [] as [$c, $v]) {
            if ($c === $code) {
                return $v;
            }
        }

        return null;
    }

    /**
     * Значения подполей с кодами из $codes в порядке записи.
     *
     * @param list<string> $codes
     * @return list<string>
     */
    private static function subs(array $field, array $codes): array
    {
        $out = [];
        foreach ($field['subfields'] ?? [] as [$c, $v]) {
            if (\in_array($c, $codes, true) && trim($v) !== '') {
                $out[] = $v;
            }
        }

        return $out;
    }
}
