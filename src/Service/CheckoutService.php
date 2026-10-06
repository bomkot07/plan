<?php

declare(strict_types=1);

namespace Uniundata\Books\Service;

use Uniundata\Books\Domain\DomainError;
use Uniundata\Books\Domain\ItemStatus;
use Uniundata\Books\Infrastructure\AuditLog;
use Uniundata\Books\Infrastructure\Db;
use Uniundata\Books\Payment\FiscalReceipt;
use Uniundata\Books\Payment\PaymentProviderInterface;
use Uniundata\Books\Payment\PaymentSession;

/**
 * Оформление заказа и платёжные попытки покупателя.
 *
 * checkout() = Tx1 (заказ draft + платёж created, резервы → converted_to_order, экземпляры →
 * checkout_pending) → createSession у банка ВНЕ транзакции → Tx2 (платёж pending + session_redirect_url,
 * заказ pending_payment; при ошибке банка — payment failed / order payment_failed).
 * Повтор /checkout с тем же Idempotency-Key и /orders/{id}/pay при живой сессии возвращают сохранённый
 * session_redirect_url без нового createSession.
 *
 * Порядок блокировок (docs/08): carts → items (asc) → reservations → cart_items → orders → payments.
 * INSERT order_items берёт неявный S-lock на book_records (проверка FK) уже после items — безопасно:
 * синхронизация не держит X на записи, ожидая экземпляры. Решения — по строкам, прочитанным
 * SELECT … FOR UPDATE; переходы — условными UPDATE с проверкой числа строк. Время — UTC_TIMESTAMP(6).
 */
final class CheckoutService
{
    /** Где покупатель может начать/повторить оплату или отменить заказ. */
    private const PAYABLE_ORDER_STATUSES = ['draft', 'pending_payment', 'payment_failed'];
    /** Платёж, после которого новая попытка и отмена невозможны: деньги в пути или получены. */
    private const MONEY_IN_FLIGHT = ['processing', 'succeeded', 'refunded', 'partially_refunded'];
    /** Короче этого сессию у банка не открываем: покупатель не успеет оплатить. */
    private const MIN_SESSION_SECONDS = 120;
    /** Пока сессия жива дольше этого запаса — повтор возвращает её URL (вторую сессию не открываем). */
    private const SESSION_REUSE_MARGIN_SECONDS = 60;
    private const MAX_PAYMENT_ATTEMPTS = 10;

    public function __construct(
        private readonly Db $db,
        private readonly AuditLog $audit,
        private readonly PaymentProviderInterface $provider,
    ) {
    }

    // =================================================================================================
    // POST /checkout
    // =================================================================================================

    /**
     * @return array{created: bool, replayed: bool, order: array<string, mixed>, payment: ?array<string, mixed>}
     *
     * @throws DomainError uniundata_cart_empty | uniundata_cart_changed | uniundata_terms_outdated |
     *                     uniundata_invalid_param | uniundata_payment_provider_error (заказ уже создан,
     *                     data.public_order_id)
     */
    public function checkout(int $userId, CheckoutRequest $req): array
    {
        if ($userId <= 0) {
            throw DomainError::authRequired();
        }

        // Быстрый путь идемпотентности: повтор того же Idempotency-Key → тот же заказ.
        $existing = $this->findOrderByCheckoutKey($userId, $req->idempotencyKey);
        if ($existing !== null) {
            return $this->replay($userId, $existing, $req);
        }

        // Снимки и настройки — вне транзакции (функции WP, кэш, без блокировок).
        $terms = $this->termsSnapshot($req);
        $customer = $this->customerSnapshot($userId, $req);
        $currency = $this->shopCurrency();
        [$ttlMinutes, $graceMinutes] = $this->paymentWindow();

        try {
            $tx = $this->db->transaction(
                fn (): array => $this->createOrderTx($userId, $req, $customer, $terms, $currency, $ttlMinutes + $graceMinutes)
            );
        } catch (\RuntimeException $e) {
            // Второй рубеж: 1062 по uq_orders_checkout_request (параллельный запрос с тем же ключом).
            $dup = $e instanceof DomainError ? null : $this->findOrderByCheckoutKey($userId, $req->idempotencyKey);
            if ($dup !== null) {
                return $this->replay($userId, $dup, $req);
            }
            throw $e;
        }

        if ($tx['kind'] === 'replay') {
            return $this->replay($userId, $tx['order'], $req);
        }
        if ($tx['kind'] === 'cart_changed') {
            // Истёкшие позиции уже помечены и ЗАКОММИЧЕНЫ: клиент показывает новую корзину и подтверждает.
            throw DomainError::cartChanged($tx['data']);
        }

        $payment = $this->openSession($tx['order_id'], $tx['payment_id'], $userId);

        return [
            'created' => true,
            'replayed' => false,
            'order' => $this->orderView($tx['order_id']),
            'payment' => $payment,
        ];
    }

    /**
     * Tx1. Возвращает kind=created|cart_changed|replay. При cart_changed изменения (истёкшие позиции,
     * корзина → checkout_started) фиксируются COMMIT-ом, исключение бросает вызывающий код.
     *
     * @param array{email: string, first_name: string, last_name: string, middle_name: ?string, phone: ?string} $customer
     * @param array<string, array{version: string, sha256: string}> $terms
     * @return array<string, mixed>
     */
    private function createOrderTx(int $userId, CheckoutRequest $req, array $customer, array $terms, string $shopCurrency, int $dueMinutes): array
    {
        $t = $this->tables();

        // (1) Открытая корзина пользователя. Сериализует checkout, reserve, remove-item и expiry этой корзины.
        $cart = $this->row(
            'SELECT id, status FROM %i WHERE open_cart_user_id = %d FOR UPDATE',
            $t['carts'],
            $userId
        );

        // Пока ждали блокировку, параллельный запрос с тем же ключом мог создать заказ.
        $dup = $this->findOrderByCheckoutKey($userId, $req->idempotencyKey);
        if ($dup !== null) {
            return ['kind' => 'replay', 'order' => $dup];
        }
        if ($cart === null) {
            throw DomainError::cartEmpty();
        }
        $cartId = (int) $cart['id'];

        // ID позиций — обычным SELECT: корзина заблокирована, новых позиций появиться не может.
        $positions = $this->rows(
            "SELECT id, book_item_id, reservation_id FROM %i WHERE cart_id = %d AND status = 'active' ORDER BY book_item_id",
            $t['cart_items'],
            $cartId
        );
        if ($positions === []) {
            throw DomainError::cartEmpty();
        }
        $itemIds = $this->ids(array_column($positions, 'book_item_id'));
        $reservationIds = $this->ids(array_column($positions, 'reservation_id'));
        $cartItemIds = $this->ids(array_column($positions, 'id'));

        // (2) Экземпляры по возрастанию id.
        $items = $this->rowsById(
            'SELECT id, availability_status, is_active, source_status FROM %i WHERE id IN (' . $this->in($itemIds) . ') ORDER BY id FOR UPDATE',
            $t['items'],
            ...$itemIds
        );
        // (3) Резервы. alive вычисляется в БД: часы веб-серверов не участвуют.
        $reservations = $this->rowsById(
            'SELECT id, book_item_id, user_id, reservation_status, (expires_at > UTC_TIMESTAMP(6)) AS alive
               FROM %i WHERE id IN (' . $this->in($reservationIds) . ') ORDER BY id FOR UPDATE',
            $t['reservations'],
            ...$reservationIds
        );
        // (4) Позиции корзины.
        $cartItems = $this->rowsById(
            'SELECT id, book_item_id, reservation_id, unit_price_amount, currency, status
               FROM %i WHERE id IN (' . $this->in($cartItemIds) . ') ORDER BY id FOR UPDATE',
            $t['cart_items'],
            ...$cartItemIds
        );

        // Перепроверка под блокировками.
        $valid = [];
        $invalid = [];
        foreach ($cartItems as $ci) {
            $r = $reservations[(int) $ci['reservation_id']] ?? null;
            $i = $items[(int) $ci['book_item_id']] ?? null;
            $reason = match (true) {
                $ci['status'] !== 'active' => null, // уже закрыта параллельно — просто не берём
                $r === null || $i === null => 'missing',
                $r['reservation_status'] !== 'active' => 'reservation_inactive',
                (int) $r['user_id'] !== $userId || (int) $r['book_item_id'] !== (int) $ci['book_item_id'] => 'foreign_reservation',
                (int) $r['alive'] !== 1 => 'expired',
                $i['availability_status'] !== 'reserved' => 'item_not_reserved',
                (int) $i['is_active'] !== 1 => 'item_inactive',
                $ci['currency'] !== $shopCurrency => 'currency', // валюту магазина сменили после резерва
                default => 'ok',
            };
            if ($reason === 'ok') {
                $valid[] = $ci;
            } elseif ($reason !== null) {
                $invalid[] = ['ci' => $ci, 'reservation' => $r, 'item' => $i, 'reason' => $reason];
            }
        }

        if ($invalid !== []) {
            $this->expirePositions($userId, $invalid);
            $this->refreshCart($cartId, (string) $cart['status'], $userId);

            return ['kind' => 'cart_changed', 'data' => [
                'reason' => 'positions_expired',
                'expired_book_item_ids' => array_map(static fn (array $x): int => (int) $x['ci']['book_item_id'], $invalid),
                'cart' => $this->cartSnapshot($cartId),
            ]];
        }
        if ($valid === []) {
            throw DomainError::cartEmpty();
        }

        // Сумма — по снимкам цен в корзине (цена, которую покупатель видел при резерве).
        $total = array_sum(array_map(static fn (array $ci): int => (int) $ci['unit_price_amount'], $valid));
        if ($shopCurrency !== $req->currency || $total !== $req->expectedTotalAmount) {
            $this->refreshCart($cartId, (string) $cart['status'], $userId);

            return ['kind' => 'cart_changed', 'data' => [
                'reason' => 'total_mismatch',
                'actual_total_amount' => $total,
                'currency' => $shopCurrency,
                'cart' => $this->cartSnapshot($cartId),
            ]];
        }

        $validCartItemIds = $this->ids(array_column($valid, 'id'));
        $validItemIds = $this->ids(array_column($valid, 'book_item_id'));
        $validReservationIds = $this->ids(array_column($valid, 'reservation_id'));
        $n = \count($valid);

        // Согласия (append-only доказательство): оферта привязывается к заказу, политика — отдельной строкой.
        $offerConsentId = $this->insertConsent($userId, 'offer', $terms['offer'], $req);
        $this->insertConsent($userId, 'privacy', $terms['privacy'], $req);

        // (5) Заказ draft. public_order_id — UUID v4 (122 бита случайности), id наружу не выдаётся.
        $publicOrderId = 'uniundata_' . wp_generate_uuid4();
        $this->exec(
            "INSERT INTO %i
               (public_order_id, user_id, cart_id, checkout_request_id, status, currency, prices_include_tax,
                subtotal_amount, discount_amount, shipping_amount, tax_amount, total_amount,
                customer_email, customer_phone, customer_first_name, customer_last_name, customer_middle_name,
                billing_address_json, shipping_address_json, offer_consent_id, placed_at, payment_due_at)
             VALUES (%s, %d, %d, %s, 'draft', %s, 1,
                %d, 0, 0, 0, %d,
                %s, NULLIF(%s, ''), %s, %s, NULLIF(%s, ''),
                NULLIF(%s, ''), NULLIF(%s, ''), %d, UTC_TIMESTAMP(6), UTC_TIMESTAMP(6) + INTERVAL %d MINUTE)",
            $t['orders'],
            $publicOrderId,
            $userId,
            $cartId,
            $req->idempotencyKey,
            $shopCurrency,
            $total,
            $total,
            $customer['email'],
            $customer['phone'] ?? '',
            $customer['first_name'],
            $customer['last_name'],
            $customer['middle_name'] ?? '',
            $req->billingAddress !== null ? (string) wp_json_encode($req->billingAddress) : '',
            $req->shippingAddress !== null ? (string) wp_json_encode($req->shippingAddress) : '',
            $offerConsentId,
            $dueMinutes
        );
        $orderId = $this->db->lastInsertId();

        // Снимки позиций: название, автор, ISBN, состояние, цена — не зависят от будущей синхронизации.
        $this->expectAffected($this->exec(
            "INSERT INTO %i
               (order_id, book_item_id, book_record_id, reservation_id, title_snapshot, subtitle_snapshot,
                author_snapshot, isbn_snapshot, publisher_snapshot, publication_year_snapshot, condition_snapshot,
                cover_url_snapshot, item_identifier_snapshot, unit_price_amount, currency, quantity)
             SELECT %d, i.id, i.book_record_id, ci.reservation_id, r.title, r.subtitle,
                    COALESCE(r.authors_text, r.responsibility_statement), r.isbn_primary, r.publisher,
                    r.publication_year, i.condition_code, COALESCE(i.cover_url, r.cover_url),
                    COALESCE(i.external_item_id, i.inventory_number, CONCAT('item-', i.id)),
                    ci.unit_price_amount, ci.currency, 1
               FROM %i ci
               JOIN %i i ON i.id = ci.book_item_id
               JOIN %i r ON r.id = i.book_record_id
              WHERE ci.id IN (" . $this->in($validCartItemIds) . ')
              ORDER BY i.id',
            $t['order_items'],
            $orderId,
            $t['cart_items'],
            $t['items'],
            $t['records'],
            ...$validCartItemIds
        ), $n, 'order_items insert');

        // (3) Резервы → converted_to_order (CHECK _chk_order требует order_id, _chk_released — released_at).
        $this->expectAffected($this->exec(
            "UPDATE %i
                SET order_id = %d, released_at = UTC_TIMESTAMP(6), release_reason = 'converted_to_order',
                    reservation_status = 'converted_to_order'
              WHERE id IN (" . $this->in($validReservationIds) . ") AND reservation_status = 'active'",
            $t['reservations'],
            $orderId,
            ...$validReservationIds
        ), $n, 'reservations → converted_to_order');

        // (4) Позиции корзины → converted_to_order.
        $this->expectAffected($this->exec(
            "UPDATE %i SET closed_at = UTC_TIMESTAMP(6), status = 'converted_to_order'
              WHERE id IN (" . $this->in($validCartItemIds) . ") AND status = 'active'",
            $t['cart_items'],
            ...$validCartItemIds
        ), $n, 'cart_items → converted_to_order');

        // (1) Корзина закрывается: следующий «Отложить» создаст новую.
        $this->expectAffected($this->exec(
            "UPDATE %i
                SET checkout_started_at = COALESCE(checkout_started_at, UTC_TIMESTAMP(6)),
                    last_activity_at = UTC_TIMESTAMP(6), expires_at = NULL, closed_at = UTC_TIMESTAMP(6),
                    status = 'converted_to_order'
              WHERE id = %d AND status IN ('active', 'checkout_started')",
            $t['carts'],
            $cartId
        ), 1, 'cart → converted_to_order');

        // (2) Экземпляры reserved → checkout_pending: дальше срок определяет orders.payment_due_at.
        $this->expectAffected($this->exec(
            "UPDATE %i SET status_changed_at = UTC_TIMESTAMP(6), availability_status = 'checkout_pending'
              WHERE id IN (" . $this->in($validItemIds) . ") AND availability_status = 'reserved'",
            $t['items'],
            ...$validItemIds
        ), $n, 'items → checkout_pending');

        // (6) Платёж: попытка 1, ключ идемпотентности для банка создаётся ДО HTTP-запроса.
        $this->exec(
            "INSERT INTO %i (order_id, provider, attempt_no, idempotency_key, status, amount, currency)
             VALUES (%d, %s, 1, %s, 'created', %d, %s)",
            $t['payments'],
            $orderId,
            $this->provider->name(),
            wp_generate_uuid4(),
            $total,
            $shopCurrency
        );
        $paymentId = $this->db->lastInsertId();

        // Аудит в той же транзакции: откат — значит, и записи аудита нет.
        $this->audit->record('order.created', 'order', $orderId, null, 'draft', [
            'public_order_id' => $publicOrderId, 'total_amount' => $total, 'currency' => $shopCurrency, 'items' => $validItemIds,
        ], 'user', $userId);
        $this->audit->record('cart.status_changed', 'cart', $cartId, (string) $cart['status'], 'converted_to_order', ['order_id' => $orderId], 'user', $userId);
        foreach ($valid as $ci) {
            $this->audit->record('reservation.status_changed', 'reservation', (int) $ci['reservation_id'], 'active', 'converted_to_order', ['order_id' => $orderId], 'user', $userId);
            $this->audit->record('item.status_changed', 'item', (int) $ci['book_item_id'], 'reserved', 'checkout_pending', ['order_id' => $orderId], 'user', $userId);
        }
        $this->audit->record('payment.created', 'payment', $paymentId, null, 'created', ['order_id' => $orderId, 'attempt_no' => 1], 'user', $userId);

        return ['kind' => 'created', 'order_id' => $orderId, 'payment_id' => $paymentId];
    }

    /**
     * Невалидные позиции: резерв → expired, позиция → expired, экземпляр reserved → release target.
     * Освобождаем только экземпляр, который держит именно этот (заблокированный, active) резерв.
     *
     * @param list<array{ci: array<string, mixed>, reservation: ?array<string, mixed>, item: ?array<string, mixed>, reason: string}> $invalid
     */
    private function expirePositions(int $userId, array $invalid): void
    {
        $t = $this->tables();
        foreach ($invalid as $x) {
            $ci = $x['ci'];
            $r = $x['reservation'];
            $i = $x['item'];
            $itemId = (int) $ci['book_item_id'];
            $reason = 'checkout_' . $x['reason'];

            $ownsItem = $r !== null && $r['reservation_status'] === 'active'
                && (int) $r['user_id'] === $userId && (int) $r['book_item_id'] === $itemId;
            if ($ownsItem) {
                $this->expectAffected($this->exec(
                    "UPDATE %i SET released_at = UTC_TIMESTAMP(6), release_reason = %s, reservation_status = 'expired'
                      WHERE id = %d AND reservation_status = 'active'",
                    $t['reservations'],
                    $reason,
                    (int) $r['id']
                ), 1, 'reservation → expired');
                $this->audit->record('reservation.status_changed', 'reservation', (int) $r['id'], 'active', 'expired', ['reason' => $reason], 'user', $userId);

                if ($i !== null && $i['availability_status'] === 'reserved') {
                    $this->expectAffected($this->exec(
                        "UPDATE %i
                            SET status_changed_at = UTC_TIMESTAMP(6),
                                availability_status = CASE source_status
                                    WHEN 'present' THEN 'available'
                                    WHEN 'missing' THEN 'sync_missing'
                                    WHEN 'withdrawn' THEN 'withdrawn' END
                          WHERE id = %d AND availability_status = 'reserved'",
                        $t['items'],
                        $itemId
                    ), 1, 'item → release target');
                    $this->audit->record('item.status_changed', 'item', $itemId, 'reserved', $this->releaseTarget((string) $i['source_status']), ['reason' => $reason], 'user', $userId);
                }
            }

            $this->exec(
                "UPDATE %i SET closed_at = UTC_TIMESTAMP(6), status = 'expired' WHERE id = %d AND status = 'active'",
                $t['cart_items'],
                (int) $ci['id']
            );
            $this->audit->record('cart_item.status_changed', 'cart_item', (int) $ci['id'], 'active', 'expired', ['reason' => $x['reason']], 'user', $userId);
        }
    }

    /**
     * После 409 cart_changed: корзина без активных позиций → expired; иначе → checkout_started
     * (оформление начато, ждём подтверждения) и пересчёт expires_at (только для отображения).
     */
    private function refreshCart(int $cartId, string $fromStatus, int $userId): void
    {
        $t = $this->tables();
        $active = (int) $this->scalar(
            "SELECT COUNT(*) FROM %i WHERE cart_id = %d AND status = 'active'",
            $t['cart_items'],
            $cartId
        );
        if ($active === 0) {
            $closed = $this->exec(
                "UPDATE %i SET expires_at = NULL, closed_at = UTC_TIMESTAMP(6), status = 'expired'
                  WHERE id = %d AND status IN ('active', 'checkout_started')",
                $t['carts'],
                $cartId
            );
            if ($closed === 1) {
                $this->audit->record('cart.status_changed', 'cart', $cartId, $fromStatus, 'expired', ['reason' => 'checkout_positions_expired'], 'user', $userId);
            }

            return;
        }
        $this->exec(
            "UPDATE %i
                SET status = 'checkout_started',
                    checkout_started_at = COALESCE(checkout_started_at, UTC_TIMESTAMP(6)),
                    last_activity_at = UTC_TIMESTAMP(6),
                    expires_at = (SELECT MIN(ci.expires_at) FROM %i ci WHERE ci.cart_id = %d AND ci.status = 'active')
              WHERE id = %d AND status IN ('active', 'checkout_started')",
            $t['carts'],
            $t['cart_items'],
            $cartId,
            $cartId
        );
        if ($fromStatus !== 'checkout_started') {
            $this->audit->record('cart.status_changed', 'cart', $cartId, $fromStatus, 'checkout_started', [], 'user', $userId);
        }
    }

    /** @return array<string, mixed> Корзина для тела 409 uniundata_cart_changed. */
    private function cartSnapshot(int $cartId): array
    {
        $t = $this->tables();
        $rows = $this->rows(
            "SELECT ci.book_item_id, ci.unit_price_amount, ci.currency, ci.expires_at, r.title
               FROM %i ci
               JOIN %i i ON i.id = ci.book_item_id
               JOIN %i r ON r.id = i.book_record_id
              WHERE ci.cart_id = %d AND ci.status = 'active'
              ORDER BY ci.added_at, ci.id",
            $t['cart_items'],
            $t['items'],
            $t['records'],
            $cartId
        );
        $items = array_map(static fn (array $x): array => [
            'book_item_id' => (int) $x['book_item_id'],
            'title' => (string) $x['title'],
            'unit_price_amount' => (int) $x['unit_price_amount'],
            'currency' => (string) $x['currency'],
            'expires_at' => Db::toIso8601($x['expires_at']),
        ], $rows);

        return [
            'items' => $items,
            'total_amount' => array_sum(array_column($items, 'unit_price_amount')),
            'currency' => $items[0]['currency'] ?? null,
        ];
    }

    /**
     * @param array{version: string, sha256: string} $doc
     */
    private function insertConsent(int $userId, string $type, array $doc, CheckoutRequest $req): int
    {
        $this->exec(
            "INSERT INTO %i
               (consent_uuid, user_id, consent_type, document_version, document_sha256, accepted_at, ip_address, user_agent_sha256)
             VALUES (%s, %d, %s, %s, %s, UTC_TIMESTAMP(6), INET6_ATON(NULLIF(%s, '')), NULLIF(%s, ''))",
            $this->db->table('book_user_consents'),
            wp_generate_uuid4(),
            $userId,
            $type,
            $doc['version'],
            $doc['sha256'],
            $req->ipAddress ?? '',
            $req->userAgent !== null ? hash('sha256', $req->userAgent) : ''
        );

        return $this->db->lastInsertId();
    }

    /**
     * Повтор того же Idempotency-Key: тот же заказ. Живая сессия — сохранённый redirect URL; платёж без
     * сессии (`created`) — createSession с тем же ключом. Новая попытка при повторе НЕ создаётся.
     *
     * @param array<string, mixed> $order
     * @return array{created: bool, replayed: bool, order: array<string, mixed>, payment: ?array<string, mixed>}
     */
    private function replay(int $userId, array $order, CheckoutRequest $req): array
    {
        if ((int) $order['total_amount'] !== $req->expectedTotalAmount || $order['currency'] !== $req->currency) {
            throw DomainError::invalidParam('Idempotency-Key', \__('Idempotency-Key was already used for a different checkout.', 'uniundata-books'));
        }
        $orderId = (int) $order['id'];
        $payment = null;
        try {
            $prep = $this->db->transaction(fn (): array => $this->preparePaymentTx($userId, $orderId, false));
            $payment = $prep['live_session']
                ?? ($prep['payment_id'] !== null ? $this->openSession($orderId, $prep['payment_id'], $userId) : null);
        } catch (DomainError $e) {
            if ($e->errorCode() !== 'uniundata_order_not_payable') {
                throw $e;
            }
        }

        return ['created' => false, 'replayed' => true, 'order' => $this->orderView($orderId), 'payment' => $payment];
    }

    // =================================================================================================
    // POST /orders/{public_order_id}/pay
    // =================================================================================================

    /**
     * Платёжная попытка по заказу до payment_due_at. Живая сессия возвращается из session_redirect_url;
     * платёж `created` досоздаётся с тем же idempotency_key; после неудачи — новая строка (attempt_no + 1).
     *
     * @return array{created: bool, replayed: bool, order: array<string, mixed>, payment: ?array<string, mixed>}
     */
    public function pay(int $userId, string $publicOrderId): array
    {
        $orderId = $this->findOwnOrderId($userId, $publicOrderId);
        $prep = $this->db->transaction(fn (): array => $this->preparePaymentTx($userId, $orderId, true));
        $this->cancelSessionsBestEffort($prep['cancel_sessions']);

        $payment = $prep['live_session'] ?? $this->openSession($orderId, (int) $prep['payment_id'], $userId);

        return ['created' => false, 'replayed' => false, 'order' => $this->orderView($orderId), 'payment' => $payment];
    }

    /**
     * Tx: items (asc) → order → payments.
     *  - live_session: у последней попытки живая сессия с сохранённым URL — банк не вызываем;
     *  - payment_id: для этого платежа нужен createSession (новая попытка или тот же ключ);
     *  - cancel_sessions: заменённые сессии, которые закрываются у банка после COMMIT.
     *
     * @return array{payment_id: ?int, live_session: ?array<string, mixed>, cancel_sessions: list<array<string, mixed>>}
     */
    private function preparePaymentTx(int $userId, int $orderId, bool $allowNewAttempt): array
    {
        $t = $this->tables();
        $itemIds = $this->orderItemIds($orderId);

        // (2) Экземпляры заказа.
        $items = $itemIds === [] ? [] : $this->rowsById(
            'SELECT id, availability_status FROM %i WHERE id IN (' . $this->in($itemIds) . ') ORDER BY id FOR UPDATE',
            $t['items'],
            ...$itemIds
        );
        // (5) Заказ + запас времени до payment_due_at − grace (сессия банка не должна его пережить).
        $order = $this->row(
            'SELECT id, user_id, public_order_id, status, total_amount, currency,
                    TIMESTAMPDIFF(SECOND, UTC_TIMESTAMP(6), payment_due_at) AS seconds_to_due
               FROM %i WHERE id = %d FOR UPDATE',
            $t['orders'],
            $orderId
        );
        if ($order === null || (int) $order['user_id'] !== $userId) {
            throw DomainError::orderNotFound();
        }
        $status = (string) $order['status'];
        if (!\in_array($status, self::PAYABLE_ORDER_STATUSES, true)) {
            throw DomainError::orderNotPayable($status);
        }
        foreach ($itemIds as $id) {
            if (($items[$id]['availability_status'] ?? null) !== 'checkout_pending') {
                // Нарушение инварианта «открытый заказ ⇒ экземпляры checkout_pending»: не берём деньги.
                error_log(\sprintf('[uniundata] order #%d is open but item #%d is not checkout_pending', $orderId, $id));
                throw DomainError::orderNotPayable($status);
            }
        }

        // (6) Платежи заказа.
        $payments = $this->rows(
            'SELECT id, attempt_no, status, provider_payment_id, idempotency_key, session_redirect_url, session_expires_at,
                    (session_expires_at > UTC_TIMESTAMP(6) + INTERVAL %d SECOND) AS session_alive
               FROM %i WHERE order_id = %d ORDER BY attempt_no FOR UPDATE',
            self::SESSION_REUSE_MARGIN_SECONDS,
            $t['payments'],
            $orderId
        );
        foreach ($payments as $p) {
            if (\in_array($p['status'], self::MONEY_IN_FLIGHT, true)) {
                throw DomainError::orderNotPayable($status); // деньги в пути или уже получены
            }
        }
        $last = $payments === [] ? null : $payments[\count($payments) - 1];
        $lastAlive = $last !== null && $last['status'] === 'pending' && (int) $last['session_alive'] === 1;

        // Живая сессия с сохранённым URL: вторую не открываем (защита от двойной оплаты), банк не зовём.
        if ($lastAlive && $last['session_redirect_url'] !== null) {
            return ['payment_id' => (int) $last['id'], 'cancel_sessions' => [], 'live_session' => [
                'public_order_id' => (string) $order['public_order_id'],
                'attempt_no' => (int) $last['attempt_no'],
                'redirect_url' => (string) $last['session_redirect_url'],
                'session_expires_at' => Db::toIso8601($last['session_expires_at']),
                'reused' => true,
            ]];
        }

        [, $graceMinutes] = $this->paymentWindow();
        if ((int) $order['seconds_to_due'] - $graceMinutes * 60 < self::MIN_SESSION_SECONDS) {
            throw DomainError::orderNotPayable($status);
        }
        // Сессия не создана (процесс упал между Tx1 и банком) или URL не сохранён — тот же ключ, банк
        // вернёт ту же сессию.
        if ($last !== null && ($last['status'] === 'created' || $lastAlive)) {
            return ['payment_id' => (int) $last['id'], 'live_session' => null, 'cancel_sessions' => []];
        }
        if (!$allowNewAttempt) {
            return ['payment_id' => null, 'live_session' => null, 'cancel_sessions' => []];
        }
        $attemptNo = $last === null ? 1 : (int) $last['attempt_no'] + 1;
        if ($attemptNo > self::MAX_PAYMENT_ATTEMPTS) {
            throw DomainError::orderNotPayable($status);
        }

        // Истекающие pending-сессии закрываем у себя; у банка — cancelSession после COMMIT.
        $cancel = [];
        foreach ($payments as $p) {
            if ($p['status'] !== 'pending') {
                continue;
            }
            $this->expectAffected($this->exec(
                "UPDATE %i SET status = 'expired' WHERE id = %d AND status = 'pending'",
                $t['payments'],
                (int) $p['id']
            ), 1, 'payment pending → expired');
            $this->audit->record('payment.status_changed', 'payment', (int) $p['id'], 'pending', 'expired', ['reason' => 'superseded_by_new_attempt'], 'user', $userId);
            if ($p['provider_payment_id'] !== null) {
                $cancel[] = $p;
            }
        }

        $this->exec(
            "INSERT INTO %i (order_id, provider, attempt_no, idempotency_key, status, amount, currency)
             VALUES (%d, %s, %d, %s, 'created', %d, %s)",
            $t['payments'],
            $orderId,
            $this->provider->name(),
            $attemptNo,
            wp_generate_uuid4(),
            (int) $order['total_amount'],
            (string) $order['currency']
        );
        $paymentId = $this->db->lastInsertId();
        $this->audit->record('payment.created', 'payment', $paymentId, null, 'created', ['order_id' => $orderId, 'attempt_no' => $attemptNo], 'user', $userId);

        return ['payment_id' => $paymentId, 'live_session' => null, 'cancel_sessions' => $cancel];
    }

    /**
     * createSession у банка ВНЕ транзакции, затем Tx2. Повторный вызов для того же платежа безопасен:
     * банк идемпотентен по idempotency_key, а Tx2 меняет статусы только из created.
     *
     * @return array{public_order_id: string, attempt_no: int, redirect_url: string, session_expires_at: ?string, reused: bool}
     */
    private function openSession(int $orderId, int $paymentId, int $userId): array
    {
        $t = $this->tables();
        [, $graceMinutes] = $this->paymentWindow();
        $p = $this->row(
            'SELECT p.id, p.attempt_no, p.idempotency_key, p.amount, p.currency, o.public_order_id,
                    TIMESTAMPDIFF(SECOND, UTC_TIMESTAMP(6), o.payment_due_at) AS seconds_to_due
               FROM %i p JOIN %i o ON o.id = p.order_id
              WHERE p.id = %d AND p.order_id = %d',
            $t['payments'],
            $t['orders'],
            $paymentId,
            $orderId
        );
        if ($p === null) {
            throw new \RuntimeException('payment not found for session');
        }
        $ttl = max(self::MIN_SESSION_SECONDS, (int) $p['seconds_to_due'] - $graceMinutes * 60);
        $publicOrderId = (string) $p['public_order_id'];

        $stage = 'receipt';
        try {
            // 54-ФЗ: позиции и контакт для чека; итог сверяется с суммой платежа до вызова банка. Ошибка чека
            // (фильтр uniundata_fiscal_receipt, расхождение суммы) — такой же отказ, как сбой createSession:
            // платёж created → cancelled, иначе заказ остался бы draft с «висящей» попыткой.
            $receipt = $this->receiptForOrder($orderId, (int) $p['amount']);
            $stage = 'createSession';
            $session = $this->provider->createSession(
                $publicOrderId,
                (string) $p['idempotency_key'],
                (int) $p['amount'],
                (string) $p['currency'],
                $ttl,
                rest_url('uniundata/v1/payment/webhook'),
                (string) apply_filters('uniundata_payment_return_url', home_url('/checkout/return/?order=' . rawurlencode($publicOrderId)), $publicOrderId),
                $receipt
            );
        } catch (\Throwable $e) {
            // В лог — только класс исключения: сообщение адаптера может содержать ответ банка.
            error_log(\sprintf('[uniundata] %s failed for payment #%d: %s', $stage, $paymentId, $e::class));
            $orderStatus = $this->db->transaction(fn (): string => $this->markSessionFailedTx($orderId, $paymentId, $userId));
            throw new DomainError(
                'uniundata_payment_provider_error',
                \__('The payment provider is unavailable. Please try again later.', 'uniundata-books'),
                502,
                ['public_order_id' => $publicOrderId, 'order_status' => $orderStatus]
            );
        }

        $closedStatus = $this->db->transaction(fn (): ?string => $this->markSessionOpenedTx($orderId, $paymentId, $session, $userId));
        if ($closedStatus !== null) {
            // Пока банк создавал сессию, заказ отменили/закрыли: сессию сразу закрываем у банка.
            $this->cancelSessionsBestEffort([[
                'id' => $paymentId, 'provider_payment_id' => $session->providerPaymentId, 'idempotency_key' => $p['idempotency_key'],
            ]]);
            throw DomainError::orderNotPayable($closedStatus);
        }

        return [
            'public_order_id' => $publicOrderId,
            'attempt_no' => (int) $p['attempt_no'],
            'redirect_url' => $session->redirectUrl,
            'session_expires_at' => Db::toIso8601($session->expiresAtSql()),
            'reused' => false,
        ];
    }

    /**
     * Tx2 (успех): orders → payments. created → pending + данные сессии; заказ draft/payment_failed →
     * pending_payment, если это последняя попытка.
     *
     * @return ?string null — сессия действует; иначе статус заказа, для которого платёж уже закрыт.
     */
    private function markSessionOpenedTx(int $orderId, int $paymentId, PaymentSession $s, int $userId): ?string
    {
        $t = $this->tables();
        $order = $this->row('SELECT id, status FROM %i WHERE id = %d FOR UPDATE', $t['orders'], $orderId);
        $payment = $this->row('SELECT id, status, attempt_no FROM %i WHERE id = %d FOR UPDATE', $t['payments'], $paymentId);
        if ($order === null || $payment === null) {
            throw new \RuntimeException('order/payment vanished');
        }
        $status = (string) $payment['status'];

        if ($status === 'created') {
            $this->expectAffected($this->exec(
                "UPDATE %i
                    SET provider_payment_id = %s, session_expires_at = %s, session_redirect_url = %s,
                        provider_status = %s, status = 'pending'
                  WHERE id = %d AND status = 'created'",
                $t['payments'],
                $s->providerPaymentId,
                $s->expiresAtSql(),
                $s->redirectUrl,
                $s->providerStatus,
                $paymentId
            ), 1, 'payment created → pending');
            $this->audit->record('payment.status_changed', 'payment', $paymentId, 'created', 'pending', ['order_id' => $orderId], 'user', $userId);
            $status = 'pending';
        } else {
            // Webhook успел раньше или это повтор с тем же ключом: только дописываем недостающее.
            $this->exec(
                'UPDATE %i SET provider_payment_id = COALESCE(provider_payment_id, %s),
                               session_expires_at = COALESCE(session_expires_at, %s),
                               session_redirect_url = COALESCE(session_redirect_url, %s)
                  WHERE id = %d',
                $t['payments'],
                $s->providerPaymentId,
                $s->expiresAtSql(),
                $s->redirectUrl,
                $paymentId
            );
        }

        if (\in_array($status, ['failed', 'cancelled', 'expired'], true)) {
            return (string) $order['status'];
        }
        $latest = (int) $this->scalar('SELECT MAX(attempt_no) FROM %i WHERE order_id = %d', $t['payments'], $orderId);
        if ($status === 'pending' && (int) $payment['attempt_no'] === $latest
            && \in_array($order['status'], ['draft', 'payment_failed'], true)) {
            $this->expectAffected($this->exec(
                "UPDATE %i SET status = 'pending_payment' WHERE id = %d AND status IN ('draft', 'payment_failed')",
                $t['orders'],
                $orderId
            ), 1, 'order → pending_payment');
            $this->audit->record('order.status_changed', 'order', $orderId, (string) $order['status'], 'pending_payment', ['payment_id' => $paymentId], 'user', $userId);
        }

        return null;
    }

    /**
     * Tx2 (ошибка банка): payment created → cancelled (failure_code session_create_failed; created → failed
     * контракт не допускает — failed означает ответ банка по открытой сессии); заказ draft/pending_payment →
     * payment_failed, если это последняя попытка. Экземпляры остаются за заказом до payment_due_at:
     * покупатель может повторить /pay.
     */
    private function markSessionFailedTx(int $orderId, int $paymentId, int $userId): string
    {
        $t = $this->tables();
        $order = $this->row('SELECT id, status FROM %i WHERE id = %d FOR UPDATE', $t['orders'], $orderId);
        $payment = $this->row('SELECT id, status, attempt_no FROM %i WHERE id = %d FOR UPDATE', $t['payments'], $paymentId);
        if ($order === null || $payment === null) {
            throw new \RuntimeException('order/payment vanished');
        }
        if ($payment['status'] !== 'created') {
            return (string) $order['status']; // сессия уже существует у банка (повтор) — статусы не трогаем
        }
        $this->expectAffected($this->exec(
            "UPDATE %i SET failure_code = 'session_create_failed', status = 'cancelled' WHERE id = %d AND status = 'created'",
            $t['payments'],
            $paymentId
        ), 1, 'payment created → cancelled');
        $this->audit->record('payment.status_changed', 'payment', $paymentId, 'created', 'cancelled', ['reason' => 'session_create_failed'], 'user', $userId);

        $latest = (int) $this->scalar('SELECT MAX(attempt_no) FROM %i WHERE order_id = %d', $t['payments'], $orderId);
        $from = (string) $order['status'];
        if ((int) $payment['attempt_no'] === $latest && \in_array($from, ['draft', 'pending_payment'], true)) {
            $this->expectAffected($this->exec(
                "UPDATE %i SET status = 'payment_failed' WHERE id = %d AND status = %s",
                $t['orders'],
                $orderId,
                $from
            ), 1, 'order → payment_failed');
            $this->audit->record('order.status_changed', 'order', $orderId, $from, 'payment_failed', ['payment_id' => $paymentId], 'user', $userId);

            return 'payment_failed';
        }

        return $from;
    }

    // =================================================================================================
    // POST /orders/{public_order_id}/cancel
    // =================================================================================================

    /**
     * Отмена покупателем: только draft / pending_payment / payment_failed и без денег в пути. Экземпляры →
     * release target. Повтор отмены — 200 без изменений; иначе 409 uniundata_order_not_cancellable.
     *
     * @return array{order: array<string, mixed>, changed: bool}
     */
    public function cancel(int $userId, string $publicOrderId): array
    {
        $orderId = $this->findOwnOrderId($userId, $publicOrderId);
        $res = $this->db->transaction(fn (): array => $this->cancelTx($userId, $orderId));
        // После COMMIT: закрыть сессии у банка. Если банк уже списал деньги — придёт webhook → поздний платёж.
        $this->cancelSessionsBestEffort($res['cancel_sessions']);

        return ['order' => $this->orderView($orderId), 'changed' => $res['changed']];
    }

    /** @return array{changed: bool, cancel_sessions: list<array<string, mixed>>} */
    private function cancelTx(int $userId, int $orderId): array
    {
        $t = $this->tables();
        $itemIds = $this->orderItemIds($orderId);
        $items = $itemIds === [] ? [] : $this->rowsById(
            'SELECT id, availability_status, source_status FROM %i WHERE id IN (' . $this->in($itemIds) . ') ORDER BY id FOR UPDATE',
            $t['items'],
            ...$itemIds
        );
        $order = $this->row('SELECT id, user_id, status FROM %i WHERE id = %d FOR UPDATE', $t['orders'], $orderId);
        if ($order === null || (int) $order['user_id'] !== $userId) {
            throw DomainError::orderNotFound();
        }
        $status = (string) $order['status'];
        if ($status === 'cancelled') {
            return ['changed' => false, 'cancel_sessions' => []];
        }
        $payments = $this->rows(
            'SELECT id, status, provider_payment_id, idempotency_key FROM %i WHERE order_id = %d ORDER BY attempt_no FOR UPDATE',
            $t['payments'],
            $orderId
        );
        $moneyInFlight = array_filter($payments, static fn (array $p): bool => \in_array($p['status'], self::MONEY_IN_FLIGHT, true));
        if (!\in_array($status, self::PAYABLE_ORDER_STATUSES, true) || $moneyInFlight !== []) {
            throw DomainError::orderNotCancellable($status);
        }

        $this->expectAffected($this->exec(
            "UPDATE %i SET cancelled_at = UTC_TIMESTAMP(6), cancel_reason = 'customer_cancelled', status = 'cancelled'
              WHERE id = %d AND status IN ('draft', 'pending_payment', 'payment_failed')",
            $t['orders'],
            $orderId
        ), 1, 'order → cancelled');
        $this->audit->record('order.status_changed', 'order', $orderId, $status, 'cancelled', ['reason' => 'customer_cancelled'], 'user', $userId);

        $cancel = [];
        foreach ($payments as $p) {
            // created|pending → cancelled (контракт v2); failed/expired/cancelled уже закрыты.
            if (!\in_array($p['status'], ['created', 'pending'], true)) {
                continue;
            }
            $this->expectAffected($this->exec(
                "UPDATE %i SET failure_code = 'order_cancelled', status = 'cancelled' WHERE id = %d AND status = %s",
                $t['payments'],
                (int) $p['id'],
                (string) $p['status']
            ), 1, 'payment → cancelled');
            $this->audit->record('payment.status_changed', 'payment', (int) $p['id'], (string) $p['status'], 'cancelled', ['reason' => 'order_cancelled'], 'user', $userId);
            if ($p['provider_payment_id'] !== null) {
                $cancel[] = $p;
            }
        }

        $this->releaseOrderItems($orderId, $items, 'order_cancelled', $userId);

        return ['changed' => true, 'cancel_sessions' => $cancel];
    }

    /**
     * checkout_pending → release target. Экземпляры принадлежат заказу (список из его order_items,
     * заказ заблокирован и был открыт ⇒ ни в каком другом открытом заказе их нет).
     *
     * @param array<int, array<string, mixed>> $items Заблокированные строки экземпляров.
     */
    private function releaseOrderItems(int $orderId, array $items, string $reason, int $userId): void
    {
        $pending = array_keys(array_filter($items, static fn (array $i): bool => $i['availability_status'] === 'checkout_pending'));
        if ($pending === []) {
            return;
        }
        $this->expectAffected($this->exec(
            "UPDATE %i
                SET status_changed_at = UTC_TIMESTAMP(6),
                    availability_status = CASE source_status
                        WHEN 'present' THEN 'available'
                        WHEN 'missing' THEN 'sync_missing'
                        WHEN 'withdrawn' THEN 'withdrawn' END
              WHERE id IN (" . $this->in($pending) . ") AND availability_status = 'checkout_pending'",
            $this->db->table('book_items'),
            ...$pending
        ), \count($pending), 'items → release target');
        foreach ($pending as $id) {
            $this->audit->record('item.status_changed', 'item', $id, 'checkout_pending', $this->releaseTarget((string) $items[$id]['source_status']), ['order_id' => $orderId, 'reason' => $reason], 'user', $userId);
        }
    }

    /** @param list<array<string, mixed>> $payments */
    private function cancelSessionsBestEffort(array $payments): void
    {
        foreach ($payments as $p) {
            try {
                $this->provider->cancelSession((string) $p['provider_payment_id'], (string) $p['idempotency_key']);
            } catch (\Throwable $e) {
                // Best effort: сессия всё равно истечёт по TTL; поздняя оплата пойдёт веткой позднего платежа.
                error_log(\sprintf('[uniundata] cancelSession failed for payment #%d: %s', (int) $p['id'], $e::class));
            }
        }
    }

    // =================================================================================================
    // Снимки, настройки, представление
    // =================================================================================================

    /**
     * Снимок покупателя для заказа: значения из customer{} запроса, иначе из профиля (usermeta
     * first_name/last_name/middle_name, wp_book_customer_profiles.phone_e164). Отчество необязательно.
     *
     * @return array{email: string, first_name: string, last_name: string, middle_name: ?string, phone: ?string}
     */
    private function customerSnapshot(int $userId, CheckoutRequest $req): array
    {
        $user = get_userdata($userId);
        if ($user === false) {
            throw DomainError::authRequired();
        }
        $email = (string) $user->user_email;
        if (!is_email($email) || \strlen($email) > 254) {
            throw DomainError::invalidParam('email', \__('Please provide a valid e-mail in your profile.', 'uniundata-books'));
        }
        $first = $req->firstName ?? $this->profileName($userId, 'first_name');
        $last = $req->lastName ?? $this->profileName($userId, 'last_name');
        if ($first === null) {
            throw DomainError::invalidParam('customer.first_name', \__('Please enter your first name.', 'uniundata-books'));
        }
        if ($last === null) {
            throw DomainError::invalidParam('customer.last_name', \__('Please enter your last name.', 'uniundata-books'));
        }
        $phone = $req->phone ?? $this->scalar(
            'SELECT phone_e164 FROM %i WHERE user_id = %d',
            $this->db->table('book_customer_profiles'),
            $userId
        );

        return [
            'email' => $email,
            'first_name' => $first,
            'last_name' => $last,
            'middle_name' => $req->middleName ?? $this->profileName($userId, 'middle_name'),
            'phone' => $phone,
        ];
    }

    /** Имя из usermeta; значение, не проходящее проверку формата, считается незаполненным. */
    private function profileName(int $userId, string $metaKey): ?string
    {
        $value = CheckoutRequest::normalizeName((string) get_user_meta($userId, $metaKey, true));

        return $value !== null && CheckoutRequest::isValidName($value) ? $value : null;
    }

    /**
     * Текущие редакции оферты и политики из option uniundata_terms_versions:
     * ['offer' => ['version' => '2026-09', 'sha256' => '<64 hex>'], 'privacy' => [...]].
     * Покупатель принял устаревшую редакцию → 409 uniundata_terms_outdated (data.current_versions):
     * клиент показывает новую редакцию и запрашивает согласие заново.
     *
     * @return array<string, array{version: string, sha256: string}>
     */
    private function termsSnapshot(CheckoutRequest $req): array
    {
        $terms = get_option('uniundata_terms_versions', []);
        $out = [];
        $current = [];
        $outdated = false;
        foreach (['offer' => $req->acceptOfferVersion, 'privacy' => $req->acceptPrivacyVersion] as $type => $accepted) {
            $cur = \is_array($terms) ? ($terms[$type] ?? null) : null;
            if (!\is_array($cur) || !isset($cur['version'], $cur['sha256']) || !preg_match('/^[0-9a-f]{64}$/', (string) $cur['sha256'])) {
                error_log('[uniundata] option uniundata_terms_versions is not configured');
                throw DomainError::internal();
            }
            $current[$type] = (string) $cur['version'];
            $outdated = $outdated || $accepted !== $current[$type];
            $out[$type] = ['version' => $current[$type], 'sha256' => (string) $cur['sha256']];
        }
        if ($outdated) {
            throw DomainError::termsOutdated($current);
        }

        return $out;
    }

    /** Валюта магазина (option uniundata_currency, ISO 4217). Не настроена — ошибка конфигурации. */
    private function shopCurrency(): string
    {
        $currency = get_option('uniundata_currency');
        if (!\is_string($currency) || preg_match('/^[A-Z]{3}$/', $currency) !== 1) {
            error_log('[uniundata] option uniundata_currency is not configured');
            throw DomainError::internal();
        }

        return $currency;
    }

    /** @return array{0: int, 1: int} [payment_ttl_minutes, payment_grace_minutes] */
    private function paymentWindow(): array
    {
        $ttl = (int) get_option('uniundata_payment_ttl_minutes', 30);
        $grace = (int) get_option('uniundata_payment_grace_minutes', 10);

        return [max(5, min(180, $ttl)), max(0, min(60, $grace))];
    }

    /** Чек «приход»: книги заказа по снимкам (название, цена, количество 1) + доставка, контакт покупателя. */
    private function receiptForOrder(int $orderId, int $paymentAmount): FiscalReceipt
    {
        $t = $this->tables();
        $order = $this->row(
            'SELECT customer_email, customer_phone, shipping_amount FROM %i WHERE id = %d',
            $t['orders'],
            $orderId
        );
        if ($order === null) {
            throw new \RuntimeException('order not found for receipt');
        }
        $lines = array_map(static fn (array $r): array => [
            'title' => (string) $r['title_snapshot'],
            'amount' => (int) $r['unit_price_amount'],
            'book_item_id' => (int) $r['book_item_id'],
        ], $this->rows(
            'SELECT book_item_id, title_snapshot, unit_price_amount FROM %i WHERE order_id = %d ORDER BY id',
            $t['order_items'],
            $orderId
        ));
        if ((int) $order['shipping_amount'] > 0) {
            $lines[] = ['title' => \__('Delivery', 'uniundata-books'), 'amount' => (int) $order['shipping_amount'], 'book_item_id' => null, 'subject' => 'service'];
        }

        return FiscalReceipt::fromLines($lines, (string) $order['customer_email'], $order['customer_phone'], $orderId, $paymentAmount, 'payment');
    }

    /** @return ?array<string, mixed> */
    private function findOrderByCheckoutKey(int $userId, string $key): ?array
    {
        return $this->row(
            'SELECT id, public_order_id, status, total_amount, currency FROM %i WHERE user_id = %d AND checkout_request_id = %s',
            $this->db->table('book_orders'),
            $userId,
            $key
        );
    }

    /** Чужой и несуществующий заказ неразличимы: 404 в обоих случаях. */
    private function findOwnOrderId(int $userId, string $publicOrderId): int
    {
        if (!preg_match('/^uniundata_[0-9a-f-]{36}$/', $publicOrderId)) {
            throw DomainError::orderNotFound();
        }
        $row = $this->row(
            'SELECT id, user_id FROM %i WHERE public_order_id = %s',
            $this->db->table('book_orders'),
            $publicOrderId
        );
        if ($row === null || (int) $row['user_id'] !== $userId) {
            throw DomainError::orderNotFound();
        }

        return (int) $row['id'];
    }

    /** @return list<int> */
    private function orderItemIds(int $orderId): array
    {
        return $this->ids($this->col(
            'SELECT book_item_id FROM %i WHERE order_id = %d ORDER BY book_item_id',
            $this->db->table('book_order_items'),
            $orderId
        ));
    }

    /** @return array<string, mixed> Представление заказа для REST (без внутренних id и PII сверх нужного). */
    private function orderView(int $orderId): array
    {
        $t = $this->tables();
        $o = $this->row(
            'SELECT public_order_id, status, currency, subtotal_amount, discount_amount, shipping_amount, tax_amount,
                    total_amount, refunded_amount, placed_at, payment_due_at, paid_at, cancelled_at
               FROM %i WHERE id = %d',
            $t['orders'],
            $orderId
        );
        if ($o === null) {
            throw new \RuntimeException('order not found');
        }
        $items = $this->rows(
            'SELECT book_item_id, title_snapshot, author_snapshot, isbn_snapshot, cover_url_snapshot, unit_price_amount, currency
               FROM %i WHERE order_id = %d ORDER BY id',
            $t['order_items'],
            $orderId
        );

        return [
            'public_order_id' => (string) $o['public_order_id'],
            'status' => (string) $o['status'],
            'currency' => (string) $o['currency'],
            'subtotal_amount' => (int) $o['subtotal_amount'],
            'discount_amount' => (int) $o['discount_amount'],
            'shipping_amount' => (int) $o['shipping_amount'],
            'tax_amount' => (int) $o['tax_amount'],
            'total_amount' => (int) $o['total_amount'],
            'refunded_amount' => (int) $o['refunded_amount'],
            'placed_at' => Db::toIso8601($o['placed_at']),
            'payment_due_at' => Db::toIso8601($o['payment_due_at']),
            'paid_at' => Db::toIso8601($o['paid_at']),
            'cancelled_at' => Db::toIso8601($o['cancelled_at']),
            'items' => array_map(static fn (array $i): array => [
                'book_item_id' => (int) $i['book_item_id'],
                'title' => (string) $i['title_snapshot'],
                'author' => $i['author_snapshot'],
                'isbn' => $i['isbn_snapshot'],
                'cover_url' => $i['cover_url_snapshot'],
                'unit_price_amount' => (int) $i['unit_price_amount'],
                'currency' => (string) $i['currency'],
            ], $items),
        ];
    }

    private function releaseTarget(string $sourceStatus): string
    {
        return ItemStatus::releaseTarget($sourceStatus)->value;
    }

    // =================================================================================================
    // SQL-хелперы поверх Db: prepare(), ошибка MySQL → \mysqli_sql_exception (Db::transaction() откатит и повторит 1213/1205)
    // =================================================================================================

    /** @return array<string, string> */
    private function tables(): array
    {
        return [
            'carts' => $this->db->table('book_carts'),
            'items' => $this->db->table('book_items'),
            'records' => $this->db->table('book_records'),
            'reservations' => $this->db->table('book_reservations'),
            'cart_items' => $this->db->table('book_cart_items'),
            'orders' => $this->db->table('book_orders'),
            'order_items' => $this->db->table('book_order_items'),
            'payments' => $this->db->table('book_payments'),
        ];
    }

    /** @return ?array<string, mixed> */
    private function row(string $sql, int|string|float ...$args): ?array
    {
        return $this->db->getRow($sql, ...$args);
    }

    /** @return list<array<string, mixed>> */
    private function rows(string $sql, int|string|float ...$args): array
    {
        return $this->db->getResults($sql, ...$args);
    }

    /** @return array<int, array<string, mixed>> */
    private function rowsById(string $sql, int|string|float ...$args): array
    {
        $out = [];
        foreach ($this->db->getResults($sql, ...$args) as $r) {
            $out[(int) $r['id']] = $r;
        }

        return $out;
    }

    /** @return list<string> Первая колонка результата. */
    private function col(string $sql, int|string|float ...$args): array
    {
        return array_map(static fn (array $r): string => (string) array_values($r)[0], $this->db->getResults($sql, ...$args));
    }

    private function scalar(string $sql, int|string|float ...$args): ?string
    {
        return $this->db->getVar($sql, ...$args);
    }

    /** INSERT/UPDATE: число изменённых строк (UPDATE теми же значениями даёт 0). Ошибка MySQL → исключение. */
    private function exec(string $sql, int|string|float ...$args): int
    {
        return $this->db->execute($sql, ...$args);
    }

    /** Переход под блокировкой обязан затронуть ровно ожидаемое число строк; иначе — ошибка логики, откат. */
    private function expectAffected(int $actual, int $expected, string $what): void
    {
        if ($actual !== $expected) {
            throw new \RuntimeException(\sprintf('Unexpected affected rows for %s: %d instead of %d', $what, $actual, $expected));
        }
    }

    /** @param array<mixed> $values @return list<int> */
    private function ids(array $values): array
    {
        $ids = array_values(array_unique(array_map('intval', $values)));
        sort($ids);

        return $ids;
    }

    /** @param list<int> $ids */
    private function in(array $ids): string
    {
        return implode(',', array_fill(0, \count($ids), '%d'));
    }
}
