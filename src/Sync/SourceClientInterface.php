<?php

declare(strict_types=1);

namespace Uniundata\Books\Sync;

/**
 * Клиент внешнего источника каталога (HTTP API, выгрузка OAI-PMH, файл на SFTP…).
 *
 * Реализация регистрируется фильтром `uniundata_sync_sources` (см. Plugin) и знает только транспорт:
 * никаких SQL и бизнес-правил. Секреты (токены, пароли) — из констант wp-config.php или переменных
 * окружения, не из wp_options.
 *
 * Требования к реализации:
 *  - вызывается ТОЛЬКО вне транзакций БД: SyncService никогда не держит блокировки во время HTTP;
 *  - пагинация курсором: fetchBatch(null) — начало прохода; дальше — nextCursor предыдущей страницы.
 *    Курсор непрозрачен для плагина, но обязан быть непустой печатной ASCII-строкой ≤ 1024 символов
 *    (SourceBatch::MAX_CURSOR_LENGTH): он хранится в wp_book_sync_runs.source_cursor и переживает падение
 *    процесса (пустая строка там зарезервирована за «источник прочитан полностью»);
 *  - порядок стабилен в пределах прохода (сортировка по внешнему ID / снимок выгрузки): после resume с
 *    курсора источник отдаёт ровно оставшиеся записи. Если источник так не умеет — курсор = номер
 *    страницы снимка, а снимок источник держит ≥ 48 ч;
 *  - isLast = true — последняя страница прохода. Проход «пропавших» (sync_missing) выполняется только
 *    после полного набора: инкрементальные выгрузки («изменённые с даты») обязаны отдавать
 *    fullSnapshot = false на КАЖДОЙ странице (см. SourceBatch) — иначе всё, что не изменилось, было бы
 *    помечено пропавшим;
 *  - currency каждой записи — валюта магазина (option uniundata_currency); экземпляры в другой валюте
 *    SyncService пропускает с ошибкой currency_mismatch в журнале прогона;
 *  - сетевые ошибки и 5xx — \RuntimeException: SyncService повторит запрос (3 попытки с паузой), затем
 *    переведёт прогон в failed с сохранённым курсором, и следующий запуск продолжит с него.
 */
interface SourceClientInterface
{
    /** Имя источника = wp_book_items.source_name / wp_book_records.source_name. Формат: SyncService::SOURCE_NAME_PATTERN. */
    public function name(): string;

    /**
     * Следующая страница выгрузки.
     *
     * @param string|null $cursor null — начать полный проход с начала.
     * @param int         $limit  Желаемый размер страницы (SyncService::BATCH_SIZE = 200); источник может
     *                            вернуть меньше, но не больше.
     *
     * @throws \RuntimeException Временная ошибка источника (сеть, таймаут, 5xx, 429).
     */
    public function fetchBatch(?string $cursor, int $limit): SourceBatch;
}
