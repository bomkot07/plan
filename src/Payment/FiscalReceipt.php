<?php

declare(strict_types=1);

namespace Uniundata\Books\Payment;

/**
 * Данные для фискального чека по 54-ФЗ: облачная касса провайдера пробивает чек «приход» при оплате
 * (PaymentProviderInterface::createSession) и «возврат прихода» при возврате (::refund).
 * Плагин передаёт только позиции и контакт покупателя; СНО, ККТ и реквизиты кассы настраиваются
 * у провайдера и в константах адаптера (wp-config.php), не в БД.
 *
 * Сумма позиций обязана совпадать с суммой платежа/возврата — иначе касса отклонит чек;
 * сервисы проверяют это до HTTP-вызова.
 */
final readonly class FiscalReceipt
{
    /** Ставка НДС по умолчанию для всех позиций (option, задаёт бухгалтер). */
    public const OPTION_VAT = 'uniundata_receipt_vat';
    /** Ставка для конкретной позиции: apply_filters(FILTER_ITEM_VAT, $vat, $bookItemId, $orderId). */
    public const FILTER_ITEM_VAT = 'uniundata_receipt_item_vat';
    /** Последняя правка всего чека: apply_filters(FILTER_RECEIPT, $receipt, $orderId, $kind). */
    public const FILTER_RECEIPT = 'uniundata_fiscal_receipt';
    /** Способ расчёта: оплата до передачи книги — «предоплата 100 %». */
    public const PAYMENT_METHODS = ['full_prepayment', 'full_payment'];

    /**
     * @param list<FiscalReceiptItem> $items
     */
    public function __construct(
        public array $items,
        /** Контакт для электронного чека. Достаточно одного; адаптер передаёт банку только то, что нужно. */
        public ?string $customerEmail,
        public ?string $customerPhone,
        public string $paymentMethod = 'full_prepayment',
    ) {
        if ($items === []) {
            throw new \InvalidArgumentException('Receipt must have at least one item');
        }
        foreach ($items as $item) {
            if (!$item instanceof FiscalReceiptItem) {
                throw new \InvalidArgumentException('Receipt items must be FiscalReceiptItem');
            }
        }
        if ($customerEmail === null && $customerPhone === null) {
            throw new \InvalidArgumentException('Receipt needs customer e-mail or phone (54-FZ)');
        }
        if ($customerPhone !== null && preg_match('/^\+[1-9][0-9]{6,14}$/', $customerPhone) !== 1) {
            throw new \InvalidArgumentException('Receipt phone must be E.164');
        }
        if (!\in_array($paymentMethod, self::PAYMENT_METHODS, true)) {
            throw new \InvalidArgumentException(\sprintf('Unknown payment method "%s"', $paymentMethod));
        }
    }

    public function total(): int
    {
        return array_sum(array_map(static fn (FiscalReceiptItem $i): int => $i->amount(), $this->items));
    }

    /**
     * Чек из строк заказа. Ставка НДС — option uniundata_receipt_vat (по умолчанию 'none') и фильтр
     * на позицию; итог сверяется с суммой платежа/возврата.
     *
     * @param list<array{title: string, amount: int, book_item_id: ?int, subject?: string}> $lines
     * @param 'payment'|'refund' $kind
     */
    public static function fromLines(array $lines, ?string $email, ?string $phone, int $orderId, int $expectedTotal, string $kind): self
    {
        $default = (string) get_option(self::OPTION_VAT, 'none');
        $items = [];
        foreach ($lines as $line) {
            $vat = (string) apply_filters(self::FILTER_ITEM_VAT, $default, $line['book_item_id'], $orderId);
            $items[] = new FiscalReceiptItem(
                FiscalReceiptItem::nameFromTitle($line['title']),
                $line['amount'],
                $vat,
                1,
                $line['subject'] ?? 'commodity',
                $line['book_item_id'],
            );
        }
        $receipt = apply_filters(
            self::FILTER_RECEIPT,
            new self($items, $email !== '' ? $email : null, $phone !== '' ? $phone : null),
            $orderId,
            $kind,
        );
        if (!$receipt instanceof self || $receipt->total() !== $expectedTotal) {
            throw new \LogicException(\sprintf('Fiscal receipt total does not match %s amount of order #%d', $kind, $orderId));
        }

        return $receipt;
    }
}
