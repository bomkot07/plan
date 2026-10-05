-- =====================================================================
-- Uniundata Books — примеры SQL-запросов (MySQL 8.0.16+, схема v2 sql/schema.sql)
--
-- Плейсхолдеры — пользовательские переменные @…: файл выполняется целиком в mysql-клиенте
-- (`mysql --default-character-set=utf8mb4 <БД> < sql/queries.sql`: без флага клиент работает в latin1
-- и кириллица в литералах ломается).
-- В PHP те же запросы идут через $wpdb->prepare() (%d / %s), префикс `wp_` → $wpdb->prefix,
-- IN-списки строятся Db::placeholders(); пользовательский ввод в текст SQL не попадает никогда.
--
-- Все запросы выполнены на MySQL 8.0.46 на тестовых данных (6 000 записей и экземпляров, 3 000 персон,
-- 3 200 резервов, 120 заказов, 100 продаж, 2 возврата, 200 согласий, 31 прогон синхронизации). Под ключевым запросом —
-- фактический план EXPLAIN (key / type / Extra). При других объёмах оптимизатор может выбрать другой
-- план — проверяйте EXPLAIN ANALYZE на продакшен-статистике.
--
-- SET NAMES с collation схемы: тогда у @-переменных та же collation, что у колонок, и сравнение
-- `title_sort >= @after_title_sort` не падает с 1267 «Illegal mix of collations» (WordPress задаёт
-- utf8mb4_unicode_520_ci сам; литералы из prepare() имеют низший приоритет coercibility и конфликта не дают).
-- =====================================================================

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_520_ci;
SET time_zone = '+00:00';


-- =====================================================================
-- 1. Каталог: фильтры и keyset-пагинация
-- =====================================================================
-- Keyset («после последней показанной строки») вместо OFFSET: страница 500 стоит столько же, сколько
-- первая, и вставки/продажи между запросами не сдвигают страницы. Ключ сортировки всегда дополняется
-- id — он уникален, порядок детерминирован. Курсор (значения последней строки) клиент получает в ответе
-- и передаёт обратно (подписанным/base64 — чтобы не подделывали тип).

-- 1.1. Записи по алфавиту (первая страница: @after_title_sort = '', @after_id = 0).
-- Форма `ключ >= X AND (ключ > X OR id > Y)` даёт range по индексу; кортеж `(a, b) > (x, y)` MySQL
-- оптимизирует хуже.
-- EXPLAIN: r range ix_records_active_title (is_active, title_sort [+ id из PK]); Using index condition;
--          без filesort — порядок уже есть в индексе.
SET @after_title_sort = 'война и мир 120', @after_id = 0, @page_size = 24;
SELECT r.id, r.title, r.subtitle, r.authors_text, r.publication_year, r.cover_url, r.title_sort
  FROM wp_book_records r
 WHERE r.is_active = 1
   AND r.title_sort >= @after_title_sort
   AND (r.title_sort > @after_title_sort OR r.id > @after_id)
 ORDER BY r.title_sort, r.id
 LIMIT 24;

-- 1.2. Экземпляры «в продаже» по возрастанию цены с диапазоном цены (минимальные единицы валюты — копейки),
--      keyset по (price_amount, id).
-- EXPLAIN: i range ix_items_catalog (is_active, availability_status, price_amount); Using index condition;
--          r eq_ref PRIMARY; без filesort.
SET @price_min = 1000, @price_max = 5000, @after_price = 0, @after_id = 0;
SELECT i.id AS book_item_id, i.price_amount, i.currency, i.condition_code,
       r.id AS book_record_id, r.title, r.authors_text, r.publication_year, COALESCE(i.cover_url, r.cover_url) AS cover_url
  FROM wp_book_items i
  JOIN wp_book_records r ON r.id = i.book_record_id AND r.is_active = 1
 WHERE i.is_active = 1
   AND i.availability_status = 'available'
   AND i.price_amount BETWEEN GREATEST(@price_min, @after_price) AND @price_max
   AND (i.price_amount > @after_price OR i.id > @after_id)
 ORDER BY i.price_amount, i.id
 LIMIT 24;

-- 1.3. Фильтр по годам + язык + «есть в продаже», сортировка по году (keyset по (publication_year, id)).
-- Условие на язык добавляется в PHP, только если фильтр выбран (не `@lang IS NULL OR …`: такой
-- предикат оптимизатор не умеет отбросить и теряет индекс).
-- EXPLAIN: r range ix_records_active_year; i ref ix_items_record_status (book_record_id, availability_status)
--          — полу-соединение EXISTS (FirstMatch / LooseScan).
SET @year_from = 1850, @year_to = 1900, @lang = 'rus', @after_year = 0, @after_id = 0;
SELECT r.id, r.title, r.authors_text, r.publication_year, r.language_code
  FROM wp_book_records r
 WHERE r.is_active = 1
   AND r.publication_year BETWEEN GREATEST(@year_from, @after_year) AND @year_to
   AND (r.publication_year > @after_year OR r.id > @after_id)
   AND r.language_code = @lang
   AND EXISTS (SELECT 1 FROM wp_book_items i
                WHERE i.book_record_id = r.id AND i.availability_status = 'available' AND i.is_active = 1)
 ORDER BY r.publication_year, r.id
 LIMIT 24;

-- 1.4. Фасет «язык» со счётчиками записей, у которых есть экземпляр в продаже (кэшируется на 5 минут).
-- EXPLAIN: r ref ix_records_active_title (is_active = 1); Using temporary; Using filesort (группировка);
--          EXISTS материализован (<subquery2>, i ref ix_items_catalog). Это проход по всем активным
--          записям: на каталоге в сотни тысяч записей — кэшировать или материализовать раз в N минут.
SELECT r.language_code, COUNT(*) AS records
  FROM wp_book_records r
 WHERE r.is_active = 1
   AND EXISTS (SELECT 1 FROM wp_book_items i
                WHERE i.book_record_id = r.id AND i.availability_status = 'available' AND i.is_active = 1)
 GROUP BY r.language_code
 ORDER BY records DESC;

-- 1.5. Карточка книги: запись + её экземпляры с публичным статусом кнопки.
-- Публично статусы укрупнены (не раскрываем blocked / sync_missing): available → «Отложить»,
-- reserved/checkout_pending → «Зарезервирована», sold → «Продано», остальное → «Нет в продаже».
-- EXPLAIN: r const PRIMARY; i ref ix_items_record_status.
SET @record_id = 2001;
SELECT r.id, r.title, r.subtitle, r.responsibility_statement, r.authors_text, r.publisher, r.publication_place,
       r.publication_year, r.edition_statement, r.physical_description, r.series_title, r.isbn_primary,
       r.language_code, r.subjects_text, r.description, r.contents_note, r.cover_url
  FROM wp_book_records r
 WHERE r.id = @record_id AND r.is_active = 1;

SELECT i.id AS book_item_id, i.price_amount, i.currency, i.condition_code, i.condition_note,
       CASE i.availability_status
            WHEN 'available' THEN 'available'
            WHEN 'reserved' THEN 'reserved'
            WHEN 'checkout_pending' THEN 'reserved'
            WHEN 'sold' THEN 'sold'
            ELSE 'unavailable'
       END AS public_state
  FROM wp_book_items i
 WHERE i.book_record_id = @record_id AND i.is_active = 1
 ORDER BY i.availability_status = 'available' DESC, i.price_amount, i.id;

-- Персоны и рубрики карточки. EXPLAIN: rc ref PRIMARY (book_record_id…); c eq_ref PRIMARY.
SELECT c.id, c.name_display, rc.role_code, rc.marc_tag
  FROM wp_book_record_contributors rc
  JOIN wp_book_contributors c ON c.id = rc.contributor_id
 WHERE rc.book_record_id = @record_id
 ORDER BY rc.position, rc.role_code;

SELECT s.id, s.heading, s.thesaurus
  FROM wp_book_record_subjects rs
  JOIN wp_book_subjects s ON s.id = rs.subject_id
 WHERE rs.book_record_id = @record_id
 ORDER BY rs.position;


-- =====================================================================
-- 2. Поиск
-- =====================================================================

-- 2.1. Основная строка поиска: FULLTEXT ft_records_main (title, subtitle, authors_text, series_title),
-- BOOLEAN MODE. PHP строит запрос из слов пользователя: спецсимволы булева синтаксиса (+-<>()~*"@)
-- вырезаются; слово длиной ≥ innodb_ft_min_token_size (3) → '+слово*'; КОРОЧЕ 3 символов — в запрос
-- не попадает: такие слова не индексируются, и обязательное '+и' или '+и*' обнуляет выдачу
-- («война и мир» → '+война* +мир*': 751 строка; '+война +и +мир' — 0, проверено на 8.0.46).
-- Если коротких слов больше нет — поиск не выполняется (подсказка «уточните запрос»).
-- Стоп-слов нет: индексы построены при innodb_ft_enable_stopword = OFF (Migrator), поэтому '+und',
-- '+the', '+for' работают. Сортировка по релевантности — пагинация OFFSET (keyset по score нестабилен),
-- но не дальше 10 страниц.
-- EXPLAIN: r fulltext ft_records_main; Using where; Using filesort — только по найденным строкам
--          (ORDER BY score, id: второй ключ нужен для стабильного порядка между страницами);
--          i ref ix_items_record_status для in_stock.
SET @q = '+толст* +войн*';  -- из «Толстой. Война и мир»: «и» отброшено
SELECT r.id, r.title, r.authors_text, r.publication_year,
       MATCH (r.title, r.subtitle, r.authors_text, r.series_title) AGAINST (@q IN BOOLEAN MODE) AS score,
       EXISTS (SELECT 1 FROM wp_book_items i
                WHERE i.book_record_id = r.id AND i.availability_status = 'available' AND i.is_active = 1) AS in_stock
  FROM wp_book_records r
 WHERE MATCH (r.title, r.subtitle, r.authors_text, r.series_title) AGAINST (@q IN BOOLEAN MODE)
   AND r.is_active = 1
 ORDER BY score DESC, r.id
 LIMIT 24 OFFSET 0;

-- 2.2. «Искать везде» (+ аннотация, рубрики, издатель): ft_records_all, NATURAL LANGUAGE MODE.
-- Список колонок в MATCH должен в точности совпадать с индексом, иначе 1191 «Can't find FULLTEXT index».
-- EXPLAIN: r fulltext ft_records_all.
SET @q_all = 'философия роман';
SELECT r.id, r.title, r.subjects_text,
       MATCH (r.title, r.subtitle, r.responsibility_statement, r.authors_text, r.series_title, r.subjects_text,
              r.description, r.publisher) AGAINST (@q_all IN NATURAL LANGUAGE MODE) AS score
  FROM wp_book_records r
 WHERE MATCH (r.title, r.subtitle, r.responsibility_statement, r.authors_text, r.series_title, r.subjects_text,
              r.description, r.publisher) AGAINST (@q_all IN NATURAL LANGUAGE MODE)
   AND r.is_active = 1
 ORDER BY score DESC, r.id
 LIMIT 24;

-- 2.3. Поиск по ISBN через wp_book_identifiers: ВСЕ 020 записи, включая отменённые ($z, is_cancelled = 1:
-- на старых книгах напечатан именно ошибочный номер). Ввод нормализует PHP:
-- MarcExtractor::normalizeIsbn('5-02-013850-9') → '9785020138506'. ISBN не уникален: результат — список.
-- EXPLAIN: idf ref ix_identifiers_lookup (id_type, id_value); r eq_ref PRIMARY.
SET @isbn13 = '9785000000001';
SELECT r.id, r.title, r.authors_text, r.publication_year, idf.raw_value, idf.is_cancelled
  FROM wp_book_identifiers idf
  JOIN wp_book_records r ON r.id = idf.book_record_id AND r.is_active = 1
 WHERE idf.id_type = 'isbn' AND idf.id_value = @isbn13
 ORDER BY idf.is_cancelled, r.title_sort, r.id;

-- 2.4. Поиск по автору (префикс нормализованного имени, как в указателе A–Я) через нормализованные
-- персоны: персона → связка → запись. Префикс ≥ 3 символов, LIKE-метасимволы экранирует
-- $wpdb->esc_like(). Keyset по (title_sort, id).
-- EXPLAIN: c range ix_contributors_sort; rc ref ix_rc_contributor (contributor_id, book_record_id);
--          r eq_ref PRIMARY; Using temporary; Using filesort (сортировка результатов по названию —
--          ограничена числом книг найденных авторов).
SET @author_prefix = 'толстой лев', @after_title_sort = '', @after_id = 0;
SELECT r.id, r.title, r.publication_year, c.name_display, rc.role_code
  FROM wp_book_contributors c
  JOIN wp_book_record_contributors rc ON rc.contributor_id = c.id
  JOIN wp_book_records r ON r.id = rc.book_record_id AND r.is_active = 1
 WHERE c.name_sort LIKE CONCAT(@author_prefix, '%')
   AND r.title_sort >= @after_title_sort
   AND (r.title_sort > @after_title_sort OR r.id > @after_id)
 ORDER BY r.title_sort, r.id
 LIMIT 24;

-- 2.5. Автодополнение в фильтре «автор»: FULLTEXT по имени персоны (любое слово имени).
-- EXPLAIN: c fulltext ft_contributors_name.
SET @author_q = '+достоевск*';
SELECT c.id, c.name_display, (SELECT COUNT(*) FROM wp_book_record_contributors rc WHERE rc.contributor_id = c.id) AS books
  FROM wp_book_contributors c
 WHERE MATCH (c.name_display) AGAINST (@author_q IN BOOLEAN MODE)
 LIMIT 10;

-- 2.6. Все книги рубрики (рубрикатор), keyset по record id.
-- EXPLAIN: rs range ix_rs_subject (subject_id, book_record_id) — Using index; r eq_ref PRIMARY.
SET @subject_id = 2, @after_id = 0;
SELECT r.id, r.title, r.authors_text
  FROM wp_book_record_subjects rs
  JOIN wp_book_records r ON r.id = rs.book_record_id AND r.is_active = 1
 WHERE rs.subject_id = @subject_id AND rs.book_record_id > @after_id
 ORDER BY rs.book_record_id
 LIMIT 24;


-- =====================================================================
-- 3. Статусы кнопок «Отложить» для списка item_ids (GET /catalog/availability?item_ids=…)
-- =====================================================================
-- Страницы каталога кэшируются целиком; кнопки получают живой статус этим запросом (≤ 100 id, no-store).
-- @user_id = 0 — гость: can_reserve = 0, reason = login_required. Для своего резерва — срок и флаг held_by_me,
-- для своего неоплаченного заказа — public_order_id; attempts_left считается без released_by_admin.
-- can_reserve учитывает валюту магазина (option uniundata_currency: экземпляр в другой валюте не
-- резервируется) и лимит одновременных активных резервов (option uniundata_max_active_reservations, 10).
-- Это только подсказка для кнопки: окончательно всё перепроверяет reserve() под FOR UPDATE.
-- Список id — JSON_TABLE (в PHP — IN (%d, %d, …) через Db::placeholders()).
-- EXPLAIN: ids ALL (табличная функция json_table, ≤ 100 строк); i eq_ref PRIMARY; me eq_ref
--          uq_reservations_one_active_per_item (active_book_item_id); попытки — ref uq_reservations_attempt
--          (Using index, покрывающий); свой открытый заказ — o ref uq_orders_checkout_request (user_id …) +
--          oi eq_ref uq_order_items_item (order_id, book_item_id).
SET @user_id = 21, @item_ids = '[50, 51, 52, 104, 2001, 2101, 101, 102, 103, 999999]',
    @shop_currency = 'RUB', @max_active = 10;
-- Активные резервы пользователя (ix_reservations_user (user_id, reservation_status, expires_at)).
SET @my_active = (SELECT COUNT(*) FROM wp_book_reservations
                   WHERE user_id = @user_id AND reservation_status = 'active' AND expires_at > UTC_TIMESTAMP(6));
SELECT ids.id AS book_item_id,
       i.availability_status IS NOT NULL AS exists_flag,
       CASE i.availability_status
            WHEN 'available' THEN 'available'
            WHEN 'reserved' THEN 'reserved'
            WHEN 'checkout_pending' THEN 'reserved'
            WHEN 'sold' THEN 'sold'
            ELSE 'unavailable'
       END AS public_state,
       me.id IS NOT NULL AS held_by_me,
       me.expires_at AS my_expires_at,
       GREATEST(0, TIMESTAMPDIFF(SECOND, UTC_TIMESTAMP(6), me.expires_at)) AS my_seconds_left,
       3 - (SELECT COUNT(*) FROM wp_book_reservations a
             WHERE a.user_id = @user_id AND a.book_item_id = ids.id AND a.attempt_no IS NOT NULL) AS attempts_left,
       (SELECT o.public_order_id
          FROM wp_book_order_items oi
          JOIN wp_book_orders o ON o.id = oi.order_id
         WHERE oi.book_item_id = ids.id AND o.user_id = @user_id
           AND o.status IN ('draft', 'pending_payment', 'payment_processing', 'payment_failed')
         LIMIT 1) AS my_open_order,
       COALESCE(@user_id > 0
        AND i.availability_status = 'available' AND i.is_active = 1
        AND i.currency = @shop_currency
        AND @my_active < @max_active
        AND (SELECT COUNT(*) FROM wp_book_reservations a
              WHERE a.user_id = @user_id AND a.book_item_id = ids.id AND a.attempt_no IS NOT NULL) < 3, 0) AS can_reserve
  FROM JSON_TABLE(@item_ids, '$[*]' COLUMNS (id BIGINT UNSIGNED PATH '$')) AS ids
  LEFT JOIN wp_book_items i ON i.id = ids.id
  LEFT JOIN wp_book_reservations me ON me.active_book_item_id = ids.id AND me.user_id = @user_id;


-- =====================================================================
-- 4. Корзина пользователя с оставшимся временем (GET /cart — только чтение, без продления)
-- =====================================================================
-- Каждая позиция истекает независимо: seconds_left по своей expires_at. Позиция, чей срок вышел до
-- прихода cron, показывается как истёкшая и не входит в сумму. catalog_price_changed — пометка
-- «цена в каталоге изменилась» (платится снимок unit_price_amount).
-- EXPLAIN: c const uq_carts_one_open_per_user (open_cart_user_id); ci ref ix_cart_items_cart (cart_id, status);
--          i/r eq_ref PRIMARY.
SET @user_id = 1;
SELECT c.id AS cart_id, ci.id AS cart_item_id, ci.book_item_id, r.title, r.authors_text,
       ci.unit_price_amount, ci.currency, i.price_amount <> ci.unit_price_amount AS catalog_price_changed,
       ci.expires_at,
       GREATEST(0, TIMESTAMPDIFF(SECOND, UTC_TIMESTAMP(6), ci.expires_at)) AS seconds_left,
       ci.expires_at <= UTC_TIMESTAMP(6) AS is_expired
  FROM wp_book_carts c
  JOIN wp_book_cart_items ci ON ci.cart_id = c.id AND ci.status = 'active'
  JOIN wp_book_items i ON i.id = ci.book_item_id
  JOIN wp_book_records r ON r.id = i.book_record_id
 WHERE c.open_cart_user_id = @user_id
 ORDER BY ci.expires_at, ci.id;

-- Итог корзины по валютам (только неистёкшие позиции) — то же, что сверяет checkout с expected_total_amount.
SELECT ci.currency, COUNT(*) AS items, SUM(ci.unit_price_amount) AS total_amount, MIN(ci.expires_at) AS next_expiry
  FROM wp_book_carts c
  JOIN wp_book_cart_items ci ON ci.cart_id = c.id AND ci.status = 'active'
 WHERE c.open_cart_user_id = @user_id
   AND ci.expires_at > UTC_TIMESTAMP(6)
 GROUP BY ci.currency;


-- =====================================================================
-- 5. Выборки для expiry (задачи Action Scheduler каждую минуту)
-- =====================================================================
-- Кандидаты выбираются ОБЫЧНЫМ чтением (без блокировок) по индексу «статус + срок», затем каждый
-- кандидат обрабатывается своей короткой транзакцией в глобальном порядке блокировок
-- (корзина → экземпляр → резерв → позиция корзины → заказ …) и ПЕРЕПРОВЕРЯЕТСЯ под FOR UPDATE.
-- Сравнение со сроком — только `колонка <= UTC_TIMESTAMP(6)`: функция на колонке
-- (DATE_ADD(reserved_at, …), TIMESTAMPDIFF(…, expires_at)) отключила бы индекс.

-- 5.1. Истёкшие активные резервы. EXPLAIN: range ix_reservations_expiry (reservation_status, expires_at);
--      Using index condition; без filesort (порядок по expires_at внутри status = 'active' — из индекса).
SELECT r.id, r.cart_id, r.book_item_id, r.user_id, r.expires_at
  FROM wp_book_reservations r
 WHERE r.reservation_status = 'active'
   AND r.expires_at <= UTC_TIMESTAMP(6)
 ORDER BY r.expires_at
 LIMIT 200;

-- 5.2. Истёкшие позиции корзин (контроль: должны совпадать с 5.1 — срок копируется из резерва).
--      EXPLAIN: range ix_cart_items_expiry (status, expires_at).
SELECT ci.id, ci.cart_id, ci.book_item_id, ci.reservation_id, ci.expires_at
  FROM wp_book_cart_items ci
 WHERE ci.status = 'active'
   AND ci.expires_at <= UTC_TIMESTAMP(6)
 ORDER BY ci.expires_at
 LIMIT 200;

-- 5.3. Неоплаченные заказы, у которых вышел payment_due_at (OrderExpiryService: сначала опрос банка вне
--      транзакции, затем payment_expired + освобождение экземпляров).
--      EXPLAIN: range ix_orders_payment_due (status, payment_due_at) — 4 интервала по статусам;
--      Using filesort только по найденным строкам (ORDER BY через несколько интервалов IN).
SELECT o.id, o.public_order_id, o.status, o.payment_due_at
  FROM wp_book_orders o
 WHERE o.status IN ('draft', 'pending_payment', 'payment_processing', 'payment_failed')
   AND o.payment_due_at <= UTC_TIMESTAMP(6)
 ORDER BY o.payment_due_at
 LIMIT 100;

-- 5.4. Пустые открытые корзины без активности 30 дней → abandoned (ежедневная задача uniundata_abandon_carts).
--      EXPLAIN: range ix_carts_status_activity (status, last_activity_at).
SELECT c.id, c.user_id, c.last_activity_at
  FROM wp_book_carts c
 WHERE c.status IN ('active', 'checkout_started')
   AND c.last_activity_at < UTC_TIMESTAMP(6) - INTERVAL 30 DAY
   AND NOT EXISTS (SELECT 1 FROM wp_book_cart_items ci WHERE ci.cart_id = c.id AND ci.status = 'active')
 ORDER BY c.last_activity_at
 LIMIT 500;

-- 5.5. Шаг обработки одного резерва (внутри START TRANSACTION … COMMIT, READ COMMITTED) — порядок
--      блокировок; решения принимаются только по строкам, прочитанным FOR UPDATE:
--   SELECT id, status FROM wp_book_carts WHERE id = @cart_id FOR UPDATE;                         -- 1
--   SELECT id, availability_status, source_status FROM wp_book_items WHERE id = @item_id FOR UPDATE;  -- 2
--   SELECT id, reservation_status, expires_at <= UTC_TIMESTAMP(6) AS is_due
--     FROM wp_book_reservations WHERE id = @reservation_id FOR UPDATE;                           -- 3
--   -- если всё ещё active и is_due: reservation → expired, cart_item → expired, item reserved → release target:
--   UPDATE wp_book_items
--      SET availability_status = CASE source_status WHEN 'present' THEN 'available'
--                                WHEN 'missing' THEN 'sync_missing' WHEN 'withdrawn' THEN 'withdrawn' END,
--          status_changed_at = UTC_TIMESTAMP(6)
--    WHERE id = @item_id AND availability_status = 'reserved';                                  -- affected = 1


-- =====================================================================
-- 6. Попытки резервирования пользователя (лимит 3 на экземпляр)
-- =====================================================================
-- Попытка = любой резерв (user, item), кроме released_by_admin (у него attempt_no = NULL).
-- В reserve() тот же COUNT выполняется под FOR UPDATE строки экземпляра; UNIQUE(user_id, book_item_id,
-- attempt_no) + CHECK (attempt_no BETWEEN 1 AND 3) — второй рубеж.
-- EXPLAIN: ref uq_reservations_attempt (user_id, book_item_id); Using where; Using index (покрывающий).
SET @user_id = 5, @item_id = 210;
SELECT COUNT(*) AS attempts_used, GREATEST(0, 3 - COUNT(*)) AS attempts_left
  FROM wp_book_reservations
 WHERE user_id = @user_id AND book_item_id = @item_id AND attempt_no IS NOT NULL;

-- История попыток пользователя по экземпляру (для поддержки).
SELECT id, attempt_no, reservation_status, reserved_at, expires_at, released_at, release_reason
  FROM wp_book_reservations
 WHERE user_id = @user_id AND book_item_id = @item_id
 ORDER BY reserved_at;

-- Экземпляры, на которые пользователь исчерпал лимит (кнопка у него — «Лимит резервов исчерпан»).
-- EXPLAIN: ref ix_reservations_user / uq_reservations_attempt (user_id …); Using index.
SELECT book_item_id, COUNT(*) AS attempts
  FROM wp_book_reservations
 WHERE user_id = @user_id AND attempt_no IS NOT NULL
 GROUP BY book_item_id
HAVING COUNT(*) >= 3;


-- =====================================================================
-- 7. История продаж
-- =====================================================================

-- 7.1. Продажи за период (отчёт менеджера), новые сверху, keyset по (sold_at, id).
--      Данные книги — из снимков order_items: запись могла измениться синхронизацией после продажи.
--      EXPLAIN: s range ix_sales_sold_at; o/oi eq_ref PRIMARY; u eq_ref PRIMARY (wp_users).
-- Первая страница: @before_sold_at = @to, @before_id = максимум BIGINT UNSIGNED.
SET @from = UTC_TIMESTAMP(6) - INTERVAL 120 DAY, @to = UTC_TIMESTAMP(6),
    @before_sold_at = UTC_TIMESTAMP(6), @before_id = 18446744073709551615;
SELECT s.id, s.sold_at, s.price_amount, s.currency, o.public_order_id,
       oi.title_snapshot, oi.author_snapshot, oi.isbn_snapshot, oi.item_identifier_snapshot,
       s.user_id, u.display_name AS customer_display_name -- NULL: аккаунт удалён, заказ остался (псевдоним user_id)
  FROM wp_book_sales s
  JOIN wp_book_orders o ON o.id = s.order_id
  JOIN wp_book_order_items oi ON oi.id = s.order_item_id
  LEFT JOIN wp_users u ON u.ID = s.user_id
 WHERE s.sold_at >= @from AND s.sold_at < @to
   AND s.sold_at <= @before_sold_at
   AND (s.sold_at < @before_sold_at OR s.id < @before_id)
 ORDER BY s.sold_at DESC, s.id DESC
 LIMIT 50;

-- 7.2. Выручка по месяцам и валютам (деньги не складываются через валюты).
--      EXPLAIN: range ix_sales_sold_at; Using temporary (GROUP BY по выражению). На тестовых данных за 12 месяцев
--      попадают все 100 продаж, и оптимизатор выбирает ALL — это верно; за 30 дней — range ix_sales_sold_at, Using index.
SELECT DATE_FORMAT(s.sold_at, '%Y-%m') AS month, s.currency, COUNT(*) AS items_sold, SUM(s.price_amount) AS revenue_amount,
       SUM(s.refunded_at IS NOT NULL) AS refunded_items
  FROM wp_book_sales s
 WHERE s.sold_at >= UTC_TIMESTAMP(6) - INTERVAL 12 MONTH
 GROUP BY month, s.currency
 ORDER BY month DESC, s.currency;

-- 7.3. Покупки пользователя («Мои заказы → купленные книги»). EXPLAIN: s ref ix_sales_user (user_id, sold_at).
SET @user_id = 45;
SELECT s.sold_at, oi.title_snapshot, oi.author_snapshot, s.price_amount, s.currency, o.public_order_id, o.status
  FROM wp_book_sales s
  JOIN wp_book_order_items oi ON oi.id = s.order_item_id
  JOIN wp_book_orders o ON o.id = s.order_id
 WHERE s.user_id = @user_id
 ORDER BY s.sold_at DESC
 LIMIT 50;

-- 7.4. Полная история экземпляра: резервы → заказы → продажа (разбор спорных случаев).
--      EXPLAIN: ref ix_reservations_item_history, ix_order_items_book_item, uq_sales_book_item.
SET @item_id = 2001;
SELECT 'reservation' AS kind, r.id, r.user_id, r.reservation_status AS status, r.reserved_at AS at, r.release_reason AS note
  FROM wp_book_reservations r WHERE r.book_item_id = @item_id
UNION ALL
SELECT 'order', o.id, o.user_id, o.status, o.placed_at, o.public_order_id
  FROM wp_book_order_items oi JOIN wp_book_orders o ON o.id = oi.order_id WHERE oi.book_item_id = @item_id
UNION ALL
SELECT 'sale', s.id, s.user_id, IF(s.refunded_at IS NULL, 'sold', 'refunded'), s.sold_at, CONCAT(s.price_amount, ' ', s.currency)
  FROM wp_book_sales s WHERE s.book_item_id = @item_id
ORDER BY at, kind;


-- =====================================================================
-- 8. Отчёт по синхронизациям
-- =====================================================================

-- 8.1. Последние прогоны источника со счётчиками и признаком «завис» (heartbeat старше 900 с —
--      SyncService::STALE_AFTER_SECONDS). resumed_from_run_id — какой упавший прогон продолжен,
--      pass_started_run_id — первый прогон полного прохода; source_cursor: NULL — читать с начала,
--      '' — источник прочитан, идёт проход «пропавших», иначе — курсор источника.
--      EXPLAIN: ref ix_sync_runs_source (source_name, started_at), Backward index scan, без filesort
--      (проверено при 3 000 прогонах 30 источников; при 31 строке оптимизатор честно выбирает ALL).
SET @source = 'primary';
SELECT id, triggered_by, status, started_at, finished_at, resumed_from_run_id, pass_started_run_id, source_cursor,
       TIMESTAMPDIFF(SECOND, started_at, COALESCE(finished_at, UTC_TIMESTAMP(6))) AS duration_s,
       records_received, records_created, records_updated, records_skipped,
       items_created, items_updated, items_skipped, items_conflicts, items_missing, items_withdrawn, errors_count,
       status = 'running' AND heartbeat_at < UTC_TIMESTAMP(6) - INTERVAL 900 SECOND AS is_stale
  FROM wp_book_sync_runs
 WHERE source_name = @source
 ORDER BY started_at DESC, id DESC
 LIMIT 10;

-- 8.1a. Цепочка прохода: все прогоны, которые продолжали друг друга (упал → retry → …).
WITH RECURSIVE chain AS (
  SELECT id, resumed_from_run_id, status, 0 AS depth FROM wp_book_sync_runs
   WHERE running_source = @source                              -- текущий running-прогон (uq_sync_runs_one_running)
  UNION ALL
  SELECT r.id, r.resumed_from_run_id, r.status, c.depth + 1
    FROM wp_book_sync_runs r JOIN chain c ON r.id = c.resumed_from_run_id
)
SELECT id, status, depth FROM chain ORDER BY depth;

-- 8.2. Ошибки последних прогонов из error_log (JSON) построчно. EXPLAIN: ref ix_sync_runs_source; e — табличная функция.
SELECT sr.id AS run_id, sr.status, e.code, e.external_id, e.message
  FROM wp_book_sync_runs sr,
       JSON_TABLE(sr.error_log, '$[*]' COLUMNS (
           code        VARCHAR(64)  PATH '$.code',
           external_id VARCHAR(191) PATH '$.external_id',
           message     VARCHAR(300) PATH '$.message')) AS e
 WHERE sr.source_name = @source
   AND sr.started_at >= UTC_TIMESTAMP(6) - INTERVAL 7 DAY
 ORDER BY sr.id DESC;

-- 8.3. Сводка по дням: сколько прогонов, сколько неуспешных, сумма конфликтов и пропавших (мониторинг).
SELECT DATE(started_at) AS day, COUNT(*) AS runs,
       SUM(status IN ('failed', 'aborted')) AS failed_runs, SUM(status = 'partial') AS partial_runs,
       SUM(items_conflicts) AS conflicts, SUM(items_missing) AS missing, SUM(errors_count) AS errors
  FROM wp_book_sync_runs
 WHERE source_name = @source AND started_at >= UTC_TIMESTAMP(6) - INTERVAL 14 DAY
 GROUP BY day
 ORDER BY day DESC;

-- 8.4. Расхождение «локальный статус ↔ источник» по экземплярам источника (что видит оператор после
--      конфликтов): например, sold при source_status = present — источник продаёт проданное.
--      EXPLAIN: ref ix_items_sync (source_name …) / full scan по источнику — отчёт, не горячий путь.
SELECT availability_status, source_status, COUNT(*) AS items
  FROM wp_book_items
 WHERE source_name = @source
 GROUP BY availability_status, source_status
 ORDER BY availability_status, source_status;

-- 8.5. Кандидаты в «пропавшие» после полного прохода (то же условие, что у SyncService::missingCounts /
--      прохода sync_missing). Порог — pass_started_run_id текущего прогона: экземпляры, которые встречались
--      в любом прогоне цепочки прохода, имеют last_seen_sync_run_id ≥ этого id.
--      Подсчёт — один проход по экземплярам источника за прогон (ALL/ref по source_name; 100 тыс. строк ≈ десятки мс).
--      Выборка кандидатов: при доле кандидатов в единицы процентов — range ix_items_sync
--      (source_name, last_seen_sync_run_id; `IS NULL OR < N` — два интервала) + filesort по id найденных
--      (проверено: 59 кандидатов из 6 000); когда кандидатов нет вовсе — range PRIMARY (id > N) с фильтром.
SET @pass_run_id = COALESCE((SELECT pass_started_run_id FROM wp_book_sync_runs WHERE running_source = @source), 100);
SELECT COUNT(*) AS active_items,
       SUM(last_seen_sync_run_id IS NULL OR last_seen_sync_run_id < @pass_run_id) AS would_be_missing
  FROM wp_book_items
 WHERE source_name = @source AND source_status = 'present' AND availability_status <> 'sold'
   AND external_item_id IS NOT NULL;

SELECT id, external_item_id, availability_status, last_seen_sync_run_id
  FROM wp_book_items
 WHERE source_name = @source
   AND (last_seen_sync_run_id IS NULL OR last_seen_sync_run_id < @pass_run_id)
   AND source_status = 'present' AND availability_status <> 'sold' AND external_item_id IS NOT NULL
   AND id > 0
 ORDER BY id
 LIMIT 200;


-- =====================================================================
-- 9. Заказы, требующие внимания
-- =====================================================================

-- 9.1. Флаг needs_attention (late_payment_conflict, duplicate_payment, amount_mismatch…), старые сверху.
--      EXPLAIN: ref ix_orders_attention (needs_attention, updated_at); без filesort.
SELECT o.id, o.public_order_id, o.status, o.attention_reason, o.total_amount, o.currency, o.updated_at,
       (SELECT COUNT(*) FROM wp_book_payments p WHERE p.order_id = o.id AND p.status = 'succeeded') AS succeeded_payments
  FROM wp_book_orders o
 WHERE o.needs_attention = 1
 ORDER BY o.updated_at
 LIMIT 100;

-- 9.2. Оплата «зависла»: payment_processing дольше 2 часов (банк не прислал итог; опрос не помог).
--      EXPLAIN: range ix_orders_status_created (status, created_at).
SELECT o.id, o.public_order_id, o.created_at, o.payment_due_at
  FROM wp_book_orders o
 WHERE o.status = 'payment_processing'
   AND o.created_at < UTC_TIMESTAMP(6) - INTERVAL 2 HOUR
 ORDER BY o.created_at;

-- 9.3. Оплачено, но не отгружено дольше 3 дней.
--      EXPLAIN: range ix_orders_status_created (status, created_at).
SELECT o.id, o.public_order_id, o.paid_at, o.total_amount, o.currency
  FROM wp_book_orders o
 WHERE o.status = 'paid'
   AND o.created_at < UTC_TIMESTAMP(6) - INTERVAL 3 DAY
 ORDER BY o.created_at
 LIMIT 100;

-- 9.4. Срок оплаты вышел более 10 минут назад, а заказ всё ещё открыт — задача expiry не работает (алерт).
--      Заказы с needs_attention (amount_mismatch, reference_mismatch) cron не закрывает намеренно — их
--      решает менеджер, поэтому они исключены. EXPLAIN: range ix_orders_payment_due.
SELECT o.id, o.public_order_id, o.status, o.payment_due_at
  FROM wp_book_orders o
 WHERE o.status IN ('draft', 'pending_payment', 'payment_processing', 'payment_failed')
   AND o.needs_attention = 0
   AND o.payment_due_at < UTC_TIMESTAMP(6) - INTERVAL 10 MINUTE;

-- 9.5. Очередь возвратов: requested/pending, старые сверху (обрабатывает задача uniundata_refund_payment
--      {refund_id}; дольше суток — повод разобраться вручную).
--      EXPLAIN (3 000 возвратов): rf range ix_refunds_status (status, requested_at); Using index condition;
--      Using filesort только по найденным строкам (два интервала IN); p/o eq_ref PRIMARY.
SELECT rf.id, rf.status, rf.reason, rf.amount, rf.currency, rf.requested_at, o.public_order_id, p.provider_payment_id
  FROM wp_book_refunds rf
  JOIN wp_book_orders o ON o.id = rf.order_id
  JOIN wp_book_payments p ON p.id = rf.payment_id
 WHERE rf.status IN ('requested', 'pending')
 ORDER BY rf.requested_at
 LIMIT 100;

-- 9.6. Возвраты и платежи заказа (карточка заказа менеджера).
--      EXPLAIN: p ref uq_payments_attempt (order_id, attempt_no); rf ref ix_refunds_payment.
SET @order_id = 8;
SELECT p.id AS payment_id, p.attempt_no, p.status AS payment_status, p.amount, p.refunded_amount,
       rf.id AS refund_id, rf.status AS refund_status, rf.reason, rf.amount AS refund_amount
  FROM wp_book_payments p
  LEFT JOIN wp_book_refunds rf ON rf.payment_id = p.id
 WHERE p.order_id = @order_id
 ORDER BY p.attempt_no, rf.id;


-- =====================================================================
-- 10. Проверка инвариантов (каждый запрос должен вернуть 0 строк; `wp uniundata doctor`)
-- =====================================================================
-- То, что гарантирует схема (один активный резерв на экземпляр, одна открытая корзина, одна продажа,
-- sold ⇔ sold_at, ≤ 3 попыток), здесь не проверяется — это UNIQUE/CHECK. Проверяются связи МЕЖДУ
-- таблицами, которые держит только код сервисов. На тестовых данных намеренно сломаны экземпляры
-- 5999 (reserved), 5998 (checkout_pending), 5997 (sold) — запросы 10.1, 10.4, 10.6 их находят.

-- Проверки — раз в сутки (и в CI), поэтому полный проход по экземплярам (ALL) допустим: отдельный индекс
-- по availability_status (7 значений) ради них не нужен. Связанные таблицы читаются по уникальным ключам.

-- 10.1. Экземпляр reserved без активного резерва.
--       EXPLAIN: i ALL; r eq_ref uq_reservations_one_active_per_item (active_book_item_id); Not exists; Using index.
SELECT i.id, i.status_changed_at
  FROM wp_book_items i
  LEFT JOIN wp_book_reservations r ON r.active_book_item_id = i.id
 WHERE i.availability_status = 'reserved' AND r.id IS NULL;

-- 10.2. Активный резерв, а экземпляр не reserved (обратное направление).
SELECT r.id AS reservation_id, r.book_item_id, i.availability_status
  FROM wp_book_reservations r
  JOIN wp_book_items i ON i.id = r.book_item_id
 WHERE r.reservation_status = 'active' AND i.availability_status <> 'reserved';

-- 10.3. Активная позиция корзины без активного резерва (и наоборот) — срок и статус у них общие.
SELECT ci.id AS cart_item_id, ci.reservation_id, r.reservation_status
  FROM wp_book_cart_items ci
  JOIN wp_book_reservations r ON r.id = ci.reservation_id
 WHERE ci.status = 'active' AND r.reservation_status <> 'active';

SELECT r.id AS reservation_id
  FROM wp_book_reservations r
  LEFT JOIN wp_book_cart_items ci ON ci.reservation_id = r.id AND ci.status = 'active'
 WHERE r.reservation_status = 'active' AND r.cart_id IS NOT NULL AND ci.id IS NULL;

-- 10.4. checkout_pending без открытого заказа (заказ с needs_attention держит экземпляры до решения менеджера).
--       EXPLAIN: i ALL; NOT EXISTS материализуется (<subquery2> по <auto_distinct_key>, anti-join).
SELECT i.id, i.status_changed_at
  FROM wp_book_items i
 WHERE i.availability_status = 'checkout_pending'
   AND NOT EXISTS (SELECT 1
                     FROM wp_book_order_items oi
                     JOIN wp_book_orders o ON o.id = oi.order_id
                    WHERE oi.book_item_id = i.id
                      AND (o.status IN ('draft', 'pending_payment', 'payment_processing', 'payment_failed')
                           OR o.needs_attention = 1));

-- 10.5. Открытый заказ держит экземпляр, который не checkout_pending (освобождён раньше времени).
SELECT o.id AS order_id, o.status, oi.book_item_id, i.availability_status
  FROM wp_book_orders o
  JOIN wp_book_order_items oi ON oi.order_id = o.id
  JOIN wp_book_items i ON i.id = oi.book_item_id
 WHERE o.status IN ('draft', 'pending_payment', 'payment_processing', 'payment_failed')
   AND i.availability_status <> 'checkout_pending';

-- 10.6. sold без строки продажи. EXPLAIN: s eq_ref uq_sales_book_item; Not exists.
SELECT i.id, i.sold_at
  FROM wp_book_items i
  LEFT JOIN wp_book_sales s ON s.book_item_id = i.id
 WHERE i.availability_status = 'sold' AND s.id IS NULL;

-- 10.7. Продажа есть, а экземпляр не sold (обратное направление; refund не возвращает в продажу).
SELECT s.id AS sale_id, s.book_item_id, i.availability_status
  FROM wp_book_sales s
  JOIN wp_book_items i ON i.id = s.book_item_id
 WHERE i.availability_status <> 'sold';

-- 10.8. Оплаченный заказ без продажи по позиции (кроме разобранных вручную needs_attention).
--       EXPLAIN: s eq_ref uq_sales_order_item.
SELECT o.id AS order_id, oi.id AS order_item_id, oi.book_item_id
  FROM wp_book_orders o
  JOIN wp_book_order_items oi ON oi.order_id = o.id
  LEFT JOIN wp_book_sales s ON s.order_item_id = oi.id
 WHERE o.status IN ('paid', 'fulfilled', 'completed') AND o.needs_attention = 0 AND s.id IS NULL;

-- 10.9. Сумма заказа ≠ сумма позиций (снимки цен) и успешный платёж ≠ сумма заказа.
SELECT o.id, o.subtotal_amount, SUM(oi.unit_price_amount * oi.quantity) AS items_sum
  FROM wp_book_orders o
  JOIN wp_book_order_items oi ON oi.order_id = o.id
 GROUP BY o.id, o.subtotal_amount
HAVING o.subtotal_amount <> items_sum;

SELECT p.id AS payment_id, p.order_id, p.amount, o.total_amount
  FROM wp_book_payments p
  JOIN wp_book_orders o ON o.id = p.order_id
 WHERE p.status IN ('succeeded', 'partially_refunded', 'refunded')
   AND (p.amount <> o.total_amount OR p.currency <> o.currency) AND o.needs_attention = 0;

-- 10.10. Активный резерв просрочен более чем на 10 минут — задача expiry не работает (алерт, а не порча данных).
--        EXPLAIN: range ix_reservations_expiry.
SELECT COUNT(*) AS overdue_reservations, MIN(expires_at) AS oldest_expiry
  FROM wp_book_reservations
 WHERE reservation_status = 'active' AND expires_at < UTC_TIMESTAMP(6) - INTERVAL 10 MINUTE;

-- 10.11. payments.refunded_amount = сумма успешных возвратов платежа (CHECK refunded_amount <= amount
--        держит схема, равенство — PaymentService). EXPLAIN: p ALL; rf ref ix_refunds_payment.
SELECT p.id AS payment_id, p.refunded_amount,
       (SELECT COALESCE(SUM(rf.amount), 0) FROM wp_book_refunds rf WHERE rf.payment_id = p.id AND rf.status = 'succeeded') AS refunds_sum
  FROM wp_book_payments p
HAVING p.refunded_amount <> refunds_sum;

-- 10.12. Возврат завис: requested/pending дольше суток (алерт; банк не ответил, задача падает).
SELECT id, order_id, status, requested_at
  FROM wp_book_refunds
 WHERE status IN ('requested', 'pending') AND requested_at < UTC_TIMESTAMP(6) - INTERVAL 1 DAY;


-- =====================================================================
-- 11. Персональные данные: сроки хранения (задача uniundata_privacy_retention, 152-ФЗ / GDPR)
-- =====================================================================
-- Признак этапа 1 (контакты обезличены) — orders.pii_erased_at, а не содержимое полей. Каждый шаг —
-- одиночные UPDATE по одной таблице пакетами, без других блокировок. Сроки — фильтры
-- uniundata_retention_contact_days (730), uniundata_consent_ip_retention_days (180),
-- uniundata_retention_accounting_years (10): заглушки до решения юриста.
-- «Закрытый» заказ: completed | refunded | cancelled | payment_expired (или partially_refunded после отгрузки),
-- без needs_attention и без незавершённого возврата.

-- 11.1. Кандидаты этапа 1 — двумя запросами без OR и без ORDER BY: так оба идут range по
--       ix_orders_retention (pii_erased_at, status, updated_at). Проверено на 30 тыс. заказов: с ORDER BY id
--       или с OR-условием «аккаунт удалён» в том же запросе план уходит в полный проход по PRIMARY.
-- а) истёк срок хранения контактов. EXPLAIN: o range ix_orders_retention; Using index condition;
--    rf — материализованный NOT EXISTS (Not exists).
SET @contact_days = 730;
SELECT o.id, o.status, o.updated_at
  FROM wp_book_orders o
 WHERE o.pii_erased_at IS NULL
   AND (o.status IN ('completed', 'refunded', 'cancelled', 'payment_expired')
        OR (o.status = 'partially_refunded' AND o.fulfilled_at IS NOT NULL))
   AND o.needs_attention = 0
   AND NOT EXISTS (SELECT 1 FROM wp_book_refunds rf WHERE rf.order_id = o.id AND rf.status IN ('requested', 'pending'))
   AND o.updated_at < UTC_TIMESTAMP(6) - INTERVAL @contact_days DAY
 LIMIT 500;

-- б) догоняющий: аккаунт удалён или был запрос на удаление, а заказ закрылся позже.
--    EXPLAIN: o ref ix_orders_attention / ix_orders_retention (необработанные закрытые заказы);
--    u eq_ref PRIMARY (wp_users); m ref user_id (wp_usermeta).
SELECT o.id, o.user_id, o.status
  FROM wp_book_orders o
 WHERE o.pii_erased_at IS NULL
   AND (o.status IN ('completed', 'refunded', 'cancelled', 'payment_expired')
        OR (o.status = 'partially_refunded' AND o.fulfilled_at IS NOT NULL))
   AND o.needs_attention = 0
   AND NOT EXISTS (SELECT 1 FROM wp_book_refunds rf WHERE rf.order_id = o.id AND rf.status IN ('requested', 'pending'))
   AND (NOT EXISTS (SELECT 1 FROM wp_users u WHERE u.ID = o.user_id)
        OR EXISTS (SELECT 1 FROM wp_usermeta m
                    WHERE m.user_id = o.user_id AND m.meta_key = 'uniundata_erasure_requested_at'))
 LIMIT 500;

-- 11.2. Этап 1 для найденных id (в PHP — IN (%d, …); условие «закрыт» перепроверяется на заблокированной
--       версии строки). Пример на одном заказе в транзакции с откатом — данные теста не меняются.
START TRANSACTION;
UPDATE wp_book_orders o
   SET o.customer_email = CONCAT('erased-', o.id, '@invalid.invalid'),
       o.customer_phone = NULL,
       o.shipping_address_json = IF(o.shipping_address_json IS NULL, NULL,
           JSON_OBJECT('v', 1, 'redacted', TRUE,
                       'country_code', JSON_UNQUOTE(JSON_EXTRACT(o.shipping_address_json, '$.country_code')))),
       o.pii_erased_at = UTC_TIMESTAMP(6)
 WHERE o.id IN (21, 22) AND o.pii_erased_at IS NULL
   AND o.status IN ('completed', 'refunded', 'cancelled', 'payment_expired') AND o.needs_attention = 0;
SELECT ROW_COUNT() AS contacts_erased;
ROLLBACK;

-- 11.3. Этап 2 (ФИО, платёжный адрес) после бухгалтерского срока — только по уже обезличенным контактам.
--       EXPLAIN: o range ix_orders_retention (pii_erased_at IS NOT NULL); Using index condition. Диапазон —
--       все обезличенные заказы (растёт со временем); признак этапа 2 — 'Anonymized' в фамилии (отдельной
--       колонки в схеме v2 нет).
SET @accounting_years = 10;
SELECT o.id, COALESCE(o.completed_at, o.cancelled_at, o.paid_at, o.placed_at, o.created_at) AS closed_at
  FROM wp_book_orders o
 WHERE o.pii_erased_at IS NOT NULL
   AND o.customer_last_name <> 'Anonymized'
   AND COALESCE(o.completed_at, o.cancelled_at, o.paid_at, o.placed_at, o.created_at)
       < UTC_TIMESTAMP(6) - INTERVAL @accounting_years YEAR
 LIMIT 500;

-- 11.4. IP и хэш User-Agent в согласиях старше 180 дней (в PHP — UPDATE … SET ip_address = NULL,
--       user_agent_sha256 = NULL с тем же WHERE и LIMIT 500).
--       EXPLAIN (30 тыс. согласий): range ix_consents_retention (accepted_at); Using index condition.
SET @ip_days = 180;
SELECT id, accepted_at
  FROM wp_book_user_consents
 WHERE accepted_at < UTC_TIMESTAMP(6) - INTERVAL @ip_days DAY
   AND (ip_address IS NOT NULL OR user_agent_sha256 IS NOT NULL)
 LIMIT 500;
