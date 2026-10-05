-- =====================================================================
-- Uniundata Books — каноническая схема БД
-- MySQL 8.0.16+ (CHECK-ограничения), InnoDB, utf8mb4, все даты в UTC.
--
-- Префикс `wp_` условный: мигратор плагина заменяет его на $wpdb->prefix.
-- Схема создаётся собственным версионным мигратором через $wpdb->query(),
-- а НЕ через dbDelta(): dbDelta не понимает generated columns, CHECK
-- и FOREIGN KEY и при повторном запуске пытается «исправить» такие таблицы.
--
-- Соглашения:
--   * деньги — INT UNSIGNED в минимальных единицах валюты (копейки/центы), CHECK > 0;
--   * статусы — VARCHAR + CHECK. Новый статус = DROP CHECK (instant) +
--     ADD CHECK (ALGORITHM=COPY: MySQL перепроверяет строки, таблица копируется);
--   * все колонки — utf8mb4 (смешение ascii/utf8mb4 в одной таблице заставляет
--     $wpdb считать таблицу 'ascii' и отклонять запросы с кириллицей);
--     коды, статусы и внешние ID — COLLATE utf8mb4_bin: регистрозависимое
--     сравнение в UNIQUE и CHECK ('eur' ≠ 'EUR', 'AbC-1' ≠ 'abc-1');
--   * имена CHECK и FOREIGN KEY в MySQL уникальны в пределах всей БД, поэтому
--     они начинаются с имени таблицы и меняются вместе с префиксом
--     (wp_2_book_items_chk_status в мультисайте не конфликтует с wp_book_items_chk_status);
--   * «уникальность только среди активных» — STORED generated column,
--     которая равна ключу для активной строки и NULL для остальных;
--     UNIQUE-индекс в InnoDB допускает сколько угодно NULL;
--   * user_id ссылается на wp_users.ID логически, без FOREIGN KEY
--     (см. docs/03-tables-and-indexes.md, раздел «Почему нет FK на wp_users»);
--   * между таблицами плагина — FOREIGN KEY без каскадов (RESTRICT):
--     строки заказов, резервов, платежей и продаж никогда не удаляются.
--     Для каждого FK объявлен явный индекс.
-- =====================================================================

SET NAMES utf8mb4;
SET time_zone = '+00:00';
-- FULLTEXT-индексы строятся без встроенного списка стоп-слов InnoDB (de, la, und, the…):
-- иначе обязательное (+) стоп-слово в BOOLEAN MODE обнуляет выдачу. Настройка
-- фиксируется в индексе в момент его создания, поэтому её задаёт сам мигратор.
SET SESSION innodb_ft_enable_stopword = OFF;

-- ---------------------------------------------------------------------
-- 1. Библиографические записи (MARC 21)
-- ---------------------------------------------------------------------
CREATE TABLE wp_book_records (
  id                        BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  source_name               VARCHAR(64)  CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL DEFAULT 'primary',
  source_record_id          VARCHAR(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL
                            COMMENT 'Стабильный внешний ID записи (по умолчанию = внешний book_id)',
  marc_control_number       VARCHAR(64)  NULL COMMENT 'MARC 001',
  marc_control_org          VARCHAR(32)  NULL COMMENT 'MARC 003',
  record_type               CHAR(1)      CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NULL COMMENT 'Leader/06: a=текст, c=ноты, e=карты…',
  bib_level                 CHAR(1)      CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NULL COMMENT 'Leader/07: m=монография, s=сериальное…',
  source_format             VARCHAR(16)  CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL
                            COMMENT 'В каком формате пришло из источника',
  marc21_format             VARCHAR(16)  CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL
                            COMMENT 'В каком формате хранится marc21_raw',
  marc21_raw                MEDIUMTEXT   NOT NULL COMMENT 'Полная запись: MARCXML или MARC-in-JSON',
  source_checksum           CHAR(64)     CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL COMMENT 'SHA-256 канонизированной записи',

  -- Денормализованные поля витрины и поиска
  title                     VARCHAR(1000) NOT NULL COMMENT '245$a',
  title_sort                VARCHAR(255)  NOT NULL COMMENT '245$a без незначащих символов (ind2), нормализовано',
  subtitle                  VARCHAR(1000) NULL COMMENT '245$b',
  responsibility_statement  VARCHAR(1000) NULL COMMENT '245$c',
  authors_text              VARCHAR(1000) NULL COMMENT '100/110/111 + 700/710/711, для вывода',
  main_author_sort          VARCHAR(255)  NULL COMMENT '100$a нормализовано, для сортировки и фильтра',
  isbn_primary              VARCHAR(13)   CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NULL COMMENT 'Первый валидный 020$a, нормализован в ISBN-13',
  publisher                 VARCHAR(500)  NULL COMMENT '264$b (ind2=1) или 260$b',
  publication_place         VARCHAR(500)  NULL COMMENT '264$a или 260$a',
  publication_year          SMALLINT      NULL COMMENT 'Год из 264$c/260$c или 008/07-10',
  publication_date_text     VARCHAR(100)  NULL COMMENT 'Исходный текст даты, например "[1905?]"',
  edition_statement         VARCHAR(500)  NULL COMMENT '250$a',
  language_code             CHAR(3)       CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NULL COMMENT '041$a или 008/35-37 (ISO 639-2/B)',
  physical_description      VARCHAR(1000) NULL COMMENT '300',
  series_title              VARCHAR(1000) NULL COMMENT '490$a / 830$a',
  subjects_text             TEXT          NULL COMMENT '6XX через "; ", для вывода и FULLTEXT',
  description               TEXT          NULL COMMENT '520$a',
  contents_note             TEXT          NULL COMMENT '505',
  cover_url                 VARCHAR(2048) NULL,
  source_url                VARCHAR(2048) NULL,

  is_active                 TINYINT(1)   NOT NULL DEFAULT 1,
  last_seen_sync_run_id     BIGINT UNSIGNED NULL,
  last_synced_at            DATETIME(6)  NULL,
  created_at                DATETIME(6)  NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at                DATETIME(6)  NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),

  PRIMARY KEY (id),
  UNIQUE KEY uq_records_source (source_name, source_record_id),
  KEY ix_records_isbn (isbn_primary),
  KEY ix_records_marc001 (marc_control_org, marc_control_number),
  KEY ix_records_active_title (is_active, title_sort),
  KEY ix_records_active_author (is_active, main_author_sort),
  KEY ix_records_active_year (is_active, publication_year),
  KEY ix_records_language (language_code),
  KEY ix_records_sync (source_name, last_seen_sync_run_id),
  FULLTEXT KEY ft_records_main (title, subtitle, authors_text, series_title),
  FULLTEXT KEY ft_records_all (title, subtitle, responsibility_statement, authors_text,
                               series_title, subjects_text, description, publisher),

  CONSTRAINT wp_book_records_chk_source_format CHECK (source_format IN ('marcxml', 'marc_json', 'iso2709', 'mrk')),
  CONSTRAINT wp_book_records_chk_marc_format   CHECK (marc21_format IN ('marcxml', 'marc_json')),
  CONSTRAINT wp_book_records_chk_year          CHECK (publication_year IS NULL OR publication_year BETWEEN 1400 AND 2100),
  CONSTRAINT wp_book_records_chk_is_active     CHECK (is_active IN (0, 1))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci;

-- Персоны и организации (1XX/7XX), нормализованные.
CREATE TABLE wp_book_contributors (
  id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  contributor_key  CHAR(64)      CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL
                   COMMENT 'SHA-256 от authority_id либо нормализованного "имя|даты"',
  name_display     VARCHAR(500)  NOT NULL COMMENT '$a $b $c $d как в записи',
  name_sort        VARCHAR(255)  NOT NULL,
  entity_type      VARCHAR(16)   CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL DEFAULT 'person',
  dates            VARCHAR(100)  NULL COMMENT '$d',
  authority_id     VARCHAR(255)  NULL COMMENT '$0 / $1: VIAF, GND, LC NAF…',
  created_at       DATETIME(6)   NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at       DATETIME(6)   NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  UNIQUE KEY uq_contributors_key (contributor_key),
  KEY ix_contributors_sort (name_sort),
  FULLTEXT KEY ft_contributors_name (name_display),
  CONSTRAINT wp_book_contributors_chk_type CHECK (entity_type IN ('person', 'corporate', 'meeting'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci;

CREATE TABLE wp_book_record_contributors (
  book_record_id   BIGINT UNSIGNED NOT NULL,
  contributor_id   BIGINT UNSIGNED NOT NULL,
  role_code        VARCHAR(16)  CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL DEFAULT 'aut' COMMENT 'Relator $4: aut, edt, trl, ill…',
  marc_tag         CHAR(3)      CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL COMMENT '100, 110, 700, 710…',
  position         SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (book_record_id, contributor_id, role_code),
  KEY ix_rc_contributor (contributor_id, book_record_id),
  CONSTRAINT wp_book_record_contributors_fk_record      FOREIGN KEY (book_record_id) REFERENCES wp_book_records (id),
  CONSTRAINT wp_book_record_contributors_fk_contributor FOREIGN KEY (contributor_id) REFERENCES wp_book_contributors (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci;

-- Предметные рубрики (6XX), нормализованные.
CREATE TABLE wp_book_subjects (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  subject_key   CHAR(64)      CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL COMMENT 'SHA-256 от thesaurus|нормализованная рубрика',
  heading       VARCHAR(1000) NOT NULL COMMENT 'Рубрика с подразделениями через " -- "',
  heading_sort  VARCHAR(255)  NOT NULL,
  marc_tag      CHAR(3)       CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL COMMENT '600, 610, 650, 651, 655…',
  thesaurus     VARCHAR(32)   CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NULL COMMENT 'ind2 или $2: lcsh, gnd, rero…',
  authority_id  VARCHAR(255)  NULL,
  created_at    DATETIME(6)   NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  UNIQUE KEY uq_subjects_key (subject_key),
  KEY ix_subjects_sort (heading_sort),
  FULLTEXT KEY ft_subjects_heading (heading)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci;

CREATE TABLE wp_book_record_subjects (
  book_record_id  BIGINT UNSIGNED NOT NULL,
  subject_id      BIGINT UNSIGNED NOT NULL,
  position        SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (book_record_id, subject_id),
  KEY ix_rs_subject (subject_id, book_record_id),
  CONSTRAINT wp_book_record_subjects_fk_record  FOREIGN KEY (book_record_id) REFERENCES wp_book_records (id),
  CONSTRAINT wp_book_record_subjects_fk_subject FOREIGN KEY (subject_id)     REFERENCES wp_book_subjects (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci;

-- Все идентификаторы записи (020, 022, 010, 035, 024).
-- ISBN НЕ уникален глобально: два экземпляра одного издания — две записи с одним ISBN.
CREATE TABLE wp_book_identifiers (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  book_record_id  BIGINT UNSIGNED NOT NULL,
  id_type         VARCHAR(16)  CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  id_value        VARCHAR(64)  CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL COMMENT 'Нормализовано: без дефисов, ISBN-10 → ISBN-13',
  raw_value       VARCHAR(255) NULL COMMENT 'Как в записи, вместе с $q',
  is_cancelled    TINYINT(1)   NOT NULL DEFAULT 0 COMMENT '1 для 020$z / 022$z',
  created_at      DATETIME(6)  NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  UNIQUE KEY uq_identifiers_record (book_record_id, id_type, id_value),
  KEY ix_identifiers_lookup (id_type, id_value),
  CONSTRAINT wp_book_identifiers_fk_record FOREIGN KEY (book_record_id) REFERENCES wp_book_records (id),
  CONSTRAINT wp_book_identifiers_chk_type CHECK (id_type IN ('isbn', 'issn', 'lccn', 'oclc', 'ean', 'ismn', 'other'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci;

-- ---------------------------------------------------------------------
-- 2. Продаваемые экземпляры
-- ---------------------------------------------------------------------
CREATE TABLE wp_book_items (
  id                     BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  book_record_id         BIGINT UNSIGNED NOT NULL,
  source_name            VARCHAR(64)  CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL DEFAULT 'primary',
  external_item_id       VARCHAR(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NULL
                         COMMENT 'Внешний book_id = один физический экземпляр. Две одинаковые книги имеют разные ID',
  inventory_number       VARCHAR(64)  NULL,
  price_amount           INT UNSIGNED NOT NULL COMMENT 'В минимальных единицах (центы)',
  currency               CHAR(3)      CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL DEFAULT 'EUR',
  condition_code         VARCHAR(16)  CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL DEFAULT 'good',
  condition_note         TEXT         NULL,
  cover_url              VARCHAR(2048) NULL,
  source_url             VARCHAR(2048) NULL,
  availability_status    VARCHAR(20)  CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL DEFAULT 'available',
  status_changed_at      DATETIME(6)  NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  is_active              TINYINT(1)   NOT NULL DEFAULT 1,
  source_status          VARCHAR(20)  CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL DEFAULT 'present'
                         COMMENT 'Что говорит источник; хранится отдельно от локального статуса',
  source_checksum        CHAR(64)     CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NULL,
  last_seen_sync_run_id  BIGINT UNSIGNED NULL,
  last_synced_at         DATETIME(6)  NULL,
  sold_at                DATETIME(6)  NULL,
  created_at             DATETIME(6)  NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at             DATETIME(6)  NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),

  PRIMARY KEY (id),
  UNIQUE KEY uq_items_external (source_name, external_item_id),
  UNIQUE KEY uq_items_inventory (inventory_number),
  KEY ix_items_record_status (book_record_id, availability_status),
  KEY ix_items_catalog (is_active, availability_status, price_amount),
  KEY ix_items_sync (source_name, last_seen_sync_run_id),

  CONSTRAINT wp_book_items_fk_record FOREIGN KEY (book_record_id) REFERENCES wp_book_records (id),
  CONSTRAINT wp_book_items_chk_status CHECK (availability_status IN
    ('available', 'reserved', 'checkout_pending', 'sold', 'withdrawn', 'sync_missing', 'blocked')),
  CONSTRAINT wp_book_items_chk_source_status CHECK (source_status IN ('present', 'missing', 'withdrawn')),
  CONSTRAINT wp_book_items_chk_price     CHECK (price_amount > 0),
  CONSTRAINT wp_book_items_chk_currency  CHECK (currency REGEXP '^[A-Z]{3}$'),
  CONSTRAINT wp_book_items_chk_condition CHECK (condition_code IN
    ('new', 'as_new', 'fine', 'very_good', 'good', 'fair', 'poor')),
  CONSTRAINT wp_book_items_chk_sold_at   CHECK ((availability_status = 'sold') = (sold_at IS NOT NULL)),
  CONSTRAINT wp_book_items_chk_is_active CHECK (is_active IN (0, 1))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci;

-- Изображения записи или конкретного экземпляра.
CREATE TABLE wp_book_images (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  book_record_id  BIGINT UNSIGNED NULL,
  book_item_id    BIGINT UNSIGNED NULL,
  image_role      VARCHAR(16)   CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL DEFAULT 'cover',
  source_url      VARCHAR(2048) NULL,
  attachment_id   BIGINT UNSIGNED NULL COMMENT 'wp_posts.ID, если файл загружен в медиатеку',
  width           SMALLINT UNSIGNED NULL,
  height          SMALLINT UNSIGNED NULL,
  position        SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  created_at      DATETIME(6)   NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  KEY ix_images_record (book_record_id, position),
  KEY ix_images_item (book_item_id, position),
  CONSTRAINT wp_book_images_fk_record FOREIGN KEY (book_record_id) REFERENCES wp_book_records (id),
  CONSTRAINT wp_book_images_fk_item   FOREIGN KEY (book_item_id)   REFERENCES wp_book_items (id),
  CONSTRAINT wp_book_images_chk_owner  CHECK (book_record_id IS NOT NULL OR book_item_id IS NOT NULL),
  CONSTRAINT wp_book_images_chk_role   CHECK (image_role IN ('cover', 'back', 'spine', 'title_page', 'detail'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci;

-- ---------------------------------------------------------------------
-- 3. Покупатели: профиль и согласия (основные данные — wp_users / wp_usermeta)
-- ---------------------------------------------------------------------
CREATE TABLE wp_book_customer_profiles (
  user_id                   BIGINT UNSIGNED NOT NULL COMMENT 'wp_users.ID',
  phone_e164                VARCHAR(16)  CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NULL COMMENT '+491701234567',
  phone_verified_at         DATETIME(6)  NULL,
  default_shipping_address  JSON         NULL,
  default_billing_address   JSON         NULL,
  company_name              VARCHAR(255) NULL COMMENT 'B2B, необязательно',
  vat_id                    VARCHAR(32)  CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NULL COMMENT 'B2B, необязательно',
  created_at                DATETIME(6)  NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at                DATETIME(6)  NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (user_id),
  KEY ix_profiles_phone (phone_e164),
  CONSTRAINT wp_book_customer_profiles_chk_phone CHECK (phone_e164 IS NULL OR phone_e164 REGEXP '^\\+[1-9][0-9]{6,14}$')
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci;

-- Юридическое доказательство согласия: append-only, отзыв — отдельной датой.
CREATE TABLE wp_book_user_consents (
  id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  consent_uuid      CHAR(36)     CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL COMMENT 'Технический идентификатор согласия',
  user_id           BIGINT UNSIGNED NOT NULL,
  consent_type      VARCHAR(32)  CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  document_version  VARCHAR(32)  NOT NULL,
  document_sha256   CHAR(64)     CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL COMMENT 'Хэш текста принятой редакции',
  accepted_at       DATETIME(6)  NOT NULL,
  ip_address        VARBINARY(16) NULL COMMENT 'inet6_aton(); обнуляется по сроку хранения',
  user_agent_sha256 CHAR(64)     CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NULL,
  withdrawn_at      DATETIME(6)  NULL,
  created_at        DATETIME(6)  NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  UNIQUE KEY uq_consents_uuid (consent_uuid),
  KEY ix_consents_user (user_id, consent_type, accepted_at),
  KEY ix_consents_retention (accepted_at),
  CONSTRAINT wp_book_user_consents_chk_type CHECK (consent_type IN ('offer', 'privacy', 'marketing'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci;

-- ---------------------------------------------------------------------
-- 4. Корзина
-- ---------------------------------------------------------------------
CREATE TABLE wp_book_carts (
  id                   BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id              BIGINT UNSIGNED NOT NULL,
  status               VARCHAR(20)  CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL DEFAULT 'active',
  started_at           DATETIME(6)  NOT NULL,
  last_activity_at     DATETIME(6)  NOT NULL,
  checkout_started_at  DATETIME(6)  NULL,
  expires_at           DATETIME(6)  NULL COMMENT 'MIN(expires_at) активных позиций, только для отображения',
  closed_at            DATETIME(6)  NULL,
  created_at           DATETIME(6)  NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at           DATETIME(6)  NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  -- Не более одной открытой корзины на пользователя.
  open_cart_user_id    BIGINT UNSIGNED
                       GENERATED ALWAYS AS (IF(status IN ('active', 'checkout_started'), user_id, NULL)) STORED,
  PRIMARY KEY (id),
  UNIQUE KEY uq_carts_one_open_per_user (open_cart_user_id),
  KEY ix_carts_user (user_id, created_at),
  KEY ix_carts_status_activity (status, last_activity_at),
  CONSTRAINT wp_book_carts_chk_status CHECK (status IN
    ('active', 'checkout_started', 'converted_to_order', 'abandoned', 'expired')),
  CONSTRAINT wp_book_carts_chk_closed CHECK ((status IN ('active', 'checkout_started')) = (closed_at IS NULL))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci;

-- ---------------------------------------------------------------------
-- 5. Резервы: история и текущее состояние
-- ---------------------------------------------------------------------
CREATE TABLE wp_book_reservations (
  id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  book_item_id        BIGINT UNSIGNED NOT NULL,
  user_id             BIGINT UNSIGNED NOT NULL,
  cart_id             BIGINT UNSIGNED NULL,
  order_id            BIGINT UNSIGNED NULL COMMENT 'Заполняется при converted_to_order',
  reservation_status  VARCHAR(20)  CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL DEFAULT 'active',
  attempt_no          TINYINT UNSIGNED NULL
                      COMMENT '1..3; NULL только у released_by_admin — такой резерв не считается попыткой',
  reserved_at         DATETIME(6)  NOT NULL,
  expires_at          DATETIME(6)  NOT NULL,
  released_at         DATETIME(6)  NULL COMMENT 'Момент выхода из active (любой финальный статус)',
  release_reason      VARCHAR(32)  CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NULL,
  created_at          DATETIME(6)  NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at          DATETIME(6)  NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  -- Не более одного активного резерва на экземпляр (второй слой после FOR UPDATE).
  active_book_item_id BIGINT UNSIGNED
                      GENERATED ALWAYS AS (IF(reservation_status = 'active', book_item_id, NULL)) STORED,
  PRIMARY KEY (id),
  UNIQUE KEY uq_reservations_one_active_per_item (active_book_item_id),
  -- Лимит трёх попыток на уровне БД: номер попытки уникален и не больше 3.
  UNIQUE KEY uq_reservations_attempt (user_id, book_item_id, attempt_no),
  KEY ix_reservations_expiry (reservation_status, expires_at),
  KEY ix_reservations_user (user_id, reservation_status, expires_at),
  KEY ix_reservations_item_history (book_item_id, reserved_at),
  KEY ix_reservations_cart (cart_id),
  KEY ix_reservations_order (order_id),
  CONSTRAINT wp_book_reservations_fk_item FOREIGN KEY (book_item_id) REFERENCES wp_book_items (id),
  CONSTRAINT wp_book_reservations_fk_cart FOREIGN KEY (cart_id)      REFERENCES wp_book_carts (id),
  CONSTRAINT wp_book_reservations_chk_status CHECK (reservation_status IN
    ('active', 'expired', 'cancelled', 'converted_to_order', 'released_by_admin')),
  CONSTRAINT wp_book_reservations_chk_attempt_range CHECK (attempt_no BETWEEN 1 AND 3),
  CONSTRAINT wp_book_reservations_chk_attempt_admin CHECK ((reservation_status = 'released_by_admin') = (attempt_no IS NULL)),
  CONSTRAINT wp_book_reservations_chk_released CHECK ((reservation_status = 'active') = (released_at IS NULL)),
  CONSTRAINT wp_book_reservations_chk_window CHECK (expires_at > reserved_at),
  CONSTRAINT wp_book_reservations_chk_order CHECK (reservation_status <> 'converted_to_order' OR order_id IS NOT NULL)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci;

CREATE TABLE wp_book_cart_items (
  id                   BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  cart_id              BIGINT UNSIGNED NOT NULL,
  book_item_id         BIGINT UNSIGNED NOT NULL,
  reservation_id       BIGINT UNSIGNED NOT NULL,
  unit_price_amount    INT UNSIGNED NOT NULL COMMENT 'Снимок цены на момент резерва',
  currency             CHAR(3)      CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  status               VARCHAR(20)  CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL DEFAULT 'active',
  added_at             DATETIME(6)  NOT NULL,
  expires_at           DATETIME(6)  NOT NULL COMMENT 'Копия reservations.expires_at',
  closed_at            DATETIME(6)  NULL,
  created_at           DATETIME(6)  NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at           DATETIME(6)  NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  -- Книга может быть активной позицией только одной корзины (и, значит, не дважды в одной).
  -- Повторный резерв после удаления в ту же корзину разрешён: старая строка уже не active.
  active_book_item_id  BIGINT UNSIGNED
                       GENERATED ALWAYS AS (IF(status = 'active', book_item_id, NULL)) STORED,
  PRIMARY KEY (id),
  UNIQUE KEY uq_cart_items_reservation (reservation_id),
  UNIQUE KEY uq_cart_items_one_active_per_item (active_book_item_id),
  KEY ix_cart_items_cart (cart_id, status),
  KEY ix_cart_items_expiry (status, expires_at),
  KEY ix_cart_items_item (book_item_id),
  CONSTRAINT wp_book_cart_items_fk_cart        FOREIGN KEY (cart_id)        REFERENCES wp_book_carts (id),
  CONSTRAINT wp_book_cart_items_fk_item        FOREIGN KEY (book_item_id)   REFERENCES wp_book_items (id),
  CONSTRAINT wp_book_cart_items_fk_reservation FOREIGN KEY (reservation_id) REFERENCES wp_book_reservations (id),
  CONSTRAINT wp_book_cart_items_chk_status CHECK (status IN ('active', 'expired', 'removed', 'converted_to_order')),
  CONSTRAINT wp_book_cart_items_chk_closed CHECK ((status = 'active') = (closed_at IS NULL)),
  CONSTRAINT wp_book_cart_items_chk_price CHECK (unit_price_amount > 0),
  CONSTRAINT wp_book_cart_items_chk_currency CHECK (currency REGEXP '^[A-Z]{3}$')
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci;

-- ---------------------------------------------------------------------
-- 6. Заказы
-- ---------------------------------------------------------------------
CREATE TABLE wp_book_orders (
  id                     BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  public_order_id        VARCHAR(64)  CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL COMMENT 'uniundata_<uuid v4>',
  user_id                BIGINT UNSIGNED NOT NULL,
  cart_id                BIGINT UNSIGNED NULL,
  checkout_request_id    CHAR(36)     CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NULL COMMENT 'Idempotency-Key запроса /checkout',
  status                 VARCHAR(24)  CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL DEFAULT 'draft',
  currency               CHAR(3)      CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  prices_include_tax     TINYINT(1)   NOT NULL DEFAULT 1,
  subtotal_amount        INT UNSIGNED NOT NULL,
  discount_amount        INT UNSIGNED NOT NULL DEFAULT 0,
  shipping_amount        INT UNSIGNED NOT NULL DEFAULT 0,
  tax_amount             INT UNSIGNED NOT NULL DEFAULT 0,
  total_amount           INT UNSIGNED NOT NULL,
  refunded_amount        INT UNSIGNED NOT NULL DEFAULT 0,
  -- Снимок покупателя: не зависит от последующих правок профиля.
  customer_email         VARCHAR(254) NOT NULL,
  customer_phone         VARCHAR(16)  CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NULL,
  customer_first_name    VARCHAR(100) NOT NULL,
  customer_last_name     VARCHAR(100) NOT NULL,
  customer_middle_name   VARCHAR(100) NULL,
  billing_address_json   JSON         NULL,
  shipping_address_json  JSON         NULL,
  shipping_method        VARCHAR(64)  CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NULL,
  offer_consent_id       BIGINT UNSIGNED NULL,
  placed_at              DATETIME(6)  NULL,
  payment_due_at         DATETIME(6)  NULL COMMENT 'После этого момента экземпляры можно освобождать',
  paid_at                DATETIME(6)  NULL,
  cancelled_at           DATETIME(6)  NULL,
  cancel_reason          VARCHAR(64)  CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NULL,
  fulfilled_at           DATETIME(6)  NULL,
  completed_at           DATETIME(6)  NULL,
  payment_due_extended_at DATETIME(6) NULL COMMENT 'Однократное продление payment_due_at, пока банк отвечает processing',
  pii_erased_at          DATETIME(6)  NULL COMMENT 'Когда контакты покупателя в заказе обезличены (срок хранения истёк)',
  needs_attention        TINYINT(1)   NOT NULL DEFAULT 0,
  attention_reason       VARCHAR(64)  CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NULL COMMENT 'late_payment_conflict, duplicate_payment, amount_mismatch…',
  created_at             DATETIME(6)  NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at             DATETIME(6)  NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  UNIQUE KEY uq_orders_public_id (public_order_id),
  UNIQUE KEY uq_orders_checkout_request (user_id, checkout_request_id),
  KEY ix_orders_user (user_id, created_at),
  KEY ix_orders_payment_due (status, payment_due_at),
  KEY ix_orders_status_created (status, created_at),
  KEY ix_orders_attention (needs_attention, updated_at),
  KEY ix_orders_cart (cart_id),
  KEY ix_orders_consent (offer_consent_id),
  KEY ix_orders_retention (pii_erased_at, status, updated_at),
  CONSTRAINT wp_book_orders_fk_cart    FOREIGN KEY (cart_id)          REFERENCES wp_book_carts (id),
  CONSTRAINT wp_book_orders_fk_consent FOREIGN KEY (offer_consent_id) REFERENCES wp_book_user_consents (id),
  CONSTRAINT wp_book_orders_chk_public_id CHECK (public_order_id REGEXP '^uniundata_[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$'),
  CONSTRAINT wp_book_orders_chk_status CHECK (status IN
    ('draft', 'pending_payment', 'payment_processing', 'paid', 'payment_failed', 'payment_expired',
     'cancelled', 'refunded', 'partially_refunded', 'fulfilled', 'completed')),
  CONSTRAINT wp_book_orders_chk_currency CHECK (currency REGEXP '^[A-Z]{3}$'),
  CONSTRAINT wp_book_orders_chk_total CHECK (
    CAST(total_amount AS SIGNED) = CAST(subtotal_amount AS SIGNED) - CAST(discount_amount AS SIGNED)
      + CAST(shipping_amount AS SIGNED) + IF(prices_include_tax = 1, 0, CAST(tax_amount AS SIGNED))),
  CONSTRAINT wp_book_orders_chk_refund CHECK (refunded_amount <= total_amount),
  CONSTRAINT wp_book_orders_chk_positive CHECK (subtotal_amount > 0 AND total_amount > 0),
  CONSTRAINT wp_book_orders_chk_paid_at CHECK (
    status NOT IN ('paid', 'fulfilled', 'completed', 'refunded', 'partially_refunded') OR paid_at IS NOT NULL)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci;

-- reservations.order_id ссылается на orders: FK добавляется после создания orders.
ALTER TABLE wp_book_reservations
  ADD CONSTRAINT wp_book_reservations_fk_order FOREIGN KEY (order_id) REFERENCES wp_book_orders (id);

CREATE TABLE wp_book_order_items (
  id                         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  order_id                   BIGINT UNSIGNED NOT NULL,
  book_item_id               BIGINT UNSIGNED NOT NULL,
  book_record_id             BIGINT UNSIGNED NOT NULL,
  reservation_id             BIGINT UNSIGNED NULL,
  title_snapshot             VARCHAR(1000) NOT NULL,
  subtitle_snapshot          VARCHAR(1000) NULL,
  author_snapshot            VARCHAR(1000) NULL,
  isbn_snapshot              VARCHAR(13)   CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NULL,
  publisher_snapshot         VARCHAR(500)  NULL,
  publication_year_snapshot  SMALLINT      NULL,
  condition_snapshot         VARCHAR(16)   CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NULL,
  cover_url_snapshot         VARCHAR(2048) NULL,
  item_identifier_snapshot   VARCHAR(191)  NOT NULL COMMENT 'external_item_id / inventory_number',
  unit_price_amount          INT UNSIGNED  NOT NULL,
  currency                   CHAR(3)       CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  quantity                   TINYINT UNSIGNED NOT NULL DEFAULT 1,
  created_at                 DATETIME(6)   NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  UNIQUE KEY uq_order_items_item (order_id, book_item_id),
  UNIQUE KEY uq_order_items_reservation (reservation_id),
  KEY ix_order_items_book_item (book_item_id),
  KEY ix_order_items_record (book_record_id),
  CONSTRAINT wp_book_order_items_fk_order       FOREIGN KEY (order_id)       REFERENCES wp_book_orders (id),
  CONSTRAINT wp_book_order_items_fk_item        FOREIGN KEY (book_item_id)   REFERENCES wp_book_items (id),
  CONSTRAINT wp_book_order_items_fk_record      FOREIGN KEY (book_record_id) REFERENCES wp_book_records (id),
  CONSTRAINT wp_book_order_items_fk_reservation FOREIGN KEY (reservation_id) REFERENCES wp_book_reservations (id),
  CONSTRAINT wp_book_order_items_chk_quantity CHECK (quantity = 1),
  CONSTRAINT wp_book_order_items_chk_price CHECK (unit_price_amount > 0),
  CONSTRAINT wp_book_order_items_chk_currency CHECK (currency REGEXP '^[A-Z]{3}$')
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci;

-- ---------------------------------------------------------------------
-- 7. Платежи и входящие события банка
-- ---------------------------------------------------------------------
CREATE TABLE wp_book_payments (
  id                   BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  order_id             BIGINT UNSIGNED NOT NULL,
  provider             VARCHAR(32)  CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  attempt_no           SMALLINT UNSIGNED NOT NULL,
  idempotency_key      CHAR(36)     CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL COMMENT 'Передаётся банку при создании сессии',
  provider_payment_id  VARCHAR(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NULL,
  status               VARCHAR(24)  CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL DEFAULT 'created',
  amount               INT UNSIGNED NOT NULL,
  refunded_amount      INT UNSIGNED NOT NULL DEFAULT 0,
  currency             CHAR(3)      CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  provider_status      VARCHAR(64)  CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NULL COMMENT 'Сырой статус провайдера',
  failure_code         VARCHAR(64)  CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NULL,
  failure_message      VARCHAR(255) NULL,
  card_brand           VARCHAR(16)  CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NULL,
  card_last4           CHAR(4)      CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NULL COMMENT 'Только последние 4 цифры (PCI DSS)',
  session_expires_at   DATETIME(6)  NULL,
  session_redirect_url VARCHAR(2048) NULL COMMENT 'URL платёжной страницы банка для повторного перехода',
  succeeded_at         DATETIME(6)  NULL,
  created_at           DATETIME(6)  NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at           DATETIME(6)  NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  UNIQUE KEY uq_payments_idempotency (idempotency_key),
  UNIQUE KEY uq_payments_provider_id (provider, provider_payment_id),
  UNIQUE KEY uq_payments_attempt (order_id, attempt_no),
  KEY ix_payments_status (status, session_expires_at),
  CONSTRAINT wp_book_payments_fk_order FOREIGN KEY (order_id) REFERENCES wp_book_orders (id),
  CONSTRAINT wp_book_payments_chk_status CHECK (status IN
    ('created', 'pending', 'processing', 'succeeded', 'failed', 'cancelled', 'expired',
     'refunded', 'partially_refunded')),
  CONSTRAINT wp_book_payments_chk_currency CHECK (currency REGEXP '^[A-Z]{3}$'),
  CONSTRAINT wp_book_payments_chk_amount CHECK (amount > 0 AND refunded_amount <= amount),
  CONSTRAINT wp_book_payments_chk_last4 CHECK (card_last4 IS NULL OR card_last4 REGEXP '^[0-9]{4}$'),
  CONSTRAINT wp_book_payments_chk_succeeded CHECK (
    status NOT IN ('succeeded', 'refunded', 'partially_refunded') OR succeeded_at IS NOT NULL)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci;

-- Inbox webhook-ов. Строка попадает сюда ТОЛЬКО после проверки подписи:
-- иначе злоумышленник мог бы «занять» provider_event_id настоящего события.
CREATE TABLE wp_book_payment_events (
  id                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  provider           VARCHAR(32)  CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  provider_event_id  VARCHAR(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL
                     COMMENT 'ID события у провайдера; если его нет — SHA-256 канонического тела',
  event_type         VARCHAR(64)  CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  payment_id         BIGINT UNSIGNED NULL,
  order_id           BIGINT UNSIGNED NULL,
  processing_status  VARCHAR(16)  CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL DEFAULT 'received',
  payload_redacted   JSON         NOT NULL COMMENT 'Без PAN/CVV/секретов/лишних PII',
  payload_sha256     CHAR(64)     CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  received_at        DATETIME(6)  NOT NULL,
  processed_at       DATETIME(6)  NULL,
  attempts           SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  error_message      VARCHAR(500) NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_payment_events_provider (provider, provider_event_id),
  KEY ix_payment_events_payment (payment_id, received_at),
  KEY ix_payment_events_status (processing_status, received_at),
  KEY ix_payment_events_order (order_id),
  CONSTRAINT wp_book_payment_events_fk_payment FOREIGN KEY (payment_id) REFERENCES wp_book_payments (id),
  CONSTRAINT wp_book_payment_events_fk_order   FOREIGN KEY (order_id)   REFERENCES wp_book_orders (id),
  CONSTRAINT wp_book_payment_events_chk_status CHECK (processing_status IN ('received', 'processed', 'ignored', 'failed'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci;

-- Возвраты: идемпотентная очередь запросов к банку (дубль оплаты, поздний платёж, отмена).
CREATE TABLE wp_book_refunds (
  id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  payment_id          BIGINT UNSIGNED NOT NULL,
  order_id            BIGINT UNSIGNED NOT NULL,
  amount              INT UNSIGNED NOT NULL,
  currency            CHAR(3)      CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  reason              VARCHAR(32)  CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  idempotency_key     CHAR(36)     CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL COMMENT 'Передаётся банку',
  provider_refund_id  VARCHAR(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NULL,
  status              VARCHAR(16)  CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL DEFAULT 'requested',
  requested_by        BIGINT UNSIGNED NULL COMMENT 'wp_users.ID менеджера; NULL — автоматически',
  failure_message     VARCHAR(255) NULL,
  requested_at        DATETIME(6)  NOT NULL,
  completed_at        DATETIME(6)  NULL,
  created_at          DATETIME(6)  NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at          DATETIME(6)  NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  UNIQUE KEY uq_refunds_idempotency (idempotency_key),
  UNIQUE KEY uq_refunds_provider_id (provider_refund_id),
  KEY ix_refunds_payment (payment_id),
  KEY ix_refunds_order (order_id),
  KEY ix_refunds_status (status, requested_at),
  CONSTRAINT wp_book_refunds_fk_payment FOREIGN KEY (payment_id) REFERENCES wp_book_payments (id),
  CONSTRAINT wp_book_refunds_fk_order   FOREIGN KEY (order_id)   REFERENCES wp_book_orders (id),
  CONSTRAINT wp_book_refunds_chk_amount CHECK (amount > 0),
  CONSTRAINT wp_book_refunds_chk_currency CHECK (currency REGEXP '^[A-Z]{3}$'),
  CONSTRAINT wp_book_refunds_chk_status CHECK (status IN ('requested', 'pending', 'succeeded', 'failed')),
  CONSTRAINT wp_book_refunds_chk_reason CHECK (reason IN
    ('duplicate_payment', 'late_payment_conflict', 'amount_mismatch', 'order_cancelled', 'customer_return', 'manual')),
  CONSTRAINT wp_book_refunds_chk_completed CHECK ((status IN ('succeeded', 'failed')) = (completed_at IS NOT NULL))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci;

-- ---------------------------------------------------------------------
-- 8. Проданные экземпляры: одна успешная продажа на экземпляр
-- ---------------------------------------------------------------------
CREATE TABLE wp_book_sales (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  book_item_id    BIGINT UNSIGNED NOT NULL,
  book_record_id  BIGINT UNSIGNED NOT NULL,
  order_id        BIGINT UNSIGNED NOT NULL,
  order_item_id   BIGINT UNSIGNED NOT NULL,
  payment_id      BIGINT UNSIGNED NOT NULL,
  user_id         BIGINT UNSIGNED NOT NULL,
  sold_at         DATETIME(6)  NOT NULL,
  price_amount    INT UNSIGNED NOT NULL,
  currency        CHAR(3)      CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  refunded_at     DATETIME(6)  NULL COMMENT 'Возврат денег не возвращает экземпляр в продажу автоматически',
  created_at      DATETIME(6)  NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  UNIQUE KEY uq_sales_book_item (book_item_id),
  UNIQUE KEY uq_sales_order_item (order_item_id),
  KEY ix_sales_order (order_id),
  KEY ix_sales_user (user_id, sold_at),
  KEY ix_sales_sold_at (sold_at),
  KEY ix_sales_record (book_record_id),
  KEY ix_sales_payment (payment_id),
  CONSTRAINT wp_book_sales_fk_item       FOREIGN KEY (book_item_id)   REFERENCES wp_book_items (id),
  CONSTRAINT wp_book_sales_fk_record     FOREIGN KEY (book_record_id) REFERENCES wp_book_records (id),
  CONSTRAINT wp_book_sales_fk_order      FOREIGN KEY (order_id)       REFERENCES wp_book_orders (id),
  CONSTRAINT wp_book_sales_fk_order_item FOREIGN KEY (order_item_id)  REFERENCES wp_book_order_items (id),
  CONSTRAINT wp_book_sales_fk_payment    FOREIGN KEY (payment_id)     REFERENCES wp_book_payments (id),
  CONSTRAINT wp_book_sales_chk_price CHECK (price_amount > 0),
  CONSTRAINT wp_book_sales_chk_currency CHECK (currency REGEXP '^[A-Z]{3}$')
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci;

-- ---------------------------------------------------------------------
-- 9. Синхронизация каталога
-- ---------------------------------------------------------------------
CREATE TABLE wp_book_sync_runs (
  id                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  source_name        VARCHAR(64)  CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  triggered_by       VARCHAR(16)  CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL DEFAULT 'cron',
  status             VARCHAR(16)  CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL DEFAULT 'running',
  started_at         DATETIME(6)  NOT NULL,
  heartbeat_at       DATETIME(6)  NOT NULL,
  finished_at        DATETIME(6)  NULL,
  source_cursor      VARCHAR(1024) NULL COMMENT 'Точка продолжения после сбоя (страница / токен источника)',
  resumed_from_run_id BIGINT UNSIGNED NULL COMMENT 'Прогон, который этот прогон продолжает после сбоя',
  pass_started_run_id BIGINT UNSIGNED NULL COMMENT 'Первый прогон полного прохода: порог для пометки пропавших',
  records_received   INT UNSIGNED NOT NULL DEFAULT 0,
  records_created    INT UNSIGNED NOT NULL DEFAULT 0,
  records_updated    INT UNSIGNED NOT NULL DEFAULT 0,
  records_skipped    INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Checksum не изменился',
  items_created      INT UNSIGNED NOT NULL DEFAULT 0,
  items_updated      INT UNSIGNED NOT NULL DEFAULT 0,
  items_skipped      INT UNSIGNED NOT NULL DEFAULT 0,
  items_conflicts    INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Источник хотел изменить защищённый локальный статус',
  items_missing      INT UNSIGNED NOT NULL DEFAULT 0,
  items_withdrawn    INT UNSIGNED NOT NULL DEFAULT 0,
  errors_count       INT UNSIGNED NOT NULL DEFAULT 0,
  error_log          JSON         NULL COMMENT 'Не более N последних ошибок: {external_id, code, message}',
  created_at         DATETIME(6)  NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  -- Не более одного running-прогона на источник (дублирует GET_LOCK на уровне данных).
  running_source     VARCHAR(64)  CHARACTER SET utf8mb4 COLLATE utf8mb4_bin
                     GENERATED ALWAYS AS (IF(status = 'running', source_name, NULL)) STORED,
  PRIMARY KEY (id),
  UNIQUE KEY uq_sync_runs_one_running (running_source),
  KEY ix_sync_runs_source (source_name, started_at),
  CONSTRAINT wp_book_sync_runs_chk_status CHECK (status IN ('running', 'succeeded', 'partial', 'failed', 'aborted')),
  CONSTRAINT wp_book_sync_runs_chk_trigger CHECK (triggered_by IN ('cron', 'wp_cli', 'admin', 'retry')),
  CONSTRAINT wp_book_sync_runs_chk_finished CHECK ((status = 'running') = (finished_at IS NULL))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci;

-- ---------------------------------------------------------------------
-- 10. Аудит (append-only): переходы статусов и важные действия
-- ---------------------------------------------------------------------
CREATE TABLE wp_book_audit_log (
  id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  occurred_at    DATETIME(6)  NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  actor_type     VARCHAR(16)  CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  actor_user_id  BIGINT UNSIGNED NULL,
  action         VARCHAR(64)  CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL COMMENT 'reservation.created, order.paid, item.status_changed…',
  entity_type    VARCHAR(32)  CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  entity_id      BIGINT UNSIGNED NULL COMMENT 'NULL — у события нет сущности (отклонённый webhook и т. п.)',
  from_status    VARCHAR(24)  CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NULL,
  to_status      VARCHAR(24)  CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NULL,
  request_id     CHAR(36)     CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NULL COMMENT 'Корреляция с логами веб-сервера',
  context        JSON         NULL COMMENT 'Без секретов, PAN и лишних PII',
  PRIMARY KEY (id),
  KEY ix_audit_entity (entity_type, entity_id, occurred_at),
  KEY ix_audit_action (action, occurred_at),
  KEY ix_audit_actor (actor_user_id, occurred_at),
  CONSTRAINT wp_book_audit_log_chk_actor CHECK (actor_type IN ('user', 'admin', 'system', 'cron', 'webhook', 'sync', 'cli'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci;
