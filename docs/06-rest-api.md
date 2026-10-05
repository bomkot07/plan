# 6. REST API

> Namespace `uniundata/v1`, 15 маршрутов в 6 контроллерах (`src/Rest/*Controller.php`). Браузер работает через
> cookie + `X-WP-Nonce`, скрипты персонала — через Application Passwords, банк — через подпись webhook-а.
> Все ответы отдаются с `Cache-Control: no-store`. Ошибки приходят в стандартном формате WP REST
> `{code, message, data:{status,…, request_id}}`.
> Контроллеры только проверяют вход, права и rate limit. Решения о резерве, заказе и оплате
> принимают сервисы в транзакциях под `SELECT … FOR UPDATE` ([05-algorithms](05-algorithms.md),
> [08-security-concurrency](08-security-concurrency.md)).
> Всё описанное ниже прогнано на стенде WordPress 7.1.2 + MySQL 8.0.46 + PHP 8.3 (§ 6.10).

## 6.1 Общие правила

### Адрес, форматы, идентификаторы

| Что | Правило |
|---|---|
| Базовый URL | `https://shop.example/wp-json/uniundata/v1/…`. Без ЧПУ тот же маршрут доступен как `/?rest_route=/uniundata/v1/…`. Это важно для allowlist на nginx (§ 6.5.4) |
| Тело запроса | JSON (`Content-Type: application/json`). `POST /checkout` принимает **только** JSON. Остальные маршруты по правилам WP принимают и form-data, но клиент шлёт JSON |
| Деньги | Целое число в минимальных единицах (`4500` = 45,00 EUR) + `currency` по ISO 4217. Float не используется нигде |
| Время | ISO 8601 в UTC с `Z`, обычно с миллисекундами (`2026-10-05T13:47:16.892Z`). Таймеры клиент считает от `server_time` из ответа, а не от часов браузера |
| ID экземпляра | `book_item_id` (BIGINT) публичен: он и так есть в HTML каталога. В `args` верхняя граница `2^53−1`, это наибольшее целое, которое JavaScript передаёт без потери точности |
| ID заказа | Наружу выходит только `public_order_id` = `uniundata_<UUID v4>` (122 бита случайности). Последовательный `orders.id` в API не появляется |

### Аутентификация

| Клиент | Механизм | Nonce | Маршруты |
|---|---|---|---|
| Витрина и личный кабинет (браузер) | cookie `wordpress_logged_in_*` + заголовок `X-WP-Nonce: <wp_create_nonce('wp_rest')>` | обязателен | `cart/*`, `checkout`, `orders/*`, персональные поля `catalog/availability` |
| JS в wp-admin | то же (`wpApiSettings.nonce`) | обязателен | `admin/*` |
| Скрипты персонала, интеграции | Application Passwords (HTTP Basic, только HTTPS) | не нужен | `admin/*`, при необходимости `orders/*` |
| Банк | подпись тела (HMAC-SHA256 / RSA) + timestamp | неприменим (§ 6.5.1) | `payment/webhook` |
| Гость | — | — | `catalog/availability` |

Как WordPress 7.1 обрабатывает cookie-запрос (`rest_cookie_check_errors`, проверено на стенде):

| Ситуация | Что делает WordPress | Что получает клиент |
|---|---|---|
| Cookie есть, `X-WP-Nonce` нет | `wp_set_current_user(0)`: запрос считается анонимным | 401 `uniundata_auth_required`. Наш `permission_callback` видит cookie без nonce и добавляет `data.reason = "missing_nonce"`, чтобы фронтенд сразу понял причину |
| Nonce неверный или просрочен (старше 24 ч) | Ошибка до `permission_callback` | 403 `rest_cookie_invalid_nonce` → клиент обновляет nonce (§ 6.8) и повторяет запрос один раз |
| Nonce верный | Пользователь аутентифицирован | Ответ содержит заголовок `X-WP-Nonce` со свежим nonce, клиент сохраняет его |

Почему nonce обязателен даже для `GET /cart`: WordPress отвечает на CORS-запросы
`Access-Control-Allow-Origin: <origin запроса>` с разрешёнными credentials. Без nonce чужой сайт мог бы
прочитать корзину залогиненного покупателя его же cookie. Nonce чужой сайт получить не может, поэтому
без nonce такой запрос анонимен.

`user_id` контроллеры берут **только** из `get_current_user_id()` и никогда из параметров запроса.

### Порядок обработки запроса в WordPress

```
rest_authentication_errors (cookie+nonce / Application Password)
  → поиск маршрута по regex                       (нет совпадения → 404 rest_no_route)
  → JSON-тело                                     (битый JSON → 400 rest_invalid_json)
  → has_valid_params(): required + validate_callback   (→ 400 rest_missing_callback_param / rest_invalid_param)
  → sanitize_params(): sanitize_callback
  → permission_callback                           (→ 401 / 403)
  → callback: rate limit → сервис → ответ         (DomainError → WP_Error)
  → rest_post_dispatch: no-store, X-Request-Id, data.request_id, Retry-After
```

Из этого порядка следует:

- `args` проверяются **до** прав. Гость с мусорным `book_item_id` получает 400, а не 401. Это ничего не
  раскрывает: схема маршрута и так публична через `OPTIONS`.
- Если задан собственный `sanitize_callback`, WordPress **не** применяет схему автоматически. Поэтому во
  всех `args` явно указан `validate_callback => 'rest_validate_request_arg'`, и `type`, `minimum`,
  `pattern`, `enum`, `maxItems` действительно проверяются.
- `permission_callback` не имеет побочных эффектов, поэтому счётчик rate limit увеличивается в начале callback.

### Заголовки ответа

| Заголовок | Когда | Зачем |
|---|---|---|
| `Cache-Control: no-store, no-cache, must-revalidate, max-age=0, private` + `Expires` в прошлом | Все ответы namespace, включая 401/403/404 самого WordPress | Ответ персональный или «живой» (статус экземпляра). WordPress сам шлёт no-cache только залогиненным, а `availability` читают гости |
| `X-Request-Id: <uuid>` | Всегда | Тот же ID пишется в `wp_book_audit_log.request_id` и в `error_log`. Если nginx/LB передал свой `X-Request-Id` (UUID), используется он |
| `Retry-After: <сек>` | 429, 503; 5xx на webhook | Для 429/503 значение берётся из `data.retry_after`, для webhook-а оно фиксированное — 30 с |
| `Location` | 201 `POST /checkout` | URL созданного заказа |
| `X-WP-Total`, `X-WP-TotalPages` | Списки | Пагинация в стиле ядра WordPress |
| `X-WP-Nonce` | Запросы с валидным nonce | Обновлённый nonce |

### Прочее

- **Batch API** (`/batch/v1`) для наших маршрутов недоступен: они не объявляют `allow_batch`. Иначе одним
  запросом можно было бы обойти rate limit.
- `admin/*` и `payment/webhook` зарегистрированы с `show_in_index => false` и не попадают в индекс
  `/wp-json/`. Защищает их не скрытие, а capability и подпись.
- Плагины безопасности, которые «закрывают REST для гостей» через `rest_authentication_errors`, должны
  пропускать `/uniundata/v1/catalog/availability` и `/uniundata/v1/payment/webhook`. Иначе витрина и
  оплата перестанут работать.

## 6.2 Сводная таблица маршрутов

Capabilities по ролям: `book_customer` — `reserve_books`, `create_book_orders`, `view_own_book_orders`;
`book_order_manager` — `manage_book_orders`, `manage_book_reservations`; `book_catalog_manager` —
`manage_book_catalog`, `manage_book_sync`; `administrator` — все ([07-users-roles](07-users-roles.md)).

| # | Метод и путь | Аутентификация | `permission_callback` / capability | Nonce | Rate limit | Успех |
|---|---|---|---|---|---|---|
| 1 | `GET /catalog/availability` | публичный; cookie+nonce добавляет персональные поля | `__return_true` **осознанно**: каталог публичен, гостю нельзя назначить capability, PII в ответе нет | если есть cookie | 300/мин на IP | 200 |
| 2 | `GET /cart` | cookie+nonce / App Password | `reserve_books` | да | — | 200 |
| 3 | `POST /cart/reserve` | то же | `reserve_books` | да | 20/мин на пользователя, 60/мин на IP | 201 создан / 200 повтор |
| 4 | `POST /cart/remove-item` | то же | `reserve_books` | да | 30 / 90 | 200 |
| 5 | `POST /checkout` | то же | `create_book_orders` | да | 5 / 20 | 201 создан / 200 повтор ключа |
| 6 | `GET /orders` | то же | `view_own_book_orders` | да | — | 200 |
| 7 | `GET /orders/{public_order_id}` | то же | только вход; объектное право `view_book_order` проверяется в callback | да | — | 200 |
| 8 | `POST /orders/{public_order_id}/pay` | то же | `create_book_orders` + строгое владение в `CheckoutService` | да | 10 / 30 | 200 |
| 9 | `POST /orders/{public_order_id}/cancel` | то же | `create_book_orders` + строгое владение | да | 10 / 30 | 200 |
| 10 | `POST /payment/webhook` | подпись банка | `permitWebhook`: необязательный IP allowlist; подпись проверяется первым шагом callback | неприменим | на nginx | 200 |
| 11 | `POST /admin/reservations/{id}/release` | cookie+nonce / App Password | `manage_book_reservations` | да (cookie) | 60/мин на пользователя | 200 |
| 12 | `POST /admin/items/{id}/block` | то же | `manage_book_catalog` | да (cookie) | 60/мин | 200 |
| 13 | `POST /admin/items/{id}/unblock` | то же | `manage_book_catalog` | да (cookie) | 60/мин | 200 |
| 14 | `POST /admin/sync/run` | то же | `manage_book_sync` | да (cookie) | 60/мин | 202 |
| 15 | `GET /admin/sync/runs` | то же | `manage_book_sync` | да (cookie) | — | 200 |

`permission_callback` пользовательских маршрутов возвращает 401 `uniundata_auth_required`, если
пользователь не определён, и 403 `uniundata_forbidden`, если нет capability. Значение `'__return_true'`
стоит только у маршрута 1.

## 6.3 Маршруты: параметры, ответы, ошибки

Общие для всех маршрутов ошибки (400 `rest_invalid_param`, 401, 403, 429, 500, 503) перечислены в § 6.4.
Здесь указаны ошибки, специфичные для маршрута.

### 6.3.1 `GET /catalog/availability` — статусы кнопок «Отложить»

| Параметр | Где | Тип и валидация | Sanitize |
|---|---|---|---|
| `item_ids` | query, обязателен | `array<integer>`: `"1,2,3"` или `item_ids[]=1&item_ids[]=2`; 1–100 элементов, каждый 1…2^53−1 | `absint`, дубли удаляются, порядок сохраняется |

```json
{
  "server_time": "2026-10-05T12:47:17.514Z",
  "authenticated": true,
  "items": [
    { "book_item_id": 1, "status": "reserved", "can_reserve": false, "reason": "in_your_cart",
      "price_amount": 4500, "currency": "EUR", "held_by_me": "cart",
      "expires_at": "2026-10-05T13:47:16.892Z", "public_order_id": null, "attempts_left": 2 },
    { "book_item_id": 3, "status": "available", "can_reserve": false, "reason": "limit_reached",
      "price_amount": 3000, "currency": "EUR", "held_by_me": null,
      "expires_at": null, "public_order_id": null, "attempts_left": 0 },
    { "book_item_id": 6, "status": "sold", "can_reserve": false, "reason": "sold",
      "price_amount": 1000, "currency": "EUR", "held_by_me": null,
      "expires_at": null, "public_order_id": null, "attempts_left": 3 },
    { "book_item_id": 99, "status": "not_found", "can_reserve": false, "reason": "not_found",
      "price_amount": null, "currency": null, "held_by_me": null,
      "expires_at": null, "public_order_id": null, "attempts_left": 3 }
  ]
}
```

| Поле | Значения |
|---|---|
| `status` (публичный) | `available`; `reserved` (внутренние `reserved` и `checkout_pending` наружу не различаются); `sold`; `unavailable` (`withdrawn`, `sync_missing`, `blocked`, неактивная запись); `not_found` |
| `reason` | `null`, если `can_reserve = true`. Иначе одно из: `login_required`, `forbidden` (нет `reserve_books`), `reserved`, `sold`, `unavailable`, `not_found`, `limit_reached` (3 попытки исчерпаны), `in_your_cart`, `in_your_order` |
| `held_by_me` | `cart` + `expires_at` — активный резерв текущего пользователя; `order` + `public_order_id` — экземпляр в его открытом заказе |
| `attempts_left` | Только для авторизованного запроса, иначе `null`. Резерв, снятый администратором, попыткой не считается |

Запросы только читают данные, без блокировок: экземпляры ищутся по `PRIMARY` (range по `IN`), попытки — по
префиксу `uq_reservations_attempt (user_id, book_item_id)`, открытые заказы — по `ix_order_items_book_item`.
Ответ служит **подсказкой для UI**: «Отложить» всё равно решается под `FOR UPDATE` (§ 6.3.3).

### 6.3.2 `GET /cart` — открытая корзина

Без параметров. Запрос только читает данные, без блокировок, и **не продлевает** резерв. Позиция, чей час
истёк до прихода cron, приходит с `is_expired: true` и не входит в сумму.

```json
{
  "cart_id": 1,
  "status": "active",
  "items": [
    { "book_item_id": 1, "book_record_id": 1, "reservation_id": 1,
      "title": "Война и мир", "subtitle": null, "authors": "Толстой, Лев", "publication_year": 1869,
      "condition_code": "good", "cover_url": null,
      "unit_price_amount": 4500, "currency": "EUR", "price_changed": false,
      "added_at": "2026-10-05T12:47:16.892Z", "expires_at": "2026-10-05T13:47:16.892Z",
      "seconds_left": 3599, "is_expired": false, "attempt_no": 1, "attempts_left": 2 }
  ],
  "items_count": 1,
  "totals": { "EUR": 4500 },
  "subtotal_amount": 4500,
  "currency": "EUR",
  "expires_at": "2026-10-05T13:47:16.892Z",
  "server_time": "2026-10-05T12:47:17.012Z"
}
```

Пустая корзина: `{"cart_id": null, "status": null, "items": [], "items_count": 0, "totals": [], "subtotal_amount": 0, "currency": null, "expires_at": null, "server_time": "…"}`.
`unit_price_amount` — снимок цены на момент резерва. `price_changed: true` означает, что цена в каталоге с
тех пор изменилась (оплачивается снимок).

### 6.3.3 `POST /cart/reserve` — «Отложить»

| Параметр | Где | Тип и валидация | Sanitize |
|---|---|---|---|
| `book_item_id` | JSON-тело, обязателен | integer, 1…2^53−1 | `absint` |

201 — резерв создан. 200 — у пользователя уже есть активный резерв на этот экземпляр: ответ идемпотентен,
новая попытка **не** тратится.

```json
{
  "created": true,
  "reservation": { "id": 1, "book_item_id": 1, "status": "active", "attempt_no": 1, "attempts_left": 2,
                   "reserved_at": "2026-10-05T12:47:16.892Z", "expires_at": "2026-10-05T13:47:16.892Z" },
  "cart": { "cart_id": 1, "status": "active", "items": [ … ], "…": "как в GET /cart" }
}
```

| HTTP | `code` | Когда | `data` |
|---|---|---|---|
| 404 | `uniundata_item_not_found` | Экземпляра нет, он неактивен или неактивна его запись | `book_item_id` |
| 409 | `uniundata_item_unavailable` | Статус не `available`: зарезервирован другим, `sold`, `blocked`, `withdrawn`, `sync_missing` | `book_item_id`, `availability_status` |
| 409 | `uniundata_reservation_limit_reached` | Три попытки на этот экземпляр уже использованы | `book_item_id`, `max_attempts: 3` |
| 503 | `uniundata_conflict_retry` | Deadlock / lock wait timeout после 3 повторов транзакции | `retry_after` |

Два покупателя нажали «Отложить» одновременно (проверено параллельными curl): первый получает 201, второй
ждёт на `FOR UPDATE` строки экземпляра и получает 409 `uniundata_item_unavailable` с
`availability_status: "reserved"`. В `wp_book_reservations` ровно одна строка `active`.

### 6.3.4 `POST /cart/remove-item` — убрать из корзины

| Параметр | Где | Тип и валидация |
|---|---|---|
| `book_item_id` | JSON-тело, обязателен | integer, 1…2^53−1 |

```json
{ "outcome": "removed", "cart": { "cart_id": 4, "status": "active", "items": [], "items_count": 0, "…": "…" } }
```

| `outcome` | Значение |
|---|---|
| `removed` | Резерв → `cancelled` (`release_reason = user_removed`, это считается попыткой), позиция → `removed`, экземпляр сразу возвращается в `available` (или в `sync_missing` / `withdrawn` по `source_status`) |
| `expired` | Час истёк раньше, чем пришёл cron: резерв закрыт как `expired` |
| `already_removed` | Повторный запрос; ничего не изменилось |

Ошибки: 404 `uniundata_item_not_found`, если этой книги нет в корзине пользователя. Ответ одинаковый,
чтобы не раскрывать, что книгу держит кто-то другой. 409 `uniundata_cart_changed`
(`data.reason = "item_in_order"`), если книга уже ушла в заказ: её освобождает отмена заказа.

### 6.3.5 `POST /checkout` — «Перейти к оплате»

Заголовки: `Content-Type: application/json` (обязательно) и `Idempotency-Key: <UUID>` (обязательно).
Клиент генерирует ключ один раз на нажатие «Подтвердить» (`crypto.randomUUID()`) и повторяет **тот же** ключ
при сетевом ретрае. После 409 `uniundata_cart_changed` клиент берёт **новый** ключ.

| Параметр | Тип и валидация (`args`) | Sanitize | Вторая проверка (`CheckoutRequest`) |
|---|---|---|---|
| `expected_total_amount` | integer 0…4294967295, обязателен | `rest_sanitize_request_arg` → int | `is_int`; расхождение с сервером → 409 |
| `currency` | string `^[A-Za-z]{3}$`, обязателен | `strtoupper` | `^[A-Z]{3}$` |
| `accept_offer_version` | string `^[A-Za-z0-9._-]{1,32}$`, обязателен | `sanitize_text_field` | Равна текущей версии в option `uniundata_terms_versions`, иначе 400 с `data.current_version` |
| `accept_privacy_version` | то же | то же | то же |
| `shipping_address` | object, `additionalProperties: false`; `line1`(1–255), `city`(1–100), `postcode`(1–20), `country` `^[A-Za-z]{2}$` обязательны; `first_name`, `last_name`(≤100), `company`, `line2`(≤255), `region`(≤100) | `rest_sanitize_request_arg` | Белый список полей, управляющие символы удаляются, UTF-8, длины, `country` → верхний регистр |
| `billing_address` | то же, необязателен | то же | то же |
| `phone` | string E.164 `^\+[1-9][0-9]{6,14}$`, необязателен | `sanitize_text_field` | E.164; если не передан, берётся из `wp_book_customer_profiles` |

Имя, фамилия и e-mail берутся из профиля WordPress, а не из запроса: их нельзя подменить телом. Снимок
этих данных попадает в заказ.

**201 Created** (`Location: …/wp-json/uniundata/v1/orders/uniundata_6b9d…`). Повтор того же ключа даёт **200**
с `"created": false, "replayed": true` и тем же заказом:

```json
{
  "created": true,
  "replayed": false,
  "order": {
    "public_order_id": "uniundata_6b9d02b6-4b31-4043-88c5-86576278eab2",
    "status": "pending_payment", "currency": "EUR",
    "subtotal_amount": 7000, "discount_amount": 0, "shipping_amount": 0, "tax_amount": 0, "total_amount": 7000,
    "placed_at": "2026-10-05T12:49:38Z", "payment_due_at": "2026-10-05T13:29:38Z",
    "paid_at": null, "cancelled_at": null,
    "items": [ { "book_item_id": 4, "title": "Война и мир", "author": "Толстой, Лев", "isbn": "9780306406157",
                 "cover_url": null, "unit_price_amount": 7000, "currency": "EUR" } ]
  },
  "payment": {
    "public_order_id": "uniundata_6b9d02b6-4b31-4043-88c5-86576278eab2",
    "attempt_no": 1,
    "redirect_url": "https://bank.example/pay/7f125eb5-8ff0-4334-b540-eb9ef0c1cc69",
    "session_expires_at": "2026-10-05T13:19:38Z"
  }
}
```

После ответа клиент делает `location.assign(payment.redirect_url)`. Возврат браузера с сайта банка
(`/checkout/return/?order=…`) **не** подтверждает оплату: страница возврата опрашивает
`GET /orders/{id}`, пока статус не станет `paid` (его выставляет только webhook или опрос банка cron-ом).

| HTTP | `code` | Когда | `data` / действие клиента |
|---|---|---|---|
| 400 | `uniundata_invalid_param` | Нет `Idempotency-Key`; тело не JSON; устарела версия оферты; в профиле нет имени или валидного e-mail | `param` (`Idempotency-Key`, `body`, `accept_offer_version`, `first_name`…), для оферты ещё `current_version` |
| 400 | `uniundata_invalid_param` | Тот же `Idempotency-Key` уже использован для другой суммы или валюты | `param: "Idempotency-Key"` → сгенерировать новый ключ |
| 409 | `uniundata_cart_empty` | В корзине нет активных позиций | — |
| 409 | `uniundata_cart_changed` | `reason: "positions_expired"` — истёкшие позиции помечены `expired` и **закоммичены**; `reason: "total_mismatch"` — сумма не совпала | `expired_book_item_ids`, `actual_total_amount`, `cart` → показать корзину и подтвердить заново с новым ключом |
| 502 | `uniundata_payment_provider_error` | Заказ создан, но банк не открыл сессию | `public_order_id`, `order_status` (`payment_failed`) → кнопка «Повторить оплату» = `POST /orders/{id}/pay` |
| 500 | `uniundata_internal` | Не настроена option `uniundata_terms_versions` и т. п. | Текст в лог, клиенту только код |

Rate limit (5 в минуту) учитывает и повторы с тем же ключом. Если клиент получил 429, он повторяет запрос
с **тем же** `Idempotency-Key` после `Retry-After`, и сервер вернёт уже созданный заказ.

### 6.3.6 Заказы

**`GET /orders`** — свои заказы, новые первыми (`ix_orders_user (user_id, created_at)`).

| Параметр | Тип и валидация |
|---|---|
| `page` | integer 1…10000, по умолчанию 1 |
| `per_page` | integer 1…50, по умолчанию 20 |
| `status` | enum статусов заказа (`draft` … `completed`), необязателен |

```json
{ "orders": [ { "public_order_id": "uniundata_7dd6213e-fc62-4673-8a43-293e4bc68c52", "status": "paid",
                "currency": "EUR", "total_amount": 9500, "items_count": 2,
                "placed_at": "2026-10-05T12:47:40.836Z", "payment_due_at": "2026-10-05T13:27:40.836Z",
                "paid_at": "2026-10-05T12:48:19.459Z", "cancelled_at": null,
                "created_at": "2026-10-05T12:47:40.836Z" } ],
  "page": 1, "per_page": 20, "total": 1 }
```

Плюс заголовки `X-WP-Total: 1`, `X-WP-TotalPages: 1`.

**`GET /orders/{public_order_id}`** — путь `uniundata_[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}`.
`permission_callback` проверяет только вход: владельца нельзя проверить до загрузки заказа. Callback
загружает заказ по `uq_orders_public_id` и вызывает `current_user_can('view_book_order', $orderId)`
(`map_meta_cap`: владелец → `view_own_book_orders`, остальные → `manage_book_orders`). Несуществующий,
чужой и синтаксически неверный номер дают **одинаковый 404** (`uniundata_order_not_found` или
`rest_no_route`), поэтому по ответу нельзя узнать, существует ли чужой заказ.

```json
{
  "public_order_id": "uniundata_6b9d02b6-4b31-4043-88c5-86576278eab2",
  "status": "pending_payment", "currency": "EUR",
  "subtotal_amount": 7000, "discount_amount": 0, "shipping_amount": 0, "tax_amount": 0,
  "total_amount": 7000, "refunded_amount": 0, "prices_include_tax": true,
  "placed_at": "2026-10-05T12:49:38.725Z", "payment_due_at": "2026-10-05T13:29:38.725Z",
  "paid_at": null, "cancelled_at": null, "cancel_reason": null, "fulfilled_at": null, "completed_at": null,
  "created_at": "2026-10-05T12:49:38.725Z", "server_time": "2026-10-05T12:51:25.453Z",
  "actions": { "can_pay": true, "can_cancel": true },
  "customer": { "email": "cust2@example.com", "phone": null, "first_name": "Cust2", "last_name": "Tester", "middle_name": null },
  "shipping_address": null, "billing_address": null, "shipping_method": null,
  "items": [ { "book_item_id": 4, "title": "Война и мир", "subtitle": null, "author": "Толстой, Лев",
               "isbn": "9780306406157", "publisher": null, "publication_year": 1869, "condition_code": "good",
               "cover_url": null, "unit_price_amount": 7000, "currency": "EUR" } ],
  "payment": { "attempt_no": 1, "status": "pending", "amount": 7000, "currency": "EUR",
               "card_brand": null, "card_last4": null,
               "session_expires_at": "2026-10-05T13:19:37.000Z", "succeeded_at": null }
}
```

Менеджер (`manage_book_orders`) дополнительно видит `needs_attention`, `attention_reason`, `user_id` и
все попытки `payments[]` с `provider`, `provider_status`, `failure_code`. Покупатель видит только последнюю
попытку и из данных карты только `card_brand` и `card_last4` (это разрешено PCI DSS). `actions` — подсказка
для кнопок, окончательно решает сервис под блокировками.

**`POST /orders/{public_order_id}/pay`** — новая или восстановленная платёжная попытка до
`payment_due_at − grace`. Если живая сессия уже есть, сервис возвращает её `redirect_url` (второй
сессии не будет, двойной оплаты тоже). Строгое владение: менеджер не может оплатить чужой заказ (404).
Ответ: `{created:false, replayed:false, order, payment:{public_order_id, attempt_no, redirect_url, session_expires_at}}`.
Ошибки: 404 `uniundata_order_not_found`; 409 `uniundata_order_not_payable` (`data.order_status`): заказ
оплачен, отменён, истёк, деньги «в пути» (`processing`) или до `payment_due_at` осталось меньше 2 минут;
502 `uniundata_payment_provider_error`.

**`POST /orders/{public_order_id}/cancel`** — отмена до оплаты из `draft`, `pending_payment` и
`payment_failed`. Экземпляры освобождаются, сессии банка закрываются после COMMIT. Ответ:
`{order, changed}`. Повторная отмена возвращает 200 с `changed: false`. Если платёж уже `processing` или
`succeeded`, ответ 409 `uniundata_order_not_payable`.

### 6.3.7 Администрирование

**`POST /admin/reservations/{id}/release`** (`manage_book_reservations`)

| Параметр | Где | Тип и валидация |
|---|---|---|
| `id` | путь `\d+` | integer 1…2^53−1 |
| `reason` | JSON, необязателен | string ≤255, `sanitize_text_field`. Значение вида `^[a-z][a-z0-9_]{0,31}$` пишется в `release_reason`, свободный текст — в `context.note` аудита |

```json
{ "reservation": { "id": 6, "book_item_id": 4, "user_id": 3, "reservation_status": "released_by_admin",
                   "attempt_no": null, "released_at": "2026-10-05T12:48:42.197Z", "release_reason": "admin_release" },
  "item_availability_status": "available" }
```

`attempt_no` становится `NULL`, и попытка возвращается покупателю: `attempts_left` снова 3. Повтор по уже
снятому администратором резерву — 200 без изменений. Ошибки: 404 `uniundata_reservation_not_found`;
410 `uniundata_reservation_expired` (`data.reservation_status`: `expired`, `cancelled`,
`converted_to_order`). Резерв, ушедший в заказ, освобождается отменой заказа, а не этим маршрутом.

**`POST /admin/items/{id}/block`** и **`/unblock`** (`manage_book_catalog`; параметры `id`, `reason`)

```json
{ "book_item_id": 5, "availability_status": "blocked", "changed": true }
```

| Переход | Результат |
|---|---|
| `available → blocked`, `blocked → available` | 200, `changed: true`, запись `item.status_changed` в аудите (actor `admin`) |
| Повтор (уже `blocked` / уже `available`) | 200, `changed: false` |
| `reserved`, `checkout_pending` | 409 `uniundata_item_unavailable`: сначала снять резерв (#11) или отменить заказ |
| `sold`, `withdrawn`, `sync_missing` | 409 `uniundata_item_unavailable` (`data.availability_status`) |
| `unblock`, когда `source_status ≠ present` | 409 (`data.source_status`): источник сообщает, что книги нет, и вернуть её в продажу нельзя |

Транзакция берёт `FOR UPDATE` только строки экземпляра (уровень 2 глобального порядка): у `available` и
`blocked` экземпляра нет активного резерва и открытого заказа.

**`POST /admin/sync/run`** (`manage_book_sync`). Параметр `source`: string `^[a-z0-9_]{1,64}$`, по умолчанию
`primary`. Синхронизация идёт минуты, поэтому HTTP-запрос только ставит задачу Action Scheduler
`uniundata_sync_daily` с args `["admin", source]`, флаг `unique`. Ответ **202 Accepted**:

| Ответ | Когда |
|---|---|
| `{"queued": true, "reason": null, "action_id": 7, "running_run": null}` | Задача поставлена |
| `{"queued": false, "reason": "already_queued", …}` | Такая задача уже ждёт или выполняется (AS проверяет уникальность по hook + group + args) |
| `{"queued": false, "reason": "already_running", "running_run": {…}}` | Есть `running`-прогон с heartbeat моложе 10 минут (`uq_sync_runs_one_running`) |
| `{"queued": true, "reason": "stale_running_replaced", …}` | `running`-прогон завис (heartbeat старше 10 мин). Новая задача поставлена, и `SyncService` прервёт зависший прогон |

**`GET /admin/sync/runs`** (`manage_book_sync`): `page`, `per_page` (1…100), `source`, `status`
(`running|succeeded|partial|failed|aborted`). Ответ `{runs:[{id, source_name, triggered_by, status,
started_at, heartbeat_at, finished_at, source_cursor, counters:{records_received, …, errors_count},
error_log:[{external_id, code, message}]}], page, per_page, total}` + `X-WP-Total`.

## 6.4 Формат ошибок

Используется стандартный формат WP REST. Контроллер бросает `DomainError`, `RestController::respond()`
превращает его в `WP_Error`, а фильтр `rest_post_dispatch` добавляет `request_id` и `Retry-After`:

```json
{
  "code": "uniundata_item_unavailable",
  "message": "This book is already reserved or no longer available.",
  "data": {
    "status": 409,
    "book_item_id": 1,
    "availability_status": "reserved",
    "request_id": "6ecb7d09-ce1b-444b-bf4b-ba223bb0aa2f"
  }
}
```

Правила:

- Клиент ветвится по `code`, а не по `message`. `message` предназначен человеку и переводится через text
  domain `uniundata-books`.
- В `data` попадают только ID и коды, без PII и внутренних деталей.
- Любое исключение, кроме `DomainError`, превращается в 500 `uniundata_internal` без текста. В
  `error_log` пишутся класс, код и `файл:строка` с `request_id`; текст исключения — только при `WP_DEBUG`,
  потому что сообщения MySQL содержат значения строк, а ответы банка — данные платежа.

| HTTP | `code` | Источник | Когда | Что делает клиент |
|---|---|---|---|---|
| 400 | `rest_missing_callback_param` / `rest_invalid_param` | WordPress (`args`) | Нет параметра; тип, диапазон, pattern или enum не прошли (`data.params`, `data.details`) | Исправить запрос |
| 400 | `rest_invalid_json` | WordPress | Битый JSON при `Content-Type: application/json` | Исправить запрос |
| 400 | `uniundata_invalid_param` | плагин | Проверка вне схемы (`data.param`) | Исправить или показать форму |
| 401 | `uniundata_auth_required` | плагин | Не вошёл или cookie без nonce (`data.reason = missing_nonce`) | Вход / получить nonce |
| 401 | `uniundata_invalid_signature` | webhook | Подпись или timestamp не прошли | (банк) |
| 403 | `rest_cookie_invalid_nonce` | WordPress | Nonce просрочен или чужой | Обновить nonce, повторить 1 раз |
| 403 | `uniundata_forbidden` | плагин | Нет capability; IP не из allowlist webhook-а | — |
| 404 | `uniundata_item_not_found` | плагин | Экземпляра нет / неактивен / нет в вашей корзине | Обновить статус |
| 404 | `uniundata_order_not_found` | плагин | Нет такого **или чужой** заказ | — |
| 404 | `uniundata_reservation_not_found` | админ | Нет резерва | — |
| 404 | `rest_no_route` | WordPress | Путь не подошёл под regex (в т. ч. неверный `public_order_id`) | — |
| 409 | `uniundata_item_unavailable` | плагин | Экземпляр не `available` | Кнопка → «Зарезервирована» / «Продано» |
| 409 | `uniundata_reservation_limit_reached` | плагин | 3 попытки исчерпаны | Кнопка неактивна навсегда |
| 409 | `uniundata_cart_empty` | плагин | Нечего оформлять | В каталог |
| 409 | `uniundata_cart_changed` | плагин | Позиции истекли или сумма изменилась | Показать корзину, новый `Idempotency-Key` |
| 409 | `uniundata_order_not_payable` | плагин | Заказ нельзя оплатить или отменить (`data.order_status`) | Перечитать заказ |
| 410 | `uniundata_reservation_expired` | плагин | Резерв уже не активен (истёк, отменён, в заказе) | Обновить корзину / статус |
| 413 | — (`{"received": false}`) | webhook | Тело webhook-а больше 64 КБ | (банк) |
| 429 | `uniundata_rate_limited` | плагин | Превышен лимит (`data.retry_after`, `Retry-After`) | Подождать |
| 500 | `uniundata_internal` | плагин | Непредвиденная ошибка | Повторить позже, сообщить `request_id` |
| 502 | `uniundata_payment_provider_error` | плагин | Банк недоступен (`data.public_order_id`) | «Повторить оплату» |
| 503 | `uniundata_conflict_retry` | плагин | Deadlock / lock wait после 3 повторов транзакции | Повторить после `Retry-After` |

## 6.5 Endpoint оплаты `POST /payment/webhook`

Это единственный путь, который делает заказ `paid`. Второй путь к тому же результату — опрос банка cron-ом
(`OrderExpiryService`). Оба вызывают `PaymentService::applyProviderResult()`. Return URL браузера оплату не
подтверждает никогда.

### 6.5.1 Способ аутентификации и почему nonce неприменим

Вызывающая сторона — сервер банка. У него нет учётной записи WordPress, cookie и nonce, а
`get_current_user_id()` равен 0 и нигде не используется. Аутентичность обеспечивают:

1. **Подпись тела** секретом, известным только нам и банку (HMAC-SHA256), или закрытым ключом банка
   (RSA/ECDSA; у нас закреплён его публичный ключ).
2. **Подписанный timestamp** с допуском ±300 с. Он защищает от повтора перехваченного запроса (replay).
3. **Сверка с заказом** под `FOR UPDATE`: `public_order_id`, сумма, валюта, наш `idempotency_key`
   (merchant reference). Если провайдер позволяет, статус `succeeded` дополнительно подтверждается
   серверным запросом `fetchPayment()` к API банка.
4. Необязательно — **IP allowlist или mTLS** (§ 6.5.4).

Почему не nonce:

- Nonce WordPress привязан к пользователю и его сессии (`wp_create_nonce` хэширует user ID и session
  token) и живёт 12–24 часа. У банка нет ни пользователя, ни сессии.
- Nonce защищает от CSRF, то есть от запросов, которые чужой сайт отправляет из браузера жертвы с её
  cookie. Webhook — запрос сервер-сервер без cookie, такой угрозы здесь нет.
- Если «зашить» nonce или токен в callback URL, получится статический секрет, который оседает в логах
  nginx, банка и прокси и не защищает тело от подмены. Подпись, наоборот, связывает секрет с конкретными
  байтами тела и моментом времени.

Nonce и capabilities остаются для **внутренних** запросов WordPress: «Оплатить» и «Отменить» из браузера
(`/orders/{id}/pay`, `/cancel`) идут с cookie + nonce + `create_book_orders`.

### 6.5.2 `permission_callback`

```php
register_rest_route('uniundata/v1', '/payment/webhook', [
    'methods'             => WP_REST_Server::CREATABLE,
    'callback'            => [$this, 'handle'],
    'permission_callback' => [$this, 'permitWebhook'], // транспортный фильтр, без побочных эффектов
    'args'                => [],                       // формат тела — банка; проверяется ПОСЛЕ подписи
    'show_in_index'       => false,
]);

public function permitWebhook(WP_REST_Request $request): bool|WP_Error
{
    $networks = self::allowedNetworks();          // константа UNIUNDATA_WEBHOOK_ALLOWED_IPS, CIDR через запятую
    if ($networks === []) {
        return true;                              // allowlist выключен: аутентичность — подпись в callback
    }
    foreach ($networks as $cidr) {
        if (self::ipInCidr((string) $this->clientIp(), $cidr)) {
            return true;
        }
    }
    return DomainError::forbidden()->toWpError(); // 403
}
```

Криптографическая проверка — **первый шаг callback** (`PaymentService::handleWebhook()`), до любой записи в
БД. Почему не в `permission_callback`:

- `verifyWebhook()` одновременно проверяет подпись и разбирает событие в `ProviderPaymentResult`, а
  результат нужен дальше. Получается одна проверка и один разбор, без повторного HMAC/RSA и без
  «тайного канала» между `permission_callback` и callback.
- Коды ответа банку задаёт единая политика `WebhookResult`: 401 — подпись, 400 — тело, 413 — размер,
  5xx — «повторите». `permission_callback` умеет отдать только «нельзя».
- Безопасность та же: до успешной проверки подписи callback ничего не пишет и не меняет. Строка в
  `wp_book_payment_events` появляется **только после** подписи. Иначе атакующий мог бы заранее «занять»
  `provider_event_id` настоящего события, и оно было бы отброшено как дубль.

`args` пусты намеренно. Схемная валидация WordPress выполняется **до** `permission_callback` и до подписи,
поэтому поля тела проверяет адаптер банка уже после подписи. Одно исключение: при
`Content-Type: application/json` и синтаксически битом теле WordPress сам вернёт 400 `rest_invalid_json`
до нашего кода. Это безопасно, потому что в БД ничего не пишется.

### 6.5.3 Проверка подписи

Правила для адаптера банка (`PaymentProviderInterface::verifyWebhook()`):

- Подпись считается по **сырому телу** `$request->get_body()`, то есть по байтам из `php://input`. Нельзя
  брать `get_json_params()` и сериализовать заново: порядок ключей, пробелы, экранирование `/` и юникода
  изменятся, и HMAC не совпадёт (или совпадёт у подделки, если канонизация неоднозначна).
- Сравнение — только `hash_equals()`. `===` и `strcmp` выходят раньше на первом несовпавшем байте, и по
  времени ответа можно подбирать подпись.
- Timestamp берётся из **подписанной** части (заголовок, входящий в подписываемую строку, или поле тела).
  Допуск ±300 с, иначе 401. `PaymentService` дополнительно перепроверяет `signedAt`, не полагаясь только
  на адаптер.
- Секрет хранится в `wp-config.php` (`define('UNIUNDATA_BANK_WEBHOOK_SECRET', getenv('…'))`) или в
  переменной окружения, но **не** в `wp_options`: options попадают в бэкапы, экспорт и видны любому
  плагину. При ротации адаптер принимает подпись любым из двух секретов (текущим и предыдущим) до конца
  окна ротации.

HMAC-SHA256 (схема `timestamp.body`, reference-реализация `PaymentService::verifyHmacSha256()`):

```php
$ts  = (string) ($headers['x_timestamp'][0] ?? '');   // WP_REST_Request::get_headers(): lower_snake_case, значения — массивы
$sig = (string) ($headers['x_signature'][0] ?? '');
$ok  = ctype_digit($ts)
    && abs(time() - (int) $ts) <= 300
    && preg_match('/^[0-9a-f]{64}$/i', $sig) === 1
    && hash_equals(hash_hmac('sha256', $ts . '.' . $rawBody, UNIUNDATA_BANK_WEBHOOK_SECRET), strtolower($sig));
if (!$ok) {
    throw DomainError::invalidSignature();            // → 401, в БД ничего
}
```

RSA-SHA256 (банк подписывает своим закрытым ключом, у нас закреплён публичный ключ из его документации,
а не загружаемый по URL из запроса):

```php
$publicKey = openssl_pkey_get_public(file_get_contents(UNIUNDATA_BANK_PUBLIC_KEY_PATH));
$signature = base64_decode((string) ($headers['x_signature'][0] ?? ''), true);
$ok = $publicKey !== false
    && $signature !== false
    && abs(time() - (int) $ts) <= 300
    && openssl_verify($ts . '.' . $rawBody, $signature, $publicKey, OPENSSL_ALGO_SHA256) === 1;
```

`openssl_verify()` возвращает `1`, `0` или `-1`/`false` при ошибке, поэтому сравнивать нужно строго с `=== 1`.

### 6.5.4 IP allowlist и mTLS на уровне веб-сервера

Это дополнительный слой: он отсекает мусор до PHP. Подпись он не заменяет, потому что IP-диапазоны
провайдеров меняются.

WordPress принимает маршрут **несколькими путями**: `/wp-json/uniundata/v1/payment/webhook`,
`/?rest_route=/uniundata/v1/payment/webhook`, `/index.php?rest_route=…`. Если allowlist закрывает только
`location` на `/wp-json/…`, его обходят через `?rest_route=`. Поэтому:

```nginx
limit_req_zone $binary_remote_addr zone=uud_webhook:10m rate=20r/s;

location = /wp-json/uniundata/v1/payment/webhook {
    allow 203.0.113.0/24;          # диапазоны из документации банка
    allow 2001:db8:100::/48;
    deny  all;
    client_max_body_size 64k;      # совпадает с лимитом PaymentService (413)
    limit_req zone=uud_webhook burst=50 nodelay;
    try_files $uri /index.php?$args;
}

# Обход через ?rest_route= закрываем. При включённых ЧПУ этот вариант сайту не нужен.
if ($args ~* "rest_route=(/|%2F)uniundata(/|%2F)v1(/|%2F)payment") { return 403; }
```

Надёжнее продублировать allowlist в приложении: `define('UNIUNDATA_WEBHOOK_ALLOWED_IPS',
'203.0.113.0/24, 2001:db8:100::/48');`. `permitWebhook` срабатывает после разрешения маршрута
WordPress-ом, поэтому закрывает **все** варианты URL. Если перед сайтом стоит CDN или балансировщик,
реальный IP возвращает фильтр `uniundata_client_ip` (§ 6.6), а `REMOTE_ADDR` прокси в allowlist не
вносится.

mTLS (если банк предъявляет клиентский сертификат):

```nginx
ssl_client_certificate /etc/nginx/bank-ca.pem;
ssl_verify_client optional;                 # для остального сайта сертификат не нужен
location = /wp-json/uniundata/v1/payment/webhook {
    if ($ssl_client_verify != SUCCESS) { return 403; }
    fastcgi_param SSL_CLIENT_VERIFY $ssl_client_verify;
    # … как выше
}
```

### 6.5.5 Валидация (порядок проверок)

1. Размер тела ≤ 64 КБ → иначе 413 (nginx `client_max_body_size` и `PaymentService`).
2. Подпись и timestamp → иначе 401, без записи в БД. Отказ попадает в `error_log` и в аудит
   (`payment.webhook_rejected`). Чтобы поток мусора не раздул таблицу, запись в аудит ограничена одной в
   минуту через `wp_cache_add`. Это ограничение работает только при персистентном объектном кэше: без
   Redis каждый отказ пишется в аудит, и поток мусора нужно резать `limit_req` на nginx.
3. Разбор тела адаптером: обязательные поля, типы, `amount` int ≥ 0, `currency` `^[A-Z]{3}$`,
   `public_order_id` `^uniundata_…$`, `idempotency_key` UUID, `card_last4` ровно 4 цифры. Это делают
   конструктор `ProviderPaymentResult` и адаптер. Ошибка → 400.
4. `provider` события совпадает с настроенным провайдером → иначе 400.
5. Inbox: `INSERT … ON DUPLICATE KEY UPDATE attempts = attempts + 1` по `(provider, provider_event_id)`.
6. Для `succeeded`, `processing` и `refunded` — подтверждение `fetchPayment()` вне транзакции. Если API
   ещё не видит успеха, ответ 503 и событие получает статус `failed`. Банк повторит доставку, и успех не
   потеряется.
7. Транзакция: `items (по id) → orders → payments → payment_events` `FOR UPDATE`. Под блокировками
   сверяются `public_order_id` / `idempotency_key`, сумма, валюта и допустимость перехода статуса.
   Расхождение суммы или ссылки → заказ получает `needs_attention = 1` (`amount_mismatch`,
   `reference_mismatch`), экземпляры **не** продаются, ответ 200: подпись верна, повтор ничего не изменит,
   разбирается менеджер.

### 6.5.6 Коды ответа и обработка ошибок

Банки повторяют доставку при любом не-2xx, обычно с растущим интервалом в течение суток и дольше.
Поэтому 2xx отдаётся только тогда, когда повтор действительно не нужен.

| Ситуация | HTTP | Тело | Что в БД | Поведение банка |
|---|---|---|---|---|
| Событие применено | 200 | `{"received": true}` | event `processed`, заказ/платёж/экземпляры изменены | Стоп |
| Повтор уже обработанного события | 200 | `{"received": true}` | `attempts + 1` | Стоп |
| Событие валидно, но действий не требует (платёж не наш, статус не поддерживается, уже `succeeded`) | 200 | `{"received": true}` | event `ignored` + причина | Стоп |
| Сумма/ссылка не сходятся | 200 | `{"received": true}` | `needs_attention`, задача менеджеру | Стоп |
| Подпись верна, тело не разбирается | 400 | `{"received": false}` | ничего | Повтор бессмыслен, но банк увидит ошибку в кабинете |
| Битый JSON (`rest_invalid_json`) | 400 | WP_Error | ничего | то же |
| Подпись/timestamp не прошли | 401 | `uniundata_invalid_signature` | ничего (аутентичный повтор не заблокирован) | Повторит |
| IP не из allowlist | 403 | `uniundata_forbidden` | ничего | Повторит |
| Тело > 64 КБ | 413 | `{"received": false}` | ничего | — |
| Deadlock после 3 повторов, API банка недоступно, успех ещё не подтверждён API | 503 + `Retry-After: 30` | `{"received": false}` | event `failed` + `error_message` | Повторит, обработка продолжится с того же места |
| Непредвиденное исключение | 500 | `{"received": false}` | event `failed` (если успел сохраниться) | Повторит |

Обработчик отвечает быстро: одна короткая транзакция. Письма, документы, возвраты и уведомления
менеджеру ставятся в Action Scheduler **после COMMIT** (`uniundata_order_paid`,
`uniundata_refund_payment`, `uniundata_order_needs_attention`) и не задерживают ответ банку.

### 6.5.7 Идемпотентность

| Уровень | Механизм | Что защищает |
|---|---|---|
| Доставка | `wp_book_payment_events` `UNIQUE (provider, provider_event_id)`. Если банк не даёт ID события, ключом служит SHA-256 тела | Повтор того же события → 200 без действий |
| Параллельные доставки | Строка события `FOR UPDATE` внутри транзакции (уровень 7 порядка блокировок) и перепроверка `processing_status` | Две одновременные доставки: вторая ждёт и видит `processed` |
| Платёж | `UNIQUE (provider, provider_payment_id)`, `UNIQUE (idempotency_key)`, переходы условным `UPDATE … WHERE status IN (…)` | Повторный `succeeded` → no-op (`already_succeeded`) |
| Заказ | `FOR UPDATE` + проверка статуса: `paid` не переходит в `paid` повторно | Двойное списание экземпляров невозможно |
| Продажа | `wp_book_sales UNIQUE (book_item_id)` и `UNIQUE (order_item_id)` | Экземпляр продаётся ровно один раз, даже при ошибке логики |
| Второй успешный платёж по оплаченному заказу | payment `succeeded` + `needs_attention = duplicate_payment` + задача возврата | Деньги не теряются, товар не продаётся дважды |
| Поздний платёж после `payment_expired` / `cancelled` | Экземпляры забираются, только если свободны; иначе `late_payment_conflict` + возврат по конфликтным | Чужой резерв не отнимается |
| Задачи после COMMIT | `as_enqueue_async_action(…, unique: true)` + идемпотентные обработчики | Письмо и возврат не дублируются |

### 6.5.8 Журналирование

| Пишем | Куда |
|---|---|
| Одна строка на доставку: `request_id`, `outcome` (`processed|duplicate|ignored|invalid_signature|bad_request|retry`), HTTP-код, ID строки inbox, длительность, IP отправителя (это IP банка, а не персональные данные покупателя), короткая техническая причина | `error_log` (контроллер) |
| Событие: `provider_event_id`, `event_type`, `payload_redacted` (после `PaymentService::redact()`), `payload_sha256` сырого тела, `attempts`, `processing_status`, `error_message` (код, а не текст банка) | `wp_book_payment_events` |
| Переходы `payment.status_changed`, `order.status_changed`, `item.status_changed`, `sale.created` с `from`/`to` и `request_id` | `wp_book_audit_log` (в той же транзакции, что и изменение) |
| Отказы подписи: только код причины, без тела и заголовков | `error_log` + `wp_book_audit_log` (не чаще раза в минуту при персистентном объектном кэше) |

| **Не** пишем никогда | Почему |
|---|---|
| Сырое тело и заголовки `X-Signature`, `Authorization` | Подпись и токены нельзя повторно использовать; тело может содержать PII |
| Секреты, ключи API, webhook secret | Компрометация всего канала |
| PAN, CVV/CVC, срок действия карты, имя держателя | PCI DSS. Из данных карты хранятся только `card_brand` и `card_last4` |
| E-mail, телефон, адрес, имя покупателя из события | Минимизация PII: всё это уже есть в снимке заказа. `redact()` заменяет такие ключи на `[redacted]`, а последовательности 13–19 цифр в значениях на `[pan-redacted]` (проверено: `customer_email` в `payload_redacted` = `"[redacted]"`) |
| Текст исключений адаптера и ответы API банка дословно | В `error_log` пишется класс исключения, текст — только при `WP_DEBUG` |

Срок хранения: `error_log` — ротация 30 дней. `payload_redacted` хранится по требованиям бухгалтерии и
сверки с банком (обычно до 5 лет). Это данные без PII, поэтому их хранение не противоречит минимизации.

### 6.5.9 Чек-лист подключения банка

1. В кабинете банка callback URL = `rest_url('uniundata/v1/payment/webhook')`, только `https://`.
2. Секрет или публичный ключ лежит в `wp-config.php` / переменных окружения. В `wp_options` его нет.
3. Allowlist (nginx + `UNIUNDATA_WEBHOOK_ALLOWED_IPS`), `client_max_body_size 64k`, `limit_req`.
4. Плагины безопасности и CDN не кэшируют и не блокируют `POST /wp-json/uniundata/v1/payment/webhook`.
5. В песочнице банка проверено: валидное событие (200, `paid`); неверная подпись (401); timestamp старше
   5 мин (401); повтор того же события (200, без изменений); `failed` после `succeeded` (no-op); две
   параллельные доставки; событие раньше ответа `createSession` (поиск по `idempotency_key`).

## 6.6 Rate limit

| Действие | На пользователя | На IP | Окно |
|---|---|---|---|
| `reserve` | 20 | 60 | 60 с |
| `remove_item` | 30 | 90 | 60 с |
| `checkout` | 5 | 20 | 60 с |
| `pay`, `cancel` | 10 | 30 | 60 с |
| `availability` (публичный) | — | 300 | 60 с |
| `admin_write` | 60 | — | 60 с |

Реализация (`RestController::enforceRateLimit()`): фиксированное окно, ключ
`uniundata_rl_<wp_hash(действие|субъект)>_<номер окна>`. `wp_hash` — HMAC с солью сайта, поэтому в Redis и
`wp_options` не лежат IP и `user_id` в открытом виде.

- **Персистентный объектный кэш** (Redis/Memcached, `wp_using_ext_object_cache()`): `wp_cache_add` +
  `wp_cache_incr` атомарны, счётчик точный при параллельных запросах. Это рекомендуемый вариант.
- **Без него** — транзиенты в `wp_options`. Read-modify-write не атомарен, и при гонке счётчик может
  недосчитать несколько запросов. Для мягкого лимита это допустимо.
- Превышение → 429 `uniundata_rate_limited`, `data.retry_after` и `Retry-After` = секунды до конца окна.
  На границе окон возможен всплеск до 2× лимита. Это свойство фиксированного окна, и для защиты от
  перебора его хватает.
- Лимит **не** обеспечивает корректность. Один резерв на экземпляр и лимит трёх попыток гарантируют
  транзакция и UNIQUE, лимит запросов только отсекает перебор и зациклившийся JS.
- Значения меняет фильтр `uniundata_rate_limits`. Первый слой — `limit_req` nginx на `/wp-json/`.

IP клиента по умолчанию берётся только из `REMOTE_ADDR`, потому что `X-Forwarded-For` подделывается
клиентом. За доверенным прокси настраивается так:

```php
add_filter('uniundata_client_ip', static function (?string $ip): ?string {
    // Доверяем заголовку, только если запрос пришёл от нашего балансировщика.
    if ($ip !== null && in_array($ip, ['10.0.0.10', '10.0.0.11'], true) && isset($_SERVER['HTTP_X_REAL_IP'])) {
        $real = trim((string) $_SERVER['HTTP_X_REAL_IP']);
        return filter_var($real, FILTER_VALIDATE_IP) !== false ? $real : $ip;
    }
    return $ip;
});
```

## 6.7 Кэширование страниц и `catalog/availability`

Карточки и списки каталога отдаёт page cache или CDN, иначе каталог не выдержит нагрузку. Поэтому HTML может
показывать статус минутной давности, и HTML не может быть источником истины для кнопки.

1. Шаблон выводит `<button class="uud-reserve" data-item-id="777" disabled aria-busy="true">Отложить</button>`
   и статус из кэша как текст. Кнопка **изначально неактивна**.
2. JS собирает `data-item-id` со страницы (до 100 за запрос) и запрашивает
   `GET /catalog/availability?item_ids=…` с `cache: 'no-store'`. Если nonce известен, запрос идёт с
   `X-WP-Nonce`.
3. По ответу кнопка включается (`can_reserve`) или получает текст по `reason`.
4. Статус перезапрашивается при `pageshow` с `persisted` (возврат из bfcache), при
   `visibilitychange → visible` и после любого 409/410 от `reserve`.

Требования к инфраструктуре: CDN и page cache **не** кэшируют `/wp-json/*` и запросы с `rest_route=`
(дублирует `Cache-Control: no-store` на случай кэшей, которые его игнорируют). Ответ `availability` не
кэшируется и в объектном кэше: по нему пользователь принимает решение, и устаревший ответ включил бы
кнопку на проданной книге. Это безопасно для данных (сервер вернёт 409), но плохо для UX.

## 6.8 JS-клиент кнопки «Отложить»

Подключение. Nonce **не** встраивается в HTML: страница может прийти из page cache, и nonce оказался бы
чужим или просроченным. Клиент получает nonce отдельным некэшируемым запросом к штатному
`admin-ajax.php?action=rest-nonce`. Для гостя этот запрос возвращает `0`, и клиент считает его гостем.

```php
add_action('wp_enqueue_scripts', static function (): void {
    wp_enqueue_script('uud-reserve', plugins_url('assets/reserve.js', UNIUNDATA_BOOKS_FILE), [], '1.0.0', ['in_footer' => true, 'strategy' => 'defer']);
    wp_add_inline_script('uud-reserve', 'window.uudRest = ' . wp_json_encode([
        'root'     => esc_url_raw(rest_url('uniundata/v1/')),
        'ajaxUrl'  => esc_url_raw(admin_url('admin-ajax.php')),
        'loginUrl' => esc_url_raw(wp_login_url()),
    ]) . ';', 'before'); // только неизменяемые URL: безопасно для page cache
});
```

```js
// assets/reserve.js
(() => {
  const cfg = window.uudRest;
  let nonce = null;             // null — ещё не запрашивали, '' — гость
  const LABELS = {
    available: 'Отложить', reserved: 'Зарезервирована', sold: 'Продано', unavailable: 'Недоступна',
    not_found: 'Недоступна', login_required: 'Войдите, чтобы отложить', forbidden: 'Недоступно для вашей роли',
    limit_reached: 'Лимит резервов исчерпан', in_your_cart: 'В корзине', in_your_order: 'В оформленном заказе',
  };

  async function getNonce(force = false) {
    if (nonce !== null && !force) return nonce;
    try {
      const r = await fetch(`${cfg.ajaxUrl}?action=rest-nonce`, { credentials: 'same-origin', cache: 'no-store' });
      const text = (await r.text()).trim();
      nonce = r.ok && /^[a-f0-9]{10}$/.test(text) ? text : '';
    } catch { nonce = ''; }
    return nonce;
  }

  /** fetch к REST: cookie + X-WP-Nonce, обновление nonce из ответа, один повтор при просроченном nonce. */
  async function api(path, { method = 'GET', body, retried = false } = {}) {
    const n = await getNonce();
    const headers = { Accept: 'application/json' };
    if (n) headers['X-WP-Nonce'] = n;
    if (body !== undefined) headers['Content-Type'] = 'application/json';
    const res = await fetch(cfg.root + path, {
      method, headers, credentials: 'same-origin', cache: 'no-store',
      body: body === undefined ? undefined : JSON.stringify(body),
    });
    const fresh = res.headers.get('X-WP-Nonce');
    if (fresh) nonce = fresh;
    const data = await res.json().catch(() => null);
    if (!retried && res.status === 403 && data?.code === 'rest_cookie_invalid_nonce') {
      await getNonce(true);
      return api(path, { method, body, retried: true });
    }
    return { status: res.status, data, retryAfter: Number(res.headers.get('Retry-After')) || 0 };
  }

  function render(btn, s) {
    btn.removeAttribute('aria-busy');
    btn.disabled = !s.can_reserve;
    btn.dataset.state = s.reason ?? 'available';
    let label = LABELS[s.reason ?? s.status] ?? LABELS.unavailable;
    if (s.held_by_me === 'cart' && s.expires_at) {
      label += ' до ' + new Date(s.expires_at).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
    }
    btn.textContent = label;
  }

  async function refreshAvailability() {
    const buttons = [...document.querySelectorAll('.uud-reserve[data-item-id]')];
    for (let i = 0; i < buttons.length; i += 100) {
      const chunk = buttons.slice(i, i + 100);
      const ids = [...new Set(chunk.map(b => b.dataset.itemId))].join(',');
      const { status, data } = await api(`catalog/availability?item_ids=${encodeURIComponent(ids)}`);
      if (status !== 200) continue; // кнопки остаются неактивными — безопасное состояние
      const byId = new Map(data.items.map(s => [String(s.book_item_id), s]));
      chunk.forEach(b => byId.has(b.dataset.itemId) && render(b, byId.get(b.dataset.itemId)));
    }
  }

  async function reserve(btn) {
    if (btn.dataset.busy) return;            // защита от двойного клика (сервер и так идемпотентен)
    btn.dataset.busy = '1';
    btn.disabled = true;
    btn.setAttribute('aria-busy', 'true');
    try {
      const { status, data, retryAfter } = await api('cart/reserve', {
        method: 'POST', body: { book_item_id: Number(btn.dataset.itemId) },
      });
      switch (status) {
        case 200: case 201: {                 // 201 — новый резерв, 200 — уже ваш
          render(btn, { can_reserve: false, reason: 'in_your_cart', held_by_me: 'cart', expires_at: data.reservation.expires_at });
          document.dispatchEvent(new CustomEvent('uud:cart', { detail: data.cart }));
          return;
        }
        case 401:
          location.assign(`${cfg.loginUrl}?redirect_to=${encodeURIComponent(location.href)}`);
          return;
        case 409:                              // занята другим, продана, лимит попыток
          render(btn, { can_reserve: false, reason: data?.code === 'uniundata_reservation_limit_reached' ? 'limit_reached' : 'reserved' });
          await refreshAvailability();         // точный статус (reserved / sold / unavailable)
          return;
        case 404: case 410:                    // экземпляр снят или резерв уже не активен
          await refreshAvailability();
          return;
        case 429: case 503: {                  // лимит запросов / временный конфликт блокировок
          const wait = Math.max(1, retryAfter || 5);
          btn.textContent = `Повторите через ${wait} с`;
          setTimeout(refreshAvailability, wait * 1000);
          return;
        }
        default:
          btn.textContent = 'Ошибка, попробуйте ещё раз';
          setTimeout(refreshAvailability, 3000);
      }
    } catch {                                  // сеть: результат неизвестен — спросить сервер
      await refreshAvailability();
    } finally {
      delete btn.dataset.busy;
    }
  }

  document.addEventListener('click', e => {
    const btn = e.target.closest('.uud-reserve[data-item-id]');
    if (btn && !btn.disabled) reserve(btn);
  });
  window.addEventListener('pageshow', e => { if (e.persisted) refreshAvailability(); });
  document.addEventListener('visibilitychange', () => { if (document.visibilityState === 'visible') refreshAvailability(); });
  refreshAvailability();
})();
```

Свойства клиента:

- Кнопка не включается без ответа сервера. При сетевой ошибке или 5xx она остаётся неактивной.
- Повторный клик и повторный запрос безопасны: сервер вернёт 200 с тем же резервом.
- После 409/410 клиент не угадывает статус, а перечитывает его через `availability`, потому что HTML
  страницы мог прийти из page cache.
- Просроченный nonce (вкладка открыта сутки) обновляется автоматически, запрос повторяется один раз.

## 6.9 Регистрация и файлы

```php
// src/Plugin.php (фрагмент): один экземпляр Db/AuditLog на запрос, контроллеры — на rest_api_init.
add_action('rest_api_init', function (): void {
    global $wpdb;
    $db       = new Db($wpdb);
    $audit    = new AuditLog($db);
    $provider = $this->paymentProvider();               // адаптер банка, секреты из wp-config.php
    $reserve  = new ReservationService($db, $audit);
    $checkout = new CheckoutService($db, $audit, $provider);
    $payments = new PaymentService($db, $audit, $provider);

    foreach ([
        new CatalogController($audit, $db),
        new CartController($audit, $reserve),
        new CheckoutController($audit, $checkout),
        new OrderController($audit, $checkout, $db),
        new PaymentWebhookController($audit, $payments),
        new AdminController($audit, $db, $reserve),
    ] as $controller) {
        $controller->register_routes();
    }
});
```

| Файл | Ответственность |
|---|---|
| `src/Rest/RestController.php` | База: `respond()` (`DomainError` → `WP_Error`, остальное → 500), фильтр `rest_post_dispatch` (no-store, `X-Request-Id`, `data.request_id`, `Retry-After`), `requireCapability()` / `requireLogin()` (401 + `missing_nonce`, 403), rate limit, `clientIp()`, общие `args` |
| `src/Rest/CatalogController.php` | `GET /catalog/availability` |
| `src/Rest/CartController.php` | `GET /cart`, `POST /cart/reserve`, `POST /cart/remove-item` → `ReservationService` |
| `src/Rest/CheckoutController.php` | `POST /checkout` (`Idempotency-Key`, JSON-схема адреса) → `CheckoutRequest` → `CheckoutService::checkout()` |
| `src/Rest/OrderController.php` | `GET /orders`, `GET /orders/{id}` (read model, `view_book_order`), `pay`, `cancel` → `CheckoutService` |
| `src/Rest/PaymentWebhookController.php` | `POST /payment/webhook` → `PaymentService::handleWebhook()`, IP allowlist, журнал доставок |
| `src/Rest/AdminController.php` | release, block/unblock (транзакция с `FOR UPDATE` экземпляра + аудит), sync run (Action Scheduler) и журнал синхронизаций |

## 6.10 Что проверено на стенде

Стенд: WordPress 7.1.2 (composer `johnpbloch/wordpress-core`), MySQL 8.0.46 с `sql/schema.sql`, PHP 8.3
built-in server, Action Scheduler 4.2, сервисы из `src/Service`, фейковый адаптер банка с HMAC-подписью.
Запросы отправлялись по HTTP через curl с настоящими cookie и nonce.

| Сценарий | Результат |
|---|---|
| Гость / cookie без nonce / неверный nonce / покупатель / менеджер каталога на `GET /cart` | 401 / 401 + `missing_nonce` / 403 `rest_cookie_invalid_nonce` / 200 / 403 `uniundata_forbidden` |
| `availability` гостем и покупателем, `item_ids=0,abc`, без `item_ids` | 200 (`login_required`, `in_your_cart`, `limit_reached`, `in_your_order`, `sold`, `unavailable`, `not_found`) / 400 / 400 |
| `reserve`: новый, повтор, чужой, проданный, несуществующий, `"abc"` | 201 / 200 `created:false` / 409 / 409 / 404 / 400 |
| Два покупателя одновременно на один экземпляр | 201 + 409, одна активная строка |
| 3 × (reserve + remove), затем reserve | 201/200 ×3, затем 409 `reservation_limit_reached`; `availability` → `limit_reached` |
| `checkout`: без ключа, form-data, неверная сумма, старая оферта, лишнее поле адреса, успех, повтор ключа, повтор с другой суммой, пустая корзина, истёкшая позиция | 400 / 400 / 409 `total_mismatch` / 400 `current_version` / 400 / 201 + `Location` / 200 `replayed` / 400 / 409 `cart_empty` / 409 `positions_expired` |
| 6-й `checkout` за минуту | 429 + `Retry-After` |
| Банк недоступен при checkout → `pay` после восстановления → `cancel` ×2 | 502 с `public_order_id` → 200 с `redirect_url` → 200 `changed:true` → 200 |
| Чужой, несуществующий и кривой `public_order_id`; `pay` чужого; `cancel` менеджером | 404 / 404 / 404 / 404 / 403 |
| Webhook: неверная подпись, timestamp −1000 с, битый JSON, подписанный мусор, успех, повтор | 401 / 401 / 400 / 400 / 200 (`paid`, экземпляры `sold`, 2 строки `wp_book_sales`) / 200 `duplicate` (`attempts = 2`) |
| Admin: release чужим покупателем, менеджером, повтор, по отменённому, несуществующий | 403 / 200 (`attempts_left` снова 3) / 200 / 410 / 404 |
| Admin: block зарезервированного, менеджером заказов, успех, повтор, reserve заблокированного, unblock, unblock `sync_missing` | 409 / 403 / 200 / 200 `changed:false` / 409 / 200 / 409 |
| `sync/run` ×2, неверный `source`, при `running`-прогоне; `sync/runs` с фильтрами | 202 `queued` → 202 `already_queued` / 400 / 202 `already_running` / 200 + `X-WP-Total` |
| Непредвиденная ошибка (нет option `uniundata_terms_versions`) | 500 `uniundata_internal` без текста; в `error_log` `request_id`, класс и `файл:строка` |
| `Cache-Control: no-store` и `X-Request-Id` на всех ответах, включая 400/401/403/404 ядра | да |
| `EXPLAIN` запросов `availability` на 20 000 экземпляров | `items` — `range PRIMARY`, `records` — `eq_ref PRIMARY`; попытки — `range uq_reservations_attempt`; список заказов — `ref ix_orders_user` |
