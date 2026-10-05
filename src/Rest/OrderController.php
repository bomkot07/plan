<?php

declare(strict_types=1);

namespace Uniundata\Books\Rest;

use Uniundata\Books\Domain\DomainError;
use Uniundata\Books\Infrastructure\AuditLog;
use Uniundata\Books\Infrastructure\Db;
use Uniundata\Books\Service\CheckoutService;

/**
 * Заказы покупателя.
 *
 *   GET  /orders                      — свои заказы (view_own_book_orders), постранично;
 *   GET  /orders/{public_order_id}    — заказ: владелец или менеджер (мета-capability view_book_order);
 *   POST /orders/{public_order_id}/pay    — новая/восстановленная платёжная попытка (строго владелец);
 *   POST /orders/{public_order_id}/cancel — отмена до оплаты (строго владелец).
 *
 * Несуществующий, чужой и синтаксически неверный public_order_id неразличимы: всегда 404.
 * Наружу выходит только public_order_id (uniundata_<UUID v4>), внутренний последовательный id — нет.
 * Чтение — без блокировок: статус оплаты меняет только webhook/опрос банка в PaymentService.
 */
final class OrderController extends RestController
{
    /** Строгий формат из ck_orders_public_id. Неподходящий путь → 404 rest_no_route (тоже 404). */
    public const PUBLIC_ID_REGEX = 'uniundata_[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}';

    private const ORDER_STATUSES = [
        'draft', 'pending_payment', 'payment_processing', 'paid', 'payment_failed', 'payment_expired',
        'cancelled', 'refunded', 'partially_refunded', 'fulfilled', 'completed',
    ];
    private const PAYABLE = ['draft', 'pending_payment', 'payment_failed'];
    /** Сессию короче этого не открываем (как CheckoutService::MIN_SESSION_SECONDS). */
    private const MIN_SESSION_SECONDS = 120;

    public function __construct(
        AuditLog $audit,
        private readonly CheckoutService $checkout,
        private readonly Db $db,
    ) {
        parent::__construct($audit);
    }

    public function register_routes(): void
    {
        $this->addCommonHooks();

        $idArg = [
            'public_order_id' => [
                'description' => 'Публичный номер заказа: uniundata_<UUID v4>.',
                'type' => 'string',
                'pattern' => '^' . self::PUBLIC_ID_REGEX . '$',
                'required' => true,
                'validate_callback' => 'rest_validate_request_arg',
                'sanitize_callback' => 'rest_sanitize_request_arg',
            ],
        ];
        $base = '/orders/(?P<public_order_id>' . self::PUBLIC_ID_REGEX . ')';

        register_rest_route(self::NAMESPACE, '/orders', [
            'methods' => \WP_REST_Server::READABLE,
            'callback' => [$this, 'listOwn'],
            'permission_callback' => $this->requireCapability('view_own_book_orders'),
            'args' => self::paginationArgs(50) + [
                'status' => [
                    'description' => 'Фильтр по статусу заказа.',
                    'type' => 'string',
                    'enum' => self::ORDER_STATUSES,
                    'required' => false,
                    'validate_callback' => 'rest_validate_request_arg',
                    'sanitize_callback' => 'sanitize_key',
                ],
            ],
        ]);

        register_rest_route(self::NAMESPACE, $base, [
            'methods' => \WP_REST_Server::READABLE,
            'callback' => [$this, 'getOrder'],
            // Владельца нельзя проверить до загрузки заказа: здесь только вход, объектное право — в callback
            // через current_user_can('view_book_order', $orderId) (map_meta_cap: владелец / manage_book_orders).
            'permission_callback' => [$this, 'requireLogin'],
            'args' => $idArg,
        ]);

        register_rest_route(self::NAMESPACE, $base . '/pay', [
            'methods' => \WP_REST_Server::CREATABLE,
            'callback' => [$this, 'pay'],
            // Строгое владение проверяет CheckoutService (чужой → 404): менеджер не платит за покупателя.
            'permission_callback' => $this->requireCapability('create_book_orders'),
            'args' => $idArg,
        ]);

        register_rest_route(self::NAMESPACE, $base . '/cancel', [
            'methods' => \WP_REST_Server::CREATABLE,
            'callback' => [$this, 'cancel'],
            'permission_callback' => $this->requireCapability('create_book_orders'),
            'args' => $idArg,
        ]);
    }

    // =============================================================================================
    // Callbacks
    // =============================================================================================

    public function listOwn(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        return $this->respond($request, function () use ($request): \WP_REST_Response {
            $userId = $this->currentUserId();
            $page = (int) $request->get_param('page');
            $perPage = (int) $request->get_param('per_page');
            $status = $request->get_param('status');
            $status = \is_string($status) && \in_array($status, self::ORDER_STATUSES, true) ? $status : null;

            $orders = $this->db->table('book_orders');
            $items = $this->db->table('book_order_items');
            $where = 'user_id = %d' . ($status !== null ? ' AND status = %s' : '');
            $args = $status !== null ? [$userId, $status] : [$userId];

            // ix_orders_user (user_id, created_at): выборка и сортировка по индексу.
            $total = (int) $this->db->getVar("SELECT COUNT(*) FROM {$orders} WHERE {$where}", ...$args);
            $rows = $total === 0 ? [] : $this->db->getResults(
                "SELECT o.public_order_id, o.status, o.currency, o.total_amount, o.placed_at, o.payment_due_at,
                        o.paid_at, o.cancelled_at, o.created_at,
                        (SELECT COUNT(*) FROM {$items} oi WHERE oi.order_id = o.id) AS items_count
                   FROM {$orders} o
                  WHERE {$where}
                  ORDER BY o.created_at DESC, o.id DESC
                  LIMIT %d OFFSET %d",
                ...[...$args, $perPage, ($page - 1) * $perPage],
            );

            $list = array_map(static fn (array $o): array => [
                'public_order_id' => (string) $o['public_order_id'],
                'status' => (string) $o['status'],
                'currency' => (string) $o['currency'],
                'total_amount' => (int) $o['total_amount'],
                'items_count' => (int) $o['items_count'],
                'placed_at' => Db::toIso8601($o['placed_at']),
                'payment_due_at' => Db::toIso8601($o['payment_due_at']),
                'paid_at' => Db::toIso8601($o['paid_at']),
                'cancelled_at' => Db::toIso8601($o['cancelled_at']),
                'created_at' => Db::toIso8601($o['created_at']),
            ], $rows);

            return self::withPagination(new \WP_REST_Response([
                'orders' => $list,
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
            ], 200), $total, $perPage);
        });
    }

    public function getOrder(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        return $this->respond($request, function () use ($request): array {
            $order = $this->db->getRow(
                "SELECT id, public_order_id, user_id, status, currency, subtotal_amount, discount_amount, shipping_amount,
                        tax_amount, total_amount, refunded_amount, prices_include_tax,
                        customer_email, customer_phone, customer_first_name, customer_last_name, customer_middle_name,
                        billing_address_json, shipping_address_json, shipping_method,
                        placed_at, payment_due_at, paid_at, cancelled_at, cancel_reason, fulfilled_at, completed_at,
                        needs_attention, attention_reason, created_at,
                        TIMESTAMPDIFF(SECOND, UTC_TIMESTAMP(6), payment_due_at) AS seconds_to_due,
                        UTC_TIMESTAMP(6) AS server_now
                   FROM {$this->db->table('book_orders')}
                  WHERE public_order_id = %s",
                (string) $request->get_param('public_order_id'),
            );
            // Одинаковый 404 для «нет такого» и «чужой»: ответ не раскрывает существование заказа.
            if ($order === null || !current_user_can('view_book_order', (int) $order['id'])) {
                throw DomainError::orderNotFound();
            }

            return $this->present($order, current_user_can('manage_book_orders'));
        });
    }

    public function pay(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        return $this->respond($request, function () use ($request): array {
            $this->enforceRateLimit('pay');

            // {created, replayed, order, payment: {public_order_id, attempt_no, redirect_url, session_expires_at}}
            return $this->checkout->pay($this->currentUserId(), (string) $request->get_param('public_order_id'));
        });
    }

    public function cancel(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        return $this->respond($request, function () use ($request): array {
            $this->enforceRateLimit('cancel');

            // {order, changed}; повторная отмена — 200 с changed=false.
            return $this->checkout->cancel($this->currentUserId(), (string) $request->get_param('public_order_id'));
        });
    }

    // =============================================================================================
    // Представление
    // =============================================================================================

    /**
     * @param array<string, string|null> $o
     * @return array<string, mixed>
     */
    private function present(array $o, bool $asManager): array
    {
        $orderId = (int) $o['id'];
        $items = $this->db->getResults(
            "SELECT book_item_id, title_snapshot, subtitle_snapshot, author_snapshot, isbn_snapshot, publisher_snapshot,
                    publication_year_snapshot, condition_snapshot, cover_url_snapshot, unit_price_amount, currency
               FROM {$this->db->table('book_order_items')}
              WHERE order_id = %d
              ORDER BY id",
            $orderId,
        );
        $payments = $this->db->getResults(
            "SELECT attempt_no, provider, status, provider_status, amount, currency, card_brand, card_last4,
                    failure_code, session_expires_at, succeeded_at, created_at
               FROM {$this->db->table('book_payments')}
              WHERE order_id = %d
              ORDER BY attempt_no DESC",
            $orderId,
        );

        $status = (string) $o['status'];
        $moneyInFlight = false;
        foreach ($payments as $p) {
            $moneyInFlight = $moneyInFlight || \in_array($p['status'], ['processing', 'succeeded', 'refunded', 'partially_refunded'], true);
        }
        $graceSeconds = 60 * max(0, (int) get_option('uniundata_payment_grace_minutes', 10));
        $payableNow = \in_array($status, self::PAYABLE, true) && !$moneyInFlight;

        $view = [
            'public_order_id' => (string) $o['public_order_id'],
            'status' => $status,
            'currency' => (string) $o['currency'],
            'subtotal_amount' => (int) $o['subtotal_amount'],
            'discount_amount' => (int) $o['discount_amount'],
            'shipping_amount' => (int) $o['shipping_amount'],
            'tax_amount' => (int) $o['tax_amount'],
            'total_amount' => (int) $o['total_amount'],
            'refunded_amount' => (int) $o['refunded_amount'],
            'prices_include_tax' => $o['prices_include_tax'] === '1',
            'placed_at' => Db::toIso8601($o['placed_at']),
            'payment_due_at' => Db::toIso8601($o['payment_due_at']),
            'paid_at' => Db::toIso8601($o['paid_at']),
            'cancelled_at' => Db::toIso8601($o['cancelled_at']),
            'cancel_reason' => $o['cancel_reason'],
            'fulfilled_at' => Db::toIso8601($o['fulfilled_at']),
            'completed_at' => Db::toIso8601($o['completed_at']),
            'created_at' => Db::toIso8601($o['created_at']),
            'server_time' => Db::toIso8601($o['server_now']),
            // Подсказки для UI; окончательное решение — под блокировками в CheckoutService.
            'actions' => [
                'can_pay' => $payableNow && $o['seconds_to_due'] !== null
                    && (int) $o['seconds_to_due'] - $graceSeconds >= self::MIN_SESSION_SECONDS,
                'can_cancel' => $payableNow,
            ],
            // Снимок данных покупателя: показывается владельцу (это его данные) и менеджеру.
            'customer' => [
                'email' => (string) $o['customer_email'],
                'phone' => $o['customer_phone'],
                'first_name' => (string) $o['customer_first_name'],
                'last_name' => (string) $o['customer_last_name'],
                'middle_name' => $o['customer_middle_name'],
            ],
            'shipping_address' => self::decodeJson($o['shipping_address_json']),
            'billing_address' => self::decodeJson($o['billing_address_json']),
            'shipping_method' => $o['shipping_method'],
            'items' => array_map(static fn (array $i): array => [
                'book_item_id' => (int) $i['book_item_id'],
                'title' => (string) $i['title_snapshot'],
                'subtitle' => $i['subtitle_snapshot'],
                'author' => $i['author_snapshot'],
                'isbn' => $i['isbn_snapshot'],
                'publisher' => $i['publisher_snapshot'],
                'publication_year' => $i['publication_year_snapshot'] !== null ? (int) $i['publication_year_snapshot'] : null,
                'condition_code' => $i['condition_snapshot'],
                'cover_url' => $i['cover_url_snapshot'],
                'unit_price_amount' => (int) $i['unit_price_amount'],
                'currency' => (string) $i['currency'],
            ], $items),
            // Покупателю — только последняя попытка и только безопасные поля (бренд + last4 по PCI DSS).
            'payment' => $payments === [] ? null : self::presentPayment($payments[0], false),
        ];

        if ($asManager) {
            $view['needs_attention'] = $o['needs_attention'] === '1';
            $view['attention_reason'] = $o['attention_reason'];
            $view['user_id'] = (int) $o['user_id'];
            $view['payments'] = array_map(static fn (array $p): array => self::presentPayment($p, true), $payments);
        }

        return $view;
    }

    /**
     * @param array<string, string|null> $p
     * @return array<string, mixed>
     */
    private static function presentPayment(array $p, bool $asManager): array
    {
        $out = [
            'attempt_no' => (int) $p['attempt_no'],
            'status' => (string) $p['status'],
            'amount' => (int) $p['amount'],
            'currency' => (string) $p['currency'],
            'card_brand' => $p['card_brand'],
            'card_last4' => $p['card_last4'],
            'session_expires_at' => Db::toIso8601($p['session_expires_at']),
            'succeeded_at' => Db::toIso8601($p['succeeded_at']),
        ];
        if ($asManager) {
            $out['provider'] = (string) $p['provider'];
            $out['provider_status'] = $p['provider_status'];
            $out['failure_code'] = $p['failure_code'];
            $out['created_at'] = Db::toIso8601($p['created_at']);
        }

        return $out;
    }

    /** @return ?array<string, mixed> */
    private static function decodeJson(?string $json): ?array
    {
        if ($json === null || $json === '') {
            return null;
        }
        $decoded = json_decode($json, true);

        return \is_array($decoded) ? $decoded : null;
    }
}
