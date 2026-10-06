-- =====================================================================
-- Тестовая обвязка для tests/mysql/run.sh (только для тестовой БД!)
--
--   t_trace    — хронология шагов всех сессий (MyISAM: запись переживает
--                ROLLBACK и видна другим сессиям сразу, без COMMIT);
--   t_outcome  — итог каждой операции (код REST, HTTP, errno MySQL);
--   t_session  — thread_id ↔ метка сессии (U1, CRON, WH1 …) для расшифровки
--                performance_schema.data_lock_waits.
--
-- Синхронизация параллельных сессий:
--   tp(point)            — «точка паузы» внутри алгоритма. Если @pause_at
--                          содержит point, сессия (держа свои блокировки)
--                          ставит пользовательскую блокировку-маяк
--                          GET_LOCK('t:<scn>:<sess>:<point>') и ждёт, пока
--                          в performance_schema.data_lock_waits не появится
--                          @pause_waiters (по умолчанию 1) сессий, ждущих
--                          ИМЕННО её блокировок (по id транзакции). Так пересечение гарантировано,
--                          а не «скорее всего» (без подбора SLEEP).
--   t_wait_paused(s, p)  — партнёр ждёт маяк сессии s в точке p и только
--                          после этого начинает свою транзакцию.
--   t_rendezvous(p, csv) — все перечисленные сессии дошли до точки p.
--   t_start_at(epoch)    — «стартовый пистолет» для стресс-прогонов.
-- =====================================================================

DROP TABLE IF EXISTS t_trace;
DROP TABLE IF EXISTS t_outcome;
DROP TABLE IF EXISTS t_session;

CREATE TABLE t_trace (
  id    INT UNSIGNED NOT NULL AUTO_INCREMENT,
  ts    DATETIME(6)  NOT NULL,
  scn   VARCHAR(32)  NOT NULL,
  sess  VARCHAR(16)  NOT NULL,
  msg   VARCHAR(1000) NOT NULL,
  PRIMARY KEY (id),
  KEY ix_trace_scn (scn, id)
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci;

CREATE TABLE t_outcome (
  id     INT UNSIGNED NOT NULL AUTO_INCREMENT,
  ts     DATETIME(6)  NOT NULL,
  scn    VARCHAR(32)  NOT NULL,
  sess   VARCHAR(16)  NOT NULL,
  op     VARCHAR(32)  NOT NULL,
  code   VARCHAR(64)  NOT NULL,
  http   SMALLINT     NOT NULL,
  errno  INT          NOT NULL DEFAULT 0,
  msg    VARCHAR(512) NULL,
  data   TEXT         NULL,
  retried TINYINT     NOT NULL DEFAULT 0 COMMENT '1 — после этой ошибки операция повторена (как Db::transaction)',
  PRIMARY KEY (id),
  KEY ix_outcome_scn (scn, sess, op)
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci;

CREATE TABLE t_session (
  thread_id BIGINT UNSIGNED NOT NULL,
  conn_id   BIGINT UNSIGNED NOT NULL,
  scn       VARCHAR(32) NOT NULL,
  sess      VARCHAR(16) NOT NULL,
  PRIMARY KEY (thread_id)
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci;

DROP PROCEDURE IF EXISTS t_hello;
DROP PROCEDURE IF EXISTS tr;
DROP PROCEDURE IF EXISTS tp;
DROP PROCEDURE IF EXISTS t_wait_paused;
DROP PROCEDURE IF EXISTS t_rendezvous;
DROP PROCEDURE IF EXISTS t_start_at;
DROP PROCEDURE IF EXISTS t_wait_used;
DROP PROCEDURE IF EXISTS t_retry;
DROP PROCEDURE IF EXISTS t_ok;
DROP PROCEDURE IF EXISTS t_fail;
DROP FUNCTION IF EXISTS f_map_code;
DROP FUNCTION IF EXISTS f_http;
DROP FUNCTION IF EXISTS f_uuid4;

DELIMITER $$

-- Регистрация сессии: вызывается первой командой каждой тестовой сессии.
CREATE PROCEDURE t_hello()
BEGIN
  REPLACE INTO t_session (thread_id, conn_id, scn, sess)
  VALUES (PS_CURRENT_THREAD_ID(), CONNECTION_ID(), COALESCE(@scn, '-'), COALESCE(@sess, '-'));
END$$

-- Шаг хронологии. SYSDATE(6) — настоящие часы (не зависят от SET TIMESTAMP «машины времени»).
CREATE PROCEDURE tr(IN p_msg VARCHAR(1000))
BEGIN
  INSERT INTO t_trace (ts, scn, sess, msg) VALUES (SYSDATE(6), COALESCE(@scn, '-'), COALESCE(@sess, '-'), p_msg);
END$$

CREATE PROCEDURE tp(IN p_point VARCHAR(64))
BEGIN
  DECLARE v_deadline DATETIME(6);
  DECLARE v_n INT DEFAULT 0;
  DECLARE v_trx BIGINT UNSIGNED;
  DECLARE v_info VARCHAR(900);
  -- Списки точек сравниваются в явной коллации: клиент мог не выполнить SET NAMES … utf8mb4_unicode_520_ci.
  IF @rv_at IS NOT NULL AND FIND_IN_SET(p_point, CONVERT(@rv_at USING utf8mb4) COLLATE utf8mb4_unicode_520_ci) > 0 THEN
    CALL t_rendezvous(p_point, @rv_peers);
  END IF;
  IF @pause_at IS NOT NULL AND FIND_IN_SET(p_point, CONVERT(@pause_at USING utf8mb4) COLLATE utf8mb4_unicode_520_ci) > 0 THEN
    CALL tr(CONCAT('ПАУЗА в ', p_point, ': держу блокировки, жду ожидающих: ', COALESCE(@pause_waiters, 1)));
    DO GET_LOCK(CONCAT('t:', @scn, ':', @sess, ':', p_point), 0);
    -- Владелец ищется по id транзакции: когда ожидающий натыкается на НЕЯВНУЮ блокировку (незакоммиченный
    -- INSERT), InnoDB делает её явной от имени владельца, но performance_schema записывает в BLOCKING_THREAD_ID
    -- поток ожидающего (проверено на 8.0.46). BLOCKING_ENGINE_TRANSACTION_ID при этом верный.
    -- id своей транзакции — из живого data_locks (information_schema.innodb_trx кэшируется ~100 мс и может
    -- ещё не содержать транзакцию). Табличные IX/IS-блокировки всегда создаёт сама транзакция-владелец.
    SELECT MAX(ENGINE_TRANSACTION_ID) INTO v_trx FROM performance_schema.data_locks
     WHERE THREAD_ID = PS_CURRENT_THREAD_ID() AND LOCK_TYPE = 'TABLE';
    SET v_deadline = SYSDATE(6) + INTERVAL COALESCE(@pause_timeout, 10) SECOND;
    w: LOOP
      SELECT COUNT(DISTINCT REQUESTING_THREAD_ID) INTO v_n
        FROM performance_schema.data_lock_waits
       WHERE (BLOCKING_ENGINE_TRANSACTION_ID = v_trx OR BLOCKING_THREAD_ID = PS_CURRENT_THREAD_ID())
         AND REQUESTING_THREAD_ID <> PS_CURRENT_THREAD_ID();
      IF v_n >= COALESCE(@pause_waiters, 1) THEN
        SELECT GROUP_CONCAT(DISTINCT CONCAT(COALESCE(s.sess, CONCAT('thread#', w.REQUESTING_THREAD_ID)),
                                            ' ждёт ', l.LOCK_MODE, ' ', l.OBJECT_NAME, '.', COALESCE(l.INDEX_NAME, '-'),
                                            ' (', COALESCE(l.LOCK_DATA, ''), ')')
                            ORDER BY s.sess SEPARATOR '; ')
          INTO v_info
          FROM performance_schema.data_lock_waits w
          JOIN performance_schema.data_locks l ON l.ENGINE_LOCK_ID = w.REQUESTING_ENGINE_LOCK_ID
          LEFT JOIN t_session s ON s.thread_id = w.REQUESTING_THREAD_ID
         WHERE (w.BLOCKING_ENGINE_TRANSACTION_ID = v_trx OR w.BLOCKING_THREAD_ID = PS_CURRENT_THREAD_ID())
           AND w.REQUESTING_THREAD_ID <> PS_CURRENT_THREAD_ID();
        CALL tr(CONCAT('ВИЖУ ОЖИДАНИЕ: ', COALESCE(v_info, '?')));
        LEAVE w;
      END IF;
      IF SYSDATE(6) > v_deadline THEN
        CALL tr(CONCAT('за ', COALESCE(@pause_timeout, 10), ' с никто не встал в очередь — продолжаю'));
        LEAVE w;
      END IF;
      DO SLEEP(0.005);
    END LOOP;
  END IF;
END$$

CREATE PROCEDURE t_wait_paused(IN p_sess VARCHAR(16), IN p_point VARCHAR(64))
BEGIN
  DECLARE v_deadline DATETIME(6) DEFAULT SYSDATE(6) + INTERVAL 15 SECOND;
  w: LOOP
    IF IS_USED_LOCK(CONCAT('t:', @scn, ':', p_sess, ':', p_point)) IS NOT NULL THEN
      CALL tr(CONCAT(p_sess, ' на паузе в ', p_point, ' — начинаю'));
      LEAVE w;
    END IF;
    IF SYSDATE(6) > v_deadline THEN
      CALL tr(CONCAT('ТАЙМАУТ: ', p_sess, ' так и не встал на паузу ', p_point));
      LEAVE w;
    END IF;
    DO SLEEP(0.002);
  END LOOP;
END$$

CREATE PROCEDURE t_rendezvous(IN p_point VARCHAR(64), IN p_peers VARCHAR(255))
BEGIN
  DECLARE v_deadline DATETIME(6) DEFAULT SYSDATE(6) + INTERVAL 15 SECOND;
  DECLARE v_i INT;
  DECLARE v_all INT;
  DECLARE v_n INT DEFAULT 1 + LENGTH(p_peers) - LENGTH(REPLACE(p_peers, ',', ''));
  DO GET_LOCK(CONCAT('rv:', @scn, ':', p_point, ':', @sess), 0);
  w: LOOP
    SET v_i = 1, v_all = 1;
    WHILE v_i <= v_n DO
      IF IS_USED_LOCK(CONCAT('rv:', @scn, ':', p_point, ':', SUBSTRING_INDEX(SUBSTRING_INDEX(p_peers, ',', v_i), ',', -1))) IS NULL THEN
        SET v_all = 0;
      END IF;
      SET v_i = v_i + 1;
    END WHILE;
    IF v_all = 1 THEN
      CALL tr(CONCAT('все дошли до ', p_point, ' (', p_peers, ')'));
      LEAVE w;
    END IF;
    IF SYSDATE(6) > v_deadline THEN
      CALL tr(CONCAT('ТАЙМАУТ рандеву ', p_point));
      LEAVE w;
    END IF;
    DO SLEEP(0.002);
  END LOOP;
END$$

-- Ждать, пока кто-то держит пользовательскую блокировку p_name (например, GET_LOCK синхронизации).
CREATE PROCEDURE t_wait_used(IN p_name VARCHAR(64))
BEGIN
  DECLARE v_deadline DATETIME(6) DEFAULT SYSDATE(6) + INTERVAL 15 SECOND;
  WHILE IS_USED_LOCK(p_name) IS NULL AND SYSDATE(6) < v_deadline DO
    DO SLEEP(0.002);
  END WHILE;
END$$

-- Повтор операции после 1213/1205, как Db::transaction(): до 3 повторов с паузой 50–200 мс.
-- p_call — текст CALL процедуры-алгоритма, p_op — её метка в t_outcome.
CREATE PROCEDURE t_retry(IN p_call TEXT, IN p_op VARCHAR(32))
BEGIN
  DECLARE v_try INT DEFAULT 1;
  DECLARE v_id INT UNSIGNED;
  DECLARE v_errno INT;
  r: LOOP
    SET @t_retry_sql = p_call;
    PREPARE t_retry_stmt FROM @t_retry_sql;
    EXECUTE t_retry_stmt;
    DEALLOCATE PREPARE t_retry_stmt;
    SET v_id = NULL, v_errno = NULL;
    SELECT id, errno INTO v_id, v_errno FROM t_outcome
     WHERE scn = @scn AND sess = @sess AND op = p_op ORDER BY id DESC LIMIT 1;
    IF v_errno IS NULL OR v_errno NOT IN (1213, 1205) OR v_try > 3 THEN
      LEAVE r;
    END IF;
    UPDATE t_outcome SET retried = 1 WHERE id = v_id;
    CALL tr(CONCAT('повтор №', v_try, ' после errno ', v_errno, ' — как Db::transaction(), пауза 50–200 мс'));
    DO SLEEP(0.05 + RAND() * 0.15);
    SET v_try = v_try + 1;
  END LOOP;
END$$

CREATE PROCEDURE t_start_at(IN p_epoch DECIMAL(20,6))
BEGIN
  DO SLEEP(GREATEST(0, p_epoch - UNIX_TIMESTAMP(SYSDATE(6))));
END$$

-- Ошибка MySQL → код ошибки REST (как DomainError / mapConstraintViolation в PHP).
CREATE FUNCTION f_map_code(p_errno INT, p_msg VARCHAR(512)) RETURNS VARCHAR(64) DETERMINISTIC
BEGIN
  IF p_errno = 1644 THEN
    RETURN SUBSTRING_INDEX(p_msg, ':', 1);
  ELSEIF p_errno = 1062 AND (p_msg LIKE '%uq_reservations_one_active_per_item%'
                          OR p_msg LIKE '%uq_cart_items_one_active_per_item%') THEN
    RETURN 'uniundata_item_unavailable';
  ELSEIF (p_errno = 1062 AND p_msg LIKE '%uq_reservations_attempt%')
      OR (p_errno = 3819 AND p_msg LIKE '%\_chk\_attempt\_range%') THEN
    -- как Db::isCheckViolation(): имя CHECK сравнивается по суффиксу (<prefix>book_reservations_chk_attempt_range)
    RETURN 'uniundata_reservation_limit_reached';
  ELSEIF p_errno IN (1213, 1205) THEN
    -- PHP Db::transaction() повторил бы до 3 раз; тест повторов не делает, чтобы увидеть каждый deadlock.
    RETURN 'uniundata_conflict_retry';
  END IF;
  RETURN 'uniundata_internal';
END$$

CREATE FUNCTION f_http(p_code VARCHAR(64)) RETURNS SMALLINT DETERMINISTIC
BEGIN
  RETURN CASE p_code
    WHEN 'uniundata_item_not_found' THEN 404
    WHEN 'uniundata_order_not_found' THEN 404
    WHEN 'uniundata_reservation_expired' THEN 410
    WHEN 'uniundata_item_unavailable' THEN 409
    WHEN 'uniundata_reservation_limit_reached' THEN 409
    WHEN 'uniundata_active_reservation_limit' THEN 409
    WHEN 'uniundata_reservation_not_found' THEN 404
    WHEN 'uniundata_order_not_cancellable' THEN 409
    WHEN 'uniundata_payment_provider_error' THEN 502
    WHEN 'uniundata_cart_empty' THEN 409
    WHEN 'uniundata_cart_changed' THEN 409
    WHEN 'uniundata_order_not_payable' THEN 409
    WHEN 'uniundata_conflict_retry' THEN 503
    ELSE 500 END;
END$$

-- UUID v4 (как wp_generate_uuid4()): нужен для wp_book_orders_chk_public_id.
CREATE FUNCTION f_uuid4() RETURNS CHAR(36) NOT DETERMINISTIC NO SQL
BEGIN
  DECLARE h CHAR(32) DEFAULT LOWER(HEX(RANDOM_BYTES(16)));
  RETURN CONCAT(SUBSTR(h, 1, 8), '-', SUBSTR(h, 9, 4), '-4', SUBSTR(h, 14, 3), '-',
                SUBSTR('89ab', 1 + (ASCII(SUBSTR(h, 17, 1)) % 4), 1), SUBSTR(h, 18, 3), '-', SUBSTR(h, 21, 12));
END$$

CREATE PROCEDURE t_ok(IN p_op VARCHAR(32), IN p_code VARCHAR(64), IN p_http SMALLINT, IN p_data TEXT)
BEGIN
  INSERT INTO t_outcome (ts, scn, sess, op, code, http, errno, msg, data)
  VALUES (SYSDATE(6), COALESCE(@scn, '-'), COALESCE(@sess, '-'), p_op, p_code, p_http, 0, NULL, p_data);
  CALL tr(CONCAT('COMMIT → ', p_http, ' ', p_code, IF(p_data IS NULL, '', CONCAT(' ', LEFT(p_data, 300)))));
END$$

CREATE PROCEDURE t_fail(IN p_op VARCHAR(32), IN p_errno INT, IN p_msg VARCHAR(512))
BEGIN
  DECLARE v_code VARCHAR(64) DEFAULT f_map_code(p_errno, p_msg);
  INSERT INTO t_outcome (ts, scn, sess, op, code, http, errno, msg, data)
  VALUES (SYSDATE(6), COALESCE(@scn, '-'), COALESCE(@sess, '-'), p_op, v_code, f_http(v_code), p_errno, p_msg, @err_data);
  CALL tr(CONCAT('ROLLBACK → ', f_http(v_code), ' ', v_code, ' (errno ', p_errno, ': ', LEFT(p_msg, 200), ')',
                 IF(@err_data IS NULL, '', CONCAT(' ', LEFT(@err_data, 200)))));
END$$

DELIMITER ;
