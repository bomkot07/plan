-- =====================================================================
-- Тестовые данные для tests/mysql/run.sh
--
-- Пользователи WordPress (wp_users) в тестовой БД не нужны: схема ссылается
-- на wp_users.ID логически, без FOREIGN KEY. В сценариях user_id — числа:
--   1xxx — покупатели (book_customer), 9001 — менеджер заказов (admin release).
--
-- Каждый сценарий создаёт СВОИ экземпляры через t_mk_item(), поэтому
-- сценарии независимы и их можно запускать в любом порядке.
-- Базовый каталог ниже — для проверок схемы (S0) и как «фон» для инвариантов.
-- =====================================================================

DROP PROCEDURE IF EXISTS t_mk_item;
DELIMITER $$
-- Одна запись MARC + один экземпляр (контракт: внешний book_id = один физический экземпляр,
-- синхронизация по умолчанию создаёт запись на каждый book_id).
CREATE PROCEDURE t_mk_item(IN p_ext VARCHAR(191), IN p_price INT UNSIGNED, OUT o_item BIGINT UNSIGNED)
BEGIN
  INSERT INTO wp_book_records (source_name, source_record_id, source_format, marc21_format, marc21_raw, source_checksum,
                               title, title_sort, authors_text, main_author_sort, isbn_primary, publisher, publication_year,
                               language_code, is_active, last_synced_at)
  VALUES ('primary', p_ext, 'marcxml', 'marcxml',
          CONCAT('<record><controlfield tag="001">', p_ext, '</controlfield></record>'),
          SHA2(CONCAT('rec|', p_ext), 256),
          CONCAT('Книга ', p_ext), LOWER(CONCAT('книга ', p_ext)), 'Тестовый Автор', 'тестовый автор',
          '9783161484100', 'Test Verlag', 1905, 'ger', 1, UTC_TIMESTAMP(6));
  INSERT INTO wp_book_items (book_record_id, source_name, external_item_id, inventory_number, price_amount, currency,
                             condition_code, availability_status, is_active, source_status, source_checksum, last_synced_at)
  VALUES (LAST_INSERT_ID(), 'primary', p_ext, CONCAT('INV-', p_ext), p_price, 'EUR', 'very_good', 'available', 1, 'present',
          SHA2(CONCAT_WS('|', 'v1', p_ext, 'present', p_price, 'EUR', 'very_good'), 256), UTC_TIMESTAMP(6));
  SET o_item = LAST_INSERT_ID();
END$$
DELIMITER ;

-- Базовый каталог: 10 экземпляров «BASE-01» … «BASE-10», цены 10.00 … 55.00 EUR.
SET @i = 0;
CALL t_mk_item('BASE-01', 1000, @i);
CALL t_mk_item('BASE-02', 1500, @i);
CALL t_mk_item('BASE-03', 2000, @i);
CALL t_mk_item('BASE-04', 2500, @i);
CALL t_mk_item('BASE-05', 3000, @i);
CALL t_mk_item('BASE-06', 3500, @i);
CALL t_mk_item('BASE-07', 4000, @i);
CALL t_mk_item('BASE-08', 4500, @i);
CALL t_mk_item('BASE-09', 5000, @i);
CALL t_mk_item('BASE-10', 5500, @i);
