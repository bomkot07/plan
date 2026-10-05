# 11. Окружение: shop.libsmr.ru и new.libsmr.ru

Раздел основан на данных «Здоровья сайта» WordPress, присланных заказчиком 05.10.2026. Магазин —
**shop.libsmr.ru**, основной сайт — **new.libsmr.ru**.

## 11.1 Факты

| Параметр | shop.libsmr.ru | new.libsmr.ru | Что значит для проекта |
|---|---|---|---|
| ОС / веб-сервер | Ubuntu 22.04 (ядро 5.15), Apache 2.4.52 | то же | Apache + PHP-FPM (`mod_proxy_fcgi`) |
| PHP | **8.3.27** FPM | 8.3.27 FPM | Код совместим с 8.3, заголовок плагина `Requires PHP: 8.3`. Синтаксис только для 8.4 не используется |
| СУБД | MySQL 8.0.46 (`mysqli`, mysqlnd 8.3.27) | — | Схема проверена именно на 8.0.46. CHECK, generated columns и FK работают |
| Кодировка / сопоставление БД | utf8mb4 / utf8mb4_unicode_520_ci | — | Совпадает со схемой |
| Префикс таблиц | `wp_` | — | Таблицы плагина: `wp_book_*` (или `wp_N_book_*` в мультисайте) |
| `max_allowed_packet` | 64 МБ | — | Пакет синхронизации из 200 MARC-записей — единицы МБ |
| `max_connections` | 151 | — | Достаточно; раннеров Action Scheduler — не больше 3 |
| `max_execution_time` | 30 с | 30 с | Синхронизация только через WP-CLI / Action Scheduler, не в веб-запросе |
| `memory_limit` | 256M | 256M | Достаточно для веба; для WP-CLI задать отдельно (512M) |
| OPcache | **переполнен**: 128/128 МБ, interned strings 8/8 МБ, hit rate ≈1,5 % | то же | См. 11.2.1 — исправить до запуска магазина |
| Imagick | нет | нет | Миниатюры обложек делает GD (`php8.3-gd`) |
| cURL / OpenSSL | 7.81.0 / 3.0.2 | то же | TLS 1.2/1.3 для банка есть. Про сертификаты см. 11.2.5 |
| Время | UTC на сервере верное, зона +04:00 (Самара) | **UTC 09:40 при реальном 11:40** | См. 11.2.2 |

## 11.2 Что сделать до запуска

### 11.2.1 OPcache (критично для производительности)

При hit rate 1,5 % почти каждый запрос заново компилирует PHP-файлы WordPress. Сайт работает в разы
медленнее, чем мог бы, а время держания блокировок в транзакциях растёт. OPcache общий для всех пулов
одного мастера PHP-FPM, поэтому память считается на оба сайта сразу.

`/etc/php/8.3/fpm/conf.d/99-opcache-tuning.ini`:

```ini
opcache.memory_consumption=384        ; два сайта WordPress с плагинами
opcache.interned_strings_buffer=32
opcache.max_accelerated_files=50000
opcache.validate_timestamps=1
opcache.revalidate_freq=60             ; или 0 + opcache_reset() при деплое
```

Затем `systemctl reload php8.3-fpm`. Проверка: «Здоровье сайта» или `opcache_get_status()`.
Hit rate должен быть больше 99 %, а «Переполнение кеша» — «Нет».

### 11.2.2 Время и часовые пояса

- На **new.libsmr.ru** отчёт показал UTC 09:40, тогда как на shop.libsmr.ru и в реальности было около 11:40.
  Если оба отчёта сняты одновременно, системные часы основного сайта отстают на 2 часа. Проверка:
  `timedatectl` должен показать `System clock synchronized: yes` (иначе включить `systemd-timesyncd`
  или chrony). Неверные часы ломают TLS, подписи webhook с timestamp и сроки во всех задачах.
- Плагин не зависит от часового пояса сервера: соединение плагина выставляет `time_zone = '+00:00'`
  на время своих транзакций, все сроки сравниваются в SQL через `UTC_TIMESTAMP(6)`.
- Рекомендуется (необязательно) задать `default-time-zone = '+00:00'` в `/etc/mysql/mysql.conf.d/mysqld.cnf`.
  Тогда и записи, сделанные в обход плагина, будут в UTC. WordPress-ядро от часового пояса MySQL
  не зависит, но если на сервере есть сторонний код с `NOW()`, его нужно проверить, потому что настройка
  глобальная для обоих сайтов.

### 11.2.3 Cron: Action Scheduler через системный cron

WP-Cron срабатывает только при посещениях, и при пустом трафике резерв может «провисеть» дольше часа.
На shop.libsmr.ru:

```php
// wp-config.php
define( 'DISABLE_WP_CRON', true );
```

```cron
# crontab -u www-data -e
* * * * *  flock -n /tmp/uniundata-as.lock  wp --path=/var/www/shop action-scheduler run --group=uniundata --batch-size=25 --quiet
15 3 * * * flock -n /tmp/uniundata-sync.lock wp --path=/var/www/shop uniundata sync run --quiet
*/5 * * * * wp --path=/var/www/shop cron event run --due-now --quiet   # прочие задачи WordPress
```

`wp` — WP-CLI. Если его нет: `curl -O https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar`.
У CLI нет лимита `max_execution_time`, а `memory_limit` для CLI задаётся в `/etc/php/8.3/cli/php.ini` (512M).

### 11.2.4 Apache и webhook банка

- Стандартный `.htaccess` WordPress уже содержит
  `RewriteRule .* - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]`. Если банк передаёт подпись в заголовке
  `Authorization`, проверьте, что эта строка на месте: без неё Apache + PHP-FPM заголовок в PHP не передаёт.
  Заголовки вида `X-Signature` передаются без дополнительной настройки.
- IP allowlist банка (если банк его публикует) можно задать на уровне Apache:

  ```apache
  <If "%{REQUEST_URI} =~ m#^/wp-json/uniundata/v1/payment/webhook#">
      Require ip 203.0.113.0/24   # диапазоны из документации банка
  </If>
  ```
  WordPress принимает REST-запросы и как `?rest_route=/uniundata/v1/…`, поэтому основная защита — подпись,
  а allowlist — дополнительный слой (плагин дублирует его в `permission_callback`).
- Page cache (если будет) не должен кэшировать `/wp-json/uniundata/*`, корзину, checkout и страницу возврата из банка.
- Обязателен HTTPS (HSTS), иначе cookie-аутентификация и nonce теряют смысл.

### 11.2.5 Сертификаты банка (Россия)

Ряд российских банков с 2022 года использует сертификаты **Russian Trusted Root CA** (НУЦ Минцифры).
Их нет в системном хранилище Ubuntu и **нет** в собственном CA-bundle WordPress
(`wp-includes/certificates/ca-bundle.crt`, который использует `wp_remote_post`). Симптом — `cURL error 60`
при создании платежа.

Решение — точечно, только для запросов к банку:

```php
wp_remote_post( $url, [
    'sslcertificates' => WP_CONTENT_DIR . '/uniundata-certs/bank-ca-bundle.pem', // WP bundle + корни банка
    'timeout'         => 15,
    // ...
] );
```

`sslverify` **не** отключать. Какие корни нужны, указано в документации конкретного банка.

### 11.2.6 MySQL: пользователь и параметры

- Пользователю БД (`support`) для установки и обновления плагина нужны `CREATE, ALTER, INDEX, DROP,
  REFERENCES` (без `REFERENCES` MySQL не создаст внешние ключи), для работы — `SELECT, INSERT, UPDATE, DELETE`.
  Мигратор проверяет права через `SHOW GRANTS` и сообщает о нехватке.
- Новые и основные сайты работают на одном сервере MySQL. Поэтому имена `GET_LOCK` плагина содержат хэш
  БД и префикса (`Db::lockName()`), чтобы установки не блокировали друг друга. Имена CHECK и FK содержат
  имя таблицы с префиксом, чтобы две установки могли жить даже в одной БД.
- `innodb_ft_min_token_size = 3` (по умолчанию): слова из 1–2 букв («и», «de») в полнотекстовый индекс
  не попадают, и поиск не делает их обязательными. Изменение требует перезапуска MySQL и пересоздания индексов.
- `innodb_lock_wait_timeout`: плагин выставляет 5 с на свои транзакции. Глобальное значение (50 с) не трогаем.
- Резервные копии: `mysqldump --single-transaction --routines --triggers` (консистентный снимок InnoDB
  без блокировки таблиц). Заказы и продажи — бухгалтерские данные, их копии нужно хранить по регламенту.

### 11.2.7 Секреты

Ключи банка и источника каталога хранятся в `wp-config.php` магазина (или в переменных окружения пула
FPM: `env[UNIUNDATA_BANK_SECRET] = …` в конфиге пула), но не в `wp_options` и не в репозитории.

## 11.3 Мультисайт или две установки

Ответа заказчика пока нет. Имя БД `new_libsmr` допускает оба варианта:

| Вариант | Таблицы плагина | Пользователи | Что учесть |
|---|---|---|---|
| Две отдельные установки WordPress | `wp_book_*` в БД магазина | Отдельные `wp_users` у сайта и магазина | Регистрация покупателя в магазине или SSO (см. 07) |
| Мультисеть, магазин — подсайт | `wp_N_book_*` (`$wpdb->prefix` подсайта) | Общие `wp_users` | Плагин активируется только на подсайте магазина (не network-wide), роли назначаются на подсайте |

Схема готова к обоим вариантам: префикс подставляется мигратором, имена ограничений не конфликтуют.
