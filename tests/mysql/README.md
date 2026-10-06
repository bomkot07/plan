# tests/mysql — гарантии схемы и транзакционных алгоритмов на реальном MySQL 8.0

`run.sh` создаёт свежую БД, загружает каноническую `sql/schema.sql` (без изменений), наполняет тестовыми
данными и прогоняет сценарии ТЗ, в том числе параллельные. Каждая проверка печатает `PASS` или `FAIL`.
Код выхода ненулевой, если есть хотя бы один `FAIL`. Разбор сценариев с реальным выводом —
[docs/10-scenarios.md](../../docs/10-scenarios.md).

```bash
tests/mysql/run.sh                     # все сценарии, около 30 секунд
tests/mysql/run.sh S5 S7               # только сценарии с этими префиксами (+ итоговые инварианты S9)
QUIET_TIMELINE=1 tests/mysql/run.sh    # без хронологий параллельных сессий
UNIUNDATA_TEST_DB=my_test MYSQL_ARGS="-uroot -psecret -h127.0.0.1" tests/mysql/run.sh
```

| Переменная | По умолчанию | Назначение |
|---|---|---|
| `UNIUNDATA_TEST_DB` | `uniundata_test` | Тестовая БД. **Пересоздаётся** при каждом запуске. Имя обязано содержать `test`, иначе нужен `FORCE=1` |
| `MYSQL_ARGS` | `-uroot` | Параметры клиента `mysql` (`--default-character-set=utf8mb4` добавляется всегда) |
| `SCHEMA_FILE` | `sql/schema.sql` | Проверяемая схема |
| `QUIET_TIMELINE` | `0` | `1` отключает печать хронологий |

Требования: MySQL 8.0.16+ (проверено на 8.0.46), включённая `performance_schema`, права на создание БД,
процедур и `SHOW ENGINE INNODB STATUS`; bash и клиент mysql. Коды выхода: `0` — нет FAIL (XFAIL допускается),
`1` — есть FAIL, `2` — ошибка окружения.

Все вызовы клиента идут с `--default-character-set=utf8mb4`, сессия — как у `Db::transaction()`:
`SET NAMES utf8mb4 COLLATE utf8mb4_unicode_520_ci`, `time_zone = '+00:00'`, READ COMMITTED,
`innodb_lock_wait_timeout = 5`. Валюта магазина — RUB, суммы в копейках.

## Файлы

| Файл | Что внутри |
|---|---|
| `run.sh` | Оркестратор: БД, загрузка, сценарии S0–S9, проверки, хронологии |
| `harness.sql` | Обвязка: `t_trace` (хронология), `t_outcome` (итог операции: код REST, HTTP, errno, data), `t_session`, точки паузы `tp()`, `t_wait_paused()`, `t_rendezvous()`, `t_start_at()`, `t_retry()` |
| `algorithms.sql` | SQL-эквиваленты PHP-алгоритмов: те же `SELECT … FOR UPDATE`, порядок блокировок, условные `UPDATE` с проверкой числа строк; `f_opt()` = `get_option()`, `f_lock_name()` = `Db::lockName()` |
| `naive.sql` | **Намеренно неправильные** варианты (без `FOR UPDATE`, нарушенный порядок блокировок) — доказывают второй рубеж UNIQUE/CHECK и то, что тест ловит deadlock |
| `seed.sql` | Минимальная `wp_options` с опциями, которые ставит `Migrator` (`uniundata_currency = RUB`, лимит 10, TTL 30 + grace 10), `t_mk_item()` и каталог `BASE-01…10` |
| `invariants.sql` | Представление `t_invariants`: 19 глобальных инвариантов, каждый должен давать 0 нарушений |

| Процедура | PHP |
|---|---|
| `a_reserve` | `ReservationService::reserve()`: корзина (ODKU) → экземпляр → резерв; валюта магазина, 3 попытки, лимит активных резервов |
| `a_remove`, `a_admin_release` | `ReservationService::removeFromCart()`, `adminRelease()` |
| `a_expire_reservations` / `a_expire_one` | `ReservationExpiryService::expireDue()` (pass A, `GET_LOCK`) |
| `a_checkout` | `CheckoutService::checkout()`: Tx1 → createSession (`@bank_session`: `NULL` — успех, `error` — `created → cancelled`, `crash` — процесс умер) → Tx2 |
| `a_webhook` / `a_apply_succeeded` / `a_create_refund` | `PaymentService::handleWebhook()` → `applyTx()` → `applySucceeded()`; возврат — строка `wp_book_refunds` (`createRefundLocked`), задачи AS — в `t_outcome.data.after_commit` |
| `a_expire_orders` / `a_expire_order_one` | `OrderExpiryService::expireDue()` (pass B); ответ `fetchPayment`: `pending`, `none`, `processing`, `unknown` |
| `a_sync_start` / `a_sync_batch` (`a_sync_records` + `a_sync_items`) / `a_sync_finish` | `SyncService`: `GET_LOCK` + строка `running`; транзакция A — записи, транзакция B — экземпляры |

Отличия от PHP на блокировки не влияют: `DomainError` → `SIGNAL SQLSTATE '45000'` с кодом REST; IN-списки —
динамический SQL; блокировки по списку id берутся циклом по возрастанию id. Повтора при 1213/1205 внутри
процедур нет (тест должен видеть каждый deadlock); в стресс-тестах повтор `Db::transaction()` эмулирует
`t_retry()`: до 3 повторов с паузой 50–200 мс.

## Как гарантируется пересечение транзакций

Без подбора `SLEEP`. В алгоритмах стоят точки `tp('<имя>')`. Сессия с `@pause_at = '<имя>'` в этой точке
держит свои блокировки, ставит маяк `GET_LOCK('t:<сценарий>:<сессия>:<точка>')` и ждёт, пока
`performance_schema.data_lock_waits` покажет сессию, ждущую **её** транзакцию; затем пишет в хронологию
`ВИЖУ ОЖИДАНИЕ: U2 ждёт X,REC_NOT_GAP wp_book_items.PRIMARY (11)` (реальные `data_locks`) и делает COMMIT.
Партнёр стартует после `t_wait_paused()`, то есть когда маяк уже стоит. Владелец ожидания ищется по id
транзакции: при неявной блокировке (незакоммиченный INSERT) 8.0.46 пишет в `BLOCKING_THREAD_ID` поток
ожидающего.

«Машина времени»: сессия cron запускается с `SET TIMESTAMP = now + N минут` — так проверяются «прошёл час»
(pass A, +61 мин) и «прошёл `payment_due_at`» (pass B, +41 мин) без подделки данных.

## Сценарии

| Блок | Что доказывается |
|---|---|
| S0 | 44 гарантии схемы v2: имена `<таблица>_chk_*`/`_fk_*`, `'rub'` отклоняется, суммы > 0, регистрозависимые `external_item_id` (и PAD SPACE), `uq_refunds_idempotency`, FULLTEXT находит `und`, вторая установка с префиксом `wp_2_` в той же БД |
| S1 | Одновременное «Отложить»: `FOR UPDATE`; без него — 1062; двойной клик идемпотентен; стрессы 8 и 12 сессий |
| S2 | Неоплата за час: pass A, pass B после `payment_due_at`, checkout после истечения, гонка pass A ↔ резерв; платёж `created → cancelled` / `created → expired`; однократное продление `payment_due_extended_at` |
| S3 | Лимит 3 попыток (COUNT под блокировкой и CHECK); admin-release возвращает попытку; лимит 10 активных резервов под блокировкой корзины; чужая валюта — 409 `reason=currency` |
| S4 | Удаление из корзины, идемпотентный повтор, гонки remove ↔ checkout |
| S5 | Двойной webhook: последовательно, одновременно, два разных события, без блокировок (1062); второй успешный платёж → строка `wp_book_refunds` |
| S6 | Синхронизация: `sold` не перетирается; валюта ≠ RUB пропускается; sync ↔ reserve; два запуска; записи и экземпляры — разными транзакциями без deadlock с checkout; контроль: одна транзакция = deadlock |
| S7 | Callback ↔ снятие: все порядки; поздний платёж (re-acquire, конфликт → возврат, `source_status ≠ present` → `late_payment_source_check`); контрольный deadlock; стресс 24 сессии |
| S8 | Одна открытая корзина (ODKU); контроль `INSERT → 1062 → FOR UPDATE`; reserve ↔ checkout; стресс 72 сессии (XFAIL S8.7e); `Idempotency-Key` |
| S9 | 19 инвариантов, учёт deadlock-ов, EXPLAIN точечных запросов |

## Легенда вывода

- `PASS` / `FAIL` — проверка; в скобках фактическое значение.
- `XFAIL` — известное вероятностное поведение проявилось (прогон не валит), `INFO` — не проявилось.
  Это `S8.7e` (редкий deadlock создания корзины, docs/10 § 10.10) и `S1.5c` (то же для повторной вставки
  active-резерва). Инварианты не нарушаются, `Db::transaction()` повторяет транзакцию.
- Хронология `┌─ … └─` — реальный порядок шагов всех сессий; время в мс от первого шага.

## Как добавить сценарий

1. Процедура есть в `algorithms.sql` или добавляется туда, повторяя PHP-запросы.
2. В `run.sh`: `sub S<n>.<m> "описание"`, данные — `mk_item` / `mk_order` / `setup`.
3. Параллельность: `run_bg T1 "SET @pause_at = '<точка>'; CALL …"` и
   `run_bg T2 "CALL t_wait_paused('T1', '<точка>'); CALL …"`, затем `waitall; timeline`.
4. Проверки: `check` / `checkq` / `expect_err`, итог операции — `oc СЕССИЯ op`, поле ответа — `oj СЕССИЯ op '$.поле'`.
