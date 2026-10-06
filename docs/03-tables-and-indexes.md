# 03. Таблицы, поля, индексы и ограничения

Источник истины — `sql/schema.sql` (схема v2). Здесь — **зачем** нужны таблицы, индексы и ограничения и какие
решения приняты под WordPress 7.1.2, PHP 8.3+ и MySQL 8.0.16+ (у заказчика 8.0.46, `utf8mb4_unicode_520_ci`).
«Проверено» — воспроизведено на MySQL 8.0.46 на этой схеме.

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
| Движок, ключи | InnoDB; `BIGINT UNSIGNED AUTO_INCREMENT` | Транзакции, `FOR UPDATE`, FK, FULLTEXT; тип как у `wp_users.ID` |
| Деньги | `INT UNSIGNED` в копейках + `CHECK (… > 0)` | Без округлений, в PHP — `int`; 1 500 ₽ = `150000`, предел 42 949 672,95 ₽ |
| Валюта | `CHAR(3) COLLATE utf8mb4_bin` + `CHECK (currency REGEXP '^[A-Z]{3}$')` у каждой суммы | Валюта магазина — option `uniundata_currency` (мигратор ставит `'RUB'`, раздел 10); экземпляр в другой валюте синхронизация не импортирует, резерв — 409. `DEFAULT 'EUR'` у `items.currency` не используется: синхронизация передаёт валюту явно |
| Даты | `DATETIME(6)`, UTC | `Db::transaction()` на время транзакции ставит `time_zone = '+00:00'` и возвращает прежнее; в SQL — `UTC_TIMESTAMP(6)`. `DEFAULT`/`ON UPDATE CURRENT_TIMESTAMP(6)` зависят от зоны сессии, поэтому все записи — через `Db`; на сервере рекомендуется `default-time-zone = '+00:00'` (у заказчика UTC+4 — мигратор предупреждает). Не `TIMESTAMP`: нет пересчёта по зоне и проблемы 2038 |
| Статусы | `VARCHAR(n) COLLATE utf8mb4_bin` + `CHECK (… IN (…))` | Неверный `ENUM` в нестрогом `sql_mode` WordPress молча становится `''`, CHECK даёт 3819. Цена — новый статус добавляется с копированием таблицы (7.3) |
| Строки | Всё `utf8mb4`: таблицы `utf8mb4_unicode_520_ci`; коды, статусы, хэши, внешние ID — `utf8mb4_bin` | Раздел 13 |
| Имена | CHECK/FK — `<таблица>_chk_<x>` / `<таблица>_fk_<x>`; индексы — `uq_`, `ix_`, `ft_` | Имена CHECK/FK уникальны в БД, индексов — в таблице (7.1) |
| Флаги | `TINYINT(1)` + `CHECK (x IN (0, 1))` | |
| Удаление | Бизнес-строки не удаляются | Корзины, резервы, заказы, платежи, возвраты, продажи меняют статус; отсюда FK без каскадов |
| Префикс | `wp_` условный | Мигратор заменяет `wp_book_` на `{$wpdb->prefix}book_` одной заменой — с именами ограничений |

**Нестрогий `sql_mode`.** `wpdb` убирает `STRICT_*`, и MySQL до проверки CHECK/UNIQUE/FK «чинит» значения:
`-100` в `INT UNSIGNED` → `0` (1264), длинная строка обрезается (1265). Защита: `CHECK (… > 0)` превращает
«починенный» `0` в 3819 (проверено: `-100` в `price_amount` → `wp_book_items_chk_price`); `Db::transaction()`
на время транзакции добавляет `STRICT_TRANS_TABLES` и восстанавливает режим (глобально нельзя — ядро и чужие
плагины рассчитывают на нестрогий), и приходят ошибки 1406/1264/1366; PHP валидирует вход (суммы `int > 0`,
внешние ID `/^[\x21-\x7E]{1,191}$/`, статусы — backed enum).

---

## 2. Список таблиц

21 таблица; ниже без префикса `wp_book_`.

| Группа (растёт от) | Таблицы |
|---|---|
| Каталог (синхронизация) | `records` — запись MARC 21 + поля витрины и поиска; `contributors` + `record_contributors` — персоны 1XX/7XX с ролью и порядком; `subjects` + `record_subjects` — рубрики 6XX с тезаурусом; `identifiers` — ISBN (в т. ч. 020$z), ISSN, LCCN, OCLC, EAN, ISMN; `items` — **продаваемый экземпляр**; `images` — изображения записи или экземпляра (и из админки) |
| Покупатель (оформление) | `customer_profiles` — телефон E.164, адреса, B2B; `user_consents` — доказательство согласия, append-only |
| Корзина (резервы) | `carts` — не более одной открытой на пользователя; `reservations` — история и текущие резервы, попытка 1..3; `cart_items` — представление резерва + снимок цены |
| Заказ и деньги (checkout, `/pay`, webhook) | `orders`; `order_items` — со снимками; `payments` — попытки оплаты; `payment_events` — inbox webhook-ов после проверки подписи; `refunds` — очередь возвратов; `sales` — факт продажи |
| Служебные | `sync_runs` — журнал синхронизации (cron); `audit_log` — аудит, append-only |

Не создаём: таблицу пользователей (`wp_users` + `wp_usermeta`: `first_name`, `last_name`, `middle_name`);
«товары» WooCommerce (карточка = запись + экземпляры); custom post type (100k+ строк `wp_posts`/`wp_postmeta` —
EAV без индексов и транзакционных инвариантов).

---

## 3. Таблицы: ключевые поля и индексы

По `information_schema`: 105 индексов — 21 PRIMARY, 25 UNIQUE (4 по generated columns), 4 FULLTEXT, 55 обычных;
56 CHECK; 30 FOREIGN KEY, у каждого явный индекс («для `_fk_x`» = индекс FK `wp_book_<таблица>_fk_x`). PRIMARY —
`id`, кроме связок (составной) и профиля (`user_id`); ниже он упомянут, только если у него особая роль.

### 3.1. `wp_book_records`

`source_name` + `source_record_id` — внешний ключ записи (по умолчанию `= book_id`, допускается группировка
экземпляров издания). `source_format` — как пришло (`marcxml`, `marc_json`, `iso2709`, `mrk`), `marc21_format` —
как хранится (`marcxml`/`marc_json`). `marc21_raw MEDIUMTEXT` — полная запись: экстрактор перезапускается без
источника. `source_checksum` — SHA-256 канонизированной записи (NFC, пробелы, без 005): совпал — пропуск
(`records_skipped`), FULLTEXT не трогается. `title VARCHAR(1000)` — вывод и FULLTEXT, `title_sort VARCHAR(255)` —
B-tree. `is_active` — видимость; строка не удаляется.

| Индекс | Колонки | Запрос |
|---|---|---|
| `uq_records_source` | `source_name, source_record_id` | Upsert синхронизации без дублей |
| `ix_records_isbn` | `isbn_primary` | ISBN в строке поиска (`Using index`, проверено) |
| `ix_records_marc001` | `marc_control_org, marc_control_number` | Сопоставление по MARC 001/003 |
| `ix_records_active_title` | `is_active, title_sort` | Алфавит без filesort; префикс `LIKE 'ад%'` |
| `ix_records_active_author` | `is_active, main_author_sort` | Указатель и сортировка по автору |
| `ix_records_active_year` | `is_active, publication_year` | `BETWEEN` по году — range + `Using index` |
| `ix_records_language` | `language_code` | Фасет «язык» |
| `ix_records_sync` | `source_name, last_seen_sync_run_id` | Не пришедшие в проходе (range, проверено) |
| `ft_records_main`, `ft_records_all` | раздел 11 | Поиск |

### 3.2. Персоны и рубрики: `contributors`, `record_contributors`, `subjects`, `record_subjects`

`contributor_key` = SHA-256 от authority ID (`$0`/`$1`: VIAF, GND, LC NAF), иначе от `имя|даты`
(`толстой, лев николаевич|1828-1910`) — даты не склеивают однофамильцев. `role_code` — relator из `$4`, иначе из
`$e` по словарю («пер.» → `trl`, «ред.» → `edt`), для 1XX — `aut`; входит в PK (автор и иллюстратор одной книги).
`subject_key` = SHA-256 от `тезаурус|рубрика` (LCSH и GND — разные строки); `heading` — подразделения через
` -- `; `thesaurus` — из ind2 (0 → `lcsh`, 2 → `mesh`, 6 → `rvm`) или `$2` при ind2 = 7 (`gnd`, `rero`, `nlr`).

| Таблица | Индекс | Колонки | Запрос |
|---|---|---|---|
| contributors | `uq_contributors_key` | `contributor_key` | Upsert `ON DUPLICATE KEY UPDATE` |
| contributors | `ix_contributors_sort` | `name_sort` | Указатель А–Я |
| contributors | `ft_contributors_name` | `name_display` | Автодополнение «автор» |
| record_contributors | `PRIMARY` | `book_record_id, contributor_id, role_code` | Персоны записи; для `_fk_record` |
| record_contributors | `ix_rc_contributor` | `contributor_id, book_record_id` | Книги автора; для `_fk_contributor` |
| subjects | `uq_subjects_key` | `subject_key` | Upsert рубрики |
| subjects | `ix_subjects_sort` | `heading_sort` | Рубрикатор А–Я |
| subjects | `ft_subjects_heading` | `heading` | Поиск рубрики |
| record_subjects | `PRIMARY` | `book_record_id, subject_id` | Рубрики записи; для `_fk_record` |
| record_subjects | `ix_rs_subject` | `subject_id, book_record_id` | Книги рубрики; для `_fk_subject` |

### 3.3. `wp_book_identifiers`

`id_value` — нормализованное значение (ISBN без дефисов, ISBN-10 → ISBN-13), `raw_value` — как в записи с `$q`.
`is_cancelled = 1` для 020$z / 022$z: не попадают в `isbn_primary`, но ищутся (на старых книгах часто напечатан
ошибочный номер).

| Индекс | Колонки | Запрос |
|---|---|---|
| `uq_identifiers_record` | `book_record_id, id_type, id_value` | Идемпотентная перезапись; для `_fk_record`; уникальность **внутри записи** (раздел 12) |
| `ix_identifiers_lookup` | `id_type, id_value` | Любой ISBN/ISSN/OCLC → список записей |

### 3.4. `wp_book_items`, `wp_book_images`

`external_item_id` — внешний `book_id`, **один физический экземпляр** (`NULL` у заведённых вручную, регистр
значим). `price_amount` (`> 0`) + `currency` — цена каталога, в корзину и заказ копируется снимок.
`availability_status` — локальный статус (`docs/04-statuses.md`), `source_status` (`present`, `missing`,
`withdrawn`) — мнение источника: синхронизация не трогает `reserved`, `checkout_pending`, `sold`, `blocked`, при
освобождении статус берётся по `source_status`. `status_changed_at` — время смены статуса, `sold_at` заполнен ⇔
`sold` (`_chk_sold_at`), `source_checksum` — SHA-256 полей экземпляра из источника. PRIMARY — **точка
сериализации**: `SELECT … FOR UPDATE` по id в резерве, checkout, webhook, освобождении (уровень 2).
Изображение принадлежит записи **или** экземпляру (`images_chk_owner`); `attachment_id` — `wp_posts.ID`
(логически), без вложения показывается `source_url`.

| Таблица | Индекс | Колонки | Запрос |
|---|---|---|---|
| items | `uq_items_external` | `source_name, external_item_id` | Upsert синхронизации; `AbC-1` ≠ `abc-1` (проверено); `NULL` допустимы |
| items | `uq_items_inventory` | `inventory_number` | Поиск по инвентарному; collation таблицы — `A-1` = `a-1`; конфликтный номер синхронизация не пишет |
| items | `ix_items_record_status` | `book_record_id, availability_status` | Экземпляры записи, «есть в продаже»; для `_fk_record` |
| items | `ix_items_catalog` | `is_active, availability_status, price_amount` | «В продаже» по цене — `Using index` без filesort (проверено) |
| items | `ix_items_sync` | `source_name, last_seen_sync_run_id` | Кандидаты в `sync_missing` |
| images | `ix_images_record` / `ix_images_item` | `book_record_id` / `book_item_id`, `position` | Галереи; для `_fk_record` / `_fk_item` |

### 3.5. Покупатель: `customer_profiles`, `user_consents`

ID, email, пароль — `wp_users` магазина; ФИО — `wp_usermeta` (`middle_name` необязателен). Профиль (1 : 0..1 с
`wp_users`, PK `user_id`): `phone_e164` (`_chk_phone`, `phone_verified_at`), адреса по умолчанию (JSON, только
автозаполнение — истина в снимке заказа), `company_name`, `vat_id`. Согласие (152-ФЗ; для ЕС — GDPR):
`consent_type` (`offer`, `privacy`, `marketing`), `document_version` + `document_sha256` (option
`uniundata_terms_versions`), `accepted_at`; `ip_address VARBINARY(16)` и `user_agent_sha256` обнуляются по сроку;
отзыв — `withdrawn_at`, строка не удаляется.

| Таблица | Индекс | Колонки | Запрос |
|---|---|---|---|
| customer_profiles | `ix_profiles_phone` | `phone_e164` | Поиск покупателя по телефону |
| user_consents | `uq_consents_uuid` | `consent_uuid` | Доказательство по техническому ID |
| user_consents | `ix_consents_user` | `user_id, consent_type, accepted_at` | Действующее согласие: `ORDER BY accepted_at DESC LIMIT 1` |
| user_consents | `ix_consents_retention` | `accepted_at` | `uniundata_privacy_retention`: обнуление IP — range |

### 3.6. Корзина: `carts`, `reservations`, `cart_items`

* **Корзина** открыта при `active`/`checkout_started`, закрыта — `closed_at` (`_chk_closed`). `expires_at` —
  `MIN` сроков активных позиций, только для таймера. `last_activity_at` — reserve/remove/checkout.
* **Резерв**: `attempt_no` 1..3 (`NULL` только у `released_by_admin`); новый номер = `1 + COUNT(*)` резервов пары
  `(user_id, book_item_id)` с `attempt_no IS NOT NULL` под блокировкой экземпляра. `expires_at = reserved_at +
  1 час` (`_chk_window`), не продлевается. `released_at` — выход из `active` (`_chk_released`); `release_reason`
  — код (`user_removed`, `expired`, `checkout_<причина>`, `admin_block`, `user_deleted`, `converted_to_order`);
  `order_id` — при `converted_to_order` (`_chk_order`).
* **Позиция**: `reservation_id` UNIQUE, `expires_at` — копия срока резерва, `unit_price_amount` (`> 0`) +
  `currency` — снимок. После удаления повторный резерв той же книги в ту же корзину разрешён.

| Таблица | Индекс | Колонки | Запрос |
|---|---|---|---|
| carts | `uq_carts_one_open_per_user` | `open_cart_user_id` (STORED) | Одна открытая корзина **и** точка блокировки `WHERE open_cart_user_id = ? FOR UPDATE` (уровень 1); создание — `INSERT … ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)` |
| carts | `ix_carts_user` | `user_id, created_at` | История корзин |
| carts | `ix_carts_status_activity` | `status, last_activity_at` | `uniundata_abandon_carts`: пустые без активности 30 дней |
| reservations | `uq_reservations_one_active_per_item` | `active_book_item_id` (STORED) | Второй рубеж после `FOR UPDATE` экземпляра (1062 → 409 `uniundata_item_unavailable`) |
| reservations | `uq_reservations_attempt` | `user_id, book_item_id, attempt_no` | Лимит трёх попыток (с `_chk_attempt_range`); подсчёт попыток |
| reservations | `ix_reservations_expiry` | `reservation_status, expires_at` | Cron: активные с `expires_at <= UTC_TIMESTAMP(6)` — range, `Using index` (проверено) |
| reservations | `ix_reservations_user` | `user_id, reservation_status, expires_at` | Лимит `uniundata_max_active_reservations` (10) |
| reservations | `ix_reservations_item_history` | `book_item_id, reserved_at` | История экземпляра; для `_fk_item` |
| reservations | `ix_reservations_cart` / `ix_reservations_order` | `cart_id` / `order_id` | Для `_fk_cart` / `_fk_order` |
| cart_items | `uq_cart_items_reservation` | `reservation_id` | 1:1 с резервом; для `_fk_reservation` |
| cart_items | `uq_cart_items_one_active_per_item` | `active_book_item_id` (STORED) | Экземпляр — активная позиция одной корзины |
| cart_items | `ix_cart_items_cart` | `cart_id, status` | `GET /cart`; для `_fk_cart` |
| cart_items | `ix_cart_items_expiry` | `status, expires_at` | Контроль истёкших позиций |
| cart_items | `ix_cart_items_item` | `book_item_id` | История; для `_fk_item` |

Проверка дубликата в `uq_carts_one_open_per_user` ставит gap-блокировку на ещё не вычищенную запись закрытой
корзины даже в READ COMMITTED: при параллельных checkout (закрывает корзину) и reserve (создаёт новую) изредка
возможен deadlock 1213 (воспроизведён стресс-тестом). Инварианты не нарушаются: `Db::transaction()` повторяет
транзакцию.

### 3.7. Заказ: `orders`, `order_items`

* `public_order_id` — `uniundata_<UUID v4>` (`_chk_public_id`, строчные hex), `id` наружу не отдаётся;
  `checkout_request_id` — `Idempotency-Key` запроса `/checkout`.
* Суммы: `subtotal_amount`, `total_amount` (`_chk_positive`), `discount_amount`, `shipping_amount`,
  `tax_amount`, `refunded_amount` (`_chk_refund`: ≤ итога); `_chk_total` — арифметика с `prices_include_tax`.
* Снимок покупателя: `customer_email`, `customer_phone`, `customer_first_name` / `_last_name` / `_middle_name`,
  `billing_address_json`, `shipping_address_json`, `shipping_method`. Позиции —
  снимки `title_`, `subtitle_`, `author_`, `isbn_`, `publisher_`, `publication_year_`, `condition_`, `cover_url_`,
  `item_identifier_snapshot`, `unit_price_amount` (`> 0`), `currency`; `quantity` = 1 (`_chk_quantity`).
* `payment_due_at` = checkout + `uniundata_payment_ttl_minutes` (30) + `uniundata_payment_grace_minutes` (10);
  `payment_due_extended_at` — однократное продление, пока банк отвечает «в обработке»; `pii_erased_at` — контакты
  обезличены по сроку; `needs_attention` + `attention_reason` — ручной разбор (`docs/04-statuses.md`, 7.6).
  PRIMARY — `FOR UPDATE` заказа (уровень 5).

| Таблица | Индекс | Колонки | Запрос |
|---|---|---|---|
| orders | `uq_orders_public_id` | `public_order_id` | `GET /orders/{id}`, `/pay`, `/cancel`; webhook |
| orders | `uq_orders_checkout_request` | `user_id, checkout_request_id` | Идемпотентность `/checkout`; чужой ключ не раскроет чужой заказ |
| orders | `ix_orders_user` | `user_id, created_at` | `GET /orders` |
| orders | `ix_orders_payment_due` | `status, payment_due_at` | Cron: 4 открытых статуса и истёкший срок — range |
| orders | `ix_orders_status_created` | `status, created_at` | Списки в админке |
| orders | `ix_orders_attention` | `needs_attention, updated_at` | Очередь ручного разбора |
| orders | `ix_orders_cart` / `ix_orders_consent` | `cart_id` / `offer_consent_id` | Для `_fk_cart` / `_fk_consent` |
| orders | `ix_orders_retention` | `pii_erased_at, status, updated_at` | `uniundata_privacy_retention` — range (`sql/queries.sql` 11.1) |
| order_items | `uq_order_items_item` | `order_id, book_item_id` | Экземпляр не дважды в заказе; для `_fk_order` |
| order_items | `uq_order_items_reservation` | `reservation_id` | Резерв — не более одной позиции (`NULL` допустимы); для `_fk_reservation` |
| order_items | `ix_order_items_book_item` | `book_item_id` | Заказы экземпляра; для `_fk_item` |
| order_items | `ix_order_items_record` | `book_record_id` | Отчёты; для `_fk_record` |

### 3.8. Деньги: `payments`, `payment_events`, `refunds`, `sales`

* **Платёж** — попытка оплаты (`attempt_no`). `idempotency_key` создаётся **до** HTTP-запроса и передаётся банку:
  повтор после потерянного ответа не откроет вторую сессию. `session_redirect_url` + `session_expires_at` —
  повтор `/checkout` или `/pay` при живой сессии отдаёт сохранённый URL без `createSession`. `refunded_amount` —
  подтверждённые возвраты (`_chk_amount`: `amount > 0 AND refunded_amount <= amount`). `provider_status` — сырой
  статус банка; карта — только `card_brand` и `card_last4` (PCI DSS). Чек 54-ФЗ формирует облачная касса
  провайдера по данным `createSession`/`refund`; в схеме чек не хранится.
* **Событие** вставляется **только после проверки подписи** (иначе злоумышленник «занял» бы
  `provider_event_id`); `provider_event_id` — ID банка или SHA-256 тела; `payload_redacted` без PAN/CVV/секретов
  и лишних ПДн; `attempts` — число доставок.
* **Возврат** — один запрос к банку; строка `requested` создаётся **в той же транзакции**, что и решение:
  автоматически (`duplicate_payment`, `late_payment_conflict`) или менеджером (`order_cancelled`,
  `customer_return`, `manual` — `POST /admin/orders/{public_order_id}/refunds` → `PaymentService::requestRefund()`).
  Возврат мимо плагина, узнанный из webhook-а, пишется как `manual` и сразу завершается; `amount_mismatch` схема
  допускает, код пока нет. После COMMIT задача `uniundata_refund_payment {refund_id}` вызывает
  `provider->refund()` **вне транзакции**: `requested → pending → succeeded | failed` (`docs/04-statuses.md`,
  раздел 9). `idempotency_key` передаётся банку, `provider_refund_id` — ID у банка (по нему находит строку
  webhook). Сумма возвратов платежа со статусом ≠ `failed` ≤ `payments.amount` — проверяет код под блокировкой
  строк возвратов (CHECK не видит других строк). `requested_by` — менеджер или `NULL`; `completed_at` ⇔ финальный
  статус (`_chk_completed`).
* **Продажа** — одна строка на экземпляр, синхронизация её не трогает; `refunded_at` — платёж возвращён
  полностью, экземпляр в продажу **не** возвращается.

PRIMARY — `FOR UPDATE` платежа (уровень 6), события (7), возврата (8; `refund_id` — аргумент задачи AS).

| Таблица | Индекс | Колонки | Запрос |
|---|---|---|---|
| payments | `uq_payments_idempotency` | `idempotency_key` | Одна сессия на попытку |
| payments | `uq_payments_provider_id` | `provider, provider_payment_id` | Webhook находит платёж; `NULL` допустимы |
| payments | `uq_payments_attempt` | `order_id, attempt_no` | Попытки без дублей; для `_fk_order` |
| payments | `ix_payments_status` | `status, session_expires_at` | Зависшие открытые попытки для опроса банка |
| payment_events | `uq_payment_events_provider` | `provider, provider_event_id` | **Идемпотентность webhook-а** (`ON DUPLICATE KEY UPDATE attempts = attempts + 1`) |
| payment_events | `ix_payment_events_payment` | `payment_id, received_at` | События платежа; для `_fk_payment` |
| payment_events | `ix_payment_events_status` | `processing_status, received_at` | Переобработка `received`/`failed` |
| payment_events | `ix_payment_events_order` | `order_id` | События заказа; для `_fk_order` |
| refunds | `uq_refunds_idempotency` | `idempotency_key` | Идемпотентность запроса к банку |
| refunds | `uq_refunds_provider_id` | `provider_refund_id` | Webhook возврата; `NULL` допустимы |
| refunds | `ix_refunds_payment` | `payment_id` | Сумма возвратов платежа; для `_fk_payment` |
| refunds | `ix_refunds_order` | `order_id` | Карточка заказа, ретеншн ПДн; для `_fk_order` |
| refunds | `ix_refunds_status` | `status, requested_at` | Незавершённые дольше суток (`sql/queries.sql` 9.5, 10.12) |
| sales | `uq_sales_book_item` | `book_item_id` | **Одна продажа на экземпляр**; для `_fk_item` |
| sales | `uq_sales_order_item` | `order_item_id` | Повтор не создаст вторую строку; для `_fk_order_item` |
| sales | `ix_sales_order` / `ix_sales_record` | `order_id` / `book_record_id` | Для `_fk_order` / `_fk_record` |
| sales | `ix_sales_user` | `user_id, sold_at` | «Мои покупки» |
| sales | `ix_sales_sold_at` | `sold_at` | Отчёты за период |
| sales | `ix_sales_payment` | `payment_id` | `refunded_at` по платежу; основной платёж заказа; для `_fk_payment` |

### 3.9. Служебные: `sync_runs`, `audit_log`

* **Прогон**: `triggered_by` — `cron`, `wp_cli`, `admin`, `retry`; `heartbeat_at` — после каждого пакета
  (`running` без него дольше 900 с — зависший). `source_cursor VARCHAR(1024)`: `NULL` — с начала, токен — продолжить,
  `''` — источник прочитан, идёт проход пропавших. `resumed_from_run_id` — какой прогон продолжает этот;
  `pass_started_run_id` — первый прогон полного прохода (`last_seen_sync_run_id` меньше — не встречался).
  Счётчики `records_*`, `items_*` (в т. ч. `items_conflicts`), `errors_count`; `error_log` JSON — последние N
  ошибок. `running_source` (STORED) дублирует `GET_LOCK(Db::lockName('sync_<source>'))` данными: блокировка
  снимается при обрыве соединения, строка остаётся в админке. PRIMARY — `FOR UPDATE` прогона (уровень 0).
* **Аудит** — только `INSERT`; `actor_type`: `user`, `admin`, `system`, `cron`, `webhook`, `sync`, `cli`;
  `entity_type` + `entity_id` — полиморфная ссылка (`NULL`, если сущности нет: отклонённый webhook);
  `context` — JSON без секретов, PAN и лишних ПДн. FK нет: аудит не блокирует бизнес-строки, таблицу можно
  архивировать или партиционировать по `occurred_at` (партиционированные InnoDB-таблицы FK не поддерживают).

| Таблица | Индекс | Колонки | Запрос |
|---|---|---|---|
| sync_runs | `uq_sync_runs_one_running` | `running_source` (STORED) | Второй запуск источника — 1062 |
| sync_runs | `ix_sync_runs_source` | `source_name, started_at` | Последний прогон, `GET /admin/sync/runs` |
| audit_log | `ix_audit_entity` | `entity_type, entity_id, occurred_at` | История сущности |
| audit_log | `ix_audit_action` | `action, occurred_at` | События типа за период |
| audit_log | `ix_audit_actor` | `actor_user_id, occurred_at` | Действия пользователя или менеджера |

---

## 4. MARC 21 → колонки

`Sync\MarcExtractor::extract()` из `marc21_raw`. Общие правила: UTF-8 (MARC-8 при Leader/09 = пробел), **NFC**;
подполя через пробел; снимается завершающая ISBD-пунктуация (` /`, ` :`, ` ;`, ` =`, `,`) и точка, если она не
часть сокращения (`ил.`, `Т. 1.`); длинные значения обрезаются в PHP (`mb_substr`); повторы — в порядке записи.

| MARC 21 | Колонка | Правило |
|---|---|---|
| Leader/06, /07 | `record_type`, `bib_level` | Как есть (`a` — текст, `c` — ноты; `m` — монография, `s` — сериальное) |
| 001 / 003 | `marc_control_number` / `marc_control_org` | Как есть; 001 **не** `source_record_id` |
| 245 $a ($n $p) | `title` | $a, при $n/$p — `$a. $n, $p` (иначе тома неотличимы) |
| 245 ind2 + $a | `title_sort` | Пропустить ind2 незначащих символов (`245 14 $a The Lord…` → `Lord…`) и фрагменты NSB/NSE; NFC → `mb_strtolower` → не-буквы/цифры в пробел → схлопнуть → 255 символов (`sortKey()`) |
| 245 $b / $c | `subtitle` / `responsibility_statement` | Без завершающего ` /` |
| 100/110/111 + 700/710/711 | `authors_text` | `$a $b $c $d`, роль (`$e`/`$4`) в скобках, через `; ` |
| 100 $a (110/111 $a) | `main_author_sort` | Как `title_sort`; `NULL` без 1XX |
| 1XX/7XX | `contributors`, `record_contributors` | Ключ, роль, позиция, `marc_tag` (3.2) |
| 020 $a | `isbn_primary`, `identifiers` | Без уточнений в скобках, цифры и `X`, контрольная цифра, ISBN-10 → ISBN-13 (`normalizeIsbn()`: `0-306-40615-2` → `9780306406157`, `080442957X` → `9780804429573`, `9780306406158` → `null`); `isbn_primary` — первый **валидный** $a |
| 020 $z / $q | `is_cancelled = 1` / `raw_value` | Отменённый ISBN ищется, но не основной |
| 022, 010, 035, 024 | `identifiers` | ISSN, LCCN, OCLC (`(OCoLC)…`), EAN/ISMN по ind1 |
| 264 (ind2 = 1) / 260 | `publisher` ($b), `publication_place` ($a) | 264 ind2 = 1 → 260 → 264 ind2 = 0/2/3; 264 ind2 = 4 — только год |
| 264 $c / 260 $c | `publication_date_text`, `publication_year` | Текст как есть (`[1905?]`, `c1999`); год — первое число 1400–2100 по `(?<!\d)(1[4-9]\d{2}\|20\d{2})(?!\d)` (`extractYear()`: `[1905?]` → 1905, `MDCCCXII` → `null`) |
| 008/06–10 | `publication_year` (резерв) | Date1, если тип даты не `b`/`n`/`\|` и 4 цифры; вне 1400–2100 → `NULL` (иначе `_chk_year` сорвёт пакет) |
| 250 / 300 | `edition_statement` / `physical_description` | Как в записи: «2-е изд., испр. и доп.», «312 с. : ил. ; 22 см» |
| 041 $a / 008/35–37 | `language_code` | Первый код (`engfre` → `eng`); при ind2 = 7 или без 041 — 008/35–37; `^[a-z]{3}$`, иначе `NULL` |
| 490 / 830 | `series_title` | 490 с `; $v`, без 490 — 830 |
| 600–655 | `subjects_text`, `subjects` | $a + $v $x $y $z через ` -- `; в `subjects_text` через `; ` без дублей; 653 — только в `subjects_text` |
| 520 / 505 | `description` / `contents_note` | Несколько 520 — через пустую строку; 505 — через ` -- ` |
| 856 $u | `source_url`, `cover_url` (резерв) | Только если интеграция не дала URL |

## 5. Что не раскладываем в колонки

В колонки — только то, по чему ищут, фильтруют, сортируют или что выводится в списках; остальное — в
`marc21_raw`: 006/007/008 (кроме даты и языка); 040–084 (фасет УДК/ББК — отдельная таблица по образцу `subjects`,
когда появится требование); 130/240/246 (кандидаты в FULLTEXT по оригинальному заглавию); 5XX, кроме 505/520
(561 провенанс, 563 переплёт, 590 автографы — карточка разбирает raw и кэширует в object cache по
`record_id + source_checksum`); 76X–78X, 800–830 кроме серии; 852, 876–878 (экземплярные данные приходят в
`items`); 880 (основная графика — по реальным данным, через `$6`); 9XX. EAV «тег/индикатор/подполе» дала бы
10–15 млн строк на 100k записей без выигрыша в запросах.

---

## 6. Уникальность «только среди активных»

Частичных индексов в MySQL нет. Решение — STORED generated column: ключ у активной строки, `NULL` у остальных,
под `UNIQUE` (InnoDB допускает сколько угодно `NULL`):

```sql
active_book_item_id BIGINT UNSIGNED
    GENERATED ALWAYS AS (IF(reservation_status = 'active', book_item_id, NULL)) STORED,
UNIQUE KEY uq_reservations_one_active_per_item (active_book_item_id)
```

Вторая активная строка — `ERROR 1062 … 'wp_book_reservations.uq_reservations_one_active_per_item'` (проверено).
Проверка — на уровне оператора: в одной транзакции сначала закрываем старую строку, потом вставляем новую.

| Таблица | Generated column | «Активна», если | Инвариант |
|---|---|---|---|
| `carts` | `open_cart_user_id` | `status IN ('active', 'checkout_started')` | Одна открытая корзина на пользователя |
| `reservations` | `active_book_item_id` | `reservation_status = 'active'` | Один активный резерв на экземпляр |
| `cart_items` | `active_book_item_id` | `status = 'active'` | Экземпляр — активная позиция одной корзины |
| `sync_runs` | `running_source` | `status = 'running'` | Один идущий прогон на источник |

Это **второй рубеж** после `SELECT … FOR UPDATE` экземпляра или корзины: страхует от ошибки в коде, скрипта и
ручного SQL. Не иначе, потому что: VIRTUAL менее явна, а STORED в существующую таблицу добавляется только
`ALGORITHM=COPY` (писать в колонку нельзя — 3105, `$wpdb->insert()` её не передаёт); функциональный индекс
`UNIQUE ((IF(…)))` работает, только если `WHERE` повторяет **то же выражение** — обычное
`status = 'active' AND book_item_id = 5` идёт полным сканом (проверено EXPLAIN); триггер не защищает от гонки
и требует прав `TRIGGER`/`SUPER`; один `FOR UPDATE` без UNIQUE — ошибка в коде молча даст два резерва.

---

## 7. CHECK-ограничения и имена ограничений

MySQL проверяет CHECK с 8.0.16; мигратор отказывается работать ниже 8.0.16 и на MariaDB.

### 7.1. Почему имена начинаются с имени таблицы

Имена CHECK и FOREIGN KEY уникальны **в пределах всей базы** (повтор — `ERROR 3822 Duplicate check constraint
name` / `ERROR 1826`, проверено). Две установки WordPress с разными префиксами в одной БД, мультисайт
(`wp_2_book_…`) или staging не должны конфликтовать; у заказчика на одном сервере — `new.libsmr.ru` и
`shop.libsmr.ru`. Поэтому имя = `<имя_таблицы>_chk_<x>` / `<имя_таблицы>_fk_<x>` (`wp_book_items_chk_status`,
`wp_book_items_fk_record`): замена `wp_book_` → `{$wpdb->prefix}book_` меняет их вместе с таблицами (проверено:
схема с `wp_2_` загружается рядом с `wp_`). Ошибки сопоставляются по суффиксу:
`Db::isCheckViolation('_chk_attempt_range')` узнаёт `wp_book_…` и `wp_2_book_…`. Имена индексов локальны для
таблицы. Имена `GET_LOCK` общие для **сервера**, поэтому их строит `Db::lockName()`:
`'uniundata_' . $name . '@' . substr(md5(DB_NAME . '|' . $wpdb->prefix), 0, 12)` (≤ 64 символов).

### 7.2. Список (56)

Имена без `wp_book_`.

| Группа | Ограничения | Что гарантирует |
|---|---|---|
| Перечисления (20) | `records_chk_source_format`, `records_chk_marc_format`, `contributors_chk_type`, `identifiers_chk_type`, `items_chk_status`, `items_chk_source_status`, `items_chk_condition`, `images_chk_role`, `user_consents_chk_type`, `carts_chk_status`, `reservations_chk_status`, `cart_items_chk_status`, `orders_chk_status`, `payments_chk_status`, `payment_events_chk_status`, `refunds_chk_status`, `refunds_chk_reason`, `sync_runs_chk_status`, `sync_runs_chk_trigger`, `audit_log_chk_actor` | Опечатка или `'AVAILABLE'` — 3819 (`utf8mb4_bin`, проверено) |
| Статус ⇔ поле (10) | `items_chk_sold_at`, `reservations_chk_released`, `reservations_chk_attempt_admin`, `reservations_chk_order`, `carts_chk_closed`, `cart_items_chk_closed`, `orders_chk_paid_at`, `payments_chk_succeeded`, `refunds_chk_completed`, `sync_runs_chk_finished` | Статус не сменить, «забыв» поле |
| Суммы > 0 (7) | `items_chk_price`, `cart_items_chk_price`, `order_items_chk_price`, `sales_chk_price`, `orders_chk_positive` (подытог и итог), `payments_chk_amount` (и `refunded_amount <= amount`), `refunds_chk_amount` | Нулевая или «починенная» сумма — ошибка |
| Арифметика (3) | `orders_chk_total` (итог = подытог − скидка + доставка [+ налог, если цены без налога], в `SIGNED`), `orders_chk_refund`, `reservations_chk_window` | Суммы и сроки сходятся |
| Диапазоны, флаги (5) | `reservations_chk_attempt_range` (1..3), `records_chk_year` (1400..2100), `order_items_chk_quantity` (= 1), `records_chk_is_active`, `items_chk_is_active` | Лимит трёх попыток в БД |
| Форматы (10) | `*_chk_currency` в `items`, `cart_items`, `orders`, `order_items`, `payments`, `refunds`, `sales`; `payments_chk_last4`; `customer_profiles_chk_phone` (E.164); `orders_chk_public_id` | `REGEXP` регистрозависим: `'rub'` и UUID с заглавными hex — ошибка (проверено) |
| Владелец (1) | `images_chk_owner` | Есть запись или экземпляр |

«A ⇔ B» — `(A) = (B)` над `NOT NULL`-выражениями; CHECK, вернувший `NULL`, выполнен (поэтому `attempt_no
BETWEEN 1 AND 3` пропускает `NULL`). CHECK не видит других строк (уникальность — UNIQUE, сумма возвратов — код
под блокировкой), не вызывает `UTC_TIMESTAMP()` (срок резерва проверяет код) и не видит **старого значения**
(допустимость перехода — условный `UPDATE`, `docs/04-statuses.md`, раздел 13). `…_chk_attempt_range` (3819) или
1062 по `uq_reservations_attempt` → 409 `uniundata_reservation_limit_reached`; другое нарушение — ошибка в коде →
500 + аудит.

### 7.3. Изменение CHECK — только копированием таблицы

| DDL | Алгоритм на 8.0.46 (проверено) |
|---|---|
| `DROP CHECK` | `INSTANT` |
| `ADD CONSTRAINT … CHECK (…)`, `ALTER CHECK … ENFORCED` | **только `ALGORITHM=COPY`**: `INPLACE`/`INSTANT` → 1845, `LOCK=NONE` → 1846; все строки перепроверяются, запись в таблицу заблокирована (`LOCK=SHARED`) |
| `ADD … CHECK (…) NOT ENFORCED` | `INSTANT`, но ничего не защищает |

Новый статус: сначала код, понимающий оба набора, затем в окно обслуживания один оператор:

```sql
ALTER TABLE wp_book_carts
  DROP CHECK wp_book_carts_chk_status,
  ADD CONSTRAINT wp_book_carts_chk_status CHECK (status IN (…, 'new_status')),
  ALGORITHM=COPY, LOCK=SHARED;
```

`items` (100k строк) копируется за секунды, `audit_log` — дольше, поэтому в аудите одно перечисление.

---

## 8. FOREIGN KEY без каскадов

30 FK, все без `ON DELETE`/`ON UPDATE` (`NO ACTION` = `RESTRICT`, проверка сразу); список —
`docs/02-er-diagram.md`, 3.1. `CASCADE` превратил бы случайный `DELETE` записи в уничтожение истории продаж,
`RESTRICT` даёт 1451 (проверено), `SET NULL` терял бы связь заказа с экземпляром. FK не даёт создать резерв на
несуществующий экземпляр, продажу или возврат без платежа. Цикл резерв ↔ заказ:
`wp_book_reservations_fk_order` добавляется `ALTER TABLE` после `wp_book_orders`.

**FK-проверка ставит S-блокировку на родителя**: `INSERT` в потомка ждёт, если родитель X-заблокирован
(проверено). В порядке блокировок (`docs/08-security-concurrency.md`: 0 `sync_runs`, `records`; 1 `carts`;
2 `items`; 3 `reservations`; 4 `cart_items`; 5 `orders`; 6 `payments`; 7 `payment_events`; 8 `refunds`)
родители вставок уже заблокированы той же транзакцией. Исключение — `records`: `INSERT` в `order_items`
(checkout) и `sales` (webhook) берёт S на запись **после** экземпляров. Цикла «records ↔ items» нет:
синхронизация не держит X на записях и экземплярах в одной транзакции (сначала транзакция по записям пакета,
затем по экземплярам). Редкий deadlock 1213 «записи ↔ записи» (синхронизация держит X на записях, checkout
берёт S на нескольких записях в другом порядке) возможен; его лечит повтор `Db::transaction()`.

## 9. Почему нет FK на wp_users

`user_id`, `requested_by`, `actor_user_id` ссылаются на `wp_users.ID` **логически**. `wp_users` — таблица
магазина `shop.libsmr.ru` (отдельная установка WordPress, покупатель регистрируется в магазине; SSO с
`new.libsmr.ru` опционален и на схему не влияет). Почему без FK: `wp_delete_user()` не обрабатывает ошибку FK —
с `RESTRICT` удаление оборвётся на середине, с `CASCADE` сотрёт заказы и продажи, обязательные для учёта;
таблица ядра не наша (миграции, переносы, staging, импорт не ждут входящих FK); в мультисайте `wp_users` общая
для сети; контакты скопированы в заказ — он читается и без строки `wp_users`.

Замена: `user_id` — только из `get_current_user_id()` (или проверенный администратором ID); `map_meta_cap`
запрещает `delete_user`, пока у покупателя есть деньги в пути (`docs/07-users-roles.md`); иначе хук `delete_user`
снимает резервы, закрывает корзину и отменяет неоплаченные заказы, а контакты закрытых заказов обезличивают
задачи `uniundata_user_deleted_cleanup` и `uniundata_privacy_retention` (`orders.pii_erased_at`). Заказы и продажи
сохраняют `user_id`. Экспорт и удаление ПДн — `wp_privacy_personal_data_exporters` / `…_erasers` (152-ФЗ: БД с
ПДн граждан РФ — на сервере в РФ; для ЕС — GDPR). Висячие ссылки:
`SELECT o.id FROM wp_book_orders o LEFT JOIN wp_users u ON u.ID = o.user_id WHERE u.ID IS NULL`.

## 10. Почему не dbDelta

`dbDelta()` делит `CREATE TABLE` регулярными выражениями: `CONSTRAINT … CHECK/FOREIGN KEY` принимает за колонки
(повторный запуск генерирует лишний `ADD COLUMN`), многострочные `GENERATED ALWAYS AS …` распадаются; нет
`ALGORITHM`/`LOCK`, переименований, backfill и порядка шагов (цикл FK). `Install\Migrator`:

* выполняет `sql/schema.sql`: `wp_book_` → `{$wpdb->prefix}book_` (с именами ограничений), `CREATE TABLE IF NOT
  EXISTS`, `ADD CONSTRAINT` — если ограничения нет; `SET` из файла не выполняет, а в своей сессии ставит
  `SET NAMES utf8mb4 COLLATE utf8mb4_unicode_520_ci` и `SET SESSION innodb_ft_enable_stopword = OFF` (раздел 11)
  и затем возвращает прежнее;
* до DDL проверяет MySQL ≥ 8.0.16, не MariaDB, InnoDB, collation, права `CREATE`, `ALTER`, `INDEX`,
  `REFERENCES` (без него FK — 1142); предупреждает, если `innodb_ft_min_token_size ≠ 3` или
  `@@global.time_zone` не UTC;
* сверяет результат с `information_schema` (`verifySchema()`: колонки, `utf8mb4`, индексы, generated columns,
  CHECK/FK, InnoDB) — на случай таблиц, созданных вручную: расхождение — исключение, данные не трогаются;
  FULLTEXT-индексы заранее существовавших таблиц перестраивает;
* каждым запуском добавляет недостающие options (`add_option`, значения администратора не перезаписываются):
  `uniundata_currency = 'RUB'`, `uniundata_max_active_reservations = 10`, `uniundata_payment_ttl_minutes = 30`,
  `uniundata_payment_grace_minutes = 10`, `uniundata_reservation_minutes = 60`, `uniundata_receipt_vat = 'none'`,
  `uniundata_sync_source = 'primary'`;
* хранит версию в `uniundata_db_version`, работает под `GET_LOCK(Db::lockName('migrate'))`; в продакшене —
  `wp uniundata migrate` при деплое. Будущие миграции — expand → migrate → contract
  (`ADD COLUMN …, ALGORITHM=INSTANT`; `ADD CHECK` — только `ALGORITHM=COPY`, 7.3).

## 11. FULLTEXT-поиск

| Индекс | Колонки | Для чего |
|---|---|---|
| `ft_records_main` | `title, subtitle, authors_text, series_title` | Основная строка поиска |
| `ft_records_all` | + `responsibility_statement, subjects_text, description, publisher` | «Искать везде» |
| `ft_contributors_name` | `name_display` | Автодополнение автора |
| `ft_subjects_heading` | `heading` | Автодополнение рубрики |

Колонки в `MATCH(…)` должны **точно** совпадать с индексом, иначе 1191 (проверено).

* **Без стоп-слов.** Встроенный список InnoDB (36 слов, в т. ч. `de`, `la`, `en`, `und`, `the`) в `BOOLEAN MODE`
  обнуляет выдачу с обязательным стоп-словом (`'+und +krieg'` → 0 строк). Настройка фиксируется в таблице
  **при создании** её FULLTEXT-индексов, поэтому мигратор в своей сессии выполняет
  `SET SESSION innodb_ft_enable_stopword = OFF` перед `CREATE TABLE`; таблица стоп-слов не нужна (проверено:
  `'+the +lord'` и `'+und +krieg'` находят книги при глобальном `ON`). Сменить настройку можно, только удалив
  **все** FT-индексы таблицы и создав их заново по одному (`DROP INDEX x, ADD FULLTEXT x` одним `ALTER` оставляет
  старую, несколько `ADD FULLTEXT` в одном `ALTER` — 1795) — так работает `Migrator::rebuildFulltext()`; пока
  индексов нет, поиск отвечает 1191.
* **Минимальная длина слова** — `innodb_ft_min_token_size = 3` (read-only; изменение — перезапуск MySQL и
  `wp uniundata migrate --rebuild-fulltext`). Слова из 1–2 символов не индексируются, обязательное `+ад`
  обнуляет выдачу (проверено), поэтому построитель **не делает их обязательными** («война и мир» →
  `+война* +мир*`); без длинных слов — подсказка или `title_sort LIKE 'ад%'`. ISBN — через `ix_identifiers_lookup`
  / `ix_records_isbn`.
* **Построитель** (PHP): NFC, `mb_strtolower`, `preg_split('/[^\p{L}\p{N}]+/u', …)` (операторы `+ - < > ( ) ~ * "
  @` отбрасываются), не более 8 слов, каждое → `+слово*`; только через `$wpdb->prepare('… AGAINST (%s IN BOOLEAN
  MODE)', $expr)` (`sql/queries.sql` 2.1). Сравнение по collation колонки: `+елка` находит «Ёлка», `+muller` —
  «Müller» (проверено).
* **Обслуживание.** Изменения видны после COMMIT; запись с неизменным `source_checksum` не перезаписывается
  (обновление помечает старый документ удалённым); после массовых изменений — `OPTIMIZE TABLE` с
  `innodb_optimize_fulltext_only = ON`.
* **CJK и масштаб.** Встроенный парсер делит по пробелам («红楼» не находит «红楼梦», `ngram` находит —
  проверено); при значимой доле CJK — отдельная таблица `WITH PARSER ngram`. Морфология, опечатки, каталог от
  1 млн — внешний поиск (OpenSearch, Manticore) из тех же колонок; доступность всегда читается из MySQL.

## 12. Почему ISBN не уникален

Один `book_id` — один экземпляр со своей записью: два экземпляра издания — две записи с одним ISBN, и `UNIQUE`
сломал бы синхронизацию. Кроме того, ISBN переиспользуют, у многотомника есть ISBN комплекта и томов, в 020$z —
отменённые номера, у книг до 1970-х ISBN нет. Поиск по ISBN возвращает **список**; уникальность — где она есть:
`uq_items_external`, `uq_records_source`, `uq_identifiers_record`.

## 13. Кодировки и collation

* **Всё `utf8mb4`**: дореформенная кириллица (ѣ, і, ѳ, ѵ), CJK, символы вне BMP.
* **Таблицы — `utf8mb4_unicode_520_ci`, как ядро** (`$wpdb->get_charset_collate()`, collation сайта заказчика):
  при разных collation `wp_users.user_email = o.customer_email` падает с 1267 (проверено). Регистр и диакритика
  не различаются (`е` = `ё`, `u` = `ü`) — то, что нужно для сортировки и поиска.
* **Коды, статусы, внешние ID, хэши — `utf8mb4_bin`** (проверено): `uq_items_external` различает `AbC-1` и `abc-1`
  — с `_ci` upsert обновил бы **чужой** экземпляр (то же для `uq_records_source`, `uq_payments_provider_id`,
  `uq_payment_events_provider`, `uq_refunds_provider_id`); CHECK отклоняют `'rub'` и `'AVAILABLE'`, которые `_ci`
  счёл бы равными `'RUB'`/`'available'`, а PHP (`===`) — нет. `utf8mb4_bin` — PAD SPACE (`'i1 '` = `'i1'` в
  UNIQUE); пробелы во внешних ID запрещает валидация PHP.
* **Почему не `CHARACTER SET ascii`.** Для запроса с не-ASCII символами `wpdb::query()` вызывает
  `strip_invalid_text_from_query()` → `get_table_charset()`: при двух разных кодировках колонок (кроме пар
  `utf8`/`utf8mb4` и `latin1`) он возвращает `'ascii'` (WordPress 7.1.2, `class-wpdb.php`), кириллица
  перекодируется в `?`, и `wpdb` отказывается выполнять запрос («Could not perform query because it contains
  invalid data») — любой запрос с названием, ФИО, адресом или поиском к смешанной таблице. Одна кодировка — и
  `get_table_charset()` возвращает `'utf8mb4'`, фильтр `pre_get_table_charset` не нужен. Таблица с `VARBINARY`
  (`user_consents.ip_address`) получает `'binary'` и не перекодируется — безопасно, строки валидирует PHP.
* **Цена почти нулевая**: ASCII-символ в `utf8mb4` занимает 1 байт в строке и индексе (`CHAR(n)` — минимум `n`
  байт); 4 байта на символ считаются только для предела ключа 3072 байта: `uq_records_source`,
  `uq_items_external` — `(64 + 191) × 4 = 1020`; поэтому `*_sort` — `VARCHAR(255)`, а `title VARCHAR(1000)` —
  только в FULLTEXT.
* **Клиент.** `wpdb` сам выполняет `SET NAMES utf8mb4`; скрипты и тесты — `mysql --default-character-set=utf8mb4`,
  иначе клиент в `latin1` молча портит кириллицу.

## 14. Оценка объёмов

Допущения (уточнить у заказчика): 100 000 экземпляров (1:1 с записями) + 30 000 в год; 25 000 продаж в год
(≈ 50 заказов в день по 1,5 книги); ≈ 300 резервов в день; 1,3 попытки оплаты на заказ, ≈ 3 webhook-а на платёж;
возвраты — единицы процентов; MARCXML 3–6 КБ.

| Таблица | Строк (старт / в год) | Байт на строку с индексами | Объём |
|---|---|---|---|
| `records` | 100k / +30k | 7 КБ (raw 4, поля 1,5, B-tree 0,5, FULLTEXT 1) | 0,7 ГБ, +0,2 ГБ/год |
| `contributors` + связка | 50k + 200k | 0,4 КБ / 60 Б | 35 МБ |
| `subjects` + связка | 30k + 300k | 0,5 КБ / 50 Б | 30 МБ |
| `identifiers` | 150k | 150 Б | 25 МБ |
| `items` / `images` | 100k / +30k; 200k / +60k | 0,6 КБ; 0,3 КБ | 60 МБ; 60 МБ |
| `carts` / `reservations` / `cart_items` | 50k / 110k / 110k в год | 0,3 / 0,35 / 0,3 КБ | 15 / 40 / 35 МБ/год |
| `orders` / `order_items` | 18k / 27k в год | 2 / 0,8 КБ | 36 / 22 МБ/год |
| `payments` / `payment_events` | 24k / 70k в год | 0,6 / 1,5 КБ | 15 / 100 МБ/год |
| `refunds` / `sales` | < 1k / 25k в год | 0,4 / 0,25 КБ | < 1 / 6 МБ/год |
| `sync_runs` | 365–730/год | до 10 КБ | < 10 МБ/год |
| `audit_log` | 2–3 млн/год | 0,4 КБ | **≈ 1 ГБ/год** — главный рост |

Каталог на 100k экземпляров — около 1 ГБ (в основном `marc21_raw`), на 1 млн — около 10 ГБ; транзакционные
таблицы — ≈ 0,3 ГБ в год, партиционирование не нужно. `audit_log` архивировать по сроку (единственная таблица,
где удаление допустимо политикой) или партиционировать по `occurred_at` (PK тогда включает `occurred_at`).
Конкуренция определяется блокировками строк экземпляров, а не объёмом. `innodb_buffer_pool_size` — от 1–2 ГБ.
Фактические размеры:

```sql
SELECT table_name, table_rows,
       ROUND(data_length / 1024 / 1024) AS data_mb, ROUND(index_length / 1024 / 1024) AS index_mb
  FROM information_schema.TABLES
 WHERE table_schema = DATABASE() AND table_name LIKE 'wp\_book\_%'
 ORDER BY data_length + index_length DESC;
```
