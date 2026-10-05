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
 *       $_SERVER['REMOTE_ADDR'] ?? null,            // или IP из доверенного прокси
 *       $request->get_header('User-Agent'),
 *   );
 *
 * REST `args` дают первую линию валидации; конструктор — вторую, независимую от того, как объект
 * создан (CLI, тесты). Все значения проверяются в PHP ДО SQL: WordPress выключает STRICT-режим
 * MySQL, и некорректные значения иначе молча «чинились» бы (обрезка строк, '?' в ascii-колонках).
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
        /** E.164; если null — берётся из wp_book_customer_profiles.phone_e164. */
        public ?string $phone = null,
        /** IP для записи согласия (INET6_ATON). */
        public ?string $ipAddress = null,
        /** Хранится только SHA-256 user agent. */
        public ?string $userAgent = null,
    ) {
        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $idempotencyKey)) {
            throw new DomainError('uniundata_invalid_param', \__('Idempotency-Key must be a UUID.', 'uniundata-books'), 400, ['param' => 'Idempotency-Key']);
        }
        if ($expectedTotalAmount < 0 || $expectedTotalAmount > 4_294_967_295) {
            throw new DomainError('uniundata_invalid_param', \__('Invalid expected_total_amount.', 'uniundata-books'), 400, ['param' => 'expected_total_amount']);
        }
        if (!preg_match('/^[A-Z]{3}$/', $currency)) {
            throw new DomainError('uniundata_invalid_param', \__('Invalid currency.', 'uniundata-books'), 400, ['param' => 'currency']);
        }
        foreach (['accept_offer_version' => $acceptOfferVersion, 'accept_privacy_version' => $acceptPrivacyVersion] as $param => $v) {
            if (!preg_match('/^[A-Za-z0-9._-]{1,32}$/', $v)) {
                throw new DomainError('uniundata_invalid_param', \__('Terms must be accepted.', 'uniundata-books'), 400, ['param' => $param]);
            }
        }
        if ($phone !== null && !preg_match('/^\+[1-9][0-9]{6,14}$/', $phone)) {
            throw new DomainError('uniundata_invalid_param', \__('Phone must be in E.164 format.', 'uniundata-books'), 400, ['param' => 'phone']);
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
        if (!is_int($amount)) {
            // JSON-число без дробной части; строки и float не принимаем (деньги — только int).
            throw new DomainError('uniundata_invalid_param', \__('Invalid expected_total_amount.', 'uniundata-books'), 400, ['param' => 'expected_total_amount']);
        }

        return new self(
            idempotencyKey: strtolower(trim($idempotencyKey)),
            expectedTotalAmount: $amount,
            currency: strtoupper((string) ($body['currency'] ?? '')),
            acceptOfferVersion: (string) ($body['accept_offer_version'] ?? ''),
            acceptPrivacyVersion: (string) ($body['accept_privacy_version'] ?? ''),
            shippingAddress: self::normalizeAddress('shipping_address', $body['shipping_address'] ?? null),
            billingAddress: self::normalizeAddress('billing_address', $body['billing_address'] ?? null),
            phone: isset($body['phone']) && $body['phone'] !== '' ? (string) $body['phone'] : null,
            ipAddress: $ip !== null && filter_var($ip, FILTER_VALIDATE_IP) !== false ? $ip : null,
            userAgent: $userAgent !== null && $userAgent !== '' ? $userAgent : null,
        );
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
        if (!is_array($raw)) {
            throw new DomainError('uniundata_invalid_param', \__('Invalid address.', 'uniundata-books'), 400, ['param' => $param]);
        }
        $out = [];
        foreach (self::ADDRESS_FIELDS as $field => $_max) {
            if (!isset($raw[$field]) || !is_string($raw[$field])) {
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
            if ($max === null || !is_string($value) || !mb_check_encoding($value, 'UTF-8') || mb_strlen($value) > $max) {
                throw new DomainError('uniundata_invalid_param', \__('Invalid address.', 'uniundata-books'), 400, ['param' => $param . '.' . $field]);
            }
        }
        foreach (['line1', 'city', 'postcode', 'country'] as $required) {
            if (!isset($address[$required])) {
                throw new DomainError('uniundata_invalid_param', \__('Address is incomplete.', 'uniundata-books'), 400, ['param' => $param . '.' . $required]);
            }
        }
        if (!preg_match('/^[A-Z]{2}$/', $address['country'])) {
            throw new DomainError('uniundata_invalid_param', \__('Invalid country code.', 'uniundata-books'), 400, ['param' => $param . '.country']);
        }
    }
}
