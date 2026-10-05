# 10. Сценарии из ТЗ на реальном MySQL 8.0

Каждый сценарий из раздела 11 ТЗ и дополнительные гонки (корзина, порядок блокировок) воспроизведены на
**настоящем MySQL 8.0.46** скриптом [`tests/mysql/run.sh`](../tests/mysql/run.sh). Для каждого сценария
ниже есть шаги во времени, SQL, блокировки, итоговое состояние, ссылка на проверки и **фактический вывод
`run.sh`** без правок. Номера id в выводе меняются от запуска к запуску, а порядок событий и исходы — нет.

Итог прогона, из которого взят вывод:

```
$ tests/mysql/run.sh
MySQL 8.0.46-0ubuntu0.24.04.4, тестовая БД 'uniundata_test', схема /home/user/plan/sql/schema.sql
загружено: schema.sql (20 таблиц wp_book_*), harness, algorithms, naive, seed, invariants
…
ИТОГ: PASS 176, FAIL 0, EXPECTED-FAIL 2, XPASS 0
```

## 10.0 Как это проверяется

**Что именно исполняется.** Каноническая [`sql/schema.sql`](../sql/schema.sql) загружается без изменений.
PHP-алгоритмы из `src/Service` и `src/Sync` перенесены в хранимые процедуры
[`tests/mysql/algorithms.sql`](../tests/mysql/algorithms.sql) один к одному. Сохранены те же
`SELECT … FOR UPDATE`, тот же глобальный порядок блокировок, те же условные `UPDATE … WHERE status = '<ожидаемый>'`
с проверкой числа строк, время только из `UTC_TIMESTAMP(6)` БД. Каждая сессия настраивается как
`Db::ensureSession()` и `$wpdb->set_charset()`:

```sql
SET NAMES utf8mb4 COLLATE utf8mb4_unicode_520_ci;
SET time_zone = '+00:00';
SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED;
SET SESSION innodb_lock_wait_timeout = 5;
```

| Процедура теста | PHP |
|---|---|
| `a_reserve(user, item)` | `ReservationService::reserve()` |
| `a_remove(user, item)` | `ReservationService::removeFromCart()` |
| `a_admin_release(admin, reservation)` | `ReservationService::adminRelease()` |
| `a_expire_reservations(limit)` | `ReservationExpiryService::expireDue()` — **pass A** (истёкшие резервы) |
| `a_checkout(user, idempotency_key, expected_total, currency)` | `CheckoutService::checkout()`: Tx1 → `createSession` вне транзакции → Tx2 |
| `a_webhook(event_id, provider_payment_id, status, amount, currency, public_order_id)` | `PaymentService::handleWebhook()` → `applyTx()` / `applySucceeded()` |
| `a_expire_order_one(order, bank_answer)` / `a_expire_orders` | `OrderExpiryService` — **pass B** (заказы после `payment_due_at`) |
| `a_sync_start` / `a_sync_batch` / `a_sync_finish` | `SyncService`: `GET_LOCK` + строка `running`, `writeBatch()` → `applyItem()` → `decide()` |

**Как гарантируется пересечение.** Подбора `SLEEP` нет. В алгоритмы вставлены точки паузы `tp('<имя>')`.
Сессия, запущенная с `@pause_at = '<имя>'`, в этой точке держит все свои блокировки и ждёт, пока
`performance_schema.data_lock_waits` покажет, что партнёр ждёт **её** транзакцию. Только после этого она
продолжает работу. Строка `ВИЖУ ОЖИДАНИЕ: U2 ждёт X,REC_NOT_GAP wp_book_items.PRIMARY (11)` в выводе — это
данные `data_locks`: кто ждёт, режим блокировки, таблица, индекс и ключ. Партнёр стартует через
`t_wait_paused()`, когда первая сессия уже на паузе. Поэтому порядок «T1 держит → T2 в очереди → T1 COMMIT →
T2 перепроверяет» повторяется при каждом запуске.

**Время.** «Прошёл час» не ждётся, а моделируется: сессия cron запускается с
`SET TIMESTAMP = сейчас + 61 мин`, и `UTC_TIMESTAMP(6)` в ней сдвинут (+41 мин для pass B, +59 мин для
checkout на границе часа). Данные при этом не меняются. Хронология пишется по реальным часам (`SYSDATE(6)`).

**Второй рубеж и контрольные deadlock-и.** [`tests/mysql/naive.sql`](../tests/mysql/naive.sql) содержит
намеренно неправильные варианты: без `FOR UPDATE`, без проверки лимита, с нарушенным порядком блокировок.
Они показывают две вещи: UNIQUE/CHECK схемы ловят то, что пропустил код; тест действительно ловит deadlock,
то есть отсутствие deadlock-ов в остальных сценариях не случайно.

**Стресс-прогоны.** Сессии стартуют по «стартовому пистолету» в общий момент времени, без пауз.
`Db::transaction()` повторяет транзакцию при 1213/1205; в стрессе это эмулирует `t_retry()`. Итоговые
проверки смотрят на результат после повторов, а каждый повтор учитывается отдельно.

Запуск:

```bash
tests/mysql/run.sh            # всё, ≈25 с
tests/mysql/run.sh S5 S7      # выборочно
```

| Сценарий ТЗ | Проверки | Результат |
|---|---|---|
| 1. Два пользователя одновременно жмут «Отложить» | S1.1–S1.5 | ровно один active-резерв; второй ждёт `FOR UPDATE` и получает 409; без `FOR UPDATE` срабатывает 1062 |
| 2. Пользователь не оплатил за час | S2.1–S2.4 | pass A освобождает в +61 мин, pass B — после `payment_due_at`; гонка с новым резервом безопасна |
| 3. Три резерва без покупки | S3.1–S3.3 | 4-я попытка — 409; CHECK 3819 как второй рубеж; admin-release попыткой не считается |
| 4. Удалил книгу из корзины | S4.1–S4.3 | резерв `cancelled`, книга `available`; гонки с checkout в обе стороны |
| 5. Оплата прошла, webhook дважды | S5.1–S5.5 | одна продажа, одно processed-событие, статусы не меняются повторно (в т. ч. при одновременной доставке) |
| 6. Синхронизация прислала проданную книгу | S6.1–S6.4 | `sold` не перетирается, `items_conflicts++`; sync ↔ reserve; один запуск синхронизации |
| 7. Callback одновременно со снятием | S7.1–S7.8 | webhook первым — не освобождается; pass B первым — поздний платёж забирает книгу или `late_payment_conflict` |
| Доп.: корзина и порядок блокировок | S8.1–S8.8 | одна открытая корзина; книга не дважды; reserve ↔ checkout без deadlock по порядку |
| Гарантии схемы / инварианты | S0, S9 | 22 PASS + 1 EXPECTED-FAIL; 16 инвариантов = 0 |

Глобальный порядок блокировок (docs/08, раздел 8.2), который соблюдают все процедуры:
`wp_book_carts → wp_book_items (по возрастанию id) → wp_book_reservations → wp_book_cart_items →
wp_book_orders → wp_book_payments → wp_book_payment_events`. Операция может пропускать уровни, но не менять
их порядок.

Обозначения блокировок InnoDB в выводе: `X,REC_NOT_GAP` — эксклюзивная блокировка только записи
(`FOR UPDATE` по равенству в READ COMMITTED); `S` / `X` — next-key-блокировка (запись + промежуток перед ней),
её InnoDB ставит при проверке дубликата в UNIQUE-индексе; `X,GAP` / `insert intention` — блокировки
промежутка при вставке.

---

## 10.1 Два пользователя одновременно нажимают «Отложить»

**Гарантия.** Активный резерв у экземпляра ровно один. Второй пользователь получает
`409 uniundata_item_unavailable` с `data.availability_status = "reserved"`, а не дубль и не 500. Даже код без
`FOR UPDATE` не создаст второй резерв: его остановит `uq_reservations_one_active_per_item`.

### Шаги во времени (S1.1, алгоритм `reserve`)

| Момент | U1 (user 1101) | U2 (user 1102) | Блокировки |
|---|---|---|---|
| t0 | `START TRANSACTION`; корзина: `SELECT … WHERE open_cart_user_id = 1101 FOR UPDATE` → нет → `INSERT … ON DUPLICATE KEY UPDATE` | — | U1: X на новую строку `wp_book_carts` |
| t1 | `SELECT … FROM wp_book_items i JOIN wp_book_records … WHERE i.id = X FOR UPDATE OF i` → `available` | — | U1: `X,REC_NOT_GAP` на `wp_book_items.PRIMARY(X)` |
| t2 | пауза (держит блокировки) | `START TRANSACTION`, своя корзина (без конфликта), `SELECT … FOR UPDATE OF i` того же экземпляра — **ждёт** | U2 в очереди: `X,REC_NOT_GAP wp_book_items.PRIMARY(X)` |
| t3 | `SELECT … WHERE active_book_item_id = X FOR UPDATE` → нет; `COUNT(*)` попыток = 0; `INSERT` резерва (attempt 1); `UPDATE … SET 'reserved' WHERE … = 'available'` (1 строка); `INSERT` позиции; `COMMIT` | ждёт | U1 снимает блокировки |
| t4 | — | получает блокировку, читает **`reserved`** → `SIGNAL uniundata_item_unavailable` → `ROLLBACK` | откатилась и корзина U2: попытка не потрачена |

```sql
-- ReservationService::reserveInTransaction() — порядок шагов
SELECT id, user_id, status FROM wp_book_carts WHERE open_cart_user_id = %d FOR UPDATE;          -- (a) 1. carts
SELECT i.id, i.availability_status, i.source_status, i.is_active, i.price_amount, i.currency, rec.is_active
  FROM wp_book_items i JOIN wp_book_records rec ON rec.id = i.book_record_id
 WHERE i.id = %d FOR UPDATE OF i;                                                              -- (b) 2. items
SELECT id, user_id, cart_id, … , (expires_at <= UTC_TIMESTAMP(6)) AS is_due
  FROM wp_book_reservations WHERE active_book_item_id = %d FOR UPDATE;                         -- (c) 3. reservations
SELECT COUNT(*) FROM wp_book_reservations
 WHERE user_id = %d AND book_item_id = %d AND attempt_no IS NOT NULL;                           -- (e) лимит
INSERT INTO wp_book_reservations (…, reservation_status, attempt_no, reserved_at, expires_at)
VALUES (…, 'active', %d, UTC_TIMESTAMP(6), UTC_TIMESTAMP(6) + INTERVAL 60 MINUTE);              -- (f)
UPDATE wp_book_items SET availability_status = 'reserved', status_changed_at = %s
 WHERE id = %d AND availability_status = 'available';                                          -- (g) ровно 1 строка
INSERT INTO wp_book_cart_items (…, unit_price_amount, currency, status, added_at, expires_at) …;-- (h) 4. cart_items
```

**Итоговое состояние:** один `active`-резерв (user 1101), экземпляр `reserved`, у U2 ни резерва, ни корзины.
Проверки — `S1.1a…g` (функция `s1` в `run.sh`).

```
--- S1.1  reserve с SELECT … FOR UPDATE: U2 ждёт блокировку строки экземпляра и видит reserved
    ┌─ хронология S1.1 (мс от первого шага; порядок — реальный порядок записи в t_trace)
    │      0.0 ms  U1      BEGIN reserve(user 1101, item #12)
    │      1.7 ms  U1      открытой корзины нет (FOR UPDATE ничего не нашёл) → INSERT … ON DUPLICATE KEY UPDATE
    │      3.9 ms  U1      создал корзину #6 (ODKU: 1 строка)
    │      8.2 ms  U1      FOR UPDATE экземпляра #12 → available
    │      9.2 ms  U1      ПАУЗА в reserve.after_item: держу блокировки, жду ожидающих: 1
    │     11.4 ms  U2      U1 на паузе в reserve.after_item — начинаю
    │     13.0 ms  U2      BEGIN reserve(user 1102, item #12)
    │     14.1 ms  U2      открытой корзины нет (FOR UPDATE ничего не нашёл) → INSERT … ON DUPLICATE KEY UPDATE
    │     15.5 ms  U2      создал корзину #7 (ODKU: 1 строка)
    │     20.0 ms  U1      ВИЖУ ОЖИДАНИЕ: U2 ждёт X,REC_NOT_GAP wp_book_items.PRIMARY (12)
    │     23.3 ms  U1      INSERT резерв #7 (попытка 1), экземпляр → reserved, позиция #4
    │     26.5 ms  U2      FOR UPDATE экземпляра #12 → reserved
    │     29.3 ms  U1      COMMIT → 201 created {"cart_id": 6, "attempt_no": 1, "expires_at": "2026-10-05 14:44:13.163190", "cart_item_id": 4, "reservation_id": 7}
    │     31.9 ms  U2      ROLLBACK → 409 uniundata_item_unavailable (errno 1644: uniundata_item_unavailable) {"book_item_id": 12, "availability_status": "reserved"}
    └─
  PASS   S1.1a    U1 получил резерв [201 created]
  PASS   S1.1b    U2 — 409 uniundata_item_unavailable [409 uniundata_item_unavailable]
  PASS   S1.1c    U2 после ожидания прочитал availability_status [reserved]
  PASS   S1.1d    U2 действительно ждал X-lock строки wp_book_items (data_lock_waits) [1]
  PASS   S1.1e    ровно один active-резерв на экземпляр, владелец U1 [1 / user 1101]
  PASS   S1.1f    у U2 попытка не потрачена и корзина не создана (откат целиком) [0/0]
  PASS   S1.1g    экземпляр reserved [reserved]
```

### Без `FOR UPDATE`: второй рубеж — `uq_reservations_one_active_per_item` (S1.2)

Процедура `n_reserve` («забыли» `FOR UPDATE`) читает статус обычным `SELECT`. В READ COMMITTED обе сессии
видят закоммиченный `available`.

| Момент | U1 | U2 | Блокировки |
|---|---|---|---|
| t0 | обычный `SELECT` → `available`; `INSERT` active-резерва; `UPDATE` экземпляра; пауза до COMMIT | — | U1: неявная X на новую запись `uq_reservations_one_active_per_item`, X на строку экземпляра |
| t1 | | обычный `SELECT` → **`available`** (незакоммиченное изменение U1 не видно); `INSERT` active-резерва — **ждёт** | U2: `S` (next-key) на дубликат в `uq_reservations_one_active_per_item` |
| t2 | `COMMIT` | `ERROR 1062 … for key 'wp_book_reservations.uq_reservations_one_active_per_item'` → 409 | |

Generated column `active_book_item_id = IF(reservation_status = 'active', book_item_id, NULL)` с UNIQUE-индексом
даёт в MySQL 8 «частичный уникальный индекс»: история резервов (`NULL`) не мешает, а второй активный
невозможен. `ReservationService::mapConstraintViolation()` превращает 1062 по этому ключу в 409 `uniundata_item_unavailable`.

```
--- S1.2  без FOR UPDATE (наивный код): второй рубеж — UNIQUE uq_reservations_one_active_per_item
    ┌─ хронология S1.2 (мс от первого шага; порядок — реальный порядок записи в t_trace)
    │      0.0 ms  U1      BEGIN naive reserve(user 1103, item #13) — БЕЗ FOR UPDATE экземпляра
    │      1.1 ms  U1      открытой корзины нет (FOR UPDATE ничего не нашёл) → INSERT … ON DUPLICATE KEY UPDATE
    │      2.1 ms  U1      создал корзину #8 (ODKU: 1 строка)
    │      5.1 ms  U1      обычный SELECT экземпляра #13 → available (снимок READ COMMITTED, без блокировки)
    │      8.3 ms  U1      INSERT резерв #8 (попытка 1), UPDATE экземпляра → reserved
    │     11.6 ms  U1      ПАУЗА в naive.before_commit: держу блокировки, жду ожидающих: 1
    │     13.1 ms  U2      U1 на паузе в naive.before_commit — начинаю
    │     14.8 ms  U2      BEGIN naive reserve(user 1104, item #13) — БЕЗ FOR UPDATE экземпляра
    │     16.3 ms  U2      открытой корзины нет (FOR UPDATE ничего не нашёл) → INSERT … ON DUPLICATE KEY UPDATE
    │     18.2 ms  U2      создал корзину #9 (ODKU: 1 строка)
    │     21.4 ms  U2      обычный SELECT экземпляра #13 → available (снимок READ COMMITTED, без блокировки)
    │     28.4 ms  U1      ВИЖУ ОЖИДАНИЕ: U2 ждёт S wp_book_reservations.uq_reservations_one_active_per_item (13, 8)
    │     35.3 ms  U1      COMMIT → 201 created {"attempt_no": 1, "reservation_id": 8}
    │     36.9 ms  U2      ROLLBACK → 409 uniundata_item_unavailable (errno 1062: Duplicate entry '13' for key 'wp_book_reservations.uq_reservations_one_active_per_item')
    └─
  PASS   S1.2a    U1 создал резерв [201 created]
  PASS   S1.2b    U2 прочитал available обычным SELECT (гонка воспроизведена) [1]
  PASS   S1.2c    U2 получил ERROR 1062 и откат [1062]
  PASS   S1.2d    1062 именно по uq_reservations_one_active_per_item → 409 item_unavailable [409 uniundata_item_unavailable | Duplicate entry '13' for key 'wp_book_reservations.uq_reservations_one_active_per_item']
  PASS   S1.2e    в БД один active-резерв [1]
```

### Тот же пользователь, двойной клик (S1.3)

Вторая вкладка ждёт уже на **корзине** (уровень 1 порядка блокировок). После COMMIT первой она находит свой
активный резерв и отвечает `200` с тем же `reservation_id`. Новая попытка не создаётся (контракт:
«повторное "Отложить" идемпотентно»).

```
--- S1.3  один пользователь, двойной клик (две вкладки): идемпотентно, попытка не тратится
    ┌─ хронология S1.3 (мс от первого шага; порядок — реальный порядок записи в t_trace)
    │      0.0 ms  T1      BEGIN reserve(user 1105, item #14)
    │      1.9 ms  T1      открытой корзины нет (FOR UPDATE ничего не нашёл) → INSERT … ON DUPLICATE KEY UPDATE
    │      4.2 ms  T1      создал корзину #10 (ODKU: 1 строка)
    │      7.1 ms  T1      FOR UPDATE экземпляра #14 → available
    │      9.3 ms  T1      INSERT резерв #10 (попытка 1), экземпляр → reserved, позиция #6
    │     10.9 ms  T1      ПАУЗА в reserve.before_commit: держу блокировки, жду ожидающих: 1
    │     13.3 ms  T2      T1 на паузе в reserve.before_commit — начинаю
    │     16.3 ms  T2      BEGIN reserve(user 1105, item #14)
    │     20.4 ms  T1      ВИЖУ ОЖИДАНИЕ: T2 ждёт X,REC_NOT_GAP wp_book_carts.uq_carts_one_open_per_user (1105, 10)
    │     23.5 ms  T2      FOR UPDATE корзины #10 (active)
    │     25.7 ms  T1      COMMIT → 201 created {"cart_id": 10, "attempt_no": 1, "expires_at": "2026-10-05 14:44:13.422040", "cart_item_id": 6, "reservation_id": 10}
    │     26.5 ms  T2      FOR UPDATE экземпляра #14 → reserved
    │     28.5 ms  T2      COMMIT → 200 existing {"cart_id": 10, "attempt_no": 1, "reservation_id": 10}
    └─
  PASS   S1.3a    первый запрос — 201 [201 created]
  PASS   S1.3b    второй — 200 с тем же резервом [200 existing #10]
  PASS   S1.3c    второй ждал на корзине пользователя (1-й уровень порядка блокировок) [1]
  PASS   S1.3d    одна попытка, одна позиция корзины, одна корзина [1/1/1]
```

### Стресс (S1.4, S1.5)

S1.4: 8 пользователей стартуют одновременно на один экземпляр — ровно один 201 и семь 409, deadlock-ов нет.
S1.5: 12 только что освобождённых соседних экземпляров резервируют 12 других пользователей одновременно;
это проверка на gap-блокировки в UNIQUE-индексах резервов, см. 10.10.

```
--- S1.4  стресс: 8 пользователей одновременно (стартовый пистолет), без пауз
  PASS   S1.4a    ровно один 201, семь 409 item_unavailable [1×201 created,7×409 uniundata_item_unavailable]
  PASS   S1.4b    ни одного deadlock / lock wait timeout [0]
  PASS   S1.4c    в БД один active-резерв [1]

--- S1.5  стресс: 12 соседних экземпляров только что освобождены, 12 других пользователей резервируют одновременно
  PASS   S1.5a    все 12 резервов созданы (с повтором как Db::transaction) [12]
  PASS   S1.5b    итог: ни одной операции, упавшей по deadlock / lock wait timeout [0]
  INFO   S1.5c    без повтора: ни одного deadlock [0 повторено] — вероятностный дефект в этом прогоне не проявился
```

---

## 10.2 Пользователь не оплатил за час

**Гарантия.** Резерв длится ровно час и не продлевается открытием корзины: `GET /cart` только читает. Через
час pass A освобождает экземпляр (`available`), резерв и позиция получают `expired`, попытка засчитана. Если
checkout уже начат, час «заморожен»: срок задаёт `payment_due_at = now + 30 + 10 мин`, освобождает pass B.

### S2.1 — pass A: +59 мин ничего, +61 мин освобождение

| Момент | U1 (user 1201) | CRON (pass A) | Блокировки |
|---|---|---|---|
| t0 | `reserve` → `expires_at = reserved_at + 60 мин`, COMMIT | — | — |
| +59 мин | — | кандидатов нет: `WHERE reservation_status = 'active' AND expires_at <= UTC_TIMESTAMP(6)` | — |
| +61 мин | — | кандидат найден обычным `SELECT`; транзакция: `FOR UPDATE` корзины → экземпляра → резерва, **перепроверка** (`active`, `is_due`); резерв → `expired`, позиция → `expired`, экземпляр `reserved → available`; корзина пуста → `expired`; COMMIT | X: `wp_book_carts.PRIMARY` → `wp_book_items.PRIMARY` → `wp_book_reservations.PRIMARY` → `wp_book_cart_items` |
| после | другой пользователь сразу резервирует → 201 | | |

```sql
-- кандидаты (ix_reservations_expiry), без блокировок
SELECT id, cart_id, book_item_id FROM wp_book_reservations
 WHERE reservation_status = 'active' AND expires_at <= UTC_TIMESTAMP(6) ORDER BY expires_at, id LIMIT %d;
-- по одному резерву, своя короткая транзакция
SELECT id FROM wp_book_carts WHERE id = %d FOR UPDATE;                                         -- 1
SELECT … FROM wp_book_items WHERE id = %d FOR UPDATE;                                          -- 2
SELECT reservation_status, … , (expires_at <= UTC_TIMESTAMP(6)) AS is_due
  FROM wp_book_reservations WHERE id = %d FOR UPDATE;                                          -- 3 + перепроверка
UPDATE wp_book_reservations SET reservation_status = 'expired', released_at = %s, release_reason = 'expired'
 WHERE id = %d AND reservation_status = 'active';
UPDATE wp_book_cart_items SET status = 'expired', closed_at = %s WHERE id = %d AND status = 'active'; -- 4
UPDATE wp_book_items SET availability_status = CASE source_status WHEN 'present' THEN 'available'
       WHEN 'missing' THEN 'sync_missing' WHEN 'withdrawn' THEN 'withdrawn' END
 WHERE id = %d AND availability_status = 'reserved';
```

```
--- S2.1  резерв без checkout: pass A через 59 мин ничего не делает, через 61 мин освобождает
  PASS   S2.1a    +59 мин: резерв ещё active, экземпляр reserved [active/reserved]
    ┌─ хронология S2.1 (мс от первого шага; порядок — реальный порядок записи в t_trace)
    │      0.0 ms  U1      BEGIN reserve(user 1201, item #28)
    │      1.8 ms  U1      открытой корзины нет (FOR UPDATE ничего не нашёл) → INSERT … ON DUPLICATE KEY UPDATE
    │      4.4 ms  U1      создал корзину #43 (ODKU: 1 строка)
    │      7.3 ms  U1      FOR UPDATE экземпляра #28 → available
    │     13.7 ms  U1      INSERT резерв #36 (попытка 1), экземпляр → reserved, позиция #32
    │     26.4 ms  U1      COMMIT → 201 created {"cart_id": 43, "attempt_no": 1, "expires_at": "2026-10-05 14:44:16.586847", "cart_item_id": 32, "reservation_id": 36}
    │     67.5 ms  CRON59  pass A: кандидаты (резерв:корзина:экземпляр) = —
    │    106.7 ms  CRON61  pass A: кандидаты (резерв:корзина:экземпляр) = 36:43:28
    │    108.4 ms  CRON61  BEGIN expire reservation #36
    │    109.2 ms  CRON61  FOR UPDATE корзины #43
    │    110.5 ms  CRON61  FOR UPDATE экземпляра #28 → reserved
    │    113.9 ms  CRON61  резерв #36 active→expired, позиция #32 →expired, экземпляр #28 reserved→available
    │    118.9 ms  CRON61  корзина #43 пуста → expired
    │    131.5 ms  CRON61  COMMIT → 200 expired {"item_status": "available", "reservation_id": 36}
    └─
  PASS   S2.1b    +61 мин: резерв expired (release_reason expired), released_at заполнен [expired/expired/1]
  PASS   S2.1c    позиция корзины expired, корзина закрыта как expired (последняя позиция) [expired/expired]
  PASS   S2.1d    экземпляр снова available [available]
  PASS   S2.1e    другой пользователь сразу может отложить [201 created]
  PASS   S2.1f    попытка U1 засчитана (attempt_no сохранён) [1]
```

### S2.2 — checkout начат, оплаты нет: pass A не трогает, pass B освобождает после `payment_due_at`

| Момент | Событие | Результат |
|---|---|---|
| t0 | резерв + checkout | заказ `pending_payment`, экземпляр `checkout_pending`, резерв `converted_to_order` |
| +61 мин | pass A | не кандидат: резерв уже не `active` |
| +39 мин | pass B (`fetchPayment` → pending) | `FOR UPDATE` экземпляра и заказа; `payment_due_at` не наступил → noop |
| +41 мин | pass B (`GET_LOCK('uniundata_expire_orders', 0)`, кандидаты по `ix_orders_payment_due`) | заказ `payment_expired`, платёж `expired`, экземпляр → release target `available` |

```
--- S2.2  checkout начат, оплаты нет: pass A не трогает, pass B после payment_due_at (40 мин) освобождает
  PASS   S2.2a    заказ создан, pending_payment, экземпляр checkout_pending [pending_payment/checkout_pending]
  PASS   S2.2b    pass A (+61 мин): резерв уже converted_to_order — экземпляр не тронут [checkout_pending]
  PASS   S2.2c    pass B (+39 мин, до payment_due_at): noop [200 noop/pending_payment]
    ┌─ хронология S2.2 (мс от первого шага; порядок — реальный порядок записи в t_trace)
    │      0.0 ms  CRONA   pass A: кандидаты (резерв:корзина:экземпляр) = —
    │     25.0 ms  CRONB39 pass B: заказ #3, банк (fetchPayment вне транзакции) ответил: pending
    │     27.5 ms  CRONB39 FOR UPDATE items #29=checkout_pending
    │     29.5 ms  CRONB39 FOR UPDATE заказ #3 → pending_payment, просрочен: 0
    │     32.0 ms  CRONB39 COMMIT → 200 noop {"is_due": 0, "order_status": "pending_payment"}
    │     63.5 ms  CRONB41 pass B: кандидаты = 3
    │     67.6 ms  CRONB41 pass B: заказ #3, банк (fetchPayment вне транзакции) ответил: pending
    │     68.8 ms  CRONB41 FOR UPDATE items #29=checkout_pending
    │     69.7 ms  CRONB41 FOR UPDATE заказ #3 → pending_payment, просрочен: 1
    │     72.4 ms  CRONB41 заказ #3 → payment_expired, экземпляры [29] освобождены
    │     86.0 ms  CRONB41 COMMIT → 200 payment_expired {"order_id": 3, "released_items": "29"}
    └─
  PASS   S2.2d    pass B (+41 мин): заказ payment_expired [payment_expired]
  PASS   S2.2e    платёж expired, экземпляр available [expired/available]
```

### S2.3 — checkout после истечения часа, но раньше cron

Checkout сам перепроверяет резервы под блокировкой (`expires_at > UTC_TIMESTAMP(6)`). Истёкшую позицию он
помечает `expired`, освобождает экземпляр, **коммитит** это и отвечает `409 uniundata_cart_changed`
(`positions_expired`). Заказ не создаётся.

```
--- S2.3  checkout после истечения часа, но раньше cron: позиция истекает в самом checkout
    ┌─ хронология S2.3 (мс от первого шага; порядок — реальный порядок записи в t_trace)
    │      0.0 ms  U1      BEGIN checkout(user 1204, expected 3200 EUR)
    │      0.8 ms  U1      FOR UPDATE корзины #46 (active)
    │      3.3 ms  U1      FOR UPDATE items #30=reserved
    │      4.3 ms  U1      FOR UPDATE reservations #39=active
    │      4.8 ms  U1      FOR UPDATE cart_items #35=active
    │      9.5 ms  U1      позиция #35 (экземпляр #30) невалидна: expired → expired
    │     16.8 ms  U1      COMMIT → 409 uniundata_cart_changed {"reason": "positions_expired", "invalid": "35:30:39:expired"}
    └─
  PASS   S2.3a    409 uniundata_cart_changed (positions_expired) [409 uniundata_cart_changed positions_expired]
  PASS   S2.3b    заказ не создан, резерв expired (checkout_expired), экземпляр available [0/expired:checkout_expired/available]
```

### S2.4 — гонка «pass A освобождает ↔ другой пользователь жмёт "Отложить"»

| Момент | CRON (pass A, +61 мин) | U2 (user 1206) | Блокировки |
|---|---|---|---|
| t0 | `FOR UPDATE` корзины и экземпляра; резерв → `expired`, экземпляр → `available`; пауза до COMMIT | — | CRON: X на экземпляр |
| t1 | | своя корзина; `FOR UPDATE` экземпляра — **ждёт** | U2: `X,REC_NOT_GAP wp_book_items.PRIMARY` |
| t2 | COMMIT | читает `available` → резерв создан | |

Книга ни в один момент не находится в двух резервах. До COMMIT cron другой пользователь не видит `available`.

```
--- S2.4  гонка: pass A освобождает ↔ другой пользователь в этот момент жмёт «Отложить»
    ┌─ хронология S2.4 (мс от первого шага; порядок — реальный порядок записи в t_trace)
    │      0.0 ms  CRON    pass A: кандидаты (резерв:корзина:экземпляр) = 40:47:31
    │      6.8 ms  CRON    BEGIN expire reservation #40
    │      8.6 ms  CRON    FOR UPDATE корзины #47
    │     12.7 ms  CRON    FOR UPDATE экземпляра #31 → reserved
    │     16.6 ms  CRON    резерв #40 active→expired, позиция #36 →expired, экземпляр #31 reserved→available
    │     20.9 ms  CRON    корзина #47 пуста → expired
    │     23.9 ms  CRON    ПАУЗА в expA.before_commit: держу блокировки, жду ожидающих: 1
    │     28.1 ms  U2      CRON на паузе в expA.before_commit — начинаю
    │     35.1 ms  U2      BEGIN reserve(user 1206, item #31)
    │     37.0 ms  U2      открытой корзины нет (FOR UPDATE ничего не нашёл) → INSERT … ON DUPLICATE KEY UPDATE
    │     38.6 ms  U2      создал корзину #48 (ODKU: 1 строка)
    │     43.1 ms  CRON    ВИЖУ ОЖИДАНИЕ: U2 ждёт X,REC_NOT_GAP wp_book_items.PRIMARY (31)
    │     52.4 ms  U2      FOR UPDATE экземпляра #31 → available
    │     54.9 ms  CRON    COMMIT → 200 expired {"item_status": "available", "reservation_id": 40}
    │     57.1 ms  U2      INSERT резерв #41 (попытка 1), экземпляр → reserved, позиция #37
    │     61.4 ms  U2      COMMIT → 201 created {"cart_id": 48, "attempt_no": 1, "expires_at": "2026-10-05 14:44:17.392231", "cart_item_id": 37, "reservation_id": 41}
    └─
  PASS   S2.4a    U2 ждал X-lock экземпляра, удерживаемый cron [1]
  PASS   S2.4b    после COMMIT cron U2 получил резерв [201 created]
  PASS   S2.4c    старый резерв expired, новый active у U2 [1205:expired,1206:active]
```

---

## 10.3 Пользователь три раза резервировал и не купил

**Гарантия.** Попытка — это любой резерв пары (пользователь, экземпляр), кроме `released_by_admin`. Четвёртая
попытка получает `409 uniundata_reservation_limit_reached` (`data.used = 3`). Проверка идёт под блокировкой
экземпляра, поэтому `COUNT(*)` стабилен: все резервы экземпляра создаются только под этой блокировкой. В БД
действуют `UNIQUE(user_id, book_item_id, attempt_no)` + `CHECK (attempt_no BETWEEN 1 AND 3)` +
`CHECK ((status = 'released_by_admin') = (attempt_no IS NULL))`.

| Попытка | Как завершилась | `attempt_no` | Считается |
|---|---|---|---|
| 1 | удаление из корзины | 1 | да |
| 2 | истекла (pass A, +61 мин) | 2 | да |
| 3 | удаление из корзины | 3 | да |
| 4 | `409 reservation_limit_reached`, строка не создаётся | — | — |

```sql
SELECT COUNT(*) FROM wp_book_reservations
 WHERE user_id = %d AND book_item_id = %d AND attempt_no IS NOT NULL;   -- под FOR UPDATE экземпляра
-- ≥ 3 → 409; иначе INSERT … attempt_no = COUNT + 1
-- admin release: … SET reservation_status = 'released_by_admin', attempt_no = NULL …  (слот освобождается)
```

```
--- S3.1  1-я: удалил из корзины; 2-я: истекла (pass A); 3-я: удалил; 4-я — 409
    ┌─ хронология S3.1 (мс от первого шага; порядок — реальный порядок записи в t_trace)
    │      0.0 ms  U1      BEGIN reserve(user 1301, item #32)
    │      1.5 ms  U1      открытой корзины нет (FOR UPDATE ничего не нашёл) → INSERT … ON DUPLICATE KEY UPDATE
    │      3.5 ms  U1      создал корзину #49 (ODKU: 1 строка)
    │      7.6 ms  U1      FOR UPDATE экземпляра #32 → available
    │     10.9 ms  U1      INSERT резерв #42 (попытка 1), экземпляр → reserved, позиция #38
    │     18.5 ms  U1      COMMIT → 201 created {"cart_id": 49, "attempt_no": 1, "expires_at": "2026-10-05 14:44:17.523001", "cart_item_id": 38, "reservation_id": 42}
    │     20.6 ms  U1      BEGIN remove-item(user 1301, item #32)
    │     21.7 ms  U1      FOR UPDATE корзины #49 (active)
    │     24.6 ms  U1      резерв #42 active→cancelled, позиция #38 →removed, экземпляр #32 reserved→available
    │     31.8 ms  U1      COMMIT → 200 removed {"cart_id": 49, "item_status": "available", "reservation_id": 42}
    │     33.1 ms  U1      BEGIN reserve(user 1301, item #32)
    │     33.9 ms  U1      FOR UPDATE корзины #49 (active)
    │     34.6 ms  U1      FOR UPDATE экземпляра #32 → available
    │     36.6 ms  U1      INSERT резерв #43 (попытка 2), экземпляр → reserved, позиция #39
    │     43.3 ms  U1      COMMIT → 201 created {"cart_id": 49, "attempt_no": 2, "expires_at": "2026-10-05 14:44:17.548834", "cart_item_id": 39, "reservation_id": 43}
    │     60.1 ms  CRON    pass A: кандидаты (резерв:корзина:экземпляр) = 43:49:32
    │     62.1 ms  CRON    BEGIN expire reservation #43
    │     63.9 ms  CRON    FOR UPDATE корзины #49
    │     65.7 ms  CRON    FOR UPDATE экземпляра #32 → reserved
    │     69.5 ms  CRON    резерв #43 active→expired, позиция #39 →expired, экземпляр #32 reserved→available
    │     74.2 ms  CRON    корзина #49 пуста → expired
    │     86.5 ms  CRON    COMMIT → 200 expired {"item_status": "available", "reservation_id": 43}
    │    104.3 ms  U1      BEGIN reserve(user 1301, item #32)
    │    106.0 ms  U1      открытой корзины нет (FOR UPDATE ничего не нашёл) → INSERT … ON DUPLICATE KEY UPDATE
    │    108.5 ms  U1      создал корзину #50 (ODKU: 1 строка)
    │    110.8 ms  U1      FOR UPDATE экземпляра #32 → available
    │    115.2 ms  U1      INSERT резерв #44 (попытка 3), экземпляр → reserved, позиция #40
    │    122.0 ms  U1      COMMIT → 201 created {"cart_id": 50, "attempt_no": 3, "expires_at": "2026-10-05 14:44:17.625907", "cart_item_id": 40, "reservation_id": 44}
    │    124.3 ms  U1      BEGIN remove-item(user 1301, item #32)
    │    125.8 ms  U1      FOR UPDATE корзины #50 (active)
    │    130.0 ms  U1      резерв #44 active→cancelled, позиция #40 →removed, экземпляр #32 reserved→available
    │    136.5 ms  U1      COMMIT → 200 removed {"cart_id": 50, "item_status": "available", "reservation_id": 44}
    │    137.8 ms  U1      BEGIN reserve(user 1301, item #32)
    │    138.6 ms  U1      FOR UPDATE корзины #50 (active)
    │    139.2 ms  U1      FOR UPDATE экземпляра #32 → available
    │    141.5 ms  U1      ROLLBACK → 409 uniundata_reservation_limit_reached (errno 1644: uniundata_reservation_limit_reached) {"used": 3, "book_item_id": 32, "max_attempts": 3}
    └─
  PASS   S3.1a    история: попытки 1..3 (cancelled, expired, cancelled) [1:cancelled,2:expired,3:cancelled]
  PASS   S3.1b    4-я попытка — 409 uniundata_reservation_limit_reached (COUNT под блокировкой) [409 uniundata_reservation_limit_reached]
  PASS   S3.1c    data.used = 3 [3]
  PASS   S3.1d    4-я строка не создана, экземпляр available [3/available]
  PASS   S3.1e    лимит персональный: другой пользователь резервирует [201 created attempt 1]
```

**Второй рубеж (S3.2).** Код, который «забыл» проверить лимит, вставляет `attempt_no = 4` и получает
`ERROR 3819 Check constraint 'ck_reservations_attempt_range'`. Ошибка отображается в 409 `limit_reached`.

```
--- S3.2  второй рубеж: код без проверки лимита упирается в CHECK ck_reservations_attempt_range
  PASS   S3.2a    наивный INSERT attempt_no = 4 → ERROR 3819 → 409 limit_reached [3819 409 uniundata_reservation_limit_reached]
  PASS   S3.2b    в БД по-прежнему 3 попытки [3]
```

**Admin-release не считается (S3.3).** Менеджер снимает третий резерв: `released_by_admin`,
`attempt_no = NULL`. Пользователь снова резервирует, и это снова попытка 3. Номера идут подряд, потому что
admin release обнуляет только последний, активный резерв.

```
--- S3.3  admin-release возвращает попытку: attempt_no := NULL
  PASS   S3.3a    менеджер снял 3-й резерв [200 released]
  PASS   S3.3b    резерв released_by_admin, attempt_no = NULL [released_by_admin/NULL]
  PASS   S3.3c    пользователь снова может отложить — это попытка 3 [201 created attempt 3]
  PASS   S3.3d    после трёх засчитанных попыток — 409 [409 uniundata_reservation_limit_reached]
  PASS   S3.3e    история попыток [1:cancelled,2:cancelled,NULL:released_by_admin,3:cancelled]
```

---

## 10.4 Пользователь удалил книгу из корзины

**Гарантия.** Удаление разрешено, пока позиция активна. Резерв → `cancelled` (`user_removed`, это попытка),
позиция → `removed`, экземпляр → release target (`available`). Корзина остаётся открытой, даже пустой.
Повторное удаление отвечает `200 already_removed`. Удаление и checkout начинаются с `FOR UPDATE` одной и той
же корзины, поэтому одна из операций всегда видит результат другой.

```sql
SELECT … FROM wp_book_carts WHERE open_cart_user_id = %d FOR UPDATE;          -- 1
SELECT … FROM wp_book_items WHERE id = %d FOR UPDATE;                         -- 2
SELECT … FROM wp_book_reservations WHERE active_book_item_id = %d FOR UPDATE; -- 3
SELECT id, status FROM wp_book_cart_items WHERE reservation_id = %d FOR UPDATE; -- 4
UPDATE wp_book_reservations SET reservation_status = 'cancelled', release_reason = 'user_removed', … WHERE … = 'active';
UPDATE wp_book_cart_items  SET status = 'removed', closed_at = … WHERE … = 'active';
UPDATE wp_book_items       SET availability_status = <release target> WHERE … = 'reserved';
```

```
--- S4.1  удаление: резерв cancelled, позиция removed, экземпляр available; повтор идемпотентен
    ┌─ хронология S4.1 (мс от первого шага; порядок — реальный порядок записи в t_trace)
    │      0.0 ms  U1      BEGIN reserve(user 1401, item #34)
    │      1.7 ms  U1      открытой корзины нет (FOR UPDATE ничего не нашёл) → INSERT … ON DUPLICATE KEY UPDATE
    │      3.6 ms  U1      создал корзину #53 (ODKU: 1 строка)
    │      8.3 ms  U1      FOR UPDATE экземпляра #34 → available
    │     13.7 ms  U1      INSERT резерв #50 (попытка 1), экземпляр → reserved, позиция #46
    │     22.4 ms  U1      COMMIT → 201 created {"cart_id": 53, "attempt_no": 1, "expires_at": "2026-10-05 14:44:18.209251", "cart_item_id": 46, "reservation_id": 50}
    │     35.6 ms  U1      BEGIN reserve(user 1401, item #35)
    │     39.6 ms  U1      FOR UPDATE корзины #53 (active)
    │     41.0 ms  U1      FOR UPDATE экземпляра #35 → available
    │     44.6 ms  U1      INSERT резерв #51 (попытка 1), экземпляр → reserved, позиция #47
    │     51.4 ms  U1      COMMIT → 201 created {"cart_id": 53, "attempt_no": 1, "expires_at": "2026-10-05 14:44:18.240160", "cart_item_id": 47, "reservation_id": 51}
    │     53.4 ms  U1      BEGIN remove-item(user 1401, item #34)
    │     54.8 ms  U1      FOR UPDATE корзины #53 (active)
    │     59.1 ms  U1      резерв #50 active→cancelled, позиция #46 →removed, экземпляр #34 reserved→available
    │     66.8 ms  U1      COMMIT → 200 removed {"cart_id": 53, "item_status": "available", "reservation_id": 50}
    └─
  PASS   S4.1a    remove-item → 200 removed, экземпляр available [200 removed available]
  PASS   S4.1b    резерв cancelled (user_removed), позиция removed [cancelled:user_removed/removed]
  PASS   S4.1c    корзина открыта, expires_at = срок оставшейся книги [active/1]
  PASS   S4.1d    повторное удаление — 200 already_removed [200 already_removed]
  PASS   S4.1e    пустая корзина остаётся открытой; повторный резерв той же книги — попытка 2 в той же корзине [active/2/2]
```

### Гонка remove ↔ checkout

| Момент | S4.2: remove первым | S4.3: checkout первым |
|---|---|---|
| t0 | R: `FOR UPDATE` корзины, книга A освобождена, пауза | CO: `FOR UPDATE` корзины → экземпляров → резервов → позиций, заказ создан, пауза |
| t1 | CO: `FOR UPDATE` корзины — **ждёт** (`uq_carts_one_open_per_user`) | R: `FOR UPDATE` корзины — **ждёт** |
| t2 | R COMMIT → CO видит одну книгу B: сумма 5300 ≠ ожидаемой 10500 → `409 cart_changed (total_mismatch)` | CO COMMIT (корзина `converted_to_order`) → R не находит открытую корзину, позиция книги `converted_to_order` → `409 cart_changed (item_in_order)` |

```
--- S4.2  гонка remove ↔ checkout: remove первым → checkout видит изменённую корзину (409)
    ┌─ хронология S4.2 (мс от первого шага; порядок — реальный порядок записи в t_trace)
    │      0.0 ms  R       BEGIN remove-item(user 1402, item #36)
    │      1.2 ms  R       FOR UPDATE корзины #54 (active)
    │      4.2 ms  R       резерв #53 active→cancelled, позиция #49 →removed, экземпляр #36 reserved→available
    │      6.4 ms  R       ПАУЗА в remove.before_commit: держу блокировки, жду ожидающих: 1
    │     11.3 ms  CO      R на паузе в remove.before_commit — начинаю
    │     14.1 ms  CO      BEGIN checkout(user 1402, expected 10500 EUR)
    │     24.8 ms  R       ВИЖУ ОЖИДАНИЕ: CO ждёт X,REC_NOT_GAP wp_book_carts.uq_carts_one_open_per_user (1402, 54)
    │     28.7 ms  CO      FOR UPDATE корзины #54 (active)
    │     31.6 ms  R       COMMIT → 200 removed {"cart_id": 54, "item_status": "available", "reservation_id": 53}
    │     32.4 ms  CO      FOR UPDATE items #37=reserved
    │     33.3 ms  CO      FOR UPDATE reservations #54=active
    │     33.9 ms  CO      FOR UPDATE cart_items #50=active
    │     36.6 ms  CO      COMMIT → 409 uniundata_cart_changed {"items": "37", "reason": "total_mismatch", "currency": "EUR", "actual_total_amount": 5300}
    └─
  PASS   S4.2a    remove — 200 [200 removed]
  PASS   S4.2b    checkout ждал корзину и ответил 409 cart_changed (total_mismatch: 5300 вместо 10500) [409 uniundata_cart_changed total_mismatch 5300]
  PASS   S4.2c    заказа нет; A available, B reserved [0/available/reserved]

--- S4.3  гонка remove ↔ checkout: checkout первым → remove получает 409 (книга уже в заказе)
    ┌─ хронология S4.3 (мс от первого шага; порядок — реальный порядок записи в t_trace)
    │      0.0 ms  CO      BEGIN checkout(user 1403, expected 10900 EUR)
    │      1.0 ms  CO      FOR UPDATE корзины #55 (active)
    │      3.0 ms  CO      FOR UPDATE items #38=reserved, #39=reserved
    │      3.9 ms  CO      FOR UPDATE reservations #55=active, #56=active
    │      4.8 ms  CO      FOR UPDATE cart_items #51=active, #52=active
    │     11.5 ms  CO      заказ #4 draft, экземпляры 38,39 → checkout_pending, резервы → converted_to_order, платёж #2 created
    │     12.5 ms  CO      ПАУЗА в checkout.before_commit: держу блокировки, жду ожидающих: 1
    │     14.4 ms  R       CO на паузе в checkout.before_commit — начинаю
    │     15.9 ms  R       BEGIN remove-item(user 1403, item #38)
    │     23.0 ms  CO      ВИЖУ ОЖИДАНИЕ: R ждёт X,REC_NOT_GAP wp_book_carts.uq_carts_one_open_per_user (1403, 55)
    │     25.8 ms  CO      Tx1 COMMIT; createSession у банка — вне транзакции; Tx2: orders → payments (created → pending)
    │     27.1 ms  R       открытой корзины нет
    │     31.3 ms  R       ROLLBACK → 409 uniundata_cart_changed (errno 1644: uniundata_cart_changed) {"reason": "item_in_order", "book_item_id": 38}
    │     33.2 ms  CO      COMMIT → 201 created {"items": "38,39", "order_id": 4, "payment_id": 2, "total_amount": 10900, "public_order_id": "uniundata_1b9da9d7-da63-4265-a4f5-f472094f30cd", "provider_payment_id": "pp_2"}
    └─
  PASS   S4.3a    checkout — 201 [201 created]
  PASS   S4.3b    remove ждал корзину, затем 409 cart_changed (item_in_order) [409 uniundata_cart_changed item_in_order]
  PASS   S4.3c    экземпляры A и B — checkout_pending [checkout_pending/checkout_pending]
```

---

## 10.5 Оплата прошла, но webhook пришёл дважды

**Гарантия.** Одна строка `wp_book_sales` (`UNIQUE(book_item_id)`), одна строка события
(`UNIQUE(provider, provider_event_id)`), статусы не меняются повторно: `updated_at` заказа и экземпляра
прежние, переход в `paid` и `sold` записан в аудит один раз. Повтор отвечает банку `200`.

Порядок в `PaymentService`: подпись проверена → inbox (`INSERT … ON DUPLICATE KEY UPDATE attempts = attempts + 1`,
автокоммит) → если `processed`/`ignored`, то сразу duplicate → иначе транзакция
`items (asc) → orders → payments → payment_events FOR UPDATE` → **перепроверка статуса события под
блокировкой** → применение → событие `processed` → COMMIT.

| Момент | S5.1: повтор после обработки | S5.2: то же событие одновременно (WH1, WH2) |
|---|---|---|
| t0 | WH1: inbox `received` → продажа, заказ `paid`, событие `processed`, COMMIT | WH1: inbox (новое) → `FOR UPDATE` экземпляра, пауза |
| t1 | WH2: inbox — `attempts = 2`, статус `processed` → `200 duplicate`, транзакции нет | WH2: inbox (`attempts = 2`, ещё `received`) → `FOR UPDATE` экземпляра — **ждёт** |
| t2 | | WH1: заказ, платежи, событие, продажа, COMMIT |
| t3 | | WH2: получает блокировки; событие уже `processed` → `200 duplicate` (`under_lock`), ничего не меняет |

```sql
INSERT INTO wp_book_payment_events (provider, provider_event_id, …, processing_status, …, attempts)
VALUES (%s, %s, …, 'received', …, 1)
ON DUPLICATE KEY UPDATE attempts = LEAST(attempts + 1, 65535), id = LAST_INSERT_ID(id);   -- вне транзакции
-- транзакция
SELECT … FROM wp_book_items WHERE id IN (…) ORDER BY id FOR UPDATE;
SELECT … FROM wp_book_orders WHERE id = %d FOR UPDATE;
SELECT … FROM wp_book_payments WHERE order_id = %d ORDER BY id FOR UPDATE;
SELECT processing_status FROM wp_book_payment_events WHERE id = %d FOR UPDATE;   -- processed → duplicate
UPDATE wp_book_items SET availability_status = 'sold', sold_at = UTC_TIMESTAMP(6), …
 WHERE id IN (…) AND availability_status = 'checkout_pending';
INSERT INTO wp_book_sales (…) SELECT … FROM wp_book_order_items …;               -- UNIQUE(book_item_id)
UPDATE wp_book_orders SET status = 'paid', paid_at = … WHERE id = %d AND status = %s;
UPDATE wp_book_payment_events SET processing_status = 'processed', … WHERE id = %d AND processing_status IN ('received','failed');
```

```
--- S5.1  повтор того же события ПОСЛЕ обработки: inbox отвечает duplicate, ничего не меняется
    ┌─ хронология S5.1 (мс от первого шага; порядок — реальный порядок записи в t_trace)
    │      0.0 ms  WH1     webhook S5-1-evt: succeeded 6000 EUR для pp_3
    │      2.2 ms  WH1     inbox: событие #3 новое, processing_status = received
    │      5.5 ms  WH1     FOR UPDATE items #40=checkout_pending
    │      7.6 ms  WH1     FOR UPDATE заказ #5 (pending_payment), платежи (1), событие #3 (received)
    │     11.7 ms  WH1     платёж (заказ pending_payment): продаём [40], конфликт []
    │     18.9 ms  WH1     COMMIT → 200 processed {"note": "", "event_id": 3, "order_id": 5, "refund_enqueued": null}
    │     38.6 ms  WH2     webhook S5-1-evt: succeeded 6000 EUR для pp_3
    │     41.0 ms  WH2     inbox: событие #3 уже было (attempts+1), processing_status = processed
    │     43.7 ms  WH2     COMMIT → 200 duplicate {"where": "inbox", "event_id": 3}
    └─
  PASS   S5.1a    первый — processed, второй — duplicate (inbox) [200 processed / 200 duplicate inbox]
  PASS   S5.1b    одна продажа, заказ paid, платёж succeeded, экземпляр sold [1/paid/succeeded/sold]
  PASS   S5.1c    одна строка события: processed, attempts = 2 [1/processed/2]
  PASS   S5.1d    повтор не тронул строки заказа и экземпляра (updated_at не изменился) [2026-10-05 13:44:18.918619|2026-10-05 13:44:18.917517]
  PASS   S5.1e    переход заказа в paid и экземпляра в sold записан в аудит один раз [1/1]

--- S5.2  то же событие ОДНОВРЕМЕННО двумя сессиями: второй ждёт блокировку и видит processed
    ┌─ хронология S5.2 (мс от первого шага; порядок — реальный порядок записи в t_trace)
    │      0.0 ms  WH1     webhook S5-2-evt: succeeded 6100 EUR для pp_4
    │      2.9 ms  WH1     inbox: событие #5 новое, processing_status = received
    │      5.9 ms  WH1     FOR UPDATE items #41=checkout_pending
    │      7.0 ms  WH1     ПАУЗА в apply.after_items: держу блокировки, жду ожидающих: 1
    │      9.2 ms  WH2     WH1 на паузе в apply.after_items — начинаю
    │     11.1 ms  WH2     webhook S5-2-evt: succeeded 6100 EUR для pp_4
    │     14.1 ms  WH2     inbox: событие #5 уже было (attempts+1), processing_status = received
    │     22.8 ms  WH1     ВИЖУ ОЖИДАНИЕ: WH2 ждёт X,REC_NOT_GAP wp_book_items.PRIMARY (41)
    │     24.1 ms  WH1     FOR UPDATE заказ #6 (pending_payment), платежи (1), событие #5 (received)
    │     26.8 ms  WH1     платёж (заказ pending_payment): продаём [41], конфликт []
    │     32.6 ms  WH2     FOR UPDATE items #41=sold
    │     35.3 ms  WH1     COMMIT → 200 processed {"note": "", "event_id": 5, "order_id": 6, "refund_enqueued": null}
    │     36.2 ms  WH2     FOR UPDATE заказ #6 (paid), платежи (1), событие #5 (processed)
    │     37.8 ms  WH2     COMMIT → 200 duplicate {"where": "under_lock", "event_id": 5}
    └─
  PASS   S5.2a    оба прошли inbox (received), WH2 ждал X-lock экземпляра [1]
  PASS   S5.2b    WH1 processed, WH2 duplicate (обнаружен под блокировкой события) [200 processed / 200 duplicate under_lock]
  PASS   S5.2c    одна продажа, одна строка события processed (attempts 2), paid в аудите один раз [1/1:processed:2/1]
```

**Два разных события об одном успехе (S5.3).** У банка разные `event_id`, поэтому inbox их не склеивает.
Второе событие под блокировкой видит, что платёж уже `succeeded`, и становится `ignored (already_succeeded)`.

```
--- S5.3  банк прислал ДВА РАЗНЫХ события об одном успехе одновременно
    ┌─ хронология S5.3 (мс от первого шага; порядок — реальный порядок записи в t_trace)
    │      0.0 ms  WH1     webhook S5-3-evt-A: succeeded 6200 EUR для pp_5
    │      3.9 ms  WH1     inbox: событие #7 новое, processing_status = received
    │      8.6 ms  WH1     FOR UPDATE items #42=checkout_pending
    │     10.9 ms  WH1     FOR UPDATE заказ #7 (pending_payment), платежи (1), событие #7 (received)
    │     14.3 ms  WH1     платёж (заказ pending_payment): продаём [42], конфликт []
    │     18.3 ms  WH1     ПАУЗА в apply.before_commit: держу блокировки, жду ожидающих: 1
    │     22.8 ms  WH2     WH1 на паузе в apply.before_commit — начинаю
    │     25.3 ms  WH2     webhook S5-3-evt-B: succeeded 6200 EUR для pp_5
    │     28.9 ms  WH2     inbox: событие #8 новое, processing_status = received
    │     36.4 ms  WH1     ВИЖУ ОЖИДАНИЕ: WH2 ждёт X,REC_NOT_GAP wp_book_items.PRIMARY (42)
    │     42.2 ms  WH2     FOR UPDATE items #42=sold
    │     45.4 ms  WH1     COMMIT → 200 processed {"note": "", "event_id": 7, "order_id": 7, "refund_enqueued": null}
    │     46.6 ms  WH2     FOR UPDATE заказ #7 (paid), платежи (1), событие #8 (received)
    │     49.1 ms  WH2     платёж #5 уже succeeded — повтор успеха, ничего не меняю
    │     53.9 ms  WH2     COMMIT → 200 ignored {"note": "already_succeeded", "event_id": 8, "order_id": 7, "refund_enqueued": null}
    └─
  PASS   S5.3a    A processed; B — ignored (already_succeeded) [200 processed / 200 ignored already_succeeded]
  PASS   S5.3b    одна продажа; события: A processed, B ignored [1/processed,ignored]
```

**Без блокировок и inbox (S5.4) — второй рубеж `uq_sales_book_item`.** Наивный обработчик читает
`checkout_pending` обычным `SELECT`. N2 ждёт строку экземпляра на своём `UPDATE`. После COMMIT N1 его
`INSERT` в `wp_book_sales` получает `ERROR 1062 … 'wp_book_sales.uq_sales_book_item'`, и вторая продажа
невозможна.

```
--- S5.4  без блокировок и inbox (наивный обработчик): второй рубеж — UNIQUE uq_sales_book_item
    ┌─ хронология S5.4 (мс от первого шага; порядок — реальный порядок записи в t_trace)
    │      0.0 ms  N1      naive webhook: обычный SELECT экземпляра #43 → checkout_pending
    │      2.4 ms  N1      UPDATE экземпляра → sold, INSERT в wp_book_sales
    │      4.9 ms  N1      ПАУЗА в nsell.before_commit: держу блокировки, жду ожидающих: 1
    │      8.1 ms  N2      N1 на паузе в nsell.before_commit — начинаю
    │     10.0 ms  N2      naive webhook: обычный SELECT экземпляра #43 → checkout_pending
    │     16.8 ms  N1      ВИЖУ ОЖИДАНИЕ: N2 ждёт X,REC_NOT_GAP wp_book_items.PRIMARY (43)
    │     23.7 ms  N1      COMMIT → 200 sold
    │     28.6 ms  N2      ROLLBACK → 500 uniundata_internal (errno 1062: Duplicate entry '43' for key 'wp_book_sales.uq_sales_book_item')
    └─
  PASS   S5.4a    N2 прочитал checkout_pending обычным SELECT (гонка воспроизведена) [1]
  PASS   S5.4b    N2 — ERROR 1062 по uq_sales_book_item [1062 | Duplicate entry '43' for key 'wp_book_sales.uq_sales_book_item']
  PASS   S5.4c    продажа одна [1]
```

**Второй успешный платёж (S5.5)** по уже оплаченному заказу (две вкладки банка). Платёж принимается
(`succeeded`), заказ получает `needs_attention = duplicate_payment`, в очередь ставится возврат на полную
сумму, вторая продажа не создаётся.

```
--- S5.5  второй УСПЕШНЫЙ платёж по уже оплаченному заказу (две вкладки банка)
  PASS   S5.5a    второй платёж принят (succeeded), заказ помечен duplicate_payment [duplicate_payment/paid:1:duplicate_payment]
  PASS   S5.5b    в очередь поставлен возврат второго платежа на полную сумму [6400 duplicate_payment]
  PASS   S5.5c    экземпляр продан один раз [1]
```

---

## 10.6 Синхронизация получила ранее проданную книгу повторно

**Гарантия.** Локальные `reserved / checkout_pending / sold / blocked` синхронизация не перетирает никогда. Она
меняет только `source_status` и увеличивает `items_conflicts`. Для `sold` в `error_log` прогона пишется
`conflict_sold` (`errors_count` не растёт). Цена проданного экземпляра не меняется, продажа не трогается.
Пакет блокирует строку прогона, затем экземпляры `FOR UPDATE` по возрастанию id, решение принимает чистая
функция `decide(local, local_source, incoming)`, а `UPDATE … WHERE id = %d AND availability_status = <прочитанный>`
страхует от ошибок порядка.

| `decide()` | источник `present` | источник `withdrawn` |
|---|---|---|
| `available` / `sync_missing` | `available` | `withdrawn` |
| `reserved` / `checkout_pending` / `blocked` | без изменений | без изменений, `source_status = withdrawn`, **конфликт** |
| `sold` | без изменений, **конфликт** (`conflict_sold`) | без изменений |

```
--- S6.1  sold не перетирается, items_conflicts++, цена проданного не меняется
  PASS   S6.1a    исходно экземпляр продан [sold]
    ┌─ хронология S6.1 (мс от первого шага; порядок — реальный порядок записи в t_trace)
    │      0.0 ms  SYNC    GET_LOCK синхронизации получен
    │     10.8 ms  SYNC    COMMIT → 200 started {"run_id": 1}
    │     13.5 ms  SYNC    BEGIN sync batch run #1 (3 записей)
    │     15.6 ms  SYNC    FOR UPDATE items #45=sold, #46=available
    │     18.2 ms  SYNC    экземпляр #45 S6-SOLD: локально sold/present, источник present → sold/present (КОНФЛИКТ)
    │     20.6 ms  SYNC    экземпляр #46 S6-AVAIL: локально available/present, источник present → available/present
    │     32.6 ms  SYNC    COMMIT → 200 applied {"created": 1, "skipped": 0, "updated": 2, "conflicts": 1, "withdrawn": 0}
    └─
  PASS   S6.1b    проданный: sold, sold_at сохранён, цена прежняя (7000), source_status present [sold/1/7000/present]
  PASS   S6.1c    обычный экземпляр обновлён (цена 7500), новый создан [7500/available]
  PASS   S6.1d    журнал прогона: conflicts=1, created=1, updated=2, ошибка conflict_sold (errors_count не растёт) [conflicts=1 created=1 updated=2 errors=0 conflict_sold status=succeeded]
  PASS   S6.1e    продажа не тронута [1]
  PASS   S6.1f    повторный прогон того же пакета: sold на месте, конфликт снова посчитан, остальное skipped [sold conflicts=1 skipped=3 created=0]
```

### Синхронизация ↔ «Отложить» одновременно

| Момент | S6.2: reserve первым | S6.3: sync первым |
|---|---|---|
| t0 | U1: экземпляр `reserved`, пауза до COMMIT | SYNC: `FOR UPDATE` экземпляра, пауза |
| t1 | SYNC: `FOR UPDATE` экземпляра — **ждёт** | U1: `FOR UPDATE` экземпляра — **ждёт** |
| t2 | U1 COMMIT → SYNC видит `reserved`: статус не трогает, `source_status = withdrawn`, конфликт | SYNC: `available → withdrawn`, COMMIT → U1 видит `withdrawn` → 409 |
| t3 | пользователь удаляет книгу → release target = `withdrawn` (не `available`) | |

```
--- S6.2  синхронизация одновременно с «Отложить»: источник снял книгу, а её резервируют
    ┌─ хронология S6.2 (мс от первого шага; порядок — реальный порядок записи в t_trace)
    │      0.0 ms  U1      BEGIN reserve(user 1602, item #48)
    │      2.2 ms  U1      открытой корзины нет (FOR UPDATE ничего не нашёл) → INSERT … ON DUPLICATE KEY UPDATE
    │      4.5 ms  U1      создал корзину #62 (ODKU: 1 строка)
    │      8.6 ms  U1      FOR UPDATE экземпляра #48 → available
    │     13.9 ms  U1      INSERT резерв #63 (попытка 1), экземпляр → reserved, позиция #59
    │     17.3 ms  U1      ПАУЗА в reserve.before_commit: держу блокировки, жду ожидающих: 1
    │     21.7 ms  SYNC    U1 на паузе в reserve.before_commit — начинаю
    │     24.2 ms  SYNC    GET_LOCK синхронизации получен
    │     29.6 ms  SYNC    COMMIT → 200 started {"run_id": 3}
    │     31.4 ms  SYNC    BEGIN sync batch run #3 (1 записей)
    │     36.0 ms  U1      ВИЖУ ОЖИДАНИЕ: SYNC ждёт X,REC_NOT_GAP wp_book_items.PRIMARY (48)
    │     38.0 ms  SYNC    FOR UPDATE items #48=reserved
    │     39.5 ms  U1      COMMIT → 201 created {"cart_id": 62, "attempt_no": 1, "expires_at": "2026-10-05 14:44:20.362004", "cart_item_id": 59, "reservation_id": 63}
    │     40.0 ms  SYNC    экземпляр #48 S6-RES: локально reserved/present, источник withdrawn → reserved/withdrawn (КОНФЛИКТ)
    │     44.9 ms  SYNC    COMMIT → 200 applied {"created": 0, "skipped": 0, "updated": 1, "conflicts": 1, "withdrawn": 0}
    └─
  PASS   S6.2a    sync ждал X-lock экземпляра, который держит reserve [1]
  PASS   S6.2b    reserved не перетёрт; source_status = withdrawn; conflicts = 1 [reserved/withdrawn/1]
  PASS   S6.2c    после удаления из корзины экземпляр получает release target = withdrawn [withdrawn/withdrawn]

--- S6.3  синхронизация первой: «Отложить» ждёт и получает 409 (книга снята)
    ┌─ хронология S6.3 (мс от первого шага; порядок — реальный порядок записи в t_trace)
    │      0.0 ms  SYNC    GET_LOCK синхронизации получен
    │      3.9 ms  SYNC    COMMIT → 200 started {"run_id": 4}
    │      5.7 ms  SYNC    BEGIN sync batch run #4 (1 записей)
    │      7.7 ms  SYNC    FOR UPDATE items #49=available
    │      9.4 ms  SYNC    ПАУЗА в sync.after_items: держу блокировки, жду ожидающих: 1
    │     11.3 ms  U1      SYNC на паузе в sync.after_items — начинаю
    │     13.0 ms  U1      BEGIN reserve(user 1603, item #49)
    │     14.0 ms  U1      открытой корзины нет (FOR UPDATE ничего не нашёл) → INSERT … ON DUPLICATE KEY UPDATE
    │     15.2 ms  U1      создал корзину #63 (ODKU: 1 строка)
    │     19.3 ms  SYNC    ВИЖУ ОЖИДАНИЕ: U1 ждёт X,REC_NOT_GAP wp_book_items.PRIMARY (49)
    │     20.6 ms  SYNC    экземпляр #49 S6-RES2: локально available/present, источник withdrawn → withdrawn/withdrawn
    │     24.5 ms  U1      FOR UPDATE экземпляра #49 → withdrawn
    │     25.9 ms  SYNC    COMMIT → 200 applied {"created": 0, "skipped": 0, "updated": 1, "conflicts": 0, "withdrawn": 1}
    │     27.5 ms  U1      ROLLBACK → 409 uniundata_item_unavailable (errno 1644: uniundata_item_unavailable) {"book_item_id": 49, "availability_status": "withdrawn"}
    └─
  PASS   S6.3a    reserve — 409 item_unavailable (withdrawn) [409 uniundata_item_unavailable withdrawn]
```

**Два запуска синхронизации (S6.4).** Слой 1 — `GET_LOCK('uniundata_sync_<source>@<db>', 0)`: второй процесс
сразу выходит (`locked`). Слой 2 — строка `running` + `UNIQUE(running_source)`: вторую `running`-строку не
вставить (1062), а если процесс умер и строка осталась, новый запуск видит её и отвечает `busy`.

```
--- S6.4  два запуска синхронизации одновременно: GET_LOCK + UNIQUE(running_source)
  PASS   S6.4a    второй запуск сразу выходит: GET_LOCK занят [200 locked]
  PASS   S6.4b    второй running-прогон источника на уровне данных (uq_sync_runs_one_running)
           MySQL: ERROR 1062 (23000): Duplicate entry 'primary' for key 'wp_book_sync_runs.uq_sync_runs_one_running'
  PASS   S6.4c    процесс умер, строка running осталась: GET_LOCK свободен, но запуск видит running → busy [200 busy]
```

---

## 10.7 Callback банка одновременно с задачей снятия истёкших резервов / заказов

После checkout экземпляр держит заказ (`checkout_pending`), а не резерв. Поэтому с webhook конкурирует
**pass B** (`OrderExpiryService`). Pass A резервы `converted_to_order` не видит (S7.4). Обе стороны берут
`items (asc) → orders → payments`, кто второй — перепроверяет статус заказа под блокировкой.

| Момент | S7.1: webhook первым | S7.2: pass B первым, книга свободна | S7.3: pass B первым, книгу уже резервирует другой |
|---|---|---|---|
| t0 | WH: `FOR UPDATE` экземпляра, продажа, заказ `paid`, пауза | CRON: `FOR UPDATE` экземпляра и заказа, заказ `payment_expired`, экземпляр `available`, пауза | CRON: `payment_expired`, `available`, COMMIT; U2: `reserve` держит экземпляр, пауза |
| t1 | CRON (+41 мин): `FOR UPDATE` экземпляра — **ждёт** | WH: `FOR UPDATE` экземпляра — **ждёт** | WH: `FOR UPDATE` экземпляра — **ждёт** |
| t2 | WH COMMIT → CRON видит заказ `paid` → **noop**, ничего не освобождает | CRON COMMIT → WH: поздний платёж, экземпляр `available` без active-резерва → **re-acquire**: продажа, заказ `paid` | U2 COMMIT → WH: экземпляр `reserved` → **конфликт**: заказ `paid` + `needs_attention = late_payment_conflict`, продажи нет, возврат 8200 в очередь |

```sql
-- pass B (OrderExpiryService::expireTx): fetchPayment у банка — ДО транзакции
SELECT … FROM wp_book_items WHERE id IN (…) ORDER BY id FOR UPDATE;
SELECT status, (payment_due_at <= UTC_TIMESTAMP(6)) AS is_due FROM wp_book_orders WHERE id = %d FOR UPDATE;
-- заказ не открыт (paid) или срок не наступил → noop
SELECT … FROM wp_book_payments WHERE order_id = %d ORDER BY attempt_no FOR UPDATE;  -- есть succeeded → noop
UPDATE wp_book_orders SET status = 'payment_expired' WHERE id = %d AND status IN ('draft','pending_payment','payment_processing','payment_failed');
UPDATE wp_book_items SET availability_status = <release target> WHERE id IN (…) AND availability_status = 'checkout_pending';
-- webhook, поздний платёж (applySucceeded): заказ payment_expired/cancelled →
--   свободен = availability_status IN ('available','sync_missing','withdrawn') И нет active-резерва
--   → UPDATE … 'sold' WHERE … IN ('available','sync_missing','withdrawn'); INSERT wp_book_sales
--   иначе → needs_attention = 'late_payment_conflict', возврат по конфликтным позициям
```

```
--- S7.1  webhook успел первым (держит экземпляр) → pass B ждёт и ничего не освобождает
    ┌─ хронология S7.1 (мс от первого шага; порядок — реальный порядок записи в t_trace)
    │      0.0 ms  WH      webhook S7-1-evt: succeeded 8000 EUR для pp_10
    │      5.2 ms  WH      inbox: событие #12 новое, processing_status = received
    │      8.1 ms  WH      FOR UPDATE items #50=checkout_pending
    │      9.4 ms  WH      FOR UPDATE заказ #11 (pending_payment), платежи (1), событие #12 (received)
    │     12.8 ms  WH      платёж (заказ pending_payment): продаём [50], конфликт []
    │     17.6 ms  WH      ПАУЗА в apply.before_commit: держу блокировки, жду ожидающих: 1
    │     19.0 ms  CRON    WH на паузе в apply.before_commit — начинаю
    │     20.4 ms  CRON    pass B: заказ #11, банк (fetchPayment вне транзакции) ответил: pending
    │     27.4 ms  WH      ВИЖУ ОЖИДАНИЕ: CRON ждёт X,REC_NOT_GAP wp_book_items.PRIMARY (50)
    │     33.3 ms  CRON    FOR UPDATE items #50=sold
    │     35.8 ms  WH      COMMIT → 200 processed {"note": "", "event_id": 12, "order_id": 11, "refund_enqueued": null}
    │     36.6 ms  CRON    FOR UPDATE заказ #11 → paid, просрочен: 1
    │     40.6 ms  CRON    COMMIT → 200 noop {"is_due": 1, "order_status": "paid"}
    └─
  PASS   S7.1a    pass B ждал X-lock экземпляра, удерживаемый webhook [1]
  PASS   S7.1b    webhook processed; pass B — noop (заказ уже paid) [200 processed / 200 noop paid]
  PASS   S7.1c    заказ paid, экземпляр sold, продажа одна [paid/sold/1]

--- S7.2  pass B успел первым (заказ payment_expired) → поздний платёж забирает свободный экземпляр
    ┌─ хронология S7.2 (мс от первого шага; порядок — реальный порядок записи в t_trace)
    │      0.0 ms  CRON    pass B: заказ #12, банк (fetchPayment вне транзакции) ответил: pending
    │      2.0 ms  CRON    FOR UPDATE items #51=checkout_pending
    │      3.1 ms  CRON    FOR UPDATE заказ #12 → pending_payment, просрочен: 1
    │      6.0 ms  CRON    заказ #12 → payment_expired, экземпляры [51] освобождены
    │      9.4 ms  CRON    ПАУЗА в oexp.before_commit: держу блокировки, жду ожидающих: 1
    │     10.5 ms  WH      CRON на паузе в oexp.before_commit — начинаю
    │     12.4 ms  WH      webhook S7-2-evt: succeeded 8100 EUR для pp_11
    │     15.6 ms  WH      inbox: событие #13 новое, processing_status = received
    │     25.5 ms  CRON    ВИЖУ ОЖИДАНИЕ: WH ждёт X,REC_NOT_GAP wp_book_items.PRIMARY (51)
    │     30.0 ms  WH      FOR UPDATE items #51=available
    │     32.2 ms  CRON    COMMIT → 200 payment_expired {"order_id": 12, "released_items": "51"}
    │     33.0 ms  WH      FOR UPDATE заказ #12 (payment_expired), платежи (1), событие #13 (received)
    │     35.8 ms  WH      ПОЗДНИЙ платёж (заказ payment_expired): продаём [51], конфликт []
    │     44.9 ms  WH      COMMIT → 200 processed {"note": "late_payment_reacquired", "event_id": 13, "order_id": 12, "refund_enqueued": null}
    └─
  PASS   S7.2a    webhook ждал X-lock экземпляра, удерживаемый pass B [1]
  PASS   S7.2b    pass B: payment_expired; webhook: late_payment_reacquired [200 payment_expired / late_payment_reacquired]
  PASS   S7.2c    заказ paid без флага, платёж expired→succeeded, экземпляр sold, продажа одна [paid:0/succeeded/sold/1]
  PASS   S7.2d    аудит: платёж подтверждён поздно (late_confirmation) [true]

--- S7.3  pass B освободил, другой пользователь резервирует, и в этот момент приходит поздний платёж
    ┌─ хронология S7.3 (мс от первого шага; порядок — реальный порядок записи в t_trace)
    │      0.0 ms  CRON    pass B: заказ #13, банк (fetchPayment вне транзакции) ответил: pending
    │      1.7 ms  CRON    FOR UPDATE items #52=checkout_pending
    │      3.2 ms  CRON    FOR UPDATE заказ #13 → pending_payment, просрочен: 1
    │      6.3 ms  CRON    заказ #13 → payment_expired, экземпляры [52] освобождены
    │     14.2 ms  CRON    COMMIT → 200 payment_expired {"order_id": 13, "released_items": "52"}
    │     30.1 ms  U2      BEGIN reserve(user 1704, item #52)
    │     31.6 ms  U2      открытой корзины нет (FOR UPDATE ничего не нашёл) → INSERT … ON DUPLICATE KEY UPDATE
    │     33.0 ms  U2      создал корзину #67 (ODKU: 1 строка)
    │     35.2 ms  U2      FOR UPDATE экземпляра #52 → available
    │     41.7 ms  U2      INSERT резерв #67 (попытка 1), экземпляр → reserved, позиция #63
    │     46.4 ms  U2      ПАУЗА в reserve.before_commit: держу блокировки, жду ожидающих: 1
    │     51.5 ms  WH      U2 на паузе в reserve.before_commit — начинаю
    │     54.7 ms  WH      webhook S7-3-evt: succeeded 8200 EUR для pp_12
    │     60.4 ms  WH      inbox: событие #14 новое, processing_status = received
    │     69.7 ms  U2      ВИЖУ ОЖИДАНИЕ: WH ждёт X,REC_NOT_GAP wp_book_items.PRIMARY (52)
    │     73.7 ms  WH      FOR UPDATE items #52=reserved
    │     75.7 ms  U2      COMMIT → 201 created {"cart_id": 67, "attempt_no": 1, "expires_at": "2026-10-05 14:44:23.362479", "cart_item_id": 63, "reservation_id": 67}
    │     77.2 ms  WH      FOR UPDATE заказ #13 (payment_expired), платежи (1), событие #14 (received)
    │     81.0 ms  WH      ПОЗДНИЙ платёж (заказ payment_expired): продаём [], конфликт [52]
    │     85.1 ms  WH      заказ #13 → needs_attention = late_payment_conflict
    │     93.2 ms  WH      COMMIT → 200 processed {"note": "late_payment_conflict", "event_id": 14, "order_id": 13, "refund_enqueued": {"amount": 8200, "reason": "late_payment_conflict", "payment_id": 12}}
    └─
  PASS   S7.3a    webhook ждал X-lock экземпляра, который держит резерв U2 [1]
  PASS   S7.3b    U2 получил резерв; webhook: late_payment_conflict [201 created / late_payment_conflict]
  PASS   S7.3c    заказ paid + needs_attention=late_payment_conflict, продажи нет, экземпляр за U2 [paid:1:late_payment_conflict/0/reserved:1704]
  PASS   S7.3d    в очередь поставлен возврат полной суммы [8200 late_payment_conflict]
```

### Pass A и оплата

S7.4: через 61 минуту после резерва оплачиваемый экземпляр остаётся `checkout_pending`, резерв —
`converted_to_order`.

S7.5 и S7.6 показывают гонку на границе часа: checkout стартует на 59-й минуте, pass A — на 61-й. Обе
операции начинают с корзины пользователя. Если первым успел checkout, pass A перепроверяет резерв, видит
`converted_to_order` и пропускает его. Если первым успел pass A, корзина закрывается как `expired`, и checkout
отвечает `409 cart_empty`. Заказа на освобождённую книгу не возникает.

```
--- S7.4  pass A не освобождает экземпляр, по которому идёт оплата (checkout_pending)
  PASS   S7.4a    через 61 мин после резерва: заказ pending_payment, экземпляр checkout_pending [pending_payment/checkout_pending]
  PASS   S7.4b    резерв converted_to_order, не expired [converted_to_order]

--- S7.5  гонка на границе часа: checkout (59:xx) ↔ pass A (60:xx) — checkout первым
    ┌─ хронология S7.5 (мс от первого шага; порядок — реальный порядок записи в t_trace)
    │      0.0 ms  CO      BEGIN checkout(user 1706, expected 8400 EUR)
    │      1.0 ms  CO      FOR UPDATE корзины #69 (active)
    │      3.7 ms  CO      FOR UPDATE items #54=reserved
    │      5.2 ms  CO      FOR UPDATE reservations #69=active
    │      7.0 ms  CO      FOR UPDATE cart_items #65=active
    │     15.8 ms  CO      заказ #15 draft, экземпляры 54 → checkout_pending, резервы → converted_to_order, платёж #14 created
    │     18.9 ms  CO      ПАУЗА в checkout.before_commit: держу блокировки, жду ожидающих: 1
    │     21.0 ms  CRON    CO на паузе в checkout.before_commit — начинаю
    │     23.2 ms  CRON    pass A: кандидаты (резерв:корзина:экземпляр) = 69:69:54
    │     24.9 ms  CRON    BEGIN expire reservation #69
    │     30.1 ms  CO      ВИЖУ ОЖИДАНИЕ: CRON ждёт X,REC_NOT_GAP wp_book_carts.PRIMARY (69)
    │     34.4 ms  CO      Tx1 COMMIT; createSession у банка — вне транзакции; Tx2: orders → payments (created → pending)
    │     38.7 ms  CRON    FOR UPDATE корзины #69
    │     40.6 ms  CRON    FOR UPDATE экземпляра #54 → checkout_pending
    │     45.4 ms  CO      COMMIT → 201 created {"items": "54", "order_id": 15, "payment_id": 14, "total_amount": 8400, "public_order_id": "uniundata_1c407d75-f5d1-4e3e-9700-03b6017c0830", "provider_payment_id": "pp_14"}
    │     47.6 ms  CRON    COMMIT → 200 skipped {"is_due": 1, "reservation_id": 69, "reservation_status": "converted_to_order"}
    └─
  PASS   S7.5a    pass A ждал корзину пользователя (1-й уровень порядка) [1]
  PASS   S7.5b    checkout 201; pass A пропустил резерв (уже converted_to_order) [201 created / 200 skipped converted_to_order]
  PASS   S7.5c    экземпляр checkout_pending [checkout_pending]

--- S7.6  гонка на границе часа: pass A первым → checkout получает 409, заказа нет
    ┌─ хронология S7.6 (мс от первого шага; порядок — реальный порядок записи в t_trace)
    │      0.0 ms  CRON    pass A: кандидаты (резерв:корзина:экземпляр) = 70:70:55
    │      1.6 ms  CRON    BEGIN expire reservation #70
    │      2.8 ms  CRON    FOR UPDATE корзины #70
    │      4.2 ms  CRON    FOR UPDATE экземпляра #55 → reserved
    │      9.9 ms  CRON    резерв #70 active→expired, позиция #66 →expired, экземпляр #55 reserved→available
    │     14.1 ms  CRON    корзина #70 пуста → expired
    │     16.2 ms  CRON    ПАУЗА в expA.before_commit: держу блокировки, жду ожидающих: 1
    │     18.6 ms  CO      CRON на паузе в expA.before_commit — начинаю
    │     21.2 ms  CO      BEGIN checkout(user 1707, expected 8500 EUR)
    │     25.3 ms  CRON    ВИЖУ ОЖИДАНИЕ: CO ждёт X,REC_NOT_GAP wp_book_carts.uq_carts_one_open_per_user (1707, 70)
    │     27.7 ms  CO      открытой корзины нет
    │     29.8 ms  CRON    COMMIT → 200 expired {"item_status": "available", "reservation_id": 70}
    │     30.6 ms  CO      ROLLBACK → 409 uniundata_cart_empty (errno 1644: uniundata_cart_empty)
    └─
  PASS   S7.6a    checkout ждал корзину, которую держит pass A [1]
  PASS   S7.6b    pass A: expired; checkout: 409 cart_empty (корзина закрыта как expired) [200 expired / 409 uniundata_cart_empty]
  PASS   S7.6c    заказа нет, экземпляр available [0/available]
```

### Контроль: неправильный порядок блокировок даёт deadlock (S7.7)

Чтобы отсутствие deadlock-ов в S7.1–S7.2 и S7.8 что-то доказывало, тест должен уметь их ловить. Сессия BAD
блокирует `orders → items` (наоборот), pass B — `items → orders`. MySQL воспроизводимо обнаруживает deadlock
(`ERROR 1213`), одна из транзакций откатывается.

```
--- S7.7  контроль: webhook с НЕПРАВИЛЬНЫМ порядком (orders → items) против pass B (items → orders) = deadlock
    ┌─ хронология S7.7 (мс от первого шага; порядок — реальный порядок записи в t_trace)
    │      0.0 ms  BAD     FOR UPDATE orders #16 (НАРУШЕН глобальный порядок)
    │      1.1 ms  BAD     ПАУЗА в pair.after_first: держу блокировки, жду ожидающих: 1
    │      2.8 ms  CRON    BAD на паузе в pair.after_first — начинаю
    │      4.7 ms  CRON    pass B: заказ #16, банк (fetchPayment вне транзакции) ответил: pending
    │      6.8 ms  CRON    FOR UPDATE items #56=checkout_pending
    │     11.1 ms  BAD     ВИЖУ ОЖИДАНИЕ: CRON ждёт X,REC_NOT_GAP wp_book_orders.PRIMARY (16)
    │     14.1 ms  CRON    FOR UPDATE заказ #16 → pending_payment, просрочен: 1
    │     15.9 ms  BAD     ROLLBACK → 503 uniundata_conflict_retry (errno 1213: Deadlock found when trying to get lock; try restarting transaction)
    │     18.1 ms  CRON    заказ #16 → payment_expired, экземпляры [56] освобождены
    │     24.6 ms  CRON    COMMIT → 200 payment_expired {"order_id": 16, "released_items": "56"}
    └─
  PASS   S7.7a    MySQL обнаружил deadlock: ровно одна сессия получила ERROR 1213 [1]
           → тест умеет ловить deadlock; при глобальном порядке (S7.1, S7.2, S7.8) их 0
```

### Стресс (S7.8)

12 заказов, у каждого webhook и pass B стартуют почти одновременно (±30 мс). Встречаются обе ветки: «webhook
первым → noop» и «pass B первым → поздний платёж забирает книгу». Каждый заказ оплачен, каждый экземпляр
продан ровно один раз, deadlock-ов и повторов нет.

```
--- S7.8  стресс: 12 заказов, webhook и pass B стартуют почти одновременно (±30 мс, без пауз)
  PASS   S7.8a    ни одного deadlock / lock wait timeout (24 сессии), повторов не понадобилось [0/0]
  PASS   S7.8b    каждый заказ оплачен, каждый экземпляр продан ровно один раз [12 paid, 12 sold, 12 sales]
           ветки: 6× expire_order:noop, 6× expire_order:payment_expired, 6× webhook:processed, 6× webhook:late_payment_reacquired
```

---

## 10.8 Корзина и порядок блокировок (дополнительно)

### Одна открытая корзина при параллельном создании (S8.1, S8.2)

`UNIQUE(open_cart_user_id)`, где `open_cart_user_id = IF(status IN ('active','checkout_started'), user_id, NULL)`.
В S8.1 три сессии одного пользователя одновременно не находят корзину (рандеву после `SELECT … FOR UPDATE`) и
вставляют её через `INSERT … ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)`. Первая создаёт корзину, двое
ждут `X` на дубликате, затем получают ту же корзину (`ROW_COUNT() = 0`).

```
--- S8.1  одна открытая корзина при параллельном создании (3 сессии, INSERT … ON DUPLICATE KEY UPDATE)
    ┌─ хронология S8.1 (мс от первого шага; порядок — реальный порядок записи в t_trace)
    │      0.0 ms  C1      открытой корзины нет (FOR UPDATE ничего не нашёл) → INSERT … ON DUPLICATE KEY UPDATE
    │      0.7 ms  C2      открытой корзины нет (FOR UPDATE ничего не нашёл) → INSERT … ON DUPLICATE KEY UPDATE
    │      1.1 ms  C3      открытой корзины нет (FOR UPDATE ничего не нашёл) → INSERT … ON DUPLICATE KEY UPDATE
    │      2.5 ms  C3      все дошли до cart.after_select (C1,C2,C3)
    │      3.5 ms  C3      создал корзину #84 (ODKU: 1 строка)
    │      4.5 ms  C1      все дошли до cart.after_select (C1,C2,C3)
    │      5.1 ms  C2      все дошли до cart.after_select (C1,C2,C3)
    │      6.3 ms  C3      ПАУЗА в cart.created: держу блокировки, жду ожидающих: 2
    │      9.8 ms  C3      ВИЖУ ОЖИДАНИЕ: C1 ждёт X wp_book_carts.uq_carts_one_open_per_user (1801, 84); C2 ждёт X wp_book_carts.uq_carts_one_open_per_user (1801, 84)
    │     11.3 ms  C1      корзину #84 уже создала другая сессия (ODKU: 0 строк), X-lock получен
    │     12.1 ms  C3      COMMIT → 200 ok {"cart_id": 84}
    │     13.3 ms  C2      корзину #84 уже создала другая сессия (ODKU: 0 строк), X-lock получен
    │     13.9 ms  C1      COMMIT → 200 ok {"cart_id": 84}
    │     16.6 ms  C2      COMMIT → 200 ok {"cart_id": 84}
    └─
  PASS   S8.1a    все три не нашли корзину и вставляли одновременно; ожидающих двое [1]
  PASS   S8.1b    у пользователя одна корзина, все три сессии получили её id [1/1/3]
  PASS   S8.1c    без deadlock [0]
```

S8.2 — контрольный, отвергнутый вариант `INSERT → 1062 → SELECT … FOR UPDATE`. После 1062 каждый проигравший
держит **S** на дубликате и просит **X**, поэтому при трёх сессиях deadlock неизбежен. Именно поэтому в
`ReservationService::lockOrCreateOpenCart()` используется ODKU.

```
--- S8.2  контроль: «INSERT → 1062 → SELECT FOR UPDATE» (отвергнутый вариант) на 3 сессиях
    ┌─ хронология S8.2 (мс от первого шага; порядок — реальный порядок записи в t_trace)
    │      0.0 ms  N2      все дошли до ncart.after_select (N1,N2,N3)
    │      1.6 ms  N2      INSERT корзины #87
    │      3.2 ms  N1      все дошли до ncart.after_select (N1,N2,N3)
    │      3.7 ms  N3      все дошли до ncart.after_select (N1,N2,N3)
    │      4.1 ms  N2      ПАУЗА в ncart.created: держу блокировки, жду ожидающих: 2
    │      8.1 ms  N2      ВИЖУ ОЖИДАНИЕ: N1 ждёт S wp_book_carts.uq_carts_one_open_per_user (1802, 87); N3 ждёт S wp_book_carts.uq_carts_one_open_per_user (1802, 87)
    │      9.9 ms  N1      INSERT → 1062 (держу S-lock на дубликате), читаю FOR UPDATE (нужен X)
    │     10.6 ms  N3      INSERT → 1062 (держу S-lock на дубликате), читаю FOR UPDATE (нужен X)
    │     11.3 ms  N2      COMMIT → 200 ok {"cart_id": 87}
    │     13.1 ms  N1      COMMIT → 200 ok {"cart_id": 87}
    │     14.0 ms  N3      ROLLBACK → 503 uniundata_conflict_retry (errno 1213: Deadlock found when trying to get lock; try restarting transaction)
    └─
  PASS   S8.2a    после 1062 проигравшие держат S-lock и просят X → deadlock воспроизведён (поэтому в коде ODKU) [1]
  PASS   S8.2b    корзина всё равно одна (UNIQUE) [1]
```

### Одну книгу нельзя добавить дважды (S8.3)

Повторное «Отложить» своей книги отвечает `200 existing`, позиция остаётся одна. На уровне БД вторую
активную позицию той же книги не вставить даже в ту же корзину (`uq_cart_items_one_active_per_item`). После
удаления повторное добавление разрешено (S4.1e), потому что старая строка уже не `active`.

```
--- S8.3  одну книгу нельзя добавить дважды
  PASS   S8.3a    повторное «Отложить» своей книги — 200 existing [200 existing]
  PASS   S8.3b    одна активная позиция [1]
  PASS   S8.3c    вторая активная позиция той же книги даже в той же корзине (uq_cart_items_one_active_per_item)
           MySQL: ERROR 1062 (23000): Duplicate entry '69' for key 'wp_book_cart_items.uq_cart_items_one_active_per_item'
```

### reserve и checkout одного пользователя параллельно (S8.4–S8.7)

Обе операции начинают с `FOR UPDATE` открытой корзины пользователя (уровень 1). Пока одна держит корзину,
вторая ждёт именно на ней и до экземпляров не доходит, поэтому цикла ожидания нет.

| Момент | S8.4: checkout первым | S8.5: reserve первым |
|---|---|---|
| t0 | CO: корзина → экземпляры A, B (asc), пауза | RS: корзина → экземпляр C → резерв, пауза |
| t1 | RS: `FOR UPDATE` корзины — **ждёт** (`uq_carts_one_open_per_user`) | CO: `FOR UPDATE` корзины — **ждёт** |
| t2 | CO COMMIT: корзина `converted_to_order` → RS не находит открытую корзину, создаёт **новую** и резервирует C | RS COMMIT → CO видит A, B, C: сумма 28800 ≠ ожидаемой 19100 → `409 cart_changed`, клиент подтверждает заново |

```
--- S8.4  reserve и checkout одного пользователя параллельно — checkout первым
    ┌─ хронология S8.4 (мс от первого шага; порядок — реальный порядок записи в t_trace)
    │      0.0 ms  CO      BEGIN checkout(user 1804, expected 18500 EUR)
    │      0.9 ms  CO      FOR UPDATE корзины #91 (active)
    │      2.7 ms  CO      FOR UPDATE items #70=reserved, #71=reserved
    │      3.7 ms  CO      ПАУЗА в checkout.after_items: держу блокировки, жду ожидающих: 1
    │      4.9 ms  RS      CO на паузе в checkout.after_items — начинаю
    │      6.9 ms  RS      BEGIN reserve(user 1804, item #72)
    │     12.4 ms  CO      ВИЖУ ОЖИДАНИЕ: RS ждёт X,REC_NOT_GAP wp_book_carts.uq_carts_one_open_per_user (1804, 91)
    │     13.2 ms  CO      FOR UPDATE reservations #86=active, #87=active
    │     13.8 ms  CO      FOR UPDATE cart_items #82=active, #83=active
    │     18.2 ms  CO      заказ #29 draft, экземпляры 70,71 → checkout_pending, резервы → converted_to_order, платёж #28 created
    │     20.8 ms  CO      Tx1 COMMIT; createSession у банка — вне транзакции; Tx2: orders → payments (created → pending)
    │     22.0 ms  RS      открытой корзины нет (FOR UPDATE ничего не нашёл) → INSERT … ON DUPLICATE KEY UPDATE
    │     23.2 ms  RS      создал корзину #92 (ODKU: 1 строка)
    │     25.0 ms  RS      FOR UPDATE экземпляра #72 → available
    │     27.5 ms  CO      COMMIT → 201 created {"items": "70,71", "order_id": 29, "payment_id": 28, "total_amount": 18500, "public_order_id": "uniundata_10d0ecac-85af-4f8d-965a-e8110060fe28", "provider_payment_id": "pp_28"}
    │     29.2 ms  RS      INSERT резерв #88 (попытка 1), экземпляр → reserved, позиция #84
    │     32.2 ms  RS      COMMIT → 201 created {"cart_id": 92, "attempt_no": 1, "expires_at": "2026-10-05 14:44:27.243372", "cart_item_id": 84, "reservation_id": 88}
    └─
  PASS   S8.4a    reserve ждал на корзине (уровень 1), а не на экземплярах [1]
  PASS   S8.4b    checkout 201 (A+B); reserve 201 в НОВОЙ корзине [201 created / 201 created / new_cart]
  PASS   S8.4c    без deadlock [0]

--- S8.5  reserve и checkout одного пользователя параллельно — reserve первым
    ┌─ хронология S8.5 (мс от первого шага; порядок — реальный порядок записи в t_trace)
    │      0.0 ms  RS      BEGIN reserve(user 1805, item #75)
    │      1.4 ms  RS      FOR UPDATE корзины #93 (active)
    │      2.8 ms  RS      FOR UPDATE экземпляра #75 → available
    │      7.1 ms  RS      INSERT резерв #91 (попытка 1), экземпляр → reserved, позиция #87
    │      8.5 ms  RS      ПАУЗА в reserve.before_commit: держу блокировки, жду ожидающих: 1
    │     10.2 ms  CO      RS на паузе в reserve.before_commit — начинаю
    │     12.5 ms  CO      BEGIN checkout(user 1805, expected 19100 EUR)
    │     17.9 ms  RS      ВИЖУ ОЖИДАНИЕ: CO ждёт X,REC_NOT_GAP wp_book_carts.uq_carts_one_open_per_user (1805, 93)
    │     20.7 ms  CO      FOR UPDATE корзины #93 (active)
    │     22.1 ms  RS      COMMIT → 201 created {"cart_id": 93, "attempt_no": 1, "expires_at": "2026-10-05 14:44:27.392435", "cart_item_id": 87, "reservation_id": 91}
    │     23.3 ms  CO      FOR UPDATE items #73=reserved, #74=reserved, #75=reserved
    │     24.0 ms  CO      FOR UPDATE reservations #89=active, #90=active, #91=active
    │     24.8 ms  CO      FOR UPDATE cart_items #85=active, #86=active, #87=active
    │     27.5 ms  CO      COMMIT → 409 uniundata_cart_changed {"items": "73,74,75", "reason": "total_mismatch", "currency": "EUR", "actual_total_amount": 28800}
    └─
  PASS   S8.5a    checkout ждал корзину; увидел три книги → 409 cart_changed (сумма 28800 ≠ 19100) [201 created / 409 uniundata_cart_changed 28800]
  PASS   S8.5b    без deadlock [0]
```

S8.6 — контроль: «remove-item» с нарушенным порядком (`items → carts`) против checkout (`carts → items`)
даёт deadlock.

```
--- S8.6  контроль: remove-item с НЕПРАВИЛЬНЫМ порядком (items → carts) против checkout (carts → items) = deadlock
    ┌─ хронология S8.6 (мс от первого шага; порядок — реальный порядок записи в t_trace)
    │      0.0 ms  BAD     FOR UPDATE items #76 (НАРУШЕН глобальный порядок)
    │      1.1 ms  BAD     ПАУЗА в pair.after_first: держу блокировки, жду ожидающих: 1
    │      6.0 ms  CO      BAD на паузе в pair.after_first — начинаю
    │     10.4 ms  CO      BEGIN checkout(user 1806, expected 19700 EUR)
    │     11.4 ms  CO      FOR UPDATE корзины #94 (active)
    │     15.8 ms  BAD     ВИЖУ ОЖИДАНИЕ: CO ждёт X,REC_NOT_GAP wp_book_items.PRIMARY (76)
    │     17.5 ms  CO      FOR UPDATE items #76=reserved, #77=reserved
    │     18.8 ms  CO      FOR UPDATE reservations #92=active, #93=active
    │     19.7 ms  BAD     ROLLBACK → 503 uniundata_conflict_retry (errno 1213: Deadlock found when trying to get lock; try restarting transaction)
    │     20.2 ms  CO      FOR UPDATE cart_items #88=active, #89=active
    │     24.2 ms  CO      заказ #30 draft, экземпляры 76,77 → checkout_pending, резервы → converted_to_order, платёж #29 created
    │     26.4 ms  CO      Tx1 COMMIT; createSession у банка — вне транзакции; Tx2: orders → payments (created → pending)
    │     30.3 ms  CO      COMMIT → 201 created {"items": "76,77", "order_id": 30, "payment_id": 29, "total_amount": 19700, "public_order_id": "uniundata_ede5d388-be60-416f-8ce7-e800b34d7350", "provider_payment_id": "pp_29"}
    └─
  PASS   S8.6a    MySQL обнаружил deadlock (ровно один 1213) [1]
```

S8.7 — стресс: 3 раунда × 12 пользователей, у каждого checkout(A+B) и reserve(C) стартуют одновременно
(72 сессии), с повтором как в `Db::transaction()`. Все операции завершились ожидаемыми исходами. Deadlock-и,
которые здесь случаются, **не связаны с порядком блокировок**: каждый из них возникает на `INSERT` новой
корзины (S8.7d), см. 10.10.

```
--- S8.7  стресс: 3 раунда × 12 пользователей, у каждого checkout(A+B) и reserve(C) стартуют одновременно (повтор как Db::transaction)
  PASS   S8.7a    итог: ни одной операции, упавшей по deadlock / lock wait timeout (с повтором) [0]
  PASS   S8.7b    все 36 reserve(C) успешны [36]
  PASS   S8.7c    каждый checkout: заказ A+B (201) или 409 cart_changed (C успел первым) — других исходов нет [36]
  PASS   S8.7d    порядок блокировок не участвует: каждый deadlock (если был) — на INSERT новой корзины (gap-lock проверки UNIQUE) [0]
  XFAIL  S8.7e    без повтора: ни одного deadlock
           ожидалось: 0 повторено; получено: 3 повторено
           EXPECTED-FAIL (дефект схемы): INSERT … ON DUPLICATE KEY UPDATE новой корзины проверяет дубликат по delete-marked записи только что закрытой корзины в uq_carts_one_open_per_user (generated column) и ставит next-key/gap-блокировки даже в READ COMMITTED; две такие вставки соседних user_id ждут insert intention друг друга. Инварианты не нарушаются, Db::transaction() повторяет транзакцию
           последний deadlock InnoDB (SHOW ENGINE INNODB STATUS):
           │ INSERT INTO wp_book_carts (user_id, status, started_at, last_activity_at)
           │ index uq_carts_one_open_per_user of table `uniundata_test`.`wp_book_carts` lock_mode X locks gap before rec
           │ index uq_carts_one_open_per_user of table `uniundata_test`.`wp_book_carts` lock_mode X locks gap before rec insert intention waiting
           │ INSERT INTO wp_book_carts (user_id, status, started_at, last_activity_at)
           │ index uq_carts_one_open_per_user of table `uniundata_test`.`wp_book_carts` lock_mode X locks gap before rec
           │ index uq_carts_one_open_per_user of table `uniundata_test`.`wp_book_carts` lock_mode X locks gap before rec insert intention waiting
           │ *** WE ROLL BACK TRANSACTION (2)
           исходы checkout: 15× created, 21× uniundata_cart_changed
```

### Двойной клик «Перейти к оплате» с тем же `Idempotency-Key` (S8.8)

Вторая сессия ждёт корзину. После COMMIT первой открытой корзины уже нет, и второй запрос находит заказ по
`UNIQUE(user_id, checkout_request_id)`: `200 replayed` с тем же `order_id`. Заказ в БД один.

```
--- S8.8  двойной клик «Перейти к оплате» с тем же Idempotency-Key
    ┌─ хронология S8.8 (мс от первого шага; порядок — реальный порядок записи в t_trace)
    │      0.0 ms  CO1     BEGIN checkout(user 1807, expected 2222 EUR)
    │      1.4 ms  CO1     FOR UPDATE корзины #149 (active)
    │      4.1 ms  CO1     FOR UPDATE items #186=reserved
    │      5.3 ms  CO1     FOR UPDATE reservations #202=active
    │      6.5 ms  CO1     FOR UPDATE cart_items #198=active
    │     12.8 ms  CO1     заказ #46 draft, экземпляры 186 → checkout_pending, резервы → converted_to_order, платёж #45 created
    │     13.7 ms  CO1     ПАУЗА в checkout.before_commit: держу блокировки, жду ожидающих: 1
    │     16.7 ms  CO2     CO1 на паузе в checkout.before_commit — начинаю
    │     19.6 ms  CO2     BEGIN checkout(user 1807, expected 2222 EUR)
    │     23.5 ms  CO1     ВИЖУ ОЖИДАНИЕ: CO2 ждёт X,REC_NOT_GAP wp_book_carts.uq_carts_one_open_per_user (1807, 149)
    │     25.9 ms  CO1     Tx1 COMMIT; createSession у банка — вне транзакции; Tx2: orders → payments (created → pending)
    │     27.1 ms  CO2     открытой корзины нет
    │     30.0 ms  CO2     COMMIT → 200 replayed {"order_id": 46}
    │     31.0 ms  CO1     COMMIT → 201 created {"items": "186", "order_id": 46, "payment_id": 45, "total_amount": 2222, "public_order_id": "uniundata_267a403e-72bc-45b3-afd4-7bf0a94a9ed1", "provider_payment_id": "pp_45"}
    └─
  PASS   S8.8a    первый 201, второй 200 replayed с тем же заказом [201 created / 200 replayed #46]
  PASS   S8.8b    в БД один заказ [1]
```

---

## 10.9 Гарантии схемы (S0)

Каждое ограничение проверено отдельным SQL, который обязан упасть с конкретной ошибкой. Ниже — вывод MySQL.

```
S0  Гарантии схемы: CHECK / UNIQUE / FK / generated columns / индексы
======================================================================================
--- экземпляры
  PASS   S0.01    sold без sold_at невозможен (ck_items_sold_at)
           MySQL: ERROR 3819 (HY000): Check constraint 'ck_items_sold_at' is violated.
  PASS   S0.02    неизвестный статус экземпляра (ck_items_status)
           MySQL: ERROR 3819 (HY000): Check constraint 'ck_items_status' is violated.
  PASS   S0.03    внешний book_id = один экземпляр (uq_items_external)
           MySQL: ERROR 1062 (23000): Duplicate entry 'primary-BASE-01' for key 'wp_book_items.uq_items_external'
  XFAIL  S0.04    валюта только A–Z (ck_items_currency): 'eur' должна отклоняться
           ожидалось: ERROR 3819; получено: eur
           EXPECTED-FAIL (дефект схемы): колонка CHARACTER SET ascii (ascii_general_ci) — REGEXP '^[A-Z]{3}$' регистронезависим; нужно REGEXP_LIKE(currency, '^[A-Z]{3}$', 'c') или COLLATE ascii_bin
--- резервы
  PASS   S0.05    второй active-резерв на экземпляр (uq_reservations_one_active_per_item)
           MySQL: ERROR 1062 (23000): Duplicate entry '2' for key 'wp_book_reservations.uq_reservations_one_active_per_item'
  PASS   S0.06    неактивных резервов на экземпляр — сколько угодно (generated column = NULL) [2 строк, active_book_item_id IS NULL: 2]
  PASS   S0.07    4-я попытка (ck_reservations_attempt_range)
           MySQL: ERROR 3819 (HY000): Check constraint 'ck_reservations_attempt_range' is violated.
  PASS   S0.08    повтор номера попытки (uq_reservations_attempt)
           MySQL: ERROR 1062 (23000): Duplicate entry '3-2-1' for key 'wp_book_reservations.uq_reservations_attempt'
  PASS   S0.09    released_by_admin обязан иметь attempt_no = NULL (ck_reservations_attempt_admin)
           MySQL: ERROR 3819 (HY000): Check constraint 'ck_reservations_attempt_admin' is violated.
  PASS   S0.10    не-admin резерв обязан иметь номер попытки (ck_reservations_attempt_admin)
           MySQL: ERROR 3819 (HY000): Check constraint 'ck_reservations_attempt_admin' is violated.
  PASS   S0.11    active ⇔ released_at IS NULL (ck_reservations_released)
           MySQL: ERROR 3819 (HY000): Check constraint 'ck_reservations_released' is violated.
  PASS   S0.12    converted_to_order без order_id (ck_reservations_order)
           MySQL: ERROR 3819 (HY000): Check constraint 'ck_reservations_order' is violated.
  PASS   S0.13    резерв несуществующего экземпляра (fk_reservations_item)
           MySQL: ERROR 1452 (23000): Cannot add or update a child row: a foreign key constraint fails (`uniundata_test`.`wp_book_reservations`, CONSTRAINT `fk_reservations_item` FOREIGN KEY (`book_item_id`) REFERENCES `wp_book_items` (`id`))
--- корзины
  PASS   S0.14    вторая открытая корзина пользователя (uq_carts_one_open_per_user)
           MySQL: ERROR 1062 (23000): Duplicate entry '77' for key 'wp_book_carts.uq_carts_one_open_per_user'
  PASS   S0.15    закрытых корзин у пользователя — сколько угодно [3]
  PASS   S0.16    книга — активная позиция только одной корзины (uq_cart_items_one_active_per_item)
           MySQL: ERROR 1062 (23000): Duplicate entry '4' for key 'wp_book_cart_items.uq_cart_items_one_active_per_item'
  PASS   S0.17    одна позиция на резерв (uq_cart_items_reservation)
           MySQL: ERROR 1062 (23000): Duplicate entry '3' for key 'wp_book_cart_items.uq_cart_items_reservation'
--- заказы, платежи, события
  PASS   S0.18    public_order_id только uniundata_<uuid v4> (ck_orders_public_id)
           MySQL: ERROR 3819 (HY000): Check constraint 'ck_orders_public_id' is violated.
  PASS   S0.19    total = subtotal − discount + shipping (ck_orders_total)
           MySQL: ERROR 3819 (HY000): Check constraint 'ck_orders_total' is violated.
  PASS   S0.20    paid без paid_at (ck_orders_paid_at)
           MySQL: ERROR 3819 (HY000): Check constraint 'ck_orders_paid_at' is violated.
  PASS   S0.21    один заказ на Idempotency-Key пользователя (uq_orders_checkout_request)
           MySQL: ERROR 1062 (23000): Duplicate entry '77-f00dfeed-0000-4000-8000-000000000001' for key 'wp_book_orders.uq_orders_checkout_request'
  PASS   S0.22    в платеже только 4 последние цифры карты (ck_payments_last4)
           MySQL: ERROR 3819 (HY000): Check constraint 'ck_payments_last4' is violated.
  PASS   S0.23    одно событие банка — одна строка inbox (uq_payment_events_provider)
           MySQL: ERROR 1062 (23000): Duplicate entry 'testbank-S0-evt' for key 'wp_book_payment_events.uq_payment_events_provider'
```

---

## 10.10 Найденные проблемы схемы (EXPECTED-FAIL)

### 1. `CHECK (currency REGEXP '^[A-Z]{3}$')` не отклоняет строчные буквы (S0.04)

Колонки `currency` объявлены `CHARACTER SET ascii`, и их коллация по умолчанию — `ascii_general_ci`.
`REGEXP` в MySQL 8 (ICU) учитывает регистронезависимость коллации, поэтому `'eur'` проходит CHECK:

```sql
SELECT 'eur' REGEXP '^[A-Z]{3}$';                                                 -- 1
SELECT REGEXP_LIKE(_ascii'eur' COLLATE ascii_general_ci, '^[A-Z]{3}$', 'c');     -- 0
```

Это касается `ck_items_currency`, `ck_cart_items_currency`, `ck_orders_currency`, `ck_order_items_currency`,
`ck_payments_currency` и `ck_sales_currency`. По той же причине `ck_orders_public_id` пропускает
`UNIUNDATA_ABCDEF01-…` (проверено вставкой), а IN-списки статусов (`ck_items_status` и др.) — `'AVAILABLE'`.
SQL считает `'AVAILABLE' = 'available'`, а PHP (`=== 'available'`) — нет. PHP сейчас нормализует валюту
(`strtoupper` в `lockItem()`), но второй рубеж не работает. Исправление:
`CHECK (REGEXP_LIKE(currency, '^[A-Z]{3}$', 'c'))` (то же для `public_order_id`) или `COLLATE ascii_bin` у
колонок-кодов и статусов.

### 2. Вероятностный deadlock при создании корзин разными пользователями (S8.7e)

**Симптом.** В стрессе S8.7 (checkout закрывает корзину пользователя, и в те же миллисекунды reserve того же
пользователя создаёт новую — так одновременно у соседних `user_id`) InnoDB возвращает `ERROR 1213` на
`INSERT … ON DUPLICATE KEY UPDATE` в `wp_book_carts`. Отчёт `SHOW ENGINE INNODB STATUS` из прогона (сокращён:
«…» — пропущенные служебные поля, «←» — расшифровка hex) показывает, что обе
транзакции держат `X locks gap before rec` на **одной и той же** записи `uq_carts_one_open_per_user` (открытая
корзина следующего по `user_id` пользователя) и обе ждут `insert intention` в этот промежуток.

```
LATEST DETECTED DEADLOCK
*** (1) TRANSACTION:  INSERT INTO wp_book_carts (user_id, status, started_at, last_activity_at) … ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)
*** (1) HOLDS THE LOCK(S):
RECORD LOCKS … index uq_carts_one_open_per_user of table `uniundata_test`.`wp_book_carts` … lock_mode X locks gap before rec
 0: len 8; hex 00000000000007d5;   ← open_cart_user_id = 2005
 1: len 8; hex 000000000000002d;   ← id = 45
*** (1) WAITING FOR THIS LOCK TO BE GRANTED:
RECORD LOCKS … index uq_carts_one_open_per_user … lock_mode X locks gap before rec insert intention waiting
*** (2) TRANSACTION:  INSERT INTO wp_book_carts … ON DUPLICATE KEY UPDATE …
*** (2) HOLDS THE LOCK(S):        … uq_carts_one_open_per_user … lock_mode X locks gap before rec   (та же запись 2005/45)
*** (2) WAITING FOR THIS LOCK TO BE GRANTED: … lock_mode X locks gap before rec insert intention waiting
*** WE ROLL BACK TRANSACTION (2)
```

**Причина.** Это не порядок блокировок: обе транзакции находятся на одном уровне (вставка корзины) и ничего
другого не держат. При закрытии корзины generated column `open_cart_user_id` становится `NULL`, и запись
`(user_id, id)` в UNIQUE-индексе остаётся delete-marked до purge. Новая открытая корзина того же
пользователя вставляет **тот же ключ**. InnoDB выполняет проверку дубликата в UNIQUE-вторичном индексе с
next-key/gap-блокировками **даже в READ COMMITTED** (в документации MySQL gap-блокировки в RC сохранены
именно для duplicate-key checking и FK). Две такие вставки в один промежуток индекса ждут друг друга.

**Последствия.** Инварианты не нарушаются: проигравшая транзакция откатывается целиком (S9: 0 нарушений), а
`Db::transaction()` по контракту повторяет её до трёх раз с джиттером 50–200 мс. Пользователь получает
обычный ответ с задержкой в доли секунды. Но утверждение «порядок блокировок исключает deadlock» для этой
операции неверно: deadlock-и редкие, но реальные, их нужно учитывать в мониторинге (`Innodb_deadlocks`) и не
считать багом кода.

**Измерение на 8.0.46** (тот же стресс S8.7, 3 × 12 пар):

| Вариант | Прогонов с deadlock | Повторов всего |
|---|---|---|
| Текущая схема (`uq_carts_one_open_per_user` на generated column) | 5 из 9 | 1–3 за прогон |
| Эксперимент: слот корзины по PRIMARY KEY (`wp_book_cart_slots(user_id PK, open_cart_id UNIQUE)`, без UNIQUE на `open_cart_user_id`) | 0 из 10 | 0 |

**Варианты решения** (решение за владельцем схемы; `sql/schema.sql` тестом не менялся):

1. Оставить как есть: deadlock безопасен благодаря повтору `Db::transaction()`. В docs/08 уточнить, что
   глобальный порядок исключает deadlock-и **порядка**, а gap-блокировки UNIQUE-индексов при вставке —
   нет; добавить метрику повторов.
2. Гарантировать «одна открытая корзина» строкой-слотом пользователя по PRIMARY KEY: она создаётся один раз и
   потом только обновляется, а новые корзины не переиспользуют ключ UNIQUE-индекса. В эксперименте этот
   вариант не дал ни одного deadlock-а.

Тот же механизм теоретически возможен у `uq_reservations_one_active_per_item` и
`uq_cart_items_one_active_per_item`: ключ `book_item_id` переиспользуется при повторном резерве.
Специальный стресс S1.5 (12 только что освобождённых соседних экземпляров) в наших прогонах deadlock-ов не
дал, но проверка оставлена как вероятностная (`INFO`/`XFAIL`).

---

## 10.11 Глобальные инварианты после всех сценариев (S9)

[`tests/mysql/invariants.sql`](../tests/mysql/invariants.sql) — представление `t_invariants`. После всех
сценариев, включая стрессы и контрольные deadlock-и, каждый инвариант даёт 0 нарушений. Эти же запросы
годятся для ежедневной проверки рабочей БД.

```
S9  Глобальные инварианты данных после всех сценариев (tests/mysql/invariants.sql)
======================================================================================
  PASS   I01      reserved-экземпляр имеет ровно один active-резерв [0]
  PASS   I02      active-резерв только у reserved-экземпляра [0]
  PASS   I03      active-резерв ⇔ active-позиция корзины [0]
  PASS   I04      active-позиция лежит в открытой корзине владельца резерва [0]
  PASS   I05      sold-экземпляр имеет ровно одну продажу [0]
  PASS   I06      продажа только у sold-экземпляра [0]
  PASS   I07      checkout_pending-экземпляр в ровно одном открытом заказе [0]
  PASS   I08      у открытого заказа все экземпляры checkout_pending [0]
  PASS   I09      попыток (attempt_no IS NOT NULL) на пару пользователь+экземпляр не больше 3 [0]
  PASS   I10      оплаченный заказ имеет продажи или needs_attention [0]
  PASS   I11      продажа только по оплаченному заказу и успешному платежу этого заказа [0]
  PASS   I12      цена продажи = снимок цены в заказе [0]
  PASS   I13      больше одного успешного платежа ⇒ needs_attention [0]
  PASS   I14      converted_to_order-резерв ссылается на заказ с этим экземпляром [0]
  PASS   I15      webhook-событие processed не более одного раза (UNIQUE provider+event_id) [0]
  PASS   I16      сумма заказа = сумма снимков позиций [0]
  PASS   S9.dl    неповторённых deadlock (1213) вне контрольных сценариев S7.7, S8.2, S8.6 — ни одного [0]
           повторённых (как Db::transaction) deadlock-ов за прогон: S8.7: 3
  PASS   S9.lw    lock wait timeout (1205) — ни одного [0]
--- индексы точечных запросов алгоритмов (EXPLAIN на реальных строках после сценариев)
  PASS   S9.ix1   открытая корзина: WHERE open_cart_user_id = ? → uq_carts_one_open_per_user [uq_carts_one_open_per_user]
  PASS   S9.ix2   active-резерв: WHERE active_book_item_id = ? → uq_reservations_one_active_per_item [uq_reservations_one_active_per_item]
  PASS   S9.ix3   webhook: платёж по (provider, provider_payment_id) → uq_payments_provider_id [uq_payments_provider_id]
  PASS   S9.ix4   продажа экземпляра: WHERE book_item_id = ? → uq_sales_book_item [uq_sales_book_item]
  PASS   S9.ix5   платежи заказа FOR UPDATE: WHERE order_id = ? → uq_payments_attempt [uq_payments_attempt]
```
