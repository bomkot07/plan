# 7. Пользователи, роли, capabilities и персональные данные

> **Кратко.** Учётные записи хранятся в `wp_users`, своей таблицы пользователей нет. `user_id` во всех
> таблицах магазина — это `wp_users.ID`. Плагин работает без WooCommerce, поэтому у него своя роль
> `book_customer` и две роли персонала. Доступ к заказу проверяет мета-capability `view_book_order`
> через `map_meta_cap`. Минимальный профиль WordPress (имя, фамилия, необязательное отчество) лежит в
> `wp_usermeta`. Телефон, адреса и B2B-реквизиты — в `wp_book_customer_profiles`, согласия — в
> `wp_book_user_consents`. Заказ хранит снимок данных покупателя. Заказы не удаляются, а анонимизируются
> в два этапа с учётом сроков хранения бухгалтерских документов.

## 7.1 Принципы

1. **Одна идентичность — `wp_users`.** Вход, пароль, восстановление доступа, Application Passwords,
   сессии и подтверждение смены email делает ядро. Плагин ссылается на `wp_users.ID` логически,
   без FOREIGN KEY (§ 7.12).
2. **Права — только через capabilities.** Каждый REST-маршрут и экран админки проверяет
   `current_user_can()`. ID пользователя берётся только из `get_current_user_id()`, никогда из параметров.
3. **Роль покупателя — `book_customer`.** Роль WooCommerce `customer` не используется, даже если WC
   установлен для других задач: её семантикой управляет WC.
4. **Минимизация PII.** Собирается только то, что нужно для исполнения заказа и бухгалтерии.
   Структурированные данные хранятся в своих таблицах с CHECK и индексами. В заказ копируется снимок.
   Журналы и аудит хранят `user_id`, но не значения PII.

## 7.2 Роли и capabilities

### Матрица

| Capability | Гость | `book_customer` | `book_catalog_manager` | `book_order_manager` | `administrator` | Назначение |
|---|---|---|---|---|---|---|
| `read` | — | да | да | да | да | Базовое право вошедшего пользователя (ядро) |
| `view_book_catalog` | — ¹ | да | да | да | да | Режим закрытой витрины (¹) |
| `reserve_books` | — | да | — | — | да | `POST /cart/reserve`, `/cart/remove-item`, `GET /cart` |
| `create_book_orders` | — | да | — | — | да | `POST /checkout`, `/orders/{id}/pay`, `/orders/{id}/cancel` (только свои заказы) |
| `view_own_book_orders` | — | да | — | да | да | `GET /orders`, свой заказ через `view_book_order` |
| `manage_book_catalog` | — | — | да | — | да | Экраны записей и экземпляров, `POST /admin/items/{id}/block`, `…/unblock` |
| `manage_book_sync` | — | — | да | — | да | `POST /admin/sync/run`, `GET /admin/sync/runs`, экран прогонов |
| `manage_book_orders` | — | — | — | да | да | Любой заказ (`view_book_order` для чужого), отмена и возврат из админки, `needs_attention` |
| `manage_book_reservations` | — | — | — | да | да | `POST /admin/reservations/{id}/release`, экран резервов |
| `view_book_order` (мета, с ID заказа) | — (`do_not_allow`) | только свой заказ | нет (нет ни `view_own_book_orders`, ни `manage_book_orders`) | любой заказ | любой заказ | § 7.4: владелец → `view_own_book_orders`, иначе → `manage_book_orders` |

¹ Гостю capability назначить нельзя (§ 7.3). Каталог, карточки и `GET /catalog/availability`
публичны и `view_book_catalog` не проверяют. Эта capability нужна для режима закрытой витрины: если
фильтр `uniundata_catalog_requires_login` вернёт `true`, шаблоны и маршруты каталога начнут её
проверять. Так включается предпросмотр новых поступлений только для зарегистрированных или закрытый
B2B-каталог.

### Действия персонала, которым нужны несколько прав

| Действие | Требуется | Почему |
|---|---|---|
| Заблокировать экземпляр в статусе `available` | `manage_book_catalog` | Действие каталога |
| Заблокировать экземпляр в чужой корзине (`reserved`) | `manage_book_catalog` **и** `manage_book_reservations` | Сначала снимается резерв (`released_by_admin`), затем ставится `blocked`, в одной транзакции (см. [09](09-migrations-tests-edge-cases.md)) |
| Заблокировать экземпляр в `checkout_pending` | `manage_book_catalog` **и** `manage_book_orders` | Сначала отменяется заказ, только потом блокировка |
| Настройки плагина (TTL оплаты, источник синхронизации, версии документов) | `manage_options` | Только администратор: настройки меняют бизнес-правила |
| Экспорт и удаление персональных данных (Инструменты → Приватность) | `export_others_personal_data` / `erase_others_personal_data` | Права ядра, которые WordPress сводит к `manage_options` (на мультисайте — `manage_network`). Менеджер заказов запросы субъектов данных не обрабатывает |

Пояснения к ролям:

- **Персонал не покупает служебной учётной записью.** У менеджеров нет `reserve_books`. Так права на
  управление заказами отделены от покупок. Если сотрудник хочет купить книгу, он делает это личной
  учётной записью, или ему добавляют вторую роль: `wp user add-role <id> book_customer`
  (WordPress поддерживает несколько ролей на пользователя).
- **`view_own_book_orders` у менеджера заказов** нужна, чтобы такой сотрудник с ролью покупателя видел
  свои заказы в «Моих заказах» по общему правилу `map_meta_cap`.
- **У менеджеров нет `list_users` и `edit_users`.** Персональные данные покупателей они видят только в
  снимке конкретного заказа, а не в общем списке пользователей WordPress.
- Если менеджер каталога загружает обложки в медиатеку (`wp_book_images.attachment_id`), ему
  дополнительно выдаётся `upload_files`.

## 7.3 Почему гостю нельзя выдать capability

- В WordPress capabilities принадлежат **пользователю**: роли и личные права хранятся в usermeta
  `{prefix}capabilities` конкретного `wp_users.ID`. Анонимный посетитель — это `WP_User` с `ID = 0`,
  без ролей и без строки в `wp_users`. Роли «гость» в модели нет, и назначить её некому.
- `current_user_can()` для пользователя 0 возвращает `true` только для встроенной `exist`. Её
  `WP_User::has_cap()` выдаёт всем («Everyone is allowed to exist»), поэтому для разграничения она
  бесполезна.
- Технически можно подмешать права пользователю 0 фильтром `user_has_cap`, но это ложная модель.
  Любой плагин, проверяющий ту же capability, откроет гостям свою функциональность. Кроме того,
  `current_user_can()` начнёт отвечать «да» до проверки nonce. Права гостя остаются неявными и не
  видны в `wp role list`.

Как выражается публичный доступ:

- публичные маршруты (`GET /catalog/availability`) объявляются с `'permission_callback' => '__return_true'`
  (WordPress требует явный `permission_callback` у каждого маршрута). Страницы каталога не проверяют
  права вообще;
- всё, что меняет состояние, требует входа: `is_user_logged_in()` → иначе 401
  `uniundata_auth_required`, затем `current_user_can(…)` → иначе 403 `uniundata_forbidden`;
- в интерфейсе гость видит вместо «Отложить» ссылку «Войдите, чтобы отложить» на
  `wp_login_url($currentUrl)`. После входа пользователь возвращается на ту же карточку, и её страница
  уже не берётся из page cache.

## 7.4 Мета-capability `view_book_order` и `map_meta_cap`

`view_book_order` — мета-capability: её нет ни у одной роли. WordPress переводит её в примитивные
права через фильтр `map_meta_cap` с учётом конкретного заказа. Владелец получает
`view_own_book_orders`, остальные — `manage_book_orders`. В том же классе стоит защита удаления
пользователя с незавершённой оплатой (§ 7.12).

```php
<?php
declare(strict_types=1);

namespace Uniundata\Books\Security;

final class Capabilities
{
    /** Деньги «в пути»: удалять такого покупателя из админки нельзя (§ 7.12). */
    private const MONEY_IN_FLIGHT = ['pending_payment', 'payment_processing', 'paid'];

    /** @var array<int, int|null> владелец заказа; кэш на время PHP-запроса */
    private static array $orderOwners = [];

    public static function register(): void
    {
        add_filter('map_meta_cap', [self::class, 'map'], 10, 4);
    }

    /**
     * @param string[] $caps примитивные права, которые уже вычислило ядро
     * @param mixed[]  $args аргументы current_user_can(): [0] — ID заказа или пользователя
     * @return string[]
     */
    public static function map(array $caps, string $cap, int $userId, array $args): array
    {
        return match ($cap) {
            'view_book_order' => self::mapViewOrder($userId, (int) ($args[0] ?? 0)),
            'delete_user'     => isset($args[0]) && self::hasMoneyInFlight((int) $args[0])
                ? ['do_not_allow']
                : $caps,
            default           => $caps,
        };
    }

    /** @return string[] */
    private static function mapViewOrder(int $userId, int $orderId): array
    {
        if ($userId <= 0 || $orderId <= 0) {
            return ['do_not_allow'];
        }
        $ownerId = self::orderOwner($orderId);
        if ($ownerId === null) {
            return ['do_not_allow'];
        }
        return $ownerId === $userId ? ['view_own_book_orders'] : ['manage_book_orders'];
    }

    private static function orderOwner(int $orderId): ?int
    {
        if (!array_key_exists($orderId, self::$orderOwners)) {
            global $wpdb;
            $owner = $wpdb->get_var($wpdb->prepare(
                "SELECT user_id FROM {$wpdb->prefix}book_orders WHERE id = %d",
                $orderId
            ));
            self::$orderOwners[$orderId] = $owner === null ? null : (int) $owner;
        }
        return self::$orderOwners[$orderId];
    }

    private static function hasMoneyInFlight(int $userId): bool
    {
        global $wpdb;
        $in = implode(',', array_fill(0, count(self::MONEY_IN_FLIGHT), '%s'));
        return (bool) $wpdb->get_var($wpdb->prepare(
            "SELECT EXISTS (SELECT 1 FROM {$wpdb->prefix}book_orders
                             WHERE user_id = %d AND status IN ($in))",
            $userId,
            ...self::MONEY_IN_FLIGHT
        ));
    }
}
```

Использование в контроллере. Отсутствующий и чужой заказ дают **одинаковый 404**, чтобы ответ не
раскрывал существование заказа:

```php
<?php
declare(strict_types=1);

namespace Uniundata\Books\Rest;

final class OrderController
{
    public function __construct(private readonly OrderReadModel $orders) {}

    public function getOrder(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        $order = $this->orders->findByPublicId((string) $request['public_order_id']);
        if ($order === null || !current_user_can('view_book_order', (int) $order['id'])) {
            return new \WP_Error(
                'uniundata_order_not_found',
                __('Заказ не найден.', 'uniundata-books'),
                ['status' => 404]
            );
        }
        // Менеджер видит служебные поля (needs_attention, attention_reason), владелец — нет.
        return new \WP_REST_Response($this->orders->present($order, current_user_can('manage_book_orders')));
    }
}
```

Детали:

- `permission_callback` маршрута `GET /orders/{public_order_id}` проверяет только `is_user_logged_in()`
  (иначе 401). Проверить владельца до загрузки заказа нельзя, поэтому право проверяется в callback.
- `POST /orders/{id}/pay` и `/cancel` проверяют не `view_book_order`, а **строгое владение**
  (`$order['user_id'] === get_current_user_id()` + `create_book_orders`). Менеджер не должен оплачивать
  или отменять чужой заказ от имени покупателя: у менеджера для этого свои действия в админке.
- `do_not_allow` отказывает даже суперадминистратору мультисайта.
- `map_meta_cap` вызывается на каждый `current_user_can()`, поэтому владелец кэшируется на время
  запроса. Персистентный кэш не нужен: владелец заказа не меняется.

## 7.5 Регистрация ролей: код и версионирование

Роли WordPress хранятся в option `{prefix}user_roles`. `add_role()` ничего не делает для существующей
роли, а каждый `add_cap()`/`remove_cap()` перезаписывает эту option. Поэтому карта ролей задаётся в коде,
а БД приводится к ней **по версии**: при активации и на `init`, когда выросла `Roles::VERSION`.

```php
<?php
declare(strict_types=1);

namespace Uniundata\Books\Install;

final class Roles
{
    /** Увеличивать при ЛЮБОМ изменении ROLES. */
    public const VERSION = 1;
    public const VERSION_OPTION = 'uniundata_roles_version';

    /** Собственные права плагина. Синхронизатор снимает только их и не трогает права ядра. */
    public const PLUGIN_CAPS = [
        'view_book_catalog', 'reserve_books', 'create_book_orders', 'view_own_book_orders',
        'manage_book_catalog', 'manage_book_orders', 'manage_book_sync', 'manage_book_reservations',
    ];

    /** label = null: роль ядра, её не создаём, только добавляем права. */
    private const ROLES = [
        'book_customer' => [
            'label' => 'Покупатель книг',
            'caps'  => ['read', 'view_book_catalog', 'reserve_books', 'create_book_orders', 'view_own_book_orders'],
        ],
        'book_catalog_manager' => [
            'label' => 'Менеджер каталога',
            'caps'  => ['read', 'view_book_catalog', 'manage_book_catalog', 'manage_book_sync'],
        ],
        'book_order_manager' => [
            'label' => 'Менеджер заказов',
            'caps'  => ['read', 'view_book_catalog', 'view_own_book_orders', 'manage_book_orders',
                        'manage_book_reservations'],
        ],
        'administrator' => [
            'label' => null,
            'caps'  => ['read', 'view_book_catalog', 'reserve_books', 'create_book_orders', 'view_own_book_orders',
                        'manage_book_catalog', 'manage_book_orders', 'manage_book_sync', 'manage_book_reservations'],
        ],
    ];

    /** Вызывается на init: дешёвое сравнение autoload-option. */
    public static function maybeUpgrade(): void
    {
        if ((int) get_option(self::VERSION_OPTION, 0) < self::VERSION) {
            self::install();
        }
    }

    /** Вызывается при активации и из maybeUpgrade(). Идемпотентно. */
    public static function install(): void
    {
        foreach (self::ROLES as $slug => $def) {
            $role = get_role($slug);
            if ($role === null) {
                if ($def['label'] === null) {
                    continue; // нестандартная сборка без administrator: роль ядра не создаём
                }
                $role = add_role($slug, $def['label'], array_fill_keys($def['caps'], true));
                if ($role === null) {
                    continue;
                }
            }
            foreach ($def['caps'] as $cap) {
                if (!$role->has_cap($cap)) {
                    $role->add_cap($cap);
                }
            }
            // Понижение прав при апгрейде: снимаем СВОИ права, которых больше нет в карте.
            foreach (array_diff(self::PLUGIN_CAPS, $def['caps']) as $cap) {
                if ($role->has_cap($cap)) {
                    $role->remove_cap($cap);
                }
            }
        }
        // Регистрация через wp-login.php создаёт покупателя. Явный выбор администратора не трогаем.
        if (get_option('default_role') === 'subscriber') {
            update_option('default_role', 'book_customer');
        }
        update_option(self::VERSION_OPTION, self::VERSION, true);
    }

    /** Из uninstall.php. На больших сайтах — пакетами через WP-CLI. */
    public static function uninstall(): void
    {
        $admin = get_role('administrator');
        foreach (self::PLUGIN_CAPS as $cap) {
            $admin?->remove_cap($cap); // remove_cap() принимает ровно одну capability
        }
        foreach (array_keys(self::ROLES) as $slug) {
            if ($slug === 'administrator') {
                continue;
            }
            foreach (get_users(['role' => $slug, 'fields' => 'ID']) as $id) {
                $user = new \WP_User((int) $id);
                $user->remove_role($slug);
                if ($user->roles === []) {
                    $user->add_role('subscriber');
                }
            }
            remove_role($slug);
        }
        if (get_option('default_role') === 'book_customer') {
            update_option('default_role', 'subscriber');
        }
        delete_option(self::VERSION_OPTION);
    }
}
```

Правила версионирования:

| Изменение | Действие |
|---|---|
| Новая capability у роли, новая роль | Изменить `ROLES`, увеличить `VERSION`. На первом `init` после деплоя права добавятся |
| Право у роли отозвано | Изменить `ROLES`, увеличить `VERSION`. Синхронизатор снимет право, но только из `PLUGIN_CAPS`: права ядра и выданные вручную права ядра не трогаются |
| Переименование capability | Две версии: в `N` добавить новую и проверять обе, в `N+1` снять старую (expand → contract, как у миграций схемы) |
| Деактивация плагина | Роли **не удаляются**: иначе пользователи остались бы с несуществующей ролью и без прав после повторной активации |
| Удаление плагина | `Roles::uninstall()`: пользователи переводятся в `subscriber`, роли удаляются, права администратора снимаются. Таблицы заказов остаются (см. [09](09-migrations-tests-edge-cases.md)) |
| Мультисайт | Роли хранятся у каждого сайта. При сетевой активации `install()` выполняется для каждого сайта через `switch_to_blog()`, для новых сайтов — на `wp_initialize_site` |

Две параллельные загрузки после деплоя могут обе выполнить `install()`. Это безопасно: операции
идемпотентны, последняя запись `update_option` одинакова.

Проверка: `wp role list`, `wp cap list book_customer`, `wp eval 'var_dump(user_can(5, "reserve_books"));'`.

**Покупатели не работают в `/wp-admin/`.** Право `read` открывает консоль и `profile.php`. Профиль и
заказы покупателя живут на странице аккаунта витрины, поэтому из консоли их перенаправляем:

```php
<?php
declare(strict_types=1);

add_action('admin_init', static function (): void {
    global $pagenow;
    if (wp_doing_ajax() || $pagenow === 'admin-post.php' || !is_user_logged_in()) {
        return; // admin-ajax.php (в т. ч. action=rest-nonce) и admin-post.php должны работать
    }
    foreach (['edit_posts', 'manage_book_catalog', 'manage_book_orders', 'manage_book_sync',
              'manage_book_reservations', 'manage_options'] as $cap) {
        if (current_user_can($cap)) {
            return;
        }
    }
    wp_safe_redirect(home_url('/account/'));
    exit;
});

add_filter('show_admin_bar', static fn (bool $show): bool =>
    $show && (current_user_can('edit_posts') || current_user_can('manage_book_orders')
        || current_user_can('manage_book_catalog')));
```

## 7.6 Какие данные нужны для оформления заказа

| Данные | Обязательно | Когда нужно | Где живёт | Снимок в заказе |
|---|---|---|---|---|
| ID пользователя | да | всегда | `wp_users.ID` | `orders.user_id` |
| Email | да | всегда: подтверждение, статус, документы, восстановление доступа | `wp_users.user_email` (ядро подтверждает смену email письмом) | `customer_email` |
| Имя, фамилия | да | счёт, адресат отправления | usermeta `first_name`, `last_name` | `customer_first_name`, `customer_last_name` (NOT NULL) |
| Отчество | **нет** | если покупатель сам указал (локальная практика) | usermeta `middle_name` | `customer_middle_name` (NULL) |
| `display_name` | нет | только для UI | `wp_users.display_name` | не копируется |
| Телефон | только если его требует выбранная доставка (курьер, служба доставки) | checkout с такой доставкой | `wp_book_customer_profiles.phone_e164` | `customer_phone` (E.164) |
| Адрес доставки | только при доставке (не при самовывозе) | checkout | `profiles.default_shipping_address` — только для автозаполнения | `shipping_address_json` — **источник истины** для этого заказа |
| Платёжный адрес | по требованию бухгалтерии (счёт для B2B) | checkout | `profiles.default_billing_address` | `billing_address_json` |
| Компания, VAT ID | только B2B | checkout «как организация» | `profiles.company_name`, `vat_id` | `billing_address_json.company`, `.vat_id` |
| Согласие с офертой | да | каждый checkout | `wp_book_user_consents` (`offer`) | `orders.offer_consent_id` |
| Согласие с политикой ПДн | да | регистрация или первый checkout для текущей версии | `wp_book_user_consents` (`privacy`) | — |
| Согласие на рассылку | нет, отдельная галочка, не предвыбрана | по желанию | `wp_book_user_consents` (`marketing`) | — |
| IP, User-Agent | нет, техническое доказательство согласия | в момент согласия | `consents.ip_address` (до 180 дней), `user_agent_sha256` | — |

**Не собираем:** дату рождения, пол, паспортные данные, данные карты (их видит только банк; у нас —
`card_brand` и `card_last4`), второй телефон, «как вы о нас узнали» в обязательных полях.

Формат JSON адреса (`shipping_address_json`, `billing_address_json`, `default_*_address`):

```json
{
  "v": 1,
  "first_name": "Иван",
  "last_name": "Петров",
  "middle_name": null,
  "company": null,
  "vat_id": null,
  "line1": "Hauptstraße 1",
  "line2": null,
  "postal_code": "10115",
  "city": "Berlin",
  "region": null,
  "country_code": "DE"
}
```

Валидация на сервере. Белый список ключей, лишние ключи отбрасываются. `sanitize_text_field` и
лимиты длины: имена ≤ 100 (как колонки `customer_*_name`), строки адреса ≤ 200, индекс ≤ 16.
`country_code` проверяется по списку ISO 3166-1 alpha-2. Размер JSON ≤ 2 КБ. Сохраняется через
`wp_json_encode`. Телефон в JSON адреса не дублируется: он хранится только в `customer_phone`.
Поле `v` — версия формата, для будущих миграций.

## 7.7 Что хранить в `wp_usermeta`, а что — в таблицах магазина

| Хранилище | Поля | Почему здесь |
|---|---|---|
| `wp_users` | `ID`, `user_email`, `user_login`, `user_pass`, `display_name`, `user_registered` | Ядро: аутентификация и идентичность |
| `wp_usermeta` (ядро) | `first_name`, `last_name`, `{prefix}capabilities` | Стандартные поля профиля: их показывает «Профиль», экспортирует ядро, они удаляются вместе с пользователем |
| `wp_usermeta` (плагин) | `middle_name` (необязательное), `uniundata_erasure_requested_at` (служебная метка § 7.11) | Скалярные необязательные значения без поиска и истории. Ключ `middle_name` без префикса: это поле того же уровня, что `first_name` и `last_name` ядра |
| `wp_book_customer_profiles` | `phone_e164`, `phone_verified_at`, `default_shipping_address`, `default_billing_address`, `company_name`, `vat_id` | Строгий формат (`CHECK phone_e164 REGEXP '^\\+[1-9][0-9]{6,14}$'`), индекс `ix_profiles_phone` для поиска менеджером по телефону (у `meta_value` нет индекса), типизированные колонки и JSON. Одна строка на пользователя (`PRIMARY KEY (user_id)`), без дублей `meta_key`. Удаляется одним `DELETE` при стирании данных |
| `wp_book_user_consents` | Согласия: тип, версия, хэш документа, время, IP, хэш UA, отзыв | Юридическое доказательство: append-only, несколько строк на пользователя, связь с заказом через FK `orders.offer_consent_id`. В usermeta это не выражается |
| `wp_book_orders` | Снимок: email, телефон, ФИО, адреса, согласие | Заказ — бухгалтерский документ. Последующие правки профиля не должны его менять |

`middle_name` регистрируется как meta, чтобы её санитизировали и проверяли права:

```php
<?php
declare(strict_types=1);

register_meta('user', 'middle_name', [
    'type'              => 'string',
    'single'            => true,
    'show_in_rest'      => false,
    'sanitize_callback' => static fn ($value): string => mb_substr(sanitize_text_field((string) $value), 0, 100),
    'auth_callback'     => static fn (bool $allowed, string $key, int $userId): bool => current_user_can('edit_user', $userId),
]);
```

Телефон нормализуется в E.164 на сервере (`libphonenumber`, перенесённая в namespace плагина через
PHP-Scoper; регион по умолчанию — страна адреса). CHECK в БД — второй рубеж. История изменений профиля
пишется в `wp_book_audit_log` (`customer.profile_updated`) со списком **изменённых полей без значений**,
чтобы журнал не стал второй копией PII.

## 7.8 Согласия: `wp_book_user_consents`

| Поле | Содержимое |
|---|---|
| `consent_uuid` | Технический идентификатор согласия (`wp_generate_uuid4()`). Его можно показать пользователю и указать в письме-подтверждении |
| `consent_type` | `offer` — оферта (новая строка на **каждый** заказ, ссылка из `orders.offer_consent_id`). `privacy` — политика обработки ПДн (одна строка на версию). `marketing` — рассылка |
| `document_version`, `document_sha256` | Версия и SHA-256 точного текста принятой редакции. Хэш защищает от незаметной правки текста под тем же номером версии |
| `accepted_at` | `UTC_TIMESTAMP(6)` сервера БД, а не время из браузера |
| `ip_address` | `VARBINARY(16)` через `INET6_ATON()`, подходит для IPv4 и IPv6. Обнуляется через 180 дней |
| `user_agent_sha256` | Хэш, а не строка UA. Обнуляется вместе с IP |
| `withdrawn_at` | Отзыв (для `marketing`). Строка не удаляется: отзыв — отдельная дата |

**Версии документов** хранятся в option `uniundata_terms_versions`:

```json
{
  "offer":   {"current": "2026-09-01", "versions": {"2026-09-01": {"sha256": "9f2c…", "url": "https://example.com/legal/offer-2026-09-01.pdf"}}},
  "privacy": {"current": "2026-06-15", "versions": {"2026-06-15": {"sha256": "51ab…", "url": "https://example.com/legal/privacy-2026-06-15.pdf"}}}
}
```

Тексты всех редакций хранятся неизменяемыми файлами. Ревизии страниц WordPress для этого не подходят:
их можно удалить или отредактировать. `POST /checkout` передаёт `accept_offer_version` и
`accept_privacy_version`. Если версия не совпадает с `current` (пользователь держал страницу открытой во
время смены редакции), сервер отвечает 400 `uniundata_invalid_param` с актуальной версией в `data`, и
пользователь подтверждает новый текст.

**Запись в checkout.** Согласия вставляются в той же транзакции, что и заказ, перед `INSERT` заказа:
нужен `offer_consent_id` для FK. Таблица append-only, `FOR UPDATE` по ней не берётся, поэтому в
глобальный порядок блокировок она не входит. Оферта — новая строка всегда. Политика — только если нет
неотозванной строки с текущей версией.

```sql
-- IP проверяется в PHP (filter_var(..., FILTER_VALIDATE_IP)); пустая строка → NULL
INSERT INTO wp_book_user_consents
  (consent_uuid, user_id, consent_type, document_version, document_sha256,
   accepted_at, ip_address, user_agent_sha256)
VALUES (%s, %d, 'offer', %s, %s,
        UTC_TIMESTAMP(6), INET6_ATON(NULLIF(%s, '')), NULLIF(%s, ''));

-- Чтение для экспорта / спора
SELECT consent_uuid, consent_type, document_version, accepted_at,
       INET6_NTOA(ip_address) AS ip, withdrawn_at
  FROM wp_book_user_consents
 WHERE user_id = %d
 ORDER BY accepted_at;

-- Ежедневная минимизация (задача uniundata_privacy_retention), пакетами
UPDATE wp_book_user_consents
   SET ip_address = NULL, user_agent_sha256 = NULL
 WHERE ip_address IS NOT NULL
   AND accepted_at < UTC_TIMESTAMP(6) - INTERVAL %d DAY   -- 180 по умолчанию
 LIMIT 1000;

-- Отзыв согласия на рассылку
UPDATE wp_book_user_consents
   SET withdrawn_at = UTC_TIMESTAMP(6)
 WHERE user_id = %d AND consent_type = 'marketing' AND withdrawn_at IS NULL;
```

`$wpdb->prepare()` не умеет передавать `NULL`: он превращает `null` в `''`. Поэтому необязательные
значения оборачиваются в `NULLIF(%s, '')`. `INET6_ATON('')` и `INET6_ATON('garbage')` в MySQL 8
возвращают `NULL` (проверено на 8.0.46).

Источник IP — `REMOTE_ADDR`. `X-Forwarded-For` учитывается, только если `REMOTE_ADDR` входит в список
доверенных прокси (константа `UNIUNDATA_TRUSTED_PROXIES`). Иначе заголовок подделывается клиентом.

**Почему 180 дней для IP.** IP — вспомогательное доказательство. Основное — `consent_uuid`, время,
версия и хэш текста, привязанные к аутентифицированному пользователю и к заказу. Полгода покрывают
типичное окно споров по платежам, а дальше IP становится лишней PII. Срок задаётся фильтром
`uniundata_consent_ip_retention_days` и утверждается юристом.

## 7.9 B2B-реквизиты

- В профиле — `company_name` и `vat_id` (оба необязательны). В заказе — снимок в `billing_address_json`
  (`company`, `vat_id`, юридический адрес). Счёт строится только из снимка.
- Проверка VAT ID (например, через VIES) — HTTP-запрос **до** транзакции checkout с таймаутом 3 с.
  Результат кладётся в снимок: `"vat_id_validated": true, "vat_id_checked_at": "…Z"`. Если сервис
  недоступен, заказ оформляется как B2C (с НДС), а не блокируется.
- Налоговые последствия B2B (reverse charge, ставки) определяет бухгалтерия. Плагин хранит факты:
  реквизиты на момент заказа и результат проверки.
- Реквизиты организации — не персональные данные, но имя контактного лица и VAT ID
  индивидуального предпринимателя — персональные. Поэтому B2B-поля проходят через тот же эрейзер и
  ту же ретенцию, что и платёжный адрес.

## 7.10 Минимизация PII и сроки хранения

| Данные | Где | Цель | Срок | Механизм |
|---|---|---|---|---|
| Аккаунт (email, имя, отчество) | `wp_users`, `wp_usermeta` | Вход, связь | Пока существует аккаунт | Удаление аккаунта (ядро удаляет всю usermeta) |
| Профиль (телефон, адреса, B2B) | `wp_book_customer_profiles` | Автозаполнение | Пока существует аккаунт | Эрейзер или удаление пользователя → `DELETE` |
| Контакты в заказе (email, телефон, адрес доставки) | `wp_book_orders` | Исполнение заказа, претензии | Закрытие заказа + `uniundata_retention_contact_days` (по умолчанию 730), раньше — по запросу на удаление или при удалении аккаунта | Этап 1 (§ 7.11) |
| ФИО и платёжный адрес в заказе | `wp_book_orders` | Бухгалтерский документ | Срок хранения бухгалтерских документов юрисдикции: `uniundata_retention_accounting_years` (по умолчанию 10), с конца года документа | Этап 2 (§ 7.11) |
| Согласия без IP | `wp_book_user_consents` | Доказательство заключения договора | Как у заказа (этап 2) | Строки остаются, `user_id` псевдонимный |
| IP и хэш UA согласия | `wp_book_user_consents` | Вспомогательное доказательство | 180 дней | Ежедневное обнуление |
| Резервы, корзины, продажи | свои таблицы | Бизнес-правила (лимит 3), учёт | Бессрочно | Персональных полей нет, только `user_id` |
| Платежи | `wp_book_payments`, `wp_book_payment_events` | Сверка с банком, бухгалтерия | Как у заказа | Только `card_brand`, `card_last4`, payload без PAN, CVV и лишних PII |
| Аудит | `wp_book_audit_log` | Безопасность, разбор инцидентов | Финансовые события — как у заказа, прочие — 2 года | Значения PII в `context` не пишутся по правилу кода |
| Логи веб-сервера и PHP | вне БД | Эксплуатация | 14–30 дней | logrotate. Тела запросов checkout и webhook не логируются |

Значения по умолчанию — технические заглушки. Конкретные сроки утверждает юрист или DPO под
юрисдикцию магазина: сроки хранения первичных документов в разных странах — порядка 5–10 лет. Сроки
задаются фильтрами, а ежедневная задача Action Scheduler `uniundata_privacy_retention` применяет их.

Правила кода:

- REST-ответы не раскрывают чужие данные. `GET /catalog/availability` не говорит, **кто**
  зарезервировал экземпляр. Чужой заказ — 404.
- Письма менеджерам содержат `public_order_id` и ссылку в админку, но не адрес и не телефон.
- В `wp_book_audit_log.context` и в логах — `user_id`, ID сущностей и маскированные значения
  (`i***@example.com`, `+49*******67`), не исходные PII.
- Отправка писем пропускает адреса в зоне `.invalid` (маркер анонимизации, § 7.11).

## 7.11 GDPR: экспорт и удаление персональных данных

Плагин встраивается в штатный механизм WordPress. Администратор создаёт запрос в «Инструменты →
Экспорт / Удаление персональных данных», субъект подтверждает его по ссылке из письма, после чего ядро
по очереди вызывает зарегистрированные экспортеры или эрейзеры с пагинацией (`$page`).

### Экспорт (`wp_privacy_personal_data_exporters`)

Ядро само экспортирует данные `wp_users` и стандартную usermeta. Плагин добавляет группы:

| Экспортер | Группа | Что входит |
|---|---|---|
| `uniundata-books-profile` | Профиль покупателя | `middle_name`, телефон, адреса по умолчанию, компания, VAT ID |
| `uniundata-books-consents` | Согласия | Тип, версия, время, отзыв, IP (если ещё хранится) |
| `uniundata-books-orders` | Заказы книг | Номер, статус, даты, суммы, снимок покупателя и адресов, книги, платежи (статус, сумма, бренд и last4) |
| `uniundata-books-reservations` | Резервы | Книга, время резерва и окончания, статус |

```php
<?php
declare(strict_types=1);

namespace Uniundata\Books\Privacy;

final class PersonalDataExporter
{
    private const PER_PAGE = 50;

    public static function register(): void
    {
        add_filter('wp_privacy_personal_data_exporters', static function (array $exporters): array {
            $exporters['uniundata-books-orders'] = [
                'exporter_friendly_name' => __('Книжный магазин: заказы', 'uniundata-books'),
                'callback'               => [self::class, 'exportOrders'],
            ];
            // …profile, consents, reservations регистрируются так же
            return $exporters;
        });
    }

    /** @return array{data: list<array<string, mixed>>, done: bool} */
    public static function exportOrders(string $email, int $page = 1): array
    {
        global $wpdb;
        $user = get_user_by('email', $email);
        if (!$user instanceof \WP_User) {
            return ['data' => [], 'done' => true]; // аккаунта нет — заказы уже обезличены
        }
        $page = max(1, $page);
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT id, public_order_id, status, currency, total_amount, placed_at, paid_at,
                    customer_email, customer_phone, customer_first_name, customer_last_name,
                    customer_middle_name, billing_address_json, shipping_address_json
               FROM {$wpdb->prefix}book_orders
              WHERE user_id = %d
              ORDER BY id
              LIMIT %d OFFSET %d",
            $user->ID,
            self::PER_PAGE,
            ($page - 1) * self::PER_PAGE
        ), ARRAY_A);

        $data = [];
        foreach ($rows as $o) {
            $titles = $wpdb->get_col($wpdb->prepare(
                "SELECT title_snapshot FROM {$wpdb->prefix}book_order_items WHERE order_id = %d ORDER BY id",
                (int) $o['id']
            ));
            $data[] = [
                'group_id'    => 'uniundata-book-orders',
                'group_label' => __('Заказы книг', 'uniundata-books'),
                'item_id'     => 'book-order-' . $o['public_order_id'],
                'data'        => [
                    ['name' => __('Номер заказа', 'uniundata-books'), 'value' => $o['public_order_id']],
                    ['name' => __('Статус', 'uniundata-books'), 'value' => $o['status']],
                    ['name' => __('Оформлен (UTC)', 'uniundata-books'), 'value' => (string) $o['placed_at']],
                    ['name' => __('Сумма', 'uniundata-books'),
                     'value' => number_format(((int) $o['total_amount']) / 100, 2, '.', '') . ' ' . $o['currency']],
                    ['name' => __('Покупатель', 'uniundata-books'), 'value' => trim(implode(' ', array_filter([
                        $o['customer_last_name'], $o['customer_first_name'], $o['customer_middle_name'],
                    ])))],
                    ['name' => 'Email', 'value' => $o['customer_email']],
                    ['name' => __('Телефон', 'uniundata-books'), 'value' => (string) $o['customer_phone']],
                    ['name' => __('Адрес доставки', 'uniundata-books'), 'value' => self::address($o['shipping_address_json'])],
                    ['name' => __('Платёжный адрес', 'uniundata-books'), 'value' => self::address($o['billing_address_json'])],
                    ['name' => __('Книги', 'uniundata-books'), 'value' => implode('; ', $titles)],
                ],
            ];
        }
        return ['data' => $data, 'done' => count($rows) < self::PER_PAGE];
    }

    private static function address(?string $json): string
    {
        $a = $json === null ? null : json_decode($json, true);
        if (!is_array($a)) {
            return '';
        }
        unset($a['v']);
        return implode(', ', array_filter(array_map('strval', $a), static fn (string $v): bool => $v !== ''));
    }
}
```

### Удаление (`wp_privacy_personal_data_erasers`): анонимизация, а не `DELETE`

Заказы, позиции, платежи и продажи не удаляются: это бухгалтерские документы, и ТЗ запрещает удалять
их строки. Эрейзер удаляет то, что можно удалить сразу, а остальное обезличивает в два этапа.

| Что | При запросе на удаление | Позже |
|---|---|---|
| `wp_book_customer_profiles` | `DELETE` | — |
| usermeta `middle_name` | `delete_user_meta` | — |
| Активные резервы и открытая корзина | Не трогаются, пока аккаунт существует: пользователь может продолжать покупки. При удалении аккаунта — снимаются (§ 7.12) | — |
| Согласия `marketing` | `withdrawn_at = now` | — |
| IP и хэш UA во всех согласиях | `NULL` сразу | — |
| Закрытые заказы | **Этап 1:** email → `erased-<id>@invalid.invalid`, телефон → `NULL`, адрес доставки → только страна | **Этап 2** после срока хранения: ФИО → `Anonymized`, платёжный адрес и B2B → только страна |
| Заказы в работе (`draft` … `paid`, `fulfilled`) | Не трогаются: нужны для исполнения | Этап 1 выполняет ежедневная задача, когда заказ закроется (по метке `uniundata_erasure_requested_at`) |

«Закрытый» заказ для целей PII — `status IN ('completed','refunded','cancelled','payment_expired')`
или `status = 'partially_refunded' AND fulfilled_at IS NOT NULL`.

```sql
-- Этап 1: контакты (эрейзер, удаление аккаунта, ретенция contact_days). Идемпотентно.
UPDATE wp_book_orders
   SET customer_email = CONCAT('erased-', id, '@invalid.invalid'),
       customer_phone = NULL,
       shipping_address_json = IF(shipping_address_json IS NULL, NULL,
           JSON_OBJECT('v', 1, 'redacted', TRUE,
                       'country_code', JSON_UNQUOTE(JSON_EXTRACT(shipping_address_json, '$.country_code'))))
 WHERE user_id = %d
   AND (status IN ('completed', 'refunded', 'cancelled', 'payment_expired')
        OR (status = 'partially_refunded' AND fulfilled_at IS NOT NULL))
   AND customer_email NOT LIKE %s;            -- параметр 'erased-%@invalid.invalid'

-- Этап 2: бухгалтерский срок истёк (ежедневная задача, пакетами по 500)
UPDATE wp_book_orders
   SET customer_first_name = 'Anonymized', customer_last_name = 'Anonymized',
       customer_middle_name = NULL,
       customer_email = CONCAT('erased-', id, '@invalid.invalid'), customer_phone = NULL,
       billing_address_json = IF(billing_address_json IS NULL, NULL,
           JSON_OBJECT('v', 1, 'redacted', TRUE,
                       'country_code', JSON_UNQUOTE(JSON_EXTRACT(billing_address_json, '$.country_code')))),
       shipping_address_json = IF(shipping_address_json IS NULL, NULL,
           JSON_OBJECT('v', 1, 'redacted', TRUE,
                       'country_code', JSON_UNQUOTE(JSON_EXTRACT(shipping_address_json, '$.country_code'))))
 WHERE COALESCE(completed_at, cancelled_at, paid_at, placed_at, created_at)
       < UTC_TIMESTAMP(6) - INTERVAL %d YEAR
   AND customer_last_name <> 'Anonymized'
 LIMIT 500;
```

Оба запроса проверены на MySQL 8.0.46: CHECK-ограничения не мешают, а `customer_email NOT NULL` остаётся
заполненным. Страна в адресах сохраняется: она нужна для налоговой отчётности по странам. Домен
`.invalid` зарезервирован (RFC 2606), поэтому письмо на такой адрес уйти не может. Одиночный `UPDATE`
трогает только `wp_book_orders` и других блокировок не держит, поэтому взаимной блокировки с checkout
или webhook быть не может. Предикат по статусу в READ COMMITTED перепроверяется на заблокированной
версии строки.

> В схеме v1 признаком обезличивания служат сами значения (`erased-…@invalid.invalid`, `Anonymized`).
> Чище и быстрее для ежедневной задачи — колонки `pii_redacted_at`/`anonymized_at` с индексом. Их стоит
> добавить отдельной аддитивной миграцией.

```php
<?php
declare(strict_types=1);

namespace Uniundata\Books\Privacy;

final readonly class RedactionResult
{
    public function __construct(
        public bool $profileDeleted,
        public int $ordersRedacted,
        public int $ordersRetained,
        public int $openOrders,
        public int $consentsMinimized,
    ) {}

    public function removedAnything(): bool
    {
        return $this->profileDeleted || $this->ordersRedacted > 0 || $this->consentsMinimized > 0;
    }
}

final class PersonalDataEraser
{
    public function __construct(private readonly PrivacyService $privacy) {}

    public function register(): void
    {
        add_filter('wp_privacy_personal_data_erasers', function (array $erasers): array {
            $erasers['uniundata-books'] = [
                'eraser_friendly_name' => __('Книжный магазин', 'uniundata-books'),
                'callback'             => [$this, 'erase'],
            ];
            return $erasers;
        });
    }

    /** @return array{items_removed: bool, items_retained: bool, messages: list<string>, done: bool} */
    public function erase(string $email, int $page = 1): array
    {
        $user = get_user_by('email', $email);
        if (!$user instanceof \WP_User) {
            return ['items_removed' => false, 'items_retained' => false, 'messages' => [], 'done' => true];
        }
        // Метка нужна ежедневной задаче: заказы, которые закроются позже, пройдут этап 1 автоматически.
        update_user_meta($user->ID, 'uniundata_erasure_requested_at', gmdate('Y-m-d H:i:s'));
        $r = $this->privacy->redactUser((int) $user->ID, 'erasure_request');

        $messages = [];
        if ($r->openOrders > 0) {
            $messages[] = sprintf(
                __('Заказов в работе: %d. Контактные данные в них будут удалены после завершения заказа.', 'uniundata-books'),
                $r->openOrders
            );
        }
        if ($r->ordersRetained > 0) {
            $messages[] = sprintf(
                __('Заказов, сохранённых для бухгалтерии: %d. ФИО и платёжный адрес будут обезличены по окончании срока хранения.', 'uniundata-books'),
                $r->ordersRetained
            );
        }
        return [
            'items_removed'  => $r->removedAnything(),
            'items_retained' => $r->openOrders > 0 || $r->ordersRetained > 0,
            'messages'       => $messages,
            'done'           => true,
        ];
    }
}
```

`PrivacyService::redactUser()` выполняет `DELETE` профиля, удаляет `middle_name`, применяет
минимизацию согласий и этап 1, затем пишет аудит `privacy.user_redacted` со счётчиками, без значений.
Эрейзер **не удаляет аккаунт**: в WordPress это отдельное действие администратора (§ 7.12).

## 7.12 Удаление пользователя WordPress

### Хуки

| Ситуация | Хук | Что делает плагин |
|---|---|---|
| Одиночный сайт, «Пользователи → Удалить», `wp user delete`, `wp_delete_user()` | `delete_user` (до удаления строки и usermeta) | Снимает резервы, отменяет неоплаченные заказы, ставит задачу очистки |
| Мультисайт, удаление из сети (`wpmu_delete_user()`) | `wpmu_delete_user` | То же для каждого сайта сети, где активен плагин (`switch_to_blog`): таблицы магазина у каждого сайта свои |
| Мультисайт, «убрать с сайта» (`wp_delete_user()` / `remove_user_from_blog()`) | `delete_user` срабатывает, но аккаунт в сети остаётся | Ничего не делаем: пользователь существует, его заказы остаются его заказами |
| Проверка прав на удаление (админка, `DELETE /wp/v2/users/{id}`) | `map_meta_cap` для `delete_user` | `do_not_allow`, если у пользователя есть заказы в `pending_payment`, `payment_processing` или `paid` (§ 7.4). Администратор сначала завершает или отменяет заказ |

`delete_user` не умеет отменять удаление: `wp_die()` в нём оставил бы полуудалённого пользователя.
Поэтому блокировка удаления реализуется только через право `delete_user`. WP-CLI и прямой вызов
`wp_delete_user()` права не проверяют, поэтому обработчик корректно работает при любом состоянии заказов.
На мультисайте guard проверяет заказы только текущего сайта (в сетевой админке — основного), а
обработчик `wpmu_delete_user` всё равно обходит все сайты.

```php
<?php
declare(strict_types=1);

namespace Uniundata\Books\Privacy;

final class UserLifecycle
{
    public function __construct(private readonly PrivacyService $privacy) {}

    public function register(): void
    {
        add_action('delete_user', [$this, 'onDeleteUser'], 10, 1);
        add_action('wpmu_delete_user', [$this, 'onDeleteNetworkUser'], 10, 1);
        add_action('uniundata_user_deleted_cleanup', [$this, 'cleanup'], 10, 1);
    }

    public function onDeleteUser(int $userId): void
    {
        if (is_multisite()) {
            return; // на мультисайте это «убрать с сайта»: аккаунт в сети остаётся
        }
        $this->releaseAndSchedule($userId);
    }

    public function onDeleteNetworkUser(int $userId): void
    {
        // Таблицы магазина у каждого сайта свои: обходим все сайты, где плагин активен.
        foreach (get_sites(['fields' => 'ids', 'number' => 0]) as $siteId) {
            switch_to_blog((int) $siteId);
            try {
                if ($this->pluginActiveOnCurrentSite()) {
                    $this->releaseAndSchedule($userId);
                }
            } finally {
                restore_current_blog();
            }
        }
    }

    private function pluginActiveOnCurrentSite(): bool
    {
        $basename = plugin_basename(UNIUNDATA_BOOKS_FILE); // константа из главного файла плагина
        return array_key_exists($basename, (array) get_site_option('active_sitewide_plugins', []))
            || in_array($basename, (array) get_option('active_plugins', []), true);
    }

    private function releaseAndSchedule(int $userId): void
    {
        // Каждая операция — свои короткие транзакции с глобальным порядком блокировок.
        $this->privacy->releaseActiveReservations($userId, 'user_deleted'); // cancelled + release target
        $this->privacy->closeOpenCart($userId);                             // abandoned, closed_at
        $this->privacy->cancelUnpaidOrders($userId, 'user_deleted');        // draft/pending_payment/payment_failed
        // PII — после удаления строки wp_users, идемпотентно и с повтором Action Scheduler.
        as_enqueue_async_action('uniundata_user_deleted_cleanup', ['user_id' => $userId], 'uniundata');
    }

    public function cleanup(int $userId): void
    {
        if (get_userdata($userId) !== false) {
            return; // удаление не состоялось — данные не трогаем
        }
        $this->privacy->redactUser($userId, 'user_deleted'); // профиль, согласия, этап 1
    }
}
```

> На мультисайте у каждого сайта своя очередь Action Scheduler (таблицы с префиксом сайта). Поэтому
> системный cron запускает `wp action-scheduler run --url=<сайт>` для каждого сайта сети.

Итог удаления:

- `wp_users` и вся usermeta — удалены ядром;
- профиль — удалён, IP в согласиях — обнулён, маркетинговые согласия — отозваны;
- активные резервы — `cancelled` с `release_reason = 'user_deleted'`, экземпляры освобождены по release
  target. Корзина — `abandoned`;
- неоплаченные заказы — `cancelled`, сессия банка отменяется задачей после `COMMIT`. Поздний платёж
  по такому заказу обрабатывается общей веткой late payment (возврат или `needs_attention`);
- оплаченные заказы, продажи и платежи остаются с прежним `user_id`. Закрытые заказы сразу проходят
  этап 1, незакрытые — когда закроются: ежедневная задача находит заказы пользователей, которых нет в
  `wp_users`, запросом ниже.

`user_id` в таблицах магазина **не обнуляется**. После удаления аккаунта это псевдоним, который ни на что
не ссылается. Обнуление сломало бы ограничения: `UNIQUE(user_id, book_item_id, attempt_no)` в
`wp_book_reservations` дал бы 1062 для двух удалённых пользователей, резервировавших один экземпляр.
Повторного использования ID нет: в MySQL 8 счётчик AUTO_INCREMENT сохраняется между перезапусками.

### Почему нет FOREIGN KEY на `wp_users`

Краткая версия — в [03, раздел 9](03-tables-and-indexes.md#9-почему-нет-fk-на-wp_users). Здесь —
с точки зрения жизненного цикла пользователя.

1. **Ядро удаляет пользователя, не зная о наших таблицах.** `wp_delete_user()` сначала удаляет
   usermeta, затем строку `wp_users` обычным `DELETE`:
   - с `RESTRICT` удаление упадёт на последнем шаге. Останется пользователь без метаданных и ролей,
     а ошибку `$wpdb` администратор не увидит;
   - с `CASCADE` пропадут заказы, продажи и платежи — бухгалтерские документы. Каскад к тому же
     упрётся в `RESTRICT`-ключи между нашими таблицами;
   - `SET NULL` несовместим с `user_id NOT NULL` и с уникальными ключами, где участвует `user_id`.
2. **Движок `wp_users` не гарантирован.** FK требует InnoDB у обеих таблиц, а старые и перенесённые
   сайты бывают с MyISAM в таблицах ядра. Плагин не должен менять движок таблиц ядра.
3. **Мультисайт.** `wp_users` — общая таблица сети, а таблицы магазина — у каждого сайта
   (`wp_2_book_orders`). Удаление из сети — не то же самое, что «убрать с сайта», и FK эту разницу не
   выразит. При `CUSTOM_USER_TABLE` таблица пользователей вообще может быть общей для нескольких
   установок WordPress.
4. **Инструменты и операции** — миграция сайта, staging, `wp db import`, частичное восстановление
   таблиц ядра — не должны спотыкаться о ссылки из таблиц плагина.

Целостность обеспечивают хуки выше и ежедневная проверка `wp uniundata doctor`:

```sql
-- Заказы удалённых пользователей, ещё не прошедшие этап 1
SELECT o.user_id, COUNT(*) AS orders_with_contacts
  FROM wp_book_orders o
  LEFT JOIN wp_users u ON u.ID = o.user_id
 WHERE u.ID IS NULL
   AND o.customer_email NOT LIKE 'erased-%@invalid.invalid'
 GROUP BY o.user_id;

-- Признак повторного использования ID (например, wp_users восстановлен из старого дампа):
-- пользователь зарегистрирован позже, чем оформлен «его» заказ. Должно быть 0 строк.
SELECT o.id, o.user_id
  FROM wp_book_orders o
  JOIN wp_users u ON u.ID = o.user_id
 WHERE u.user_registered > o.created_at;
```

`LEFT JOIN … WHERE u.ID IS NULL` выполняется через `eq_ref` по первичному ключу `wp_users`
(«Not exists» в `EXPLAIN`). Для ежедневной задачи полного прохода по заказам достаточно, а с колонкой
`pii_redacted_at` он сужается индексом. `wp_users.user_registered` WordPress пишет в UTC, как и
`created_at` плагина.

## 7.13 Что проверяют тесты

- Гость: 401 на `/cart/reserve` и `/checkout`. `GET /catalog/availability` — 200 без cookie.
- `book_catalog_manager` получает 403 на `/cart/reserve`. `book_customer` — 403 на `/admin/*`.
- `current_user_can('view_book_order', $id)`: владелец — `true`; другой покупатель — `false` и 404 в REST;
  `book_order_manager` — `true`; `book_catalog_manager` — `false`.
- `Roles::install()` дважды подряд не меняет `wp_user_roles`. Увеличение `VERSION` с удалённой из
  карты capability снимает её только у ролей плагина.
- Удаление пользователя с `pending_payment` из админки запрещено (`delete_user` → `do_not_allow`).
  Удаление через `wp user delete` снимает резервы, отменяет неоплаченные заказы, а задача очистки
  проводит этап 1. Строки заказов и продаж остаются.
- Эрейзер: профиль удалён, IP в согласиях — `NULL`, закрытые заказы — `erased-…@invalid.invalid`,
  заказ в `paid` не изменён, в ответе `items_retained = true` и сообщение.
- Экспортер постранично возвращает все заказы (`done = false` до последней страницы).
