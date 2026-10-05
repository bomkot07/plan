<?php

declare(strict_types=1);

namespace Uniundata\Books\Payment;

/**
 * Позиция фискального чека (54-ФЗ). Один экземпляр книги = одна позиция с количеством 1.
 * Коды ставки НДС и признаков — нейтральные; адаптер банка переводит их в коды своего API.
 */
final readonly class FiscalReceiptItem
{
    /** Ставка НДС позиции. Какая применяется — решает бухгалтер (СНО, льгота для книжной продукции). */
    public const VAT_CODES = ['none', 'vat0', 'vat5', 'vat7', 'vat10', 'vat20', 'vat22'];
    /** Признак предмета расчёта: товар или услуга (доставка). */
    public const SUBJECTS = ['commodity', 'service'];
    /** ФФД: наименование предмета расчёта — не длиннее 128 символов. */
    public const MAX_NAME_LENGTH = 128;

    public function __construct(
        public string $name,
        /** Цена за единицу в минимальных единицах валюты (копейки), > 0. */
        public int $unitPrice,
        public string $vat,
        public int $quantity = 1,
        public string $subject = 'commodity',
        /** wp_book_items.id — для адаптера и фильтров; в чек не печатается. */
        public ?int $bookItemId = null,
    ) {
        if ($name === '' || !mb_check_encoding($name, 'UTF-8') || mb_strlen($name) > self::MAX_NAME_LENGTH) {
            throw new \InvalidArgumentException('Receipt item name must be 1..128 UTF-8 chars');
        }
        if ($unitPrice <= 0 || $quantity < 1) {
            throw new \InvalidArgumentException('Receipt item price and quantity must be positive');
        }
        if (!\in_array($vat, self::VAT_CODES, true)) {
            throw new \InvalidArgumentException(\sprintf('Unknown VAT code "%s"', $vat));
        }
        if (!\in_array($subject, self::SUBJECTS, true)) {
            throw new \InvalidArgumentException(\sprintf('Unknown receipt subject "%s"', $subject));
        }
    }

    public function amount(): int
    {
        return $this->unitPrice * $this->quantity;
    }

    /** Название из снимка заказа: без управляющих символов, обрезано до лимита ФФД. */
    public static function nameFromTitle(string $title): string
    {
        $name = trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $title));

        return $name === '' ? 'Книга' : mb_substr($name, 0, self::MAX_NAME_LENGTH);
    }
}
