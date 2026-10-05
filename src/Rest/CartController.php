<?php

declare(strict_types=1);

namespace Uniundata\Books\Rest;

use Uniundata\Books\Infrastructure\AuditLog;
use Uniundata\Books\Service\ReservationService;

/**
 * Корзина и кнопка «Отложить».
 *
 *   GET  /cart              — открытая корзина (только чтение: срок резерва НЕ продлевается);
 *   POST /cart/reserve      — резерв экземпляра на 1 час (201 — создан, 200 — уже ваш активный резерв);
 *   POST /cart/remove-item  — удаление из корзины: резерв → cancelled, экземпляр снова доступен.
 *
 * Аутентификация: cookie + X-WP-Nonce (wp_rest) из браузера или Application Password. Без nonce
 * WordPress считает cookie-запрос анонимным → 401 uniundata_auth_required (data.reason = missing_nonce).
 * Вся бизнес-логика и проверки под блокировками — в ReservationService; контроллер только валидирует
 * вход, проверяет права, применяет rate limit и переводит результат в HTTP.
 */
final class CartController extends RestController
{
    public function __construct(AuditLog $audit, private readonly ReservationService $reservations)
    {
        parent::__construct($audit);
    }

    public function register_routes(): void
    {
        $this->addCommonHooks();

        register_rest_route(self::NAMESPACE, '/cart', [
            'methods' => \WP_REST_Server::READABLE,
            'callback' => [$this, 'getCart'],
            'permission_callback' => $this->requireCapability('reserve_books'),
            'args' => [],
        ]);

        register_rest_route(self::NAMESPACE, '/cart/reserve', [
            'methods' => \WP_REST_Server::CREATABLE,
            'callback' => [$this, 'reserve'],
            'permission_callback' => $this->requireCapability('reserve_books'),
            'args' => [
                'book_item_id' => self::idArg('ID экземпляра (wp_book_items.id).'),
            ],
        ]);

        register_rest_route(self::NAMESPACE, '/cart/remove-item', [
            'methods' => \WP_REST_Server::CREATABLE,
            'callback' => [$this, 'removeItem'],
            'permission_callback' => $this->requireCapability('reserve_books'),
            'args' => [
                'book_item_id' => self::idArg('ID экземпляра, который нужно убрать из корзины.'),
            ],
        ]);
    }

    public function getCart(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        return $this->respond($request, fn (): array => $this->reservations->getCart($this->currentUserId()));
    }

    public function reserve(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        return $this->respond($request, function () use ($request): \WP_REST_Response {
            $this->enforceRateLimit('reserve');

            $result = $this->reservations->reserve($this->currentUserId(), (int) $request->get_param('book_item_id'));

            // Повторное «Отложить» на свой активный резерв идемпотентно: 200 и тот же резерв, попытка не тратится.
            return new \WP_REST_Response([
                'created' => $result['created'],
                'reservation' => $result['reservation'],
                'cart' => $result['cart'],
            ], $result['created'] ? 201 : 200);
        });
    }

    public function removeItem(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        return $this->respond($request, function () use ($request): array {
            $this->enforceRateLimit('remove_item');

            // outcome: removed | expired (срок вышел до cron) | already_removed (повторный запрос).
            return $this->reservations->removeFromCart($this->currentUserId(), (int) $request->get_param('book_item_id'));
        });
    }
}
