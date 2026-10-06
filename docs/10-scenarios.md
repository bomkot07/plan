# 10. Сценарии из ТЗ на реальном MySQL 8.0

Все сценарии из пункта 11 ТЗ воспроизведены на настоящем сервере MySQL параллельными сессиями. Ниже —
**фактический вывод** `tests/mysql/run.sh`, а не иллюстрация. Последний прогон:

```text
MySQL 8.0.46-0ubuntu0.24.04.4, тестовая БД 'uniundata_test', схема sql/schema.sql
ИТОГ: PASS 229, FAIL 0, EXPECTED-FAIL 0
```

Проверка S8.7e вероятностная: это осознанно принятый редкий deadlock при создании корзины (§ 10.10). В одних
прогонах он проявляется и отмечается как EXPECTED-FAIL (повтор транзакции его устраняет), в других — как INFO
«не проявилось». FAIL не возникает ни в каком случае: инварианты не нарушаются.

**Как устроен тест.** `algorithms.sql` содержит SQL-эквиваленты PHP-алгоритмов: те же `SELECT … FOR UPDATE`, тот же
порядок блокировок, READ COMMITTED, те же условные `UPDATE`. Пересечение транзакций гарантируется без подбора
`SLEEP`. Сессия останавливается в точке `tp('<имя>')`, держит свои блокировки и ждёт, пока
`performance_schema.data_lock_waits` покажет, что вторая сессия ждёт **именно её**. Только после этого первая
сессия продолжает работу. Строка «ВИЖУ ОЖИДАНИЕ» в хронологии — это и есть доказательство пересечения. Подробности
и запуск отдельных групп — [tests/mysql/README.md](../tests/mysql/README.md).

```bash
tests/mysql/run.sh            # всё, около 30 с
tests/mysql/run.sh S5 S7      # только группы S5 и S7 (+ инварианты S9)
```

| Сценарий ТЗ | Раздел | Тесты |
|---|---|---|
| Два пользователя одновременно нажимают «Отложить» | [10.1](#101-два-пользователя-одновременно-нажимают-отложить) | S1.1–S1.5 |
| Пользователь не оплатил за час | [10.2](#102-пользователь-не-оплатил-за-час) | S2.1–S2.6 |
| Три резерва без покупки | [10.3](#103-пользователь-три-раза-резервировал-и-не-купил) | S3.1–S3.5 |
| Пользователь удалил книгу из корзины | [10.4](#104-пользователь-удалил-книгу-из-корзины) | S4.1–S4.3 |
| Оплата прошла, webhook пришёл дважды | [10.5](#105-оплата-прошла-webhook-пришёл-дважды) | S5.1–S5.5 |
| Синхронизация получила проданную книгу | [10.6](#106-синхронизация-получила-ранее-проданную-книгу-повторно) | S6.1–S6.7 |
| Callback одновременно со снятием истёкших резервов | [10.7](#107-callback-банка-одновременно-с-задачей-снятия-истёкших-резервов-и-заказов) | S7.1–S7.9 |

## 10.1 Два пользователя одновременно нажимают «Отложить»

U1 берёт `FOR UPDATE` строки экземпляра и останавливается, держа блокировку. U2 упирается в ту же строку и ждёт.
После `COMMIT` U1 сессия U2 читает уже `reserved` и получает 409. Транзакция U2 откатывается целиком, поэтому
попытка не тратится, а созданная было корзина исчезает.

```text
--- S1.1  reserve с SELECT … FOR UPDATE: U2 ждёт блокировку строки экземпляра и видит reserved
    ┌─ хронология S1.1 (мс от первого шага; порядок — реальный порядок записи в t_trace)
    │      0.0 ms  U1      BEGIN reserve(user 1101, item #14)
    │      0.7 ms  U1      открытой корзины нет (FOR UPDATE ничего не нашёл) → INSERT … ON DUPLICATE KEY UPDATE
    │      1.5 ms  U1      создал корзину #6 (ODKU: 1 строка)
    │      3.5 ms  U1      FOR UPDATE экземпляра #14 → available
    │      4.2 ms  U1      ПАУЗА в reserve.after_item: держу блокировки, жду ожидающих: 1
    │      6.3 ms  U2      U1 на паузе в reserve.after_item — начинаю
    │      7.9 ms  U2      BEGIN reserve(user 1102, item #14)
    │      8.6 ms  U2      открытой корзины нет (FOR UPDATE ничего не нашёл) → INSERT … ON DUPLICATE KEY UPDATE
    │      9.3 ms  U2      создал корзину #7 (ODKU: 1 строка)
    │     11.9 ms  U1      ВИЖУ ОЖИДАНИЕ: U2 ждёт X,REC_NOT_GAP wp_book_items.PRIMARY (14)
    │     13.6 ms  U1      INSERT резерв #7 (попытка 1), экземпляр → reserved, позиция #4
    │     14.9 ms  U2      FOR UPDATE экземпляра #14 → reserved
    │     15.9 ms  U1      COMMIT → 201 created {"cart_id": 6, "attempt_no": 1, "expires_at": "2026-10-06 05:01:51.625907", "cart_item_id": 4, "reservation_id": 7}
    │     17.6 ms  U2      ROLLBACK → 409 uniundata_item_unavailable (errno 1644: uniundata_item_unavailable) {"book_item_id": 14, "availability_status": "reserved"}
    └─
  PASS   S1.1a    U1 получил резерв [201 created]
  PASS   S1.1b    U2 — 409 uniundata_item_unavailable [409 uniundata_item_unavailable]
  PASS   S1.1c    U2 после ожидания прочитал availability_status [reserved]
  PASS   S1.1d    U2 действительно ждал X-lock строки wp_book_items (data_lock_waits) [1]
  PASS   S1.1e    ровно один active-резерв на экземпляр, владелец U1 [1 / user 1101]
  PASS   S1.1f    у U2 попытка не потрачена и корзина не создана (откат целиком) [0/0]
  PASS   S1.1g    экземпляр reserved [reserved]
```

Второй рубеж (S1.2): «наивный» код без `FOR UPDATE` всё равно получает `1062` по
`uq_reservations_one_active_per_item`. S1.3 — двойной клик одного пользователя идемпотентен. S1.4–S1.5 — стресс без пауз.

```text
--- S1.2  без FOR UPDATE (наивный код): второй рубеж — UNIQUE uq_reservations_one_active_per_item
  PASS   S1.2a    U1 создал резерв [201 created]
  PASS   S1.2b    U2 прочитал available обычным SELECT (гонка воспроизведена) [1]
  PASS   S1.2c    U2 получил ERROR 1062 и откат [1062]
  PASS   S1.2d    1062 именно по uq_reservations_one_active_per_item → 409 item_unavailable [409 uniundata_item_unavailable | Duplicate entry '15' for key 'wp_book_reservations.uq_reservations_one_active_per_item']
  PASS   S1.2e    в БД один active-резерв [1]
```

```text
--- S1.3  один пользователь, двойной клик (две вкладки): идемпотентно, попытка не тратится
  PASS   S1.3a    первый запрос — 201 [201 created]
  PASS   S1.3b    второй — 200 с тем же резервом [200 existing #10]
  PASS   S1.3c    второй ждал на корзине пользователя (1-й уровень порядка блокировок) [1]
  PASS   S1.3d    одна попытка, одна позиция корзины, одна корзина [1/1/1]
```

```text
--- S1.4  стресс: 8 пользователей одновременно (стартовый пистолет), без пауз
  PASS   S1.4a    ровно один 201, семь 409 item_unavailable [1×201 created,7×409 uniundata_item_unavailable]
  PASS   S1.4b    ни одного deadlock / lock wait timeout [0]
  PASS   S1.4c    в БД один active-резерв [1]
```

```text
--- S1.5  стресс: 12 соседних экземпляров только что освобождены, 12 других пользователей резервируют одновременно
  PASS   S1.5a    все 12 резервов созданы (с повтором как Db::transaction) [12]
  PASS   S1.5b    итог: ни одной операции, упавшей по deadlock / lock wait timeout [0]
  INFO   S1.5c    без повтора: ни одного deadlock [0 повторено] — в этом прогоне не проявилось
```

## 10.2 Пользователь не оплатил за час

Pass A (`ReservationExpiryService`) снимает только резервы с истёкшим сроком: на +59 мин он ничего не делает,
на +61 мин освобождает. Если оплата начата, резерв уже `converted_to_order`, и экземпляр держится до
`payment_due_at` (30 мин сессии + 10 мин grace). После этого его освобождает pass B (`OrderExpiryService`), но
только после вопроса банку вне транзакции.

```text
--- S2.1  резерв без checkout: pass A через 59 мин ничего не делает, через 61 мин освобождает
  PASS   S2.1a    +59 мин: резерв ещё active, экземпляр reserved [active/reserved]
    ┌─ хронология S2.1 (мс от первого шага; порядок — реальный порядок записи в t_trace)
    │      0.0 ms  U1      BEGIN reserve(user 1201, item #30)
    │      0.9 ms  U1      открытой корзины нет (FOR UPDATE ничего не нашёл) → INSERT … ON DUPLICATE KEY UPDATE
    │      1.8 ms  U1      создал корзину #43 (ODKU: 1 строка)
    │      3.1 ms  U1      FOR UPDATE экземпляра #30 → available
    │      5.2 ms  U1      INSERT резерв #36 (попытка 1), экземпляр → reserved, позиция #32
    │      7.9 ms  U1      COMMIT → 201 created {"cart_id": 43, "attempt_no": 1, "expires_at": "2026-10-06 05:01:54.216275", "cart_item_id": 32, "reservation_id": 36}
    │     30.7 ms  CRON59  pass A: кандидаты (резерв:корзина:экземпляр) = —
    │     53.3 ms  CRON61  pass A: кандидаты (резерв:корзина:экземпляр) = 36:43:30
    │     55.2 ms  CRON61  BEGIN expire reservation #36
    │     55.8 ms  CRON61  FOR UPDATE корзины #43
    │     56.7 ms  CRON61  FOR UPDATE экземпляра #30 → reserved
    │     63.9 ms  CRON61  резерв #36 active→expired, позиция #32 →expired, экземпляр #30 reserved→available
    │     65.8 ms  CRON61  корзина #43 пуста → expired
    │     68.0 ms  CRON61  COMMIT → 200 expired {"item_status": "available", "reservation_id": 36}
    └─
  PASS   S2.1b    +61 мин: резерв expired (release_reason expired), released_at заполнен [expired/expired/1]
  PASS   S2.1c    позиция корзины expired, корзина закрыта как expired (последняя позиция) [expired/expired]
  PASS   S2.1d    экземпляр снова available [available]
  PASS   S2.1e    другой пользователь сразу может отложить [201 created]
  PASS   S2.1f    попытка U1 засчитана (attempt_no сохранён) [1]
```

```text
--- S2.2  checkout начат, оплаты нет: pass A не трогает, pass B после payment_due_at (40 мин) освобождает
  PASS   S2.2a    заказ создан, pending_payment, экземпляр checkout_pending [pending_payment/checkout_pending]
  PASS   S2.2b    pass A (+61 мин): резерв уже converted_to_order — экземпляр не тронут [checkout_pending]
  PASS   S2.2c    pass B (+39 мин, до payment_due_at): noop [200 noop/pending_payment]
  PASS   S2.2d    pass B (+41 мин): заказ payment_expired [payment_expired]
  PASS   S2.2e    платёж expired, экземпляр available [expired/available]
```

```text
--- S2.3  checkout после истечения часа, но раньше cron: позиция истекает в самом checkout
  PASS   S2.3a    409 uniundata_cart_changed (positions_expired) [409 uniundata_cart_changed positions_expired]
  PASS   S2.3b    заказ не создан, резерв expired (checkout_expired), экземпляр available [0/expired:checkout_expired/available]
```

```text
--- S2.4  гонка: pass A освобождает ↔ другой пользователь в этот момент жмёт «Отложить»
  PASS   S2.4a    U2 ждал X-lock экземпляра, удерживаемый cron [1]
  PASS   S2.4b    после COMMIT cron U2 получил резерв [201 created]
  PASS   S2.4c    старый резерв expired, новый active у U2 [1205:expired,1206:active]
```

```text
--- S2.5  платёж created: банк отказал в сессии → cancelled; PHP-процесс умер после Tx1 → pass B: created → expired
  PASS   S2.5a    createSession упал → 502 uniundata_payment_provider_error [502 uniundata_payment_provider_error]
  PASS   S2.5b    платёж created → cancelled (session_create_failed), заказ payment_failed, экземпляр за заказом до payment_due_at [cancelled:session_create_failed/payment_failed/checkout_pending]
  PASS   S2.5c    процесс умер между Tx1 и Tx2: заказ draft, платёж created, экземпляр checkout_pending [draft/created/checkout_pending]
  PASS   S2.5d    pass B (+41 мин, банк платежа не знает): draft → payment_expired, платёж created → expired, экземпляр available [payment_expired/expired:order_expired/available]
  PASS   S2.5e    заказ payment_failed тоже закрыт; cancelled-платёж не тронут [payment_expired/cancelled/available]
```

```text
--- S2.6  банк отвечает processing: pass B продлевает срок ОДИН раз (orders.payment_due_extended_at)
  PASS   S2.6a    два раннера одновременно: C1 продлил, C2 ждал блокировку и увидел новый срок — noop [200 extended / 200 noop 0]
  PASS   S2.6b    payment_due_at = момент продления + grace (10 мин); заказ payment_processing; флаг payment_processing_overdue; платёж processing [10 мин/payment_processing/payment_processing_overdue/processing]
  PASS   S2.6c    +52 мин, банк всё ещё processing: второго продления нет — заказ payment_expired [200 payment_expired/payment_expired]
  PASS   S2.6d    платёж processing → expired, экземпляр available, продление в аудите ровно 1 раз [expired/available/1]
```

## 10.3 Пользователь три раза резервировал и не купил

Попытки: удаление, истечение, удаление. Четвёртая попытка получает 409 `uniundata_reservation_limit_reached`.
Код без проверки лимита упирается в `CHECK wp_book_reservations_chk_attempt_range` (S3.2). Снятие резерва
администратором попытку возвращает (S3.3, `attempt_no := NULL`). Отдельно проверены лимит одновременных резервов
(S3.4) и запрет книг не в валюте магазина (S3.5).

```text
--- S3.1  1-я: удалил из корзины; 2-я: истекла (pass A); 3-я: удалил; 4-я — 409
    ┌─ хронология S3.1 (мс от первого шага; порядок — реальный порядок записи в t_trace)
    │      0.0 ms  U1      BEGIN reserve(user 1301, item #37)
    │      0.7 ms  U1      открытой корзины нет (FOR UPDATE ничего не нашёл) → INSERT … ON DUPLICATE KEY UPDATE
    │      1.6 ms  U1      создал корзину #52 (ODKU: 1 строка)
    │      2.6 ms  U1      FOR UPDATE экземпляра #37 → available
    │      4.4 ms  U1      INSERT резерв #45 (попытка 1), экземпляр → reserved, позиция #41
    │      7.3 ms  U1      COMMIT → 201 created {"cart_id": 52, "attempt_no": 1, "expires_at": "2026-10-06 05:01:55.188328", "cart_item_id": 41, "reservation_id": 45}
    │      8.2 ms  U1      BEGIN remove-item(user 1301, item #37)
    │      8.8 ms  U1      FOR UPDATE корзины #52 (active)
    │     10.7 ms  U1      резерв #45 active→cancelled, позиция #41 →removed, экземпляр #37 reserved→available
    │     13.4 ms  U1      COMMIT → 200 removed {"cart_id": 52, "item_status": "available", "reservation_id": 45}
    │     14.2 ms  U1      BEGIN reserve(user 1301, item #37)
    │     14.9 ms  U1      FOR UPDATE корзины #52 (active)
    │     15.3 ms  U1      FOR UPDATE экземпляра #37 → available
    │     17.1 ms  U1      INSERT резерв #46 (попытка 2), экземпляр → reserved, позиция #42
    │     19.6 ms  U1      COMMIT → 201 created {"cart_id": 52, "attempt_no": 2, "expires_at": "2026-10-06 05:01:55.201110", "cart_item_id": 42, "reservation_id": 46}
    │     30.3 ms  CRON    pass A: кандидаты (резерв:корзина:экземпляр) = 46:52:37
    │     31.2 ms  CRON    BEGIN expire reservation #46
    │     31.5 ms  CRON    FOR UPDATE корзины #52
    │     32.1 ms  CRON    FOR UPDATE экземпляра #37 → reserved
    │     33.9 ms  CRON    резерв #46 active→expired, позиция #42 →expired, экземпляр #37 reserved→available
    │     36.3 ms  CRON    корзина #52 пуста → expired
    │     39.1 ms  CRON    COMMIT → 200 expired {"item_status": "available", "reservation_id": 46}
    │     49.7 ms  U1      BEGIN reserve(user 1301, item #37)
    │     50.7 ms  U1      открытой корзины нет (FOR UPDATE ничего не нашёл) → INSERT … ON DUPLICATE KEY UPDATE
    │     52.1 ms  U1      создал корзину #53 (ODKU: 1 строка)
    │     53.6 ms  U1      FOR UPDATE экземпляра #37 → available
    │     56.2 ms  U1      INSERT резерв #47 (попытка 3), экземпляр → reserved, позиция #43
    │     58.8 ms  U1      COMMIT → 201 created {"cart_id": 53, "attempt_no": 3, "expires_at": "2026-10-06 05:01:55.239567", "cart_item_id": 43, "reservation_id": 47}
    │     59.9 ms  U1      BEGIN remove-item(user 1301, item #37)
    │     60.5 ms  U1      FOR UPDATE корзины #53 (active)
    │     63.2 ms  U1      резерв #47 active→cancelled, позиция #43 →removed, экземпляр #37 reserved→available
    │     66.2 ms  U1      COMMIT → 200 removed {"cart_id": 53, "item_status": "available", "reservation_id": 47}
    │     66.8 ms  U1      BEGIN reserve(user 1301, item #37)
    │     67.2 ms  U1      FOR UPDATE корзины #53 (active)
    │     67.6 ms  U1      FOR UPDATE экземпляра #37 → available
    │     68.8 ms  U1      ROLLBACK → 409 uniundata_reservation_limit_reached (errno 1644: uniundata_reservation_limit_reached) {"used": 3, "book_item_id": 37, "max_attempts": 3}
    └─
  PASS   S3.1a    история: попытки 1..3 (cancelled, expired, cancelled) [1:cancelled,2:expired,3:cancelled]
  PASS   S3.1b    4-я попытка — 409 uniundata_reservation_limit_reached (COUNT под блокировкой) [409 uniundata_reservation_limit_reached]
  PASS   S3.1c    data.used = 3 [3]
  PASS   S3.1d    4-я строка не создана, экземпляр available [3/available]
  PASS   S3.1e    лимит персональный: другой пользователь резервирует [201 created attempt 1]
```

```text
--- S3.2  второй рубеж: код без проверки лимита упирается в CHECK wp_book_reservations_chk_attempt_range
  PASS   S3.2a    наивный INSERT attempt_no = 4 → ERROR 3819 → 409 limit_reached [3819 409 uniundata_reservation_limit_reached]
  PASS   S3.2b    в БД по-прежнему 3 попытки [3]
```

```text
--- S3.3  admin-release возвращает попытку: attempt_no := NULL
  PASS   S3.3a    менеджер снял 3-й резерв [200 released]
  PASS   S3.3b    резерв released_by_admin, attempt_no = NULL [released_by_admin/NULL]
  PASS   S3.3c    пользователь снова может отложить — это попытка 3 [201 created attempt 3]
  PASS   S3.3d    после трёх засчитанных попыток — 409 [409 uniundata_reservation_limit_reached]
  PASS   S3.3e    история попыток [1:cancelled,2:cancelled,NULL:released_by_admin,3:cancelled]
```

```text
--- S3.4  лимит одновременно отложенных книг (uniundata_max_active_reservations = 10): COUNT под блокировкой корзины
  PASS   S3.4a    9 книг уже отложено; 10-я (вкладка 1) — 201 [201 created]
  PASS   S3.4b    11-я (вкладка 2) ждала корзину пользователя [1]
  PASS   S3.4c    и после COMMIT вкладки 1 получила 409 uniundata_active_reservation_limit [409 uniundata_active_reservation_limit max=10]
  PASS   S3.4d    удалил одну книгу — 11-я резервируется [201 created]
```

```text
--- S3.5  экземпляр не в валюте магазина (option uniundata_currency = RUB) не резервируется
  PASS   S3.5a    409 uniundata_item_unavailable, data.reason = currency [409 uniundata_item_unavailable currency EUR≠RUB]
  PASS   S3.5b    откат целиком: попытка не потрачена, корзина не создана, экземпляр available [0/0/available]
```

## 10.4 Пользователь удалил книгу из корзины

Удаление разрешено: резерв `cancelled`, позиция `removed`, экземпляр сразу `available`, повтор запроса
идемпотентен. В гонке с checkout побеждает тот, кто первым взял `FOR UPDATE` корзины (S4.2, S4.3).

```text
--- S4.1  удаление: резерв cancelled, позиция removed, экземпляр available; повтор идемпотентен
    ┌─ хронология S4.1 (мс от первого шага; порядок — реальный порядок записи в t_trace)
    │      0.0 ms  U1      BEGIN reserve(user 1401, item #51)
    │      0.8 ms  U1      открытой корзины нет (FOR UPDATE ничего не нашёл) → INSERT … ON DUPLICATE KEY UPDATE
    │      1.6 ms  U1      создал корзину #58 (ODKU: 1 строка)
    │      3.1 ms  U1      FOR UPDATE экземпляра #51 → available
    │      5.6 ms  U1      INSERT резерв #64 (попытка 1), экземпляр → reserved, позиция #60
    │      7.8 ms  U1      COMMIT → 201 created {"cart_id": 58, "attempt_no": 1, "expires_at": "2026-10-06 05:01:55.985840", "cart_item_id": 60, "reservation_id": 64}
    │     18.7 ms  U1      BEGIN reserve(user 1401, item #52)
    │     19.4 ms  U1      FOR UPDATE корзины #58 (active)
    │     19.9 ms  U1      FOR UPDATE экземпляра #52 → available
    │     21.2 ms  U1      INSERT резерв #65 (попытка 1), экземпляр → reserved, позиция #61
    │     24.1 ms  U1      COMMIT → 201 created {"cart_id": 58, "attempt_no": 1, "expires_at": "2026-10-06 05:01:56.002388", "cart_item_id": 61, "reservation_id": 65}
    │     25.0 ms  U1      BEGIN remove-item(user 1401, item #51)
    │     25.6 ms  U1      FOR UPDATE корзины #58 (active)
    │     27.3 ms  U1      резерв #64 active→cancelled, позиция #60 →removed, экземпляр #51 reserved→available
    │     30.0 ms  U1      COMMIT → 200 removed {"cart_id": 58, "item_status": "available", "reservation_id": 64}
    └─
  PASS   S4.1a    remove-item → 200 removed, экземпляр available [200 removed available]
  PASS   S4.1b    резерв cancelled (user_removed), позиция removed [cancelled:user_removed/removed]
  PASS   S4.1c    корзина открыта, expires_at = срок оставшейся книги [active/1]
  PASS   S4.1d    повторное удаление — 200 already_removed [200 already_removed]
  PASS   S4.1e    пустая корзина остаётся открытой; повторный резерв той же книги — попытка 2 в той же корзине [active/2/2]
```

```text
--- S4.2  гонка remove ↔ checkout: remove первым → checkout видит изменённую корзину (409)
  PASS   S4.2a    remove — 200 [200 removed]
  PASS   S4.2b    checkout ждал корзину и ответил 409 cart_changed (total_mismatch: 5300 вместо 10500) [409 uniundata_cart_changed total_mismatch 5300]
  PASS   S4.2c    заказа нет; A available, B reserved [0/available/reserved]
```

```text
--- S4.3  гонка remove ↔ checkout: checkout первым → remove получает 409 (книга уже в заказе)
  PASS   S4.3a    checkout — 201 [201 created]
  PASS   S4.3b    remove ждал корзину, затем 409 cart_changed (item_in_order) [409 uniundata_cart_changed item_in_order]
  PASS   S4.3c    экземпляры A и B — checkout_pending [checkout_pending/checkout_pending]
```

## 10.5 Оплата прошла, webhook пришёл дважды

Одно и то же событие приходит одновременно в две сессии. Обе проходят inbox (`INSERT … ON DUPLICATE KEY UPDATE
attempts = attempts + 1`). Вторая ждёт блокировку экземпляра, затем видит событие `processed` и отвечает
`200 duplicate`, ничего не меняя.

```text
--- S5.2  то же событие ОДНОВРЕМЕННО двумя сессиями: второй ждёт блокировку и видит processed
    ┌─ хронология S5.2 (мс от первого шага; порядок — реальный порядок записи в t_trace)
    │      0.0 ms  WH1     webhook S5-2-evt: succeeded 6100 RUB для pp_8
    │      1.5 ms  WH1     inbox: событие #5 новое, processing_status = received
    │      2.7 ms  WH1     FOR UPDATE items #58=checkout_pending
    │      3.3 ms  WH1     ПАУЗА в apply.after_items: держу блокировки, жду ожидающих: 1
    │      5.7 ms  WH2     WH1 на паузе в apply.after_items — начинаю
    │      7.3 ms  WH2     webhook S5-2-evt: succeeded 6100 RUB для pp_8
    │      9.6 ms  WH2     inbox: событие #5 уже было (attempts+1), processing_status = received
    │     17.0 ms  WH1     ВИЖУ ОЖИДАНИЕ: WH2 ждёт X,REC_NOT_GAP wp_book_items.PRIMARY (58)
    │     18.3 ms  WH1     FOR UPDATE заказ #9 (pending_payment), платежи (1), событие #5 (received)
    │     21.0 ms  WH1     платёж (заказ pending_payment): продаём [58], конфликт [], проверить у источника []
    │     26.4 ms  WH2     FOR UPDATE items #58=sold
    │     29.5 ms  WH1     COMMIT → 200 processed {"note": "", "event_id": 5, "order_id": 9, "after_commit": [{"args": {"order_id": 9}, "hook": "uniundata_order_paid"}]}
    │     30.2 ms  WH2     FOR UPDATE заказ #9 (paid), платежи (1), событие #5 (processed)
    │     32.1 ms  WH2     COMMIT → 200 duplicate {"where": "under_lock", "event_id": 5}
    └─
  PASS   S5.2a    оба прошли inbox (received), WH2 ждал X-lock экземпляра [1]
  PASS   S5.2b    WH1 processed, WH2 duplicate (обнаружен под блокировкой события) [200 processed / 200 duplicate under_lock]
  PASS   S5.2c    одна продажа, одна строка события processed (attempts 2), paid в аудите один раз [1/1:processed:2/1]
```

S5.1 — повтор после обработки. S5.3 — два разных события об одном успехе. S5.4 — наивный обработчик без
блокировок останавливается на `UNIQUE uq_sales_book_item`. S5.5 — второй успешный платёж: `duplicate_payment` +
возврат.

```text
--- S5.1  повтор того же события ПОСЛЕ обработки: inbox отвечает duplicate, ничего не меняется
  PASS   S5.1a    первый — processed, второй — duplicate (inbox) [200 processed / 200 duplicate inbox]
  PASS   S5.1b    одна продажа, заказ paid, платёж succeeded, экземпляр sold [1/paid/succeeded/sold]
  PASS   S5.1c    одна строка события: processed, attempts = 2 [1/processed/2]
  PASS   S5.1d    повтор не тронул строки заказа и экземпляра (updated_at не изменился) [2026-10-06 04:01:56.443028|2026-10-06 04:01:56.442029]
  PASS   S5.1e    переход заказа в paid и экземпляра в sold записан в аудит один раз [1/1]
```

```text
--- S5.3  банк прислал ДВА РАЗНЫХ события об одном успехе одновременно
  PASS   S5.3a    A processed; B — ignored (already_succeeded) [200 processed / 200 ignored already_succeeded]
  PASS   S5.3b    одна продажа; события: A processed, B ignored [1/processed,ignored]
```

```text
--- S5.4  без блокировок и inbox (наивный обработчик): второй рубеж — UNIQUE uq_sales_book_item
  PASS   S5.4a    N2 прочитал checkout_pending обычным SELECT (гонка воспроизведена) [1]
  PASS   S5.4b    N2 — ERROR 1062 по uq_sales_book_item [1062 | Duplicate entry '60' for key 'wp_book_sales.uq_sales_book_item']
  PASS   S5.4c    продажа одна [1]
```

```text
--- S5.5  второй УСПЕШНЫЙ платёж по уже оплаченному заказу (две вкладки банка)
  PASS   S5.5a    второй платёж принят (succeeded), заказ помечен duplicate_payment [duplicate_payment/paid:1:duplicate_payment]
  PASS   S5.5b    решение о возврате — строка wp_book_refunds в той же транзакции: второй платёж, полная сумма, requested [6400 RUB duplicate_payment requested pp_S5-5-second key=1]
  PASS   S5.5c    после COMMIT — задачи AS: uniundata_order_needs_attention и uniundata_refund_payment {refund_id} [["uniundata_order_needs_attention", "uniundata_refund_payment"] #3]
  PASS   S5.5d    экземпляр продан один раз [1]
```

## 10.6 Синхронизация получила ранее проданную книгу повторно

Источник снова прислал проданный экземпляр как доступный. `sold` не меняется, цена проданного не трогается,
`items_conflicts` растёт, в `error_log` прогона попадает `conflict_sold`. Повторный прогон того же пакета даёт
тот же результат.

```text
--- S6.1  sold не перетирается, items_conflicts++, цена проданного не меняется
  PASS   S6.1a    исходно экземпляр продан [sold]
    ┌─ хронология S6.1 (мс от первого шага; порядок — реальный порядок записи в t_trace)
    │      0.0 ms  SYNC    GET_LOCK синхронизации получен
    │      4.3 ms  SYNC    COMMIT → 200 started {"run_id": 1}
    │      5.8 ms  SYNC    BEGIN sync A (записи) run #1, 3 шт.
    │      7.0 ms  SYNC    FOR UPDATE records #61=active, #62=active
    │      9.8 ms  SYNC    COMMIT sync A: новых записей 1
    │     11.4 ms  SYNC    BEGIN sync B (экземпляры) run #1, 3 шт.
    │     12.3 ms  SYNC    FOR UPDATE items #62=sold, #63=available
    │     13.3 ms  SYNC    экземпляр #62 S6-SOLD: локально sold/present, источник present → sold/present (КОНФЛИКТ)
    │     14.2 ms  SYNC    экземпляр #63 S6-AVAIL: локально available/present, источник present → available/present
    │     16.6 ms  SYNC    COMMIT → 200 applied {"created": 1, "skipped": 0, "updated": 2, "conflicts": 1, "withdrawn": 0}
    └─
  PASS   S6.1b    проданный: sold, sold_at сохранён, цена прежняя (7000), source_status present [sold/1/7000/present]
  PASS   S6.1c    обычный экземпляр обновлён (цена 7500), новый создан [7500/available]
  PASS   S6.1d    журнал прогона: conflicts=1, created=1, updated=2, ошибка conflict_sold (errors_count не растёт) [conflicts=1 created=1 updated=2 errors=0 conflict_sold status=succeeded]
  PASS   S6.1e    продажа не тронута [1]
  PASS   S6.1f    повторный прогон того же пакета: sold на месте, конфликт снова посчитан, остальное skipped [sold conflicts=1 skipped=3 created=0]
  PASS   S6.1g    экземпляр в EUR не импортирован (currency_mismatch, errors_count = 1), в RUB — создан [0/1 errors=1 currency_mismatch]
```

S6.2–S6.3 — синхронизация одновременно с «Отложить». S6.4 — два запуска синхронизации. S6.5–S6.6 — синхронизация
одновременно с checkout: транзакция по записям и транзакция по экземплярам разделены, поэтому deadlock-а нет.
S6.7 — контроль: та же работа **одной** транзакцией даёт deadlock, то есть тест действительно его ловит.

```text
--- S6.2  синхронизация одновременно с «Отложить»: источник снял книгу, а её резервируют
  PASS   S6.2a    sync A ждал запись книги: UPDATE статуса экземпляра в reserve взял на неё S-lock (FK-индекс ix_items_record_status) [1]
  PASS   S6.2b    reserved не перетёрт; source_status = withdrawn; conflicts = 1 [reserved/withdrawn/1]
  PASS   S6.2c    после удаления из корзины экземпляр получает release target = withdrawn [withdrawn/withdrawn]
```

```text
--- S6.3  синхронизация первой: «Отложить» ждёт и получает 409 (книга снята)
  PASS   S6.3a    reserve — 409 item_unavailable (withdrawn) [409 uniundata_item_unavailable withdrawn]
```

```text
--- S6.4  два запуска синхронизации одновременно: GET_LOCK + UNIQUE(running_source)
  PASS   S6.4a    второй запуск сразу выходит: GET_LOCK занят [200 locked]
  PASS   S6.4b    второй running-прогон источника на уровне данных (uq_sync_runs_one_running)
           MySQL: ERROR 1062 (23000): Duplicate entry 'primary' for key 'wp_book_sync_runs.uq_sync_runs_one_running'
  PASS   S6.4c    процесс умер, строка running осталась: GET_LOCK свободен, но запуск видит running → busy [200 busy]
```

```text
--- S6.5  синхронизация первой (транзакция A держит запись книги) ↔ checkout этой книги: две транзакции sync — без deadlock
  PASS   S6.5a    checkout (уже держит экземпляр) ждёт S-блокировку записи книги — проверка FK при INSERT order_items [1]
  PASS   S6.5b    sync A не ждёт экземпляров и фиксируется; checkout 201; sync B — applied; deadlock нет [201 created / 200 applied / 0]
  PASS   S6.5c    B ждал экземпляр до COMMIT checkout и не перетёр checkout_pending: source_status = withdrawn, конфликт посчитан [checkout_pending/withdrawn/1]
```

```text
--- S6.6  checkout первым (X на экземпляре, S на записи от FK) ↔ синхронизация: A ждёт запись, B видит checkout_pending
  PASS   S6.6a    sync A ждал X-блокировку записи, на которой checkout держит S [1]
  PASS   S6.6b    checkout 201, sync applied, deadlock нет [201 created / 200 applied / 0]
  PASS   S6.6c    экземпляр checkout_pending, новая цена 7900 записана, в заказе — снимок 7500 [checkout_pending/7900/7500]
```

```text
--- S6.7  контроль: синхронизация в ОДНОЙ транзакции (records → items) против checkout (items → records по FK) = deadlock
  PASS   S6.7a    MySQL обнаружил deadlock (ровно один 1213) — поэтому SyncService пишет записи и экземпляры разными транзакциями [1]
```

## 10.7 Callback банка одновременно с задачей снятия истёкших резервов и заказов

**Webhook первым (S7.1).** Webhook держит экземпляр, pass B ждёт его. После `COMMIT` webhook-а pass B видит заказ
`paid` и ничего не делает.

```text
--- S7.1  webhook успел первым (держит экземпляр) → pass B ждёт и ничего не освобождает
    ┌─ хронология S7.1 (мс от первого шага; порядок — реальный порядок записи в t_trace)
    │      0.0 ms  WH      webhook S7-1-evt: succeeded 8000 RUB для pp_17
    │      1.4 ms  WH      inbox: событие #12 новое, processing_status = received
    │      2.7 ms  WH      FOR UPDATE items #71=checkout_pending
    │      3.8 ms  WH      FOR UPDATE заказ #17 (pending_payment), платежи (1), событие #12 (received)
    │      6.7 ms  WH      платёж (заказ pending_payment): продаём [71], конфликт [], проверить у источника []
    │      9.4 ms  WH      ПАУЗА в apply.before_commit: держу блокировки, жду ожидающих: 1
    │     11.3 ms  CRON    WH на паузе в apply.before_commit — начинаю
    │     12.9 ms  CRON    pass B: заказ #17, банк (fetchPayment вне транзакции) ответил: pending
    │     17.2 ms  WH      ВИЖУ ОЖИДАНИЕ: CRON ждёт X,REC_NOT_GAP wp_book_items.PRIMARY (71)
    │     18.4 ms  CRON    FOR UPDATE items #71=sold
    │     19.1 ms  WH      COMMIT → 200 processed {"note": "", "event_id": 12, "order_id": 17, "after_commit": [{"args": {"order_id": 17}, "hook": "uniundata_order_paid"}]}
    │     19.4 ms  CRON    FOR UPDATE заказ #17 → paid, просрочен: 1, продлён: нет
    │     20.6 ms  CRON    COMMIT → 200 noop {"is_due": 1, "order_status": "paid"}
    └─
  PASS   S7.1a    pass B ждал X-lock экземпляра, удерживаемый webhook [1]
  PASS   S7.1b    webhook processed; pass B — noop (заказ уже paid) [200 processed / 200 noop paid]
  PASS   S7.1c    заказ paid, экземпляр sold, продажа одна [paid/sold/1]
```

**Pass B первым, книгу успел зарезервировать другой (S7.3).** Pass B освободил экземпляр, U2 его зарезервировал,
и в этот момент приходит поздний успешный платёж. Экземпляр U2 не отбирается. Заказ получает
`paid` + `needs_attention = late_payment_conflict`, а возврат полной суммы записывается строкой `wp_book_refunds`
в той же транзакции.

```text
--- S7.3  pass B освободил, другой пользователь резервирует, и в этот момент приходит поздний платёж
    ┌─ хронология S7.3 (мс от первого шага; порядок — реальный порядок записи в t_trace)
    │      0.0 ms  CRON    pass B: заказ #19, банк (fetchPayment вне транзакции) ответил: pending
    │      1.2 ms  CRON    FOR UPDATE items #73=checkout_pending
    │      2.0 ms  CRON    FOR UPDATE заказ #19 → pending_payment, просрочен: 1, продлён: нет
    │      4.3 ms  CRON    заказ #19 → payment_expired, платежи [#19 pending→expired], экземпляры [73] освобождены
    │      6.5 ms  CRON    COMMIT → 200 payment_expired {"order_id": 19, "payments": "#19 pending→expired", "released_items": "73"}
    │     16.0 ms  U2      BEGIN reserve(user 1704, item #73)
    │     16.6 ms  U2      открытой корзины нет (FOR UPDATE ничего не нашёл) → INSERT … ON DUPLICATE KEY UPDATE
    │     17.6 ms  U2      создал корзину #75 (ODKU: 1 строка)
    │     19.0 ms  U2      FOR UPDATE экземпляра #73 → available
    │     21.1 ms  U2      INSERT резерв #84 (попытка 1), экземпляр → reserved, позиция #80
    │     22.0 ms  U2      ПАУЗА в reserve.before_commit: держу блокировки, жду ожидающих: 1
    │     24.5 ms  WH      U2 на паузе в reserve.before_commit — начинаю
    │     25.8 ms  WH      webhook S7-3-evt: succeeded 8200 RUB для pp_19
    │     27.6 ms  WH      inbox: событие #14 новое, processing_status = received
    │     35.0 ms  U2      ВИЖУ ОЖИДАНИЕ: WH ждёт X,REC_NOT_GAP wp_book_items.PRIMARY (73)
    │     37.3 ms  WH      FOR UPDATE items #73=reserved
    │     38.3 ms  U2      COMMIT → 201 created {"cart_id": 75, "attempt_no": 1, "expires_at": "2026-10-06 05:02:00.299230", "cart_item_id": 80, "reservation_id": 84}
    │     39.1 ms  WH      FOR UPDATE заказ #19 (payment_expired), платежи (1), событие #14 (received)
    │     41.8 ms  WH      ПОЗДНИЙ платёж (заказ payment_expired): продаём [], конфликт [73], проверить у источника []
    │     44.6 ms  WH      заказ #19 → needs_attention = late_payment_conflict
    │     46.5 ms  WH      INSERT wp_book_refunds #4: 8200 RUB, late_payment_conflict, requested
    │     49.9 ms  WH      COMMIT → 200 processed {"note": "late_payment_conflict", "event_id": 14, "order_id": 19, "after_commit": [{"args": {"reason": "late_payment_conflict", "order_id": 19}, "hook": "uniundata_order_needs_attention"}, {"args": {"refund_id": 4}, "hook": "uniundata_refund_payment"}]}
    └─
  PASS   S7.3a    webhook ждал X-lock экземпляра, который держит резерв U2 [1]
  PASS   S7.3b    U2 получил резерв; webhook: late_payment_conflict [201 created / late_payment_conflict]
  PASS   S7.3c    заказ paid + needs_attention=late_payment_conflict, продажи нет, экземпляр за U2 [paid:1:late_payment_conflict/0/reserved:1704]
  PASS   S7.3d    возврат полной суммы записан строкой wp_book_refunds (requested), задача AS — после COMMIT [8200 late_payment_conflict requested]
```

S7.2 — поздний платёж забирает свободный экземпляр. S7.4 — pass A не трогает экземпляр в оплате. S7.5 и
**S7.6** — гонка на границе часа: если первым успел pass A, checkout получает 409 (`uniundata_cart_empty` или
`uniundata_cart_changed`, клиент в обоих случаях перезагружает корзину), заказ не создаётся. S7.7 — контроль
неправильного порядка блокировок. S7.8 — стресс. S7.9 — поздний платёж за книгу, снятую источником:
`late_payment_source_check`.

```text
--- S7.2  pass B успел первым (заказ payment_expired) → поздний платёж забирает свободный экземпляр
  PASS   S7.2a    webhook ждал X-lock экземпляра, удерживаемый pass B [1]
  PASS   S7.2b    pass B: payment_expired; webhook: late_payment_reacquired [200 payment_expired / late_payment_reacquired]
  PASS   S7.2c    заказ paid без флага, платёж expired→succeeded, экземпляр sold, продажа одна [paid:0/succeeded/sold/1]
  PASS   S7.2d    аудит: платёж подтверждён поздно (late_confirmation) [true]
```

```text
--- S7.4  pass A не освобождает экземпляр, по которому идёт оплата (checkout_pending)
  PASS   S7.4a    через 61 мин после резерва: заказ pending_payment, экземпляр checkout_pending [pending_payment/checkout_pending]
  PASS   S7.4b    резерв converted_to_order, не expired [converted_to_order]
```

```text
--- S7.5  гонка на границе часа: checkout (59:xx) ↔ pass A (60:xx) — checkout первым
  PASS   S7.5a    pass A ждал корзину пользователя (1-й уровень порядка) [1]
  PASS   S7.5b    checkout 201; pass A пропустил резерв (уже converted_to_order) [201 created / 200 skipped converted_to_order]
  PASS   S7.5c    экземпляр checkout_pending [checkout_pending]
```

```text
--- S7.6  гонка на границе часа: pass A первым → checkout получает 409, заказа нет
  PASS   S7.6a    checkout ждал корзину, которую держит pass A [1]
  PASS   S7.6b    pass A: expired; checkout: 409 cart_empty (корзина закрыта как expired) [200 expired / 409 uniundata_cart_empty]
  PASS   S7.6c    заказа нет, экземпляр available [0/available]
```

```text
--- S7.7  контроль: webhook с НЕПРАВИЛЬНЫМ порядком (orders → items) против pass B (items → orders) = deadlock
  PASS   S7.7a    MySQL обнаружил deadlock: ровно одна сессия получила ERROR 1213 [1]
           → тест умеет ловить deadlock; при глобальном порядке (S7.1, S7.2, S7.8) их 0
```

```text
--- S7.8  стресс: 12 заказов, webhook и pass B стартуют почти одновременно (±30 мс, без пауз)
  PASS   S7.8a    ни одного deadlock / lock wait timeout (24 сессии), повторов не понадобилось [0/0]
  PASS   S7.8b    каждый заказ оплачен, каждый экземпляр продан ровно один раз [12 paid, 12 sold, 12 sales]
           ветки: 6× expire_order:noop, 6× expire_order:payment_expired, 6× webhook:processed, 6× webhook:late_payment_reacquired
```

```text
--- S7.9  поздний платёж, а источник за время оплаты снял книгу (source_status = withdrawn)
  PASS   S7.9a    sync не тронул checkout_pending, записал source_status = withdrawn [checkout_pending/withdrawn]
  PASS   S7.9b    pass B: заказ payment_expired, экземпляр получил release target withdrawn [payment_expired/withdrawn]
  PASS   S7.9c    webhook: свободный экземпляр (withdrawn, без резерва) забран и продан [late_payment_reacquired/sold]
  PASS   S7.9d    заказ paid + needs_attention = late_payment_source_check (проверить книгу физически), возврата нет [paid:1:late_payment_source_check/0]
```

## 10.8 Корзина и порядок блокировок (дополнительно)

S8.1 — одна открытая корзина при параллельном создании. S8.2 — отвергнутый вариант «INSERT → 1062 →
SELECT FOR UPDATE», который даёт deadlock. S8.3 — книга не добавляется дважды. S8.4–S8.6 — reserve и checkout
одного пользователя параллельно. S8.8 — двойной клик «Перейти к оплате» с тем же `Idempotency-Key`.

```text
--- S8.1  одна открытая корзина при параллельном создании (3 сессии, INSERT … ON DUPLICATE KEY UPDATE)
  PASS   S8.1a    все три не нашли корзину и вставляли одновременно; ожидающих двое [1]
  PASS   S8.1b    у пользователя одна корзина, все три сессии получили её id [1/1/3]
  PASS   S8.1c    без deadlock [0]
```

```text
--- S8.2  контроль: «INSERT → 1062 → SELECT FOR UPDATE» (отвергнутый вариант) на 3 сессиях
  PASS   S8.2a    после 1062 проигравшие держат S-lock и просят X → deadlock воспроизведён (поэтому в коде ODKU) [1]
  PASS   S8.2b    корзина всё равно одна (UNIQUE) [1]
```

```text
--- S8.3  одну книгу нельзя добавить дважды
  PASS   S8.3a    повторное «Отложить» своей книги — 200 existing [200 existing]
  PASS   S8.3b    одна активная позиция [1]
  PASS   S8.3c    вторая активная позиция той же книги даже в той же корзине (uq_cart_items_one_active_per_item)
           MySQL: ERROR 1062 (23000): Duplicate entry '91' for key 'wp_book_cart_items.uq_cart_items_one_active_per_item'
```

```text
--- S8.4  reserve и checkout одного пользователя параллельно — checkout первым
  PASS   S8.4a    reserve ждал на корзине (уровень 1), а не на экземплярах [1]
  PASS   S8.4b    checkout 201 (A+B); reserve 201 в НОВОЙ корзине [201 created / 201 created / new_cart]
  PASS   S8.4c    без deadlock [0]
```

```text
--- S8.5  reserve и checkout одного пользователя параллельно — reserve первым
  PASS   S8.5a    checkout ждал корзину; увидел три книги → 409 cart_changed (сумма 28800 ≠ 19100) [201 created / 409 uniundata_cart_changed 28800]
  PASS   S8.5b    без deadlock [0]
```

```text
--- S8.6  контроль: remove-item с НЕПРАВИЛЬНЫМ порядком (items → carts) против checkout (carts → items) = deadlock
  PASS   S8.6a    MySQL обнаружил deadlock (ровно один 1213) [1]
```

```text
--- S8.8  двойной клик «Перейти к оплате» с тем же Idempotency-Key
  PASS   S8.8a    первый 201, второй 200 replayed с тем же заказом [201 created / 200 replayed #51]
  PASS   S8.8b    в БД один заказ [1]
```

## 10.9 Гарантии схемы (S0)

Каждое ограничение проверено попыткой его нарушить.

```text
--- экземпляры
  PASS   S0.01    sold без sold_at невозможен
  PASS   S0.02    неизвестный статус экземпляра
  PASS   S0.03    внешний book_id = один экземпляр
  PASS   S0.04    валюта только A–Z: 'rub' отклоняется (utf8mb4_bin — REGEXP регистрозависим)
--- резервы
  PASS   S0.05    второй active-резерв на экземпляр
  PASS   S0.06    неактивных резервов на экземпляр — сколько угодно (generated column = NULL) [2 строк, active_book_item_id IS NULL: 2]
  PASS   S0.07    4-я попытка
  PASS   S0.08    повтор номера попытки
  PASS   S0.09    released_by_admin обязан иметь attempt_no = NULL
  PASS   S0.10    не-admin резерв обязан иметь номер попытки
  PASS   S0.11    active ⇔ released_at IS NULL
  PASS   S0.12    converted_to_order без order_id
  PASS   S0.13    резерв несуществующего экземпляра
--- корзины
  PASS   S0.14    вторая открытая корзина пользователя
  PASS   S0.15    закрытых корзин у пользователя — сколько угодно [3]
  PASS   S0.16    книга — активная позиция только одной корзины
  PASS   S0.17    одна позиция на резерв
--- заказы, платежи, события
  PASS   S0.18    public_order_id только uniundata_<uuid v4>
  PASS   S0.19    total = subtotal − discount + shipping
  PASS   S0.20    paid без paid_at
  PASS   S0.21    один заказ на Idempotency-Key пользователя
  PASS   S0.22    в платеже только 4 последние цифры карты
  PASS   S0.23    одно событие банка — одна строка inbox
--- v2: суммы строго > 0 (бесплатная книга / нулевой платёж — ошибка данных)
  PASS   S0.24    цена экземпляра 0
  PASS   S0.25    снимок цены в корзине 0
  PASS   S0.26    заказ на 0
  PASS   S0.27    снимок цены в заказе 0
  PASS   S0.28    платёж на 0
  PASS   S0.29    возвращено больше, чем заплачено (refunded_amount ≤ amount)
  PASS   S0.30    возврат на 0
  PASS   S0.31    продажа за 0
--- v2: возвраты — один ключ идемпотентности банка на одну строку
  PASS   S0.32    повтор idempotency_key возврата
  PASS   S0.33    неизвестная причина возврата
  PASS   S0.34    succeeded без completed_at
--- v2: внешние ID регистрозависимы (utf8mb4_bin)
  PASS   S0.35    'base-01' и 'BASE-01' — два разных экземпляра; поиск по 'base-01' находит только свой [1 / base-01]
  PASS   S0.36    хвостовой пробел не различается (utf8mb4_bin — PAD SPACE): SourceBatch пропускает только \x21–\x7E
--- v2: FULLTEXT построен без стоп-слов (SET SESSION innodb_ft_enable_stopword = OFF перед CREATE TABLE)
  PASS   S0.37    '+Krieg +und +Frieden' и '+und' находят запись (ft_records_main) [1/1]
  PASS   S0.38    контроль: та же таблица со стоп-словами InnoDB по умолчанию — '+Krieg +und' даёт 0 [0]
  PASS   S0.39    слова короче innodb_ft_min_token_size (3) не индексируются: '+ум' → 0, '+сердце' → 1 (поэтому плагин не делает их обязательными) [0/1]
--- v2: вторая установка WordPress в той же БД (префикс wp_2_): sed 's/\bwp_/wp_2_/g' schema.sql
  PASS   S0.40    схема с префиксом wp_2_ загружается рядом с wp_ без ошибок [ok / 21 таблиц]
  PASS   S0.41    каждое имя CHECK/FK начинается с имени своей таблицы (обе установки: 86 + 86, чужих имён 0) [86 + 86, чужих 0]
  PASS   S0.42    ошибка второй установки называет её ограничение (Db сверяет по суффиксу _chk_status)
  PASS   S0.43    контроль: одно имя CHECK в двух таблицах — ERROR 3822 (поэтому имя = <таблица>_chk_<x>)
  PASS   S0.44    Db::lockName() разных установок различается: GET_LOCK синхронизации не пересекаются [1 1]
```

## 10.10 Принятое поведение: редкий deadlock при создании корзины (S8.7e)

Стресс S8.7: 36 пользователей одновременно запускают checkout корзины (A+B) и резерв новой книги (C). Если
checkout закрывает корзину, а резерв в ту же миллисекунду создаёт новую, InnoDB проверяет дубликат в
`uq_carts_one_open_per_user` по ещё не вычищенной (delete-marked) записи закрытой корзины и ставит gap-блокировку
даже в READ COMMITTED. Две такие вставки соседних пользователей могут ждать друг друга. Это **не** нарушение
порядка блокировок, инварианты не страдают: транзакция откатывается целиком, а `Db::transaction()` её
повторяет. Итог стресса — 0 операций, упавших с ошибкой. В прогоне, вывод которого приведён ниже, deadlock
не проявился. В другом прогоне был один повторённый deadlock с таким отчётом InnoDB:

```text
INSERT INTO wp_book_carts (user_id, status, started_at, last_activity_at)
index uq_carts_one_open_per_user of table `uniundata_test`.`wp_book_carts` lock_mode X locks gap before rec
index uq_carts_one_open_per_user of table `uniundata_test`.`wp_book_carts` lock_mode X locks gap before rec insert intention waiting
*** WE ROLL BACK TRANSACTION (2)
``` Альтернатива (отдельная таблица-«слот» корзины с
PRIMARY KEY по `user_id`) убирает эффект, но усложняет схему ради редкого случая, поэтому не выбрана.

```text
--- S8.7  стресс: 3 раунда × 12 пользователей, у каждого checkout(A+B) и reserve(C) стартуют одновременно (повтор как Db::transaction)
  PASS   S8.7a    итог: ни одной операции, упавшей по deadlock / lock wait timeout (с повтором) [0]
  PASS   S8.7b    все 36 reserve(C) успешны [36]
  PASS   S8.7c    каждый checkout: заказ A+B (201) или 409 cart_changed (C успел первым) — других исходов нет [36]
  PASS   S8.7d    порядок блокировок не участвует: каждый deadlock (если был) — на INSERT новой корзины (gap-lock проверки UNIQUE) [0]
  INFO   S8.7e    без повтора: ни одного deadlock [0 повторено] — в этом прогоне не проявилось
           исходы checkout: 23× uniundata_cart_changed, 13× created
```

## 10.11 Глобальные инварианты после всех сценариев (S9)

После всех сценариев 19 инвариантов из `tests/mysql/invariants.sql` дают 0 нарушений. Это те же запросы, что
выполняет `wp uniundata doctor`. Ни одного неожиданного deadlock-а или lock wait timeout не было, а точечные
запросы алгоритмов используют нужные индексы (EXPLAIN).

```text
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
  PASS   I17      duplicate_payment / late_payment_conflict ⇒ есть строка возврата [0]
  PASS   I18      невозвращённых (не failed) возвратов по платежу не больше суммы платежа [0]
  PASS   I19      активных непросроченных резервов на пользователя ≤ uniundata_max_active_reservations [0]
  PASS   S9.dl    неповторённых deadlock (1213) вне контрольных сценариев S6.7, S7.7, S8.2, S8.6 — ни одного [0]
           повторённых (как Db::transaction) deadlock-ов за прогон: 0
  PASS   S9.lw    lock wait timeout (1205) — ни одного [0]
--- индексы точечных запросов алгоритмов (EXPLAIN на реальных строках после сценариев)
  PASS   S9.ix1   открытая корзина: WHERE open_cart_user_id = ? → uq_carts_one_open_per_user [uq_carts_one_open_per_user]
  PASS   S9.ix2   active-резерв: WHERE active_book_item_id = ? → uq_reservations_one_active_per_item [uq_reservations_one_active_per_item]
  PASS   S9.ix3   webhook: платёж по (provider, provider_payment_id) → uq_payments_provider_id [uq_payments_provider_id]
  PASS   S9.ix4   продажа экземпляра: WHERE book_item_id = ? → uq_sales_book_item [uq_sales_book_item]
  PASS   S9.ix5   платежи заказа FOR UPDATE: WHERE order_id = ? → uq_payments_attempt [uq_payments_attempt]
```
