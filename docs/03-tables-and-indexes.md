# 03. Таблицы, поля, индексы и ограничения

Источник истины — `sql/schema.sql`. Документ объясняет, **зачем** каждая таблица, каждый индекс и каждое
ограничение, и какие решения приняты под WordPress 7.1 / PHP 8.4 / MySQL 8.0.16+. Всё, что помечено
«проверено», воспроизведено на MySQL 8.0.46 на этой схеме.

Содержание:

1. [Общие соглашения](#1-общие-соглашения)
2. [Список таблиц](#2-список-таблиц)
3. [Таблицы: ключевые поля и индексы](#3-таблицы-ключевые-поля-и-индексы)
4. [MARC 21 → колонки](#4-marc-21--колонки)
5. [Что не раскладываем в колонки и почему](#5-что-не-раскладываем-в-колонки-и-почему)
6. [Уникальность «только среди активных»: generated columns](#6-уникальность-только-среди-активных-generated-columns)
7. [CHECK-ограничения](#7-check-ограничения)
8. [FOREIGN KEY без каскадов](#8-foreign-key-без-каскадов)
9. [Почему нет FK на wp_users](#9-почему-нет-fk-на-wp_users)
10. [Почему не dbDelta](#10-почему-не-dbdelta)
11. [FULLTEXT-поиск](#11-fulltext-поиск)
12. [Почему ISBN не уникален](#12-почему-isbn-не-уникален)
13. [Кодировки и collation](#13-кодировки-и-collation)
14. [Оценка объёмов](#14-оценка-объёмов)

---

## 1. Общие соглашения

| Решение | Как в схеме | Почему |
|---|---|---|
| Движок | `ENGINE=InnoDB` везде | Транзакции, row-level locks, `SELECT … FOR UPDATE`, FOREIGN KEY, FULLTEXT |
| Суррогатные ключи | `BIGINT UNSIGNED AUTO_INCREMENT` | Совпадает с типом `wp_users.ID` / `wp_posts.ID` (`BIGINT(20) UNSIGNED`), запас на годы |
| Деньги | `INT UNSIGNED` в минимальных единицах (центах) | Нет ошибок округления `FLOAT`, нет масштабирования `DECIMAL` в PHP (`int` в PHP 8.4 64-битный). Максимум 42 949 672,95 EUR на значение — достаточно |
| Валюта | `CHAR(3) CHARACTER SET ascii` + CHECK формата | ISO 4217. Хранится рядом с каждой суммой (экземпляр, позиция корзины, позиция заказа, платёж, продажа) |
| Даты | `DATETIME(6)`, всё в UTC | Соединение: `SET time_zone = '+00:00'`, в SQL — `UTC_TIMESTAMP(6)`. `DATETIME`, а не `TIMESTAMP`: нет пересчёта по `time_zone` сессии и нет проблемы 2038. Микросекунды нужны для упорядочивания событий внутри одной секунды (резерв → checkout → webhook) |
| Статусы | `VARCHAR(n) CHARACTER SET ascii` + `CHECK (status IN (…))` | WordPress убирает из `sql_mode` строгие режимы (`STRICT_TRANS_TABLES`, `STRICT_ALL_TABLES` и др.): в такой сессии неверное значение `ENUM` **молча** превращается в `''`, а CHECK всё равно выдаёт ошибку 3819 (проверено). Цена: добавить статус = `DROP CHECK` (INSTANT) + `ADD CHECK` (`ALGORITHM=COPY`, перестроение таблицы, проверено) — делается миграцией в окно обслуживания |
| Коды, хэши, внешние ID | `CHARACTER SET ascii` | 1 байт на символ вместо 4: `VARCHAR(191) ascii` в индексе занимает 191 байт, а не 764 |
| Флаги | `TINYINT(1)` + `CHECK (x IN (0, 1))` | |
| Удаление | Никогда не `DELETE` бизнес-строк | Корзины, резервы, заказы, платежи, продажи, проданные экземпляры только меняют статус. Отсюда FK без каскадов (раздел 8) |
| Префикс | `wp_` в файле условный | Мигратор заменяет на `$wpdb->prefix`; ссылки на пользователей — `$wpdb->users` (в мультисайте это общая таблица сети) |
| Создание | Собственный версионный мигратор, не `dbDelta()` | Раздел 10 |

**Ловушка нестрогого `sql_mode` в WordPress.** `wpdb` при подключении убирает из сессии `STRICT_*`,
`TRADITIONAL`, `ANSI`, `NO_ZERO_DATE`, `ONLY_FULL_GROUP_BY`. CHECK, UNIQUE и FK продолжают работать, но
**до** них MySQL молча «чинит» значения (проверено на 8.0.46):

* `-100` в `INT UNSIGNED` превращается в `0` (warning 1264). CHECK вида `price_amount > 0` в схеме нет;
* не-ASCII символ в `ascii`-колонке превращается в `?` (warning 1366): `AÜ1` и `AÖ1` становятся `A?1` и
  конфликтуют по UNIQUE;
* слишком длинная строка обрезается (warning 1265).

Поэтому все входящие значения валидируются в PHP до SQL: суммы — `int > 0`; внешние ID —
`/^[\x21-\x7E]{1,191}$/`; статусы — только через backed enum. Дополнительно можно включать строгий режим
только на время транзакций плагина в `Db::transaction()` и восстанавливать прежний `sql_mode` после неё:
глобально включать нельзя, потому что ядро WP и чужие плагины рассчитывают на нестрогий режим.

---

## 2. Список таблиц

| # | Таблица | Назначение | Растёт от |
|---|---|---|---|
| 1 | `wp_book_records` | Библиографическая запись: полный MARC 21 (`marc21_raw`) + денормализованные поля витрины и поиска. При продаже не удаляется | синхронизации |
| 2 | `wp_book_contributors` | Нормализованные персоны и организации из 1XX/7XX (дедупликация по authority ID или имени с датами) | синхронизации |
| 3 | `wp_book_record_contributors` | M:N запись ↔ персона с ролью (relator code) и порядком | синхронизации |
| 4 | `wp_book_subjects` | Нормализованные предметные рубрики 6XX с тезаурусом | синхронизации |
| 5 | `wp_book_record_subjects` | M:N запись ↔ рубрика | синхронизации |
| 6 | `wp_book_identifiers` | Все идентификаторы записи: ISBN (включая отменённые 020$z), ISSN, LCCN, OCLC, EAN, ISMN | синхронизации |
| 7 | `wp_book_items` | **Продаваемый экземпляр**: цена, состояние, локальный статус доступности, статус в источнике, факт продажи | синхронизации |
| 8 | `wp_book_images` | Изображения записи (обложка издания) или конкретного экземпляра (фото состояния) | синхронизации, админки |
| 9 | `wp_book_customer_profiles` | Данные покупателя, которым нужен строгий формат или индекс: телефон E.164, адреса по умолчанию, реквизиты B2B | оформления заказов |
| 10 | `wp_book_user_consents` | Юридическое доказательство согласия (оферта, ПДн, маркетинг): версия, хэш текста, время, IP. Только дописывается | оформления заказов |
| 11 | `wp_book_carts` | Корзина; не более одной открытой на пользователя | резервов |
| 12 | `wp_book_reservations` | История и текущее состояние резервов, номер попытки 1..3 | резервов |
| 13 | `wp_book_cart_items` | Позиция корзины = представление резерва + снимок цены | резервов |
| 14 | `wp_book_orders` | Заказ: публичный ID `uniundata_<uuid>`, суммы, снимок покупателя и адресов, сроки оплаты | checkout |
| 15 | `wp_book_order_items` | Позиции заказа со снимками названия, автора, ISBN, цены | checkout |
| 16 | `wp_book_payments` | Попытки оплаты у провайдера: idempotency key, ID провайдера, статус, last4 | checkout, повторной оплаты |
| 17 | `wp_book_payment_events` | Inbox webhook-ов **после** проверки подписи; дедупликация, переобработка | webhook-ов |
| 18 | `wp_book_sales` | Факт продажи; `UNIQUE (book_item_id)`: одна продажа на экземпляр | оплат |
| 19 | `wp_book_sync_runs` | Журнал прогонов синхронизации: счётчики, ошибки, курсор продолжения, heartbeat | cron |
| 20 | `wp_book_audit_log` | Аудит переходов статусов и важных действий (append-only) | всего |

Что **не** создаём:

* таблицу пользователей: используем `wp_users` (ID, email, display_name) и `wp_usermeta`
  (`first_name`, `last_name`, необязательный `middle_name`, настройки уведомлений);
* таблицу «товаров» в стиле WooCommerce: продаётся экземпляр, его карточка строится из
  `wp_book_records` + `wp_book_items`;
* custom post type для книг: 100k+ записей в `wp_posts`/`wp_postmeta` (EAV) дают медленные мета-запросы
  без нормальных индексов и без транзакционных инвариантов.

---

## 3. Таблицы: ключевые поля и индексы

В каждой таблице перечислены **все** индексы, включая PRIMARY. Колонка «Запрос» — какой запрос
обслуживает индекс. Четыре индекса создаёт сам MySQL под FOREIGN KEY, у которых нет подходящего явного
индекса; они помечены «авто» (имя индекса совпадает с именем constraint, проверено через
`information_schema.STATISTICS`).

Итого (по `information_schema.STATISTICS` после загрузки `sql/schema.sql`): 97 индексов — 20 PRIMARY,
22 UNIQUE (4 из них по generated columns), 4 FULLTEXT, 47 обычных явных и 4 автоматических под FK.
Плюс 45 CHECK-ограничений и 28 FOREIGN KEY.

### 3.1. `wp_book_records`

Ключевые поля:

* `source_name` + `source_record_id` — стабильный внешний ключ записи. По умолчанию
  `source_record_id = book_id` (контракт интеграции: один `book_id` — один экземпляр), но схема позволяет
  группировать экземпляры одного издания под одной записью.
* `source_format` — в каком формате пришло (`marcxml`, `marc_json`, `iso2709`, `mrk`); `marc21_format` — в
  каком хранится (`marcxml` или `marc_json`). ISO 2709 и MRK конвертируются при импорте: ISO 2709 бинарный
  (смещения в байтах, предел 99 999 байт на запись, возможна кодировка MARC-8 при Leader/09 = пробел), его
  неудобно хранить в текстовой колонке и разбирать повторно.
* `marc21_raw MEDIUMTEXT` — полная запись (до 16 МБ). Источник для повторного извлечения полей без
  обращения к источнику.
* `source_checksum CHAR(64)` — SHA-256 канонизированной записи (NFC, нормализованные пробелы, без поля
  005, которое некоторые источники переписывают при каждой выгрузке). Совпала — запись пропускается
  (`records_skipped`), FULLTEXT-индекс не трогается.
* `title VARCHAR(1000)` / `title_sort VARCHAR(255)` — отображение и сортировка. `title_sort` ограничен 255
  символами, чтобы влезть в B-tree (255 × 4 = 1020 байт < 3072).
* `is_active` — запись видна в каталоге. Снимается флагом (источник перестал присылать запись, ручное
  скрытие), строка не удаляется: на неё ссылаются экземпляры, заказы и продажи.
* `last_seen_sync_run_id` — в каком прогоне запись последний раз пришла из источника (логическая ссылка).

| Индекс | Колонки | Тип | Запрос |
|---|---|---|---|
| `PRIMARY` | `id` | PK | Карточка книги, JOIN из items/order_items/sales |
| `uq_records_source` | `source_name, source_record_id` | UNIQUE | Upsert при синхронизации: найти запись по внешнему ID; защита от дублей при повторном или параллельном импорте |
| `ix_records_isbn` | `isbn_primary` | обычный | Поиск по основному ISBN (ввод в строку поиска), подсказка дублей; `Using index` (проверено EXPLAIN) |
| `ix_records_marc001` | `marc_control_org, marc_control_number` | обычный | Диагностика и сопоставление записей по MARC 001/003 (например, одна запись пришла под двумя `book_id`) |
| `ix_records_active_title` | `is_active, title_sort` | обычный | Каталог «по алфавиту»: `WHERE is_active = 1 ORDER BY title_sort LIMIT 24` — без filesort; префиксный поиск коротких слов `title_sort LIKE 'ад%'` |
| `ix_records_active_author` | `is_active, main_author_sort` | обычный | Указатель авторов, фильтр `main_author_sort LIKE 'толстой%'`, сортировка по автору |
| `ix_records_active_year` | `is_active, publication_year` | обычный | Фильтр по годам: `publication_year BETWEEN 1850 AND 1860` — range + `Using index` |
| `ix_records_language` | `language_code` | обычный | Фасет «язык»: фильтр и `GROUP BY language_code` для счётчиков |
| `ix_records_sync` | `source_name, last_seen_sync_run_id` | обычный | Поиск записей, не пришедших в текущем полном проходе: `WHERE source_name = ? AND (last_seen_sync_run_id IS NULL OR last_seen_sync_run_id < ?)` — range по этому индексу (проверено) |
| `ft_records_main` | `title, subtitle, authors_text, series_title` | FULLTEXT | Основная строка поиска (раздел 11) |
| `ft_records_all` | + `responsibility_statement, subjects_text, description, publisher` | FULLTEXT | «Искать везде», включая аннотацию и рубрики |

### 3.2. `wp_book_contributors` и `wp_book_record_contributors`

* `contributor_key` = SHA-256 от authority ID (`$0`/`$1`: VIAF, GND, LC NAF), а при его отсутствии — от
  нормализованного `имя|даты` (`толстой, лев николаевич|1828-1910`). Даты нужны, чтобы не склеить
  однофамильцев.
* `name_display` — как в записи (`$a $b $c $d`), `name_sort` — нормализованная форма для указателя.
* В связке `role_code` — relator code из `$4` (`aut`, `edt`, `trl`, `ill`, `com`); при отсутствии `$4`
  выводится из `$e` по словарю («пер.» → `trl`, «ред.» → `edt`, «ил.» → `ill`); для 1XX по умолчанию `aut`.
  `role_code` входит в PK, поэтому одна персона может быть одновременно автором и иллюстратором книги.
* `position` — порядок вывода (как в записи).

| Таблица | Индекс | Колонки | Тип | Запрос |
|---|---|---|---|---|
| contributors | `PRIMARY` | `id` | PK | JOIN из связки |
| contributors | `uq_contributors_key` | `contributor_key` | UNIQUE | Upsert персоны при синхронизации: `INSERT … ON DUPLICATE KEY UPDATE` |
| contributors | `ix_contributors_sort` | `name_sort` | обычный | Указатель авторов A–Я, постраничный |
| contributors | `ft_contributors_name` | `name_display` | FULLTEXT | Автодополнение в фильтре «автор» |
| record_contributors | `PRIMARY` | `book_record_id, contributor_id, role_code` | PK | Список персон записи для карточки; служит индексом для `fk_rc_record` |
| record_contributors | `ix_rc_contributor` | `contributor_id, book_record_id` | обычный | «Все книги автора»: от персоны к записям; индекс для `fk_rc_contributor` |

### 3.3. `wp_book_subjects` и `wp_book_record_subjects`

* `subject_key` = SHA-256 от `тезаурус|нормализованная рубрика`. Одна и та же строка в LCSH и GND — разные
  рубрики.
* `heading` — рубрика с подразделениями через ` -- ` (`Россия -- История -- 19 в.`).
* `thesaurus` — из второго индикатора (0 → `lcsh`, 1 → `lcshac`, 2 → `mesh`, 3 → `nal`, 5 → `cash`,
  6 → `rvm`) или из `$2` при ind2 = 7 (`gnd`, `rero`, `nlr`…).

| Таблица | Индекс | Колонки | Тип | Запрос |
|---|---|---|---|---|
| subjects | `PRIMARY` | `id` | PK | JOIN |
| subjects | `uq_subjects_key` | `subject_key` | UNIQUE | Upsert рубрики при синхронизации |
| subjects | `ix_subjects_sort` | `heading_sort` | обычный | Рубрикатор A–Я |
| subjects | `ft_subjects_heading` | `heading` | FULLTEXT | Поиск рубрики в фильтре |
| record_subjects | `PRIMARY` | `book_record_id, subject_id` | PK | Рубрики записи для карточки; индекс для `fk_rs_record` |
| record_subjects | `ix_rs_subject` | `subject_id, book_record_id` | обычный | «Все книги рубрики»; индекс для `fk_rs_subject` |

### 3.4. `wp_book_identifiers`

* `id_value` — нормализованное значение (ISBN без дефисов, ISBN-10 → ISBN-13), `raw_value` — как в записи
  вместе с уточнением `$q` (`978-5-17-090000-0 (в пер.)`).
* `is_cancelled = 1` для 020$z / 022$z (ошибочный или отменённый номер). Такие ISBN не попадают в
  `isbn_primary`, но ищутся: на старых книгах часто напечатан именно ошибочный номер.

| Индекс | Колонки | Тип | Запрос |
|---|---|---|---|
| `PRIMARY` | `id` | PK | |
| `uq_identifiers_record` | `book_record_id, id_type, id_value` | UNIQUE | Идемпотентная перезапись идентификаторов записи при синхронизации; индекс для `fk_identifiers_record`. Уникальность **внутри записи**, не глобальная (раздел 12) |
| `ix_identifiers_lookup` | `id_type, id_value` | обычный | Поиск по любому ISBN/ISSN/OCLC: `WHERE id_type = 'isbn' AND id_value = ?` → записи (возможно несколько) |

### 3.5. `wp_book_items`

Ключевые поля:

* `external_item_id` — внешний `book_id`: **один физический экземпляр**. Две одинаковые книги имеют разные
  ID. `NULL` допустим для экземпляров, заведённых вручную.
* `inventory_number` — инвентарный номер магазина (если есть).
* `price_amount` + `currency` — текущая цена каталога. В корзину и заказ копируется снимок.
* `availability_status` — **локальный** статус продажи (7 значений, `docs/04-statuses.md`).
* `source_status` (`present`, `missing`, `withdrawn`) — что сообщает источник; хранится отдельно, чтобы
  синхронизация не перетирала защищённые локальные статусы (`reserved`, `checkout_pending`, `sold`,
  `blocked`). При освобождении экземпляр получает статус по `source_status`
  (`present` → `available`, `missing` → `sync_missing`, `withdrawn` → `withdrawn`).
* `status_changed_at` — время последней смены статуса (для витрины и диагностики).
* `sold_at` — заполнен тогда и только тогда, когда статус `sold` (`ck_items_sold_at`).
* `source_checksum` — SHA-256 полей экземпляра из источника (цена, состояние, статус) для пропуска
  неизменённых.

| Индекс | Колонки | Тип | Запрос |
|---|---|---|---|
| `PRIMARY` | `id` | PK | **`SELECT … FOR UPDATE` по id** — точка сериализации резерва, checkout, webhook и снятия резервов; `GET /catalog/availability?item_ids=1,2,3` |
| `uq_items_external` | `source_name, external_item_id` | UNIQUE | Upsert экземпляра при синхронизации; гарантия «один внешний `book_id` — одна строка». Несколько `NULL` допустимы |
| `uq_items_inventory` | `inventory_number` | UNIQUE | Поиск по инвентарному номеру в админке; запрет дублей. Collation `utf8mb4_unicode_520_ci`: `A-1` и `a-1` считаются одним номером |
| `ix_items_record_status` | `book_record_id, availability_status` | обычный | Карточка записи: её экземпляры и их статусы; «есть ли у записи экземпляр в продаже» для списка записей; индекс для `fk_items_record` |
| `ix_items_catalog` | `is_active, availability_status, price_amount` | обычный | Каталог экземпляров: `WHERE is_active = 1 AND availability_status = 'available' ORDER BY price_amount LIMIT 24` — `Using index` без filesort (проверено); фильтр по диапазону цены |
| `ix_items_sync` | `source_name, last_seen_sync_run_id` | обычный | Поиск экземпляров, не пришедших в текущем полном проходе (для `sync_missing`) |

### 3.6. `wp_book_images`

* Владелец — запись (`book_record_id`) **или** экземпляр (`book_item_id`): `ck_images_owner`. Для
  букинистики важны фото конкретного экземпляра (переплёт, дефекты, автограф).
* `attachment_id` — `wp_posts.ID`, если файл загружен в медиатеку (логическая ссылка; без вложения
  показывается `source_url`).

| Индекс | Колонки | Тип | Запрос |
|---|---|---|---|
| `PRIMARY` | `id` | PK | |
| `ix_images_record` | `book_record_id, position` | обычный | Галерея записи в порядке `position`; индекс для `fk_images_record` |
| `ix_images_item` | `book_item_id, position` | обычный | Галерея экземпляра; индекс для `fk_images_item` |

### 3.7. `wp_book_customer_profiles`

Что где хранится:

| Данные | Где | Почему |
|---|---|---|
| ID, email, `display_name`, пароль | `wp_users` | Ядро WP, логин, восстановление пароля |
| `first_name`, `last_name`, `middle_name` (необязательное) | `wp_usermeta` | Стандартные ключи WP, редактируются в профиле, не нужен индекс |
| Телефон | `wp_book_customer_profiles.phone_e164` | Строгий формат (CHECK E.164), индексный поиск, верификация (`phone_verified_at`) |
| Адреса по умолчанию | `default_shipping_address`, `default_billing_address` (JSON) | Только для автозаполнения формы. Источник истины для заказа — **снимок** в `wp_book_orders` |
| Реквизиты B2B | `company_name`, `vat_id` | Необязательные |
| Согласия | `wp_book_user_consents` | Юридическое доказательство, только дописывается |

| Индекс | Колонки | Тип | Запрос |
|---|---|---|---|
| `PRIMARY` | `user_id` | PK | Профиль текущего пользователя (`get_current_user_id()`); 1:0..1 с `wp_users` |
| `ix_profiles_phone` | `phone_e164` | обычный | Поиск покупателя по телефону в админке, проверка дублей |

### 3.8. `wp_book_user_consents`

* `consent_uuid` — технический идентификатор согласия (передаётся в заказ и в письма).
* `document_version` + `document_sha256` — какая редакция принята и её хэш (текст редакции хранится
  отдельно, например в опции `uniundata_terms_versions` / странице WP).
* `ip_address VARBINARY(16)` — `INET6_ATON()`, обнуляется по сроку хранения (минимизация ПДн).
  `user_agent_sha256` — только хэш.
* Отзыв — `withdrawn_at`; строка не удаляется.

| Индекс | Колонки | Тип | Запрос |
|---|---|---|---|
| `PRIMARY` | `id` | PK | `wp_book_orders.offer_consent_id` |
| `uq_consents_uuid` | `consent_uuid` | UNIQUE | Поиск доказательства по техническому ID |
| `ix_consents_user` | `user_id, consent_type, accepted_at` | обычный | Действующее согласие пользователя: `WHERE user_id = ? AND consent_type = 'offer' ORDER BY accepted_at DESC LIMIT 1` |

### 3.9. `wp_book_carts`

* `status`: `active`, `checkout_started` — корзина **открыта**; `converted_to_order`, `abandoned`,
  `expired` — закрыта (`closed_at` NOT NULL, `ck_carts_closed`).
* `expires_at` — `MIN(expires_at)` активных позиций, **только для отображения** таймера. Каждая позиция
  истекает независимо по своему сроку.
* `last_activity_at` — обновляется при reserve/remove/checkout; открытие страницы корзины его не трогает и
  резерв не продлевает.
* `open_cart_user_id` — STORED generated column (раздел 6).

| Индекс | Колонки | Тип | Запрос |
|---|---|---|---|
| `PRIMARY` | `id` | PK | |
| `uq_carts_one_open_per_user` | `open_cart_user_id` | UNIQUE | Инвариант «не более одной открытой корзины» **и** точка блокировки: `SELECT … WHERE open_cart_user_id = ? FOR UPDATE` (шаг 1 глобального порядка блокировок). Гонка двух первых резервов пользователя: второй `INSERT` корзины получает 1062 и перечитывает существующую |
| `ix_carts_user` | `user_id, created_at` | обычный | История корзин пользователя (админка, диагностика) |
| `ix_carts_status_activity` | `status, last_activity_at` | обычный | Cron: пустые корзины без активности 30 дней → `abandoned` |

### 3.10. `wp_book_reservations`

Ключевые поля:

* `attempt_no` 1..3 — номер попытки пользователя на этот экземпляр; `NULL` только у `released_by_admin`
  (такой резерв не считается попыткой). Новый номер = `1 + COUNT(*)` резервов этой пары
  `(user_id, book_item_id)` с `attempt_no IS NOT NULL`, считается под блокировкой строки экземпляра.
* `reserved_at`, `expires_at = reserved_at + 1 час` (`ck_reservations_window`). Не продлевается.
* `released_at` — момент выхода из `active` (любой финальный статус), `release_reason` — ASCII-код
  причины (`user_removed` по контракту для удаления из корзины; коды истечения, checkout и снятия
  администратором задаются сервисами).
* `order_id` — заказ, в который конвертирован резерв (`ck_reservations_order`).
* `active_book_item_id` — STORED generated column (раздел 6).

| Индекс | Колонки | Тип | Запрос |
|---|---|---|---|
| `PRIMARY` | `id` | PK | Блокировка конкретного резерва (admin release, expiry) |
| `uq_reservations_one_active_per_item` | `active_book_item_id` | UNIQUE | Второй рубеж после `FOR UPDATE` экземпляра: два активных резерва на экземпляр невозможны (1062 → 409 `uniundata_item_unavailable`). Также поиск активного резерва экземпляра: `WHERE active_book_item_id = ?` — const-доступ (идемпотентный повторный «Отложить») |
| `uq_reservations_attempt` | `user_id, book_item_id, attempt_no` | UNIQUE | Лимит трёх попыток на уровне БД (вместе с CHECK 1..3); покрывающий индекс для подсчёта попыток `WHERE user_id = ? AND book_item_id = ? AND attempt_no IS NOT NULL` (оптимизатор может выбрать и `ix_reservations_item_history` — оба дают единицы строк) |
| `ix_reservations_expiry` | `reservation_status, expires_at` | обычный | Cron снятия просроченных: `WHERE reservation_status = 'active' AND expires_at <= UTC_TIMESTAMP(6) ORDER BY expires_at LIMIT 200` — range, `Using index` (проверено) |
| `ix_reservations_user` | `user_id, reservation_status, expires_at` | обычный | Активные резервы пользователя (корзина, «мои резервы», лимит одновременных резервов) |
| `ix_reservations_item_history` | `book_item_id, reserved_at` | обычный | История резервов экземпляра в админке; индекс для `fk_reservations_item` (ведущая колонка `uq_reservations_attempt` — `user_id`, он для FK не годится) |
| `ix_reservations_cart` | `cart_id` | обычный | Резервы корзины; индекс для `fk_reservations_cart` |
| `ix_reservations_order` | `order_id` | обычный | Резервы заказа (отмена, поздний платёж); индекс для `fk_reservations_order` |

### 3.11. `wp_book_cart_items`

* Позиция — представление резерва: `reservation_id` UNIQUE, `expires_at` — копия срока резерва (корзина
  показывает таймер без JOIN).
* `unit_price_amount` + `currency` — снимок цены на момент резерва: если синхронизация изменит цену
  каталога, в корзине и в заказе останется цена, которую видел пользователь.
* `active_book_item_id` — STORED generated column: экземпляр может быть активной позицией только одной
  корзины, а значит, и не дважды в одной. Повторный резерв той же книги в ту же корзину после удаления
  разрешён: старая строка уже `removed`.

| Индекс | Колонки | Тип | Запрос |
|---|---|---|---|
| `PRIMARY` | `id` | PK | |
| `uq_cart_items_reservation` | `reservation_id` | UNIQUE | Связь 1:1 с резервом; индекс для `fk_cart_items_reservation` |
| `uq_cart_items_one_active_per_item` | `active_book_item_id` | UNIQUE | Экземпляр — активная позиция не более чем одной корзины (1062 → 409) |
| `ix_cart_items_cart` | `cart_id, status` | обычный | `GET /cart`: `WHERE cart_id = ? AND status = 'active'`; индекс для `fk_cart_items_cart` |
| `ix_cart_items_expiry` | `status, expires_at` | обычный | Cron: истёкшие активные позиции (страховка, если позиция разошлась с резервом) |
| `ix_cart_items_item` | `book_item_id` | обычный | История позиций экземпляра; индекс для `fk_cart_items_item` |

### 3.12. `wp_book_orders`

Ключевые поля:

* `public_order_id` — `uniundata_<UUID v4>` (CHECK формата). Единственный идентификатор, который видит
  пользователь и банк. Последовательный `id` наружу не отдаётся (перебор чужих заказов).
* `checkout_request_id` — `Idempotency-Key` запроса `POST /checkout`: повтор с тем же ключом возвращает тот
  же заказ.
* Суммы: `subtotal_amount`, `discount_amount`, `shipping_amount`, `tax_amount`, `total_amount`,
  `refunded_amount`; `ck_orders_total` проверяет арифметику с учётом `prices_include_tax`.
* Снимок покупателя: `customer_email`, `customer_phone`, `customer_first_name`, `customer_last_name`,
  `customer_middle_name`, `billing_address_json`, `shipping_address_json`, `shipping_method`. Правка
  профиля после заказа заказ не меняет.
* `offer_consent_id` — принятая редакция оферты.
* `payment_due_at` = момент checkout + `payment_ttl` + `grace` (опции, по умолчанию 30 + 10 минут); после
  него cron освобождает экземпляры неоплаченного заказа.
* `needs_attention` + `attention_reason` — флаг ручного разбора (`late_payment_conflict`,
  `duplicate_payment`, `amount_mismatch`). Флаг, а не статус: он сочетается с `paid` и другими статусами.

| Индекс | Колонки | Тип | Запрос |
|---|---|---|---|
| `PRIMARY` | `id` | PK | `SELECT … FOR UPDATE` заказа (шаг 5 порядка блокировок) |
| `uq_orders_public_id` | `public_order_id` | UNIQUE | `GET /orders/{public_order_id}`, `/pay`, `/cancel`; сопоставление webhook-а по внешнему ID заказа |
| `uq_orders_checkout_request` | `user_id, checkout_request_id` | UNIQUE | Идемпотентность `POST /checkout`: повтор → 1062 → вернуть существующий заказ. Ключ уникален в пределах пользователя: чужой ключ не раскроет чужой заказ |
| `ix_orders_user` | `user_id, created_at` | обычный | `GET /orders` (свои заказы, новые сверху) |
| `ix_orders_payment_due` | `status, payment_due_at` | обычный | Cron истечения: `WHERE status IN ('draft','pending_payment','payment_processing','payment_failed') AND payment_due_at <= UTC_TIMESTAMP(6)`. Из-за `IN` по первой колонке `ORDER BY payment_due_at` даёт filesort по объединению диапазонов — допустимо, просроченных заказов единицы |
| `ix_orders_status_created` | `status, created_at` | обычный | Админка: списки заказов по статусу и дате |
| `ix_orders_attention` | `needs_attention, updated_at` | обычный | Очередь ручного разбора: `WHERE needs_attention = 1 ORDER BY updated_at` |
| `ix_orders_cart` | `cart_id` | обычный | Заказ по корзине; индекс для `fk_orders_cart` |
| `fk_orders_consent` | `offer_consent_id` | авто (FK) | Создан MySQL для `fk_orders_consent`; запросы «заказы по согласию» редки |

### 3.13. `wp_book_order_items`

* Снимки: `title_snapshot`, `subtitle_snapshot`, `author_snapshot`, `isbn_snapshot`,
  `publisher_snapshot`, `publication_year_snapshot`, `condition_snapshot`, `cover_url_snapshot`,
  `item_identifier_snapshot` (внешний ID / инвентарный номер), `unit_price_amount`, `currency`.
* `quantity` всегда 1 (`ck_order_items_quantity`): уникальный экземпляр не масштабируется.

| Индекс | Колонки | Тип | Запрос |
|---|---|---|---|
| `PRIMARY` | `id` | PK | `wp_book_sales.order_item_id` |
| `uq_order_items_item` | `order_id, book_item_id` | UNIQUE | Экземпляр не может быть дважды в одном заказе; позиции заказа по `order_id`; индекс для `fk_order_items_order` |
| `ix_order_items_book_item` | `book_item_id` | обычный | В каких заказах был экземпляр (поздний платёж: «не в чужом ли checkout»); индекс для `fk_order_items_item` |
| `ix_order_items_record` | `book_record_id` | обычный | Отчёты по изданиям; индекс для `fk_order_items_record` |
| `fk_order_items_reservation` | `reservation_id` | авто (FK) | Создан MySQL для `fk_order_items_reservation` |

### 3.14. `wp_book_payments`

* `attempt_no` — номер попытки оплаты заказа; повторная оплата после `payment_failed` — новая строка.
* `idempotency_key` (UUID) — генерируется **до** HTTP-запроса к банку и передаётся ему: если ответ на
  `createSession` потерян, повтор с тем же ключом не создаст вторую сессию.
* `provider_payment_id` — ID платежа у провайдера, `NULL` до ответа банка.
* `provider_status` — сырой статус провайдера (для разбора), `status` — наш нормализованный.
* `card_brand`, `card_last4` — только бренд и последние 4 цифры (PCI DSS); PAN, CVV, срок карты не
  хранятся никогда.
* `session_expires_at` — TTL сессии у банка (= `payment_ttl`).

| Индекс | Колонки | Тип | Запрос |
|---|---|---|---|
| `PRIMARY` | `id` | PK | `FOR UPDATE` платежа (шаг 6 порядка блокировок); `wp_book_sales.payment_id` |
| `uq_payments_idempotency` | `idempotency_key` | UNIQUE | Защита от двойного создания сессии при повторе |
| `uq_payments_provider_id` | `provider, provider_payment_id` | UNIQUE | Webhook: найти платёж по ID провайдера; один платёж провайдера — одна строка. Несколько `NULL` допустимы |
| `uq_payments_attempt` | `order_id, attempt_no` | UNIQUE | Последовательные попытки заказа без дублей номера; все платежи заказа; индекс для `fk_payments_order` |
| `ix_payments_status` | `status, session_expires_at` | обычный | Опрос «зависших» платежей: `WHERE status IN ('created','pending','processing') AND session_expires_at < UTC_TIMESTAMP(6)` |

### 3.15. `wp_book_payment_events`

* Строка вставляется **только после проверки подписи** webhook-а: иначе злоумышленник мог бы «занять»
  `provider_event_id` настоящего события и заставить настоящее считаться дублем.
* `provider_event_id` — ID события у провайдера; если провайдер его не даёт — SHA-256 канонического тела.
* `payload_redacted` — тело без PAN/CVV/секретов/лишних ПДн; `payload_sha256` — хэш исходного тела.
* `attempts` — сколько раз событие обрабатывалось (повторные доставки банка).

| Индекс | Колонки | Тип | Запрос |
|---|---|---|---|
| `PRIMARY` | `id` | PK | |
| `uq_payment_events_provider` | `provider, provider_event_id` | UNIQUE | **Идемпотентность webhook-а**: повторная доставка → 1062 → читаем существующую строку и смотрим её `processing_status` |
| `ix_payment_events_payment` | `payment_id, received_at` | обычный | История событий платежа; индекс для `fk_payment_events_payment` |
| `ix_payment_events_status` | `processing_status, received_at` | обычный | Очередь переобработки: `received`/`failed` старше N минут |
| `fk_payment_events_order` | `order_id` | авто (FK) | Создан MySQL для `fk_payment_events_order`; события заказа в админке |

### 3.16. `wp_book_sales`

* Одна строка — одна успешная продажа экземпляра. Синхронизация каталога таблицу не трогает.
* `refunded_at` — возврат денег. Экземпляр в продажу **не** возвращается (`sold` — терминальный статус).

| Индекс | Колонки | Тип | Запрос |
|---|---|---|---|
| `PRIMARY` | `id` | PK | |
| `uq_sales_book_item` | `book_item_id` | UNIQUE | **Одна продажа на экземпляр** — последний рубеж против двойной продажи (дубль webhook-а, поздний платёж, ошибка кода); индекс для `fk_sales_item` |
| `uq_sales_order_item` | `order_item_id` | UNIQUE | Повторная обработка одного и того же заказа не создаст вторую строку; индекс для `fk_sales_order_item` |
| `ix_sales_order` | `order_id` | обычный | Продажи заказа; индекс для `fk_sales_order` |
| `ix_sales_user` | `user_id, sold_at` | обычный | «Мои покупки», отчёты по покупателю |
| `ix_sales_sold_at` | `sold_at` | обычный | Отчёты за период |
| `ix_sales_record` | `book_record_id` | обычный | Продажи по изданию; индекс для `fk_sales_record` |
| `fk_sales_payment` | `payment_id` | авто (FK) | Создан MySQL для `fk_sales_payment` |

### 3.17. `wp_book_sync_runs`

* `triggered_by`: `cron`, `wp_cli`, `admin`, `retry`. `status`: `running` → `succeeded`/`partial`/
  `failed`/`aborted` (`docs/04-statuses.md`).
* `heartbeat_at` — обновляется после каждого пакета; «зависший» `running` со старым heartbeat признаётся
  мёртвым (`aborted`).
* `source_cursor` — точка продолжения (страница/токен источника) для безопасного повторного запуска.
* Счётчики: `records_received/created/updated/skipped`, `items_created/updated/skipped/conflicts/missing/
  withdrawn`, `errors_count`; `error_log` JSON — не более N последних ошибок `{external_id, code, message}`.
* `running_source` — STORED generated column: не более одного `running` на источник. Дублирует
  `GET_LOCK()` на уровне данных: именованная блокировка снимается при обрыве соединения, а строка остаётся
  и видна в админке.

| Индекс | Колонки | Тип | Запрос |
|---|---|---|---|
| `PRIMARY` | `id` | PK | |
| `uq_sync_runs_one_running` | `running_source` | UNIQUE | Второй параллельный запуск того же источника получает 1062 |
| `ix_sync_runs_source` | `source_name, started_at` | обычный | `GET /admin/sync/runs`, последний прогон источника: `ORDER BY started_at DESC LIMIT 1` |

### 3.18. `wp_book_audit_log`

* Только `INSERT`. `actor_type`: `user`, `admin`, `system`, `cron`, `webhook`, `sync`, `cli`.
* `entity_type` + `entity_id` — полиморфная ссылка; `from_status`/`to_status` — переход;
  `request_id` — корреляция с логами веб-сервера; `context` — JSON без секретов, PAN и лишних ПДн.
* FK нет намеренно: аудит не должен блокировать бизнес-строки FK-проверками, а таблицу можно
  партиционировать или архивировать по `occurred_at` (партиционированные таблицы InnoDB не поддерживают FK).

| Индекс | Колонки | Тип | Запрос |
|---|---|---|---|
| `PRIMARY` | `id` | PK | |
| `ix_audit_entity` | `entity_type, entity_id, occurred_at` | обычный | История сущности: «что происходило с экземпляром 123» |
| `ix_audit_action` | `action, occurred_at` | обычный | Все события типа за период: `order.paid` за сегодня |
| `ix_audit_actor` | `actor_user_id, occurred_at` | обычный | Действия пользователя/администратора |

---

## 4. MARC 21 → колонки

Извлечение выполняет `Sync\MarcExtractor` из `marc21_raw`. Общие правила для всех строковых полей:

1. Перед извлечением запись приводится к UTF-8 (MARC-8 конвертируется, если Leader/09 = пробел) и к
   **NFC** (`Normalizer::normalize($s, Normalizer::FORM_C)`, расширение `intl`): после конвертации из
   MARC-8 диакритика часто хранится комбинирующими символами.
2. Подполя одного поля склеиваются через пробел; удаляется завершающая ISBD-пунктуация (` /`, ` :`, ` ;`,
   ` =`, `,`) и финальная точка, если она не часть сокращения (`ил.`, `т.`, `Т. 1.`).
3. Значения длиннее колонки обрезаются в PHP (`mb_substr`) с `…`, а не полагаются на нестрогий режим MySQL.
4. Повторяющиеся поля обрабатываются в порядке записи.

| MARC 21 | Колонка | Правило |
|---|---|---|
| Leader/06 | `record_type` | Как есть: `a` — текст, `c` — ноты, `e` — карты, `t` — рукописный текст… |
| Leader/07 | `bib_level` | `m` — монография, `s` — сериальное, `a` — составная часть… |
| 001 | `marc_control_number` | Как есть. **Не** является `source_record_id`: внешний стабильный ключ — `book_id` интеграции |
| 003 | `marc_control_org` | Код организации, присвоившей 001 |
| 245 $a | `title` | $a, а при наличии $n/$p (номер и название части) — `$a. $n, $p`: иначе тома многотомника неотличимы в каталоге, а продаются они поштучно |
| 245 ind2 + $a | `title_sort` | Пропустить **ind2** первых символов (число незначащих символов: `245 14 $a The Lord…` → `Lord…`); удалить фрагменты между управляющими символами NSB/NSE (U+0098…U+009C), которые некоторые каталоги используют вместо ind2; NFC → `mb_strtolower` → всё, кроме букв и цифр, в пробел → схлопнуть пробелы → первые 255 символов |
| 245 $b | `subtitle` | Без завершающего ` /` |
| 245 $c | `responsibility_statement` | Как в записи («Л. Н. Толстой ; пер. с англ. …») |
| 100/110/111 + 700/710/711 | `authors_text` | Для вывода: `$a $b $c $d` каждой персоны, роль из `$e`/`$4` в скобках, через `; `, до 1000 символов |
| 100 $a (или 110/111 $a) | `main_author_sort` | Нормализация как у `title_sort`; `NULL`, если 1XX нет (сортировка тогда по названию) |
| 100/110/111/700/710/711 | `wp_book_contributors`, `wp_book_record_contributors` | Ключ дедупликации, роль, позиция — см. 3.2. `marc_tag` сохраняется |
| 020 $a | `isbn_primary`, `wp_book_identifiers` | Убрать уточнения в скобках (старая практика писать «(в пер.)» прямо в $a), всё кроме цифр и `X`; проверить контрольную цифру; ISBN-10 → ISBN-13 (`978` + 9 цифр + пересчитанная контрольная цифра EAN-13). `isbn_primary` = первый **валидный** $a. Невалидный $a — только в identifiers |
| 020 $z | `wp_book_identifiers.is_cancelled = 1` | Отменённый/ошибочный ISBN: ищется, но не основной |
| 020 $q | `wp_book_identifiers.raw_value` | Уточнение («hbk.», «в пер.») хранится в исходной строке |
| 022 $a/$z, 010 $a, 035 $a, 024 $a | `wp_book_identifiers` | ISSN, LCCN, OCLC (`(OCoLC)…` из 035), EAN/ISMN из 024 по ind1 |
| 264 (ind2 = 1) / 260 | `publisher` ($b), `publication_place` ($a) | Приоритет: 264 с ind2 = 1 (публикация) → 260 → 264 с ind2 = 0/2/3. 264 ind2 = 4 (copyright) — только как источник года, если других нет |
| 264 $c / 260 $c | `publication_date_text`, `publication_year` | Текст сохраняется как есть (`[1905?]`, `c1999`, `MDCCCXII`, `189-?`). Год — первое четырёхзначное число 1400–2100 по регулярному выражению `(?<!\d)(1[4-9]\d{2}\|20\d{2})(?!\d)` (`\b` не годится: в `c1999` между `c` и `1` нет границы слова) |
| 008/06–10 | `publication_year` (резерв) | Если в $c года нет: Date1 (008/07–10), если тип даты 008/06 не `b`/`n`/`\|` и Date1 — четыре цифры (`19uu` → `NULL`). Год вне 1400–2100 → `NULL` (иначе CHECK `ck_records_year` даст 3819 и сорвёт пакет) |
| 250 $a ($b) | `edition_statement` | «2-е изд., испр. и доп.» |
| 041 $a | `language_code` | Первый код; в старых записях несколько кодов слиты в одном $a (`engfre`) — берём первые 3 символа. Если ind2 = 7 и `$2` — не коды MARC (например, ISO 639-3) — берём 008/35–37. Проверка `^[a-z]{3}$` |
| 008/35–37 | `language_code` (резерв) | Если 041 нет. Пробелы и `\|\|\|` → `NULL` |
| 300 $a $b $c $e | `physical_description` | С ISBD-пунктуацией записи: «312 с. : ил. ; 22 см» |
| 490 $a $v / 830 $a $v | `series_title` | 490 (серия как на издании) с `; $v`; если 490 нет — 830 (авторитетная форма) |
| 600/610/611/630/648/650/651/655 | `subjects_text`, `wp_book_subjects` | Рубрика = $a + подразделения $v $x $y $z через ` -- `; рубрики в `subjects_text` через `; ` без дублей. 653 (неконтролируемые ключевые слова) — только в `subjects_text` |
| 520 $a ($b) | `description` | Несколько 520 — через пустую строку |
| 505 $a (или $g $t $r) | `contents_note` | Расширенное 505 склеивается через ` -- ` |
| 856 $u | `source_url`, `cover_url` (резерв) | Основной источник URL — данные интеграции; 856 — только если интеграция их не дала |

Ключевые реализации нормализации (PHP 8.3/8.4, проверены на тестовых значениях):

```php
/** 020$a → ISBN-13 или null, если контрольная цифра неверна. */
function normalize_isbn(string $raw): ?string
{
    $s = strtoupper((string) preg_replace('/[^0-9Xx]/', '', (string) preg_replace('/\(.*?\)/', '', $raw)));
    if (preg_match('/^\d{9}[\dX]$/', $s) === 1) {          // ISBN-10: сумма (10..1) * d ≡ 0 (mod 11)
        $sum = 0;
        for ($i = 0; $i < 10; $i++) {
            $sum += (10 - $i) * ($s[$i] === 'X' ? 10 : (int) $s[$i]);
        }
        if ($sum % 11 !== 0) {
            return null;
        }
        $core = '978' . substr($s, 0, 9);
    } elseif (preg_match('/^97[89]\d{10}$/', $s) === 1) {   // ISBN-13 (979 не имеет ISBN-10)
        $core = substr($s, 0, 12);
    } else {
        return null;
    }
    $sum = 0;
    for ($i = 0; $i < 12; $i++) {                            // EAN-13: веса 1, 3, 1, 3…
        $sum += (int) $core[$i] * ($i % 2 === 0 ? 1 : 3);
    }
    $isbn13 = $core . (string) ((10 - $sum % 10) % 10);
    return strlen($s) === 13 && $isbn13 !== $s ? null : $isbn13;
}
// '0-306-40615-2' → '9780306406157'; '080442957X' → '9780804429573'; '9780306406158' → null

/** 264$c / 260$c → год. '[1905?]' → 1905, 'c1999' → 1999, '1890-1895' → 1890, 'MDCCCXII' → null */
function extract_year(?string $dateText): ?int
{
    return $dateText !== null && preg_match('/(?<!\d)(1[4-9]\d{2}|20\d{2})(?!\d)/', $dateText, $m) === 1
        ? (int) $m[1] : null;
}
```

---

## 5. Что не раскладываем в колонки и почему

Полная запись всегда лежит в `marc21_raw`. В колонки выносится только то, по чему ищут, фильтруют,
сортируют или что выводится в списках без разбора XML.

| Поля MARC | Где остаются | Почему не колонки |
|---|---|---|
| 006, 007, 008 (кроме даты и языка) | raw | Кодированные позиции (иллюстрации, аудитория, форма) не участвуют в поиске витрины |
| 040, 042, 050, 060, 080, 082, 084 | raw | Источник каталогизации и классификации. Для фасета по УДК (080) / ББК (084) — отдельная таблица по образцу `wp_book_subjects`, когда появится требование |
| 130, 240 (унифицированное заглавие), 246 (варианты заглавия) | raw | Кандидаты на добавление в FULLTEXT, если понадобится поиск по оригинальному заглавию переводов («War and peace» → «Война и мир») |
| 5XX, кроме 505/520: 500, 504, 546, 561 (провенанс), 563 (переплёт), 590 (локальные примечания: автографы, экслибрисы) | raw | Для букинистики важны **на карточке**, но не для поиска. Карточка разбирает их из raw и кэширует в object cache (`wp_cache_*`) по ключу `record_id + source_checksum` |
| 76X–78X (связи), 800–811 | raw | Навигация по связанным изданиям — отдельная задача |
| 852, 876–878 (местонахождение, экземпляры) | raw / данные интеграции | Экземплярные данные приходят от интеграции в `wp_book_items`, а не из библиографической записи |
| 880 (параллельные поля в оригинальной графике) | raw | Какую форму считать основной (оригинальную графику или транслитерацию из 245) — решение по реальным данным источника; при необходимости экстрактор берёт 880, связанное с 245 через `$6` |
| 9XX (локальные поля источника) | raw | Семантика зависит от источника |

Почему не раскладываем «всё»: поля и подполя MARC повторяются и зависят от индикаторов. Обобщённая
EAV-таблица «тег/индикаторы/подполе» даёт 50–150 строк на запись (10–15 млн строк на 100k записей) и не
ускоряет ни один нужный запрос. Хранение raw позволяет при изменении правил извлечения перезапустить
экстрактор по всем записям (WP-CLI) **без** обращения к источнику, а `source_checksum` не меняется от
изменения правил.

---

## 6. Уникальность «только среди активных»: generated columns

### Механизм

Нужно правило «не более одного **активного** резерва на экземпляр», при этом истёкших и отменённых
резервов на тот же экземпляр может быть сколько угодно. Решение в схеме:

```sql
active_book_item_id BIGINT UNSIGNED
    GENERATED ALWAYS AS (IF(reservation_status = 'active', book_item_id, NULL)) STORED,
UNIQUE KEY uq_reservations_one_active_per_item (active_book_item_id)
```

* У активной строки колонка равна `book_item_id`, у всех остальных — `NULL`.
* UNIQUE-индекс InnoDB допускает **любое количество `NULL`** (`NULL` не равен `NULL`), поэтому
  неактивные строки не конкурируют между собой, а две активные с одним экземпляром дают
  `ERROR 1062 Duplicate entry '…' for key 'wp_book_reservations.uq_reservations_one_active_per_item'`
  (проверено).
* `UPDATE … SET reservation_status = 'expired'` пересчитывает колонку в `NULL` и освобождает «слот» при
  COMMIT.
* InnoDB проверяет уникальность **сразу**, на уровне оператора, а не в конце транзакции: в одной
  транзакции сначала закрываем старый резерв, потом вставляем новый.

Тот же приём в четырёх местах:

| Таблица | Generated column | Условие «активности» | Инвариант |
|---|---|---|---|
| `wp_book_carts` | `open_cart_user_id` | `status IN ('active', 'checkout_started')` | Одна открытая корзина на пользователя |
| `wp_book_reservations` | `active_book_item_id` | `reservation_status = 'active'` | Один активный резерв на экземпляр |
| `wp_book_cart_items` | `active_book_item_id` | `status = 'active'` | Экземпляр — активная позиция одной корзины |
| `wp_book_sync_runs` | `running_source` | `status = 'running'` | Один идущий прогон на источник |

Это **второй рубеж**. Первый — `SELECT … FOR UPDATE` строки экземпляра (или корзины) в транзакции:
параллельный запрос ждёт блокировку и после неё видит уже изменённый статус. UNIQUE страхует от ошибки в
коде: новый путь, где забыли блокировку, админский скрипт, ручной SQL.

### Почему не частичный индекс

`CREATE UNIQUE INDEX … WHERE status = 'active'` есть в PostgreSQL и SQLite, но **не в MySQL**.

### Сравнение вариантов

| Вариант | Как выглядит | Плюсы | Минусы |
|---|---|---|---|
| **STORED generated column + UNIQUE** (выбран) | Колонка + `UNIQUE KEY (active_book_item_id)` | Видна в `SHOW CREATE TABLE`, `DESCRIBE`, `SELECT *`, дампах; к ней можно обращаться по имени: `WHERE active_book_item_id = ?` — const-доступ, индекс используется гарантированно; понятное имя в ошибке 1062 | +8 байт на строку (несущественно); добавить STORED колонку в существующую таблицу — только `ALGORITHM=COPY`; колонку нельзя писать (`INSERT` со значением даёт ошибку 3105, проверено) — `$wpdb->insert()` не должен её передавать |
| VIRTUAL generated column + UNIQUE | То же с `VIRTUAL` | Значение хранится только в индексе; колонку можно добавить без перестроения | Выигрыш 8 байт на строку при меньшей очевидности; для критичного инварианта предпочитаем материализованное значение |
| **Функциональный индекс** (8.0.13+) | `UNIQUE KEY ((IF(reservation_status = 'active', book_item_id, NULL)))` | Нет лишней колонки | Реализован скрытой VIRTUAL-колонкой, которой нет в `information_schema.COLUMNS` (проверено на 8.0.46). Оптимизатор использует индекс, только если в `WHERE` повторено **то же выражение**: `WHERE IF(st = 'active', book_item_id, NULL) = 5` → const, а естественное `WHERE st = 'active' AND book_item_id = 5` → полный скан (проверено EXPLAIN). Выражение в `SHOW CREATE TABLE` видно, но смысл менее очевиден |
| Триггер BEFORE INSERT/UPDATE | Проверка `SELECT COUNT(*)` в триггере | — | Без блокировок не защищает от гонки; скрытая логика; права `TRIGGER` (и при бинлоге — `SUPER`/`log_bin_trust_function_creators`) часто урезаны на хостинге и в managed MySQL |
| Только `FOR UPDATE` в коде | — | Достаточно при дисциплине | Одна ошибка в коде молча создаст два активных резерва |

STORED выбран ради **явности**: инвариант читается из схемы, проверяется по имени колонки и видим в
`SHOW CREATE TABLE` без знания внутренностей функциональных индексов.

---

## 7. CHECK-ограничения

MySQL **проверяет** CHECK начиная с 8.0.16; более ранние версии синтаксис принимали и молча игнорировали.
Мигратор при активации плагина проверяет `SELECT VERSION()` и отказывается работать ниже 8.0.16. MariaDB
не поддерживается без адаптации (другая семантика JSON, generated columns и CHECK).

| Группа | Ограничения | Что гарантирует |
|---|---|---|
| Перечисления статусов | `ck_items_status`, `ck_items_source_status`, `ck_reservations_status`, `ck_carts_status`, `ck_cart_items_status`, `ck_orders_status`, `ck_payments_status`, `ck_payment_events_status`, `ck_sync_runs_status`, `ck_sync_runs_trigger`, `ck_audit_actor`, `ck_records_source_format`, `ck_records_marc_format`, `ck_items_condition`, `ck_contributors_type`, `ck_identifiers_type`, `ck_images_role`, `ck_consents_type` | Опечатка в статусе — ошибка 3819, а не мусор в данных (в отличие от `ENUM` в нестрогом режиме WP) |
| «Статус ⇔ поле» | `ck_items_sold_at` (`sold` ⇔ `sold_at` NOT NULL), `ck_reservations_released` (`active` ⇔ `released_at` IS NULL), `ck_reservations_attempt_admin` (`released_by_admin` ⇔ `attempt_no` IS NULL), `ck_reservations_order` (`converted_to_order` ⇒ `order_id`), `ck_carts_closed`, `ck_cart_items_closed`, `ck_orders_paid_at` (оплаченные статусы ⇒ `paid_at`), `ck_payments_succeeded` (`succeeded`/`refunded` ⇒ `succeeded_at`), `ck_sync_runs_finished` (`running` ⇔ `finished_at` IS NULL) | Нельзя сменить статус, «забыв» сопутствующее поле, и наоборот |
| Диапазоны | `ck_reservations_attempt_range` (1..3), `ck_records_year` (1400..2100), `ck_order_items_quantity` (= 1), `ck_records_is_active`, `ck_items_is_active` | Лимит трёх попыток на уровне БД; количество всегда 1 |
| Форматы | `ck_items_currency`, `ck_cart_items_currency`, `ck_orders_currency`, `ck_order_items_currency`, `ck_payments_currency`, `ck_sales_currency` (`^[A-Z]{3}$`), `ck_payments_last4`, `ck_profiles_phone` (E.164), `ck_orders_public_id` (`uniundata_` + UUID v4) | Мусор не попадает в данные (см. оговорку о регистре ниже) |
| Арифметика | `ck_orders_total` (итог = подытог − скидка + доставка [+ налог, если цены без налога]), `ck_orders_refund` (возвращено ≤ итога), `ck_reservations_window` (`expires_at > reserved_at`) | Суммы не расходятся; в `ck_orders_total` вычитание идёт в `SIGNED`, чтобы не получить ошибку переполнения `UNSIGNED` |
| Владелец | `ck_images_owner` | У изображения есть запись или экземпляр |

Приёмы:

* «A тогда и только тогда, когда B» записывается как `(A) = (B)`: обе части — булевы выражения над
  `NOT NULL` колонками или `IS NULL`, поэтому никогда не дают `NULL`.
* CHECK, вернувший `NULL`, считается выполненным. Поэтому `attempt_no BETWEEN 1 AND 3` пропускает
  `NULL` у `released_by_admin`.

Чего CHECK не умеет и чем это закрыто:

* не видит других строк и таблиц (нет подзапросов) → уникальности через UNIQUE, связи через FK;
* не допускает недетерминированные функции (`NOW()`, `UTC_TIMESTAMP()`) → «резерв ещё не истёк» проверяет
  код под блокировкой;
* **не видит старого значения** при `UPDATE` → допустимость перехода `A → B` проверяется в коде условием
  `WHERE status IN (…)` и числом затронутых строк (`docs/04-statuses.md`, раздел «Защита переходов»).

Обработка ошибок: `3819 Check constraint 'ck_…' is violated.` Имя ограничения извлекается из текста
ошибки (`$wpdb->last_error`). `ck_reservations_attempt_range` → 409 `uniundata_reservation_limit_reached`
(контракт); любое другое — это ошибка в коде → 500 `uniundata_internal` + запись в аудит.

**Оговорка о регистре.** Колонки `CHARACTER SET ascii` по умолчанию получают collation
`ascii_general_ci`, нечувствительный к регистру, и `REGEXP` в CHECK наследует его: `'eur'` проходит
`ck_items_currency`, а `public_order_id` с заглавными hex-цифрами проходит `ck_orders_public_id`
(проверено на 8.0.46). Пока схема не исправлена, PHP обязан приводить валюту к верхнему регистру, а UUID —
к нижнему. Исправление в схеме: `REGEXP_LIKE(currency, '^[A-Z]{3}$', 'c')` или `COLLATE ascii_bin` у этих
колонок.

---

## 8. FOREIGN KEY без каскадов

Между таблицами плагина объявлено 28 FOREIGN KEY, все без `ON DELETE` / `ON UPDATE`, то есть с
поведением `RESTRICT` (в InnoDB `NO ACTION` = `RESTRICT`, проверяется сразу).

Почему без каскадов:

* бизнес-строки не удаляются никогда (ТЗ: корзины, резервы, заказы, платежи, продажи, проданные
  экземпляры). `ON DELETE CASCADE` превратил бы случайный `DELETE` записи книги в молчаливое уничтожение
  истории продаж; `RESTRICT` превращает его в ошибку 1451 (проверено: удалить экземпляр, на который
  ссылается резерв, нельзя);
* `ON DELETE SET NULL` терял бы связь заказа с экземпляром;
* PK не меняются, поэтому `ON UPDATE` не нужен.

Что даёт FK:

* нельзя создать резерв на несуществующий экземпляр, позицию заказа без заказа, продажу без платежа;
* InnoDB требует индекс на колонке потомка и создаёт его сам, если подходящего нет. В схеме таких четыре:
  `fk_orders_consent`, `fk_order_items_reservation`, `fk_payment_events_order`, `fk_sales_payment`
  (раздел 3).

Цена FK, важная для конкурентности:

* **FK-проверка ставит S-блокировку на родительскую строку.** `INSERT` в потомка ждёт, если родитель
  X-заблокирован другой транзакцией (проверено: вставка экземпляра ждёт, пока другая транзакция держит
  `UPDATE` его записи, и падает с 1205 по таймауту). В нашем глобальном порядке блокировок (корзина →
  экземпляры → резервы → позиции корзины → заказ → платежи → события) родители, в которые идут вставки,
  уже X-заблокированы той же транзакцией или только что ею созданы, поэтому конфликтов нет.
* Исключение — `wp_book_records`: `INSERT` в `wp_book_order_items` (checkout) и `wp_book_sales` (webhook)
  ставит S-блокировку на запись книги **после** блокировки экземпляров. Если синхронизация в одной
  транзакции сначала обновит запись (X), а потом экземпляр (X), возможен deadlock 1213. `Db::transaction()`
  его переживёт (повтор), но провоцировать не нужно: синхронизация обновляет `wp_book_records` и
  `wp_book_items` **разными короткими транзакциями** (или в порядке «экземпляры → запись»).
* Порядок создания таблиц важен: `fk_reservations_order` добавляется `ALTER TABLE` после создания
  `wp_book_orders` (циклическая ссылка резерв ↔ заказ). Удаление плагина (`uninstall.php`, только по явному
  согласию администратора) — в обратном порядке или с `SET FOREIGN_KEY_CHECKS = 0`.

## 9. Почему нет FK на wp_users

`user_id` во всех таблицах плагина ссылается на `wp_users.ID` **логически**, без FOREIGN KEY:

1. **Удаление пользователя в WordPress.** `wp_delete_user()` выполняет `DELETE FROM wp_users` и не
   обрабатывает ошибку FK. С `RESTRICT` удаление упадёт на середине: метаданные уже удалены, строка
   пользователя осталась, администратор видит невнятную ошибку. С `CASCADE` удаление пользователя молча
   сотрёт его заказы и продажи, которые обязаны храниться по срокам бухгалтерского и налогового учёта.
2. **Таблица ядра — не наша.** Миграции ядра, плагины переноса и staging (пересоздание `wp_users` через
   `DROP`/`CREATE`), импорт пользователей не ожидают входящих FK и падают на них. Плагин не должен менять
   поведение операций над таблицами ядра.
3. **Мультисайт и общие пользователи.** В мультисайте `wp_users` общая для сети (`$wpdb->users`), а таблицы
   плагина могут быть на сайт; при `CUSTOM_USER_TABLE` таблица пользователей может быть общей для
   нескольких установок. FK через такие границы хрупок или невозможен.
4. **Снимки делают заказ самодостаточным.** Email, имя, телефон и адреса скопированы в заказ, поэтому
   заказ читается и без строки `wp_users`.

Чем заменяем FK:

* все записи с `user_id` создаются только из `get_current_user_id()` (или проверенного админом ID), а не
  из параметров запроса;
* хук `delete_user` (и `wpmu_delete_user`) срабатывает **до** удаления строки: плагин пишет аудит,
  снимает активные резервы пользователя (экземпляры освобождаются так же, как при снятии администратором)
  и закрывает его открытую корзину. Заказы и продажи остаются с прежним `user_id` как историческим
  идентификатором;
* экспорт и удаление ПДн по запросу (GDPR) — через штатные `wp_privacy_personal_data_exporters` /
  `wp_privacy_personal_data_erasers`: обезличиваются снимки в заказах после истечения сроков хранения;
* регулярная проверка «висячих» ссылок:
  `SELECT o.id FROM wp_book_orders o LEFT JOIN wp_users u ON u.ID = o.user_id WHERE u.ID IS NULL`.

## 10. Почему не dbDelta

`dbDelta()` — штатный способ WordPress создавать и «доводить» таблицы, но для этой схемы он не подходит:

* **Построчный разбор регулярными выражениями.** `dbDelta` делит тело `CREATE TABLE` по переводам строк и
  считает колонкой всё, что не начинается с `PRIMARY`/`KEY`/`INDEX`/`UNIQUE`/`FULLTEXT`/`SPATIAL`. Строки
  `CONSTRAINT … FOREIGN KEY` и `CONSTRAINT … CHECK` он примет за колонки и при повторном запуске
  сгенерирует бессмысленный `ALTER TABLE … ADD COLUMN CONSTRAINT …`; многострочные определения (`COMMENT` на
  следующей строке, `GENERATED ALWAYS AS …`) распадутся на отдельные «поля».
* **Generated columns.** Сравнение типов идёт по выводу `DESCRIBE`, где нет выражения generated column:
  изменение выражения `dbDelta` не увидит, а попытка «исправить» тип колонки может сломать её.
* **Нет управления DDL.** Нельзя указать `ALGORITHM`/`LOCK`, нельзя переименовать колонку (будет новая
  колонка, старая останется), нельзя удалить колонку или индекс, нет миграций данных (backfill), нет
  порядка шагов (циклический FK резерв ↔ заказ).
* **Формат-зависимость.** Требования вида «два пробела после `PRIMARY KEY`», `KEY` вместо `INDEX` делают
  схему хрупкой к форматированию.

Вместо этого `Install\Migrator`:

* хранит версию в опции `uniundata_db_version` и применяет упорядоченные шаги миграции через
  `$wpdb->query()`;
* каждый шаг идемпотентен: перед `ALTER` проверяет `information_schema` (колонка, индекс, constraint уже
  есть?);
* перед созданием проверяет `SELECT VERSION() >= 8.0.16` и движок InnoDB;
* подставляет `$wpdb->prefix` вместо `wp_`;
* запускается из `register_activation_hook` и при `plugins_loaded`, если версия в опции меньше версии кода
  (обновление плагина без реактивации), под `GET_LOCK('uniundata_migrate', 0)`, чтобы два запроса не
  мигрировали одновременно;
* для FULLTEXT-индексов заранее задаёт таблицу стоп-слов (раздел 11).

## 11. FULLTEXT-поиск

### Индексы

| Индекс | Колонки | Для чего |
|---|---|---|
| `ft_records_main` | `title, subtitle, authors_text, series_title` | Основная строка поиска: название + автор |
| `ft_records_all` | + `responsibility_statement, subjects_text, description, publisher` | «Искать везде» |
| `ft_contributors_name` | `name_display` | Автодополнение автора |
| `ft_subjects_heading` | `heading` | Автодополнение рубрики |

Список колонок в `MATCH(…)` должен **точно** совпадать со списком колонок одного из FULLTEXT-индексов, иначе
`ERROR 1191 Can't find FULLTEXT index matching the column list` (проверено). Поэтому два индекса —
два фиксированных набора, а не «любая комбинация».

### Как работает InnoDB FULLTEXT и что из этого следует

* **Минимальная длина слова** — `innodb_ft_min_token_size` = 3 (значение по умолчанию, на сервере проверено).
  Слова из 1–2 символов не индексируются: «Ад», «Ив», «It», «Го» через FULLTEXT не найти. Переменная
  серверная, только для чтения: изменение — перезапуск MySQL и перестроение FULLTEXT-индексов (на managed
  MySQL часто недоступно), поэтому на неё не полагаемся. Если все слова запроса короче 3 символов —
  префиксный поиск `title_sort LIKE 'ад%'` по `ix_records_active_title`. Ввод, похожий на ISBN, —
  отдельная ветка через `ix_identifiers_lookup` и `ix_records_isbn`.
* **Стоп-слова.** Встроенный список InnoDB — 36 английских слов: `a about an are as at be by com de en for
  from how i in is it la of on or that the this to und was what when where who will with www`. В нём есть
  `de`, `la`, `en`, `und` — значимые слова других языков. В `BOOLEAN MODE` обязательное стоп-слово
  обнуляет весь результат: `'+the +lord'` и `'+und +krieg'` возвращают 0 строк (проверено на 8.0.46).
  Решение: своя таблица стоп-слов (пустая или минимальная), назначенная **до** создания индексов:

  ```sql
  CREATE TABLE wp_book_ft_stopwords (value VARCHAR(30) NOT NULL) ENGINE=InnoDB
    DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci;
  SET SESSION innodb_ft_user_stopword_table = '<имя_БД>/wp_book_ft_stopwords';
  -- затем CREATE TABLE … FULLTEXT KEY … (или ALTER TABLE … ADD FULLTEXT)
  SET SESSION innodb_ft_user_stopword_table = DEFAULT;
  ```

  С пустой таблицей `'+the +lord'` и `'+und +krieg'` находят книги (проверено). Список стоп-слов
  фиксируется в момент создания индекса; смена — `DROP INDEX` + `ADD FULLTEXT`. Этой таблицы в
  `sql/schema.sql` пока нет.
* **Построитель запроса** (PHP), чтобы пользовательский ввод не ломал синтаксис `BOOLEAN MODE`:
  1. NFC, `mb_strtolower`, разбиение `preg_split('/[^\p{L}\p{N}]+/u', …)` — операторы `+ - < > ( ) ~ * " @`
     отбрасываются вместе с прочей пунктуацией;
  2. слова короче 3 символов и стоп-слова выбрасываются (иначе обязательный `+` обнулит результат);
  3. не более 8 слов, каждое → `+слово*` (префикс: `войн*` находит «война», «войны»; стемминга в MySQL нет,
     можно опционально обрезать окончания стеммером Snowball до добавления `*`);
  4. выражение передаётся только через `$wpdb->prepare('… AGAINST (%s IN BOOLEAN MODE)', $expr)`.

  ```sql
  SELECT r.id, r.title, r.authors_text, r.publication_year,
         MATCH (r.title, r.subtitle, r.authors_text, r.series_title)
               AGAINST ('+толст* +войн*' IN BOOLEAN MODE) AS score
    FROM wp_book_records r
   WHERE r.is_active = 1
     AND MATCH (r.title, r.subtitle, r.authors_text, r.series_title)
               AGAINST ('+толст* +войн*' IN BOOLEAN MODE)
   ORDER BY score DESC, r.title_sort
   LIMIT 24;
  ```

* **Collation в поиске.** FULLTEXT сравнивает слова по collation колонки: `+елка` находит «Ёлка»,
  `+muller` — «Müller», `ovelser` — «Øvelser» (проверено).
* **Видимость изменений.** Изменения FULLTEXT-индекса применяются при COMMIT: поиск не видит
  незакоммиченные строки. Обновление только неиндексируемых колонок (`last_seen_sync_run_id`,
  `last_synced_at`, `is_active`) FULLTEXT не трогает, а изменение `title` помечает старый документ
  удалённым (проверено по `INNODB_FT_DELETED`); пропуск записей по `source_checksum` защищает индекс от
  лишней перезаписи. После массовых изменений накапливаются удалённые doc ID — периодически
  `OPTIMIZE TABLE wp_book_records` с `innodb_optimize_fulltext_only = ON` (глобальная переменная, нужны
  права) или полный `OPTIMIZE` в окно обслуживания.
* **CJK (китайский, японский, корейский).** Встроенный парсер делит текст по пробелам и пунктуации, а в
  CJK пробелов нет: «红楼梦» становится одним словом, и поиск «红楼» его не находит (проверено). Парсер
  `ngram` (`FULLTEXT … WITH PARSER ngram`, `ngram_token_size` = 2, серверная read-only переменная) находит
  (проверено). Смешать парсеры в одном индексе нельзя; второй FULLTEXT на тот же набор колонок MySQL создать
  позволяет, но `MATCH` выбирает индекс по набору колонок, и выбор становится неоднозначным. Если доля CJK
  значима — отдельная колонка или таблица (например, `book_record_id` + текст) с `WITH PARSER ngram`, к
  которой обращаемся, только если в запросе есть символы `\p{Han}`, `\p{Hiragana}`, `\p{Katakana}`,
  `\p{Hangul}`. В текущей схеме этого нет: каталог европейский и русскоязычный.
* **Масштаб.** Если понадобятся морфология, опечатки, сложные фасеты или каталог от 1 млн записей —
  внешний поиск (OpenSearch, Meilisearch, Manticore), индексируемый из тех же денормализованных колонок.
  Доступность экземпляра при этом всегда читается из MySQL, а не из поискового индекса.

## 12. Почему ISBN не уникален

* **Модель данных.** Один внешний `book_id` — один физический экземпляр, и по умолчанию на каждый
  `book_id` создаётся своя запись. Два экземпляра одного издания — две записи с одним ISBN. `UNIQUE` на ISBN
  сломал бы синхронизацию на втором же экземпляре.
* **Реальность MARC.** Издатели переиспользуют ISBN (переиздания, допечатки, ошибки, особенно в изданиях
  1990-х); у многотомника есть ISBN комплекта и ISBN томов; в 020$z лежат отменённые номера; у книг до
  1970-х ISBN нет вовсе (`NULL`).
* **Как ищем.** `ix_records_isbn` (основной ISBN) и `ix_identifiers_lookup` (все ISBN, включая
  отменённые) возвращают **список** записей — витрина показывает все экземпляры.
* **Где уникальность действительно нужна:** `uq_items_external (source_name, external_item_id)` и
  `uq_records_source (source_name, source_record_id)` — внешние ключи интеграции;
  `uq_identifiers_record` — запрет дублей одного идентификатора **внутри** записи.

## 13. Кодировки и collation

* **`utf8mb4`** — полный Unicode: CJK, дореформенная кириллица (ѣ, і, ѳ, ѵ) в названиях старых изданий,
  редкие символы вне BMP. `utf8mb3` (`utf8`) устарел и не хранит 4-байтовые символы.
* **`utf8mb4_unicode_520_ci`, как в WordPress.** `wpdb` выбирает `utf8mb4_unicode_520_ci`, если сервер
  его поддерживает (MySQL 8 поддерживает), и именно его возвращает `$wpdb->get_charset_collate()`;
  собственный default MySQL 8 — `utf8mb4_0900_ai_ci`. Если таблицы плагина и ядра в разных collation,
  сравнение строковых колонок между ними (например, `wp_users.user_email = o.customer_email` в отчёте)
  падает с `ERROR 1267 Illegal mix of collations` (проверено). Таблицы плагина соединяются с ядром по
  целочисленным ID, но единый collation снимает этот класс ошибок полностью.
* **Свойства `utf8mb4_unicode_520_ci`** (проверено): нечувствителен к регистру и диакритике
  (`е` = `ё`, `u` = `ü`, `o` = `ø`) — хорошо для поиска и сортировки `title_sort`; `PAD SPACE`
  (`'abc '` = `'abc'`). Следствие для UNIQUE: `uq_items_inventory` считает `A-1` и `a-1` одним номером.
* **`ascii` для кодов, статусов, хэшей и внешних ID** — 1 байт на символ, индексы в 4 раза меньше.
  Collation по умолчанию `ascii_general_ci` **нечувствителен к регистру** (проверено):
  * `uq_items_external` считает `AbC-1` и `abc-1` одним экземпляром. Если ID источника чувствительны к
    регистру, upsert обновит **чужой** экземпляр. То же для `uq_records_source`,
    `uq_payments_provider_id`, `uq_payment_events_provider`;
  * `REGEXP` в CHECK не различает регистр (раздел 7);
  * в нестрогом режиме WP не-ASCII символ молча становится `?` (раздел 1).

  Рекомендация для схемы: `COLLATE ascii_bin` у внешних идентификаторов; до исправления — валидация в
  PHP и проверка у интеграции, чувствительны ли её ID к регистру.
* **Длина индексируемых строк.** Предел ключа InnoDB (`DYNAMIC`) — 3072 байта. `VARCHAR(255) utf8mb4` =
  1020 байт: поэтому `title_sort`, `name_sort`, `heading_sort`, `main_author_sort` — `VARCHAR(255)`, а
  `title VARCHAR(1000)` (4000 байт) в B-tree не индексируется, только в FULLTEXT.
* Соединение: `wpdb` сам выполняет `SET NAMES utf8mb4` с collation сайта. Скрипты вне WP (импорт через
  `mysql`) обязаны указывать `--default-character-set=utf8mb4`, иначе кириллица запишется
  «кракозябрами» без ошибки (проверено при подготовке документа).

## 14. Оценка объёмов

Допущения базового сценария (уточнить у заказчика):

* каталог — 100 000 экземпляров, по умолчанию 1:1 с записями; прирост 30 000 экземпляров в год;
* продаётся 25 000 экземпляров в год (≈ 50 заказов в день, 1,5 книги на заказ);
* ≈ 300 резервов в день (≈ 110 000 в год, конверсия резерва в покупку ≈ 25%);
* 1,3 попытки оплаты на заказ, ≈ 3 webhook-события на платёж;
* средняя MARCXML-запись 3–6 КБ.

| Таблица | Строк (старт / в год) | Байт на строку с индексами (≈) | Объём |
|---|---|---|---|
| `wp_book_records` | 100k / +30k | 7 КБ (raw 4 КБ + поля 1,5 КБ + B-tree 0,5 КБ + FULLTEXT ≈ 1 КБ) | ≈ 0,7 ГБ, +0,2 ГБ/год |
| `wp_book_contributors` + связка | 50k + 200k | 0,4 КБ / 60 Б | ≈ 35 МБ |
| `wp_book_subjects` + связка | 30k + 300k | 0,5 КБ / 50 Б | ≈ 30 МБ |
| `wp_book_identifiers` | 150k | 150 Б | ≈ 25 МБ |
| `wp_book_items` | 100k / +30k | 0,6 КБ | ≈ 60 МБ |
| `wp_book_images` | 200k / +60k | 0,3 КБ | ≈ 60 МБ |
| `wp_book_carts` | 50k/год | 0,3 КБ | ≈ 15 МБ/год |
| `wp_book_reservations` | 110k/год | 0,35 КБ | ≈ 40 МБ/год |
| `wp_book_cart_items` | 110k/год | 0,3 КБ | ≈ 35 МБ/год |
| `wp_book_orders` | 18k/год | 2 КБ (снимки, JSON-адреса) | ≈ 36 МБ/год |
| `wp_book_order_items` | 27k/год | 0,8 КБ | ≈ 22 МБ/год |
| `wp_book_payments` | 24k/год | 0,4 КБ | ≈ 10 МБ/год |
| `wp_book_payment_events` | 70k/год | 1,5 КБ (JSON) | ≈ 100 МБ/год |
| `wp_book_sales` | 25k/год | 0,25 КБ | ≈ 6 МБ/год |
| `wp_book_sync_runs` | 365–730/год | до 10 КБ (`error_log`) | < 10 МБ/год |
| `wp_book_audit_log` | 2–3 млн/год | 0,4 КБ | **≈ 1 ГБ/год** — главный источник роста |

Выводы:

* Каталог на 100k экземпляров — около 1 ГБ; на 1 млн — около 10 ГБ (линейно, основная доля — `marc21_raw`).
* Транзакционные таблицы растут на ≈ 0,3 ГБ в год и не требуют партиционирования.
* `wp_book_audit_log` — архивировать по сроку хранения (например, выгрузка и удаление строк старше N лет;
  это единственная таблица, где удаление допустимо политикой хранения) или партиционировать по
  `occurred_at` (FK у таблицы нет, но PK тогда должен включать `occurred_at`).
* Конкуренция определяется не объёмом, а блокировками: `FOR UPDATE` берётся на одну строку экземпляра,
  поэтому даже пиковая нагрузка (поступление коллекции, 10× резервов за час) конфликтует только на
  одних и тех же книгах.
* `innodb_buffer_pool_size` — не меньше 1–2 ГБ, чтобы индексы и горячие данные помещались в память.

Фактические размеры после запуска:

```sql
SELECT table_name, table_rows,
       ROUND(data_length  / 1024 / 1024) AS data_mb,
       ROUND(index_length / 1024 / 1024) AS index_mb
  FROM information_schema.TABLES
 WHERE table_schema = DATABASE() AND table_name LIKE 'wp\_book\_%'
 ORDER BY data_length + index_length DESC;
```
