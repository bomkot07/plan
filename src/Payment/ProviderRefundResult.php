<?php

declare(strict_types=1);

namespace Uniundata\Books\Payment;

/**
 * Ответ банка на PaymentProviderInterface::refund(). Повтор вызова с тем же ключом идемпотентности
 * возвращает тот же возврат с его текущим статусом — так PaymentService::processRefund() и опрашивает
 * незавершённый возврат.
 */
final readonly class ProviderRefundResult
{
    public const PENDING = 'pending';
    public const SUCCEEDED = 'succeeded';
    public const FAILED = 'failed';

    public function __construct(
        /** ID возврата у банка → wp_book_refunds.provider_refund_id. */
        public string $providerRefundId,
        /** pending — банк принял запрос, итог позже; succeeded — деньги возвращены; failed — отказ. */
        public string $status,
        /** Короткая причина отказа без PII, ≤ 255. */
        public ?string $failureMessage = null,
    ) {
        if (preg_match('/^[\x21-\x7E]{1,128}$/', $providerRefundId) !== 1) {
            throw new \InvalidArgumentException('providerRefundId must be 1..128 printable ASCII chars');
        }
        if (!\in_array($status, [self::PENDING, self::SUCCEEDED, self::FAILED], true)) {
            throw new \InvalidArgumentException(\sprintf('Unknown refund status "%s"', $status));
        }
        if ($failureMessage !== null && mb_strlen($failureMessage) > 255) {
            throw new \InvalidArgumentException('failureMessage must be <= 255 chars');
        }
    }
}
