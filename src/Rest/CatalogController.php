<?php

declare(strict_types=1);

namespace Uniundata\Books\Rest;

use Uniundata\Books\Domain\ItemStatus;
use Uniundata\Books\Infrastructure\AuditLog;
use Uniundata\Books\Infrastructure\Db;
use Uniundata\Books\Service\ReservationService;

/**
 * GET /catalog/availability — актуальные статусы кнопок «Отложить».
 *
 * Страницы каталога и карточки отдаются из page cache / CDN, поэтому HTML может показывать статус
 * минутной давности. Кнопка в HTML по умолчанию неактивна, а JS сразу после загрузки (и при возврате
 * на вкладку / из bfcache) спрашивает этот endpoint с `Cache-Control: no-store`.
 *
 * Endpoint только читает (без блокировок) и ни на что не влияет: решение о резерве принимает
 * POST /cart/reserve под SELECT … FOR UPDATE. Поэтому ответ — подсказка для UI, а не гарантия.
 */
final class CatalogController extends RestController
{
    public const MAX_ITEMS = 100;

    /** Открытые заказы: экземпляр удерживается в checkout_pending. */
    private const OPEN_ORDER_STATUSES = ['draft', 'pending_payment', 'payment_processing', 'payment_failed'];

    public function __construct(AuditLog $audit, private readonly Db $db)
    {
        parent::__construct($audit);
    }

    public function register_routes(): void
    {
        $this->addCommonHooks();

        register_rest_route(self::NAMESPACE, '/catalog/availability', [
            'methods' => \WP_REST_Server::READABLE,
            'callback' => [$this, 'availability'],
            // Осознанно публичный: каталог виден гостям (capabilities гостю назначить нельзя), ответ не содержит
            // PII. Пользовательские поля (held_by_me, attempts_left) появляются, только если WordPress
            // аутентифицировал запрос (cookie + X-WP-Nonce или Application Password).
            'permission_callback' => '__return_true',
            'args' => [
                'item_ids' => [
                    'description' => \sprintf('ID экземпляров (wp_book_items.id) через запятую или массивом, 1–%d.', self::MAX_ITEMS),
                    'type' => 'array',
                    'items' => ['type' => 'integer', 'minimum' => 1, 'maximum' => self::MAX_ID],
                    'minItems' => 1,
                    'maxItems' => self::MAX_ITEMS,
                    'required' => true,
                    'validate_callback' => 'rest_validate_request_arg',
                    'sanitize_callback' => [self::class, 'sanitizeIdList'],
                ],
            ],
        ]);
    }

    /**
     * "3,1,3" / ["3","1"] → [3, 1]: целые > 0 без дублей, порядок запроса сохраняется.
     *
     * @return list<int>
     */
    public static function sanitizeIdList(mixed $value): array
    {
        $ids = array_map('absint', wp_parse_list(\is_array($value) || \is_string($value) ? $value : ''));

        return array_values(array_unique(array_filter($ids, static fn (int $id): bool => $id > 0)));
    }

    public function availability(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        return $this->respond($request, function () use ($request): array {
            $this->enforceRateLimit('availability');

            /** @var list<int> $ids */
            $ids = (array) $request->get_param('item_ids');
            $userId = get_current_user_id();
            $canReserveCap = $userId > 0 && current_user_can('reserve_books');

            $items = $this->loadItems($ids);
            $mine = $userId > 0 ? $this->loadMyReservations($userId, $ids) : [];
            $pendingIds = array_keys(array_filter($items, static fn (array $i): bool => $i['availability_status'] === 'checkout_pending'));
            $myOrders = $userId > 0 && $pendingIds !== [] ? $this->loadMyOrderHolds($userId, $pendingIds) : [];
            // Сколько ещё книг пользователь может отложить одновременно (как ReservationService::reserve()).
            $activeLeft = $canReserveCap ? max(0, self::maxActiveReservations() - $this->countMyActiveReservations($userId)) : null;
            $ctx = ['user_id' => $userId, 'can_reserve_cap' => $canReserveCap, 'active_left' => $activeLeft, 'shop_currency' => self::shopCurrency()];

            $out = [];
            foreach ($ids as $id) {
                $out[] = $this->present($id, $items[$id] ?? null, $mine[$id] ?? null, $myOrders[$id] ?? null, $ctx);
            }

            return [
                'server_time' => Db::toIso8601($this->db->now()),
                'authenticated' => $userId > 0,
                'active_reservations_left' => $activeLeft,
                'items' => $out,
            ];
        });
    }

    /**
     * @param ?array<string, mixed> $item
     * @param ?array<string, mixed> $mine
     * @param array{user_id: int, can_reserve_cap: bool, active_left: ?int, shop_currency: ?string} $ctx
     * @return array<string, mixed>
     */
    private function present(int $id, ?array $item, ?array $mine, ?string $myOrder, array $ctx): array
    {
        $userId = $ctx['user_id'];
        $attemptsUsed = $mine !== null ? (int) $mine['attempts_used'] : 0;
        $row = [
            'book_item_id' => $id,
            'status' => 'not_found',
            'can_reserve' => false,
            'reason' => 'not_found',
            'price_amount' => null,
            'currency' => null,
            'held_by_me' => null,
            'expires_at' => null,
            'public_order_id' => null,
            'attempts_left' => $userId > 0 ? max(0, ReservationService::MAX_ATTEMPTS - $attemptsUsed) : null,
        ];
        if ($item === null) {
            return $row;
        }

        $row['price_amount'] = (int) $item['price_amount'];
        $row['currency'] = (string) $item['currency'];
        $status = (string) $item['availability_status'];

        // Публичный статус: внутренние reserved/checkout_pending не различаем, служебные — «недоступна».
        // Цена не в валюте магазина (option uniundata_currency) — такой экземпляр не резервируется (409 reason=currency).
        $row['status'] = match (true) {
            !$item['is_active'] => 'unavailable',
            $status === 'available' && $ctx['shop_currency'] !== null && $item['currency'] !== $ctx['shop_currency'] => 'unavailable',
            default => ItemStatus::from($status)->publicState(),
        };

        if ($status === 'reserved' && $mine !== null && $mine['active_expires_at'] !== null) {
            $row['held_by_me'] = 'cart';
            $row['expires_at'] = Db::toIso8601((string) $mine['active_expires_at']);
            $row['reason'] = 'in_your_cart';

            return $row;
        }
        if ($status === 'checkout_pending' && $myOrder !== null) {
            $row['held_by_me'] = 'order';
            $row['public_order_id'] = $myOrder;
            $row['reason'] = 'in_your_order';

            return $row;
        }

        if ($row['status'] !== 'available') {
            $row['reason'] = $row['status'];

            return $row;
        }
        if ($userId <= 0) {
            $row['reason'] = 'login_required';

            return $row;
        }
        if (!$ctx['can_reserve_cap']) {
            $row['reason'] = 'forbidden';

            return $row;
        }
        if ($attemptsUsed >= ReservationService::MAX_ATTEMPTS) {
            $row['reason'] = 'limit_reached';

            return $row;
        }
        if ($ctx['active_left'] === 0) {
            $row['reason'] = 'active_limit_reached';

            return $row;
        }

        $row['can_reserve'] = true;
        $row['reason'] = null;

        return $row;
    }

    /**
     * Поиск по PRIMARY KEY; запись (record) тоже должна быть активной, как в ReservationService::reserve().
     *
     * @param list<int> $ids
     * @return array<int, array<string, mixed>>
     */
    private function loadItems(array $ids): array
    {
        $rows = $this->db->getResults(
            "SELECT i.id, i.availability_status, i.price_amount, i.currency,
                    (i.is_active = 1 AND r.is_active = 1) AS is_active
               FROM {$this->db->table('book_items')} i
               JOIN {$this->db->table('book_records')} r ON r.id = i.book_record_id
              WHERE i.id IN ({$this->db->placeholders(\count($ids))})",
            ...$ids,
        );
        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row['id']] = [
                'availability_status' => (string) $row['availability_status'],
                'price_amount' => (int) $row['price_amount'],
                'currency' => (string) $row['currency'],
                'is_active' => $row['is_active'] === '1',
            ];
        }

        return $out;
    }

    /**
     * Использованные попытки и активный резерв текущего пользователя.
     * Индекс uq_reservations_attempt (user_id, book_item_id, attempt_no) — диапазон по префиксу.
     *
     * @param list<int> $ids
     * @return array<int, array{attempts_used: int, active_expires_at: ?string}>
     */
    private function loadMyReservations(int $userId, array $ids): array
    {
        $rows = $this->db->getResults(
            "SELECT book_item_id,
                    SUM(attempt_no IS NOT NULL) AS attempts_used,
                    MAX(IF(reservation_status = 'active', expires_at, NULL)) AS active_expires_at
               FROM {$this->db->table('book_reservations')}
              WHERE user_id = %d AND book_item_id IN ({$this->db->placeholders(\count($ids))})
              GROUP BY book_item_id",
            $userId,
            ...$ids,
        );
        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row['book_item_id']] = [
                'attempts_used' => (int) $row['attempts_used'],
                'active_expires_at' => $row['active_expires_at'],
            ];
        }

        return $out;
    }

    /**
     * Экземпляры в checkout_pending, которые держит открытый заказ текущего пользователя.
     *
     * @param list<int> $ids
     * @return array<int, string> book_item_id => public_order_id
     */
    private function loadMyOrderHolds(int $userId, array $ids): array
    {
        $rows = $this->db->getResults(
            "SELECT oi.book_item_id, o.public_order_id
               FROM {$this->db->table('book_order_items')} oi
               JOIN {$this->db->table('book_orders')} o ON o.id = oi.order_id
              WHERE o.user_id = %d
                AND o.status IN ({$this->db->placeholders(\count(self::OPEN_ORDER_STATUSES), '%s')})
                AND oi.book_item_id IN ({$this->db->placeholders(\count($ids))})",
            $userId,
            ...self::OPEN_ORDER_STATUSES,
            ...$ids,
        );
        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row['book_item_id']] = (string) $row['public_order_id'];
        }

        return $out;
    }

    /** Активные (не истёкшие) резервы пользователя — ix_reservations_user (user_id, reservation_status, expires_at). */
    private function countMyActiveReservations(int $userId): int
    {
        return (int) $this->db->getVar(
            "SELECT COUNT(*) FROM {$this->db->table('book_reservations')}
              WHERE user_id = %d AND reservation_status = 'active' AND expires_at > UTC_TIMESTAMP(6)",
            $userId,
        );
    }

    /** = ReservationService::maxActiveReservations(): мусор или значение < 1 в опции — значение по умолчанию. */
    private static function maxActiveReservations(): int
    {
        $value = (int) get_option('uniundata_max_active_reservations', ReservationService::DEFAULT_MAX_ACTIVE_RESERVATIONS);

        return $value >= 1 ? $value : ReservationService::DEFAULT_MAX_ACTIVE_RESERVATIONS;
    }

    /** Валюта магазина; не настроена — null (подсказка не проверяет валюту, reserve ответит ошибкой конфигурации). */
    private static function shopCurrency(): ?string
    {
        $currency = get_option('uniundata_currency');

        return \is_string($currency) && preg_match('/^[A-Z]{3}$/', $currency) === 1 ? $currency : null;
    }
}
