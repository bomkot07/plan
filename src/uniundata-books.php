<?php
/**
 * Plugin Name:       Uniundata Books
 * Description:       Магазин уникальных экземпляров книг: каталог MARC 21 с ежедневной синхронизацией, резерв на 1 час, корзина, заказы и банковская оплата.
 * Version:           1.0.0
 * Requires at least: 7.1
 * Requires PHP:      8.3
 * Author:            Uniundata
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       uniundata-books
 * Domain Path:       /languages
 *
 * Сборка плагина:
 *   uniundata-books/
 *     uniundata-books.php        ← этот файл
 *     src/                       ← PSR-4 Uniundata\Books\ (в репозитории примеров этот файл лежит в src/)
 *     sql/schema.sql             ← каноническая схема, её выполняет Install\Migrator
 *     vendor/                    ← composer install --no-dev -o (autoload + woocommerce/action-scheduler)
 *
 * Целевое окружение: PHP 8.3+ (у заказчика 8.3.27; код совместим и с 8.4), MySQL 8.0.16+ (InnoDB).
 * Синтаксис этого файла намеренно простой: на старом PHP WordPress должен показать сообщение
 * «требуется PHP 8.3» (по заголовку Requires PHP), а не упасть с ParseError.
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

define('UNIUNDATA_BOOKS_FILE', __FILE__);
define('UNIUNDATA_BOOKS_VERSION', '1.0.0');

if (is_readable(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
} else {
    // Без composer (разработка, этот репозиторий): PSR-4 для Uniundata\Books\ из src/ или из каталога файла.
    spl_autoload_register(static function (string $class): void {
        $prefix = 'Uniundata\\Books\\';
        if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
            return;
        }
        $base = is_dir(__DIR__ . '/src') ? __DIR__ . '/src/' : __DIR__ . '/';
        $file = $base . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (is_readable($file)) {
            require_once $file;
        }
    });
}

// Action Scheduler подключается ФАЙЛОМ, а не автозагрузкой: он регистрирует свою версию, и на plugins_loaded
// инициализируется самая новая копия среди всех плагинов (WooCommerce и др.). Без вложенной копии
// библиотеку даёт отдельный плагин action-scheduler или WooCommerce.
if (is_readable(__DIR__ . '/vendor/woocommerce/action-scheduler/action-scheduler.php')) {
    require_once __DIR__ . '/vendor/woocommerce/action-scheduler/action-scheduler.php';
}

register_activation_hook(__FILE__, ['Uniundata\\Books\\Plugin', 'activate']);
register_deactivation_hook(__FILE__, ['Uniundata\\Books\\Plugin', 'deactivate']);

add_action('plugins_loaded', ['Uniundata\\Books\\Plugin', 'boot'], 5);
