# Uniundata Books — магазин уникальных книг на WordPress

Проектная документация, проверенная SQL-схема и PHP-примеры плагина для магазина, который продаёт
**уникальные физические экземпляры книг** (один экземпляр продаётся ровно один раз).

Стек: WordPress 7.1.2 · PHP 8.3+ (совместимо с 8.4) · MySQL 8.0.16+ (InnoDB) · Action Scheduler · WP-CLI.
Целевой сервер: **shop.libsmr.ru** — отдельная установка WordPress рядом с основным сайтом new.libsmr.ru;
PHP 8.3.27 FPM, Apache 2.4.52, MySQL 8.0.46 (см. [11-environment](docs/11-environment.md)). Валюта — RUB (суммы в копейках).

## Главные решения

| Вопрос | Решение | Где подробно |
|---|---|---|
| WooCommerce или свой плагин | **Отдельный custom plugin** + Action Scheduler как standalone-библиотека | [01-architecture](docs/01-architecture.md) |
| Запись и экземпляр | `wp_book_records` (MARC 21) 1:N `wp_book_items`. Внешний `book_id` = один физический экземпляр (`external_item_id`, UNIQUE). Две одинаковые книги имеют разные `book_id` | [03-tables](docs/03-tables-and-indexes.md) |
| Пользователи | `wp_users` + стандартные роли/capabilities. Своей таблицы пользователей нет. Профиль магазина (телефон E.164, адреса, B2B) и согласия хранятся в отдельных таблицах | [07-users-roles](docs/07-users-roles.md) |
| Один активный резерв на экземпляр | `SELECT … FOR UPDATE` строки экземпляра **и** STORED generated column `IF(status='active', book_item_id, NULL)` + UNIQUE | [08-security-concurrency](docs/08-security-concurrency.md) |
| Лимит 3 резерва | Проверка в транзакции под блокировкой экземпляра **и** в БД: `UNIQUE(user_id, book_item_id, attempt_no)` + `CHECK (attempt_no BETWEEN 1 AND 3)`. Резерв, снятый администратором, попыткой не считается (`attempt_no = NULL`) | [05-algorithms](docs/05-algorithms.md) |
| Резерв 1 час и оплата | Час ограничивает **начало** оплаты. После «Перейти к оплате» экземпляр держится до `payment_due_at` (TTL платёжной сессии 30 мин + grace 10 мин) | [04-statuses](docs/04-statuses.md) |
| Факт оплаты | Только серверный webhook банка с проверенной подписью и сверкой суммы, валюты и ID заказа. Идемпотентность через `wp_book_payment_events` UNIQUE(provider, event_id) и `wp_book_sales` UNIQUE(book_item_id) | [06-rest-api](docs/06-rest-api.md) |
| Гонки | Единый порядок блокировок `carts → items → reservations → cart_items → orders → payments → payment_events → refunds`, READ COMMITTED на каждую транзакцию, повтор при deadlock | [08-security-concurrency](docs/08-security-concurrency.md) |
| Возвраты | Решение о возврате (дубль оплаты, поздний платёж, отмена) — строка `wp_book_refunds` в той же транзакции; запрос к банку — задачей Action Scheduler с ключом идемпотентности | [05-algorithms](docs/05-algorithms.md) |
| Чеки и ПДн | 54-ФЗ: данные для чека передаются провайдеру в `createSession` и `refund`. 152-ФЗ: отдельное согласие, обезличивание контактов по сроку хранения (`orders.pii_erased_at`) | [07-users-roles](docs/07-users-roles.md) |
| Синхронизация | Action Scheduler / системный cron + WP-CLI, `GET_LOCK` + один `running`-прогон на источник, пакеты, upsert по checksum. Локальные `reserved/checkout_pending/sold/blocked` не перетираются, пропавшие экземпляры получают `sync_missing` | [05-algorithms](docs/05-algorithms.md) |
| Миграции | Свой версионный мигратор, **не** `dbDelta()`: dbDelta не поддерживает generated columns, CHECK и FK | [09-migrations-tests](docs/09-migrations-tests-edge-cases.md) |

## Содержание

1. [Архитектура и выбор WooCommerce / custom plugin](docs/01-architecture.md)
2. [ER-диаграмма (Mermaid)](docs/02-er-diagram.md)
3. [Таблицы, MARC 21-маппинг и индексы](docs/03-tables-and-indexes.md)
4. [Статусы и допустимые переходы](docs/04-statuses.md)
5. [Алгоритмы: резерв, снятие резервов, удаление из корзины, checkout, webhook, синхронизация](docs/05-algorithms.md)
6. [REST API](docs/06-rest-api.md)
7. [Пользователи, роли, capabilities, персональные данные](docs/07-users-roles.md)
8. [Безопасность, идемпотентность, транзакции, конкурентный доступ](docs/08-security-concurrency.md)
9. [Миграции, тесты, edge cases](docs/09-migrations-tests-edge-cases.md)
10. [Сценарии из ТЗ с реальным выводом MySQL](docs/10-scenarios.md)
11. [Окружение shop.libsmr.ru: что настроить до запуска](docs/11-environment.md)

## Настройки и команды

| Option (`wp_options`) | По умолчанию | Назначение |
|---|---|---|
| `uniundata_currency` | `RUB` (ставится при установке) | Валюта магазина. Экземпляры в другой валюте не резервируются |
| `uniundata_max_active_reservations` | `10` | Одновременных активных резервов на пользователя |
| `uniundata_payment_ttl_minutes` / `uniundata_payment_grace_minutes` | `30` / `10` | Срок платёжной сессии и запас на поздний webhook |
| `uniundata_reservation_minutes` | `60` | Срок резерва (по ТЗ — ровно час) |
| `uniundata_terms_versions` | — | Действующие версии оферты и политики ПДн (версия + SHA-256) |
| `uniundata_receipt_vat` | `none` | Ставка НДС в чеке 54-ФЗ — задаёт бухгалтер |

Секреты банка и источника — константы в `wp-config.php` или переменные окружения, не options.

WP-CLI (`wp uniundata …`): `migrate [--rebuild-fulltext]` — схема; `sync run [--resume]` / `sync status` —
синхронизация; `expire` — снять просроченные резервы и заказы; `privacy-retention` — обезличивание по срокам;
`doctor` — проверка схемы, настроек и инвариантов (код выхода 1 при нарушениях).

## Файлы

```
sql/schema.sql        — CREATE TABLE для всех таблиц (MySQL 8.0.16+)
sql/queries.sql       — примеры запросов: каталог, поиск, корзина, expiry, отчёты, инварианты
src/                  — PHP-код плагина (namespace Uniundata\Books), главный файл src/uniundata-books.php
composer.json         — автозагрузка PSR-4 и Action Scheduler ^4.2
tests/mysql/run.sh    — сценарии гонок на реальном MySQL 8 (параллельные сессии)
```

## Как проверить

```bash
# Нужен локальный MySQL 8.0.16+ с доступом root без пароля (или задайте MYSQL_ARGS)
tests/mysql/run.sh               # схема + все сценарии параллельного доступа, PASS/FAIL
find src -name '*.php' -print0 | xargs -0 -n1 php -l
```

Схема и сценарии проверены на MySQL 8.0.46 — той же версии, что на сервере магазина. PHP-код проверен
линтером PHP 8.3 (на сервере магазина PHP 8.3.27) и не использует синтаксис, появившийся только в 8.4.
