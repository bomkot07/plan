<?php

declare(strict_types=1);

namespace Uniundata\Books\Install;

/**
 * Роли и capabilities магазина (контракт, docs/07-users-roles.md § 7.2, 7.5).
 *
 * Роли WordPress хранятся в option `{prefix}user_roles`. add_role() не меняет существующую роль, а каждый
 * add_cap()/remove_cap() перезаписывает всю option. Поэтому карта ролей задаётся здесь, а БД приводится
 * к ней ПО ВЕРСИИ: при активации и на `init`, когда выросла Roles::VERSION (одно сравнение autoload-option
 * на запрос вместо записи в wp_options).
 *
 * Гость: capabilities в WordPress назначить нельзя (у user 0 нет ролей) — каталог и
 * GET /catalog/availability публичны, всё остальное требует входа.
 *
 * | capability               | book_customer | book_catalog_manager | book_order_manager | administrator |
 * |--------------------------|:-------------:|:--------------------:|:------------------:|:-------------:|
 * | read                     |       +       |          +           |         +          |       +       |
 * | view_book_catalog        |       +       |          +           |         +          |       +       |
 * | reserve_books            |       +       |                      |                    |       +       |
 * | create_book_orders       |       +       |                      |                    |       +       |
 * | view_own_book_orders     |       +       |                      |         +          |       +       |
 * | manage_book_catalog      |               |          +           |                    |       +       |
 * | manage_book_sync         |               |          +           |                    |       +       |
 * | manage_book_orders       |               |                      |         +          |       +       |
 * | manage_book_reservations |               |                      |         +          |       +       |
 *
 * Мета-capability `view_book_order` (с ID заказа) не назначается ролям: её разворачивает map_meta_cap
 * (Plugin::mapMetaCap): владелец → view_own_book_orders, иначе → manage_book_orders.
 */
final class Roles
{
    /** Увеличивать при ЛЮБОМ изменении ROLES или PLUGIN_CAPS. */
    public const VERSION = 1;
    public const VERSION_OPTION = 'uniundata_roles_version';

    /** Собственные права плагина. Синхронизатор снимает только их и не трогает права ядра. */
    public const PLUGIN_CAPS = [
        'view_book_catalog', 'reserve_books', 'create_book_orders', 'view_own_book_orders',
        'manage_book_catalog', 'manage_book_orders', 'manage_book_sync', 'manage_book_reservations',
    ];

    /** label = null: роль ядра — не создаём, только приводим права. */
    public const ROLES = [
        'book_customer' => [
            'label' => 'Покупатель книг',
            'caps' => ['read', 'view_book_catalog', 'reserve_books', 'create_book_orders', 'view_own_book_orders'],
        ],
        'book_catalog_manager' => [
            'label' => 'Менеджер каталога',
            'caps' => ['read', 'view_book_catalog', 'manage_book_catalog', 'manage_book_sync'],
        ],
        'book_order_manager' => [
            'label' => 'Менеджер заказов',
            'caps' => ['read', 'view_book_catalog', 'view_own_book_orders', 'manage_book_orders', 'manage_book_reservations'],
        ],
        'administrator' => [
            'label' => null,
            'caps' => ['read', 'view_book_catalog', 'reserve_books', 'create_book_orders', 'view_own_book_orders',
                'manage_book_catalog', 'manage_book_orders', 'manage_book_sync', 'manage_book_reservations'],
        ],
    ];

    /** На `init`: дешёвое сравнение autoload-option. */
    public static function maybeUpgrade(): void
    {
        if ((int) get_option(self::VERSION_OPTION, 0) < self::VERSION) {
            self::install();
        }
    }

    /**
     * При активации и из maybeUpgrade(). Идемпотентно: повторный вызов не меняет wp_user_roles.
     * Две параллельные загрузки после деплоя могут обе дойти сюда — операции идемпотентны,
     * итоговая запись одинакова.
     */
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
            // Понижение прав при апгрейде: снимаем СВОИ права, которых больше нет в карте роли.
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

    /**
     * Из uninstall.php (только удаление плагина; деактивация роли НЕ трогает — иначе пользователи
     * остались бы с несуществующей ролью). На больших сайтах — пакетами через WP-CLI.
     */
    public static function uninstall(): void
    {
        $admin = get_role('administrator');
        foreach (self::PLUGIN_CAPS as $cap) {
            $admin?->remove_cap($cap);
        }
        foreach (array_keys(self::ROLES) as $slug) {
            if (self::ROLES[$slug]['label'] === null) {
                continue;
            }
            foreach (get_users(['role' => $slug, 'fields' => 'ID', 'number' => -1]) as $id) {
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
