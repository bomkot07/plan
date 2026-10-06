# 7. Пользователи, роли, capabilities и персональные данные

> **Кратко.** shop.libsmr.ru и new.libsmr.ru — две отдельные установки WordPress, у каждой свои `wp_users`.
> Покупатель **регистрируется в магазине**; `user_id` во всех таблицах магазина — `wp_users.ID` магазина,
> своей таблицы пользователей нет. Роли: `book_customer` и две роли персонала, доступ к заказу —
> мета-capability `view_book_order` через `map_meta_cap`. Имя и фамилия — в `wp_usermeta`, телефон, адреса
> и реквизиты — в `wp_book_customer_profiles`, согласия — в `wp_book_user_consents`, заказ хранит снимок
> данных покупателя. ПДн — по 152-ФЗ (GDPR — вариант). Строки заказов не удаляются: контакты обезличиваются
> по сроку (`orders.pii_erased_at`, задача `uniundata_privacy_retention`). Единый вход с new.libsmr.ru —
> опция (§ 7.13).

## 7.1 Принципы

1. **Одна идентичность — `wp_users` магазина.** Вход, пароли, восстановление, Application Passwords и
   подтверждение смены email делает ядро. Плагин ссылается на `wp_users.ID` без FOREIGN KEY (§ 7.12).
2. **Права — только capabilities.** Каждый REST-маршрут и экран проверяет `current_user_can()`; ID
   пользователя берётся из `get_current_user_id()`, никогда из параметров.
3. **Роль покупателя — `book_customer`**, а не `customer` WooCommerce (её семантикой управляет WC).
4. **Минимизация ПДн.** Собирается только нужное для заказа, чека и бухгалтерии; в заказ копируется снимок;
   журналы и аудит хранят `user_id` и ID сущностей, но не значения ПДн.

## 7.2 Роли и capabilities

Карта ролей — `src/Install/Roles.php` (`Roles::ROLES`).

| Capability | Гость | `book_customer` | `book_catalog_manager` | `book_order_manager` | `administrator` | Назначение |
|---|---|---|---|---|---|---|
| `read` | — | + | + | + | + | Базовое право вошедшего (ядро) |
| `view_book_catalog` | — ¹ | + | + | + | + | Режим закрытой витрины (¹) |
| `reserve_books` | — | + | — | — | + | `POST /cart/reserve`, `/cart/remove-item`, `GET /cart` |
| `create_book_orders` | — | + | — | — | + | `POST /checkout`, `/orders/{id}/pay`, `/orders/{id}/cancel` (только свои) |
| `view_own_book_orders` | — | + | — | + | + | `GET /orders`, свой заказ через `view_book_order` |
| `manage_book_catalog` | — | — | + | — | + | Записи и экземпляры, `POST /admin/items/{id}/block`, `…/unblock` |
| `manage_book_sync` | — | — | + | — | + | `POST /admin/sync/run`, `GET /admin/sync/runs` |
| `manage_book_orders` | — | — | — | + | + | Любой заказ, `POST /admin/orders/{id}/refunds`, разбор `needs_attention` |
| `manage_book_reservations` | — | — | — | + | + | `POST /admin/reservations/{id}/release` |
| `view_book_order` (мета, + ID заказа) | — | свой | — | любой | любой | § 7.4 |

¹ Гостю capability назначить нельзя (§ 7.3): каталог, карточки и `GET /catalog/availability` публичны и
`view_book_catalog` не проверяют. Право зарезервировано для закрытой витрины (предпросмотр для
зарегистрированных, B2B) — в примерах не реализовано (†).

Действия персонала (как в `src/Rest/AdminController.php` и [06](06-rest-api.md) § 6.3.8):

| Действие | Требуется | Поведение |
|---|---|---|
| Заблокировать `available` | `manage_book_catalog` | `available → blocked` |
| Заблокировать в чужой корзине (`reserved`) | `manage_book_catalog` **и** `manage_book_reservations` | В одной транзакции: резерв → `released_by_admin` (попытка не считается), экземпляр → `blocked`. Без второго права — 403 с `data.required_capability` |
| Заблокировать `checkout_pending` | — | 409 `uniundata_item_unavailable`, `data.required_action = cancel_order`: экземпляр держит заказ |
| Разблокировать | `manage_book_catalog` | `blocked →` release target по `source_status` (`available`, `sync_missing`, `withdrawn`) |
| Возврат денег | `manage_book_orders` | Строка `wp_book_refunds` + задача `uniundata_refund_payment`, ответ 202 |
| Настройки (валюта, TTL оплаты, версии документов) | `manage_options` | Только администратор |
| Экспорт/удаление ПДн («Инструменты → Приватность») | `export_others_personal_data` / `erase_others_personal_data` | Ядро сводит их к `manage_options`; менеджеры запросы субъектов не обрабатывают |

- **Персонал не покупает служебной учётной записью**: у менеджеров нет `reserve_books`. Сотруднику-покупателю
  добавляют вторую роль (`wp user add-role <id> book_customer`); для этого у менеджера заказов есть
  `view_own_book_orders`.
- У менеджеров нет `list_users`/`edit_users`: данные покупателя они видят только в снимке заказа.
  Менеджеру каталога, загружающему обложки, дополнительно выдаётся `upload_files`.

## 7.3 Почему гостю нельзя выдать capability

Capabilities в WordPress принадлежат пользователю (usermeta `{prefix}capabilities` конкретного
`wp_users.ID`). Гость — `WP_User` с `ID = 0` без ролей; для него `current_user_can()` истинна только для
`exist`. Подмешать права через `user_has_cap` можно, но тогда любой плагин с той же capability откроет
гостям свою функциональность, а права гостя станут невидимыми в `wp role list`.

Поэтому публичные маршруты объявлены с `'permission_callback' => '__return_true'` (каталог); всё, что меняет
состояние, требует входа — 401 `uniundata_auth_required`, затем 403 `uniundata_forbidden` без capability
(`RestController::requireCapability()`). Гость видит вместо «Отложить» ссылку на `wp_login_url()`.

## 7.4 Мета-capability `view_book_order` и `map_meta_cap`

`view_book_order` не назначена ни одной роли; её разворачивает `Plugin::mapMetaCap()` (фильтр
`map_meta_cap`, 10, 4):

```php
if ($cap === 'view_book_order') {
    $orderId = (int) ($args[0] ?? 0);
    if ($userId <= 0 || $orderId <= 0 || !$this->schemaReady) {
        return ['do_not_allow'];
    }
    $owner = $this->orderOwner($orderId);   // SELECT user_id … WHERE id = %d, кэш на время запроса
    if ($owner === null) {
        return ['do_not_allow'];
    }
    return [$owner === $userId ? 'view_own_book_orders' : 'manage_book_orders'];
}
if ($cap === 'delete_user' && isset($args[0]) && (int) $args[0] > 0 && $this->schemaReady) {
    return $this->hasMoneyInFlight((int) $args[0]) ? ['do_not_allow'] : $caps;   // § 7.12
}
```

`GET /orders/{public_order_id}` (`OrderController::getOrder()`): `permission_callback` — только вход
(владельца до загрузки заказа не проверить); отсутствующий и чужой заказ дают **одинаковый 404**
`uniundata_order_not_found`:

```php
if ($order === null || !current_user_can('view_book_order', (int) $order['id'])) {
    throw DomainError::orderNotFound();
}
return $this->present($order, current_user_can('manage_book_orders'));
```

Read model заказа — `OrderController::present($order, $asManager)`: покупателю — позиции, суммы, последняя
попытка оплаты (бренд и last4 карты), снимок покупателя и адресов; менеджеру дополнительно `needs_attention`,
`attention_reason`, `user_id`, все платежи и возвраты. После обезличивания (`pii_erased = true`) блоки
`customer` и адресов не отдаются никому.

`POST /orders/{id}/pay` и `/cancel` требуют `create_book_orders` и строгого владения: `CheckoutService`
ищет заказ по `public_order_id` **и** `user_id`. `do_not_allow` отказывает даже суперадминистратору.

## 7.5 Регистрация ролей и версионирование

Роли хранятся в option `{prefix}user_roles`; `add_role()` не меняет существующую роль, а каждый
`add_cap()` перезаписывает всю option. Поэтому БД приводится к карте из кода **по версии**: `Roles::install()`
при активации и в `wp uniundata migrate`, `Roles::maybeUpgrade()` на `init`, если
`uniundata_roles_version < Roles::VERSION`. `install()` идемпотентна и меняет `default_role` с `subscriber` на
`book_customer` (явный выбор администратора не трогает).

| Изменение | Действие |
|---|---|
| Новая capability или роль | Изменить `ROLES`, увеличить `VERSION` — права добавятся на первом `init` |
| Право отозвано | То же; снимаются только права из `PLUGIN_CAPS`, права ядра не трогаются |
| Переименование capability | Две версии: добавить новую и проверять обе, затем снять старую |
| Деактивация | Роли остаются (иначе пользователи остались бы с несуществующей ролью) |
| Удаление плагина | `Roles::uninstall()`: пользователи → `subscriber`, роли удалены; таблицы заказов остаются |

Проверка: `wp role list`, `wp cap list book_customer`.

Покупатели не работают в `/wp-admin/` (†): профиль и заказы — на странице аккаунта витрины, консоль для
пользователя без прав персонала (`edit_posts`, `manage_book_*`, `manage_options`) перенаправляется на
`/account/` на `admin_init`, кроме `admin-ajax.php` (нужен для `action=rest-nonce`) и `admin-post.php`.

## 7.6 Какие данные нужны для оформления заказа

`POST /checkout` принимает `customer {first_name, last_name, middle_name?, phone?}`; недостающее
`CheckoutService` берёт из профиля (usermeta, `wp_book_customer_profiles.phone_e164`). Нет имени или фамилии
ни там, ни там — 400 `uniundata_invalid_param` (`param = customer.first_name|last_name`).

| Данные | Обязательно | Где живёт | Снимок в заказе |
|---|---|---|---|
| ID пользователя | да | `wp_users.ID` | `orders.user_id` |
| Email | да: уведомления, электронный чек 54-ФЗ | `wp_users.user_email` | `customer_email` |
| Имя, фамилия | да: документы, получатель | usermeta `first_name`, `last_name` | `customer_first_name`, `customer_last_name` |
| Отчество | **нет** | usermeta `middle_name` | `customer_middle_name` (NULL) |
| `display_name` | нет, только интерфейс | `wp_users` | — |
| Телефон | если требует доставка | `profiles.phone_e164` | `customer_phone` (E.164) |
| Адрес доставки | только при доставке | `profiles.default_shipping_address` — для автозаполнения | `shipping_address_json` — **источник истины** заказа |
| Платёжный адрес | по требованию бухгалтерии | `profiles.default_billing_address` | `billing_address_json` |
| Организация, ИНН/VAT ID | только B2B | `profiles.company_name`, `vat_id` | `company` в адресе (§ 7.9) |
| Согласия: оферта, обработка ПДн | да, на каждый заказ | `wp_book_user_consents` | `orders.offer_consent_id` |
| Согласие на рассылку | нет, галочка не отмечена заранее | `wp_book_user_consents` | — |
| IP, хэш User-Agent | нет, доказательство согласия | `consents.ip_address`, `user_agent_sha256` | — |

**Не собираем:** дату рождения, пол, паспорт, данные карты (у нас только `card_brand`, `card_last4`).

Адрес — белый список `CheckoutRequest::ADDRESS_FIELDS` (`first_name`, `last_name`, `company`, `line1`,
`line2`, `city`, `region`, `postcode`, `country`); обязательны `line1`, `city`, `postcode`, `country`
(ISO 3166-1 alpha-2). Лишние ключи отбрасываются, управляющие символы удаляются, длины проверяются до SQL;
HTML экранируется при выводе.

## 7.7 Что хранить в `wp_usermeta`, а что — в таблицах магазина

| Хранилище | Поля | Почему здесь |
|---|---|---|
| `wp_users` | `ID`, `user_email`, `user_login`, `user_pass`, `display_name` | Аутентификация ядра |
| `wp_usermeta` (ядро) | `first_name`, `last_name`, `{prefix}capabilities` | Стандартный профиль: показывает, экспортирует и удаляет ядро |
| `wp_usermeta` (плагин) | `middle_name`, `uniundata_erasure_requested_at` (метка § 7.11) | Скаляры без поиска и истории. `middle_name` — `register_meta()` с санитизацией и `auth_callback` (`Plugin::registerUserMeta()`) |
| `wp_book_customer_profiles` | `phone_e164`, `phone_verified_at`, адреса по умолчанию, `company_name`, `vat_id` | Строгий формат (CHECK `^\+[1-9][0-9]{6,14}$`), индекс `ix_profiles_phone` для поиска (у `meta_value` индекса нет), одна строка на пользователя, удаляется одним `DELETE` |
| `wp_book_user_consents` | Тип, версия и SHA-256 документа, время, IP, хэш UA, отзыв | Юридическое доказательство: append-only |
| `wp_book_orders` | Снимок контактов, ФИО, адресов, ссылка на согласие | Заказ — документ, правки профиля его не меняют |

Телефон нормализуется в E.164 на сервере (`CheckoutRequest::normalizePhone()`); CHECK в БД — второй рубеж.
Изменения профиля (†) пишутся в аудит списком изменённых полей **без значений**.

## 7.8 Согласия и юридические документы

| Документ | Реализация |
|---|---|
| **Оферта** (договор купли-продажи) — основание обработки для исполнения заказа (152-ФЗ ст. 6 ч. 1 п. 5) | Строка `offer` на каждый заказ, ссылка `orders.offer_consent_id` |
| **Согласие на обработку ПДн** — с 01.09.2025 оформляется **отдельно** от иных подтверждаемых документов (ст. 9 ч. 1) | Отдельный текст и отдельная галочка, строка `privacy` на каждый заказ |
| **Политика обработки ПДн** — публикуется (ст. 18.1 ч. 2), с ней знакомят | Ссылка в формах регистрации и checkout |
| **Согласие на рассылку** (закон «О рекламе», ст. 18 ч. 1) | Строка `marketing`, отзыв — `withdrawn_at` |

Строка согласия: `consent_uuid` (технический идентификатор), `consent_type`, `document_version` и
`document_sha256` (хэш точного текста редакции), `accepted_at` (время сервера БД), `ip_address`
(`INET6_ATON()`, обнуляется через 180 дней), `user_agent_sha256`, `withdrawn_at`.

Текущие редакции — option `uniundata_terms_versions` (пока не заполнена — уведомление администратору, checkout
отвечает 500 `uniundata_internal`):

```bash
wp option update uniundata_terms_versions --format=json \
  '{"offer":{"version":"2026-09","sha256":"<64 hex>","url":"https://shop.libsmr.ru/legal/offer-2026-09.pdf"},
    "privacy":{"version":"2026-09","sha256":"<64 hex>","url":"https://shop.libsmr.ru/legal/pd-consent-2026-09.pdf"}}'
```

Тексты всех редакций хранятся неизменяемыми файлами (ревизии страниц WordPress можно удалить). `POST
/checkout` передаёт `accept_offer_version` и `accept_privacy_version`; несовпадение с текущей редакцией —
409 `uniundata_terms_outdated` с `data.current_versions`. Обе строки вставляются в транзакции заказа до
`INSERT` заказа (нужен `offer_consent_id`); таблица append-only, `FOR UPDATE` по ней не берётся. IP
проверяется в PHP (`FILTER_VALIDATE_IP`) — в strict-режиме `INET6_ATON('garbage')` даёт ошибку 1411;
источник — `REMOTE_ADDR` или фильтр `uniundata_client_ip` за доверенным прокси.

## 7.9 Реквизиты для B2B

В профиле — `company_name` и `vat_id` (в РФ — ИНН, 10 или 12 цифр). В v1 в снимок заказа попадает только
`company` платёжного адреса; ИНН в снимке появится вместе с выставлением счетов юрлицам (расширение белого
списка `CheckoutRequest`). Проверка реквизитов во внешних сервисах — до транзакции checkout, с таймаутом.
ФИО контактного лица и ИНН ИП — ПДн, поэтому B2B-поля проходят тот же эрейзер и сроки.

## 7.10 Персональные данные: 152-ФЗ (основной вариант) и GDPR

Магазин — оператор ПДн по праву РФ. Плагин закрывает техническую часть; организационную (ответственный,
локальные акты, модель угроз, уровень защищённости ИСПДн, ст. 18.1, 19, 22.1) делает заказчик, формулировки
и сроки утверждает его юрист.

| Требование 152-ФЗ | Что сделать | Где в проекте |
|---|---|---|
| Правовые основания (ст. 6) | Исполнение договора — оферта; бухгалтерия и чеки 54-ФЗ — обязанность по закону; рассылка — только согласие | § 7.8 |
| **Отдельное согласие** (ст. 9 ч. 1) | Отдельный документ и галочка, не отмеченная заранее; доказательство — версия и хэш текста, время, пользователь | `wp_book_user_consents` (`privacy`) |
| **Локализация** (ст. 18 ч. 5) | Запись и хранение ПДн граждан РФ — в БД на территории РФ: MySQL магазина, бэкапы, staging, копии разработчиков. Зарубежные сервисы с ПДн (почтовые SaaS, трекеры ошибок, аналитика) — трансграничная передача (ст. 12) с уведомлением РКН до её начала | Плагин передаёт ПДн только банку и через него ОФД (чек 54-ФЗ). Логи, аудит, алерты — без ПДн |
| **Уведомление РКН** (ст. 22) | До начала обработки; об изменениях — не позднее 15-го числа следующего месяца. Цели, категории и сроки — таблица ниже | Организационно |
| **Сроки хранения** (ст. 5 ч. 7, ст. 21 ч. 4–5) | Хранить не дольше цели; по достижении цели или отзыву согласия — уничтожить или обезличить в 30 дней, если нет другого основания (договор, бухгалтерия по 402-ФЗ — не менее 5 лет) | Эрейзер и задача `uniundata_privacy_retention` (§ 7.11) |
| Запросы субъекта (ст. 14, 20) | Ответ в 10 рабочих дней (+5 с мотивированным уведомлением) | Экспортеры (§ 7.11) |
| Утечка (ст. 21 ч. 3.1) | Уведомить РКН в 24 часа, о результатах расследования — в 72 часа | Аудит и логи с `request_id` для оценки объёма |

**GDPR** (если магазин начнёт продавать в ЕС): механизмы те же. Основания — договор Art. 6(1)(b),
бухгалтерия 6(1)(c), рассылка — согласие 6(1)(a); строка `privacy` означает ознакомление с уведомлением
Art. 13, а не согласие. Экспортер покрывает Art. 15/20, эрейзер — Art. 17 (бухгалтерия остаётся по
Art. 17(3)(b)). Срок ответа — месяц, об утечке — надзорному органу за 72 часа; локализации нет, передача за
пределы ЕЭЗ — по главе V.

| Данные | Где | Цель | Срок по умолчанию | Механизм |
|---|---|---|---|---|
| Аккаунт (email, ФИО) | `wp_users`, `wp_usermeta` | Вход, связь | Пока есть аккаунт | Удаление аккаунта |
| Профиль (телефон, адреса, реквизиты) | `wp_book_customer_profiles` | Автозаполнение | Пока есть аккаунт | Эрейзер / удаление → `DELETE` |
| Контакты в заказе (email, телефон, адрес доставки) | `wp_book_orders` | Исполнение, претензии | 730 дней после закрытия; раньше — по запросу или при удалении аккаунта | Этап 1, `pii_erased_at` |
| ФИО и платёжный адрес в заказе | `wp_book_orders` | Бухгалтерский документ | 10 лет (заглушка; 402-ФЗ — ≥ 5 лет после отчётного года) | Этап 2 |
| Согласия (без IP) | `wp_book_user_consents` | Доказательство договора и согласия | Как у заказа | `user_id` псевдонимный |
| IP и хэш UA согласия | `wp_book_user_consents` | Вспомогательное доказательство | 180 дней | Ежедневное обнуление |
| Резервы, корзины, продажи | Свои таблицы | Лимит попыток, учёт | Бессрочно | ПДн нет, только `user_id` |
| Платежи и события банка | `wp_book_payments`, `_payment_events` | Сверка, бухгалтерия | Как у заказа | `card_brand`, `card_last4`; payload без PAN/CVV |
| Аудит | `wp_book_audit_log` | Безопасность | Финансовые события — как заказ, прочие — 2 года (†) | Значений ПДн нет |
| Логи Apache/PHP | Вне БД | Эксплуатация | 14–30 дней | logrotate; тела checkout и webhook не логируются |

Сроки — константы `Plugin::RETENTION_CONTACT_DAYS` (730), `RETENTION_CONSENT_IP_DAYS` (180),
`RETENTION_ACCOUNTING_YEARS` (10); фильтры `uniundata_retention_contact_days`,
`uniundata_consent_ip_retention_days`, `uniundata_retention_accounting_years`.

Правила кода: REST не раскрывает, **кто** зарезервировал экземпляр, чужой заказ — 404; письма менеджерам
(`uniundata_order_needs_attention`, алерты синхронизации) содержат номер и статус, но не адрес и телефон; в
`audit_log.context` и логах — только ID; после `pii_erased_at` письма покупателю и контакты для чека
возврата не отправляются (`Scheduler::orderPaid()`, `PaymentService`).

## 7.11 Экспорт, удаление по запросу и сроки хранения

Плагин встраивается в штатный механизм «Инструменты → Экспорт / Удаление персональных данных»: субъект
подтверждает запрос по ссылке из письма, ядро вызывает экспортеры и эрейзеры.

**Экспорт** (ядро само выгружает `wp_users` и стандартную usermeta):

| Экспортер | Метод | Что входит |
|---|---|---|
| `uniundata-books-profile` | `Plugin::exportProfile()` | `middle_name`, телефон, адреса по умолчанию, организация, ИНН; все согласия (тип, версия, время, отзыв, IP, пока хранится) |
| `uniundata-books-orders` | `Plugin::exportOrders()` | По 50 заказов на страницу: номер, статус, даты, сумма, снимок покупателя и адресов, книги |

История резервов пока не выгружается; для полного ответа по ст. 14 её стоит добавить третьим экспортером.

**Удаление** — эрейзер `uniundata-books` (`Plugin::erasePersonalData()`). Заказы, платежи и продажи —
бухгалтерские документы, их строки не удаляются: эрейзер удаляет что можно и обезличивает остальное.
**Аккаунт он не удаляет** — полное «удалите мои данные» = эрейзер + удаление аккаунта (§ 7.12).

| Что | При запросе | Позже |
|---|---|---|
| `wp_book_customer_profiles`, usermeta `middle_name` | `DELETE` | — |
| Согласия `marketing` | `withdrawn_at = UTC_TIMESTAMP(6)` | — |
| IP и хэш UA во всех согласиях | `NULL` | — |
| Закрытые заказы | **Этап 1**: email → `erased-<id>@invalid.invalid` (колонка NOT NULL, зона `.invalid` по RFC 2606), телефон → `NULL`, адрес доставки → только страна, `pii_erased_at = UTC_TIMESTAMP(6)` | **Этап 2** после бухгалтерского срока: ФИО → `Anonymized`, отчество → `NULL`, платёжный адрес → только страна |
| Незакрытые заказы | Не трогаются; ставится метка `uniundata_erasure_requested_at` | Этап 1 — ежедневной задачей, когда заказ закроется |

Ответ эрейзера: `items_retained = true` и сообщение, если есть незакрытые или удерживаемые для бухгалтерии
заказы.

**Закрытый заказ** (`Plugin::CLOSED_ORDER_SQL`): статус `completed`, `refunded`, `cancelled`,
`payment_expired` или `partially_refunded` с `fulfilled_at IS NOT NULL`; **и** `needs_attention = 0`; **и** нет
возврата в `requested`/`pending`. Пока менеджер разбирает заказ или банк проводит возврат, контакты нужны.

Признак этапа 1 — **колонка `orders.pii_erased_at`**, а не содержимое полей (заглушка в email — лишь значение
NOT NULL-колонки). Этап 1 — одиночный `UPDATE wp_book_orders … WHERE … AND pii_erased_at IS NULL AND
<закрыт>`: он идемпотентен, держит только строки заказов (плюс `NOT EXISTS` по `wp_book_refunds`) и не
образует цикла ожидания с checkout или webhook; в READ COMMITTED условие перепроверяется на заблокированной
версии строки.

**Ежедневная задача `uniundata_privacy_retention`** (02:10 UTC, `Plugin::privacyRetention()`; вручную —
`wp uniundata privacy-retention`), пакет до 500 строк на шаг:

1. IP и хэш UA в согласиях старше 180 дней → `NULL` (`ix_consents_retention`).
2. Этап 1 для закрытых заказов с `pii_erased_at IS NULL`, если заказ не менялся дольше срока контактов
   (`ix_orders_retention (pii_erased_at, status, updated_at)`), или аккаунта нет в `wp_users`, или есть
   метка `uniundata_erasure_requested_at`.
3. Этап 2 для заказов с `pii_erased_at IS NOT NULL` старше бухгалтерского срока от
   `COALESCE(completed_at, cancelled_at, paid_at, placed_at, created_at)`. Признак этапа 2 —
   `customer_last_name = 'Anonymized'`.

Итог пишется в аудит (`privacy.retention_applied`, только счётчики).

## 7.12 Удаление пользователя WordPress

| Ситуация | Хук | Что делает плагин |
|---|---|---|
| Проверка права на удаление (админка, `DELETE /wp/v2/users/{id}`) | `map_meta_cap` для `delete_user` | `do_not_allow`, если у пользователя есть заказ в `pending_payment`, `payment_processing` или `paid` (`Plugin::MONEY_IN_FLIGHT`): деньги «в пути» или заказ исполняется. Администратор сначала завершает заказ |
| «Пользователи → Удалить», `wp user delete`, `wp_delete_user()` | `delete_user` (до удаления строки) → `Plugin::onDeleteUser()` → `releaseUser()` | Шаги 1–4 ниже |
| Мультисайт (не используется на shop.libsmr.ru) | `wpmu_delete_user` → `onDeleteNetworkUser()` | То же на каждом сайте, где активен плагин; «убрать с сайта» ничего не делает |

`delete_user` не умеет отменить удаление (`wp_die()` оставил бы полуудалённого пользователя), поэтому запрет —
только через `map_meta_cap`, а WP-CLI и `wp_delete_user()` права не проверяют. `releaseUser()` корректен при
любом состоянии заказов:

1. Активные резервы — каждый в своей транзакции в порядке корзина → экземпляр → резерв: `cancelled`
   (`release_reason = 'user_deleted'`), экземпляр — в release target.
2. Открытая корзина → `abandoned`.
3. Заказы `draft`, `pending_payment`, `payment_failed` (`Plugin::CANCELLABLE_ORDERS`) →
   `CheckoutService::cancel()`, сессии банка закрываются после `COMMIT`. `payment_processing` не трогается —
   ждём итог банка; поздний платёж по отменённому заказу уходит в общую ветку (возврат или
   `needs_attention`).
4. Задача `uniundata_user_deleted_cleanup {user_id}` (без `unique`) → `Plugin::cleanupDeletedUser()`: если
   пользователь всё-таки существует — ничего; иначе удаляет профиль, отзывает `marketing`, обнуляет IP и
   выполняет этап 1 для закрытых заказов. Незакрытые обезличит ежедневная задача после закрытия.

`user_id` в таблицах магазина **не обнуляется**: после удаления аккаунта это псевдоним. Обнуление сломало бы
`UNIQUE(user_id, book_item_id, attempt_no)` для двух удалённых пользователей одного экземпляра. ID не
переиспользуется: счётчик AUTO_INCREMENT в MySQL 8 сохраняется между перезапусками.

**FOREIGN KEY на `wp_users` нет** ([03](03-tables-and-indexes.md) § 9): ядро удаляет пользователя, не зная о
наших таблицах (`RESTRICT` оборвёт `wp_delete_user()` на полпути, `CASCADE` удалит заказы, `SET NULL`
несовместим с ключами); движок `wp_users` не гарантирован; восстановление и перенос таблиц ядра не должны
спотыкаться о ссылки. Контроль — хуки выше и запрос:

```sql
-- Заказы удалённых пользователей без этапа 1 (незакрытые ждут закрытия — это не ошибка)
SELECT o.user_id, COUNT(*) FROM wp_book_orders o
  LEFT JOIN wp_users u ON u.ID = o.user_id
 WHERE u.ID IS NULL AND o.pii_erased_at IS NULL
 GROUP BY o.user_id;
```

## 7.13 Регистрация покупателя и единый вход с new.libsmr.ru

**Регистрация в магазине — основной путь.** Аккаунт основного сайта в магазине не действует: у установок
разные `wp_users`.

- `users_can_register = 1`; `default_role = book_customer` ставит `Roles::install()`. Штатная форма
  `wp-login.php?action=register` (или форма страницы аккаунта (†)) спрашивает логин и email; пароль
  покупатель задаёт по ссылке из письма — это и подтверждение адреса. Имя и фамилия запрашиваются при первом
  checkout (§ 7.6).
- Защита формы от ботов (honeypot или капча, лимит на IP в Apache/fail2ban) (†).
- 152-ФЗ: в форме — ссылка на политику; если юрист решит, что до заключения договора нужно согласие, —
  отдельная галочка, а согласие пишется строкой `privacy` в `wp_book_user_consents` (хуки `register_form`,
  `registration_errors`, `user_register`) (†).

**Единый вход — опция, отдельным этапом.** Если бизнесу нужен один аккаунт на двух сайтах — OpenID Connect:
магазин — клиент (плагин OIDC-клиента, например OpenID Connect Generic Client), провайдер — new.libsmr.ru
(плагин OIDC/OAuth-сервера) или отдельный IdP в РФ (Keycloak). При первом входе в магазине создаётся
локальный пользователь с ролью `book_customer`, связанный с `sub` в usermeta; плагин магазина работает с
локальным `wp_users.ID` и от SSO не зависит.

| | Плюсы | Минусы и риски |
|---|---|---|
| Регистрация в магазине | Ничего разрабатывать не надо; взлом или сбой основного сайта не затрагивает магазин; отдельный круг администраторов с доступом к ПДн | Два аккаунта у человека, профили не синхронизированы |
| OIDC | Один аккаунт, установки остаются независимыми; на стороне IdP можно добавить вход через Яндекс ID / VK ID | Новый критичный компонент: IdP недоступен — входа нет (нужен резервный локальный пароль). Безопасность: `state`, `nonce`, PKCE, проверка `iss`/`aud`, точные redirect URI. Связывать существующий аккаунт по email — только при `email_verified = true`, иначе захват аккаунта. Выход и удаление аккаунта у IdP не распространяются на магазин — нужен регламент. Если сайтами владеют разные юрлица — это передача ПДн другому оператору (основание, поручение, отражение в политике); оба сервера — в РФ |

Не рекомендуем: общую `wp_users` через `CUSTOM_USER_TABLE` (не штатный сценарий ядра, роли в usermeta с
разными префиксами, ломаются плагины), перевод двух установок в мультисайт ради входа и копирование
пользователей между базами (два источника истины по паролю и email).

## 7.14 Что проверяют тесты

- Гость: 401 на `/cart/reserve` и `/checkout`, `GET /catalog/availability` — 200 без cookie.
- `book_catalog_manager` — 403 на `/cart/reserve`; `book_customer` — 403 на `/admin/*`.
- `view_book_order`: владелец — да; другой покупатель — нет и 404 в REST; `book_order_manager` — да;
  `book_catalog_manager` — нет.
- Блокировка в чужой корзине без `manage_book_reservations` — 403; в `checkout_pending` — 409 с
  `required_action = cancel_order`.
- `Roles::install()` дважды подряд не меняет `wp_user_roles`; рост `VERSION` снимает удалённое право только
  у ролей плагина.
- Удаление пользователя с заказом в `pending_payment` из админки запрещено; `wp user delete` снимает резервы,
  отменяет неоплаченные заказы, задача очистки выполняет этап 1; строки заказов и продаж остаются.
- Эрейзер: профиль удалён, IP в согласиях `NULL`, у закрытых заказов заполнен `pii_erased_at`, в адресе
  осталась только страна; заказ в `paid` не изменён, `items_retained = true`.
- `privacy-retention`: заказ с `needs_attention = 1` или возвратом в `pending` не обезличивается.
- Checkout с устаревшей редакцией — 409 `uniundata_terms_outdated`; у каждого заказа есть строки `offer` и
  `privacy`.
- Экспортер заказов постранично возвращает все заказы (`done = false` до последней страницы).
