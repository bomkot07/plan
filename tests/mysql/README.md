# tests/mysql — гарантии схемы и транзакционных алгоритмов на реальном MySQL 8.0

`run.sh` создаёт свежую БД, загружает каноническую `sql/schema.sql` (без изменений), наполняет тестовыми
данными и прогоняет сценарии из ТЗ, в том числе параллельные. Каждая проверка печатает `PASS` или `FAIL`.
Код выхода ненулевой, если есть хотя бы один `FAIL`. Подробный разбор сценариев с реальным выводом:
[docs/10-scenarios.md](../../docs/10-scenarios.md).

```bash
tests/mysql/run.sh                     # все сценарии, около 25 секунд
tests/mysql/run.sh S5 S7               # только сценарии с этими префиксами (+ итоговые инварианты S9)
QUIET_TIMELINE=1 tests/mysql/run.sh    # без хронологий параллельных сессий
UNIUNDATA_TEST_DB=my_test MYSQL_ARGS="-uroot -psecret -h127.0.0.1" tests/mysql/run.sh
```

| Переменная | По умолчанию | Назначение |
|---|---|---|
| `UNIUNDATA_TEST_DB` | `uniundata_test` | Тестовая БД. **Пересоздаётся** при каждом запуске. Имя обязано содержать `test`, иначе нужен `FORCE=1` (защита от `DROP` рабочей БД) |
| `MYSQL_ARGS` | `-uroot` | Параметры клиента `mysql` |
| `SCHEMA_FILE` | `sql/schema.sql` | Проверяемая схема |
| `QUIET_TIMELINE` | `0` | `1` отключает печать хронологий |

Требования: MySQL 8.0.16+ (CHECK-ограничения; проверено на 8.0.46), включённая `performance_schema`
(по ней определяется, кто кого ждёт), права на создание БД, процедур и `SHOW ENGINE INNODB STATUS`.
Из инструментов нужны только bash и клиент mysql.

Коды выхода: `0` — все проверки PASS (EXPECTED-FAIL допускаются), `1` — есть FAIL, `2` — ошибка окружения.

## Файлы

| Файл | Что внутри |
|---|---|
| `run.sh` | Оркестратор: БД, загрузка, сценарии S0–S9, проверки, хронологии |
| `harness.sql` | Обвязка: `t_trace` (хронология), `t_outcome` (итог операции: код REST, HTTP, errno), `t_session` (поток ↔ метка сессии), точки паузы `tp()`, `t_wait_paused()`, `t_rendezvous()`, `t_start_at()`, `t_retry()` |
| `algorithms.sql` | SQL-эквиваленты PHP-алгоритмов. Каждая процедура повторяет запросы сервиса: те же `SELECT … FOR UPDATE`, тот же порядок блокировок, те же условные `UPDATE` с проверкой числа строк |
| `naive.sql` | **Намеренно неправильные** варианты: без `FOR UPDATE`, без проверки лимита, с нарушенным порядком блокировок. Нужны, чтобы показать второй рубеж (UNIQUE/CHECK) и то, что тест действительно ловит deadlock |
| `seed.sql` | `t_mk_item()` (запись MARC + экземпляр) и базовый каталог `BASE-01…10` |
| `invariants.sql` | Представление `t_invariants`: 16 глобальных инвариантов, каждый должен давать 0 нарушений |

Соответствие процедур и PHP-кода:

| Процедура | PHP |
|---|---|
| `a_reserve` | `ReservationService::reserve()` (вкл. `lockOrCreateOpenCart`, `lockItem`, `releaseLocked`, `refreshCartLocked`) |
| `a_remove` | `ReservationService::removeFromCart()` |
| `a_admin_release` | `ReservationService::adminRelease()` |
| `a_expire_reservations` / `a_expire_one` | `ReservationExpiryService::expireDue()` — pass A |
| `a_checkout` | `CheckoutService::checkout()`: Tx1 `createOrderTx` → createSession (смоделирован) → Tx2 `markSessionOpenedTx` |
| `a_webhook` / `a_apply_succeeded` | `PaymentService::handleWebhook()` → `applyTx()` → `applySucceeded()` |
| `a_expire_orders` / `a_expire_order_one` | `OrderExpiryService::expireDue()` — pass B (ответ `fetchPayment` задаётся параметром) |
| `a_sync_start` / `a_sync_batch` / `a_sync_finish` | `SyncService`: `GET_LOCK` + строка `running`, `writeBatch()` / `applyItem()` / `decide()` |

Отличия от PHP не влияют на блокировки. `DomainError` заменён на `SIGNAL SQLSTATE '45000'` с кодом REST.
IN-списки `$wpdb->prepare()` выполняются динамическим SQL с теми же литералами. Блокировки по списку id
берутся циклом по возрастанию id, то есть в том же порядке, что `WHERE id IN (…) ORDER BY id FOR UPDATE`
по PRIMARY. Повтора при 1213/1205 внутри процедур нет: тест должен видеть каждый deadlock. В стресс-тестах
повтор `Db::transaction()` эмулирует `t_retry()`: до 3 повторов с паузой 50–200 мс.

## Как гарантируется пересечение транзакций

Без подбора `SLEEP`. В алгоритмы вставлены точки `tp('<имя>')`. Если сессия запущена с
`@pause_at = '<имя>'`, то в этой точке она:

1. держит все свои блокировки и ставит маяк `GET_LOCK('t:<сценарий>:<сессия>:<точка>')`;
2. ждёт, пока `performance_schema.data_lock_waits` покажет сессию, которая ждёт **её** транзакцию
   (`BLOCKING_ENGINE_TRANSACTION_ID`). Число ожидающих задаёт `@pause_waiters`, таймаут — `@pause_timeout`;
3. пишет в хронологию строку `ВИЖУ ОЖИДАНИЕ: U2 ждёт X,REC_NOT_GAP wp_book_items.PRIMARY (11)`. Это
   реальные данные `data_locks`: режим блокировки, индекс и ключ;
4. продолжает работу и делает COMMIT.

Партнёр начинает свою транзакцию только после `t_wait_paused('<сессия>', '<точка>')`, то есть когда маяк
уже стоит. Так порядок «T1 держит → T2 встаёт в очередь → T1 фиксирует → T2 перепроверяет» воспроизводится
при каждом запуске.

Особенность MySQL 8.0.46, найденная при отладке. Когда ожидающий натыкается на **неявную** блокировку
(незакоммиченный INSERT), InnoDB делает её явной от имени владельца, но `performance_schema` записывает в
`BLOCKING_THREAD_ID` поток ожидающего. Поэтому владелец ищется по id транзакции, а этот id берётся из живой
`performance_schema.data_locks`: `information_schema.innodb_trx` кэшируется примерно на 100 мс.

«Машина времени»: сессия cron запускается с `SET TIMESTAMP = now + N минут`, и `UTC_TIMESTAMP(6)` в ней
сдвинут. Так проверяются «прошёл час» (pass A, +61 мин) и «прошёл payment_due_at» (pass B, +41 мин), и
данные при этом не подделываются. `t_trace` пишется по `SYSDATE(6)`, то есть по реальным часам.

## Сценарии

| Блок | Что доказывается |
|---|---|
| S0 | 23 гарантии схемы: каждый CHECK / UNIQUE / FK срабатывает с ожидаемой ошибкой (вывод MySQL печатается) |
| S1 | Одновременное «Отложить»: `FOR UPDATE` (второй ждёт и видит `reserved`); без `FOR UPDATE` — 1062 по `uq_reservations_one_active_per_item`; двойной клик идемпотентен; стрессы 8 и 12 сессий |
| S2 | Неоплата за час: pass A (+59 — ничего, +61 — освобождение), pass B после `payment_due_at`, checkout после истечения, гонка pass A ↔ резерв другим |
| S3 | Лимит 3: 4-я попытка — 409 (COUNT под блокировкой) и CHECK 3819 для кода без проверки; admin-release возвращает попытку |
| S4 | Удаление из корзины, идемпотентный повтор, гонки remove ↔ checkout в обе стороны |
| S5 | Двойной webhook: последовательно, одновременно (одно событие и два разных), без блокировок — 1062 по `uq_sales_book_item`; второй успешный платёж |
| S6 | Синхронизация: `sold` не перетирается и `conflicts++`; sync ↔ reserve в обе стороны; два запуска (`GET_LOCK` + `uq_sync_runs_one_running`) |
| S7 | Callback ↔ снятие: webhook первым; pass B первым (поздний платёж забирает книгу); поздний платёж при чужом резерве (`late_payment_conflict`); pass A не трогает `checkout_pending`; checkout ↔ pass A на границе часа; контрольный deadlock; стресс 24 сессии |
| S8 | Одна открытая корзина при параллельном создании (ODKU); контроль `INSERT → 1062 → FOR UPDATE` (deadlock); книга не добавляется дважды; reserve ↔ checkout одного пользователя в обе стороны; контрольный deadlock; стресс 72 сессии; двойной checkout с одним Idempotency-Key |
| S9 | 16 глобальных инвариантов после всех сценариев, учёт deadlock-ов, EXPLAIN точечных запросов |

## Легенда вывода

- `PASS` / `FAIL` — проверка; в скобках фактическое значение.
- `XFAIL` — известный дефект схемы (EXPECTED-FAIL) с пояснением; прогон он не валит.
- `INFO` — вероятностный дефект в этом прогоне не проявился.
- `XPASS` — дефект, помеченный как EXPECTED-FAIL, больше не воспроизводится, пометку нужно снять.
- Хронология `┌─ … └─` — реальный порядок шагов всех сессий сценария; время в мс от первого шага.

Известные дефекты (подробно — в docs/10-scenarios.md, раздел 10.10):

- `S0.04` — `CHECK (currency REGEXP '^[A-Z]{3}$')` на колонке `ascii_general_ci` пропускает `'eur'`.
- `S8.7e` — вероятностный deadlock `INSERT … ON DUPLICATE KEY UPDATE` новых корзин разных пользователей
  на gap-блокировках `uq_carts_one_open_per_user`. Инварианты он не нарушает, `Db::transaction()` повторяет
  транзакцию.

## Как добавить сценарий

1. Нужная процедура уже есть в `algorithms.sql` или добавляется туда же, повторяя PHP-запросы.
2. В `run.sh`: `sub S<n>.<m> "описание"`, данные создаются через `mk_item` / `mk_order` / `setup`.
3. Параллельность: `run_bg T1 "SET @pause_at = '<точка>'; CALL …"` и
   `run_bg T2 "CALL t_wait_paused('T1', '<точка>'); CALL …"`, затем `waitall; timeline`.
4. Проверки: `check` / `checkq` / `expect_err`, итог операции — `oc СЕССИЯ op`, поля ответа — `oj СЕССИЯ op '$.поле'`.
