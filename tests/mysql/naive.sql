-- =====================================================================
-- НАМЕРЕННО НЕПРАВИЛЬНЫЕ варианты алгоритмов (только для tests/mysql/run.sh)
--
-- Нужны, чтобы доказать две вещи:
--   1) второй рубеж: даже если в коде забыть FOR UPDATE, UNIQUE/CHECK схемы
--      не дают нарушить инвариант (1062 / 3819 вместо двойного резерва/продажи);
--   2) тест действительно ловит deadlock: нарушение глобального порядка
--      блокировок воспроизводимо даёт ERROR 1213, а правильный порядок — нет.
-- В плагине таких процедур нет.
-- =====================================================================

DROP PROCEDURE IF EXISTS n_reserve;
DROP PROCEDURE IF EXISTS n_sell;
DROP PROCEDURE IF EXISTS n_get_cart;
DROP PROCEDURE IF EXISTS n_lock_pair;
DROP PROCEDURE IF EXISTS a_get_cart;

DELIMITER $$

-- reserve БЕЗ SELECT … FOR UPDATE экземпляра и без проверки лимита попыток.
CREATE PROCEDURE n_reserve(IN p_user BIGINT UNSIGNED, IN p_item BIGINT UNSIGNED)
BEGIN
  DECLARE v_errno INT;
  DECLARE v_msg VARCHAR(512);
  DECLARE v_cart BIGINT UNSIGNED;
  DECLARE v_cart_status VARCHAR(20);
  DECLARE v_status VARCHAR(20);
  DECLARE v_price INT UNSIGNED;
  DECLARE v_cur CHAR(3);
  DECLARE v_used INT;
  DECLARE v_res BIGINT UNSIGNED;
  DECLARE EXIT HANDLER FOR SQLEXCEPTION
  BEGIN
    GET DIAGNOSTICS CONDITION 1 v_errno = MYSQL_ERRNO, v_msg = MESSAGE_TEXT;
    ROLLBACK;
    CALL t_fail('naive_reserve', v_errno, v_msg);
  END;
  SET @err_data = NULL;
  START TRANSACTION;
  CALL tr(CONCAT('BEGIN naive reserve(user ', p_user, ', item #', p_item, ') — БЕЗ FOR UPDATE экземпляра'));
  CALL a_lock_or_create_cart(p_user, v_cart, v_cart_status);
  SELECT availability_status, price_amount, currency INTO v_status, v_price, v_cur FROM wp_book_items WHERE id = p_item;
  CALL tr(CONCAT('обычный SELECT экземпляра #', p_item, ' → ', v_status, ' (снимок READ COMMITTED, без блокировки)'));
  IF v_status <> 'available' THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'uniundata_item_unavailable';
  END IF;
  SELECT COUNT(*) INTO v_used FROM wp_book_reservations
   WHERE user_id = p_user AND book_item_id = p_item AND attempt_no IS NOT NULL;
  INSERT INTO wp_book_reservations (book_item_id, user_id, cart_id, reservation_status, attempt_no, reserved_at, expires_at)
  VALUES (p_item, p_user, v_cart, 'active', v_used + 1, UTC_TIMESTAMP(6), UTC_TIMESTAMP(6) + INTERVAL 60 MINUTE);
  SET v_res = LAST_INSERT_ID();
  UPDATE wp_book_items SET availability_status = 'reserved', status_changed_at = UTC_TIMESTAMP(6) WHERE id = p_item;
  INSERT INTO wp_book_cart_items (cart_id, book_item_id, reservation_id, unit_price_amount, currency, status, added_at, expires_at)
  VALUES (v_cart, p_item, v_res, v_price, v_cur, 'active', UTC_TIMESTAMP(6), UTC_TIMESTAMP(6) + INTERVAL 60 MINUTE);
  CALL tr(CONCAT('INSERT резерв #', v_res, ' (попытка ', v_used + 1, '), UPDATE экземпляра → reserved'));
  CALL tp('naive.before_commit');
  COMMIT;
  CALL t_ok('naive_reserve', 'created', 201, JSON_OBJECT('reservation_id', v_res, 'attempt_no', v_used + 1));
END$$

-- «Продажа» без блокировок заказа/экземпляра (как обработчик webhook без FOR UPDATE и без inbox).
CREATE PROCEDURE n_sell(IN p_order BIGINT UNSIGNED, IN p_item BIGINT UNSIGNED, IN p_pay BIGINT UNSIGNED)
BEGIN
  DECLARE v_errno INT;
  DECLARE v_msg VARCHAR(512);
  DECLARE v_status VARCHAR(20);
  DECLARE EXIT HANDLER FOR SQLEXCEPTION
  BEGIN
    GET DIAGNOSTICS CONDITION 1 v_errno = MYSQL_ERRNO, v_msg = MESSAGE_TEXT;
    ROLLBACK;
    CALL t_fail('naive_sell', v_errno, v_msg);
  END;
  SET @err_data = NULL;
  START TRANSACTION;
  SELECT availability_status INTO v_status FROM wp_book_items WHERE id = p_item;
  CALL tr(CONCAT('naive webhook: обычный SELECT экземпляра #', p_item, ' → ', v_status));
  IF v_status <> 'checkout_pending' THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'uniundata_item_unavailable';
  END IF;
  UPDATE wp_book_items SET availability_status = 'sold', sold_at = UTC_TIMESTAMP(6), status_changed_at = UTC_TIMESTAMP(6)
   WHERE id = p_item;
  INSERT INTO wp_book_sales (book_item_id, book_record_id, order_id, order_item_id, payment_id, user_id, sold_at, price_amount, currency)
  SELECT oi.book_item_id, oi.book_record_id, oi.order_id, oi.id, p_pay, o.user_id, UTC_TIMESTAMP(6), oi.unit_price_amount, oi.currency
    FROM wp_book_order_items oi JOIN wp_book_orders o ON o.id = oi.order_id
   WHERE oi.order_id = p_order AND oi.book_item_id = p_item;
  CALL tr('UPDATE экземпляра → sold, INSERT в wp_book_sales');
  CALL tp('nsell.before_commit');
  COMMIT;
  CALL t_ok('naive_sell', 'sold', 200, NULL);
END$$

-- Корзина «INSERT → 1062 → SELECT … FOR UPDATE» (вариант, от которого отказались в пользу ODKU).
CREATE PROCEDURE n_get_cart(IN p_user BIGINT UNSIGNED)
BEGIN
  DECLARE v_errno INT;
  DECLARE v_msg VARCHAR(512);
  DECLARE v_cart BIGINT UNSIGNED;
  DECLARE v_dup INT DEFAULT 0;
  DECLARE CONTINUE HANDLER FOR 1062 SET v_dup = 1;
  DECLARE EXIT HANDLER FOR SQLEXCEPTION
  BEGIN
    GET DIAGNOSTICS CONDITION 1 v_errno = MYSQL_ERRNO, v_msg = MESSAGE_TEXT;
    ROLLBACK;
    CALL t_fail('naive_cart', v_errno, v_msg);
  END;
  SET @err_data = NULL;
  START TRANSACTION;
  SELECT id INTO v_cart FROM wp_book_carts WHERE open_cart_user_id = p_user FOR UPDATE;
  IF v_cart IS NULL THEN
    CALL tp('ncart.after_select');
    INSERT INTO wp_book_carts (user_id, status, started_at, last_activity_at)
    VALUES (p_user, 'active', UTC_TIMESTAMP(6), UTC_TIMESTAMP(6));
    IF v_dup = 0 THEN
      SET v_cart = LAST_INSERT_ID();
      CALL tr(CONCAT('INSERT корзины #', v_cart));
      CALL tp('ncart.created');
    ELSE
      CALL tr('INSERT → 1062 (держу S-lock на дубликате), читаю FOR UPDATE (нужен X)');
      SELECT id INTO v_cart FROM wp_book_carts WHERE open_cart_user_id = p_user FOR UPDATE;
    END IF;
  END IF;
  COMMIT;
  CALL t_ok('naive_cart', 'ok', 200, JSON_OBJECT('cart_id', v_cart));
END$$

-- Две блокировки в заданном порядке — для контрольного нарушения глобального порядка.
CREATE PROCEDURE n_lock_pair(IN p_first VARCHAR(16), IN p_first_id BIGINT UNSIGNED,
                             IN p_second VARCHAR(16), IN p_second_id BIGINT UNSIGNED)
BEGIN
  DECLARE v_errno INT;
  DECLARE v_msg VARCHAR(512);
  DECLARE v_x VARCHAR(32);
  DECLARE v_i INT DEFAULT 1;
  DECLARE v_t VARCHAR(16);
  DECLARE v_id BIGINT UNSIGNED;
  DECLARE EXIT HANDLER FOR SQLEXCEPTION
  BEGIN
    GET DIAGNOSTICS CONDITION 1 v_errno = MYSQL_ERRNO, v_msg = MESSAGE_TEXT;
    ROLLBACK;
    CALL t_fail('lock_pair', v_errno, v_msg);
  END;
  SET @err_data = NULL;
  START TRANSACTION;
  WHILE v_i <= 2 DO
    SET v_t = IF(v_i = 1, p_first, p_second), v_id = IF(v_i = 1, p_first_id, p_second_id);
    CASE v_t
      WHEN 'items' THEN SELECT availability_status INTO v_x FROM wp_book_items WHERE id = v_id FOR UPDATE;
      WHEN 'orders' THEN SELECT status INTO v_x FROM wp_book_orders WHERE id = v_id FOR UPDATE;
      WHEN 'carts' THEN SELECT status INTO v_x FROM wp_book_carts WHERE id = v_id FOR UPDATE;
    END CASE;
    CALL tr(CONCAT('FOR UPDATE ', v_t, ' #', v_id, IF(v_i = 1, ' (НАРУШЕН глобальный порядок)', '')));
    IF v_i = 1 THEN
      CALL tp('pair.after_first');
    END IF;
    SET v_i = v_i + 1;
  END WHILE;
  COMMIT;
  CALL t_ok('lock_pair', 'ok', 200, NULL);
END$$

-- Корректный вариант: только шаг (a) reserve — lockOrCreateOpenCart (ODKU) отдельной транзакцией.
CREATE PROCEDURE a_get_cart(IN p_user BIGINT UNSIGNED)
BEGIN
  DECLARE v_errno INT;
  DECLARE v_msg VARCHAR(512);
  DECLARE v_cart BIGINT UNSIGNED;
  DECLARE v_status VARCHAR(20);
  DECLARE EXIT HANDLER FOR SQLEXCEPTION
  BEGIN
    GET DIAGNOSTICS CONDITION 1 v_errno = MYSQL_ERRNO, v_msg = MESSAGE_TEXT;
    ROLLBACK;
    CALL t_fail('cart', v_errno, v_msg);
  END;
  SET @err_data = NULL;
  START TRANSACTION;
  CALL a_lock_or_create_cart(p_user, v_cart, v_status);
  COMMIT;
  CALL t_ok('cart', 'ok', 200, JSON_OBJECT('cart_id', v_cart));
END$$

DELIMITER ;
