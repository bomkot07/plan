<?php

declare(strict_types=1);

namespace Uniundata\Books\Sync;

/**
 * Одна страница выгрузки источника — после валидации.
 *
 * Конструктор не бросает исключение из-за одной плохой записи: такие записи попадают в $rejected
 * (с причиной), а остальные идут в работу. Иначе одна битая цена остановила бы импорт всего каталога.
 * Исключение — нарушение контракта страницы целиком (курсор, флаги).
 *
 * Формат входной записи (один физический экземпляр = один внешний book_id):
 *   [
 *     'external_item_id' => 'B-000123',            // обязательно; ASCII, ≤ 191; = wp_book_items.external_item_id
 *     'source_record_id' => null,                  // по умолчанию = external_item_id (запись на каждый book_id)
 *     'marc_format'      => 'marcxml',             // marcxml | marc_json | iso2709 | mrk
 *     'marc'             => '<record>…</record>',  // ≤ 1 МБ
 *     'status'           => 'present',             // present | withdrawn («снят» источником)
 *     'price_amount'     => 450000,                // int > 0, минимальные единицы (копейки); float/«4500.00» не принимаются
 *     'currency'         => 'RUB',                 // ISO 4217; не равна валюте магазина — экземпляр пропускается
 *     'condition_code'   => 'very_good',           // new | as_new | fine | very_good | good | fair | poor
 *     'condition_note'   => 'Корешок подклеен',    // необязательно
 *     'inventory_number' => 'INV-77',              // необязательно; UNIQUE в магазине
 *     'cover_url'        => 'https://…',           // необязательно, http(s)
 *     'source_url'       => 'https://…',           // необязательно, http(s)
 *   ]
 *
 * @phpstan-type SourceEntry array{external_item_id: string, source_record_id: string, marc_format: string,
 *     marc: string, status: 'present'|'withdrawn', price_amount: int, currency: string, condition_code: string,
 *     condition_note: ?string, inventory_number: ?string, cover_url: ?string, source_url: ?string}
 * @phpstan-type RejectedEntry array{external_item_id: ?string, code: string, message: string}
 */
final class SourceBatch
{
    /** wp_book_sync_runs.source_cursor — VARCHAR(1024). */
    public const MAX_CURSOR_LENGTH = 1024;

    private const FORMATS = ['marcxml', 'marc_json', 'iso2709', 'mrk'];
    private const CONDITIONS = ['new', 'as_new', 'fine', 'very_good', 'good', 'fair', 'poor'];
    private const MAX_PRICE = 4_294_967_295; // INT UNSIGNED

    /** @var list<SourceEntry> */
    public readonly array $entries;

    /** @var list<RejectedEntry> */
    public readonly array $rejected;

    /** Сколько записей прислал источник (валидные + отклонённые) — records_received. */
    public readonly int $receivedCount;

    /**
     * @param list<array<string, mixed>> $rawEntries
     * @param string|null $nextCursor  Курсор следующей страницы; null допустим только при $isLast.
     * @param bool        $isLast      Последняя страница прохода.
     * @param bool        $fullSnapshot Проход отдаёт ВЕСЬ каталог источника (а не только изменения); одинаков
     *                                 у всех страниц прохода. Только после полного прохода помечаются пропавшие.
     * @param int|null    $totalCount  Сколько записей в проходе всего, если источник знает (для прогресса).
     */
    public function __construct(
        array $rawEntries,
        public readonly ?string $nextCursor,
        public readonly bool $isLast,
        public readonly bool $fullSnapshot = true,
        public readonly ?int $totalCount = null,
    ) {
        if ($nextCursor !== null
            && (\strlen($nextCursor) > self::MAX_CURSOR_LENGTH || preg_match('/^[\x20-\x7E]+$/', $nextCursor) !== 1)) {
            throw new \UnexpectedValueException('Source cursor must be printable ASCII, 1..1024 characters');
        }
        if (!$isLast && $nextCursor === null) {
            throw new \UnexpectedValueException('Source returned no cursor for a non-final page');
        }

        $entries = [];
        $rejected = [];
        foreach (array_values($rawEntries) as $i => $raw) {
            try {
                $entries[] = self::normalizeEntry(\is_array($raw) ? $raw : throw new \UnexpectedValueException('Entry must be an object'));
            } catch (\UnexpectedValueException $e) {
                $id = \is_array($raw) && \is_string($raw['external_item_id'] ?? null) && self::isAsciiId($raw['external_item_id'])
                    ? $raw['external_item_id']
                    : null;
                $rejected[] = ['external_item_id' => $id, 'code' => 'invalid_entry', 'message' => \sprintf('#%d: %s', $i, $e->getMessage())];
            }
        }

        $this->entries = $entries;
        $this->rejected = $rejected;
        $this->receivedCount = \count($rawEntries);
    }

    /**
     * @param array<string, mixed> $e
     * @return SourceEntry
     */
    private static function normalizeEntry(array $e): array
    {
        $externalId = $e['external_item_id'] ?? null;
        if (!\is_string($externalId) || !self::isAsciiId($externalId)) {
            throw new \UnexpectedValueException('external_item_id must be printable ASCII, 1..191 characters');
        }
        $recordId = $e['source_record_id'] ?? null;
        if ($recordId === null || $recordId === '') {
            $recordId = $externalId;
        } elseif (!\is_string($recordId) || !self::isAsciiId($recordId)) {
            throw new \UnexpectedValueException('source_record_id must be printable ASCII, 1..191 characters');
        }

        $format = $e['marc_format'] ?? null;
        if (!\is_string($format) || !\in_array($format, self::FORMATS, true)) {
            throw new \UnexpectedValueException('Unsupported marc_format');
        }
        $marc = $e['marc'] ?? null;
        if (!\is_string($marc) || $marc === '' || \strlen($marc) > MarcExtractor::MAX_RAW_BYTES) {
            throw new \UnexpectedValueException('marc must be a non-empty string up to 1 MiB');
        }

        $status = $e['status'] ?? 'present';
        if (!\in_array($status, ['present', 'withdrawn'], true)) {
            throw new \UnexpectedValueException('status must be present|withdrawn');
        }

        $price = $e['price_amount'] ?? null;
        if (\is_string($price) && preg_match('/^\d{1,10}$/', $price) === 1) {
            $price = (int) $price;
        }
        if (!\is_int($price) || $price < 1 || $price > self::MAX_PRICE) {
            // CHECK wp_book_items_chk_price: price_amount > 0 — бесплатных/без цены экземпляров в продаже нет.
            throw new \UnexpectedValueException('price_amount must be a positive integer in minor units');
        }

        $currency = \is_string($e['currency'] ?? null) ? strtoupper($e['currency']) : '';
        if (preg_match('/^[A-Z]{3}$/', $currency) !== 1) {
            throw new \UnexpectedValueException('currency must be ISO 4217');
        }

        $condition = $e['condition_code'] ?? 'good';
        if (!\is_string($condition) || !\in_array($condition, self::CONDITIONS, true)) {
            throw new \UnexpectedValueException('Unknown condition_code');
        }

        $inventory = self::optionalString($e['inventory_number'] ?? null, 64, 'inventory_number');

        return [
            'external_item_id' => $externalId,
            'source_record_id' => $recordId,
            'marc_format' => $format,
            'marc' => $marc,
            'status' => $status,
            'price_amount' => $price,
            'currency' => $currency,
            'condition_code' => $condition,
            'condition_note' => self::optionalString($e['condition_note'] ?? null, 10_000, 'condition_note'),
            'inventory_number' => $inventory,
            // Неверный URL не повод отвергать экземпляр: просто не сохраняем его.
            'cover_url' => self::url($e['cover_url'] ?? null),
            'source_url' => self::url($e['source_url'] ?? null),
        ];
    }

    private static function isAsciiId(string $id): bool
    {
        return preg_match('/^[\x21-\x7E]{1,191}$/', $id) === 1;
    }

    private static function optionalString(mixed $value, int $maxChars, string $name): ?string
    {
        if ($value === null) {
            return null;
        }
        if (!\is_string($value) || !mb_check_encoding($value, 'UTF-8')) {
            throw new \UnexpectedValueException(\sprintf('%s must be a UTF-8 string', $name));
        }
        $value = trim($value);
        if (mb_strlen($value) > $maxChars) {
            throw new \UnexpectedValueException(\sprintf('%s is longer than %d characters', $name, $maxChars));
        }

        return $value === '' ? null : $value;
    }

    private static function url(mixed $value): ?string
    {
        if (!\is_string($value) || $value === '' || \strlen($value) > 2048) {
            return null;
        }
        $value = trim($value);

        return preg_match('~^https?://[\x21-\x7E]+$~i', $value) === 1 && filter_var($value, FILTER_VALIDATE_URL) !== false
            ? $value
            : null;
    }
}
