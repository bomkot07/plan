-- =====================================================================
-- Глобальные инварианты данных: каждый запрос должен вернуть 0.
-- run.sh проверяет их после всех сценариев (включая стресс-прогоны).
-- Те же запросы пригодны для ежедневной проверки продакшен-БД.
-- =====================================================================

DROP VIEW IF EXISTS t_invariants;

CREATE VIEW t_invariants AS
-- I01: экземпляр reserved ⇔ ровно один active-резерв
SELECT 'I01 reserved-экземпляр имеет ровно один active-резерв' AS name, COUNT(*) AS violations
  FROM wp_book_items i
 WHERE i.availability_status = 'reserved'
   AND (SELECT COUNT(*) FROM wp_book_reservations r WHERE r.book_item_id = i.id AND r.reservation_status = 'active') <> 1
UNION ALL
SELECT 'I02 active-резерв только у reserved-экземпляра', COUNT(*)
  FROM wp_book_reservations r JOIN wp_book_items i ON i.id = r.book_item_id
 WHERE r.reservation_status = 'active' AND i.availability_status <> 'reserved'
UNION ALL
-- I03: active-резерв ⇔ active-позиция корзины с тем же reservation_id
SELECT 'I03 active-резерв ⇔ active-позиция корзины', COUNT(*)
  FROM wp_book_reservations r
  LEFT JOIN wp_book_cart_items ci ON ci.reservation_id = r.id
 WHERE (r.reservation_status = 'active') <> (COALESCE(ci.status, '') = 'active')
UNION ALL
SELECT 'I04 active-позиция лежит в открытой корзине владельца резерва', COUNT(*)
  FROM wp_book_cart_items ci
  JOIN wp_book_carts c ON c.id = ci.cart_id
  JOIN wp_book_reservations r ON r.id = ci.reservation_id
 WHERE ci.status = 'active' AND (c.status NOT IN ('active', 'checkout_started') OR c.user_id <> r.user_id OR r.cart_id <> c.id)
UNION ALL
-- I05: sold ⇔ ровно одна продажа
SELECT 'I05 sold-экземпляр имеет ровно одну продажу', COUNT(*)
  FROM wp_book_items i
 WHERE i.availability_status = 'sold' AND (SELECT COUNT(*) FROM wp_book_sales s WHERE s.book_item_id = i.id) <> 1
UNION ALL
SELECT 'I06 продажа только у sold-экземпляра', COUNT(*)
  FROM wp_book_sales s JOIN wp_book_items i ON i.id = s.book_item_id
 WHERE i.availability_status <> 'sold'
UNION ALL
-- I07: checkout_pending ⇔ ровно один открытый заказ с этим экземпляром
SELECT 'I07 checkout_pending-экземпляр в ровно одном открытом заказе', COUNT(*)
  FROM wp_book_items i
 WHERE i.availability_status = 'checkout_pending'
   AND (SELECT COUNT(*) FROM wp_book_order_items oi JOIN wp_book_orders o ON o.id = oi.order_id
         WHERE oi.book_item_id = i.id
           AND o.status IN ('draft', 'pending_payment', 'payment_processing', 'payment_failed')) <> 1
UNION ALL
SELECT 'I08 у открытого заказа все экземпляры checkout_pending', COUNT(*)
  FROM wp_book_orders o JOIN wp_book_order_items oi ON oi.order_id = o.id JOIN wp_book_items i ON i.id = oi.book_item_id
 WHERE o.status IN ('draft', 'pending_payment', 'payment_processing', 'payment_failed')
   AND i.availability_status <> 'checkout_pending'
UNION ALL
SELECT 'I09 попыток (attempt_no IS NOT NULL) на пару пользователь+экземпляр не больше 3', COUNT(*)
  FROM (SELECT user_id, book_item_id FROM wp_book_reservations WHERE attempt_no IS NOT NULL
         GROUP BY user_id, book_item_id HAVING COUNT(*) > 3) x
UNION ALL
-- I10: оплаченный заказ: есть продажа или флаг ручного разбора (поздний платёж без свободных книг)
SELECT 'I10 оплаченный заказ имеет продажи или needs_attention', COUNT(*)
  FROM wp_book_orders o
 WHERE o.status IN ('paid', 'fulfilled', 'completed')
   AND NOT EXISTS (SELECT 1 FROM wp_book_sales s WHERE s.order_id = o.id) AND o.needs_attention = 0
UNION ALL
SELECT 'I11 продажа только по оплаченному заказу и успешному платежу этого заказа', COUNT(*)
  FROM wp_book_sales s
  JOIN wp_book_orders o ON o.id = s.order_id
  JOIN wp_book_payments p ON p.id = s.payment_id
 WHERE o.status NOT IN ('paid', 'fulfilled', 'completed', 'refunded', 'partially_refunded')
    OR p.order_id <> s.order_id OR p.status NOT IN ('succeeded', 'refunded', 'partially_refunded')
UNION ALL
SELECT 'I12 цена продажи = снимок цены в заказе', COUNT(*)
  FROM wp_book_sales s JOIN wp_book_order_items oi ON oi.id = s.order_item_id
 WHERE s.price_amount <> oi.unit_price_amount OR s.book_item_id <> oi.book_item_id
UNION ALL
SELECT 'I13 больше одного успешного платежа ⇒ needs_attention', COUNT(*)
  FROM wp_book_orders o
 WHERE (SELECT COUNT(*) FROM wp_book_payments p WHERE p.order_id = o.id AND p.status IN ('succeeded', 'refunded', 'partially_refunded')) > 1
   AND o.needs_attention = 0
UNION ALL
SELECT 'I14 converted_to_order-резерв ссылается на заказ с этим экземпляром', COUNT(*)
  FROM wp_book_reservations r
 WHERE r.reservation_status = 'converted_to_order'
   AND NOT EXISTS (SELECT 1 FROM wp_book_order_items oi WHERE oi.order_id = r.order_id AND oi.book_item_id = r.book_item_id)
UNION ALL
SELECT 'I15 webhook-событие processed не более одного раза (UNIQUE provider+event_id)', COUNT(*)
  FROM (SELECT provider, provider_event_id FROM wp_book_payment_events GROUP BY provider, provider_event_id HAVING COUNT(*) > 1) x
UNION ALL
SELECT 'I16 сумма заказа = сумма снимков позиций', COUNT(*)
  FROM wp_book_orders o
 WHERE o.subtotal_amount <> (SELECT COALESCE(SUM(oi.unit_price_amount), 0) FROM wp_book_order_items oi WHERE oi.order_id = o.id);
