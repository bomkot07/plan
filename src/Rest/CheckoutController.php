<?php

declare(strict_types=1);

namespace Uniundata\Books\Rest;

use Uniundata\Books\Domain\DomainError;
use Uniundata\Books\Infrastructure\AuditLog;
use Uniundata\Books\Service\CheckoutRequest;
use Uniundata\Books\Service\CheckoutService;

/**
 * POST /checkout — «Перейти к оплате».
 *
 * Тело — только JSON. Обязательный заголовок `Idempotency-Key` (UUID, генерирует клиент один раз на
 * нажатие кнопки и повторяет при ретраях) → orders.checkout_request_id, UNIQUE(user_id, checkout_request_id):
 * повтор того же ключа возвращает тот же заказ (200, replayed=true), а не создаёт второй.
 *
 * Данные покупателя: `customer` {first_name, last_name, middle_name?, phone?}; незаполненное берётся из
 * профиля (usermeta first_name/last_name/middle_name, wp_book_customer_profiles.phone_e164), e-mail — только
 * из профиля. Отчество не обязательно.
 *
 * Валидация в два слоя: args (тип, диапазон, белый список полей) отсекают мусор до permission/callback;
 * CheckoutRequest проверяет то же независимо от транспорта (CLI, тесты) и нормализует телефон в E.164.
 * Ответы:
 *   201 — заказ создан, payment.redirect_url — страница банка;
 *   200 — повтор Idempotency-Key, тот же заказ;
 *   409 uniundata_cart_empty / uniundata_cart_changed — клиент обрабатывает ОДИНАКОВО: перезагружает корзину
 *       (GET /cart), показывает её и подтверждает заново с НОВЫМ Idempotency-Key и новой expected_total_amount;
 *   409 uniundata_terms_outdated — data.current_versions: показать новые тексты оферты/политики;
 *   502 uniundata_payment_provider_error — заказ создан (data.public_order_id), банк недоступен:
 *       клиент повторяет оплату через POST /orders/{id}/pay.
 */
final class CheckoutController extends RestController
{
    /** Телефон до нормализации: «+7 (846) 123-45-67»; E.164 проверяет CheckoutRequest. */
    private const PHONE_SCHEMA = ['type' => 'string', 'pattern' => '^\\+?[0-9 ()\\-.]{7,24}$', 'maxLength' => 24];

    /** Имя: длина = customer_*_name VARCHAR(100); символы проверяет CheckoutRequest, пустая строка — «из профиля». */
    private const NAME_SCHEMA = ['type' => 'string', 'maxLength' => 100];

    /** Поля адреса и их максимальная длина — те же, что в CheckoutRequest::ADDRESS_FIELDS. */
    private const ADDRESS_SCHEMA = [
        'type' => 'object',
        'additionalProperties' => false,
        'properties' => [
            'first_name' => ['type' => 'string', 'maxLength' => 100],
            'last_name' => ['type' => 'string', 'maxLength' => 100],
            'company' => ['type' => 'string', 'maxLength' => 255],
            'line1' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 255, 'required' => true],
            'line2' => ['type' => 'string', 'maxLength' => 255],
            'city' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 100, 'required' => true],
            'region' => ['type' => 'string', 'maxLength' => 100],
            'postcode' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 20, 'required' => true],
            'country' => ['type' => 'string', 'pattern' => '^[A-Za-z]{2}$', 'required' => true],
        ],
    ];

    public function __construct(AuditLog $audit, private readonly CheckoutService $checkout)
    {
        parent::__construct($audit);
    }

    public function register_routes(): void
    {
        $this->addCommonHooks();

        register_rest_route(self::NAMESPACE, '/checkout', [
            'methods' => \WP_REST_Server::CREATABLE,
            'callback' => [$this, 'checkout'],
            'permission_callback' => $this->requireCapability('create_book_orders'),
            'args' => [
                'expected_total_amount' => [
                    'description' => 'Сумма к оплате, которую видел покупатель, в минимальных единицах валюты (копейки). Расхождение с сервером → 409 uniundata_cart_changed.',
                    'type' => 'integer',
                    'minimum' => 1,
                    'maximum' => 4294967295,
                    'required' => true,
                    'validate_callback' => 'rest_validate_request_arg',
                    'sanitize_callback' => 'rest_sanitize_request_arg',
                ],
                'currency' => [
                    'description' => 'Валюта магазина (option uniundata_currency), ISO 4217.',
                    'type' => 'string',
                    'pattern' => '^[A-Za-z]{3}$',
                    'required' => true,
                    'validate_callback' => 'rest_validate_request_arg',
                    'sanitize_callback' => static fn (mixed $v): string => strtoupper((string) $v),
                ],
                'accept_offer_version' => self::termsArg('Версия принятой оферты (из option uniundata_terms_versions).'),
                'accept_privacy_version' => self::termsArg('Версия принятой политики обработки персональных данных.'),
                'shipping_address' => self::ADDRESS_SCHEMA + [
                    'description' => 'Адрес доставки (снимок в заказ). Обязательны line1, city, postcode, country.',
                    'required' => false,
                    'validate_callback' => 'rest_validate_request_arg',
                    'sanitize_callback' => 'rest_sanitize_request_arg',
                ],
                'billing_address' => self::ADDRESS_SCHEMA + [
                    'description' => 'Платёжный адрес, если отличается от адреса доставки.',
                    'required' => false,
                    'validate_callback' => 'rest_validate_request_arg',
                    'sanitize_callback' => 'rest_sanitize_request_arg',
                ],
                'customer' => [
                    'description' => 'Покупатель для снимка заказа и чека; незаданные поля берутся из профиля.',
                    'type' => 'object',
                    'additionalProperties' => false,
                    'properties' => [
                        'first_name' => self::NAME_SCHEMA,
                        'last_name' => self::NAME_SCHEMA,
                        'middle_name' => self::NAME_SCHEMA,
                        'phone' => self::PHONE_SCHEMA,
                    ],
                    'required' => false,
                    'validate_callback' => 'rest_validate_request_arg',
                    'sanitize_callback' => 'rest_sanitize_request_arg',
                ],
                'phone' => self::PHONE_SCHEMA + [
                    'description' => 'Устарело: то же, что customer.phone (customer.phone приоритетнее).',
                    'required' => false,
                    'validate_callback' => 'rest_validate_request_arg',
                    'sanitize_callback' => 'rest_sanitize_request_arg',
                ],
            ],
        ]);
    }

    public function checkout(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        return $this->respond($request, function () use ($request): \WP_REST_Response {
            $this->enforceRateLimit('checkout');
            $userId = $this->currentUserId();

            $key = $request->get_header('Idempotency-Key');
            if ($key === null || trim($key) === '') {
                throw DomainError::invalidParam('Idempotency-Key', \__('Idempotency-Key header is required.', 'uniundata-books'));
            }

            // get_json_params() уже содержит значения после sanitize_callback из args (WP_REST_Request::sanitize_params()).
            $body = $request->get_json_params();
            if (!\is_array($body)) {
                throw DomainError::invalidParam('body', \__('Request body must be JSON (Content-Type: application/json).', 'uniundata-books'));
            }

            $result = $this->checkout->checkout($userId, CheckoutRequest::fromRest(
                $body,
                $key,
                $this->clientIp(),
                $request->get_header('User-Agent'),
            ));

            $publicOrderId = (string) $result['order']['public_order_id'];
            $data = [
                'created' => $result['created'],
                'replayed' => $result['replayed'],
                'order' => $result['order'],
                'payment' => $result['payment'],
            ];
            if ($result['replayed']) {
                return new \WP_REST_Response($data, 200);
            }

            return $this->created($data, rest_url(self::NAMESPACE . '/orders/' . rawurlencode($publicOrderId)));
        });
    }

    /** @return array<string, mixed> */
    private static function termsArg(string $description): array
    {
        return [
            'description' => $description,
            'type' => 'string',
            'pattern' => '^[A-Za-z0-9._-]{1,32}$',
            'required' => true,
            'validate_callback' => 'rest_validate_request_arg',
            'sanitize_callback' => 'sanitize_text_field',
        ];
    }
}
