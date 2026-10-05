<?php

declare(strict_types=1);

namespace Uniundata\Books;

use Uniundata\Books\Cli\Commands;
use Uniundata\Books\Cron\Scheduler;
use Uniundata\Books\Domain\DomainError;
use Uniundata\Books\Domain\ReservationStatus;
use Uniundata\Books\Infrastructure\AuditLog;
use Uniundata\Books\Infrastructure\Db;
use Uniundata\Books\Install\Migrator;
use Uniundata\Books\Install\Roles;
use Uniundata\Books\Payment\PaymentProviderInterface;
use Uniundata\Books\Service\CheckoutService;
use Uniundata\Books\Service\ReservationService;
use Uniundata\Books\Sync\MarcExtractor;
use Uniundata\Books\Sync\SourceClientInterface;
use Uniundata\Books\Sync\SyncService;

/**
 * Bootstrap плагина: контейнер сервисов, хуки WordPress, REST, Action Scheduler, WP-CLI, права, приватность.
 *
 * Порядок (docs/01-architecture.md § 1.4):
 *   register_activation_hook → activate(): окружение + миграции + роли + options по умолчанию;
 *   plugins_loaded (5)       → boot(): догоняющая миграция, остальные хуки;
 *   init                     → Roles::maybeUpgrade(), register_meta(middle_name);
 *   rest_api_init            → Rest\*Controller::register_routes();
 *   Action Scheduler         → Scheduler::register(): обработчики ВСЕХ задач плагина (группа 'uniundata') —
 *                              recurring uniundata_expire_reservations / _expire_orders (60 с), _sync_daily (03:15 UTC),
 *                              _abandon_carts и _privacy_retention (ежедневно); async uniundata_order_paid {order_id},
 *                              _refund_payment {refund_id} → PaymentService::processRefund(),
 *                              _order_needs_attention {order_id, reason}, _user_deleted_cleanup {user_id},
 *                              _sync_continue {source, run_id}, _sync_alert {run_id, code};
 *   map_meta_cap             → view_book_order, delete_user;
 *   delete_user / wpmu_delete_user → снятие резервов, отмена неоплаченных заказов, отложенное обезличивание;
 *   wp_privacy_personal_data_exporters / _erasers → экспорт и обезличивание данных магазина (152-ФЗ; GDPR).
 *
 * Контейнер — минимальный: явные фабрики для сервисов с настройками и autowiring по типам конструктора
 * для остальных классов плагина (контроллеры, сервисы без скалярных параметров).
 */
final class Plugin
{
    public const VERSION = '1.0.0';
    public const TEXT_DOMAIN = 'uniundata-books';

    /** Контроллеры REST (namespace uniundata/v1). Каждый реализует register_routes(). */
    private const CONTROLLERS = [
        Rest\CatalogController::class,
        Rest\CartController::class,
        Rest\CheckoutController::class,
        Rest\OrderController::class,
        Rest\PaymentWebhookController::class,
        Rest\AdminController::class,
    ];

    /** Деньги «в пути» или заказ в исполнении: удалять такого покупателя из админки нельзя. */
    private const MONEY_IN_FLIGHT = ['pending_payment', 'payment_processing', 'paid'];
    /** Неоплаченные заказы, которые отменяются при удалении аккаунта. */
    private const CANCELLABLE_ORDERS = ['draft', 'pending_payment', 'payment_failed'];
    /**
     * «Закрытый» заказ для целей ПДн (docs/07 § 7.11): исполнен, возвращён или не оплачен, без открытого
     * разбора и без незавершённого возврата. Алиас таблицы — o.
     */
    private const CLOSED_ORDER_SQL = "(o.status IN ('completed', 'refunded', 'cancelled', 'payment_expired')
                                       OR (o.status = 'partially_refunded' AND o.fulfilled_at IS NOT NULL))
                                      AND o.needs_attention = 0
                                      AND NOT EXISTS (SELECT 1 FROM %s rf WHERE rf.order_id = o.id AND rf.status IN ('requested', 'pending'))";

    /**
     * Сроки хранения ПДн по умолчанию — технические заглушки, их утверждает юрист (152-ФЗ: срок — до
     * достижения цели обработки; бухгалтерские документы — по 402-ФЗ). Меняются фильтрами
     * uniundata_retention_contact_days, uniundata_consent_ip_retention_days, uniundata_retention_accounting_years.
     */
    public const RETENTION_CONTACT_DAYS = 730;
    public const RETENTION_CONSENT_IP_DAYS = 180;
    public const RETENTION_ACCOUNTING_YEARS = 10;
    private const RETENTION_BATCH = 500;
    /** Маркер этапа 2 (ФИО). Этап 1 отмечается колонкой orders.pii_erased_at. */
    private const ANONYMIZED = 'Anonymized';

    private static ?self $instance = null;

    /** @var array<string, object> */
    private array $services = [];

    /** @var array<string, \Closure(self): object> */
    private array $factories;

    /** Схема на версии кода: иначе REST и фоновые задачи не регистрируются (admin notice). */
    private bool $schemaReady = true;
    private ?string $schemaError = null;

    /** @var array<int, int|null> Владелец заказа (map_meta_cap вызывается на каждый current_user_can). */
    private array $orderOwners = [];

    private function __construct()
    {
        $this->factories = [
            self::class => static fn (self $p): object => $p,
            \wpdb::class => static fn (): object => $GLOBALS['wpdb'],
            Db::class => static fn (self $p): object => new Db($p->wpdb()),
            AuditLog::class => static fn (self $p): object => new AuditLog($p->get(Db::class)),
            ReservationService::class => static fn (self $p): object => new ReservationService(
                $p->get(Db::class),
                $p->get(AuditLog::class),
                max(1, (int) get_option('uniundata_reservation_minutes', 60)),
            ),
            PaymentProviderInterface::class => static function (): object {
                // Адаптер конкретного банка подключается фильтром (секреты — из wp-config.php / env).
                $provider = apply_filters('uniundata_payment_provider', null);
                if (!$provider instanceof PaymentProviderInterface) {
                    throw new \RuntimeException('No payment provider configured (filter uniundata_payment_provider)');
                }

                return $provider;
            },
            MarcExtractor::class => static function (): object {
                $converter = apply_filters('uniundata_marc_converter', null); // ISO 2709/MRK → MARCXML
                return new MarcExtractor($converter instanceof \Closure ? $converter : null);
            },
            SyncService::class => static function (self $p): object {
                $sources = array_filter(
                    (array) apply_filters('uniundata_sync_sources', []),
                    static fn (mixed $s): bool => $s instanceof SourceClientInterface,
                );

                return new SyncService(
                    $p->get(Db::class),
                    $p->get(AuditLog::class),
                    $p->get(MarcExtractor::class),
                    $sources,
                    (float) apply_filters('uniundata_sync_missing_threshold', SyncService::MISSING_THRESHOLD),
                );
            },
            Migrator::class => static fn (self $p): object => new Migrator($p->get(Db::class), self::schemaFile()),
        ];
    }

    public static function instance(): self
    {
        return self::$instance ??= new self();
    }

    // =============================================================================================
    // Жизненный цикл
    // =============================================================================================

    /** register_activation_hook. Ошибка окружения останавливает активацию с понятным сообщением. */
    public static function activate(bool $networkWide = false): void
    {
        if (is_multisite() && $networkWide) {
            // На больших сетях — `wp site list --field=url | xargs -I{} wp --url={} uniundata migrate`.
            foreach (get_sites(['fields' => 'ids', 'number' => 0]) as $siteId) {
                switch_to_blog((int) $siteId);
                try {
                    self::installForSite();
                } finally {
                    restore_current_blog();
                }
            }

            return;
        }
        self::installForSite();
    }

    /** Таблицы (с префиксом текущего сайта), роли и options по умолчанию. Также wp_initialize_site. */
    public static function installForSite(): void
    {
        $plugin = self::instance();
        try {
            $plugin->get(Migrator::class)->migrate(30);
        } catch (\RuntimeException $e) {
            wp_die(
                esc_html($e->getMessage()),
                esc_html__('Uniundata Books cannot be activated', 'uniundata-books'),
                ['back_link' => true],
            );
        }
        Roles::install();

        // add_option не перезаписывает значения администратора при повторной активации.
        add_option('uniundata_payment_ttl_minutes', 30);
        add_option('uniundata_payment_grace_minutes', 10);
        add_option('uniundata_reservation_minutes', 60); // менять только вместе с бизнес-правилами
        add_option('uniundata_max_active_reservations', 10);
        add_option('uniundata_sync_source', 'primary', '', false);
        add_option('uniundata_terms_versions', [], '', false); // заполняет администратор: version + sha256
        // uniundata_currency (ISO 4217, «валюта магазина») по умолчанию НЕ задаётся: её выбирает администратор
        // (`wp option update uniundata_currency RUB`). Пока её нет — резерв, checkout и синхронизация
        // отказывают, а в админке висит уведомление (configProblems()).
    }

    /** Таблицы, роли и данные остаются: деактивация обратима. Снимаются только задачи плагина. */
    public static function deactivate(bool $networkWide = false): void
    {
        if (\function_exists('as_unschedule_all_actions')) {
            foreach (Scheduler::RECURRING_HOOKS as $hook) {
                as_unschedule_all_actions($hook, [], Scheduler::GROUP);
            }
        }
        delete_option(Scheduler::SCHEDULE_VERSION_OPTION);
    }

    /** plugins_loaded, приоритет 5. */
    public static function boot(): void
    {
        $plugin = self::instance();

        // Activation hook не вызывается при обновлении файлами/автообновлении — догоняем здесь.
        // Основной путь — шаг деплоя `wp uniundata migrate`; это страховка (lock wait ≤ 5 с).
        $migrator = $plugin->get(Migrator::class);
        if ($migrator->needsMigration()) {
            try {
                $migrator->migrate((\defined('WP_CLI') && WP_CLI) || wp_doing_cron() ? 30 : 5);
            } catch (\RuntimeException $e) {
                $plugin->schemaReady = false;
                $plugin->schemaError = $e->getMessage();
                error_log('[uniundata] schema migration pending: ' . $e->getMessage());
            }
        }

        $plugin->registerHooks();
    }

    private function registerHooks(): void
    {
        add_action('init', [Roles::class, 'maybeUpgrade']);
        add_action('init', [$this, 'registerUserMeta']);
        // Переводы — не раньше init (WordPress 6.7+ иначе пишет notice о слишком ранней загрузке).
        add_action('init', static fn () => load_plugin_textdomain(
            self::TEXT_DOMAIN,
            false,
            \dirname(plugin_basename(self::pluginFile())) . '/languages',
        ));
        add_filter('map_meta_cap', [$this, 'mapMetaCap'], 10, 4);
        add_action('wp_initialize_site', static function (\WP_Site $site): void {
            if (!\function_exists('is_plugin_active_for_network')) {
                require_once ABSPATH . 'wp-admin/includes/plugin.php'; // сайт может создаваться не из админки (REST, CLI)
            }
            if (!is_plugin_active_for_network(plugin_basename(self::pluginFile()))) {
                return;
            }
            switch_to_blog((int) $site->blog_id);
            try {
                self::installForSite();
            } finally {
                restore_current_blog();
            }
        }, 200);

        // Удаление пользователя и приватность подписаны всегда; при отстающей схеме обработчики сами ничего не делают.
        add_action('delete_user', [$this, 'onDeleteUser'], 10, 1);
        add_action('wpmu_delete_user', [$this, 'onDeleteNetworkUser'], 10, 1);
        add_filter('wp_privacy_personal_data_exporters', [$this, 'registerPrivacyExporters']);
        add_filter('wp_privacy_personal_data_erasers', [$this, 'registerPrivacyErasers']);
        add_action('admin_notices', [$this, 'adminNotices']);

        // Обработчики задач Action Scheduler подписываются в КАЖДОМ процессе (WP-CLI, WP-Cron, async runner) и
        // при отстающей схеме тоже: задача без обработчика помечается failed и теряется, а Scheduler такую
        // задачу откладывает на 5 минут (recurring-задачи при этом не ставятся).
        $this->get(Scheduler::class)->register();

        if (\defined('WP_CLI') && WP_CLI) {
            // migrate/doctor доступны и при отстающей схеме — именно ими её и чинят.
            Commands::register($this);
        }

        if ($this->schemaReady) {
            add_action('rest_api_init', [$this, 'registerRestRoutes']);
        }
    }

    // =============================================================================================
    // Контейнер
    // =============================================================================================

    /**
     * @template T of object
     * @param class-string<T> $id
     * @return T
     */
    public function get(string $id): object
    {
        if (isset($this->services[$id])) {
            return $this->services[$id];
        }
        $factory = $this->factories[$id] ?? null;
        $service = $factory !== null ? $factory($this) : $this->autowire($id);

        return $this->services[$id] = $service;
    }

    public function has(string $id): bool
    {
        return isset($this->services[$id]) || isset($this->factories[$id])
            || (str_starts_with($id, __NAMESPACE__ . '\\') && class_exists($id));
    }

    /** Подмена сервиса (тесты, кастомный провайдер). */
    public function set(string $id, object $service): void
    {
        $this->services[$id] = $service;
    }

    public function wpdb(): \wpdb
    {
        return $GLOBALS['wpdb'];
    }

    private function autowire(string $class): object
    {
        if (!str_starts_with($class, __NAMESPACE__ . '\\') || !class_exists($class)) {
            throw new \RuntimeException(\sprintf('Cannot resolve service "%s"', $class));
        }
        $ref = new \ReflectionClass($class);
        if (!$ref->isInstantiable()) {
            throw new \RuntimeException(\sprintf('Service "%s" is not instantiable', $class));
        }
        $ctor = $ref->getConstructor();
        if ($ctor === null) {
            return $ref->newInstance();
        }
        $args = [];
        foreach ($ctor->getParameters() as $param) {
            $type = $param->getType();
            if ($type instanceof \ReflectionNamedType && !$type->isBuiltin() && $this->has($type->getName())) {
                $args[] = $this->get($type->getName());
            } elseif ($param->isDefaultValueAvailable()) {
                $args[] = $param->getDefaultValue();
            } elseif ($type !== null && $type->allowsNull()) {
                $args[] = null;
            } else {
                throw new \RuntimeException(\sprintf('Cannot autowire $%s of %s', $param->getName(), $class));
            }
        }

        return $ref->newInstanceArgs($args);
    }

    // =============================================================================================
    // REST
    // =============================================================================================

    public function registerRestRoutes(): void
    {
        foreach (self::CONTROLLERS as $class) {
            if (!class_exists($class)) {
                continue;
            }
            try {
                $this->get($class)->register_routes();
            } catch (\Throwable $e) {
                // Например, не настроен банк: каталог и корзина работают, оплата — нет (маршрут не появится, 404).
                error_log(\sprintf('[uniundata] REST controller %s is disabled: %s', $class, $e->getMessage()));
            }
        }
    }

    // =============================================================================================
    // Права: map_meta_cap
    // =============================================================================================

    /**
     * view_book_order (+ order_id): владелец → view_own_book_orders, остальные → manage_book_orders.
     * delete_user (+ user_id): do_not_allow, если у покупателя деньги «в пути» (админка и REST /wp/v2/users).
     *
     * @param list<string> $caps
     * @param array<int, mixed> $args
     * @return list<string>
     */
    public function mapMetaCap(array $caps, string $cap, int $userId, array $args): array
    {
        if ($cap === 'view_book_order') {
            $orderId = (int) ($args[0] ?? 0);
            if ($userId <= 0 || $orderId <= 0 || !$this->schemaReady) {
                return ['do_not_allow'];
            }
            $owner = $this->orderOwner($orderId);
            if ($owner === null) {
                return ['do_not_allow'];
            }

            return [$owner === $userId ? 'view_own_book_orders' : 'manage_book_orders'];
        }
        if ($cap === 'delete_user' && isset($args[0]) && (int) $args[0] > 0 && $this->schemaReady) {
            return $this->hasMoneyInFlight((int) $args[0]) ? ['do_not_allow'] : $caps;
        }

        return $caps;
    }

    private function orderOwner(int $orderId): ?int
    {
        if (!\array_key_exists($orderId, $this->orderOwners)) {
            $db = $this->get(Db::class);
            $owner = $db->getVar("SELECT user_id FROM {$db->table('orders')} WHERE id = %d", $orderId);
            $this->orderOwners[$orderId] = $owner === null ? null : (int) $owner;
        }

        return $this->orderOwners[$orderId];
    }

    private function hasMoneyInFlight(int $userId): bool
    {
        $db = $this->get(Db::class);

        return $db->getVar(
            "SELECT EXISTS (SELECT 1 FROM {$db->table('orders')}
                             WHERE user_id = %d AND status IN ({$db->placeholders(\count(self::MONEY_IN_FLIGHT), '%s')}))",
            $userId,
            ...self::MONEY_IN_FLIGHT,
        ) === '1';
    }

    // =============================================================================================
    // Удаление пользователя
    // =============================================================================================

    /**
     * delete_user срабатывает ДО удаления строки wp_users и не умеет отменить удаление (wp_die оставил бы
     * полуудалённого пользователя), поэтому запрет — только через map_meta_cap выше, а здесь — корректное
     * завершение при любом состоянии заказов (WP-CLI и wp_delete_user() права не проверяют):
     *   активные резервы → cancelled (release_reason user_deleted) с освобождением экземпляров;
     *   открытая корзина → abandoned; неоплаченные заказы → cancelled;
     *   PII — задачей после удаления строки (заказы и продажи остаются с прежним user_id — псевдоним).
     */
    public function onDeleteUser(int $userId): void
    {
        if (is_multisite()) {
            return; // «убрать с сайта»: аккаунт в сети остаётся, его заказы — его заказы
        }
        $this->releaseUser($userId);
    }

    public function onDeleteNetworkUser(int $userId): void
    {
        foreach (get_sites(['fields' => 'ids', 'number' => 0]) as $siteId) {
            switch_to_blog((int) $siteId);
            try {
                if ($this->activeOnCurrentSite()) {
                    $this->releaseUser($userId);
                }
            } finally {
                restore_current_blog();
            }
        }
    }

    private function releaseUser(int $userId): void
    {
        if (!$this->schemaReady || $userId <= 0) {
            return;
        }
        $db = $this->get(Db::class);
        $actorId = get_current_user_id();
        $actorType = $actorId > 0 ? 'admin' : ((\defined('WP_CLI') && WP_CLI) ? 'cli' : 'system');
        $reservations = $this->get(ReservationService::class);

        // 1. Активные резервы — по одному, в глобальном порядке блокировок: корзина → экземпляр → резерв.
        foreach ($db->getResults(
            "SELECT id, cart_id, book_item_id FROM {$db->table('reservations')}
              WHERE user_id = %d AND reservation_status = 'active'",
            $userId,
        ) as $r) {
            try {
                $db->transaction(function () use ($db, $reservations, $r, $actorType, $actorId): void {
                    $cart = $r['cart_id'] !== null ? $reservations->lockCartById((int) $r['cart_id']) : null;
                    $item = $reservations->lockItem((int) $r['book_item_id']);
                    $res = $reservations->lockReservation((int) $r['id']);
                    if ($item === null || $res === null || $res['status'] !== ReservationStatus::Active->value) {
                        return;
                    }
                    $now = $db->now();
                    $reservations->releaseLocked($res, $item, ReservationStatus::Cancelled, 'user_deleted', $now, $actorType, $actorId ?: null);
                    if ($cart !== null) {
                        $reservations->refreshCartLocked($cart, $now, false, 'abandoned', $actorType, $actorId ?: null);
                    }
                });
            } catch (\Throwable $e) {
                error_log(\sprintf('[uniundata] user %d: cannot release reservation #%d: %s', $userId, (int) $r['id'], $e->getMessage()));
            }
        }

        // 2. Пустая открытая корзина → abandoned.
        $cartId = $db->getVar("SELECT id FROM {$db->table('carts')} WHERE open_cart_user_id = %d", $userId);
        if ($cartId !== null) {
            $db->transaction(function () use ($reservations, $cartId, $db, $actorType, $actorId): void {
                $cart = $reservations->lockCartById((int) $cartId);
                if ($cart !== null) {
                    $reservations->refreshCartLocked($cart, $db->now(), false, 'abandoned', $actorType, $actorId ?: null);
                }
            });
        }

        // 3. Неоплаченные заказы → cancelled (сессии банка закрываются после COMMIT; поздний платёж уйдёт в
        //    общую ветку late payment). payment_processing не трогаем — ждём итог банка.
        $orders = $db->getResults(
            "SELECT public_order_id FROM {$db->table('orders')}
              WHERE user_id = %d AND status IN ({$db->placeholders(\count(self::CANCELLABLE_ORDERS), '%s')})",
            $userId,
            ...self::CANCELLABLE_ORDERS,
        );
        if ($orders !== []) {
            try {
                $checkout = $this->get(CheckoutService::class);
                foreach ($orders as $o) {
                    try {
                        $checkout->cancel($userId, (string) $o['public_order_id']);
                    } catch (DomainError $e) {
                        error_log(\sprintf('[uniundata] user %d: order %s not cancelled: %s', $userId, $o['public_order_id'], $e->errorCode()));
                    }
                }
            } catch (\RuntimeException $e) {
                // Провайдер не настроен: заказ закроет OrderExpiryService по payment_due_at.
                error_log('[uniundata] user deletion: ' . $e->getMessage());
            }
        }

        // 4. ПДн — после удаления строки wp_users, идемпотентно, задачей Action Scheduler. Без unique: в AS 3.9
        //    уникальность проверяется по hook + group БЕЗ args, и задача второго удалённого пользователя пропала бы.
        if (\function_exists('as_enqueue_async_action')) {
            as_enqueue_async_action(Scheduler::HOOK_USER_CLEANUP, ['user_id' => $userId], Scheduler::GROUP);
        }
    }

    /** Задача uniundata_user_deleted_cleanup: удаление состоялось → обезличить данные магазина. */
    public function cleanupDeletedUser(int $userId): void
    {
        if ($userId <= 0 || get_userdata($userId) !== false) {
            return; // удаление не состоялось — данные не трогаем
        }
        $this->redactUser($userId, 'user_deleted');
    }

    private function activeOnCurrentSite(): bool
    {
        $basename = plugin_basename(self::pluginFile());

        return \array_key_exists($basename, (array) get_site_option('active_sitewide_plugins', []))
            || \in_array($basename, (array) get_option('active_plugins', []), true);
    }

    // =============================================================================================
    // Персональные данные (152-ФЗ; GDPR — для покупателей из ЕС): экспорт, удаление, сроки хранения
    // =============================================================================================

    /**
     * @param array<string, array<string, mixed>> $exporters
     * @return array<string, array<string, mixed>>
     */
    public function registerPrivacyExporters(array $exporters): array
    {
        $exporters['uniundata-books-profile'] = [
            'exporter_friendly_name' => __('Book shop: customer profile and consents', 'uniundata-books'),
            'callback' => [$this, 'exportProfile'],
        ];
        $exporters['uniundata-books-orders'] = [
            'exporter_friendly_name' => __('Book shop: orders', 'uniundata-books'),
            'callback' => [$this, 'exportOrders'],
        ];

        return $exporters;
    }

    /**
     * @param array<string, array<string, mixed>> $erasers
     * @return array<string, array<string, mixed>>
     */
    public function registerPrivacyErasers(array $erasers): array
    {
        $erasers['uniundata-books'] = [
            'eraser_friendly_name' => __('Book shop', 'uniundata-books'),
            'callback' => [$this, 'erasePersonalData'],
        ];

        return $erasers;
    }

    /** @return array{data: list<array<string, mixed>>, done: bool} */
    public function exportProfile(string $email, int $page = 1): array
    {
        $user = get_user_by('email', $email);
        if (!$user instanceof \WP_User || !$this->schemaReady) {
            return ['data' => [], 'done' => true];
        }
        $db = $this->get(Db::class);
        $data = [];
        $profile = $db->getRow(
            "SELECT phone_e164, default_shipping_address, default_billing_address, company_name, vat_id
               FROM {$db->table('customer_profiles')} WHERE user_id = %d",
            (int) $user->ID,
        );
        $middle = (string) get_user_meta((int) $user->ID, 'middle_name', true);
        if ($profile !== null || $middle !== '') {
            $data[] = [
                'group_id' => 'uniundata-book-profile',
                'group_label' => __('Book shop profile', 'uniundata-books'),
                'item_id' => 'book-profile-' . (int) $user->ID,
                'data' => array_values(array_filter([
                    ['name' => __('Middle name', 'uniundata-books'), 'value' => $middle],
                    ['name' => __('Phone', 'uniundata-books'), 'value' => (string) ($profile['phone_e164'] ?? '')],
                    ['name' => __('Default shipping address', 'uniundata-books'), 'value' => self::addressText($profile['default_shipping_address'] ?? null)],
                    ['name' => __('Default billing address', 'uniundata-books'), 'value' => self::addressText($profile['default_billing_address'] ?? null)],
                    ['name' => __('Company', 'uniundata-books'), 'value' => (string) ($profile['company_name'] ?? '')],
                    ['name' => __('VAT ID', 'uniundata-books'), 'value' => (string) ($profile['vat_id'] ?? '')],
                ], static fn (array $f): bool => $f['value'] !== '')),
            ];
        }
        foreach ($db->getResults(
            "SELECT consent_uuid, consent_type, document_version, accepted_at, withdrawn_at, INET6_NTOA(ip_address) AS ip
               FROM {$db->table('user_consents')} WHERE user_id = %d ORDER BY id",
            (int) $user->ID,
        ) as $c) {
            $data[] = [
                'group_id' => 'uniundata-book-consents',
                'group_label' => __('Book shop consents', 'uniundata-books'),
                'item_id' => 'book-consent-' . $c['consent_uuid'],
                'data' => [
                    ['name' => __('Type', 'uniundata-books'), 'value' => (string) $c['consent_type']],
                    ['name' => __('Document version', 'uniundata-books'), 'value' => (string) $c['document_version']],
                    ['name' => __('Accepted at (UTC)', 'uniundata-books'), 'value' => (string) $c['accepted_at']],
                    ['name' => __('Withdrawn at (UTC)', 'uniundata-books'), 'value' => (string) ($c['withdrawn_at'] ?? '')],
                    ['name' => __('IP address', 'uniundata-books'), 'value' => (string) ($c['ip'] ?? '')],
                ],
            ];
        }

        return ['data' => $data, 'done' => true];
    }

    /** @return array{data: list<array<string, mixed>>, done: bool} */
    public function exportOrders(string $email, int $page = 1): array
    {
        $user = get_user_by('email', $email);
        if (!$user instanceof \WP_User || !$this->schemaReady) {
            return ['data' => [], 'done' => true]; // аккаунта нет — заказы уже обезличены
        }
        $perPage = 50;
        $db = $this->get(Db::class);
        $rows = $db->getResults(
            "SELECT id, public_order_id, status, currency, total_amount, placed_at, paid_at, customer_email,
                    customer_phone, customer_first_name, customer_last_name, customer_middle_name,
                    billing_address_json, shipping_address_json
               FROM {$db->table('orders')}
              WHERE user_id = %d
              ORDER BY id
              LIMIT %d OFFSET %d",
            (int) $user->ID,
            $perPage,
            (max(1, $page) - 1) * $perPage,
        );
        $data = [];
        foreach ($rows as $o) {
            $titles = array_column($db->getResults(
                "SELECT title_snapshot FROM {$db->table('order_items')} WHERE order_id = %d ORDER BY id",
                (int) $o['id'],
            ), 'title_snapshot');
            $data[] = [
                'group_id' => 'uniundata-book-orders',
                'group_label' => __('Book orders', 'uniundata-books'),
                'item_id' => 'book-order-' . $o['public_order_id'],
                'data' => [
                    ['name' => __('Order number', 'uniundata-books'), 'value' => (string) $o['public_order_id']],
                    ['name' => __('Status', 'uniundata-books'), 'value' => (string) $o['status']],
                    ['name' => __('Placed at (UTC)', 'uniundata-books'), 'value' => (string) $o['placed_at']],
                    ['name' => __('Paid at (UTC)', 'uniundata-books'), 'value' => (string) ($o['paid_at'] ?? '')],
                    ['name' => __('Total', 'uniundata-books'), 'value' => number_format(((int) $o['total_amount']) / 100, 2, '.', '') . ' ' . $o['currency']],
                    ['name' => __('Customer', 'uniundata-books'), 'value' => trim(implode(' ', array_filter([
                        $o['customer_last_name'], $o['customer_first_name'], $o['customer_middle_name'],
                    ])))],
                    ['name' => 'Email', 'value' => (string) $o['customer_email']],
                    ['name' => __('Phone', 'uniundata-books'), 'value' => (string) ($o['customer_phone'] ?? '')],
                    ['name' => __('Shipping address', 'uniundata-books'), 'value' => self::addressText($o['shipping_address_json'])],
                    ['name' => __('Billing address', 'uniundata-books'), 'value' => self::addressText($o['billing_address_json'])],
                    ['name' => __('Books', 'uniundata-books'), 'value' => implode('; ', $titles)],
                ],
            ];
        }

        return ['data' => $data, 'done' => \count($rows) < $perPage];
    }

    /**
     * Эрейзер: удаляем, что можно (профиль, middle_name, маркетинговые согласия, IP), закрытые заказы —
     * обезличиваем (этап 1), строки заказов/платежей/продаж не удаляем (бухгалтерия, ТЗ). Аккаунт не удаляется.
     *
     * @return array{items_removed: bool, items_retained: bool, messages: list<string>, done: bool}
     */
    public function erasePersonalData(string $email, int $page = 1): array
    {
        $user = get_user_by('email', $email);
        if (!$user instanceof \WP_User || !$this->schemaReady) {
            return ['items_removed' => false, 'items_retained' => false, 'messages' => [], 'done' => true];
        }
        // Метка для ежедневной задачи: заказы, которые закроются позже, пройдут этап 1 автоматически.
        update_user_meta((int) $user->ID, 'uniundata_erasure_requested_at', gmdate('Y-m-d H:i:s'));
        $deletedMeta = delete_user_meta((int) $user->ID, 'middle_name');
        $r = $this->redactUser((int) $user->ID, 'erasure_request');

        $messages = [];
        if ($r['open_orders'] > 0) {
            /* translators: %d: number of orders */
            $messages[] = \sprintf(__('Orders in progress: %d. Contact details will be removed once they are closed.', 'uniundata-books'), $r['open_orders']);
        }
        if ($r['retained_orders'] > 0) {
            /* translators: %d: number of orders */
            $messages[] = \sprintf(__('Orders kept for accounting: %d. Names and billing addresses are anonymised after the retention period.', 'uniundata-books'), $r['retained_orders']);
        }

        return [
            'items_removed' => $deletedMeta || $r['profile_deleted'] || $r['orders_redacted'] > 0 || $r['consents_minimized'] > 0,
            'items_retained' => $r['open_orders'] > 0 || $r['retained_orders'] > 0,
            'messages' => $messages,
            'done' => true,
        ];
    }

    /**
     * Задача uniundata_privacy_retention (ежедневно). Каждый шаг — пакет одиночных UPDATE по wp_book_orders или
     * wp_book_user_consents без других блокировок (взаимной блокировки с checkout/webhook быть не может).
     *   1. IP и хэш UA в согласиях старше RETENTION_CONSENT_IP_DAYS — NULL (ix_consents_retention).
     *   2. Этап 1 (контакты) для закрытых заказов, у которых pii_erased_at IS NULL и:
     *      истёк RETENTION_CONTACT_DAYS (ix_orders_retention), ИЛИ аккаунт удалён, ИЛИ был запрос на удаление.
     *   3. Этап 2 (ФИО, платёжный адрес) после бухгалтерского срока RETENTION_ACCOUNTING_YEARS.
     *
     * @return array{consents: int, contacts: int, names: int}
     */
    public function privacyRetention(int $batch = self::RETENTION_BATCH): array
    {
        if (!$this->schemaReady) {
            return ['consents' => 0, 'contacts' => 0, 'names' => 0];
        }
        $db = $this->get(Db::class);
        $wpdb = $this->wpdb();
        $batch = max(1, min($batch, 5000));
        $ipDays = max(1, (int) apply_filters('uniundata_consent_ip_retention_days', self::RETENTION_CONSENT_IP_DAYS));
        $contactDays = max(1, (int) apply_filters('uniundata_retention_contact_days', self::RETENTION_CONTACT_DAYS));
        $years = max(1, (int) apply_filters('uniundata_retention_accounting_years', self::RETENTION_ACCOUNTING_YEARS));
        $orders = $db->table('orders');
        $closed = $this->closedOrderSql($db);

        $consents = $db->execute(
            "UPDATE {$db->table('user_consents')} SET ip_address = NULL, user_agent_sha256 = NULL
              WHERE accepted_at < UTC_TIMESTAMP(6) - INTERVAL %d DAY
                AND (ip_address IS NOT NULL OR user_agent_sha256 IS NOT NULL)
              LIMIT %d",
            $ipDays,
            $batch,
        );

        // Кандидаты — обычным чтением (двумя запросами: без OR оба идут range по ix_orders_retention), UPDATE
        // перепроверяет условие на заблокированной версии строки. ORDER BY не нужен: обработанные строки
        // выпадают из условия (pii_erased_at), а ORDER BY id увёл бы план на полный проход по PRIMARY.
        $ids = array_column($db->getResults(
            "SELECT o.id FROM {$orders} o
              WHERE o.pii_erased_at IS NULL AND {$closed} AND o.updated_at < UTC_TIMESTAMP(6) - INTERVAL %d DAY
              LIMIT %d",
            $contactDays,
            $batch,
        ), 'id');
        // Догоняющий этап 1: заказы удалённых аккаунтов и запросивших удаление, закрытые уже после запроса.
        $ids = array_merge($ids, array_column($db->getResults(
            "SELECT o.id FROM {$orders} o
              WHERE o.pii_erased_at IS NULL AND {$closed}
                AND (NOT EXISTS (SELECT 1 FROM {$wpdb->users} u WHERE u.ID = o.user_id)
                     OR EXISTS (SELECT 1 FROM {$wpdb->usermeta} m
                                 WHERE m.user_id = o.user_id AND m.meta_key = 'uniundata_erasure_requested_at'))
              LIMIT %d",
            $batch,
        ), 'id'));
        $ids = array_values(array_unique(array_map('intval', $ids)));
        $contacts = $ids === [] ? 0 : $this->eraseContacts($db, 'o.id IN (' . $db->placeholders(\count($ids)) . ')', $ids);

        $names = $db->execute(
            "UPDATE {$orders} o
                SET o.customer_first_name = %s, o.customer_last_name = %s, o.customer_middle_name = NULL,
                    o.billing_address_json = IF(o.billing_address_json IS NULL, NULL,
                        JSON_OBJECT('v', 1, 'redacted', TRUE,
                                    'country_code', JSON_UNQUOTE(JSON_EXTRACT(o.billing_address_json, '$.country_code'))))
              WHERE o.pii_erased_at IS NOT NULL
                AND o.customer_last_name <> %s
                AND COALESCE(o.completed_at, o.cancelled_at, o.paid_at, o.placed_at, o.created_at)
                    < UTC_TIMESTAMP(6) - INTERVAL %d YEAR
              LIMIT %d",
            self::ANONYMIZED,
            self::ANONYMIZED,
            self::ANONYMIZED,
            $years,
            $batch,
        );

        if ($consents + $contacts + $names > 0) {
            $this->get(AuditLog::class)->record('privacy.retention_applied', 'system', null, null, null, [
                'consents_minimized' => $consents,
                'orders_contacts_erased' => $contacts,
                'orders_names_anonymized' => $names,
            ], 'cron');
        }

        return ['consents' => $consents, 'contacts' => $contacts, 'names' => $names];
    }

    /**
     * Этап 1 обезличивания пользователя (docs/07 § 7.11). Идемпотентно; одиночные UPDATE без других блокировок.
     *
     * @return array{profile_deleted: bool, consents_minimized: int, orders_redacted: int, open_orders: int, retained_orders: int}
     */
    private function redactUser(int $userId, string $reason): array
    {
        $db = $this->get(Db::class);
        $orders = $db->table('orders');
        $closed = $this->closedOrderSql($db);
        $profileDeleted = $db->execute("DELETE FROM {$db->table('customer_profiles')} WHERE user_id = %d", $userId) > 0;
        $consents = $db->execute(
            "UPDATE {$db->table('user_consents')} SET withdrawn_at = UTC_TIMESTAMP(6)
              WHERE user_id = %d AND consent_type = 'marketing' AND withdrawn_at IS NULL",
            $userId,
        );
        $consents += $db->execute(
            "UPDATE {$db->table('user_consents')} SET ip_address = NULL, user_agent_sha256 = NULL
              WHERE user_id = %d AND (ip_address IS NOT NULL OR user_agent_sha256 IS NOT NULL)",
            $userId,
        );
        $redacted = $this->eraseContacts($db, 'o.user_id = %d', [$userId]);
        $open = (int) $db->getVar("SELECT COUNT(*) FROM {$orders} o WHERE o.user_id = %d AND NOT ({$closed})", $userId);
        $retained = (int) $db->getVar("SELECT COUNT(*) FROM {$orders} o WHERE o.user_id = %d AND {$closed}", $userId);
        $this->get(AuditLog::class)->record('privacy.user_redacted', 'user', $userId, null, null, [
            'reason' => $reason,
            'profile_deleted' => $profileDeleted,
            'consents_minimized' => $consents,
            'orders_redacted' => $redacted,
            'open_orders' => $open,
        ], 'system', get_current_user_id() ?: null);

        return [
            'profile_deleted' => $profileDeleted,
            'consents_minimized' => $consents,
            'orders_redacted' => $redacted,
            'open_orders' => $open,
            'retained_orders' => $retained,
        ];
    }

    /**
     * Этап 1 для закрытых заказов по условию $where (алиас o): email → заглушка в зоне .invalid (колонка
     * NOT NULL, RFC 2606 — письмо уйти не может), телефон → NULL, адрес доставки → только страна,
     * pii_erased_at = сейчас. Признак «уже обезличен» — pii_erased_at, а не содержимое полей.
     *
     * @param list<int> $args
     */
    private function eraseContacts(Db $db, string $where, array $args): int
    {
        return $db->execute(
            "UPDATE {$db->table('orders')} o
                SET o.customer_email = CONCAT('erased-', o.id, '@invalid.invalid'),
                    o.customer_phone = NULL,
                    o.shipping_address_json = IF(o.shipping_address_json IS NULL, NULL,
                        JSON_OBJECT('v', 1, 'redacted', TRUE,
                                    'country_code', JSON_UNQUOTE(JSON_EXTRACT(o.shipping_address_json, '$.country_code')))),
                    o.pii_erased_at = UTC_TIMESTAMP(6)
              WHERE {$where} AND o.pii_erased_at IS NULL AND {$this->closedOrderSql($db)}",
            ...$args,
        );
    }

    private function closedOrderSql(Db $db): string
    {
        return '(' . sprintf(self::CLOSED_ORDER_SQL, $db->table('refunds')) . ')';
    }

    private static function addressText(?string $json): string
    {
        $a = $json === null ? null : json_decode($json, true);
        if (!\is_array($a)) {
            return '';
        }
        unset($a['v'], $a['redacted']);

        return implode(', ', array_filter(array_map(
            static fn (mixed $v): string => \is_scalar($v) ? (string) $v : '',
            $a,
        ), static fn (string $v): bool => $v !== ''));
    }

    // =============================================================================================
    // Прочее
    // =============================================================================================

    public function registerUserMeta(): void
    {
        // Отчество необязательно; ключ без префикса — поле того же уровня, что first_name/last_name ядра.
        register_meta('user', 'middle_name', [
            'type' => 'string',
            'single' => true,
            'sanitize_callback' => static fn (mixed $v): string => mb_substr(sanitize_text_field((string) $v), 0, 100),
            'auth_callback' => static fn (bool $allowed, string $key, int $userId): bool => current_user_can('edit_user', $userId),
            'show_in_rest' => false,
        ]);
    }

    /** Схема не на версии кода или магазин не настроен — уведомление администратору. */
    public function adminNotices(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }
        $problems = $this->schemaReady
            ? $this->configProblems()
            : [__('The database schema is not up to date. The shop is disabled until `wp uniundata migrate` succeeds.', 'uniundata-books') . ' ' . $this->schemaError];
        foreach ($problems as $problem) {
            printf('<div class="notice notice-error"><p><strong>Uniundata Books:</strong> %s</p></div>', esc_html($problem));
        }
    }

    /**
     * Обязательные настройки, без которых магазин отказывает (резерв/checkout — 500, синхронизация — failed).
     *
     * @return list<string>
     */
    public function configProblems(): array
    {
        $problems = [];
        if (preg_match('/^[A-Z]{3}$/', (string) get_option('uniundata_currency', '')) !== 1) {
            $problems[] = __('Shop currency is not set: wp option update uniundata_currency RUB (ISO 4217).', 'uniundata-books');
        }
        $terms = get_option('uniundata_terms_versions', []);
        foreach (['offer', 'privacy'] as $type) {
            $t = \is_array($terms) ? ($terms[$type] ?? null) : null;
            if (!\is_array($t) || !isset($t['version'], $t['sha256']) || preg_match('/^[0-9a-f]{64}$/', (string) $t['sha256']) !== 1) {
                /* translators: %s: offer|privacy */
                $problems[] = \sprintf(__('Current %s document version is not set (option uniundata_terms_versions: version + sha256).', 'uniundata-books'), $type);
            }
        }
        if (!apply_filters('uniundata_payment_provider', null) instanceof PaymentProviderInterface) {
            $problems[] = __('No payment provider configured (filter uniundata_payment_provider): checkout is disabled.', 'uniundata-books');
        }
        if (!\function_exists('as_enqueue_async_action')) {
            $problems[] = __('Action Scheduler is not loaded: reservations and unpaid orders are not released.', 'uniundata-books');
        }

        return $problems;
    }

    public function isSchemaReady(): bool
    {
        return $this->schemaReady;
    }

    public static function pluginFile(): string
    {
        return \defined('UNIUNDATA_BOOKS_FILE') ? (string) UNIUNDATA_BOOKS_FILE : __DIR__ . '/uniundata-books.php';
    }

    /** sql/schema.sql: рядом с главным файлом (сборка плагина) или уровнем выше (репозиторий: src/ + sql/). */
    public static function schemaFile(): string
    {
        $dir = \dirname(self::pluginFile());
        foreach ([$dir . '/sql/schema.sql', \dirname($dir) . '/sql/schema.sql'] as $candidate) {
            if (is_readable($candidate)) {
                return $candidate;
            }
        }

        return $dir . '/sql/schema.sql';
    }
}
