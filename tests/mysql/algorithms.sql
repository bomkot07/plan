-- =====================================================================
-- SQL-эквиваленты транзакционных алгоритмов плагина (для tests/mysql/run.sh)
--
-- Каждая процедура повторяет запросы PHP-сервиса из src/Service, src/Sync:
-- те же SELECT … FOR UPDATE, тот же глобальный порядок блокировок (docs/08)
--   0) sync_runs, records (только синхронизация, отдельной транзакцией)
--   1) carts → 2) items (по возрастанию id) → 3) reservations → 4) cart_items
--   → 5) orders → 6) payments → 7) payment_events → 8) refunds,
-- те же условные UPDATE … WHERE status = '<ожидаемый>' с проверкой числа строк,
-- время только из UTC_TIMESTAMP(6) БД. Сессии запускаются в READ COMMITTED,
-- time_zone '+00:00', innodb_lock_wait_timeout = 5 (как Db::transaction()).
-- Опции WordPress (валюта магазина, лимиты, срок оплаты) читаются из wp_options
-- функцией f_opt() — как get_option(); имена GET_LOCK — f_lock_name() = Db::lockName().
--
-- Отличия от PHP, не влияющие на блокировки:
--   * DomainError → SIGNAL SQLSTATE '45000' MESSAGE_TEXT = '<код REST>'; обработчик
--     делает ROLLBACK и пишет итог в t_outcome (код, HTTP, errno);
--   * повтора транзакции при 1213/1205 нет (Db::transaction() повторил бы до 3 раз):
--     тест обязан увидеть каждый deadlock, а не скрыть его повтором;
--   * HTTP к банку/источнику смоделирован параметрами (p_bank — ответ fetchPayment,
--     @bank_session — исход createSession в checkout);
--   * задачи Action Scheduler после COMMIT (as_enqueue_async_action) не ставятся, а
--     перечисляются в t_outcome.data.after_commit: [{hook, args}];
--   * IN (…)-списки PHP ($wpdb->prepare + плейсхолдеры) — динамический SQL с теми же
--     литералами; блокировки по списку id берутся циклом по возрастанию id
--     (тот же порядок, что у WHERE id IN (…) ORDER BY id FOR UPDATE по PRIMARY).
--   * tp('<точка>') — точки паузы для гарантированного пересечения (harness.sql).
-- =====================================================================

DROP PROCEDURE IF EXISTS a_audit;
DROP PROCEDURE IF EXISTS a_exec;
DROP PROCEDURE IF EXISTS a_lock_ids;
DROP PROCEDURE IF EXISTS a_lock_or_create_cart;
DROP PROCEDURE IF EXISTS a_release_locked;
DROP PROCEDURE IF EXISTS a_refresh_cart;
DROP PROCEDURE IF EXISTS a_reserve;
DROP PROCEDURE IF EXISTS a_remove;
DROP PROCEDURE IF EXISTS a_admin_release;
DROP PROCEDURE IF EXISTS a_expire_one;
DROP PROCEDURE IF EXISTS a_expire_reservations;
DROP PROCEDURE IF EXISTS a_checkout;
DROP PROCEDURE IF EXISTS a_checkout_refresh_cart;
DROP PROCEDURE IF EXISTS a_webhook;
DROP PROCEDURE IF EXISTS a_apply_succeeded;
DROP PROCEDURE IF EXISTS a_flag_order;
DROP PROCEDURE IF EXISTS a_expire_order_one;
DROP PROCEDURE IF EXISTS a_expire_orders;
DROP PROCEDURE IF EXISTS a_sync_start;
DROP PROCEDURE IF EXISTS a_sync_batch;
DROP PROCEDURE IF EXISTS a_sync_finish;
DROP FUNCTION IF EXISTS f_release_target;
DROP FUNCTION IF EXISTS f_sync_decide;
DROP FUNCTION IF EXISTS f_csv_n;
DROP FUNCTION IF EXISTS f_csv_at;
DROP FUNCTION IF EXISTS f_opt;
DROP FUNCTION IF EXISTS f_lock_name;
DROP PROCEDURE IF EXISTS a_create_refund;
DROP PROCEDURE IF EXISTS a_sync_records;
DROP PROCEDURE IF EXISTS a_sync_items;

DELIMITER $$

-- ---------------------------------------------------------------------
-- Общие шаги
-- ---------------------------------------------------------------------
CREATE FUNCTION f_csv_n(p_csv TEXT) RETURNS INT DETERMINISTIC
  RETURN IF(p_csv IS NULL OR p_csv = '', 0, 1 + LENGTH(p_csv) - LENGTH(REPLACE(p_csv, ',', '')))$$

CREATE FUNCTION f_csv_at(p_csv TEXT, p_i INT) RETURNS VARCHAR(255) DETERMINISTIC
  RETURN SUBSTRING_INDEX(SUBSTRING_INDEX(p_csv, ',', p_i), ',', -1)$$

-- get_option(): опции плагина в wp_options (их ставит Migrator при установке).
CREATE FUNCTION f_opt(p_name VARCHAR(191)) RETURNS VARCHAR(255) READS SQL DATA
  RETURN (SELECT option_value FROM wp_options WHERE option_name = p_name)$$

-- Db::lockName(): 'uniundata_<name>@' + первые 12 hex md5(DB_NAME|prefix). На одном сервере MySQL живут
-- new.libsmr.ru и shop.libsmr.ru — имена GET_LOCK у разных установок не пересекаются.
CREATE FUNCTION f_lock_name(p_name VARCHAR(41), p_prefix VARCHAR(32)) RETURNS VARCHAR(64) DETERMINISTIC
  RETURN CONCAT('uniundata_', p_name, '@', LEFT(MD5(CONCAT(DATABASE(), '|', p_prefix)), 12))$$

-- Контракт: release target по source_status.
CREATE FUNCTION f_release_target(p_source VARCHAR(20)) RETURNS VARCHAR(20) DETERMINISTIC
  RETURN CASE p_source WHEN 'present' THEN 'available' WHEN 'missing' THEN 'sync_missing'
                       WHEN 'withdrawn' THEN 'withdrawn' END$$

-- AuditLog::record()
CREATE PROCEDURE a_audit(IN p_action VARCHAR(64), IN p_entity_type VARCHAR(32), IN p_entity_id BIGINT UNSIGNED,
                         IN p_from VARCHAR(24), IN p_to VARCHAR(24), IN p_context JSON,
                         IN p_actor_type VARCHAR(16), IN p_actor_user BIGINT UNSIGNED)
BEGIN
  INSERT INTO wp_book_audit_log (occurred_at, actor_type, actor_user_id, action, entity_type, entity_id,
                                 from_status, to_status, context)
  VALUES (UTC_TIMESTAMP(6), p_actor_type, p_actor_user, p_action, p_entity_type, p_entity_id, p_from, p_to, p_context);
END$$

-- Запрос с IN-списком литералов (как $wpdb->prepare с генерированными плейсхолдерами).
CREATE PROCEDURE a_exec(IN p_sql TEXT, OUT o_rows INT)
BEGIN
  SET @a_sql = p_sql;
  PREPARE a_stmt FROM @a_sql;
  EXECUTE a_stmt;
  SET o_rows = ROW_COUNT();
  DEALLOCATE PREPARE a_stmt;
END$$

-- FOR UPDATE строк по списку id строго по возрастанию.
CREATE PROCEDURE a_lock_ids(IN p_table VARCHAR(32), IN p_csv TEXT)
BEGIN
  DECLARE v_i INT DEFAULT 1;
  DECLARE v_n INT DEFAULT f_csv_n(p_csv);
  DECLARE v_id BIGINT UNSIGNED;
  DECLARE v_s VARCHAR(32);
  DECLARE v_seen TEXT DEFAULT '';
  WHILE v_i <= v_n DO
    SET v_id = CAST(f_csv_at(p_csv, v_i) AS UNSIGNED), v_s = NULL;
    CASE p_table
      WHEN 'records' THEN SELECT IF(is_active = 1, 'active', 'inactive') INTO v_s FROM wp_book_records WHERE id = v_id FOR UPDATE;
      WHEN 'items' THEN SELECT availability_status INTO v_s FROM wp_book_items WHERE id = v_id FOR UPDATE;
      WHEN 'reservations' THEN SELECT reservation_status INTO v_s FROM wp_book_reservations WHERE id = v_id FOR UPDATE;
      WHEN 'cart_items' THEN SELECT status INTO v_s FROM wp_book_cart_items WHERE id = v_id FOR UPDATE;
    END CASE;
    SET v_seen = CONCAT(v_seen, IF(v_seen = '', '', ', '), '#', v_id, '=', COALESCE(v_s, 'нет'));
    SET v_i = v_i + 1;
  END WHILE;
  IF v_n > 0 THEN
    CALL tr(CONCAT('FOR UPDATE ', p_table, ' ', v_seen));
  END IF;
END$$

-- ReservationService::lockOrCreateOpenCart(): FOR UPDATE открытой корзины; нет — INSERT … ON DUPLICATE KEY UPDATE.
CREATE PROCEDURE a_lock_or_create_cart(IN p_user BIGINT UNSIGNED, OUT o_cart BIGINT UNSIGNED, OUT o_status VARCHAR(20))
BEGIN
  DECLARE v_rows INT;
  SET o_cart = NULL, o_status = NULL;
  SELECT id, status INTO o_cart, o_status FROM wp_book_carts WHERE open_cart_user_id = p_user FOR UPDATE;
  IF o_cart IS NOT NULL THEN
    CALL tr(CONCAT('FOR UPDATE корзины #', o_cart, ' (', o_status, ')'));
  ELSE
    CALL tr('открытой корзины нет (FOR UPDATE ничего не нашёл) → INSERT … ON DUPLICATE KEY UPDATE');
    CALL tp('cart.after_select');
    INSERT INTO wp_book_carts (user_id, status, started_at, last_activity_at)
    VALUES (p_user, 'active', UTC_TIMESTAMP(6), UTC_TIMESTAMP(6))
    ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id);
    SET v_rows = ROW_COUNT();
    SELECT id, status INTO o_cart, o_status FROM wp_book_carts WHERE id = LAST_INSERT_ID() FOR UPDATE;
    IF v_rows = 1 THEN
      CALL tr(CONCAT('создал корзину #', o_cart, ' (ODKU: 1 строка)'));
      CALL a_audit('cart.created', 'cart', o_cart, NULL, o_status, NULL, 'user', p_user);
      CALL tp('cart.created');
    ELSE
      CALL tr(CONCAT('корзину #', o_cart, ' уже создала другая сессия (ODKU: 0 строк), X-lock получен'));
    END IF;
  END IF;
END$$

-- ReservationService::releaseLocked(). Вызывающий держит: корзина резерва → экземпляр → резерв.
CREATE PROCEDURE a_release_locked(IN p_res BIGINT UNSIGNED, IN p_item BIGINT UNSIGNED, IN p_to VARCHAR(20),
                                  IN p_reason VARCHAR(32), IN p_actor_type VARCHAR(16), IN p_actor_user BIGINT UNSIGNED,
                                  OUT o_item_to VARCHAR(20))
BEGIN
  DECLARE v_ci BIGINT UNSIGNED;
  DECLARE v_ci_status VARCHAR(20);
  DECLARE v_item_from VARCHAR(20);
  DECLARE v_src VARCHAR(20);
  DECLARE v_user BIGINT UNSIGNED;
  DECLARE v_attempt INT;
  IF p_to NOT IN ('expired', 'cancelled', 'released_by_admin') THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'logic_error: bad release target';
  END IF;
  SELECT user_id, attempt_no INTO v_user, v_attempt FROM wp_book_reservations WHERE id = p_res;
  SELECT availability_status, source_status INTO v_item_from, v_src FROM wp_book_items WHERE id = p_item;

  -- 4. cart_items
  SELECT id, status INTO v_ci, v_ci_status FROM wp_book_cart_items WHERE reservation_id = p_res FOR UPDATE;

  UPDATE wp_book_reservations
     SET reservation_status = p_to, released_at = UTC_TIMESTAMP(6), release_reason = p_reason,
         attempt_no = IF(p_to = 'released_by_admin', NULL, attempt_no)
   WHERE id = p_res AND reservation_status = 'active';
  IF ROW_COUNT() <> 1 THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'logic_error: reservation is not active';
  END IF;

  IF v_ci IS NOT NULL AND v_ci_status = 'active' THEN
    UPDATE wp_book_cart_items SET status = IF(p_to = 'expired', 'expired', 'removed'), closed_at = UTC_TIMESTAMP(6)
     WHERE id = v_ci AND status = 'active';
    IF ROW_COUNT() <> 1 THEN
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'logic_error: cart item is not active';
    END IF;
  END IF;

  SET o_item_to = v_item_from;
  IF v_item_from = 'reserved' THEN
    SET o_item_to = f_release_target(v_src);
    UPDATE wp_book_items SET availability_status = o_item_to, status_changed_at = UTC_TIMESTAMP(6)
     WHERE id = p_item AND availability_status = 'reserved';
    IF ROW_COUNT() <> 1 THEN
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'logic_error: item is not reserved';
    END IF;
    CALL a_audit('item.status_changed', 'item', p_item, 'reserved', o_item_to,
                 JSON_OBJECT('reservation_id', p_res, 'source_status', v_src, 'reason', p_reason), p_actor_type, p_actor_user);
  ELSE
    CALL a_audit('item.release_skipped', 'item', p_item, v_item_from, NULL,
                 JSON_OBJECT('reservation_id', p_res, 'reason', p_reason), p_actor_type, p_actor_user);
  END IF;
  CALL a_audit(CONCAT('reservation.', p_to), 'reservation', p_res, 'active', p_to,
               JSON_OBJECT('book_item_id', p_item, 'user_id', v_user, 'cart_item_id', v_ci, 'attempt_no', v_attempt,
                           'reason', p_reason), p_actor_type, p_actor_user);
  CALL tr(CONCAT('резерв #', p_res, ' active→', p_to, ', позиция #', COALESCE(v_ci, '-'), ' →',
                 IF(p_to = 'expired', 'expired', 'removed'), ', экземпляр #', p_item, ' ', v_item_from, '→', o_item_to));
END$$

-- ReservationService::refreshCartLocked(). Вызывающий держит FOR UPDATE корзины.
CREATE PROCEDURE a_refresh_cart(IN p_cart BIGINT UNSIGNED, IN p_touch TINYINT, IN p_close_as VARCHAR(20),
                                IN p_actor_type VARCHAR(16), IN p_actor_user BIGINT UNSIGNED)
BEGIN
  DECLARE v_n INT;
  DECLARE v_min DATETIME(6);
  DECLARE v_status VARCHAR(20);
  SELECT COUNT(*), MIN(expires_at) INTO v_n, v_min FROM wp_book_cart_items WHERE cart_id = p_cart AND status = 'active';
  IF v_n = 0 AND p_close_as IS NOT NULL THEN
    SELECT status INTO v_status FROM wp_book_carts WHERE id = p_cart;
    UPDATE wp_book_carts SET status = p_close_as, closed_at = UTC_TIMESTAMP(6), expires_at = NULL
     WHERE id = p_cart AND status IN ('active', 'checkout_started');
    IF ROW_COUNT() = 1 THEN
      CALL a_audit(CONCAT('cart.', p_close_as), 'cart', p_cart, v_status, p_close_as, NULL, p_actor_type, p_actor_user);
      CALL tr(CONCAT('корзина #', p_cart, ' пуста → ', p_close_as));
    END IF;
  ELSE
    SELECT status INTO v_status FROM wp_book_carts WHERE id = p_cart;
    -- действие покупателя возвращает checkout_started → active: состав изменился, оформление заново
    UPDATE wp_book_carts
       SET expires_at = v_min, last_activity_at = IF(p_touch = 1, UTC_TIMESTAMP(6), last_activity_at),
           checkout_started_at = IF(p_touch = 1 AND status = 'checkout_started', NULL, checkout_started_at),
           status = IF(p_touch = 1 AND status = 'checkout_started', 'active', status)
     WHERE id = p_cart;
    IF p_touch = 1 AND v_status = 'checkout_started' THEN
      CALL a_audit('cart.status_changed', 'cart', p_cart, 'checkout_started', 'active', JSON_OBJECT('reason', 'cart_modified'),
                   p_actor_type, p_actor_user);
    END IF;
  END IF;
END$$

-- ---------------------------------------------------------------------
-- POST /cart/reserve — ReservationService::reserve()
-- ---------------------------------------------------------------------
CREATE PROCEDURE a_reserve(IN p_user BIGINT UNSIGNED, IN p_item BIGINT UNSIGNED)
proc: BEGIN
  DECLARE v_errno INT;
  DECLARE v_msg VARCHAR(512);
  DECLARE v_cart BIGINT UNSIGNED;
  DECLARE v_cart_status VARCHAR(20);
  DECLARE v_status VARCHAR(20);
  DECLARE v_src VARCHAR(20);
  DECLARE v_item_active TINYINT;
  DECLARE v_rec_active TINYINT;
  DECLARE v_price INT UNSIGNED;
  DECLARE v_cur CHAR(3) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin;
  DECLARE v_shop CHAR(3) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT f_opt('uniundata_currency');
  DECLARE v_max INT DEFAULT GREATEST(1, COALESCE(CAST(f_opt('uniundata_max_active_reservations') AS SIGNED), 10));
  DECLARE v_active INT;
  DECLARE v_ar BIGINT UNSIGNED;
  DECLARE v_ar_user BIGINT UNSIGNED;
  DECLARE v_ar_cart BIGINT UNSIGNED;
  DECLARE v_ar_attempt INT;
  DECLARE v_ar_exp DATETIME(6);
  DECLARE v_ar_due TINYINT;
  DECLARE v_used INT;
  DECLARE v_now DATETIME(6);
  DECLARE v_exp DATETIME(6);
  DECLARE v_res BIGINT UNSIGNED;
  DECLARE v_ci BIGINT UNSIGNED;
  DECLARE EXIT HANDLER FOR SQLEXCEPTION
  BEGIN
    GET DIAGNOSTICS CONDITION 1 v_errno = MYSQL_ERRNO, v_msg = MESSAGE_TEXT;
    ROLLBACK;
    CALL t_fail('reserve', v_errno, v_msg);
  END;
  SET @err_data = NULL;
  -- shopCurrency() — до транзакции; без валюты магазина резерв не работает (fail closed → 500)
  IF v_shop IS NULL OR v_shop NOT REGEXP '^[A-Z]{3}$' THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'logic_error: option uniundata_currency is not set';
  END IF;

  START TRANSACTION;
  CALL tr(CONCAT('BEGIN reserve(user ', p_user, ', item #', p_item, ')'));
  -- (a) 1. carts
  CALL a_lock_or_create_cart(p_user, v_cart, v_cart_status);
  CALL tp('reserve.after_cart');

  -- (b) 2. items: второй пользователь ждёт здесь до COMMIT первого
  SELECT i.availability_status, i.source_status, i.is_active, i.price_amount, i.currency, rec.is_active
    INTO v_status, v_src, v_item_active, v_price, v_cur, v_rec_active
    FROM wp_book_items i
    JOIN wp_book_records rec ON rec.id = i.book_record_id
   WHERE i.id = p_item
     FOR UPDATE OF i;
  CALL tr(CONCAT('FOR UPDATE экземпляра #', p_item, ' → ', COALESCE(v_status, 'нет строки')));
  CALL tp('reserve.after_item');
  IF v_status IS NULL OR v_item_active <> 1 OR v_rec_active <> 1 THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'uniundata_item_not_found';
  END IF;

  -- (c) 3. reservations: текущий активный резерв экземпляра
  SELECT id, user_id, cart_id, attempt_no, expires_at, (expires_at <= UTC_TIMESTAMP(6))
    INTO v_ar, v_ar_user, v_ar_cart, v_ar_attempt, v_ar_exp, v_ar_due
    FROM wp_book_reservations WHERE active_book_item_id = p_item FOR UPDATE;
  IF v_ar IS NOT NULL THEN
    IF v_ar_user <> p_user THEN
      SET @err_data = JSON_OBJECT('book_item_id', p_item, 'availability_status', v_status);
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'uniundata_item_unavailable';
    END IF;
    IF v_ar_due = 0 THEN
      COMMIT;
      CALL t_ok('reserve', 'existing', 200, JSON_OBJECT('reservation_id', v_ar, 'attempt_no', v_ar_attempt, 'cart_id', v_ar_cart));
      LEAVE proc;
    END IF;
    IF v_ar_cart <> v_cart THEN
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'logic_error: active reservation outside open cart';
    END IF;
    -- свой резерв истёк, cron ещё не дошёл: закрыть как expired (это попытка) и продолжить
    CALL a_release_locked(v_ar, p_item, 'expired', 'expired', 'user', p_user, v_status);
  END IF;

  -- (d) статус; цена не в валюте магазина — экземпляр не продаётся
  IF v_status <> 'available' THEN
    SET @err_data = JSON_OBJECT('book_item_id', p_item, 'availability_status', v_status);
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'uniundata_item_unavailable';
  END IF;
  IF v_cur <> v_shop THEN
    SET @err_data = JSON_OBJECT('book_item_id', p_item, 'availability_status', v_status, 'reason', 'currency',
                                'currency', v_cur, 'shop_currency', v_shop);
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'uniundata_item_unavailable';
  END IF;

  -- (e) лимит попыток (стабилен: все резервы экземпляра создаются под его блокировкой)
  SELECT COUNT(*) INTO v_used FROM wp_book_reservations
   WHERE user_id = p_user AND book_item_id = p_item AND attempt_no IS NOT NULL;
  IF v_used >= 3 THEN
    SET @err_data = JSON_OBJECT('book_item_id', p_item, 'max_attempts', 3, 'used', v_used);
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'uniundata_reservation_limit_reached';
  END IF;

  -- (e2) сколько книг пользователь держит сейчас: стабильно под блокировкой корзины из (a) — новые
  -- резервы пользователя появляются только под ней; просроченные, но не снятые cron-ом не считаются
  SELECT COUNT(*) INTO v_active FROM wp_book_reservations
   WHERE user_id = p_user AND reservation_status = 'active' AND expires_at > UTC_TIMESTAMP(6);
  IF v_active >= v_max THEN
    SET @err_data = JSON_OBJECT('max_active_reservations', v_max, 'active', v_active);
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'uniundata_active_reservation_limit';
  END IF;

  -- (f) резерв: ровно 1 час по часам БД
  SELECT UTC_TIMESTAMP(6), UTC_TIMESTAMP(6) + INTERVAL 60 MINUTE INTO v_now, v_exp;
  INSERT INTO wp_book_reservations (book_item_id, user_id, cart_id, reservation_status, attempt_no, reserved_at, expires_at)
  VALUES (p_item, p_user, v_cart, 'active', v_used + 1, v_now, v_exp);
  SET v_res = LAST_INSERT_ID();

  -- (g) экземпляр → reserved, условный UPDATE
  UPDATE wp_book_items SET availability_status = 'reserved', status_changed_at = v_now
   WHERE id = p_item AND availability_status = 'available';
  IF ROW_COUNT() <> 1 THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'logic_error: item available -> reserved failed';
  END IF;

  -- (h) 4. cart_items: снимок цены
  INSERT INTO wp_book_cart_items (cart_id, book_item_id, reservation_id, unit_price_amount, currency, status, added_at, expires_at)
  VALUES (v_cart, p_item, v_res, v_price, v_cur, 'active', v_now, v_exp);
  SET v_ci = LAST_INSERT_ID();

  -- (i) корзина
  CALL a_refresh_cart(v_cart, 1, NULL, 'user', p_user);
  -- (j) аудит
  CALL a_audit('reservation.created', 'reservation', v_res, NULL, 'active',
               JSON_OBJECT('book_item_id', p_item, 'cart_id', v_cart, 'cart_item_id', v_ci, 'attempt_no', v_used + 1,
                           'expires_at', v_exp, 'unit_price_amount', v_price, 'currency', v_cur), 'user', p_user);
  CALL a_audit('item.status_changed', 'item', p_item, 'available', 'reserved', JSON_OBJECT('reservation_id', v_res), 'user', p_user);
  CALL tr(CONCAT('INSERT резерв #', v_res, ' (попытка ', v_used + 1, '), экземпляр → reserved, позиция #', v_ci));
  CALL tp('reserve.before_commit');
  COMMIT;
  CALL t_ok('reserve', 'created', 201, JSON_OBJECT('reservation_id', v_res, 'attempt_no', v_used + 1, 'cart_id', v_cart,
                                                   'cart_item_id', v_ci, 'expires_at', v_exp));
END$$

-- ---------------------------------------------------------------------
-- POST /cart/remove-item — ReservationService::removeFromCart()
-- ---------------------------------------------------------------------
CREATE PROCEDURE a_remove(IN p_user BIGINT UNSIGNED, IN p_item BIGINT UNSIGNED)
proc: BEGIN
  DECLARE v_errno INT;
  DECLARE v_msg VARCHAR(512);
  DECLARE v_cart BIGINT UNSIGNED;
  DECLARE v_cart_status VARCHAR(20);
  DECLARE v_status VARCHAR(20);
  DECLARE v_ar BIGINT UNSIGNED;
  DECLARE v_ar_user BIGINT UNSIGNED;
  DECLARE v_ar_cart BIGINT UNSIGNED;
  DECLARE v_ar_due TINYINT;
  DECLARE v_to VARCHAR(20);
  DECLARE v_item_to VARCHAR(20);
  DECLARE v_last VARCHAR(20);
  DECLARE EXIT HANDLER FOR SQLEXCEPTION
  BEGIN
    GET DIAGNOSTICS CONDITION 1 v_errno = MYSQL_ERRNO, v_msg = MESSAGE_TEXT;
    ROLLBACK;
    CALL t_fail('remove', v_errno, v_msg);
  END;
  SET @err_data = NULL;

  START TRANSACTION;
  CALL tr(CONCAT('BEGIN remove-item(user ', p_user, ', item #', p_item, ')'));
  -- 1. carts (без создания)
  SELECT id, status INTO v_cart, v_cart_status FROM wp_book_carts WHERE open_cart_user_id = p_user FOR UPDATE;
  CALL tr(IF(v_cart IS NULL, 'открытой корзины нет', CONCAT('FOR UPDATE корзины #', v_cart, ' (', v_cart_status, ')')));
  CALL tp('remove.after_cart');
  -- 2. items
  SELECT availability_status INTO v_status FROM wp_book_items WHERE id = p_item FOR UPDATE;
  IF v_status IS NULL THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'uniundata_item_not_found';
  END IF;
  -- 3. reservations
  SELECT id, user_id, cart_id, (expires_at <= UTC_TIMESTAMP(6)) INTO v_ar, v_ar_user, v_ar_cart, v_ar_due
    FROM wp_book_reservations WHERE active_book_item_id = p_item FOR UPDATE;

  IF v_ar IS NOT NULL AND v_ar_user = p_user THEN
    IF v_cart IS NULL OR v_ar_cart <> v_cart THEN
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'logic_error: active reservation outside open cart';
    END IF;
    SET v_to = IF(v_ar_due = 1, 'expired', 'cancelled');
    CALL a_release_locked(v_ar, p_item, v_to, IF(v_ar_due = 1, 'expired', 'user_removed'), 'user', p_user, v_item_to);
    CALL a_refresh_cart(v_cart, 1, NULL, 'user', p_user);
    CALL tp('remove.before_commit');
    COMMIT;
    CALL t_ok('remove', IF(v_to = 'expired', 'expired', 'removed'), 200,
              JSON_OBJECT('reservation_id', v_ar, 'item_status', v_item_to, 'cart_id', v_cart));
    LEAVE proc;
  END IF;

  -- своего активного резерва нет: повтор, книга уже в заказе или её не было в корзине
  SELECT ci.status INTO v_last
    FROM wp_book_cart_items ci JOIN wp_book_carts c ON c.id = ci.cart_id
   WHERE ci.book_item_id = p_item AND c.user_id = p_user
   ORDER BY ci.id DESC LIMIT 1;
  IF v_last IS NULL THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'uniundata_item_not_found';
  ELSEIF v_last = 'converted_to_order' THEN
    SET @err_data = JSON_OBJECT('book_item_id', p_item, 'reason', 'item_in_order');
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'uniundata_cart_changed';
  ELSEIF v_last IN ('removed', 'expired') THEN
    COMMIT;
    CALL t_ok('remove', 'already_removed', 200, JSON_OBJECT('book_item_id', p_item, 'last_cart_item_status', v_last));
  ELSE
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'logic_error: active cart item without active reservation';
  END IF;
END$$

-- ---------------------------------------------------------------------
-- POST /admin/reservations/{id}/release — ReservationService::adminRelease()
-- ---------------------------------------------------------------------
CREATE PROCEDURE a_admin_release(IN p_admin BIGINT UNSIGNED, IN p_res BIGINT UNSIGNED)
proc: BEGIN
  DECLARE v_errno INT;
  DECLARE v_msg VARCHAR(512);
  DECLARE v_cart_id BIGINT UNSIGNED;
  DECLARE v_item BIGINT UNSIGNED;
  DECLARE v_cart BIGINT UNSIGNED;
  DECLARE v_status VARCHAR(20);
  DECLARE v_rstatus VARCHAR(20);
  DECLARE v_item_to VARCHAR(20);
  DECLARE EXIT HANDLER FOR SQLEXCEPTION
  BEGIN
    GET DIAGNOSTICS CONDITION 1 v_errno = MYSQL_ERRNO, v_msg = MESSAGE_TEXT;
    ROLLBACK;
    CALL t_fail('admin_release', v_errno, v_msg);
  END;
  SET @err_data = NULL;
  -- ID верхних уровней — обычным чтением
  SELECT cart_id, book_item_id INTO v_cart_id, v_item FROM wp_book_reservations WHERE id = p_res;
  IF v_item IS NULL THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'uniundata_reservation_not_found';
  END IF;
  START TRANSACTION;
  IF v_cart_id IS NOT NULL THEN
    SELECT id INTO v_cart FROM wp_book_carts WHERE id = v_cart_id FOR UPDATE;          -- 1
  END IF;
  SELECT availability_status INTO v_status FROM wp_book_items WHERE id = v_item FOR UPDATE; -- 2
  SELECT reservation_status INTO v_rstatus FROM wp_book_reservations WHERE id = p_res FOR UPDATE; -- 3
  IF v_rstatus = 'released_by_admin' THEN
    COMMIT;
    CALL t_ok('admin_release', 'noop', 200, NULL);
    LEAVE proc;
  END IF;
  IF v_rstatus <> 'active' THEN
    SET @err_data = JSON_OBJECT('reservation_id', p_res, 'reservation_status', v_rstatus);
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'uniundata_reservation_expired';
  END IF;
  CALL a_release_locked(p_res, v_item, 'released_by_admin', 'admin_release', 'admin', p_admin, v_item_to);
  IF v_cart IS NOT NULL THEN
    CALL a_refresh_cart(v_cart, 0, NULL, 'admin', p_admin);
  END IF;
  COMMIT;
  CALL t_ok('admin_release', 'released', 200, JSON_OBJECT('reservation_id', p_res, 'item_status', v_item_to));
END$$

-- ---------------------------------------------------------------------
-- Pass A: снятие истёкших резервов — ReservationExpiryService
-- ---------------------------------------------------------------------
CREATE PROCEDURE a_expire_one(IN p_res BIGINT UNSIGNED, IN p_cart BIGINT UNSIGNED, IN p_item BIGINT UNSIGNED)
proc: BEGIN
  DECLARE v_errno INT;
  DECLARE v_msg VARCHAR(512);
  DECLARE v_cart BIGINT UNSIGNED;
  DECLARE v_status VARCHAR(20);
  DECLARE v_rstatus VARCHAR(20);
  DECLARE v_rcart BIGINT UNSIGNED;
  DECLARE v_ritem BIGINT UNSIGNED;
  DECLARE v_due TINYINT;
  DECLARE v_item_to VARCHAR(20);
  DECLARE EXIT HANDLER FOR SQLEXCEPTION
  BEGIN
    GET DIAGNOSTICS CONDITION 1 v_errno = MYSQL_ERRNO, v_msg = MESSAGE_TEXT;
    ROLLBACK;
    CALL t_fail('expire_res', v_errno, v_msg);
  END;
  SET @err_data = NULL;
  START TRANSACTION;
  CALL tr(CONCAT('BEGIN expire reservation #', p_res));
  IF p_cart IS NOT NULL THEN
    SELECT id INTO v_cart FROM wp_book_carts WHERE id = p_cart FOR UPDATE;               -- 1. carts
    CALL tr(CONCAT('FOR UPDATE корзины #', p_cart));
  END IF;
  CALL tp('expA.after_cart');
  SELECT availability_status INTO v_status FROM wp_book_items WHERE id = p_item FOR UPDATE; -- 2. items
  CALL tr(CONCAT('FOR UPDATE экземпляра #', p_item, ' → ', COALESCE(v_status, 'нет')));
  SELECT reservation_status, cart_id, book_item_id, (expires_at <= UTC_TIMESTAMP(6))      -- 3. reservations
    INTO v_rstatus, v_rcart, v_ritem, v_due
    FROM wp_book_reservations WHERE id = p_res FOR UPDATE;
  CALL tp('expA.after_locks');
  -- перепроверка после блокировки
  IF v_status IS NULL OR v_rstatus IS NULL OR v_rstatus <> 'active' OR v_due <> 1
     OR NOT (v_rcart <=> p_cart) OR v_ritem <> p_item THEN
    COMMIT;
    CALL t_ok('expire_res', 'skipped', 200, JSON_OBJECT('reservation_id', p_res, 'reservation_status', v_rstatus, 'is_due', v_due));
    LEAVE proc;
  END IF;
  CALL a_release_locked(p_res, p_item, 'expired', 'expired', 'cron', NULL, v_item_to);
  IF v_cart IS NOT NULL THEN
    CALL a_refresh_cart(v_cart, 0, 'expired', 'cron', NULL);
  END IF;
  CALL tp('expA.before_commit');
  COMMIT;
  CALL t_ok('expire_res', 'expired', 200, JSON_OBJECT('reservation_id', p_res, 'item_status', v_item_to));
END$$

CREATE PROCEDURE a_expire_reservations(IN p_limit INT)
proc: BEGIN
  DECLARE v_list TEXT;
  DECLARE v_i INT DEFAULT 1;
  DECLARE v_e VARCHAR(100);
  -- GET_LOCK(Db::lockName('expire_reservations'), 0): второй раннер сразу выходит
  IF GET_LOCK(f_lock_name('expire_reservations', 'wp_'), 0) <> 1 THEN
    CALL t_ok('expire_reservations', 'locked', 200, NULL);
    LEAVE proc;
  END IF;
  -- кандидаты — обычным чтением (ix_reservations_expiry), всё перепроверяется под блокировкой
  SELECT GROUP_CONCAT(CONCAT(id, ':', COALESCE(cart_id, 0), ':', book_item_id) ORDER BY expires_at, id) INTO v_list
    FROM (SELECT id, cart_id, book_item_id, expires_at FROM wp_book_reservations
           WHERE reservation_status = 'active' AND expires_at <= UTC_TIMESTAMP(6)
           ORDER BY expires_at, id LIMIT p_limit) c;
  CALL tr(CONCAT('pass A: кандидаты (резерв:корзина:экземпляр) = ', COALESCE(v_list, '—')));
  WHILE v_i <= f_csv_n(v_list) DO
    SET v_e = f_csv_at(v_list, v_i);
    CALL a_expire_one(CAST(SUBSTRING_INDEX(v_e, ':', 1) AS UNSIGNED),
                      NULLIF(CAST(SUBSTRING_INDEX(SUBSTRING_INDEX(v_e, ':', 2), ':', -1) AS UNSIGNED), 0),
                      CAST(SUBSTRING_INDEX(v_e, ':', -1) AS UNSIGNED));
    SET v_i = v_i + 1;
  END WHILE;
  DO RELEASE_LOCK(f_lock_name('expire_reservations', 'wp_'));
END$$

-- CheckoutService::refreshCart() после 409 cart_changed: пустая → expired, иначе → checkout_started.
CREATE PROCEDURE a_checkout_refresh_cart(IN p_cart BIGINT UNSIGNED)
BEGIN
  IF (SELECT COUNT(*) FROM wp_book_cart_items WHERE cart_id = p_cart AND status = 'active') = 0 THEN
    UPDATE wp_book_carts SET expires_at = NULL, closed_at = UTC_TIMESTAMP(6), status = 'expired'
     WHERE id = p_cart AND status IN ('active', 'checkout_started');
  ELSE
    UPDATE wp_book_carts
       SET status = 'checkout_started', checkout_started_at = COALESCE(checkout_started_at, UTC_TIMESTAMP(6)),
           last_activity_at = UTC_TIMESTAMP(6),
           expires_at = (SELECT MIN(ci.expires_at) FROM wp_book_cart_items ci WHERE ci.cart_id = p_cart AND ci.status = 'active')
     WHERE id = p_cart AND status IN ('active', 'checkout_started');
  END IF;
END$$

-- ---------------------------------------------------------------------
-- POST /checkout — CheckoutService::checkout(): Tx1 → createSession (вне транзакции) → Tx2
-- @bank_session: NULL — сессия создана; 'error' — банк отказал; 'crash' — процесс умер после Tx1
-- ---------------------------------------------------------------------
CREATE PROCEDURE a_checkout(IN p_user BIGINT UNSIGNED, IN p_key CHAR(36), IN p_expected INT, IN p_currency CHAR(3))
proc: BEGIN
  DECLARE v_errno INT;
  DECLARE v_msg VARCHAR(512);
  DECLARE v_cart BIGINT UNSIGNED;
  DECLARE v_cart_status VARCHAR(20);
  DECLARE v_dup BIGINT UNSIGNED;
  DECLARE v_items TEXT;
  DECLARE v_resv TEXT;
  DECLARE v_cis TEXT;
  DECLARE v_n INT;
  DECLARE v_valid INT;
  DECLARE v_invalid INT;
  DECLARE v_total BIGINT;
  DECLARE v_cur_n INT;
  DECLARE v_cur CHAR(3);
  DECLARE v_ok_items TEXT;
  DECLARE v_ok_res TEXT;
  DECLARE v_ok_cis TEXT;
  DECLARE v_bad TEXT;
  DECLARE v_offer BIGINT UNSIGNED;
  DECLARE v_order BIGINT UNSIGNED;
  DECLARE v_pub VARCHAR(64);
  DECLARE v_pay BIGINT UNSIGNED;
  DECLARE v_pp VARCHAR(128);
  DECLARE v_rows INT;
  DECLARE v_i INT;
  DECLARE v_ostatus VARCHAR(24);
  DECLARE v_pstatus VARCHAR(24);
  DECLARE v_ci_id BIGINT UNSIGNED;
  DECLARE v_r_id BIGINT UNSIGNED;
  DECLARE v_r_status VARCHAR(20);
  DECLARE v_r_user BIGINT UNSIGNED;
  DECLARE v_it BIGINT UNSIGNED;
  DECLARE v_it_status VARCHAR(20);
  DECLARE v_it_src VARCHAR(20);
  DECLARE v_reason VARCHAR(32);
  DECLARE v_shop CHAR(3) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT f_opt('uniundata_currency');
  -- paymentWindow(): ttl 5..180, grace 0..60 (опции uniundata_payment_ttl_minutes / _grace_minutes)
  DECLARE v_ttl INT DEFAULT LEAST(180, GREATEST(5, COALESCE(CAST(f_opt('uniundata_payment_ttl_minutes') AS SIGNED), 30)));
  DECLARE v_grace INT DEFAULT LEAST(60, GREATEST(0, COALESCE(CAST(f_opt('uniundata_payment_grace_minutes') AS SIGNED), 10)));
  DECLARE EXIT HANDLER FOR SQLEXCEPTION
  BEGIN
    GET DIAGNOSTICS CONDITION 1 v_errno = MYSQL_ERRNO, v_msg = MESSAGE_TEXT;
    ROLLBACK;
    CALL t_fail('checkout', v_errno, v_msg);
  END;
  SET @err_data = NULL;
  IF v_shop IS NULL OR v_shop NOT REGEXP '^[A-Z]{3}$' THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'logic_error: option uniundata_currency is not set';
  END IF;

  -- ===== Tx1 (createOrderTx) =====
  START TRANSACTION;
  CALL tr(CONCAT('BEGIN checkout(user ', p_user, ', expected ', p_expected, ' ', p_currency, ')'));
  -- (1) carts
  SELECT id, status INTO v_cart, v_cart_status FROM wp_book_carts WHERE open_cart_user_id = p_user FOR UPDATE;
  CALL tr(IF(v_cart IS NULL, 'открытой корзины нет', CONCAT('FOR UPDATE корзины #', v_cart, ' (', v_cart_status, ')')));
  CALL tp('checkout.after_cart');
  SELECT id INTO v_dup FROM wp_book_orders WHERE user_id = p_user AND checkout_request_id = p_key;
  IF v_dup IS NOT NULL THEN
    COMMIT;
    CALL t_ok('checkout', 'replayed', 200, JSON_OBJECT('order_id', v_dup));
    LEAVE proc;
  END IF;
  IF v_cart IS NULL THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'uniundata_cart_empty';
  END IF;
  SELECT GROUP_CONCAT(book_item_id ORDER BY book_item_id), GROUP_CONCAT(reservation_id ORDER BY reservation_id),
         GROUP_CONCAT(id ORDER BY id), COUNT(*)
    INTO v_items, v_resv, v_cis, v_n
    FROM wp_book_cart_items WHERE cart_id = v_cart AND status = 'active';
  IF v_n = 0 THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'uniundata_cart_empty';
  END IF;
  CALL a_lock_ids('items', v_items);          -- (2) по возрастанию id
  CALL tp('checkout.after_items');
  CALL a_lock_ids('reservations', v_resv);    -- (3)
  CALL a_lock_ids('cart_items', v_cis);       -- (4)

  -- перепроверка под блокировками (тот же match, что в PHP)
  DROP TEMPORARY TABLE IF EXISTS tmp_chk;
  CREATE TEMPORARY TABLE tmp_chk ENGINE=InnoDB AS
  SELECT ci.id AS ci_id, ci.book_item_id, ci.reservation_id, ci.unit_price_amount, ci.currency,
         r.id AS r_id, r.reservation_status, r.user_id AS r_user, i.availability_status, i.source_status,
         CASE
           WHEN ci.status <> 'active' THEN NULL
           WHEN r.id IS NULL OR i.id IS NULL THEN 'missing'
           WHEN r.reservation_status <> 'active' THEN 'reservation_inactive'
           WHEN r.user_id <> p_user OR r.book_item_id <> ci.book_item_id THEN 'foreign_reservation'
           WHEN r.expires_at <= UTC_TIMESTAMP(6) THEN 'expired'
           WHEN i.availability_status <> 'reserved' THEN 'item_not_reserved'
           WHEN i.is_active <> 1 THEN 'item_inactive'
           WHEN ci.currency <> v_shop THEN 'currency'
           ELSE 'ok' END AS reason
    FROM wp_book_cart_items ci
    LEFT JOIN wp_book_reservations r ON r.id = ci.reservation_id
    LEFT JOIN wp_book_items i ON i.id = ci.book_item_id
   WHERE ci.cart_id = v_cart AND FIND_IN_SET(ci.id, v_cis) > 0;
  SELECT COALESCE(SUM(reason = 'ok'), 0), COALESCE(SUM(reason <> 'ok'), 0),
         GROUP_CONCAT(IF(reason <> 'ok', CONCAT(ci_id, ':', book_item_id, ':', COALESCE(r_id, 0), ':', reason), NULL) ORDER BY ci_id)
    INTO v_valid, v_invalid, v_bad FROM tmp_chk;

  IF v_invalid > 0 THEN
    -- expirePositions: невалидные позиции → expired, освобождаем только экземпляр своего active резерва
    SET v_i = 1;
    WHILE v_i <= f_csv_n(v_bad) DO
      SET v_ci_id = CAST(SUBSTRING_INDEX(f_csv_at(v_bad, v_i), ':', 1) AS UNSIGNED);
      SET v_it = CAST(SUBSTRING_INDEX(SUBSTRING_INDEX(f_csv_at(v_bad, v_i), ':', 2), ':', -1) AS UNSIGNED);
      SET v_r_id = NULLIF(CAST(SUBSTRING_INDEX(SUBSTRING_INDEX(f_csv_at(v_bad, v_i), ':', 3), ':', -1) AS UNSIGNED), 0);
      SET v_reason = SUBSTRING_INDEX(f_csv_at(v_bad, v_i), ':', -1);
      SET v_r_status = NULL, v_r_user = NULL, v_it_status = NULL;
      SELECT reservation_status, user_id INTO v_r_status, v_r_user FROM wp_book_reservations WHERE id = v_r_id;
      SELECT availability_status INTO v_it_status FROM wp_book_items WHERE id = v_it;
      IF v_r_status = 'active' AND v_r_user = p_user THEN
        UPDATE wp_book_reservations SET released_at = UTC_TIMESTAMP(6), release_reason = CONCAT('checkout_', v_reason),
                                        reservation_status = 'expired'
         WHERE id = v_r_id AND reservation_status = 'active';
        IF ROW_COUNT() <> 1 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'logic_error: reservation -> expired'; END IF;
        CALL a_audit('reservation.status_changed', 'reservation', v_r_id, 'active', 'expired',
                     JSON_OBJECT('reason', CONCAT('checkout_', v_reason)), 'user', p_user);
        IF v_it_status = 'reserved' THEN
          UPDATE wp_book_items
             SET status_changed_at = UTC_TIMESTAMP(6), availability_status = f_release_target(source_status)
           WHERE id = v_it AND availability_status = 'reserved';
          IF ROW_COUNT() <> 1 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'logic_error: item -> release target'; END IF;
          CALL a_audit('item.status_changed', 'item', v_it, 'reserved', NULL, JSON_OBJECT('reason', CONCAT('checkout_', v_reason)), 'user', p_user);
        END IF;
      END IF;
      UPDATE wp_book_cart_items SET closed_at = UTC_TIMESTAMP(6), status = 'expired' WHERE id = v_ci_id AND status = 'active';
      CALL a_audit('cart_item.status_changed', 'cart_item', v_ci_id, 'active', 'expired', JSON_OBJECT('reason', v_reason), 'user', p_user);
      CALL tr(CONCAT('позиция #', v_ci_id, ' (экземпляр #', v_it, ') невалидна: ', v_reason, ' → expired'));
      SET v_i = v_i + 1;
    END WHILE;
    CALL a_checkout_refresh_cart(v_cart);
    COMMIT;
    CALL t_ok('checkout', 'uniundata_cart_changed', 409, JSON_OBJECT('reason', 'positions_expired', 'invalid', v_bad));
    LEAVE proc;
  END IF;
  IF v_valid = 0 THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'uniundata_cart_empty';
  END IF;

  SELECT SUM(unit_price_amount), COUNT(DISTINCT currency), MIN(currency),
         GROUP_CONCAT(book_item_id ORDER BY book_item_id), GROUP_CONCAT(reservation_id ORDER BY reservation_id),
         GROUP_CONCAT(ci_id ORDER BY ci_id)
    INTO v_total, v_cur_n, v_cur, v_ok_items, v_ok_res, v_ok_cis
    FROM tmp_chk WHERE reason = 'ok';
  IF v_shop <> p_currency OR v_total <> p_expected THEN
    CALL a_checkout_refresh_cart(v_cart);
    COMMIT;
    CALL t_ok('checkout', 'uniundata_cart_changed', 409,
              JSON_OBJECT('reason', 'total_mismatch', 'actual_total_amount', v_total, 'currency', v_cur, 'items', v_ok_items));
    LEAVE proc;
  END IF;

  -- согласия (append-only)
  INSERT INTO wp_book_user_consents (consent_uuid, user_id, consent_type, document_version, document_sha256, accepted_at)
  VALUES (f_uuid4(), p_user, 'offer', '2026-09', SHA2('offer 2026-09', 256), UTC_TIMESTAMP(6));
  SET v_offer = LAST_INSERT_ID();
  INSERT INTO wp_book_user_consents (consent_uuid, user_id, consent_type, document_version, document_sha256, accepted_at)
  VALUES (f_uuid4(), p_user, 'privacy', '2026-09', SHA2('privacy 2026-09', 256), UTC_TIMESTAMP(6));

  -- (5) заказ draft; payment_due_at = now + ttl 30 + grace 10
  SET v_pub = CONCAT('uniundata_', f_uuid4());
  INSERT INTO wp_book_orders
    (public_order_id, user_id, cart_id, checkout_request_id, status, currency, prices_include_tax,
     subtotal_amount, discount_amount, shipping_amount, tax_amount, total_amount,
     customer_email, customer_phone, customer_first_name, customer_last_name, customer_middle_name,
     billing_address_json, shipping_address_json, offer_consent_id, placed_at, payment_due_at)
  VALUES (v_pub, p_user, v_cart, p_key, 'draft', p_currency, 1,
          v_total, 0, 0, 0, v_total,
          CONCAT('user', p_user, '@example.test'), NULL, 'Test', CONCAT('User', p_user), NULL,
          NULL, JSON_OBJECT('country', 'RU', 'city', 'Самара'), v_offer, UTC_TIMESTAMP(6),
          UTC_TIMESTAMP(6) + INTERVAL (v_ttl + v_grace) MINUTE);
  SET v_order = LAST_INSERT_ID();

  CALL a_exec(CONCAT(
    'INSERT INTO wp_book_order_items (order_id, book_item_id, book_record_id, reservation_id, title_snapshot, subtitle_snapshot,
       author_snapshot, isbn_snapshot, publisher_snapshot, publication_year_snapshot, condition_snapshot,
       cover_url_snapshot, item_identifier_snapshot, unit_price_amount, currency, quantity)
     SELECT ', v_order, ', i.id, i.book_record_id, ci.reservation_id, r.title, r.subtitle,
            COALESCE(r.authors_text, r.responsibility_statement), r.isbn_primary, r.publisher,
            r.publication_year, i.condition_code, COALESCE(i.cover_url, r.cover_url),
            COALESCE(i.external_item_id, i.inventory_number, CONCAT(''item-'', i.id)),
            ci.unit_price_amount, ci.currency, 1
       FROM wp_book_cart_items ci
       JOIN wp_book_items i ON i.id = ci.book_item_id
       JOIN wp_book_records r ON r.id = i.book_record_id
      WHERE ci.id IN (', v_ok_cis, ') ORDER BY i.id'), v_rows);
  IF v_rows <> v_valid THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'logic_error: order_items insert'; END IF;

  CALL a_exec(CONCAT(
    'UPDATE wp_book_reservations SET order_id = ', v_order, ', released_at = UTC_TIMESTAMP(6),
            release_reason = ''converted_to_order'', reservation_status = ''converted_to_order''
      WHERE id IN (', v_ok_res, ') AND reservation_status = ''active'''), v_rows);
  IF v_rows <> v_valid THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'logic_error: reservations -> converted_to_order'; END IF;

  CALL a_exec(CONCAT(
    'UPDATE wp_book_cart_items SET closed_at = UTC_TIMESTAMP(6), status = ''converted_to_order''
      WHERE id IN (', v_ok_cis, ') AND status = ''active'''), v_rows);
  IF v_rows <> v_valid THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'logic_error: cart_items -> converted_to_order'; END IF;

  UPDATE wp_book_carts
     SET checkout_started_at = COALESCE(checkout_started_at, UTC_TIMESTAMP(6)), last_activity_at = UTC_TIMESTAMP(6),
         expires_at = NULL, closed_at = UTC_TIMESTAMP(6), status = 'converted_to_order'
   WHERE id = v_cart AND status IN ('active', 'checkout_started');
  IF ROW_COUNT() <> 1 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'logic_error: cart -> converted_to_order'; END IF;

  CALL a_exec(CONCAT(
    'UPDATE wp_book_items SET status_changed_at = UTC_TIMESTAMP(6), availability_status = ''checkout_pending''
      WHERE id IN (', v_ok_items, ') AND availability_status = ''reserved'''), v_rows);
  IF v_rows <> v_valid THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'logic_error: items -> checkout_pending'; END IF;

  -- (6) платёж: попытка 1, ключ идемпотентности для банка — до HTTP
  INSERT INTO wp_book_payments (order_id, provider, attempt_no, idempotency_key, status, amount, currency)
  VALUES (v_order, 'testbank', 1, f_uuid4(), 'created', v_total, p_currency);
  SET v_pay = LAST_INSERT_ID();

  CALL a_audit('order.created', 'order', v_order, NULL, 'draft',
               JSON_OBJECT('public_order_id', v_pub, 'total_amount', v_total, 'currency', p_currency, 'items', v_ok_items), 'user', p_user);
  CALL a_audit('cart.status_changed', 'cart', v_cart, v_cart_status, 'converted_to_order', JSON_OBJECT('order_id', v_order), 'user', p_user);
  CALL a_audit('payment.created', 'payment', v_pay, NULL, 'created', JSON_OBJECT('order_id', v_order, 'attempt_no', 1), 'user', p_user);
  CALL tr(CONCAT('заказ #', v_order, ' draft, экземпляры ', v_ok_items, ' → checkout_pending, резервы → converted_to_order, платёж #', v_pay, ' created'));
  CALL tp('checkout.before_commit');
  COMMIT;
  CALL tr('Tx1 COMMIT; createSession у банка — вне транзакции; Tx2: orders → payments');

  -- ===== createSession у банка (ВНЕ транзакции; смоделировано @bank_session) =====
  IF @bank_session = 'crash' THEN
    -- PHP-процесс умер между Tx1 и Tx2 (max_execution_time, OOM): платёж остался created, заказ draft.
    -- Его закроет pass B после payment_due_at (created → expired).
    CALL t_ok('checkout', 'process_died_after_tx1', 500, JSON_OBJECT('order_id', v_order, 'payment_id', v_pay));
    LEAVE proc;
  END IF;
  IF @bank_session = 'error' THEN
    -- ===== Tx2 markSessionFailedTx: orders → payments; created → cancelled, заказ → payment_failed =====
    START TRANSACTION;
    SELECT status INTO v_ostatus FROM wp_book_orders WHERE id = v_order FOR UPDATE;
    SELECT status INTO v_pstatus FROM wp_book_payments WHERE id = v_pay FOR UPDATE;
    UPDATE wp_book_payments SET failure_code = 'session_create_failed', status = 'cancelled' WHERE id = v_pay AND status = 'created';
    IF ROW_COUNT() <> 1 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'logic_error: payment created -> cancelled'; END IF;
    CALL a_audit('payment.status_changed', 'payment', v_pay, 'created', 'cancelled', JSON_OBJECT('reason', 'session_create_failed'), 'user', p_user);
    UPDATE wp_book_orders SET status = 'payment_failed' WHERE id = v_order AND status = 'draft';
    IF ROW_COUNT() <> 1 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'logic_error: order -> payment_failed'; END IF;
    CALL a_audit('order.status_changed', 'order', v_order, 'draft', 'payment_failed', JSON_OBJECT('payment_id', v_pay), 'user', p_user);
    COMMIT;
    CALL tr(CONCAT('createSession: ошибка банка → платёж #', v_pay, ' created→cancelled, заказ #', v_order, ' draft→payment_failed'));
    CALL t_ok('checkout', 'uniundata_payment_provider_error', 502, JSON_OBJECT('order_id', v_order, 'payment_id', v_pay,
                                                                               'order_status', 'payment_failed'));
    LEAVE proc;
  END IF;
  SET v_pp = CONCAT('pp_', v_pay);

  -- ===== Tx2 (markSessionOpenedTx): orders → payments =====
  START TRANSACTION;
  SELECT status INTO v_ostatus FROM wp_book_orders WHERE id = v_order FOR UPDATE;
  SELECT status INTO v_pstatus FROM wp_book_payments WHERE id = v_pay FOR UPDATE;
  IF v_pstatus = 'created' THEN
    UPDATE wp_book_payments SET provider_payment_id = v_pp, session_expires_at = UTC_TIMESTAMP(6) + INTERVAL 30 MINUTE,
                                provider_status = 'session_created', status = 'pending'
     WHERE id = v_pay AND status = 'created';
    CALL a_audit('payment.status_changed', 'payment', v_pay, 'created', 'pending', JSON_OBJECT('order_id', v_order), 'user', p_user);
    SET v_pstatus = 'pending';
  ELSE
    UPDATE wp_book_payments SET provider_payment_id = COALESCE(provider_payment_id, v_pp),
                                session_expires_at = COALESCE(session_expires_at, UTC_TIMESTAMP(6) + INTERVAL 30 MINUTE)
     WHERE id = v_pay;
  END IF;
  IF v_pstatus = 'pending' AND v_ostatus IN ('draft', 'payment_failed') THEN
    UPDATE wp_book_orders SET status = 'pending_payment' WHERE id = v_order AND status IN ('draft', 'payment_failed');
    CALL a_audit('order.status_changed', 'order', v_order, v_ostatus, 'pending_payment', JSON_OBJECT('payment_id', v_pay), 'user', p_user);
  END IF;
  COMMIT;
  CALL t_ok('checkout', 'created', 201, JSON_OBJECT('order_id', v_order, 'public_order_id', v_pub, 'payment_id', v_pay,
                                                    'provider_payment_id', v_pp, 'items', v_ok_items, 'total_amount', v_total));
END$$

-- ---------------------------------------------------------------------
-- POST /payment/webhook — PaymentService::handleWebhook() + applyTx() (подпись уже проверена,
-- fetchPayment подтвердил статус)
-- ---------------------------------------------------------------------
-- PaymentService::flagOrder(): та же причина — без повторного аудита; блокирующую причину (amount_mismatch,
-- reference_mismatch держат заказ от автозакрытия) менее важная не перетирает. Уведомление — после COMMIT.
CREATE PROCEDURE a_flag_order(IN p_order BIGINT UNSIGNED, IN p_reason VARCHAR(64), IN p_context JSON)
proc: BEGIN
  DECLARE v_flag TINYINT;
  DECLARE v_cur VARCHAR(64);
  DECLARE v_store VARCHAR(64);
  SELECT needs_attention, attention_reason INTO v_flag, v_cur FROM wp_book_orders WHERE id = p_order; -- строка уже под X
  IF v_flag = 1 AND v_cur <=> p_reason THEN
    LEAVE proc;
  END IF;
  SET v_store = IF(v_flag = 1 AND v_cur IN ('amount_mismatch', 'reference_mismatch')
                   AND p_reason NOT IN ('amount_mismatch', 'reference_mismatch'), v_cur, p_reason);
  UPDATE wp_book_orders SET attention_reason = v_store, needs_attention = 1 WHERE id = p_order;
  CALL a_audit('order.needs_attention', 'order', p_order, NULL, NULL,
               JSON_MERGE_PATCH(COALESCE(p_context, JSON_OBJECT()), JSON_OBJECT('reason', p_reason, 'previous_reason', v_cur)),
               'webhook', NULL);
  SET @after_commit = JSON_ARRAY_APPEND(@after_commit, '$',
        JSON_OBJECT('hook', 'uniundata_order_needs_attention', 'args', JSON_OBJECT('order_id', p_order, 'reason', p_reason)));
  CALL tr(CONCAT('заказ #', p_order, ' → needs_attention = ', v_store));
END$$

-- PaymentService::createRefundLocked(): решение о возврате — строка wp_book_refunds(status = requested) В ТОЙ ЖЕ
-- транзакции, что и решение; уровень 8 порядка блокировок. После COMMIT — AS uniundata_refund_payment {refund_id}.
CREATE PROCEDURE a_create_refund(IN p_order BIGINT UNSIGNED, IN p_pay BIGINT UNSIGNED, IN p_amount INT,
                                 IN p_reason VARCHAR(32), OUT o_refund BIGINT UNSIGNED)
BEGIN
  DECLARE v_reserved BIGINT;
  DECLARE v_pamount INT UNSIGNED;
  DECLARE v_cur CHAR(3);
  SELECT amount, currency INTO v_pamount, v_cur FROM wp_book_payments WHERE id = p_pay;           -- уже под X (6)
  SELECT COALESCE(SUM(amount), 0) INTO v_reserved FROM wp_book_refunds
   WHERE payment_id = p_pay AND status <> 'failed' FOR UPDATE;                                     -- (8) refunds
  IF p_amount <= 0 OR v_reserved + p_amount > v_pamount THEN
    SET @err_data = JSON_OBJECT('param', 'amount');
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'uniundata_invalid_param';
  END IF;
  INSERT INTO wp_book_refunds (payment_id, order_id, amount, currency, reason, idempotency_key, status, requested_by, requested_at)
  VALUES (p_pay, p_order, p_amount, v_cur, p_reason, f_uuid4(), 'requested', NULL, UTC_TIMESTAMP(6));
  SET o_refund = LAST_INSERT_ID();
  CALL a_audit('refund.requested', 'refund', o_refund, NULL, 'requested',
               JSON_OBJECT('order_id', p_order, 'payment_id', p_pay, 'amount', p_amount, 'reason', p_reason), 'webhook', NULL);
  SET @after_commit = JSON_ARRAY_APPEND(@after_commit, '$',
        JSON_OBJECT('hook', 'uniundata_refund_payment', 'args', JSON_OBJECT('refund_id', o_refund)));
  CALL tr(CONCAT('INSERT wp_book_refunds #', o_refund, ': ', p_amount, ' ', v_cur, ', ', p_reason, ', requested'));
END$$

-- applySucceeded(): продажа + paid; дубль; поздний платёж (re-acquire или конфликт).
CREATE PROCEDURE a_apply_succeeded(IN p_order BIGINT UNSIGNED, IN p_pay BIGINT UNSIGNED, IN p_items TEXT,
                                   IN p_amount INT, IN p_currency CHAR(3),
                                   OUT o_outcome VARCHAR(16), OUT o_note VARCHAR(64))
proc: BEGIN
  DECLARE v_pstatus VARCHAR(24);
  DECLARE v_pamount INT UNSIGNED;
  DECLARE v_pcur CHAR(3);
  DECLARE v_ostatus VARCHAR(24);
  DECLARE v_other INT;
  DECLARE v_late TINYINT;
  DECLARE v_i INT DEFAULT 1;
  DECLARE v_it BIGINT UNSIGNED;
  DECLARE v_ist VARCHAR(20);
  DECLARE v_act INT;
  DECLARE v_sell TEXT DEFAULT '';
  DECLARE v_conf TEXT DEFAULT '';
  DECLARE v_rows INT;
  DECLARE v_refund BIGINT;
  DECLARE v_refund_id BIGINT UNSIGNED;
  DECLARE v_src VARCHAR(20);
  DECLARE v_check TEXT DEFAULT '';
  DECLARE v_sell_st TEXT DEFAULT '';
  SELECT status, amount, currency INTO v_pstatus, v_pamount, v_pcur FROM wp_book_payments WHERE id = p_pay;
  SELECT status INTO v_ostatus FROM wp_book_orders WHERE id = p_order;

  IF p_amount IS NULL OR p_amount <> v_pamount OR p_currency <> v_pcur THEN
    UPDATE wp_book_payments SET provider_status = 'succeeded' WHERE id = p_pay;
    CALL a_flag_order(p_order, 'amount_mismatch', JSON_OBJECT('payment_id', p_pay));
    SET o_outcome = 'processed', o_note = 'amount_mismatch';
    LEAVE proc;
  END IF;
  IF v_pstatus IN ('succeeded', 'refunded', 'partially_refunded') THEN
    CALL tr(CONCAT('платёж #', p_pay, ' уже succeeded — повтор успеха, ничего не меняю'));
    SET o_outcome = 'ignored', o_note = 'already_succeeded';
    LEAVE proc;
  END IF;

  -- movePayment: created → pending → succeeded (прямого created → succeeded в контракте нет)
  IF v_pstatus = 'created' THEN
    UPDATE wp_book_payments SET status = 'pending' WHERE id = p_pay AND status = 'created';
    CALL a_audit('payment.status_changed', 'payment', p_pay, 'created', 'pending', NULL, 'webhook', NULL);
    SET v_pstatus = 'pending';
  END IF;
  UPDATE wp_book_payments
     SET provider_status = 'succeeded', succeeded_at = COALESCE(succeeded_at, UTC_TIMESTAMP(6)), status = 'succeeded'
   WHERE id = p_pay AND status = v_pstatus;
  IF ROW_COUNT() <> 1 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'logic_error: payment -> succeeded'; END IF;
  CALL a_audit('payment.status_changed', 'payment', p_pay, v_pstatus, 'succeeded',
               JSON_OBJECT('late_confirmation', v_pstatus IN ('failed', 'expired', 'cancelled')), 'webhook', NULL);

  -- второй успешный платёж по оплаченному заказу
  SELECT COUNT(*) INTO v_other FROM wp_book_payments
   WHERE order_id = p_order AND id <> p_pay AND status IN ('succeeded', 'refunded', 'partially_refunded');
  IF v_other > 0 OR v_ostatus IN ('paid', 'fulfilled', 'completed', 'refunded', 'partially_refunded') THEN
    CALL a_flag_order(p_order, 'duplicate_payment', JSON_OBJECT('payment_id', p_pay));
    CALL a_create_refund(p_order, p_pay, v_pamount, 'duplicate_payment', v_refund_id);
    SET o_outcome = 'processed', o_note = 'duplicate_payment';
    LEAVE proc;
  END IF;

  SET v_late = v_ostatus IN ('payment_expired', 'cancelled');
  WHILE v_i <= f_csv_n(p_items) DO
    SET v_it = CAST(f_csv_at(p_items, v_i) AS UNSIGNED), v_ist = NULL, v_src = NULL, v_act = 0;
    SELECT availability_status, source_status INTO v_ist, v_src FROM wp_book_items WHERE id = v_it;   -- уже под нашей X
    IF v_late = 1 THEN
      SELECT COUNT(*) INTO v_act FROM wp_book_reservations WHERE active_book_item_id = v_it;
    END IF;
    IF (v_late = 1 AND v_ist IN ('available', 'sync_missing', 'withdrawn') AND v_act = 0)
       OR (v_late = 0 AND v_ist = 'checkout_pending') THEN
      SET v_sell = CONCAT(v_sell, IF(v_sell = '', '', ','), v_it);
      SET v_sell_st = CONCAT(v_sell_st, IF(v_sell_st = '', '', ','), v_ist);
      IF v_late = 1 AND v_src <> 'present' THEN
        -- источник говорит «нет/снят»: книгу продаём, но менеджер проверяет её физически
        SET v_check = CONCAT(v_check, IF(v_check = '', '', ','), v_it);
      END IF;
    ELSE
      SET v_conf = CONCAT(v_conf, IF(v_conf = '', '', ','), v_it);
    END IF;
    SET v_i = v_i + 1;
  END WHILE;
  CALL tr(CONCAT(IF(v_late = 1, 'ПОЗДНИЙ платёж (заказ ' , 'платёж (заказ '), v_ostatus, '): продаём [', v_sell,
                 '], конфликт [', v_conf, '], проверить у источника [', v_check, ']'));

  IF v_sell <> '' THEN
    CALL a_exec(CONCAT(
      'UPDATE wp_book_items SET sold_at = UTC_TIMESTAMP(6), status_changed_at = UTC_TIMESTAMP(6), availability_status = ''sold''
        WHERE id IN (', v_sell, ') AND availability_status IN (',
        IF(v_late = 1, '''available'',''sync_missing'',''withdrawn''', '''checkout_pending'''), ')'), v_rows);
    IF v_rows <> f_csv_n(v_sell) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'logic_error: items -> sold'; END IF;
    -- UNIQUE(book_item_id) и UNIQUE(order_item_id) — второй рубеж от двойной продажи
    CALL a_exec(CONCAT(
      'INSERT INTO wp_book_sales (book_item_id, book_record_id, order_id, order_item_id, payment_id, user_id, sold_at, price_amount, currency)
       SELECT oi.book_item_id, oi.book_record_id, oi.order_id, oi.id, ', p_pay, ', o.user_id, UTC_TIMESTAMP(6),
              oi.unit_price_amount, oi.currency
         FROM wp_book_order_items oi JOIN wp_book_orders o ON o.id = oi.order_id
        WHERE oi.order_id = ', p_order, ' AND oi.book_item_id IN (', v_sell, ') ORDER BY oi.book_item_id'), v_rows);
    IF v_rows <> f_csv_n(v_sell) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'logic_error: sales insert'; END IF;
    SET v_i = 1;
    WHILE v_i <= f_csv_n(v_sell) DO
      CALL a_audit('item.status_changed', 'item', CAST(f_csv_at(v_sell, v_i) AS UNSIGNED), f_csv_at(v_sell_st, v_i), 'sold',
                   JSON_OBJECT('order_id', p_order, 'payment_id', p_pay, 'late_payment', v_late = 1), 'webhook', NULL);
      SET v_i = v_i + 1;
    END WHILE;
  END IF;

  -- moveOrder → paid (draft → pending_payment → paid)
  IF v_ostatus = 'draft' THEN
    UPDATE wp_book_orders SET status = 'pending_payment' WHERE id = p_order AND status = 'draft';
    CALL a_audit('order.status_changed', 'order', p_order, 'draft', 'pending_payment', NULL, 'webhook', NULL);
    SET v_ostatus = 'pending_payment';
  END IF;
  UPDATE wp_book_orders SET paid_at = COALESCE(paid_at, UTC_TIMESTAMP(6)), status = 'paid'
   WHERE id = p_order AND status = v_ostatus
     AND status IN ('pending_payment', 'payment_processing', 'payment_failed', 'payment_expired', 'cancelled');
  IF ROW_COUNT() <> 1 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'logic_error: order -> paid'; END IF;
  CALL a_audit('order.status_changed', 'order', p_order, v_ostatus, 'paid',
               JSON_OBJECT('payment_id', p_pay, 'late_payment', v_late = 1), 'webhook', NULL);
  -- closeOtherOpenAttempts: created/pending → cancelled (у банка — после COMMIT)
  UPDATE wp_book_payments SET status = 'cancelled' WHERE order_id = p_order AND id <> p_pay AND status IN ('created', 'pending');

  IF v_conf <> '' THEN
    -- часть экземпляров ушла другим: продаём свободные, остальное — возврат строкой wp_book_refunds
    SELECT COALESCE(SUM(unit_price_amount), 0) INTO v_refund FROM wp_book_order_items
     WHERE order_id = p_order AND FIND_IN_SET(book_item_id, v_conf) > 0;
    IF v_sell = '' THEN SET v_refund = v_pamount; END IF;
    CALL a_flag_order(p_order, 'late_payment_conflict',
                      JSON_OBJECT('conflict_item_ids', v_conf, 'refund_amount', v_refund, 'source_check_item_ids', v_check));
    CALL a_create_refund(p_order, p_pay, v_refund, 'late_payment_conflict', v_refund_id);
  ELSEIF v_check <> '' THEN
    CALL a_flag_order(p_order, 'late_payment_source_check', JSON_OBJECT('source_check_item_ids', v_check));
  END IF;
  IF v_sell <> '' THEN
    SET @after_commit = JSON_ARRAY_APPEND(@after_commit, '$',
          JSON_OBJECT('hook', 'uniundata_order_paid', 'args', JSON_OBJECT('order_id', p_order)));
  END IF;
  SET o_outcome = 'processed';
  SET o_note = IF(v_late = 1, IF(v_conf = '', 'late_payment_reacquired', 'late_payment_conflict'), '');
END$$

CREATE PROCEDURE a_webhook(IN p_event VARCHAR(128), IN p_pp VARCHAR(128), IN p_status VARCHAR(24),
                           IN p_amount INT, IN p_currency CHAR(3), IN p_public_order VARCHAR(64))
proc: BEGIN
  DECLARE v_errno INT;
  DECLARE v_msg VARCHAR(512);
  DECLARE v_ev BIGINT UNSIGNED;
  DECLARE v_rc INT;
  DECLARE v_state VARCHAR(16);
  DECLARE v_pay BIGINT UNSIGNED;
  DECLARE v_order BIGINT UNSIGNED;
  DECLARE v_items TEXT;
  DECLARE v_ostatus VARCHAR(24);
  DECLARE v_pub VARCHAR(64);
  DECLARE v_npay INT;
  DECLARE v_outcome VARCHAR(16);
  DECLARE v_note VARCHAR(64);
  DECLARE EXIT HANDLER FOR SQLEXCEPTION
  BEGIN
    GET DIAGNOSTICS CONDITION 1 v_errno = MYSQL_ERRNO, v_msg = MESSAGE_TEXT;
    ROLLBACK;
    -- markEventFailed: банк повторит доставку
    IF v_ev IS NOT NULL THEN
      UPDATE wp_book_payment_events SET error_message = LEFT(v_msg, 500), processing_status = 'failed'
       WHERE id = v_ev AND processing_status IN ('received', 'failed');
    END IF;
    CALL t_fail('webhook', v_errno, v_msg);
  END;
  SET @err_data = NULL;
  CALL tr(CONCAT('webhook ', p_event, ': ', p_status, ' ', p_amount, ' ', p_currency, ' для ', p_pp));

  -- 2. inbox: идемпотентность по (provider, provider_event_id); автокоммит, вне транзакции
  INSERT INTO wp_book_payment_events (provider, provider_event_id, event_type, processing_status, payload_redacted,
                                      payload_sha256, received_at, attempts)
  VALUES ('testbank', p_event, CONCAT('payment.', p_status), 'received',
          JSON_OBJECT('status', p_status, 'amount', p_amount, 'currency', p_currency, 'payment', p_pp),
          SHA2(CONCAT_WS('|', p_event, p_status, p_amount, p_currency, p_pp), 256), UTC_TIMESTAMP(6), 1)
  ON DUPLICATE KEY UPDATE attempts = LEAST(attempts + 1, 65535), id = LAST_INSERT_ID(id);
  SET v_rc = ROW_COUNT(), v_ev = LAST_INSERT_ID();
  SELECT processing_status INTO v_state FROM wp_book_payment_events WHERE id = v_ev;
  CALL tr(CONCAT('inbox: событие #', v_ev, IF(v_rc = 1, ' новое', ' уже было (attempts+1)'), ', processing_status = ', v_state));
  IF v_state IN ('processed', 'ignored') THEN
    CALL t_ok('webhook', 'duplicate', 200, JSON_OBJECT('event_id', v_ev, 'where', 'inbox'));
    LEAVE proc;
  END IF;

  -- 3. fetchPayment (вне транзакции) — смоделирован: банк подтверждает p_status
  -- 4. apply: ID для блокировки — обычным SELECT
  SELECT id, order_id INTO v_pay, v_order FROM wp_book_payments WHERE provider = 'testbank' AND provider_payment_id = p_pp;
  IF v_pay IS NULL THEN
    UPDATE wp_book_payment_events SET processed_at = UTC_TIMESTAMP(6), error_message = 'payment_not_found', processing_status = 'ignored'
     WHERE id = v_ev AND processing_status IN ('received', 'failed');
    CALL t_ok('webhook', 'ignored', 200, JSON_OBJECT('event_id', v_ev, 'note', 'payment_not_found'));
    LEAVE proc;
  END IF;
  SELECT GROUP_CONCAT(book_item_id ORDER BY book_item_id) INTO v_items FROM wp_book_order_items WHERE order_id = v_order;

  START TRANSACTION;
  SET @after_commit = JSON_ARRAY();     -- resetTxState(): очередь задач AS на после COMMIT
  CALL a_lock_ids('items', v_items);                                               -- (2) items asc
  CALL tp('apply.after_items');
  SELECT status, public_order_id INTO v_ostatus, v_pub FROM wp_book_orders WHERE id = v_order FOR UPDATE;   -- (5)
  SELECT COUNT(*) INTO v_npay FROM wp_book_payments WHERE order_id = v_order FOR UPDATE;                  -- (6)
  SELECT processing_status INTO v_state FROM wp_book_payment_events WHERE id = v_ev FOR UPDATE;           -- (7)
  CALL tr(CONCAT('FOR UPDATE заказ #', v_order, ' (', v_ostatus, '), платежи (', v_npay, '), событие #', v_ev, ' (', v_state, ')'));
  IF v_state IN ('processed', 'ignored') THEN
    COMMIT;
    CALL t_ok('webhook', 'duplicate', 200, JSON_OBJECT('event_id', v_ev, 'where', 'under_lock'));
    LEAVE proc;
  END IF;

  IF p_public_order IS NOT NULL AND p_public_order <> v_pub THEN
    CALL a_flag_order(v_order, 'reference_mismatch', NULL);
    SET v_outcome = 'processed', v_note = 'reference_mismatch';
  ELSEIF p_status = 'succeeded' THEN
    CALL a_apply_succeeded(v_order, v_pay, v_items, p_amount, p_currency, v_outcome, v_note);
  ELSE
    SET v_outcome = 'ignored', v_note = 'status_not_modelled_in_test';
  END IF;

  UPDATE wp_book_payment_events
     SET payment_id = v_pay, order_id = v_order, processed_at = UTC_TIMESTAMP(6), error_message = NULLIF(v_note, ''),
         processing_status = IF(v_outcome = 'processed', 'processed', 'ignored')
   WHERE id = v_ev AND processing_status IN ('received', 'failed');
  CALL tp('apply.before_commit');
  COMMIT;
  -- enqueueAfterCommit(): as_enqueue_async_action(hook, args, 'uniundata') — только после COMMIT
  CALL t_ok('webhook', v_outcome, 200, JSON_OBJECT('event_id', v_ev, 'order_id', v_order, 'note', v_note,
                                                   'after_commit', CAST(@after_commit AS JSON)));
END$$

-- ---------------------------------------------------------------------
-- Pass B: неоплаченные заказы после payment_due_at — OrderExpiryService::expireOne()
-- p_bank — что ответил fetchPayment (опрос банка ВНЕ транзакции):
--   pending    — сессия не оплачена (или failed/expired/cancelled) → заказ закрывается;
--   none       — банк не знает платежа (created: сессия не создавалась) → закрывается;
--   processing — банк обрабатывает: applyProviderResult (отдельная транзакция) + ОДНОКРАТНОЕ продление;
--   unknown    — банк недоступен: ждём ещё grace, затем закрываем.
-- ---------------------------------------------------------------------
CREATE PROCEDURE a_expire_order_one(IN p_order BIGINT UNSIGNED, IN p_bank VARCHAR(16))
proc: BEGIN
  DECLARE v_errno INT;
  DECLARE v_msg VARCHAR(512);
  DECLARE v_items TEXT;
  DECLARE v_status VARCHAR(24);
  DECLARE v_due TINYINT;
  DECLARE v_overdue TINYINT;
  DECLARE v_extended_at DATETIME(6);
  DECLARE v_npay INT;
  DECLARE v_money INT;
  DECLARE v_closed TEXT;
  DECLARE v_pending TEXT;
  DECLARE v_rows INT;
  DECLARE v_i INT;
  DECLARE v_grace INT DEFAULT LEAST(60, GREATEST(0, COALESCE(CAST(f_opt('uniundata_payment_grace_minutes') AS SIGNED), 10)));
  DECLARE EXIT HANDLER FOR SQLEXCEPTION
  BEGIN
    GET DIAGNOSTICS CONDITION 1 v_errno = MYSQL_ERRNO, v_msg = MESSAGE_TEXT;
    ROLLBACK;
    CALL t_fail('expire_order', v_errno, v_msg);
  END;
  SET @err_data = NULL;
  CALL tr(CONCAT('pass B: заказ #', p_order, ', банк (fetchPayment вне транзакции) ответил: ', p_bank));
  SELECT GROUP_CONCAT(book_item_id ORDER BY book_item_id) INTO v_items FROM wp_book_order_items WHERE order_id = p_order;

  IF p_bank = 'processing' THEN
    -- applyProviderResult(processing): тот же путь, что webhook — items → orders → payments
    START TRANSACTION;
    CALL a_lock_ids('items', v_items);
    SELECT status INTO v_status FROM wp_book_orders WHERE id = p_order FOR UPDATE;
    SELECT COUNT(*) INTO v_npay FROM wp_book_payments WHERE order_id = p_order FOR UPDATE;
    UPDATE wp_book_payments SET provider_status = 'processing', status = 'processing'
     WHERE order_id = p_order AND status = 'pending'
       AND attempt_no = (SELECT m FROM (SELECT MAX(attempt_no) m FROM wp_book_payments WHERE order_id = p_order) x);
    IF ROW_COUNT() = 1 THEN
      CALL a_audit('payment.status_changed', 'payment', (SELECT MAX(id) FROM wp_book_payments WHERE order_id = p_order),
                   'pending', 'processing', NULL, 'cron', NULL);
      UPDATE wp_book_orders SET status = 'payment_processing' WHERE id = p_order AND status = 'pending_payment';
      IF ROW_COUNT() = 1 THEN
        CALL a_audit('order.status_changed', 'order', p_order, 'pending_payment', 'payment_processing', NULL, 'cron', NULL);
      END IF;
    END IF;
    COMMIT;
  END IF;

  -- ===== expireTx =====
  START TRANSACTION;
  CALL a_lock_ids('items', v_items);                                                   -- (2)
  CALL tp('oexp.after_items');
  SELECT status, (payment_due_at <= UTC_TIMESTAMP(6)), (payment_due_at <= UTC_TIMESTAMP(6) - INTERVAL v_grace MINUTE),
         payment_due_extended_at
    INTO v_status, v_due, v_overdue, v_extended_at
    FROM wp_book_orders WHERE id = p_order FOR UPDATE;                                 -- (5)
  CALL tr(CONCAT('FOR UPDATE заказ #', p_order, ' → ', COALESCE(v_status, 'нет'), ', просрочен: ', COALESCE(v_due, '-'),
                 ', продлён: ', COALESCE(v_extended_at, 'нет')));
  IF v_status IS NULL OR v_status NOT IN ('draft', 'pending_payment', 'payment_processing', 'payment_failed') OR v_due <> 1 THEN
    COMMIT;
    CALL t_ok('expire_order', 'noop', 200, JSON_OBJECT('order_status', v_status, 'is_due', v_due));
    LEAVE proc;
  END IF;
  SELECT COUNT(*), COALESCE(SUM(status IN ('succeeded', 'refunded', 'partially_refunded')), 0)
    INTO v_npay, v_money FROM wp_book_payments WHERE order_id = p_order FOR UPDATE;  -- (6)
  IF v_money > 0 THEN
    COMMIT;
    CALL t_ok('expire_order', 'noop', 200, JSON_OBJECT('reason', 'money_received'));
    LEAVE proc;
  END IF;
  IF p_bank = 'unknown' AND v_overdue <> 1 THEN
    COMMIT;
    CALL t_ok('expire_order', 'noop', 200, JSON_OBJECT('reason', 'bank_unknown_wait_grace'));
    LEAVE proc;
  END IF;
  -- однократное продление: признак — orders.payment_due_extended_at (меняется под блокировкой заказа)
  IF p_bank = 'processing' AND v_extended_at IS NULL THEN
    UPDATE wp_book_orders
       SET payment_due_at = UTC_TIMESTAMP(6) + INTERVAL GREATEST(1, v_grace) MINUTE,
           payment_due_extended_at = UTC_TIMESTAMP(6),
           attention_reason = IF(needs_attention = 1, attention_reason, 'payment_processing_overdue'), needs_attention = 1
     WHERE id = p_order AND payment_due_extended_at IS NULL
       AND status IN ('draft', 'pending_payment', 'payment_processing', 'payment_failed');
    IF ROW_COUNT() <> 1 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'logic_error: order payment_due_at extension'; END IF;
    CALL a_audit('order.payment_due_extended', 'order', p_order, v_status, v_status,
                 JSON_OBJECT('reason', 'bank_reports_processing', 'grace_minutes', v_grace), 'cron', NULL);
    CALL tr(CONCAT('банк: processing → payment_due_at + ', v_grace, ' мин (однократно), needs_attention'));
    CALL tp('oexp.before_commit');
    COMMIT;
    CALL t_ok('expire_order', 'extended', 200, JSON_OBJECT('after_commit', JSON_ARRAY(JSON_OBJECT('hook', 'uniundata_order_needs_attention',
                 'args', JSON_OBJECT('order_id', p_order, 'reason', 'payment_processing_overdue')))));
    LEAVE proc;
  END IF;

  UPDATE wp_book_orders SET status = 'payment_expired'
   WHERE id = p_order AND status IN ('draft', 'pending_payment', 'payment_processing', 'payment_failed');
  IF ROW_COUNT() <> 1 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'logic_error: order -> payment_expired'; END IF;
  CALL a_audit('order.status_changed', 'order', p_order, v_status, 'payment_expired', JSON_OBJECT('reason', 'payment_due_passed'), 'cron', NULL);
  -- контракт v2: created | pending | processing → expired (created → failed не бывает)
  SELECT GROUP_CONCAT(CONCAT('#', id, ' ', status, '→expired') ORDER BY attempt_no) INTO v_closed
    FROM wp_book_payments WHERE order_id = p_order AND status IN ('created', 'pending', 'processing');
  UPDATE wp_book_payments SET failure_code = COALESCE(failure_code, 'order_expired'), status = 'expired'
   WHERE order_id = p_order AND status IN ('created', 'pending', 'processing');

  SELECT GROUP_CONCAT(id ORDER BY id) INTO v_pending FROM wp_book_items
   WHERE FIND_IN_SET(id, v_items) > 0 AND availability_status = 'checkout_pending';
  IF v_pending IS NOT NULL THEN
    CALL a_exec(CONCAT(
      'UPDATE wp_book_items SET status_changed_at = UTC_TIMESTAMP(6),
              availability_status = CASE source_status WHEN ''present'' THEN ''available''
                                    WHEN ''missing'' THEN ''sync_missing'' WHEN ''withdrawn'' THEN ''withdrawn'' END
        WHERE id IN (', v_pending, ') AND availability_status = ''checkout_pending'''), v_rows);
    IF v_rows <> f_csv_n(v_pending) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'logic_error: items -> release target'; END IF;
    SET v_i = 1;
    WHILE v_i <= f_csv_n(v_pending) DO
      CALL a_audit('item.status_changed', 'item', CAST(f_csv_at(v_pending, v_i) AS UNSIGNED), 'checkout_pending',
                   (SELECT availability_status FROM wp_book_items WHERE id = CAST(f_csv_at(v_pending, v_i) AS UNSIGNED)),
                   JSON_OBJECT('order_id', p_order, 'reason', 'order_expired'), 'cron', NULL);
      SET v_i = v_i + 1;
    END WHILE;
  END IF;
  CALL tr(CONCAT('заказ #', p_order, ' → payment_expired, платежи [', COALESCE(v_closed, ''), '], экземпляры [',
                 COALESCE(v_pending, ''), '] освобождены'));
  CALL tp('oexp.before_commit');
  COMMIT;
  CALL t_ok('expire_order', 'payment_expired', 200, JSON_OBJECT('order_id', p_order, 'released_items', v_pending, 'payments', v_closed));
END$$

-- OrderExpiryService::expireDue(): GET_LOCK(Db::lockName('expire_orders')), кандидаты обычным SELECT, по одному заказу.
CREATE PROCEDURE a_expire_orders(IN p_bank VARCHAR(16))
BEGIN
  DECLARE v_list TEXT;
  DECLARE v_i INT DEFAULT 1;
  IF GET_LOCK(f_lock_name('expire_orders', 'wp_'), 0) <> 1 THEN
    CALL t_ok('expire_orders', 'locked', 200, NULL);
  ELSE
    SELECT GROUP_CONCAT(id ORDER BY payment_due_at, id) INTO v_list FROM wp_book_orders
     WHERE status IN ('draft', 'pending_payment', 'payment_processing', 'payment_failed')
       AND payment_due_at <= UTC_TIMESTAMP(6)
       AND NOT (needs_attention = 1 AND attention_reason IN ('amount_mismatch', 'reference_mismatch'));
    CALL tr(CONCAT('pass B: кандидаты = ', COALESCE(v_list, '—')));
    WHILE v_i <= f_csv_n(v_list) DO
      CALL a_expire_order_one(CAST(f_csv_at(v_list, v_i) AS UNSIGNED), p_bank);
      SET v_i = v_i + 1;
    END WHILE;
    DO RELEASE_LOCK(f_lock_name('expire_orders', 'wp_'));
  END IF;
END$$

-- ---------------------------------------------------------------------
-- Синхронизация — SyncService (openRun / writeBatch / applyItem / decide)
-- ---------------------------------------------------------------------
-- decide(): 'availability|source_status|conflict'
CREATE FUNCTION f_sync_decide(p_local VARCHAR(20), p_local_source VARCHAR(20), p_incoming VARCHAR(20))
RETURNS VARCHAR(64) DETERMINISTIC
BEGIN
  IF p_local IN ('reserved', 'checkout_pending', 'sold', 'blocked') THEN
    RETURN CONCAT(p_local, '|', p_incoming, '|',
                  IF(p_local = 'sold', p_incoming = 'present', p_incoming = 'withdrawn' AND p_local_source <> 'withdrawn'));
  END IF;
  IF p_incoming = 'withdrawn' THEN
    RETURN 'withdrawn|withdrawn|0';
  END IF;
  RETURN CASE p_local
    WHEN 'available' THEN 'available|present|0'
    WHEN 'sync_missing' THEN 'available|present|0'
    WHEN 'withdrawn' THEN IF(p_local_source = 'present', 'withdrawn|present|1', 'available|present|0')
  END;
END$$

CREATE PROCEDURE a_sync_start(IN p_source VARCHAR(64), OUT o_run BIGINT UNSIGNED)
proc: BEGIN
  DECLARE v_errno INT;
  DECLARE v_msg VARCHAR(512);
  DECLARE v_cur BIGINT UNSIGNED;
  DECLARE EXIT HANDLER FOR SQLEXCEPTION
  BEGIN
    GET DIAGNOSTICS CONDITION 1 v_errno = MYSQL_ERRNO, v_msg = MESSAGE_TEXT;
    ROLLBACK;
    CALL t_fail('sync_start', v_errno, v_msg);
  END;
  SET o_run = NULL;
  -- слой 1: GET_LOCK соединения, имя — Db::lockName('sync_<source>')
  IF GET_LOCK(f_lock_name(CONCAT('sync_', p_source), 'wp_'), 0) <> 1 THEN
    CALL t_ok('sync_start', 'locked', 200, JSON_OBJECT('reason', 'GET_LOCK busy'));
    LEAVE proc;
  END IF;
  CALL tr('GET_LOCK синхронизации получен');
  -- слой 2: строка running + UNIQUE(running_source)
  START TRANSACTION;
  SELECT id INTO v_cur FROM wp_book_sync_runs WHERE running_source = p_source FOR UPDATE;
  IF v_cur IS NOT NULL THEN
    COMMIT;
    CALL t_ok('sync_start', 'busy', 200, JSON_OBJECT('running_run_id', v_cur));
    LEAVE proc;
  END IF;
  INSERT INTO wp_book_sync_runs (source_name, triggered_by, status, started_at, heartbeat_at)
  VALUES (p_source, 'wp_cli', 'running', UTC_TIMESTAMP(6), UTC_TIMESTAMP(6));
  SET o_run = LAST_INSERT_ID();
  UPDATE wp_book_sync_runs SET pass_started_run_id = o_run WHERE id = o_run;
  COMMIT;
  CALL t_ok('sync_start', 'started', 200, JSON_OBJECT('run_id', o_run));
END$$

-- SyncService::applyBatch(): p_entries = JSON-массив {external_item_id, source_record_id?, status: present|withdrawn,
-- price_amount, currency, condition_code?}. Две транзакции — X-блокировки записей и экземпляров никогда не
-- берутся вместе (порядок docs/08: 0) sync_runs, records; затем items):
--   A (a_sync_records): строка прогона → записи FOR UPDATE по возрастанию id → INSERT новых; счётчики records_*;
--   B (a_sync_items):   строка прогона → экземпляры FOR UPDATE по возрастанию id → INSERT/UPDATE; items_*, ошибки.
-- Экземпляр в валюте ≠ uniundata_currency не импортируется: ошибка currency_mismatch, только отметка last_seen.
CREATE PROCEDURE a_sync_batch(IN p_run BIGINT UNSIGNED, IN p_source VARCHAR(64), IN p_entries JSON)
BEGIN
  DECLARE v_shop CHAR(3) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT f_opt('uniundata_currency');
  DECLARE v_ok JSON DEFAULT JSON_ARRAY();
  DECLARE v_err JSON DEFAULT JSON_ARRAY();
  DECLARE v_i INT DEFAULT 0;
  DECLARE v_e JSON;
  -- разбор пакета до транзакций (в PHP — MARC и checksum, только CPU)
  WHILE v_i < JSON_LENGTH(p_entries) DO
    SET v_e = JSON_EXTRACT(p_entries, CONCAT('$[', v_i, ']'));
    IF JSON_UNQUOTE(JSON_EXTRACT(v_e, '$.currency')) COLLATE utf8mb4_bin <> v_shop THEN
      SET v_err = JSON_ARRAY_APPEND(v_err, '$', JSON_OBJECT('external_id', JSON_EXTRACT(v_e, '$.external_item_id'),
                  'code', 'currency_mismatch', 'message', CONCAT('Item currency ', JSON_UNQUOTE(JSON_EXTRACT(v_e, '$.currency')),
                  ' differs from the shop currency ', v_shop, '; skipped')));
    ELSE
      SET v_ok = JSON_ARRAY_APPEND(v_ok, '$', JSON_SET(v_e, '$.source_record_id',
                   COALESCE(JSON_EXTRACT(v_e, '$.source_record_id'), JSON_EXTRACT(v_e, '$.external_item_id'))));
    END IF;
    SET v_i = v_i + 1;
  END WHILE;
  CALL a_sync_records(p_run, p_source, v_ok);
  IF @sync_ok = 1 THEN
    CALL a_sync_items(p_run, p_source, v_ok, v_err, JSON_LENGTH(p_entries));
  END IF;
END$$

-- Транзакция A: записи пакета. Экземпляры не трогаются.
CREATE PROCEDURE a_sync_records(IN p_run BIGINT UNSIGNED, IN p_source VARCHAR(64), IN p_entries JSON)
proc: BEGIN
  DECLARE v_errno INT;
  DECLARE v_msg VARCHAR(512);
  DECLARE v_run_status VARCHAR(16);
  DECLARE v_ids TEXT;
  DECLARE v_new INT DEFAULT 0;
  DECLARE v_rows INT;
  DECLARE EXIT HANDLER FOR SQLEXCEPTION
  BEGIN
    GET DIAGNOSTICS CONDITION 1 v_errno = MYSQL_ERRNO, v_msg = MESSAGE_TEXT;
    ROLLBACK;
    SET @sync_ok = 0;
    CALL t_fail('sync_records', v_errno, v_msg);
  END;
  SET @sync_ok = 0;
  START TRANSACTION;
  CALL tr(CONCAT('BEGIN sync A (записи) run #', p_run, ', ', JSON_LENGTH(p_entries), ' шт.'));
  SELECT status INTO v_run_status FROM wp_book_sync_runs WHERE id = p_run FOR UPDATE;     -- 0) строка прогона
  IF v_run_status IS NULL OR v_run_status <> 'running' THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'sync_run_not_running';
  END IF;
  -- существующие записи: ID обычным чтением, затем FOR UPDATE по возрастанию id
  SELECT GROUP_CONCAT(DISTINCT r.id ORDER BY r.id) INTO v_ids
    FROM wp_book_records r
    JOIN JSON_TABLE(p_entries, '$[*]' COLUMNS (rid VARCHAR(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin PATH '$.source_record_id')) j
      ON r.source_record_id = j.rid
   WHERE r.source_name = p_source;
  CALL a_lock_ids('records', v_ids);
  INSERT INTO wp_book_records (source_name, source_record_id, source_format, marc21_format, marc21_raw, source_checksum,
                               title, title_sort, is_active, last_seen_sync_run_id, last_synced_at)
  SELECT p_source, j.rid, 'marc_json', 'marc_json', '{}', SHA2(j.rid, 256), CONCAT('Book ', j.rid), LOWER(CONCAT('book ', j.rid)),
         1, p_run, UTC_TIMESTAMP(6)
    FROM (SELECT DISTINCT rid FROM JSON_TABLE(p_entries, '$[*]' COLUMNS (
            rid VARCHAR(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin PATH '$.source_record_id',
            st VARCHAR(16) PATH '$.status')) jt WHERE st = 'present') j
   WHERE NOT EXISTS (SELECT 1 FROM wp_book_records r WHERE r.source_name = p_source AND r.source_record_id = j.rid);
  SET v_new = ROW_COUNT();
  IF v_ids IS NOT NULL THEN
    CALL a_exec(CONCAT('UPDATE wp_book_records SET last_seen_sync_run_id = ', p_run, ', last_synced_at = UTC_TIMESTAMP(6)
                        WHERE id IN (', v_ids, ')'), v_rows);
  END IF;
  CALL tp('sync.after_records');
  UPDATE wp_book_sync_runs
     SET heartbeat_at = UTC_TIMESTAMP(6), records_created = records_created + v_new,
         records_skipped = records_skipped + f_csv_n(v_ids)
   WHERE id = p_run;
  COMMIT;
  SET @sync_ok = 1;
  CALL tr(CONCAT('COMMIT sync A: новых записей ', v_new));
END$$

-- Транзакция B: экземпляры пакета (+ счётчики, ошибки). Все решения — по строкам, прочитанным FOR UPDATE.
CREATE PROCEDURE a_sync_items(IN p_run BIGINT UNSIGNED, IN p_source VARCHAR(64), IN p_entries JSON, IN p_errors JSON,
                              IN p_received INT)
proc: BEGIN
  DECLARE v_errno INT;
  DECLARE v_msg VARCHAR(512);
  DECLARE v_run_status VARCHAR(16);
  DECLARE v_ids TEXT;
  DECLARE v_i INT DEFAULT 0;
  DECLARE v_n INT DEFAULT JSON_LENGTH(p_entries);
  DECLARE v_ext VARCHAR(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin;
  DECLARE v_rid VARCHAR(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin;
  DECLARE v_in_status VARCHAR(20);
  DECLARE v_price INT UNSIGNED;
  DECLARE v_cur CHAR(3);
  DECLARE v_cond VARCHAR(16);
  DECLARE v_sum CHAR(64);
  DECLARE v_id BIGINT UNSIGNED;
  DECLARE v_local VARCHAR(20);
  DECLARE v_lsrc VARCHAR(20);
  DECLARE v_lsum CHAR(64);
  DECLARE v_d VARCHAR(64);
  DECLARE v_avail VARCHAR(20);
  DECLARE v_newsrc VARCHAR(20);
  DECLARE v_conflict TINYINT;
  DECLARE v_rec BIGINT UNSIGNED;
  DECLARE c_created INT DEFAULT 0;
  DECLARE c_updated INT DEFAULT 0;
  DECLARE c_skipped INT DEFAULT 0;
  DECLARE c_conflicts INT DEFAULT 0;
  DECLARE c_withdrawn INT DEFAULT 0;
  DECLARE v_errors JSON DEFAULT p_errors;
  DECLARE EXIT HANDLER FOR SQLEXCEPTION
  BEGIN
    GET DIAGNOSTICS CONDITION 1 v_errno = MYSQL_ERRNO, v_msg = MESSAGE_TEXT;
    ROLLBACK;
    CALL t_fail('sync_batch', v_errno, v_msg);
  END;
  SET @err_data = NULL;
  SET c_skipped = JSON_LENGTH(p_errors);   -- currency_mismatch: пропущены, только отметка «есть в источнике»
  START TRANSACTION;
  CALL tr(CONCAT('BEGIN sync B (экземпляры) run #', p_run, ', ', v_n, ' шт.'));
  SELECT status INTO v_run_status FROM wp_book_sync_runs WHERE id = p_run FOR UPDATE;     -- строка прогона
  IF v_run_status IS NULL OR v_run_status <> 'running' THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'sync_run_not_running';
  END IF;
  -- ID существующих экземпляров (в т. ч. пропущенных по валюте — для last_seen) — обычным чтением,
  -- затем FOR UPDATE по возрастанию id
  SELECT GROUP_CONCAT(i.id ORDER BY i.id) INTO v_ids
    FROM wp_book_items i
    JOIN JSON_TABLE(JSON_MERGE_PRESERVE(p_entries, p_errors), '$[*]' COLUMNS (
           ext VARCHAR(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin PATH '$.external_item_id',
           eid VARCHAR(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin PATH '$.external_id')) j
      ON i.external_item_id = COALESCE(j.ext, j.eid)
   WHERE i.source_name = p_source;
  CALL a_lock_ids('items', v_ids);
  CALL tp('sync.after_items');
  -- пропущенные по валюте: только last_seen
  UPDATE wp_book_items i
    JOIN JSON_TABLE(p_errors, '$[*]' COLUMNS (eid VARCHAR(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin PATH '$.external_id')) j
      ON i.external_item_id = j.eid
     SET i.last_seen_sync_run_id = p_run, i.last_synced_at = UTC_TIMESTAMP(6)
   WHERE i.source_name = p_source;

  WHILE v_i < v_n DO
    SET v_ext = JSON_UNQUOTE(JSON_EXTRACT(p_entries, CONCAT('$[', v_i, '].external_item_id')));
    SET v_rid = JSON_UNQUOTE(JSON_EXTRACT(p_entries, CONCAT('$[', v_i, '].source_record_id')));
    SET v_in_status = JSON_UNQUOTE(JSON_EXTRACT(p_entries, CONCAT('$[', v_i, '].status')));
    SET v_price = JSON_EXTRACT(p_entries, CONCAT('$[', v_i, '].price_amount'));
    SET v_cur = JSON_UNQUOTE(JSON_EXTRACT(p_entries, CONCAT('$[', v_i, '].currency')));
    SET v_cond = COALESCE(JSON_UNQUOTE(JSON_EXTRACT(p_entries, CONCAT('$[', v_i, '].condition_code'))), 'good');
    SET v_sum = SHA2(CONCAT_WS('|', 'v1', v_ext, v_in_status, v_price, v_cur, v_cond), 256);
    SET v_id = NULL, v_local = NULL, v_lsrc = NULL, v_lsum = NULL;
    SELECT id, availability_status, source_status, source_checksum INTO v_id, v_local, v_lsrc, v_lsum
      FROM wp_book_items WHERE source_name = p_source AND external_item_id = v_ext;    -- строка уже под нашей X-блокировкой
    IF v_id IS NULL THEN
      IF v_in_status = 'withdrawn' THEN
        SET c_skipped = c_skipped + 1;
      ELSE
        -- запись создана транзакцией A; FK-проверка INSERT берёт на неё только S-блокировку
        SELECT id INTO v_rec FROM wp_book_records WHERE source_name = p_source AND source_record_id = v_rid;
        INSERT INTO wp_book_items (book_record_id, source_name, external_item_id, price_amount, currency, condition_code,
                                   availability_status, status_changed_at, is_active, source_status, source_checksum,
                                   last_seen_sync_run_id, last_synced_at)
        VALUES (v_rec, p_source, v_ext, v_price, v_cur, v_cond, 'available', UTC_TIMESTAMP(6), 1, 'present', v_sum, p_run, UTC_TIMESTAMP(6));
        SET c_created = c_created + 1;
      END IF;
    ELSE
      SET v_d = f_sync_decide(v_local, v_lsrc, v_in_status);
      SET v_avail = SUBSTRING_INDEX(v_d, '|', 1);
      SET v_newsrc = SUBSTRING_INDEX(SUBSTRING_INDEX(v_d, '|', 2), '|', -1);
      SET v_conflict = CAST(SUBSTRING_INDEX(v_d, '|', -1) AS UNSIGNED);
      IF v_conflict = 1 THEN
        SET c_conflicts = c_conflicts + 1;
        IF v_local = 'sold' THEN
          SET v_errors = JSON_ARRAY_APPEND(v_errors, '$', JSON_OBJECT('external_id', v_ext, 'code', 'conflict_sold',
                                                                     'message', 'Source still offers an item sold locally'));
        END IF;
      END IF;
      CALL tr(CONCAT('экземпляр #', v_id, ' ', v_ext, ': локально ', v_local, '/', v_lsrc, ', источник ', v_in_status,
                     ' → ', v_avail, '/', v_newsrc, IF(v_conflict = 1, ' (КОНФЛИКТ)', '')));
      IF v_lsum <=> v_sum AND v_avail = v_local AND v_newsrc = v_lsrc THEN
        UPDATE wp_book_items SET last_seen_sync_run_id = p_run, last_synced_at = UTC_TIMESTAMP(6) WHERE id = v_id;
        SET c_skipped = c_skipped + 1;
      ELSE
        -- поля sold не меняются; статус — только по decide(); условие по прочитанному под блокировкой статусу
        UPDATE wp_book_items
           SET last_seen_sync_run_id = p_run, last_synced_at = UTC_TIMESTAMP(6), source_status = v_newsrc, source_checksum = v_sum,
               price_amount = IF(v_local = 'sold', price_amount, v_price),
               currency = IF(v_local = 'sold', currency, v_cur),
               condition_code = IF(v_local = 'sold', condition_code, v_cond),
               status_changed_at = IF(v_avail <> v_local, UTC_TIMESTAMP(6), status_changed_at),
               availability_status = v_avail
         WHERE id = v_id AND availability_status = v_local;
        IF ROW_COUNT() <> 1 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'logic_error: sync item update'; END IF;
        SET c_updated = c_updated + 1;
        IF v_avail <> v_local THEN
          IF v_avail = 'withdrawn' THEN SET c_withdrawn = c_withdrawn + 1; END IF;
          CALL a_audit('item.status_changed', 'item', v_id, v_local, v_avail, JSON_OBJECT('run_id', p_run), 'sync', NULL);
        ELSEIF v_conflict = 1 THEN
          CALL a_audit('sync.conflict', 'item', v_id, v_local, v_local, JSON_OBJECT('run_id', p_run, 'source_status', v_newsrc), 'sync', NULL);
        END IF;
      END IF;
    END IF;
    SET v_i = v_i + 1;
  END WHILE;

  -- счётчики — инкрементом в той же транзакции (повтор пакета их не удваивает: курсор двигается здесь же)
  UPDATE wp_book_sync_runs
     SET heartbeat_at = UTC_TIMESTAMP(6), records_received = records_received + p_received,
         items_created = items_created + c_created, items_updated = items_updated + c_updated,
         items_skipped = items_skipped + c_skipped, items_conflicts = items_conflicts + c_conflicts,
         items_withdrawn = items_withdrawn + c_withdrawn,
         errors_count = errors_count + JSON_LENGTH(p_errors),   -- conflict_sold — не ошибка, не считается
         error_log = IF(JSON_LENGTH(v_errors) = 0, error_log,
                        JSON_MERGE_PRESERVE(COALESCE(error_log, JSON_ARRAY()), v_errors))
   WHERE id = p_run;
  COMMIT;
  CALL t_ok('sync_batch', 'applied', 200, JSON_OBJECT('created', c_created, 'updated', c_updated, 'skipped', c_skipped,
                                                      'conflicts', c_conflicts, 'withdrawn', c_withdrawn));
END$$

CREATE PROCEDURE a_sync_finish(IN p_run BIGINT UNSIGNED, IN p_source VARCHAR(64))
BEGIN
  UPDATE wp_book_sync_runs SET status = 'succeeded', finished_at = UTC_TIMESTAMP(6) WHERE id = p_run AND status = 'running';
  DO RELEASE_LOCK(f_lock_name(CONCAT('sync_', p_source), 'wp_'));
END$$

DELIMITER ;
