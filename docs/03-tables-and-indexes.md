# 03. Таблицы, поля, индексы и ограничения

Источник истины — `sql/schema.sql` (схема v2). Документ объясняет, **зачем** нужны таблица, индекс и
ограничение и какие решения приняты под WordPress 7.1.2, PHP 8.3+ (совместимо с 8.4) и MySQL 8.0.16+
(у заказчика 8.0.46). Пометка «проверено» означает, что поведение воспроизведено на MySQL 8.0.46 на этой
схеме.

Содержание:

1. [Общие соглашения](#1-общие-соглашения)
2. [Список таблиц](#2-список-таблиц)
3. [Таблицы: ключевые поля и индексы](#3-таблицы-ключевые-поля-и-индексы)
4. [MARC 21 → колонки](#4-marc-21--колонки)
5. [Что не раскладываем в колонки](#5-что-не-раскладываем-в-колонки)
6. [Уникальность «только среди активных»](#6-уникальность-только-среди-активных)
7. [CHECK-ограничения и имена ограничений](#7-check-ограничения-и-имена-ограничений)
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
| Движок | `ENGINE=InnoDB` | Транзакции, построчные блокировки, `SELECT … FOR UPDATE`, FK, FULLTEXT |
| Ключи | `BIGINT UNSIGNED AUTO_INCREMENT` | Тот же тип, что `wp_users.ID` / `wp_posts.ID` |
| Деньги | `INT UNSIGNED` в минимальных единицах (копейки, центы) + `CHECK (… > 0)` | Нет ошибок округления `FLOAT`, в PHP — обычный `int`. Предел 42 949 672,95 в валюте на одно значение |
| Валюта | `CHAR(3) COLLATE utf8mb4_bin` + `CHECK (currency REGEXP '^[A-Z]{3}$')` рядом с каждой суммой | ISO 4217. Валюта магазина — option `uniundata_currency`; экземпляр в другой валюте не резервируется и не импортируется |
| Даты | `DATETIME(6)`, UTC | `Db::transaction()` на время транзакции ставит `time_zone = '+00:00'` и возвращает прежнее значение; в SQL — `UTC_TIMESTAMP(6)`. `DEFAULT`/`ON UPDATE CURRENT_TIMESTAMP(6)` зависят от зоны сессии, поэтому все записи плагина идут через `Db::transaction()`, а на сервере рекомендуется `default-time-zone = '+00:00'` (у заказчика сервер в UTC+4; мигратор предупреждает). `DATETIME`, а не `TIMESTAMP`: нет пересчёта по зоне и проблемы 2038. Микросекунды упорядочивают события внутри секунды |
| Статусы | `VARCHAR(n) COLLATE utf8mb4_bin` + `CHECK (… IN (…))` | В нестрогом `sql_mode` WordPress неверное значение `ENUM` молча становится `''`, а CHECK даёт ошибку 3819. Цена — новый статус добавляется миграцией с копированием таблицы (раздел 7) |
| Строки | Всё `utf8mb4`: таблицы — `utf8mb4_unicode_520_ci` (как ядро WP), коды, статусы, хэши и внешние ID — `utf8mb4_bin` | Раздел 13: смешение `ascii` и `utf8mb4` ломает `wpdb` на кириллице |
| Имена ограничений | `<таблица>_chk_<x>`, `<таблица>_fk_<x>`; индексы — `uq_`, `ix_`, `ft_` | Имена CHECK и FK уникальны в пределах БД, имена индексов — в пределах таблицы (раздел 7) |
| Флаги | `TINYINT(1)` + `CHECK (x IN (0, 1))` | |
| Удаление | Бизнес-строки не удаляются | Корзины, резервы, заказы, платежи, возвраты, продажи и проданные экземпляры только меняют статус; отсюда FK без каскадов |
| Префикс | `wp_` в файле условный | Мигратор заменяет `wp_book_` на `{$wpdb->prefix}book_` одной заменой — вместе с именами ограничений |
| Создание | Версионный мигратор, не `dbDelta()` | Раздел 10 |

**Нестрогий `sql_mode` WordPress.** `wpdb` убирает из сессии `STRICT_*`, `TRADITIONAL`, `NO_ZERO_DATE` и др.
До проверки CHECK, UNIQUE и FK MySQL тогда молча «чинит» значения: `-100` в `INT UNSIGNED` становится `0`
(warning 1264), длинная строка обрезается (1265). В v2 это закрыто с трёх сторон:

* `CHECK (… > 0)` на всех суммах превращает «починенный» `0` в ошибку 3819 (проверено: `-100` в
  `price_amount` → `wp_book_items_chk_price`);
* `Db::transaction()` на время транзакции плагина добавляет `STRICT_TRANS_TABLES` и затем восстанавливает
  прежний `sql_mode` (глобально нельзя: ядро и чужие плагины рассчитывают на нестрогий режим) — вместо
  обрезки приходят ошибки 1406/1264/1366;
* PHP валидирует вход до SQL: суммы — `int > 0`, внешние ID — `/^[\x21-\x7E]{1,191}$/`, статусы — только
  через backed enum.

---

## 2. Список таблиц

| # | Таблица | Назначение | Растёт от |
|---|---|---|---|
| 1 | `wp_book_records` | Библиографическая запись: полный MARC 21 + денормализованные поля витрины и поиска; при продаже не удаляется | синхронизации |
| 2 | `wp_book_contributors` | Персоны и организации 1XX/7XX | синхронизации |
| 3 | `wp_book_record_contributors` | M:N запись ↔ персона с ролью и порядком | синхронизации |
| 4 | `wp_book_subjects` | Предметные рубрики 6XX с тезаурусом | синхронизации |
| 5 | `wp_book_record_subjects` | M:N запись ↔ рубрика | синхронизации |
| 6 | `wp_book_identifiers` | ISBN (в т. ч. отменённые 020$z), ISSN, LCCN, OCLC, EAN, ISMN | синхронизации |
| 7 | `wp_book_items` | **Продаваемый экземпляр**: цена, состояние, локальный статус, статус в источнике, факт продажи | синхронизации |
| 8 | `wp_book_images` | Изображения записи или конкретного экземпляра | синхронизации, админки |
| 9 | `wp_book_customer_profiles` | Телефон E.164, адреса по умолчанию, реквизиты B2B | оформления |
| 10 | `wp_book_user_consents` | Доказательство согласия: версия и хэш документа, время, IP (append-only) | оформления |
| 11 | `wp_book_carts` | Корзина; не более одной открытой на пользователя | резервов |
| 12 | `wp_book_reservations` | История и текущее состояние резервов, попытка 1..3 | резервов |
| 13 | `wp_book_cart_items` | Позиция корзины = представление резерва + снимок цены | резервов |
| 14 | `wp_book_orders` | Заказ: `uniundata_<uuid>`, суммы, снимок покупателя и адресов, сроки оплаты | checkout |
| 15 | `wp_book_order_items` | Позиции заказа со снимками названия, автора, ISBN, цены | checkout |
| 16 | `wp_book_payments` | Попытки оплаты: idempotency key, ID провайдера, статус, сумма возвратов | checkout, повторной оплаты |
| 17 | `wp_book_payment_events` | Inbox webhook-ов **после** проверки подписи | webhook-ов |
| 18 | `wp_book_refunds` | Идемпотентная очередь возвратов денег через банк | возвратов |
| 19 | `wp_book_sales` | Факт продажи; `UNIQUE (book_item_id)` | оплат |
| 20 | `wp_book_sync_runs` | Журнал прогонов синхронизации: счётчики, ошибки, курсор, цепочка возобновлений | cron |
| 21 | `wp_book_audit_log` | Аудит переходов статусов и важных действий (append-only) | всего |

Не создаём: таблицу пользователей (есть `wp_users` и `wp_usermeta`: `first_name`, `last_name`,
необязательный `middle_name`); «товары» в стиле WooCommerce (карточка = запись + экземпляры); custom post
type для книг (100k+ строк в `wp_posts`/`wp_postmeta` — EAV без нормальных индексов и транзакционных
инвариантов).

---

## 3. Таблицы: ключевые поля и индексы

Для каждой таблицы перечислены **все** индексы. Итого после загрузки `sql/schema.sql`
(`information_schema`): 105 индексов — 21 PRIMARY, 25 UNIQUE (4 из них по generated columns), 4 FULLTEXT,
55 обычных; 56 CHECK и 30 FOREIGN KEY. Тип индекса виден по имени: `uq_` — UNIQUE, `ix_` — обычный, `ft_` —
FULLTEXT. У каждого FK есть явный индекс, «автоматических» индексов с именем ограничения нет; в колонке
«Запрос» FK указан суффиксом имени (`_fk_record` = `wp_book_<таблица>_fk_record`).

### 3.1. `wp_book_records`

* `source_name` + `source_record_id` — стабильный внешний ключ записи; по умолчанию
  `source_record_id = book_id`, но схема допускает группировку экземпляров одного издания.
* `source_format` — как пришло (`marcxml`, `marc_json`, `iso2709`, `mrk`), `marc21_format` — как хранится
  (`marcxml` или `marc_json`): ISO 2709 (бинарный, до 99 999 байт, возможна MARC-8) и MRK конвертируются при
  импорте.
* `marc21_raw MEDIUMTEXT` — полная запись; позволяет перезапустить извлечение полей без обращения к источнику.
* `source_checksum` — SHA-256 канонизированной записи (NFC, нормализованные пробелы, без поля 005). Совпал —
  запись пропускается (`records_skipped`), FULLTEXT не трогается.
* `title VARCHAR(1000)` — вывод и FULLTEXT; `title_sort VARCHAR(255)` — сортировка в B-tree.
* `is_active` — видимость в каталоге; строка не удаляется (на неё ссылаются экземпляры, заказы, продажи).

| Индекс | Колонки | Запрос |
|---|---|---|
| `PRIMARY` | `id` | Карточка, JOIN из items/order_items/sales |
| `uq_records_source` | `source_name, source_record_id` | Upsert синхронизации; защита от дублей при повторном или параллельном импорте |
| `ix_records_isbn` | `isbn_primary` | Ввод ISBN в строку поиска (`Using index`, проверено) |
| `ix_records_marc001` | `marc_control_org, marc_control_number` | Сопоставление по MARC 001/003 (одна запись под двумя `book_id`) |
| `ix_records_active_title` | `is_active, title_sort` | Каталог по алфавиту `WHERE is_active = 1 ORDER BY title_sort` без filesort; префикс `title_sort LIKE 'ад%'` |
| `ix_records_active_author` | `is_active, main_author_sort` | Указатель и сортировка по автору |
| `ix_records_active_year` | `is_active, publication_year` | Фильтр `publication_year BETWEEN …` — range + `Using index` |
| `ix_records_language` | `language_code` | Фасет «язык» |
| `ix_records_sync` | `source_name, last_seen_sync_run_id` | Записи, не пришедшие в текущем проходе (range, проверено) |
| `ft_records_main` | `title, subtitle, authors_text, series_title` | Основная строка поиска (раздел 11) |
| `ft_records_all` | + `responsibility_statement, subjects_text, description, publisher` | «Искать везде» |

### 3.2. `wp_book_contributors`, `wp_book_record_contributors`

* `contributor_key` = SHA-256 от authority ID (`$0`/`$1`: VIAF, GND, LC NAF), иначе от нормализованного
  `имя|даты` (`толстой, лев николаевич|1828-1910`) — даты не дают склеить однофамильцев.
* `role_code` — relator code из `$4`, при его отсутствии — из `$e` по словарю («пер.» → `trl`, «ред.» → `edt`);
  для 1XX по умолчанию `aut`. Входит в PK: одна персона — автор и иллюстратор одной книги.

| Таблица | Индекс | Колонки | Запрос |
|---|---|---|---|
| contributors | `PRIMARY` | `id` | JOIN из связки |
| contributors | `uq_contributors_key` | `contributor_key` | Upsert персоны `INSERT … ON DUPLICATE KEY UPDATE` |
| contributors | `ix_contributors_sort` | `name_sort` | Указатель авторов А–Я |
| contributors | `ft_contributors_name` | `name_display` | Автодополнение в фильтре «автор» |
| record_contributors | `PRIMARY` | `book_record_id, contributor_id, role_code` | Персоны записи; индекс для `_fk_record` |
| record_contributors | `ix_rc_contributor` | `contributor_id, book_record_id` | «Все книги автора»; индекс для `_fk_contributor` |

### 3.3. `wp_book_subjects`, `wp_book_record_subjects`

* `subject_key` = SHA-256 от `тезаурус|нормализованная рубрика`: одна строка в LCSH и GND — разные рубрики.
* `heading` — рубрика с подразделениями через ` -- `; `thesaurus` — из ind2 (0 → `lcsh`, 2 → `mesh`,
  6 → `rvm` …) или из `$2` при ind2 = 7 (`gnd`, `rero`, `nlr`).

| Таблица | Индекс | Колонки | Запрос |
|---|---|---|---|
| subjects | `PRIMARY` | `id` | JOIN |
| subjects | `uq_subjects_key` | `subject_key` | Upsert рубрики |
| subjects | `ix_subjects_sort` | `heading_sort` | Рубрикатор А–Я |
| subjects | `ft_subjects_heading` | `heading` | Поиск рубрики в фильтре |
| record_subjects | `PRIMARY` | `book_record_id, subject_id` | Рубрики записи; индекс для `_fk_record` |
| record_subjects | `ix_rs_subject` | `subject_id, book_record_id` | «Все книги рубрики»; индекс для `_fk_subject` |

### 3.4. `wp_book_identifiers`

`id_value` — нормализованное значение (ISBN без дефисов, ISBN-10 → ISBN-13), `raw_value` — как в записи с
`$q`. `is_cancelled = 1` для 020$z / 022$z: в `isbn_primary` не попадают, но ищутся (на старых книгах часто
напечатан именно ошибочный номер).

| Индекс | Колонки | Запрос |
|---|---|---|
| `PRIMARY` | `id` | |
| `uq_identifiers_record` | `book_record_id, id_type, id_value` | Идемпотентная перезапись идентификаторов записи; индекс для `_fk_record`. Уникальность **внутри записи** (раздел 12) |
| `ix_identifiers_lookup` | `id_type, id_value` | Поиск по любому ISBN/ISSN/OCLC → список записей |

### 3.5. `wp_book_items`

* `external_item_id` — внешний `book_id`, **один физический экземпляр** (две одинаковые книги — разные ID);
  `NULL` для заведённых вручную. Регистр значим (`utf8mb4_bin`).
* `price_amount` + `currency` — текущая цена каталога, `CHECK > 0`; в корзину и заказ копируется снимок.
* `availability_status` — **локальный** статус (7 значений, `docs/04-statuses.md`); `source_status`
  (`present`, `missing`, `withdrawn`) — мнение источника. Синхронизация не перетирает `reserved`,
  `checkout_pending`, `sold`, `blocked`; при освобождении экземпляр получает статус по `source_status`.
* `status_changed_at` — время смены статуса; `sold_at` — заполнен тогда и только тогда, когда `sold`
  (`_chk_sold_at`); `source_checksum` — SHA-256 полей экземпляра из источника.

| Индекс | Колонки | Запрос |
|---|---|---|
| `PRIMARY` | `id` | **`SELECT … FOR UPDATE` по id** — точка сериализации резерва, checkout, webhook, снятия резервов; `GET /catalog/availability` |
| `uq_items_external` | `source_name, external_item_id` | Upsert синхронизации; «один внешний `book_id` — одна строка». `AbC-1` ≠ `abc-1` (проверено); несколько `NULL` допустимы |
| `uq_items_inventory` | `inventory_number` | Поиск по инвентарному номеру. Collation таблицы (`_unicode_520_ci`): `A-1` и `a-1` — один номер; уникален по всем источникам, конфликтный номер синхронизация не записывает |
| `ix_items_record_status` | `book_record_id, availability_status` | Экземпляры записи и «есть ли в продаже»; индекс для `_fk_record` |
| `ix_items_catalog` | `is_active, availability_status, price_amount` | `WHERE is_active = 1 AND availability_status = 'available' ORDER BY price_amount` — `Using index` без filesort (проверено) |
| `ix_items_sync` | `source_name, last_seen_sync_run_id` | Экземпляры, не пришедшие в полном проходе (кандидаты в `sync_missing`) |

### 3.6. `wp_book_images`

Владелец — запись **или** экземпляр (`_chk_owner`); для букинистики важны фото конкретного экземпляра.
`attachment_id` — `wp_posts.ID` (логическая ссылка), без вложения показывается `source_url`.

| Индекс | Колонки | Запрос |
|---|---|---|
| `PRIMARY` | `id` | |
| `ix_images_record` | `book_record_id, position` | Галерея записи; индекс для `_fk_record` |
| `ix_images_item` | `book_item_id, position` | Галерея экземпляра; индекс для `_fk_item` |

### 3.7. `wp_book_customer_profiles`

| Данные | Где | Почему |
|---|---|---|
| ID, email, `display_name`, пароль | `wp_users` | Ядро WP, логин, восстановление пароля |
| `first_name`, `last_name`, `middle_name` (необязательное) | `wp_usermeta` | Стандартные ключи, индекс не нужен |
| Телефон | `phone_e164` | Строгий формат (`_chk_phone`), индексный поиск, `phone_verified_at` |
| Адреса по умолчанию | `default_shipping_address`, `default_billing_address` (JSON) | Только автозаполнение; источник истины — **снимок** в заказе |
| B2B | `company_name`, `vat_id` | Необязательно |

| Индекс | Колонки | Запрос |
|---|---|---|
| `PRIMARY` | `user_id` | Профиль `get_current_user_id()`; 1 : 0..1 с `wp_users` |
| `ix_profiles_phone` | `phone_e164` | Поиск покупателя по телефону |

### 3.8. `wp_book_user_consents`

`consent_uuid` — технический идентификатор согласия; `document_version` + `document_sha256` — принятая
редакция (тексты версий — option `uniundata_terms_versions`); `ip_address VARBINARY(16)` (`INET6_ATON`) и
`user_agent_sha256` обнуляются по сроку хранения; отзыв — `withdrawn_at`, строка не удаляется.

| Индекс | Колонки | Запрос |
|---|---|---|
| `PRIMARY` | `id` | `orders.offer_consent_id` |
| `uq_consents_uuid` | `consent_uuid` | Доказательство по техническому ID |
| `ix_consents_user` | `user_id, consent_type, accepted_at` | Действующее согласие пользователя: `ORDER BY accepted_at DESC LIMIT 1` |
| `ix_consents_retention` | `accepted_at` | Ежедневная задача `uniundata_privacy_retention`: `SET ip_address = NULL … WHERE accepted_at < …` — range вместо полного прохода |

### 3.9. `wp_book_carts`

* Открыта при `active`, `checkout_started`; закрыта при `converted_to_order`, `abandoned`, `expired`
  (`closed_at` NOT NULL, `_chk_closed`).
* `expires_at` — `MIN(expires_at)` активных позиций, **только для таймера**: позиции истекают независимо.
* `last_activity_at` — меняется при reserve/remove/checkout; открытие страницы корзины ничего не пишет.

| Индекс | Колонки | Запрос |
|---|---|---|
| `PRIMARY` | `id` | |
| `uq_carts_one_open_per_user` | `open_cart_user_id` (STORED) | «Не более одной открытой корзины» **и** точка блокировки: `SELECT … WHERE open_cart_user_id = ? FOR UPDATE` (уровень 1 порядка блокировок). Создание — `INSERT … ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)` |
| `ix_carts_user` | `user_id, created_at` | История корзин пользователя |
| `ix_carts_status_activity` | `status, last_activity_at` | `uniundata_abandon_carts`: пустые открытые корзины без активности 30 дней |

Проверка дубликата в этом UNIQUE ставит gap-блокировку на ещё не вычищенную запись закрытой корзины даже в
READ COMMITTED. Если checkout закрывает корзину, а параллельный запрос того же пользователя создаёт новую,
изредка возможен deadlock 1213 (воспроизведён стресс-тестом, `docs/10-scenarios.md`). Инварианты он не
нарушает: транзакция откатывается целиком, `Db::transaction()` её повторяет.

### 3.10. `wp_book_reservations`

* `attempt_no` 1..3 — номер попытки пары `(user_id, book_item_id)`; `NULL` только у `released_by_admin`.
  Новый номер = `1 + COUNT(*)` резервов пары с `attempt_no IS NOT NULL`, под блокировкой строки экземпляра.
* `expires_at = reserved_at + 1 час` (`_chk_window`), не продлевается. `released_at` — выход из `active`
  (любой финальный статус, `_chk_released`); `release_reason` — код (`user_removed`, `expired`,
  `admin_block`, `converted_to_order` …); `order_id` — заказ при `converted_to_order` (`_chk_order`).

| Индекс | Колонки | Запрос |
|---|---|---|
| `PRIMARY` | `id` | Блокировка резерва (admin release, expiry) |
| `uq_reservations_one_active_per_item` | `active_book_item_id` (STORED) | Второй рубеж после `FOR UPDATE` экземпляра (1062 → 409 `uniundata_item_unavailable`); поиск активного резерва экземпляра — const |
| `uq_reservations_attempt` | `user_id, book_item_id, attempt_no` | Лимит трёх попыток в БД (вместе с `_chk_attempt_range`); подсчёт попыток пары |
| `ix_reservations_expiry` | `reservation_status, expires_at` | Cron: `WHERE reservation_status = 'active' AND expires_at <= UTC_TIMESTAMP(6) ORDER BY expires_at` — range, `Using index` (проверено) |
| `ix_reservations_user` | `user_id, reservation_status, expires_at` | Активные резервы пользователя; лимит `uniundata_max_active_reservations` (по умолчанию 10) |
| `ix_reservations_item_history` | `book_item_id, reserved_at` | История резервов экземпляра; индекс для `_fk_item` (`uq_reservations_attempt` начинается с `user_id` и не годится) |
| `ix_reservations_cart` | `cart_id` | Индекс для `_fk_cart` |
| `ix_reservations_order` | `order_id` | Резервы заказа; индекс для `_fk_order` |

### 3.11. `wp_book_cart_items`

Позиция — представление резерва: `reservation_id` UNIQUE, `expires_at` — копия срока резерва,
`unit_price_amount` (`CHECK > 0`) + `currency` — снимок цены на момент резерва. Экземпляр может быть активной
позицией только одной корзины; после удаления повторный резерв той же книги в ту же корзину разрешён (старая
строка уже не `active`).

| Индекс | Колонки | Запрос |
|---|---|---|
| `PRIMARY` | `id` | |
| `uq_cart_items_reservation` | `reservation_id` | Связь 1:1 с резервом; индекс для `_fk_reservation` |
| `uq_cart_items_one_active_per_item` | `active_book_item_id` (STORED) | Экземпляр — активная позиция не более одной корзины |
| `ix_cart_items_cart` | `cart_id, status` | `GET /cart`; индекс для `_fk_cart` |
| `ix_cart_items_expiry` | `status, expires_at` | Контроль: истёкшие активные позиции (должны совпадать с резервами) |
| `ix_cart_items_item` | `book_item_id` | История позиций экземпляра; индекс для `_fk_item` |

### 3.12. `wp_book_orders`

* `public_order_id` — `uniundata_<UUID v4>` (`_chk_public_id`, только строчные hex: колонка `utf8mb4_bin`).
  Последовательный `id` наружу не отдаётся.
* `checkout_request_id` — `Idempotency-Key` запроса `POST /checkout`.
* Суммы: `subtotal_amount`, `total_amount` (`_chk_positive`: > 0), `discount_amount`, `shipping_amount`,
  `tax_amount`, `refunded_amount` (`_chk_refund`: ≤ `total_amount`); `_chk_total` проверяет арифметику с
  учётом `prices_include_tax`.
* Снимок покупателя: `customer_email`, `customer_phone`, `customer_first_name`, `customer_last_name`,
  `customer_middle_name`, `billing_address_json`, `shipping_address_json`, `shipping_method`.
* `payment_due_at` = checkout + `uniundata_payment_ttl_minutes` (30) + `uniundata_payment_grace_minutes`
  (10); после него cron освобождает экземпляры. `payment_due_extended_at` — однократное продление, пока банк
  отвечает «в обработке» (признак однократности — колонка, а не журнал аудита).
* `pii_erased_at` — когда контакты покупателя обезличены по сроку хранения (признак — колонка, а не
  маркеры в данных).
* `needs_attention` + `attention_reason` — флаг ручного разбора (раздел 7.6 в `docs/04-statuses.md`).

| Индекс | Колонки | Запрос |
|---|---|---|
| `PRIMARY` | `id` | `FOR UPDATE` заказа (уровень 5 порядка блокировок) |
| `uq_orders_public_id` | `public_order_id` | `GET /orders/{id}`, `/pay`, `/cancel`; сопоставление webhook-а |
| `uq_orders_checkout_request` | `user_id, checkout_request_id` | Идемпотентность `POST /checkout`; ключ уникален в пределах пользователя — чужой ключ не раскроет чужой заказ |
| `ix_orders_user` | `user_id, created_at` | `GET /orders` |
| `ix_orders_payment_due` | `status, payment_due_at` | Cron: `status IN (4 открытых) AND payment_due_at <= …` — range по четырём диапазонам |
| `ix_orders_status_created` | `status, created_at` | Списки заказов в админке |
| `ix_orders_attention` | `needs_attention, updated_at` | Очередь ручного разбора |
| `ix_orders_cart` | `cart_id` | Индекс для `_fk_cart` |
| `ix_orders_consent` | `offer_consent_id` | Индекс для `_fk_consent` |
| `ix_orders_retention` | `pii_erased_at, status, updated_at` | `uniundata_privacy_retention`: `pii_erased_at IS NULL AND status IN (закрытые) AND updated_at < …` — range (`sql/queries.sql` 11.1) |

### 3.13. `wp_book_order_items`

Снимки `title_`, `subtitle_`, `author_`, `isbn_`, `publisher_`, `publication_year_`, `condition_`,
`cover_url_`, `item_identifier_snapshot`, `unit_price_amount` (`CHECK > 0`), `currency`. `quantity` всегда 1
(`_chk_quantity`).

| Индекс | Колонки | Запрос |
|---|---|---|
| `PRIMARY` | `id` | `sales.order_item_id` |
| `uq_order_items_item` | `order_id, book_item_id` | Экземпляр не дважды в заказе; позиции заказа; индекс для `_fk_order` |
| `uq_order_items_reservation` | `reservation_id` | Один резерв — не более одной позиции заказа (несколько `NULL` допустимы); индекс для `_fk_reservation` |
| `ix_order_items_book_item` | `book_item_id` | В каких заказах был экземпляр (поздний платёж); индекс для `_fk_item` |
| `ix_order_items_record` | `book_record_id` | Отчёты по изданиям; индекс для `_fk_record` |

### 3.14. `wp_book_payments`

* `attempt_no` — попытка оплаты заказа; новая попытка — новая строка.
* `idempotency_key` (UUID) создаётся **до** HTTP-запроса и передаётся банку: потерянный ответ
  `createSession` при повторе не откроет вторую сессию.
* `session_redirect_url`, `session_expires_at` — страница оплаты и TTL сессии: повтор `/checkout` или `/pay`
  при живой сессии возвращает сохранённый URL без нового `createSession`.
* `amount` > 0; `refunded_amount` — сумма подтверждённых возвратов этого платежа (`_chk_amount`:
  `refunded_amount <= amount`).
* `provider_status` — сырой статус банка; `card_brand`, `card_last4` — только бренд и 4 цифры (PCI DSS).

| Индекс | Колонки | Запрос |
|---|---|---|
| `PRIMARY` | `id` | `FOR UPDATE` платежа (уровень 6) |
| `uq_payments_idempotency` | `idempotency_key` | Одна сессия на попытку |
| `uq_payments_provider_id` | `provider, provider_payment_id` | Webhook находит платёж по ID банка; несколько `NULL` допустимы |
| `uq_payments_attempt` | `order_id, attempt_no` | Попытки заказа без дублей номера; индекс для `_fk_order` |
| `ix_payments_status` | `status, session_expires_at` | «Зависшие» открытые попытки для опроса банка |

### 3.15. `wp_book_payment_events`

Строка вставляется **только после проверки подписи**: иначе злоумышленник «занял» бы `provider_event_id`
настоящего события. `provider_event_id` — ID у банка или SHA-256 канонического тела; `payload_redacted` — без
PAN/CVV/секретов/лишних ПДн; `attempts` — сколько раз событие обрабатывалось.

| Индекс | Колонки | Запрос |
|---|---|---|
| `PRIMARY` | `id` | `FOR UPDATE` события (уровень 7) |
| `uq_payment_events_provider` | `provider, provider_event_id` | **Идемпотентность webhook-а**: повтор → 1062 → смотрим `processing_status` |
| `ix_payment_events_payment` | `payment_id, received_at` | История событий платежа; индекс для `_fk_payment` |
| `ix_payment_events_status` | `processing_status, received_at` | Очередь переобработки `received`/`failed` |
| `ix_payment_events_order` | `order_id` | События заказа в админке; индекс для `_fk_order` |

### 3.16. `wp_book_refunds`

* Одна строка — один запрос возврата к банку. Создаётся со статусом `requested` **в той же транзакции**, где
  принято решение: автоматически при обработке платежа (`duplicate_payment`, `late_payment_conflict`) или
  менеджером (`order_cancelled`, `customer_return`, `manual`). Возврат, сделанный мимо плагина и узнанный из
  webhook-а банка, записывается как `manual` и сразу завершается. `amount_mismatch` схема допускает для
  решения менеджера по заказу с несовпавшей суммой; `PaymentService::requestRefund()` пока принимает только
  `order_cancelled`, `customer_return`, `manual`.
* После COMMIT задача Action Scheduler `uniundata_refund_payment {refund_id}` блокирует строку, вызывает
  `provider->refund()` **вне транзакции** и переводит статус `requested → pending → succeeded | failed`
  (`docs/04-statuses.md`, раздел 9).
* `idempotency_key` (UUID) передаётся банку: повтор задачи не создаёт второй возврат. `provider_refund_id` —
  ID возврата у банка, по нему webhook возврата находит строку.
* `amount` > 0. Сумма незавершённых и успешных (`status <> 'failed'`) возвратов платежа не превышает
  `payments.amount` — проверяется кодом под блокировкой строк возвратов платежа (CHECK не видит других строк);
  итог в `payments.refunded_amount` ограничен CHECK.
* `requested_by` — `wp_users.ID` менеджера или `NULL`; `completed_at` заполнен тогда и только тогда, когда
  статус финальный (`_chk_completed`).

| Индекс | Колонки | Запрос |
|---|---|---|
| `PRIMARY` | `id` | `FOR UPDATE` возврата (уровень 8 порядка блокировок); аргумент задачи AS |
| `uq_refunds_idempotency` | `idempotency_key` | Идемпотентность запроса к банку |
| `uq_refunds_provider_id` | `provider_refund_id` | Сопоставление webhook-а возврата; несколько `NULL` допустимы |
| `ix_refunds_payment` | `payment_id` | Возвраты платежа (сумма под блокировкой); индекс для `_fk_payment` |
| `ix_refunds_order` | `order_id` | Карточка заказа; «нет незавершённых возвратов» в ретеншне ПДн; индекс для `_fk_order` |
| `ix_refunds_status` | `status, requested_at` | Очередь и мониторинг: `requested`/`pending` дольше суток (`sql/queries.sql` 9.5, 10.12) |

### 3.17. `wp_book_sales`

Одна строка — одна успешная продажа экземпляра; синхронизация таблицу не трогает. `refunded_at` — деньги по
платежу возвращены полностью; экземпляр в продажу **не** возвращается (`sold` терминален).

| Индекс | Колонки | Запрос |
|---|---|---|
| `PRIMARY` | `id` | |
| `uq_sales_book_item` | `book_item_id` | **Одна продажа на экземпляр** — последний рубеж против двойной продажи; индекс для `_fk_item` |
| `uq_sales_order_item` | `order_item_id` | Повторная обработка заказа не создаст вторую строку; индекс для `_fk_order_item` |
| `ix_sales_order` | `order_id` | Индекс для `_fk_order` |
| `ix_sales_user` | `user_id, sold_at` | «Мои покупки» |
| `ix_sales_sold_at` | `sold_at` | Отчёты за период |
| `ix_sales_record` | `book_record_id` | Индекс для `_fk_record` |
| `ix_sales_payment` | `payment_id` | `UPDATE … SET refunded_at … WHERE payment_id = ?` при полном возврате; индекс для `_fk_payment` |

### 3.18. `wp_book_sync_runs`

* `triggered_by`: `cron`, `wp_cli`, `admin`, `retry`; `status`: `running` → `succeeded`/`partial`/`failed`/
  `aborted`. `heartbeat_at` обновляется после каждого пакета; `running` без heartbeat дольше 900 с
  признаётся зависшим.
* `source_cursor VARCHAR(1024)` — точка продолжения: `NULL` — читать с начала, токен/страница источника —
  продолжить, `''` — источник прочитан, идёт проход «пропавших».
* `resumed_from_run_id` — упавший или зависший прогон, который продолжает этот; `pass_started_run_id` —
  первый прогон полного прохода: экземпляры с `last_seen_sync_run_id < pass_started_run_id` в этом проходе
  не встречались.
* Счётчики `records_*`, `items_*` (в т. ч. `items_conflicts`), `errors_count`; `error_log` JSON — не более N
  последних ошибок.
* `running_source` (STORED) дублирует `GET_LOCK(Db::lockName('sync_<source>'))` на уровне данных: именованная
  блокировка снимается при обрыве соединения, строка остаётся и видна в админке.

| Индекс | Колонки | Запрос |
|---|---|---|
| `PRIMARY` | `id` | `FOR UPDATE` прогона (уровень 0, только синхронизация) |
| `uq_sync_runs_one_running` | `running_source` (STORED) | Второй параллельный запуск источника получает 1062 |
| `ix_sync_runs_source` | `source_name, started_at` | Последний прогон источника, `GET /admin/sync/runs` |

### 3.19. `wp_book_audit_log`

Только `INSERT`. `actor_type`: `user`, `admin`, `system`, `cron`, `webhook`, `sync`, `cli`.
`entity_type` + `entity_id` — полиморфная ссылка; `entity_id` = `NULL`, если сущности нет (отклонённый
webhook, запрос синхронизации до создания прогона). `context` — JSON без секретов, PAN и лишних ПДн. FK нет
намеренно: аудит не блокирует бизнес-строки, а таблицу можно архивировать или партиционировать по
`occurred_at` (партиционированные таблицы InnoDB не поддерживают FK).

| Индекс | Колонки | Запрос |
|---|---|---|
| `PRIMARY` | `id` | |
| `ix_audit_entity` | `entity_type, entity_id, occurred_at` | История сущности |
| `ix_audit_action` | `action, occurred_at` | События типа за период |
| `ix_audit_actor` | `actor_user_id, occurred_at` | Действия пользователя или менеджера |

---

## 4. MARC 21 → колонки

Извлечение — `Sync\MarcExtractor::extract()` из `marc21_raw`. Общие правила строк: перевод в UTF-8 (MARC-8 при
Leader/09 = пробел) и **NFC**; подполя склеиваются через пробел; снимается завершающая ISBD-пунктуация
(` /`, ` :`, ` ;`, ` =`, `,`) и финальная точка, если она не часть сокращения (`ил.`, `Т. 1.`); длинные
значения обрезаются в PHP (`mb_substr`), а не нестрогим режимом MySQL; повторяющиеся поля — в порядке записи.

| MARC 21 | Колонка | Правило |
|---|---|---|
| Leader/06, /07 | `record_type`, `bib_level` | Как есть (`a` — текст, `c` — ноты; `m` — монография, `s` — сериальное) |
| 001 / 003 | `marc_control_number` / `marc_control_org` | Как есть. 001 **не** `source_record_id`: стабильный ключ — `book_id` интеграции |
| 245 $a ($n $p) | `title` | $a, а при $n/$p — `$a. $n, $p`: иначе тома многотомника неотличимы |
| 245 ind2 + $a | `title_sort` | Пропустить ind2 незначащих символов (`245 14 $a The Lord…` → `Lord…`) и фрагменты между NSB/NSE; NFC → `mb_strtolower` → не-буквы и не-цифры в пробел → схлопнуть → 255 символов (`MarcExtractor::sortKey()`) |
| 245 $b / $c | `subtitle` / `responsibility_statement` | Без завершающего ` /`; $c как в записи |
| 100/110/111 + 700/710/711 | `authors_text` | `$a $b $c $d` каждой персоны, роль из `$e`/`$4` в скобках, через `; ` |
| 100 $a (110/111 $a) | `main_author_sort` | Как `title_sort`; `NULL`, если 1XX нет |
| 1XX/7XX | `wp_book_contributors`, `wp_book_record_contributors` | Ключ, роль, позиция, `marc_tag` (раздел 3.2) |
| 020 $a | `isbn_primary`, `wp_book_identifiers` | Убрать уточнения в скобках, оставить цифры и `X`, проверить контрольную цифру, ISBN-10 → ISBN-13 (`MarcExtractor::normalizeIsbn()`: `0-306-40615-2` → `9780306406157`, `080442957X` → `9780804429573`, `9780306406158` → `null`). `isbn_primary` — первый **валидный** $a |
| 020 $z / $q | `identifiers.is_cancelled = 1` / `raw_value` | Отменённый ISBN ищется, но не основной; уточнение хранится в исходной строке |
| 022, 010, 035, 024 | `wp_book_identifiers` | ISSN, LCCN, OCLC (`(OCoLC)…`), EAN/ISMN по ind1 |
| 264 (ind2 = 1) / 260 | `publisher` ($b), `publication_place` ($a) | Приоритет 264 ind2 = 1 → 260 → 264 ind2 = 0/2/3; 264 ind2 = 4 — только источник года |
| 264 $c / 260 $c | `publication_date_text`, `publication_year` | Текст как есть (`[1905?]`, `c1999`); год — первое число 1400–2100 по `(?<!\d)(1[4-9]\d{2}\|20\d{2})(?!\d)` (`MarcExtractor::extractYear()`: `[1905?]` → 1905, `c1999` → 1999, `MDCCCXII` → `null`) |
| 008/06–10 | `publication_year` (резерв) | Date1, если тип даты не `b`/`n`/`\|` и четыре цифры. Год вне 1400–2100 → `NULL`, иначе `_chk_year` даст 3819 и сорвёт пакет |
| 250 | `edition_statement` | «2-е изд., испр. и доп.» |
| 041 $a / 008/35–37 | `language_code` | Первый код (слитные `engfre` → первые 3 символа); при ind2 = 7 с не-MARC кодами или без 041 — 008/35–37; `^[a-z]{3}$`, иначе `NULL` |
| 300 | `physical_description` | С пунктуацией записи: «312 с. : ил. ; 22 см» |
| 490 / 830 | `series_title` | 490 с `; $v`; если 490 нет — 830 |
| 600–655 | `subjects_text`, `wp_book_subjects` | $a + $v $x $y $z через ` -- `; в `subjects_text` через `; ` без дублей; 653 — только в `subjects_text` |
| 520 / 505 | `description` / `contents_note` | Несколько 520 — через пустую строку; 505 склеивается через ` -- ` |
| 856 $u | `source_url`, `cover_url` (резерв) | Только если интеграция не дала URL |

## 5. Что не раскладываем в колонки

Полная запись всегда в `marc21_raw`; в колонки выносится только то, по чему ищут, фильтруют, сортируют или
что выводится в списках.

| Поля MARC | Почему не колонки |
|---|---|
| 006, 007, 008 (кроме даты и языка) | Кодированные позиции не участвуют в поиске витрины |
| 040–084 (каталогизация, классификация) | Для фасета по УДК/ББК — отдельная таблица по образцу `wp_book_subjects`, когда появится требование |
| 130, 240, 246 | Кандидаты в FULLTEXT, если понадобится поиск по оригинальному заглавию |
| 5XX, кроме 505/520 (500, 504, 546, 561 провенанс, 563 переплёт, 590 автографы) | Важны **на карточке**, но не для поиска: карточка разбирает raw и кэширует в object cache по `record_id + source_checksum` |
| 76X–78X, 800–830 (кроме серии) | Навигация по связанным изданиям — отдельная задача |
| 852, 876–878 | Экземплярные данные приходят от интеграции в `wp_book_items` |
| 880 | Какую графику считать основной — решение по реальным данным; при необходимости экстрактор берёт 880, связанное с 245 через `$6` |
| 9XX | Семантика зависит от источника |

EAV «тег/индикаторы/подполе» дала бы 10–15 млн строк на 100k записей и не ускорила бы ни один нужный запрос;
raw позволяет перезапустить экстрактор по всем записям (WP-CLI) без обращения к источнику.

---

## 6. Уникальность «только среди активных»

Частичных индексов (`UNIQUE … WHERE status = 'active'`) в MySQL нет. Решение — STORED generated column,
равная ключу у активной строки и `NULL` у остальных, под `UNIQUE`; InnoDB допускает сколько угодно `NULL`:

```sql
active_book_item_id BIGINT UNSIGNED
    GENERATED ALWAYS AS (IF(reservation_status = 'active', book_item_id, NULL)) STORED,
UNIQUE KEY uq_reservations_one_active_per_item (active_book_item_id)
```

Две активные строки с одним экземпляром дают `ERROR 1062 … for key
'wp_book_reservations.uq_reservations_one_active_per_item'` (проверено). Уникальность проверяется на уровне
оператора, поэтому в одной транзакции сначала закрываем старую строку, потом вставляем новую.

| Таблица | Generated column | «Активна», если | Инвариант |
|---|---|---|---|
| `wp_book_carts` | `open_cart_user_id` | `status IN ('active', 'checkout_started')` | Одна открытая корзина на пользователя |
| `wp_book_reservations` | `active_book_item_id` | `reservation_status = 'active'` | Один активный резерв на экземпляр |
| `wp_book_cart_items` | `active_book_item_id` | `status = 'active'` | Экземпляр — активная позиция одной корзины |
| `wp_book_sync_runs` | `running_source` | `status = 'running'` | Один идущий прогон на источник |

Это **второй рубеж**. Первый — `SELECT … FOR UPDATE` строки экземпляра (или корзины): параллельный запрос ждёт
и видит уже изменённый статус. UNIQUE страхует от ошибки в коде, админского скрипта, ручного SQL.

Альтернативы и почему не они:

* **VIRTUAL + UNIQUE** — экономит 8 байт на строку, но менее явна; STORED добавляется в существующую таблицу
  только `ALGORITHM=COPY`, писать в неё нельзя (3105) — `$wpdb->insert()` колонку не передаёт.
* **Функциональный индекс** `UNIQUE ((IF(…)))` — скрытая колонка не видна в `information_schema.COLUMNS`,
  а индекс используется, только если в `WHERE` повторено **то же выражение**: естественное
  `WHERE status = 'active' AND book_item_id = 5` идёт полным сканом (проверено EXPLAIN).
* **Триггер** — без блокировок не защищает от гонки; права `TRIGGER` (с бинлогом — ещё `SUPER` или
  `log_bin_trust_function_creators`) на хостинге часто урезаны.
* **Только `FOR UPDATE`** — одна ошибка в коде молча создаст два активных резерва.

---

## 7. CHECK-ограничения и имена ограничений

MySQL проверяет CHECK с 8.0.16 (раньше молча игнорировал); мигратор отказывается работать ниже 8.0.16 и на
MariaDB.

### 7.1. Почему имена начинаются с имени таблицы

В MySQL имена CHECK и FOREIGN KEY уникальны **в пределах всей базы**, а не таблицы (проверено: второй
`CONSTRAINT ck_x CHECK …` в той же БД — `ERROR 3822 Duplicate check constraint name`, второй `fk_x` —
`ERROR 1826`). Поэтому короткие имена `ck_items_status` / `fk_items_record` из первой версии ломали установку
второго комплекта таблиц в той же БД: мультисайт (`wp_2_book_…`), вторая установка WordPress, staging-копия
с другим префиксом. На одном сервере заказчика живут `new.libsmr.ru` и `shop.libsmr.ru` — сценарий реальный.

В v2 имя = `<имя_таблицы>_chk_<x>` / `<имя_таблицы>_fk_<x>` (`wp_book_items_chk_status`,
`wp_book_items_fk_record`). Мигратор заменяет `wp_book_` на `{$wpdb->prefix}book_` одной заменой — имена
ограничений меняются вместе с таблицами, отдельно их префиксовать не нужно (проверено: схема с `wp_2_`
загружается в ту же БД рядом с `wp_`). Ошибки сопоставляются **по суффиксу**: `Db::isCheckViolation(
'_chk_attempt_range')` узнаёт и `wp_book_…`, и `wp_2_book_…`. Имена индексов (`uq_…`, `ix_…`, `ft_…`)
локальны для таблицы и не менялись.

### 7.2. Список (56)

Имена даны без `wp_book_`: `items_chk_status` = `wp_book_items_chk_status`.

| Группа | Ограничения | Что гарантирует |
|---|---|---|
| Перечисления (20) | `records_chk_source_format`, `records_chk_marc_format`, `contributors_chk_type`, `identifiers_chk_type`, `items_chk_status`, `items_chk_source_status`, `items_chk_condition`, `images_chk_role`, `user_consents_chk_type`, `carts_chk_status`, `reservations_chk_status`, `cart_items_chk_status`, `orders_chk_status`, `payments_chk_status`, `payment_events_chk_status`, `refunds_chk_status`, `refunds_chk_reason`, `sync_runs_chk_status`, `sync_runs_chk_trigger`, `audit_log_chk_actor` | Опечатка или `'AVAILABLE'` — ошибка 3819, а не мусор в данных (колонки `utf8mb4_bin`, проверено) |
| Статус ⇔ поле (10) | `items_chk_sold_at`, `reservations_chk_released`, `reservations_chk_attempt_admin`, `reservations_chk_order`, `carts_chk_closed`, `cart_items_chk_closed`, `orders_chk_paid_at`, `payments_chk_succeeded`, `refunds_chk_completed`, `sync_runs_chk_finished` | Нельзя сменить статус, «забыв» сопутствующее поле |
| Суммы > 0 (7) | `items_chk_price`, `cart_items_chk_price`, `order_items_chk_price`, `sales_chk_price`, `orders_chk_positive` (подытог и итог), `payments_chk_amount` (`amount > 0 AND refunded_amount <= amount`), `refunds_chk_amount` | Нулевая или «починенная» нестрогим режимом отрицательная сумма — ошибка |
| Арифметика (3) | `orders_chk_total` (итог = подытог − скидка + доставка [+ налог, если цены без налога]; вычисление в `SIGNED`), `orders_chk_refund` (возвращено ≤ итога), `reservations_chk_window` (`expires_at > reserved_at`) | Суммы и сроки не расходятся |
| Диапазоны и флаги (5) | `reservations_chk_attempt_range` (1..3), `records_chk_year` (1400..2100), `order_items_chk_quantity` (= 1), `records_chk_is_active`, `items_chk_is_active` | Лимит трёх попыток в БД; количество всегда 1 |
| Форматы (10) | `*_chk_currency` в `items`, `cart_items`, `orders`, `order_items`, `payments`, `refunds`, `sales` (`^[A-Z]{3}$`); `payments_chk_last4`; `customer_profiles_chk_phone` (E.164); `orders_chk_public_id` (`uniundata_` + UUID v4) | `utf8mb4_bin` делает `REGEXP` регистрозависимым: `'eur'` и UUID с заглавными hex отклоняются (проверено) |
| Владелец (1) | `images_chk_owner` | У изображения есть запись или экземпляр |

Приёмы: «A тогда и только тогда, когда B» — `(A) = (B)` над `NOT NULL`-выражениями; CHECK, вернувший `NULL`,
считается выполненным — поэтому `attempt_no BETWEEN 1 AND 3` пропускает `NULL` у `released_by_admin`.

Чего CHECK не умеет: видеть другие строки (уникальность — UNIQUE, связи — FK, сумма возвратов платежа — код
под блокировкой); недетерминированные функции (`UTC_TIMESTAMP()` — «резерв не истёк» проверяет код);
**старое значение** при `UPDATE` (допустимость перехода — условный `UPDATE … WHERE status IN (…)`,
`docs/04-statuses.md`, раздел 13).

Обработка: `3819 Check constraint 'wp_book_reservations_chk_attempt_range' is violated` (или 1062 по
`uq_reservations_attempt`) → 409 `uniundata_reservation_limit_reached`; любое другое нарушение — ошибка в
коде → 500 `uniundata_internal` + аудит.

### 7.3. Изменение CHECK — только копированием таблицы

| DDL | Алгоритм на 8.0.46 (проверено) |
|---|---|
| `DROP CHECK` | `INSTANT` (только метаданные) |
| `ADD CONSTRAINT … CHECK (…)` (ENFORCED), `ALTER CHECK … ENFORCED` | **только `ALGORITHM=COPY`**: `INPLACE`/`INSTANT` → `ERROR 1845`, `LOCK=NONE` → `ERROR 1846`. MySQL перепроверяет все строки, таблица копируется, запись в неё на это время заблокирована (`LOCK=SHARED`) |
| `ADD … CHECK (…) NOT ENFORCED` | `INSTANT`, но ничего не защищает |

Новый статус: сначала код, который понимает оба набора, затем миграция в окно обслуживания одним оператором,
чтобы не было момента без проверки:

```sql
ALTER TABLE wp_book_carts
  DROP CHECK wp_book_carts_chk_status,
  ADD CONSTRAINT wp_book_carts_chk_status CHECK (status IN (…, 'new_status')),
  ALGORITHM=COPY, LOCK=SHARED;
```

Для `wp_book_items` (100k строк) копирование занимает секунды; для `wp_book_audit_log` — заметно дольше,
поэтому у аудита только одно перечисление (`actor_type`).

---

## 8. FOREIGN KEY без каскадов

30 FOREIGN KEY между таблицами плагина, все без `ON DELETE`/`ON UPDATE` (в InnoDB `NO ACTION` = `RESTRICT`,
проверяется сразу). Список с кардинальностями — `docs/02-er-diagram.md`, раздел 3.1.

* Бизнес-строки не удаляются: `CASCADE` превратил бы случайный `DELETE` записи книги в молчаливое уничтожение
  истории продаж, `RESTRICT` даёт ошибку 1451 (проверено); `SET NULL` терял бы связь заказа с экземпляром.
* FK не даёт создать резерв на несуществующий экземпляр, позицию без заказа, продажу или возврат без платежа.
* Индекс на колонке потомка объявлен явно для всех 30 FK (в первой версии четыре создавал сам MySQL под
  именем ограничения).
* Цикл резерв ↔ заказ: `wp_book_reservations_fk_order` добавляется `ALTER TABLE` после `wp_book_orders`.

**FK-проверка ставит S-блокировку на родительскую строку**, и `INSERT` в потомка ждёт, если родитель
X-заблокирован (проверено). В глобальном порядке блокировок (`docs/08-security-concurrency.md`: 0 —
`sync_runs`, `records`; 1 — `carts`; 2 — `items`; 3 — `reservations`; 4 — `cart_items`; 5 — `orders`;
6 — `payments`; 7 — `payment_events`; 8 — `refunds`) родители вставок уже заблокированы той же транзакцией.
Исключение — `wp_book_records`: `INSERT` в `order_items` (checkout) и `sales` (webhook) берёт S на запись
**после** экземпляров. Цикла нет, потому что синхронизация никогда не держит X на записи и её экземплярах в
одной транзакции: сначала транзакция по записям пакета, затем отдельная — по экземплярам.

## 9. Почему нет FK на wp_users

`user_id` (и `requested_by`, `actor_user_id`) ссылается на `wp_users.ID` **логически**:

1. **Удаление пользователя.** `wp_delete_user()` не обрабатывает ошибку FK: с `RESTRICT` удаление оборвётся
   на середине (метаданные уже удалены), с `CASCADE` сотрёт заказы и продажи, которые обязаны храниться по
   бухгалтерскому и налоговому учёту.
2. **Таблица ядра — не наша.** Миграции ядра, плагины переноса, staging, импорт пользователей не ожидают
   входящих FK.
3. **Мультисайт.** `wp_users` общая для сети (при `CUSTOM_USER_TABLE` — и для нескольких установок), таблицы
   плагина — на сайт.
4. **Снимки.** Контакты и адреса скопированы в заказ — он читается и без строки `wp_users`.

Чем заменено: `user_id` берётся только из `get_current_user_id()` (или проверенного администратором ID);
удаление пользователя (решение `docs/07-users-roles.md`) через `map_meta_cap('delete_user')` запрещено, пока
у него есть заказы в `pending_payment`, `payment_processing` или `paid`; иначе хук `delete_user` (до удаления
строки) снимает активные резервы, закрывает корзину и отменяет неоплаченные заказы, а контакты в закрытых
заказах обезличивают задачи `uniundata_user_deleted_cleanup` и `uniundata_privacy_retention` (отметка —
`orders.pii_erased_at`). Заказы и продажи сохраняют `user_id` как исторический идентификатор. Экспорт и
удаление ПДн по запросу — штатные `wp_privacy_personal_data_exporters` / `…_erasers` (152-ФЗ; для ЕС — GDPR).
Контроль «висячих» ссылок:
`SELECT o.id FROM wp_book_orders o LEFT JOIN wp_users u ON u.ID = o.user_id WHERE u.ID IS NULL`.

## 10. Почему не dbDelta

`dbDelta()` делит `CREATE TABLE` по строкам регулярными выражениями: `CONSTRAINT … CHECK/FOREIGN KEY`
принимает за колонки (повторный запуск генерирует бессмысленный `ADD COLUMN`), многострочные
`GENERATED ALWAYS AS …` распадаются, выражение generated column в `DESCRIBE` не видно. Нет `ALGORITHM`/`LOCK`,
переименований, удаления, backfill и порядка шагов (цикл FK).

`Install\Migrator` вместо этого:

* выполняет каноническую `sql/schema.sql`: `wp_book_` → `{$wpdb->prefix}book_` (вместе с именами
  ограничений), `CREATE TABLE IF NOT EXISTS`, `ADD CONSTRAINT` — только если ограничения нет;
* сам задаёт сессию DDL: `SET NAMES utf8mb4 COLLATE utf8mb4_unicode_520_ci` и
  `SET SESSION innodb_ft_enable_stopword = OFF` (раздел 11), затем возвращает прежнее;
* до DDL проверяет MySQL ≥ 8.0.16, не MariaDB, InnoDB, `utf8mb4_unicode_520_ci`, права (`REFERENCES` для FK);
  предупреждает, если `innodb_ft_min_token_size ≠ 3` или `@@global.time_zone` не UTC;
* сверяет результат с `information_schema` (колонки, кодировки, индексы, generated columns, CHECK/FK) — на
  случай таблиц, созданных вручную; расхождение — исключение, данные не трогаются;
* хранит версию в option `uniundata_db_version` и работает под `GET_LOCK(Db::lockName('migrate'))` (имя
  включает отпечаток БД и префикса); в продакшене — `wp uniundata migrate` при деплое.

## 11. FULLTEXT-поиск

| Индекс | Колонки | Для чего |
|---|---|---|
| `ft_records_main` | `title, subtitle, authors_text, series_title` | Основная строка поиска |
| `ft_records_all` | + `responsibility_statement, subjects_text, description, publisher` | «Искать везде» |
| `ft_contributors_name` | `name_display` | Автодополнение автора |
| `ft_subjects_heading` | `heading` | Автодополнение рубрики |

Колонки в `MATCH(…)` должны **точно** совпадать с одним из индексов, иначе `ERROR 1191` (проверено) — поэтому
два фиксированных набора.

* **Без стоп-слов.** Встроенный список InnoDB — 36 английских слов, среди них `de`, `la`, `en`, `und`, `the`.
  В `BOOLEAN MODE` обязательное стоп-слово обнуляет результат (`'+und +krieg'` → 0 строк). Список фиксируется
  в индексе **в момент создания**, поэтому `sql/schema.sql` и мигратор выполняют
  `SET SESSION innodb_ft_enable_stopword = OFF` перед `CREATE TABLE`. Проверено: `'+the +lord'` и
  `'+und +krieg'` находят книги, хотя глобальное значение переменной на сервере осталось `ON`. Отдельная
  таблица стоп-слов не нужна. Индексы, построенные иначе (таблица создана вручную), мигратор перестраивает.
* **Минимальная длина слова** — `innodb_ft_min_token_size = 3` (серверная, read-only; изменение — перезапуск
  MySQL и `wp uniundata migrate --rebuild-fulltext`). Слова из 1–2 символов не индексируются, и обязательное
  `+ад` обнуляет выдачу (проверено), поэтому построитель запроса **не делает их обязательными** — отбрасывает
  («война и мир» → `+война* +мир*`). Если длинных слов нет — подсказка «уточните запрос» или префиксный поиск
  `title_sort LIKE 'ад%'` по `ix_records_active_title`. Ввод, похожий на ISBN, идёт через
  `ix_identifiers_lookup` / `ix_records_isbn`.
* **Построитель запроса** (PHP): NFC, `mb_strtolower`, `preg_split('/[^\p{L}\p{N}]+/u', …)` (операторы
  `+ - < > ( ) ~ * " @` отбрасываются), не более 8 слов, каждое → `+слово*`; выражение передаётся только через
  `$wpdb->prepare('… AGAINST (%s IN BOOLEAN MODE)', $expr)`. Пример — `sql/queries.sql` 2.1.
* **Collation.** Слова сравниваются по collation колонки: `+елка` находит «Ёлка», `+muller` — «Müller»
  (проверено).
* **Обслуживание.** Изменения индекса видны после COMMIT; изменение `title` помечает старый документ
  удалённым, поэтому записи с неизменным `source_checksum` не перезаписываются. После массовых изменений —
  `OPTIMIZE TABLE` с `innodb_optimize_fulltext_only = ON` в окно обслуживания.
* **CJK и масштаб.** Встроенный парсер делит текст по пробелам («红楼» не находит «红楼梦», парсер `ngram`
  находит — проверено); при значимой доле CJK — отдельная таблица `WITH PARSER ngram`. Морфология, опечатки,
  каталог от 1 млн записей — внешний поиск (OpenSearch, Manticore) из тех же колонок; доступность экземпляра
  всегда читается из MySQL.

## 12. Почему ISBN не уникален

* Один `book_id` — один экземпляр, по умолчанию своя запись: два экземпляра одного издания — две записи с
  одним ISBN. `UNIQUE` на ISBN сломал бы синхронизацию на втором экземпляре.
* Издатели переиспользуют ISBN, у многотомника есть ISBN комплекта и томов, в 020$z — отменённые номера,
  у книг до 1970-х ISBN нет.
* `ix_records_isbn` и `ix_identifiers_lookup` возвращают **список** записей. Уникальность нужна там, где она
  есть: `uq_items_external`, `uq_records_source`, `uq_identifiers_record` (внутри записи).

## 13. Кодировки и collation

* **Всё `utf8mb4`**: дореформенная кириллица (ѣ, і, ѳ, ѵ), CJK, символы вне BMP.
* **Таблицы — `utf8mb4_unicode_520_ci`, как ядро WordPress** (`$wpdb->get_charset_collate()` на MySQL 8, у
  заказчика это collation сайта). При разных collation сравнение строк плагина и ядра
  (`wp_users.user_email = o.customer_email`) падает с `ERROR 1267 Illegal mix of collations` (проверено).
  Нечувствителен к регистру и диакритике (`е` = `ё`, `u` = `ü`) — то, что нужно для `title_sort` и поиска.
* **Коды, статусы, внешние ID, хэши — `utf8mb4_bin`.** Сравнение регистрозависимое (проверено):
  `uq_items_external` различает `AbC-1` и `abc-1` — с `_ci` upsert синхронизации обновил бы **чужой**
  экземпляр (то же для `uq_records_source`, `uq_payments_provider_id`, `uq_payment_events_provider`,
  `uq_refunds_provider_id`); CHECK отклоняют `'eur'` и `'AVAILABLE'`, которые SQL с `_ci` счёл бы равными
  `'EUR'`/`'available'`, а PHP (`===`) — нет. `utf8mb4_bin` — PAD SPACE: `'i1 '` = `'i1'` в UNIQUE
  (проверено), пробелы во внешних ID запрещает валидация PHP.
* **Почему не `CHARACTER SET ascii`, как в первой версии.** `wpdb::query()` для запроса с не-ASCII символами
  вызывает `strip_invalid_text_from_query()`, а та — `get_table_charset()` первой таблицы запроса. Метод
  собирает кодировки колонок (`SHOW FULL COLUMNS`); две разные кодировки, кроме пары `utf8`/`utf8mb4` и
  `latin1`, дают `'ascii'` (WordPress 7.1.2, `class-wpdb.php`). Текст запроса перекодируется через `ascii`,
  кириллица становится `?`, строка не совпадает с исходной — и `wpdb` отказывается выполнять запрос
  («Could not perform query because it contains invalid data»). Любой запрос с кириллицей (название, ФИО,
  адрес, поисковая строка) к таблице со смешанными `ascii` + `utf8mb4` колонками не выполнялся без фильтра
  `pre_get_table_charset`. В v2 кодировка одна, `get_table_charset()` возвращает `'utf8mb4'`, фильтр удалён
  из `Plugin.php`. Таблица с `VARBINARY`/`BLOB`-колонкой получает `'binary'` и не перекодируется вовсе —
  это `wp_book_user_consents` (`ip_address`), что безопасно: строки валидирует PHP.
* **Цена отказа от `ascii` почти нулевая.** `utf8mb4` — кодировка переменной длины: ASCII-символ занимает
  1 байт и в строке, и в индексе (`CHAR(n)` — минимум `n` байт). По 4 байта на символ считаются только для
  предела ключа 3072 байта: самые длинные составные ключи (`uq_records_source`, `uq_items_external`) —
  `(64 + 191) × 4 = 1020`. Поэтому `*_sort` — `VARCHAR(255)` (1020 байт), а `title VARCHAR(1000)`
  индексируется только в FULLTEXT.
* **Клиент.** `wpdb` сам выполняет `SET NAMES utf8mb4`; скрипты вне WP и тесты — только
  `mysql --default-character-set=utf8mb4`, иначе клиент в `latin1` молча портит кириллицу.

## 14. Оценка объёмов

Допущения (уточнить у заказчика): 100 000 экземпляров (1:1 с записями) + 30 000 в год; 25 000 продаж в год
(≈ 50 заказов в день по 1,5 книги); ≈ 300 резервов в день; 1,3 попытки оплаты на заказ, ≈ 3 webhook-а на
платёж; возвраты — единицы процентов заказов; MARCXML 3–6 КБ.

| Таблица | Строк (старт / в год) | Байт на строку с индексами (≈) | Объём |
|---|---|---|---|
| `wp_book_records` | 100k / +30k | 7 КБ (raw 4 КБ, поля 1,5 КБ, B-tree 0,5 КБ, FULLTEXT ≈ 1 КБ) | ≈ 0,7 ГБ, +0,2 ГБ/год |
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
| `wp_book_payments` | 24k/год | 0,6 КБ (с `session_redirect_url`) | ≈ 15 МБ/год |
| `wp_book_payment_events` | 70k/год | 1,5 КБ (JSON) | ≈ 100 МБ/год |
| `wp_book_refunds` | < 1k/год | 0,4 КБ | < 1 МБ/год |
| `wp_book_sales` | 25k/год | 0,25 КБ | ≈ 6 МБ/год |
| `wp_book_sync_runs` | 365–730/год | до 10 КБ (`error_log`) | < 10 МБ/год |
| `wp_book_audit_log` | 2–3 млн/год | 0,4 КБ | **≈ 1 ГБ/год** — главный источник роста |

* Каталог на 100k экземпляров — около 1 ГБ (основная доля — `marc21_raw`), на 1 млн — около 10 ГБ.
* Транзакционные таблицы растут на ≈ 0,3 ГБ в год, партиционирование не нужно.
* `wp_book_audit_log` — архивировать по сроку хранения (единственная таблица, где удаление допустимо
  политикой) или партиционировать по `occurred_at` (PK тогда должен включать `occurred_at`).
* Конкуренция определяется блокировками, а не объёмом: `FOR UPDATE` берётся на строку экземпляра, пиковая
  нагрузка конфликтует только на одних и тех же книгах.
* `innodb_buffer_pool_size` — не меньше 1–2 ГБ.

Фактические размеры:

```sql
SELECT table_name, table_rows,
       ROUND(data_length  / 1024 / 1024) AS data_mb,
       ROUND(index_length / 1024 / 1024) AS index_mb
  FROM information_schema.TABLES
 WHERE table_schema = DATABASE() AND table_name LIKE 'wp\_book\_%'
 ORDER BY data_length + index_length DESC;
```
