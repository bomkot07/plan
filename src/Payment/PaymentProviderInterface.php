<?php

declare(strict_types=1);

namespace Uniundata\Books\Payment;

/**
 * Порт платёжного провайдера. Адаптер конкретного банка (src/Payment/<Bank>Provider.php) реализует
 * HTTP-вызовы через wp_remote_post()/wp_remote_get() с таймаутом 10–15 с.
 *
 * Правила для всех реализаций:
 *  1. Ни один метод НЕ вызывается внутри Db::transaction(): сервисы зовут банк только вне транзакций.
 *  2. Ключи и секреты — из констант wp-config.php / переменных окружения (UNIUNDATA_BANK_*),
 *     не из wp_options. В исключения, логи и redactedPayload секреты, PAN, CVV не попадают.
 *  3. Любая ошибка транспорта или ответ банка 5xx/4xx → \RuntimeException (сообщение без секретов
 *     и тела ответа). Сервисы трактуют её как «результат неизвестен».
 *  4. createSession идемпотентен по $idempotencyKey: повтор с тем же ключом возвращает ту же сессию
 *     (ключ передаётся банку как Idempotency-Key / merchant reference). Это позволяет безопасно
 *     повторять вызов после таймаута и восстанавливать redirect URL.
 *  5. Банк должен возвращать наш $idempotencyKey (merchant reference) и $publicOrderId в событиях и
 *     ответах API — по ним PaymentService находит платёж, если provider_payment_id ещё не сохранён.
 */
interface PaymentProviderInterface
{
    /** Код провайдера = wp_book_payments.provider и wp_book_payment_events.provider ([a-z0-9_]{1,32}). */
    public function name(): string;

    /**
     * Создаёт платёжную сессию у банка (сервер → банк).
     *
     * @param int    $amount      Сумма в минимальных единицах (центы), = payments.amount.
     * @param int    $ttlSeconds  Сколько банк принимает оплату; не больше payment_due_at − grace.
     * @param string $callbackUrl URL webhook-а: rest_url('uniundata/v1/payment/webhook').
     * @param string $returnUrl   Куда банк вернёт браузер. Возврат на него НЕ является подтверждением оплаты.
     * @param ?string $customerEmail Только если банк требует (3-D Secure 2); иначе null — минимизация PII.
     *
     * @throws \RuntimeException Ошибка транспорта или отказ банка (результат неизвестен).
     */
    public function createSession(
        string $publicOrderId,
        string $idempotencyKey,
        int $amount,
        string $currency,
        int $ttlSeconds,
        string $callbackUrl,
        string $returnUrl,
        ?string $customerEmail = null,
    ): PaymentSession;

    /**
     * Серверный запрос статуса платежа (опрос банка cron-ом и подтверждение webhook-а).
     *
     * @param ?string $providerPaymentId Null, если сессия ещё не была сохранена у нас (платёж `created`);
     *                                   тогда поиск по $idempotencyKey (merchant reference).
     * @return ?ProviderPaymentResult    Null — банк ДОСТОВЕРНО не знает такого платежа (сессия не создана).
     *
     * @throws \RuntimeException Банк недоступен / ответ не разобран (результат неизвестен).
     */
    public function fetchPayment(?string $providerPaymentId, string $idempotencyKey): ?ProviderPaymentResult;

    /**
     * Проверяет подлинность webhook-а и разбирает его. Вызывается ДО любой записи в БД.
     *
     * Реализация обязана:
     *  - считать подпись по СЫРОМУ телу ($rawBody как пришло, до json_decode);
     *  - сравнивать подписи только hash_equals() (постоянное время), см. PaymentService::verifyHmacSha256();
     *    для RSA/ECDSA-подписи — openssl_verify() с закреплённым публичным ключом банка;
     *  - проверять, что подписанный timestamp не старше/не новее ±300 с (защита от replay),
     *    и возвращать его в ProviderPaymentResult::$signedAt;
     *  - заполнить eventId (ID события у банка; если банк его не даёт — hash('sha256', $rawBody)).
     *
     * @param array<string, string|string[]> $headers Заголовки как отдаёт WP_REST_Request::get_headers():
     *                                               ключи в нижнем регистре, '-' заменён на '_'
     *                                               (X-Signature → x_signature), значения — list<string>.
     *                                               Адаптер берёт первый элемент и не доверяет остальным.
     *
     * @throws \Uniundata\Books\Domain\DomainError uniundata_invalid_signature (401) — подпись/время не прошли.
     * @throws \InvalidArgumentException Подпись верна, но тело не разбирается (400).
     */
    public function verifyWebhook(string $rawBody, array $headers): ProviderPaymentResult;

    /**
     * Закрывает сессию у банка (best effort, после COMMIT отмены/истечения заказа).
     * Если банк уже провёл оплату, придёт webhook succeeded → ветка позднего платежа.
     *
     * @throws \RuntimeException
     */
    public function cancelSession(string $providerPaymentId, string $idempotencyKey): void;

    /**
     * Возврат денег. Вызывается только из задачи Action Scheduler (после COMMIT).
     *
     * @param string $refundIdempotencyKey Детерминированный ключ (например, UUIDv5 от payment_id + reason):
     *                                     повтор задачи не создаёт второй возврат.
     * @return string ID возврата у банка (для аудита). Итог возврата приходит webhook-ом refunded.
     *
     * @throws \RuntimeException
     */
    public function refund(string $providerPaymentId, int $amount, string $currency, string $refundIdempotencyKey): string;
}
