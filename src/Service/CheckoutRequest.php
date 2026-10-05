<?php

declare(strict_types=1);

namespace Uniundata\Books\Service;

use Uniundata\Books\Domain\DomainError;

/**
 * Валидированный вход POST /checkout. Создаётся контроллером из WP_REST_Request:
 *
 *   CheckoutRequest::fromRest(
 *       $request->get_json_params(),
 *       (string) $request->get_header('Idempotency-Key'),
 *       $clientIp,                                  // IP из доверенного прокси
 *       $request->get_header('User-Agent'),
 *   );
 *
 * Тело: {expected_total_amount, currency, accept_offer_version, accept_privacy_version,
 *        customer?: {first_name?, last_name?, middle_name?, phone?}, shipping_address?, billing_address?}.
 * Поля customer необязательны: незаданное берётся из профиля (usermeta first_name/last_name/middle_name,
 * wp_book_customer_profiles.phone_e164) в CheckoutService. Отчество не обязательно нигде.
 * Верхнеуровневый `phone` принимается как устаревший синоним customer.phone.
 *
 * REST `args` дают первую линию валидации; конструктор — вторую, независимую от того, как объект
 * создан (CLI, тесты). Значения проверяются в PHP ДО SQL: длины и форматы совпадают с колонками и
 * CHECK-ограничениями wp_book_orders (транзакции плагина идут в STRICT_TRANS_TABLES).
 */
final readonly class CheckoutRequest
{
    /** Белый список полей адреса и их максимальная длина. */
    private const ADDRESS_FIELDS = [
        'first_name' => 100,
        'last_name' => 100,
        'company' => 255,
        'line1' => 255,
        'line2' => 255,
        'city' => 100,
        'region' => 100,
        'postcode' => 20,
        'country' => 2,
    ];

    /** = customer_first_name / customer_last_name / customer_middle_name VARCHAR(100). */
    public const NAME_MAX_LENGTH = 100;
    /** Буквы любых алфавитов, пробел, дефис, апостроф, точка; начинается с буквы. */
    private const NAME_PATTERN = "/^\\p{L}[\\p{L}\\p{M} .'’-]*$/u";
    private const PHONE_PATTERN = '/^\+[1-9][0-9]{6,14}$/';

    /**
     * @param ?array<string, string> $shippingAddress Нормализованный адрес (только поля из белого списка).
     * @param ?array<string, string> $billingAddress
     */
    public function __construct(
        /** Заголовок Idempotency-Key → orders.checkout_request_id (UUID, нижний регистр). */
        public string $idempotencyKey,
        /** Сумма, которую видел клиент; расхождение → 409 uniundata_cart_changed. */
        public int $expectedTotalAmount,
        public string $currency,
        public string $acceptOfferVersion,
        public string $acceptPrivacyVersion,
        public ?array $shippingAddress = null,
        public ?array $billingAddress = null,
        /** customer.first_name; null — из usermeta first_name. */
        public ?string $firstName = null,
        /** customer.last_name; null — из usermeta last_name. */
        public ?string $lastName = null,
        /** customer.middle_name (необязательно); null — из usermeta middle_name, если есть. */
        public ?string $middleName = null,
        /** customer.phone в E.164; null — из wp_book_customer_profiles.phone_e164. */
        public ?string $phone = null,
        /** IP для записи согласия (INET6_ATON). */
        public ?string $ipAddress = null,
        /** Хранится только SHA-256 user agent. */
        public ?string $userAgent = null,
    ) {
        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $idempotencyKey)) {
            throw DomainError::invalidParam('Idempotency-Key', \__('Idempotency-Key must be a UUID.', 'uniundata-books'));
        }
        // Сумма заказа > 0 (CHECK wp_book_orders_chk_positive); верхняя граница — INT UNSIGNED.
        if ($expectedTotalAmount < 1 || $expectedTotalAmount > 4_294_967_295) {
            throw DomainError::invalidParam('expected_total_amount', \__('Invalid expected_total_amount.', 'uniundata-books'));
        }
        if (!preg_match('/^[A-Z]{3}$/', $currency)) {
            throw DomainError::invalidParam('currency', \__('Invalid currency.', 'uniundata-books'));
        }
        foreach (['accept_offer_version' => $acceptOfferVersion, 'accept_privacy_version' => $acceptPrivacyVersion] as $param => $v) {
            if (!preg_match('/^[A-Za-z0-9._-]{1,32}$/', $v)) {
                throw DomainError::invalidParam($param, \__('Terms must be accepted.', 'uniundata-books'));
            }
        }
        foreach (['customer.first_name' => $firstName, 'customer.last_name' => $lastName, 'customer.middle_name' => $middleName] as $param => $v) {
            if ($v !== null && !self::isValidName($v)) {
                throw DomainError::invalidParam($param, \__('Invalid name.', 'uniundata-books'));
            }
        }
        if ($phone !== null && !preg_match(self::PHONE_PATTERN, $phone)) {
            throw DomainError::invalidParam('customer.phone', \__('Phone must be in E.164 format.', 'uniundata-books'));
        }
        if ($ipAddress !== null && filter_var($ipAddress, FILTER_VALIDATE_IP) === false) {
            throw new \InvalidArgumentException('ipAddress is not a valid IP');
        }
        self::assertAddress('shipping_address', $shippingAddress);
        self::assertAddress('billing_address', $billingAddress);
    }

    /**
     * @param array<string, mixed> $body JSON-тело запроса.
     */
    public static function fromRest(array $body, string $idempotencyKey, ?string $ip, ?string $userAgent): self
    {
        $amount = $body['expected_total_amount'] ?? null;
        if (!\is_int($amount)) {
            // JSON-число без дробной части; строки и float не принимаем (деньги — только int).
            throw DomainError::invalidParam('expected_total_amount', \__('Invalid expected_total_amount.', 'uniundata-books'));
        }
        $customer = $body['customer'] ?? [];
        if (!\is_array($customer)) {
            throw DomainError::invalidParam('customer', \__('Invalid customer data.', 'uniundata-books'));
        }
        $phone = self::stringOrNull($customer, 'phone', 'customer.phone') ?? self::stringOrNull($body, 'phone', 'phone');

        return new self(
            idempotencyKey: strtolower(trim($idempotencyKey)),
            expectedTotalAmount: $amount,
            currency: strtoupper((string) ($body['currency'] ?? '')),
            acceptOfferVersion: (string) ($body['accept_offer_version'] ?? ''),
            acceptPrivacyVersion: (string) ($body['accept_privacy_version'] ?? ''),
            shippingAddress: self::normalizeAddress('shipping_address', $body['shipping_address'] ?? null),
            billingAddress: self::normalizeAddress('billing_address', $body['billing_address'] ?? null),
            firstName: self::normalizeName(self::stringOrNull($customer, 'first_name', 'customer.first_name')),
            lastName: self::normalizeName(self::stringOrNull($customer, 'last_name', 'customer.last_name')),
            middleName: self::normalizeName(self::stringOrNull($customer, 'middle_name', 'customer.middle_name')),
            phone: self::normalizePhone($phone),
            ipAddress: $ip !== null && filter_var($ip, FILTER_VALIDATE_IP) !== false ? $ip : null,
            userAgent: $userAgent !== null && $userAgent !== '' ? $userAgent : null,
        );
    }

    /** Убирает управляющие символы и лишние пробелы; пустая строка → null («взять из профиля»). */
    public static function normalizeName(?string $raw): ?string
    {
        if ($raw === null) {
            return null;
        }
        $v = trim((string) preg_replace('/[\x00-\x1F\x7F\s]+/u', ' ', $raw));

        return $v === '' ? null : $v;
    }

    public static function isValidName(string $name): bool
    {
        return mb_check_encoding($name, 'UTF-8')
            && mb_strlen($name) <= self::NAME_MAX_LENGTH
            && preg_match(self::NAME_PATTERN, $name) === 1;
    }

    /** '+7 (846) 123-45-67' → '+78461234567'; формат проверяет конструктор. */
    public static function normalizePhone(?string $raw): ?string
    {
        if ($raw === null) {
            return null;
        }
        $v = (string) preg_replace('/[\s()\-.]+/', '', $raw);

        return $v === '' ? null : $v;
    }

    /** @param array<array-key, mixed> $data */
    private static function stringOrNull(array $data, string $key, string $param): ?string
    {
        if (!isset($data[$key])) {
            return null;
        }
        if (!\is_string($data[$key])) {
            throw DomainError::invalidParam($param, \__('Invalid value.', 'uniundata-books'));
        }

        return $data[$key];
    }

    /**
     * Оставляет только поля из белого списка, обрезает пробелы, отбрасывает пустые.
     *
     * @return ?array<string, string>
     */
    private static function normalizeAddress(string $param, mixed $raw): ?array
    {
        if ($raw === null) {
            return null;
        }
        if (!\is_array($raw)) {
            throw DomainError::invalidParam($param, \__('Invalid address.', 'uniundata-books'));
        }
        $out = [];
        foreach (self::ADDRESS_FIELDS as $field => $_max) {
            if (!isset($raw[$field]) || !\is_string($raw[$field])) {
                continue;
            }
            // Управляющие символы убираем; HTML не экранируем здесь — экранирование при выводе (esc_html).
            $v = trim((string) preg_replace('/[\x00-\x1F\x7F]/u', '', $raw[$field]));
            if ($v !== '') {
                $out[$field] = $field === 'country' ? strtoupper($v) : $v;
            }
        }

        return $out === [] ? null : $out;
    }

    /** @param ?array<string, string> $address */
    private static function assertAddress(string $param, ?array $address): void
    {
        if ($address === null) {
            return;
        }
        foreach ($address as $field => $value) {
            $max = self::ADDRESS_FIELDS[$field] ?? null;
            if ($max === null || !\is_string($value) || !mb_check_encoding($value, 'UTF-8') || mb_strlen($value) > $max) {
                throw DomainError::invalidParam($param . '.' . $field, \__('Invalid address.', 'uniundata-books'));
            }
        }
        foreach (['line1', 'city', 'postcode', 'country'] as $required) {
            if (!isset($address[$required])) {
                throw DomainError::invalidParam($param . '.' . $required, \__('Address is incomplete.', 'uniundata-books'));
            }
        }
        if (!preg_match('/^[A-Z]{2}$/', $address['country'])) {
            throw DomainError::invalidParam($param . '.country', \__('Invalid country code.', 'uniundata-books'));
        }
    }
}
