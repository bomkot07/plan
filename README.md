# Uniundata Books — магазин уникальных книг на WordPress

Проектная документация, проверенная SQL-схема и PHP-примеры плагина для магазина, который продаёт
**уникальные физические экземпляры книг** (один экземпляр продаётся ровно один раз).

Стек: WordPress 7.1.2 · PHP 8.4 · MySQL 8.0.16+ (InnoDB) · Action Scheduler · WP-CLI.

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
| Гонки | Единый порядок блокировок `carts → items → reservations → cart_items → orders → payments → payment_events`, READ COMMITTED, повтор транзакции при deadlock | [08-security-concurrency](docs/08-security-concurrency.md) |
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

## Файлы

```
sql/schema.sql        — CREATE TABLE для всех таблиц (MySQL 8.0.16+)
sql/queries.sql       — примеры запросов: каталог, поиск, корзина, expiry, отчёты, инварианты
src/                  — PHP-код плагина (namespace Uniundata\Books)
tests/mysql/run.sh    — сценарии гонок на реальном MySQL 8 (параллельные сессии)
```

## Как проверить

```bash
# Нужен локальный MySQL 8.0.16+ с доступом root без пароля (или задайте MYSQL_ARGS)
tests/mysql/run.sh               # схема + все сценарии параллельного доступа, PASS/FAIL
find src -name '*.php' -print0 | xargs -0 -n1 php -l
```

Схема и сценарии проверены на MySQL 8.0.46. Код рассчитан на PHP 8.4, но использует синтаксис, совместимый
с 8.3, и проверен линтером PHP 8.3: в окружении проверки не было PHP 8.4.
