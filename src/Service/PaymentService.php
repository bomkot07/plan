<?php

declare(strict_types=1);

namespace Uniundata\Books\Service;

use Uniundata\Books\Domain\DomainError;
use Uniundata\Books\Infrastructure\AuditLog;
use Uniundata\Books\Infrastructure\Db;
use Uniundata\Books\Payment\PaymentProviderInterface;
use Uniundata\Books\Payment\ProviderPaymentResult;
use Uniundata\Books\Payment\WebhookResult;

/**
 * Приём webhook-ов банка и применение результата платежа (общий путь для webhook и опроса банка).
 *
 * Факт оплаты — ТОЛЬКО серверное подтверждение: webhook с проверенной подписью (и, если включено,
 * подтверждённый server-to-server запросом fetchPayment) или ответ API банка при опросе cron-ом.
 * Return URL браузера оплату не подтверждает.
 *
 * Порядок блокировок в applyProviderResult (контракт): items (asc) → orders → payments → payment_events.
 * Побочные эффекты (письма, документы, возвраты, уведомления) — только после COMMIT через Action Scheduler.
 */
final class PaymentService
{
    /** Action Scheduler: письмо/документы по оплаченному заказу. Обработчик: fn (int $orderId). */
    public const HOOK_ORDER_PAID = 'uniundata_order_paid';
    /** Возврат денег. Обработчик: fn (int $paymentId, int $amount, string $reason). */
    public const HOOK_REFUND = 'uniundata_refund_payment';
    /** Уведомление менеджеру о needs_attention. Обработчик: fn (int $orderId, string $reason). */
    public const HOOK_ATTENTION = 'uniundata_order_needs_attention';
    public const AS_GROUP = 'uniundata';

    /** Допуск по времени подписанного timestamp (защита от replay). */
    public const SIGNATURE_TOLERANCE_SECONDS = 300;
    private const MAX_BODY_BYTES = 65536;

    /** Платёж: допустимые исходные статусы для целевого (контракт). */
    private const PAYMENT_FROM = [
        'pending' => ['created'],
        'processing' => ['pending'],
        'succeeded' => ['pending', 'processing', 'failed', 'expired', 'cancelled'],
        'failed' => ['created', 'pending', 'processing'],
        'cancelled' => ['pending'],
        'expired' => ['pending', 'processing'],
        'refunded' => ['succeeded', 'partially_refunded'],
        'partially_refunded' => ['succeeded'],
    ];

    /** Заказ: допустимые исходные статусы для целевого (контракт). */
    private const ORDER_FROM = [
        'pending_payment' => ['draft', 'payment_failed'],
        'payment_processing' => ['pending_payment'],
        'paid' => ['pending_payment', 'payment_processing', 'payment_failed', 'payment_expired', 'cancelled'],
        'payment_failed' => ['draft', 'pending_payment', 'payment_processing'],
        'refunded' => ['paid', 'fulfilled', 'completed', 'partially_refunded'],
        'partially_refunded' => ['paid', 'fulfilled', 'completed'],
    ];

    private const PAID_ORDER_STATUSES = ['paid', 'fulfilled', 'completed', 'refunded', 'partially_refunded'];
    private const MONEY_RECEIVED = ['succeeded', 'refunded', 'partially_refunded'];
    /** Свободный экземпляр для позднего платежа (плюс: нет активного резерва). */
    private const FREE_ITEM_STATUSES = ['available', 'sync_missing', 'withdrawn'];

    private \wpdb $wpdb;
    /** @var list<array{0: string, 1: array<string, int|string>}> Задачи Action Scheduler после COMMIT. */
    private array $afterCommit = [];
    /** @var list<array<string, mixed>> Сессии банка, которые закрываются после COMMIT (best effort). */
    private array $sessionsToCancel = [];
    private string $actor = 'webhook';
    private ?int $currentEventId = null;

    public function __construct(
        private readonly Db $db,
        private readonly AuditLog $audit,
        private readonly PaymentProviderInterface $provider,
        /** Подтверждать succeeded/processing/refund из webhook запросом fetchPayment (вне транзакции). */
        private readonly bool $confirmWithApi = true,
        ?\wpdb $wpdb = null,
    ) {
        $this->wpdb = $wpdb ?? $GLOBALS['wpdb'];
    }

    // =================================================================================================
    // POST /payment/webhook
    // =================================================================================================

    /**
     * Контроллер передаёт СЫРОЕ тело ($request->get_body(), не get_json_params(): подпись считается по байтам)
     * и $request->get_headers() (ключи lower_snake_case, значения — массивы). Ответ банку — $result->httpStatus
     * и $result->responseBody(); для 401 можно вернуть WP_Error uniundata_invalid_signature.
     *
     * @param array<string, string|string[]> $headers
     */
    public function handleWebhook(string $rawBody, array $headers): WebhookResult
    {
        if (strlen($rawBody) > self::MAX_BODY_BYTES) {
            return WebhookResult::badRequest('body too large', 413);
        }
        $headers = array_change_key_case($headers, CASE_LOWER);

        // 1. Подпись и время — ДО любой записи в БД: иначе атакующий мог бы «занять» provider_event_id.
        try {
            $event = $this->provider->verifyWebhook($rawBody, $headers);
        } catch (DomainError $e) {
            $this->noteRejectedWebhook($e->errorCode());

            return WebhookResult::invalidSignature();
        } catch (\InvalidArgumentException $e) {
            $this->noteRejectedWebhook('unparseable_payload');

            return WebhookResult::badRequest('payload rejected');
        }
        // Повторная проверка окна времени, не полагаясь только на адаптер.
        if ($event->signedAt === null || abs(time() - $event->signedAt) > self::SIGNATURE_TOLERANCE_SECONDS) {
            $this->noteRejectedWebhook('stale_timestamp');

            return WebhookResult::invalidSignature('stale timestamp');
        }
        if ($event->provider !== $this->provider->name()) {
            return WebhookResult::badRequest('provider mismatch');
        }

        // 2. Inbox: идемпотентность по (provider, provider_event_id). Автокоммит, вне транзакции.
        $eventId = $this->storeEvent($event, $rawBody);
        $state = $this->scalar(
            'SELECT processing_status FROM %i WHERE id = %d',
            $this->db->table('book_payment_events'),
            $eventId
        );
        if ($state === 'processed' || $state === 'ignored') {
            return WebhookResult::duplicate($eventId); // повтор доставки: 200, без изменений
        }

        // 3. Подтверждение у банка server-to-server (вне транзакции).
        $result = $event;
        if ($this->confirmWithApi && in_array($event->status->value, ['succeeded', 'processing', 'refunded', 'partially_refunded'], true)) {
            $payment = $this->locatePayment($event);
            if ($payment !== null) {
                try {
                    $fresh = $this->provider->fetchPayment(
                        $payment['provider_payment_id'] ?? $event->providerPaymentId,
                        (string) $payment['idempotency_key']
                    );
                } catch (\RuntimeException $e) {
                    $this->markEventFailed($eventId, 'provider_api_unavailable');

                    return WebhookResult::retryLater($eventId, 'provider api unavailable');
                }
                if ($fresh === null) {
                    $this->markEventFailed($eventId, 'provider_api_unknown_payment');

                    return WebhookResult::retryLater($eventId, 'payment unknown at provider api');
                }
                // Успех из webhook, который API ещё не подтверждает (лаг банка или подделка при утечке ключа):
                // не применяем и НЕ помечаем ignored — иначе повторная доставка станет дублем и успех потеряется.
                if ($event->status->value === 'succeeded'
                    && !in_array($fresh->status->value, ['succeeded', 'refunded', 'partially_refunded'], true)) {
                    $this->markEventFailed($eventId, 'success_not_confirmed_by_api');

                    return WebhookResult::retryLater($eventId, 'success not confirmed by provider api');
                }
                // В остальных случаях источник истины — API (оно свежее события).
                $result = $fresh->withEventOf($event);
            }
        }

        // 4. Применение (транзакция) + задачи после COMMIT.
        try {
            [$outcome, $note] = $this->apply($result, $eventId, 'webhook');
        } catch (\Throwable $e) {
            // deadlock после повторов (503 conflict_retry), CHECK/UNIQUE (ошибка логики) и т.п.:
            // событие failed, банк повторит доставку — guard IN ('received','failed') позволит переобработку.
            $this->markEventFailed($eventId, $e instanceof DomainError ? $e->errorCode() : 'apply_failed: ' . $e::class);
            error_log(sprintf('[uniundata] webhook event #%d failed: %s', $eventId, $e::class));

            return WebhookResult::retryLater($eventId, 'apply failed', $e instanceof DomainError ? 503 : 500);
        }

        return match ($outcome) {
            'processed' => WebhookResult::processed($eventId),
            'duplicate' => WebhookResult::duplicate($eventId),
            default => WebhookResult::ignored($eventId, $note),
        };
    }

    /**
     * Общий путь применения результата банка: webhook ($eventId — строка inbox) или опрос ($eventId = null).
     */
    public function applyProviderResult(ProviderPaymentResult $r, ?int $eventId): void
    {
        $this->apply($r, $eventId, $eventId !== null ? 'webhook' : 'cron');
    }

    /**
     * Reference-реализация HMAC-SHA256 для адаптеров (схема "timestamp.body", как у большинства банков).
     * Сравнение — только hash_equals (постоянное время): обычное === даёт timing-атаку на подпись.
     */
    public static function verifyHmacSha256(
        string $secret,
        string $rawBody,
        string $timestamp,
        string $signatureHex,
        int $now,
        int $toleranceSeconds = self::SIGNATURE_TOLERANCE_SECONDS,
    ): bool {
        if ($secret === '' || !ctype_digit($timestamp) || !preg_match('/^[0-9a-fA-F]{64}$/', $signatureHex)) {
            return false;
        }
        if (abs($now - (int) $timestamp) > $toleranceSeconds) {
            return false;
        }
        $expected = hash_hmac('sha256', $timestamp . '.' . $rawBody, $secret);

        return hash_equals($expected, strtolower($signatureHex));
    }

    /**
     * Очистка payload перед сохранением/логированием: ключи с секретами и PII → '[redacted]',
     * последовательности 13–19 цифр (возможный PAN) и e-mail в значениях маскируются.
     *
     * @param array<mixed> $data
     * @return array<mixed>
     */
    public static function redact(array $data, int $depth = 0): array
    {
        $out = [];
        foreach ($data as $k => $v) {
            $key = strtolower((string) $k);
            if ($depth >= 6) {
                $out[$k] = '[truncated]';
                continue;
            }
            if (preg_match('/(^|_)(pan|cvv|cvc|cvn|csc|ip)($|_)/', $key)
                || preg_match('/card_?number|secret|token|password|signature|authorization|iban|account_?number|e_?mail|phone|address|holder|first_?name|last_?name|full_?name|expiry|exp_month|exp_year/', $key)) {
                $out[$k] = '[redacted]';
                continue;
            }
            if (is_array($v)) {
                $out[$k] = self::redact($v, $depth + 1);
            } elseif (is_string($v)) {
                $v = (string) preg_replace('/\b(?:\d[ -]?){12,18}\d\b/', '[pan-redacted]', $v);
                $v = (string) preg_replace('/[^\s@]+@[^\s@]+\.[^\s@]+/', '[email-redacted]', $v);
                $out[$k] = mb_substr($v, 0, 500);
            } elseif (is_int($v) || is_float($v) || is_bool($v) || $v === null) {
                $out[$k] = $v;
            }
        }

        return $out;
    }

    // =================================================================================================
    // Применение результата
    // =================================================================================================

    /** @return array{0: string, 1: string} [outcome processed|ignored|duplicate, note] */
    private function apply(ProviderPaymentResult $r, ?int $eventId, string $actor): array
    {
        $payment = $this->locatePayment($r);
        if ($payment === null) {
            // Валидное событие, но платёж не наш / неизвестен: не ошибка доставки, повтор не поможет.
            if ($eventId !== null) {
                $this->exec(
                    "UPDATE %i SET processed_at = UTC_TIMESTAMP(6), error_message = 'payment_not_found', processing_status = 'ignored'
                      WHERE id = %d AND processing_status IN ('received', 'failed')",
                    $this->db->table('book_payment_events'),
                    $eventId
                );
            }
            $this->audit->record('payment.unmatched_result', 'payment_event', $eventId ?? 0, null, $r->status->value, [
                'provider' => $r->provider, 'provider_payment_id' => $r->providerPaymentId,
            ], $actor);

            return ['ignored', 'payment_not_found'];
        }
        $orderId = (int) $payment['order_id'];
        $paymentId = (int) $payment['id'];
        // ID для блокировки — обычным SELECT; позиции заказа неизменны после checkout.
        $itemIds = $this->ids($this->col(
            'SELECT book_item_id FROM %i WHERE order_id = %d ORDER BY book_item_id',
            $this->db->table('book_order_items'),
            $orderId
        ));

        $res = $this->db->transaction(function () use ($r, $eventId, $actor, $paymentId, $orderId, $itemIds): array {
            // Db::transaction() может повторить замыкание после deadlock: состояние сбрасываем.
            $this->afterCommit = [];
            $this->sessionsToCancel = [];
            $this->actor = $actor;
            $this->currentEventId = $eventId;

            return $this->applyTx($r, $eventId, $paymentId, $orderId, $itemIds);
        });

        $this->enqueueAfterCommit();
        $this->cancelSessionsAfterCommit();

        return $res;
    }

    /**
     * @param list<int> $itemIds
     * @return array{0: string, 1: string}
     */
    private function applyTx(ProviderPaymentResult $r, ?int $eventId, int $paymentId, int $orderId, array $itemIds): array
    {
        $t = $this->tables();

        // (2) Экземпляры заказа по возрастанию id.
        $items = $itemIds === [] ? [] : $this->rowsById(
            'SELECT id, availability_status, source_status FROM %i WHERE id IN (' . $this->in($itemIds) . ') ORDER BY id FOR UPDATE',
            $t['items'],
            ...$itemIds
        );
        // (5) Заказ.
        $order = $this->row(
            'SELECT id, user_id, public_order_id, status, total_amount, currency, refunded_amount, needs_attention, attention_reason
               FROM %i WHERE id = %d FOR UPDATE',
            $t['orders'],
            $orderId
        );
        // (6) Все платежи заказа: нужны для проверки дубля и «последней попытки».
        $payments = $this->rowsById(
            'SELECT id, attempt_no, status, amount, currency, provider_payment_id, idempotency_key
               FROM %i WHERE order_id = %d ORDER BY id FOR UPDATE',
            $t['payments'],
            $orderId
        );
        // (7) Событие: параллельная доставка того же события могла уже применить его.
        if ($eventId !== null) {
            $evState = $this->scalar('SELECT processing_status FROM %i WHERE id = %d FOR UPDATE', $t['events'], $eventId);
            if ($evState === 'processed' || $evState === 'ignored') {
                return ['duplicate', 'already processed'];
            }
        }
        if ($order === null || !isset($payments[$paymentId])) {
            throw new \RuntimeException('order/payment vanished');
        }
        $p = $payments[$paymentId];

        // Сверка ссылок: банк должен говорить о ЭТОМ заказе и ЭТОЙ попытке.
        if (($r->publicOrderId !== null && $r->publicOrderId !== $order['public_order_id'])
            || ($r->idempotencyKey !== null && $r->idempotencyKey !== $p['idempotency_key'])) {
            $this->flagOrder($order, 'reference_mismatch');
            $outcome = ['processed', 'reference_mismatch'];
        } else {
            $outcome = match ($r->status->value) {
                'succeeded' => $this->applySucceeded($r, $order, $payments, $paymentId, $items),
                'pending', 'processing' => $this->applyInFlight($r, $order, $payments, $paymentId),
                'failed', 'cancelled', 'expired' => $this->applyFailure($r, $order, $payments, $paymentId),
                'refunded', 'partially_refunded' => $this->applyRefund($r, $order, $payments, $paymentId),
                default => ['ignored', 'unsupported_status'],
            };
        }

        if ($eventId !== null) {
            $this->exec(
                "UPDATE %i
                    SET payment_id = %d, order_id = %d, processed_at = UTC_TIMESTAMP(6),
                        error_message = NULLIF(%s, ''), processing_status = %s
                  WHERE id = %d AND processing_status IN ('received', 'failed')",
                $t['events'],
                $paymentId,
                $orderId,
                $outcome[1],
                $outcome[0] === 'processed' ? 'processed' : 'ignored',
                $eventId
            );
        }

        return $outcome;
    }

    /**
     * Успех: продажа экземпляров + заказ paid; дубль; поздний платёж (re-acquire или конфликт).
     *
     * @param array<string, mixed>             $order
     * @param array<int, array<string, mixed>> $payments
     * @param array<int, array<string, mixed>> $items
     * @return array{0: string, 1: string}
     */
    private function applySucceeded(ProviderPaymentResult $r, array &$order, array &$payments, int $paymentId, array $items): array
    {
        $t = $this->tables();
        $p = &$payments[$paymentId];
        $orderId = (int) $order['id'];

        // Сверка суммы и валюты с нашим платежом (= сумма заказа на момент попытки).
        if ($r->amount === null || $r->currency === null || $r->amount !== (int) $p['amount'] || $r->currency !== $p['currency']) {
            $this->exec('UPDATE %i SET provider_status = %s WHERE id = %d', $t['payments'], $r->providerStatus, $paymentId);
            $this->flagOrder($order, 'amount_mismatch');

            return ['processed', 'amount_mismatch'];
        }
        if (in_array($p['status'], self::MONEY_RECEIVED, true)) {
            return ['ignored', 'already_succeeded']; // повтор успеха: статусы уже такие
        }

        $this->movePayment($p, 'succeeded', $r);

        // Второй успешный платёж по уже оплаченному заказу: деньги вернуть, заказ не трогать.
        $otherSucceeded = false;
        foreach ($payments as $id => $q) {
            if ($id !== $paymentId && in_array($q['status'], self::MONEY_RECEIVED, true)) {
                $otherSucceeded = true;
            }
        }
        if ($otherSucceeded || in_array($order['status'], self::PAID_ORDER_STATUSES, true)) {
            $this->flagOrder($order, 'duplicate_payment');
            $this->afterCommit[] = [self::HOOK_REFUND, ['payment_id' => $paymentId, 'amount' => (int) $p['amount'], 'reason' => 'duplicate_payment']];

            return ['processed', 'duplicate_payment'];
        }

        // Поздний платёж: заказ уже payment_expired/cancelled, экземпляры освобождены.
        $late = in_array($order['status'], ['payment_expired', 'cancelled'], true);
        $activeReserved = [];
        if ($late && $items !== []) {
            // Обычное чтение: экземпляры заблокированы нами, новый резерв на них сейчас невозможен.
            $ids = array_keys($items);
            $activeReserved = array_map('intval', $this->col(
                'SELECT active_book_item_id FROM %i WHERE active_book_item_id IN (' . $this->in($ids) . ')',
                $t['reservations'],
                ...$ids
            ));
        }
        $sellable = [];
        $conflict = [];
        foreach ($items as $id => $it) {
            $ok = $late
                ? in_array($it['availability_status'], self::FREE_ITEM_STATUSES, true) && !in_array($id, $activeReserved, true)
                : $it['availability_status'] === 'checkout_pending';
            if ($ok) {
                $sellable[] = (int) $id;
            } else {
                $conflict[] = (int) $id;
            }
        }

        if ($sellable !== []) {
            $from = $late ? self::FREE_ITEM_STATUSES : ['checkout_pending'];
            $this->expectAffected($this->exec(
                "UPDATE %i
                    SET sold_at = UTC_TIMESTAMP(6), status_changed_at = UTC_TIMESTAMP(6), availability_status = 'sold'
                  WHERE id IN (" . $this->in($sellable) . ') AND availability_status IN (' . implode(',', array_fill(0, count($from), '%s')) . ')',
                $t['items'],
                ...$sellable,
                ...$from
            ), count($sellable), 'items → sold');
            // UNIQUE(book_item_id) и UNIQUE(order_item_id) — второй рубеж от двойной продажи.
            $this->expectAffected($this->exec(
                'INSERT INTO %i
                   (book_item_id, book_record_id, order_id, order_item_id, payment_id, user_id, sold_at, price_amount, currency)
                 SELECT oi.book_item_id, oi.book_record_id, oi.order_id, oi.id, %d, o.user_id, UTC_TIMESTAMP(6),
                        oi.unit_price_amount, oi.currency
                   FROM %i oi JOIN %i o ON o.id = oi.order_id
                  WHERE oi.order_id = %d AND oi.book_item_id IN (' . $this->in($sellable) . ')
                  ORDER BY oi.book_item_id',
                $t['sales'],
                $paymentId,
                $t['order_items'],
                $t['orders'],
                $orderId,
                ...$sellable
            ), count($sellable), 'sales insert');
            foreach ($sellable as $id) {
                $this->audit->record('item.status_changed', 'item', $id, (string) $items[$id]['availability_status'], 'sold', [
                    'order_id' => $orderId, 'payment_id' => $paymentId, 'late_payment' => $late,
                ], $this->actor);
            }
        }

        $this->moveOrder($order, 'paid', ['payment_id' => $paymentId, 'late_payment' => $late]);
        $this->closeOtherPendingAttempts($payments, $paymentId);

        if ($conflict !== []) {
            // Часть экземпляров ушла другим: продаём свободные, остальное — возврат. Деньги не теряются.
            $refund = $sellable === []
                ? (int) $p['amount']
                : (int) $this->scalar(
                    'SELECT COALESCE(SUM(unit_price_amount), 0) FROM %i WHERE order_id = %d AND book_item_id IN (' . $this->in($conflict) . ')',
                    $t['order_items'],
                    $orderId,
                    ...$conflict
                );
            $this->flagOrder($order, 'late_payment_conflict', ['conflict_item_ids' => $conflict, 'refund_amount' => $refund]);
            $this->afterCommit[] = [self::HOOK_REFUND, ['payment_id' => $paymentId, 'amount' => $refund, 'reason' => 'late_payment_conflict']];
        }
        if ($sellable !== []) {
            $this->afterCommit[] = [self::HOOK_ORDER_PAID, ['order_id' => $orderId]];
        }

        return ['processed', $late ? ($conflict === [] ? 'late_payment_reacquired' : 'late_payment_conflict') : ''];
    }

    /**
     * pending / processing: только продвижение вперёд; опоздавшие события игнорируются.
     *
     * @param array<string, mixed>             $order
     * @param array<int, array<string, mixed>> $payments
     * @return array{0: string, 1: string}
     */
    private function applyInFlight(ProviderPaymentResult $r, array &$order, array &$payments, int $paymentId): array
    {
        $to = $r->status->value;
        if (!$this->movePayment($payments[$paymentId], $to, $r)) {
            return ['ignored', 'stale_or_repeated_status'];
        }
        if ($this->isLatestAttempt($payments, $paymentId)) {
            if ($to === 'pending' && in_array($order['status'], ['draft', 'payment_failed'], true)) {
                $this->moveOrder($order, 'pending_payment', ['payment_id' => $paymentId]);
            }
            if ($to === 'processing' && in_array($order['status'], ['draft', 'pending_payment', 'payment_failed'], true)) {
                $this->moveOrder($order, 'payment_processing', ['payment_id' => $paymentId]);
            }
        }

        return ['processed', ''];
    }

    /**
     * failed / cancelled / expired от банка. Экземпляры остаются checkout_pending до payment_due_at:
     * покупатель может повторить оплату (POST /orders/{id}/pay), освобождает OrderExpiryService.
     *
     * @param array<string, mixed>             $order
     * @param array<int, array<string, mixed>> $payments
     * @return array{0: string, 1: string}
     */
    private function applyFailure(ProviderPaymentResult $r, array &$order, array &$payments, int $paymentId): array
    {
        if (!$this->movePayment($payments[$paymentId], $r->status->value, $r)) {
            return ['ignored', 'stale_or_repeated_status']; // например, cancelled после нашей отмены заказа
        }
        if ($this->isLatestAttempt($payments, $paymentId)
            && in_array($order['status'], ['draft', 'pending_payment', 'payment_processing'], true)) {
            $this->moveOrder($order, 'payment_failed', ['payment_id' => $paymentId, 'failure_code' => $r->failureCode]);
        }

        return ['processed', ''];
    }

    /**
     * Подтверждение возврата. Статус заказа меняется только возвратом «основного» платежа (того, что
     * оплатил заказ); возврат дублирующего платежа меняет только сам платёж.
     *
     * @param array<string, mixed>             $order
     * @param array<int, array<string, mixed>> $payments
     * @return array{0: string, 1: string}
     */
    private function applyRefund(ProviderPaymentResult $r, array &$order, array &$payments, int $paymentId): array
    {
        $t = $this->tables();
        $to = $r->status->value;
        $moved = $this->movePayment($payments[$paymentId], $to, $r);
        $grows = $payments[$paymentId]['status'] === 'partially_refunded' && $to === 'partially_refunded';
        if (!$moved && !$grows) {
            return ['ignored', 'stale_or_repeated_status'];
        }
        if ($r->refundedAmount === null || $this->primaryPaymentId((int) $order['id'], $payments) !== $paymentId) {
            return ['processed', 'non_primary_refund'];
        }

        $refunded = min((int) $order['total_amount'], $r->refundedAmount);
        $this->exec(
            'UPDATE %i SET refunded_amount = GREATEST(refunded_amount, %d) WHERE id = %d',
            $t['orders'],
            $refunded,
            (int) $order['id']
        );
        $target = $refunded >= (int) $order['total_amount'] ? 'refunded' : 'partially_refunded';
        $this->moveOrder($order, $target, ['payment_id' => $paymentId, 'refunded_amount' => $refunded]);
        if ($target === 'refunded') {
            // Экземпляр остаётся sold: возврат денег не возвращает книгу в продажу.
            $this->exec(
                'UPDATE %i SET refunded_at = COALESCE(refunded_at, UTC_TIMESTAMP(6)) WHERE payment_id = %d',
                $t['sales'],
                $paymentId
            );
        }

        return ['processed', ''];
    }

    // =================================================================================================
    // Переходы (условный UPDATE + проверка числа строк + аудит)
    // =================================================================================================

    /** @param array<string, mixed> $p Строка платежа (обновляется по ссылке). */
    private function movePayment(array &$p, string $to, ProviderPaymentResult $r): bool
    {
        $from = (string) $p['status'];
        if ($from === $to) {
            return false;
        }
        // created → (succeeded|processing|cancelled|expired): контракт не знает прямого перехода — через pending
        // (webhook пришёл раньше, чем Tx2 checkout записал pending).
        if ($from === 'created' && !in_array($to, ['pending', 'failed'], true)) {
            if (!$this->movePayment($p, 'pending', $r)) {
                return false;
            }
            $from = 'pending';
        }
        if (!in_array($from, self::PAYMENT_FROM[$to] ?? [], true)) {
            return false;
        }
        $isFailure = in_array($to, ['failed', 'cancelled', 'expired'], true) ? 1 : 0;
        $this->expectAffected($this->exec(
            "UPDATE %i
                SET provider_payment_id = COALESCE(provider_payment_id, NULLIF(%s, '')),
                    provider_status = %s,
                    card_brand = COALESCE(NULLIF(%s, ''), card_brand),
                    card_last4 = COALESCE(NULLIF(%s, ''), card_last4),
                    failure_code = IF(%d = 1, NULLIF(%s, ''), failure_code),
                    failure_message = IF(%d = 1, NULLIF(%s, ''), failure_message),
                    succeeded_at = IF(%s = 'succeeded', COALESCE(succeeded_at, UTC_TIMESTAMP(6)), succeeded_at),
                    status = %s
              WHERE id = %d AND status = %s",
            $this->db->table('book_payments'),
            $r->providerPaymentId ?? '',
            $r->providerStatus,
            $r->cardBrand ?? '',
            $r->cardLast4 ?? '',
            $isFailure,
            $r->failureCode ?? '',
            $isFailure,
            $r->failureMessage ?? '',
            $to,
            $to,
            (int) $p['id'],
            $from
        ), 1, "payment $from → $to");
        $this->audit->record('payment.status_changed', 'payment', (int) $p['id'], $from, $to, [
            'provider_status' => $r->providerStatus,
            'event_id' => $this->currentEventId,
            'late_confirmation' => in_array($from, ['failed', 'expired', 'cancelled'], true),
        ], $this->actor);
        $p['status'] = $to;

        return true;
    }

    /**
     * @param array<string, mixed> $order Строка заказа (обновляется по ссылке).
     * @param array<string, mixed> $context
     */
    private function moveOrder(array &$order, string $to, array $context = []): bool
    {
        $from = (string) $order['status'];
        if ($from === $to) {
            return false;
        }
        // draft → paid/processing и payment_failed → processing — через pending_payment (две записи аудита).
        if (($from === 'draft' && in_array($to, ['paid', 'payment_processing'], true))
            || ($from === 'payment_failed' && $to === 'payment_processing')) {
            $this->moveOrder($order, 'pending_payment', $context);
            $from = 'pending_payment';
        }
        if (!in_array($from, self::ORDER_FROM[$to] ?? [], true)) {
            return false;
        }
        $this->expectAffected($this->exec(
            "UPDATE %i
                SET paid_at = IF(%s = 'paid', COALESCE(paid_at, UTC_TIMESTAMP(6)), paid_at),
                    status = %s
              WHERE id = %d AND status = %s",
            $this->db->table('book_orders'),
            $to,
            $to,
            (int) $order['id'],
            $from
        ), 1, "order $from → $to");
        $this->audit->record('order.status_changed', 'order', (int) $order['id'], $from, $to, $context + ['event_id' => $this->currentEventId], $this->actor);
        $order['status'] = $to;

        return true;
    }

    /**
     * @param array<string, mixed> $order
     * @param array<string, mixed> $context
     */
    private function flagOrder(array &$order, string $reason, array $context = []): void
    {
        $changed = $this->exec(
            'UPDATE %i SET attention_reason = %s, needs_attention = 1 WHERE id = %d',
            $this->db->table('book_orders'),
            $reason,
            (int) $order['id']
        );
        if ($changed === 0) {
            return; // уже на разборе по той же причине: без повторного аудита и уведомления
        }
        $order['needs_attention'] = 1;
        $order['attention_reason'] = $reason;
        $this->audit->record('order.needs_attention', 'order', (int) $order['id'], null, null, $context + [
            'reason' => $reason, 'event_id' => $this->currentEventId,
        ], $this->actor);
        $this->afterCommit[] = [self::HOOK_ATTENTION, ['order_id' => (int) $order['id'], 'reason' => $reason]];
    }

    /**
     * Заказ оплачен: другие живые попытки (pending) закрываем у себя и после COMMIT — у банка,
     * чтобы покупатель не оплатил второй раз из соседней вкладки. Если всё же оплатит — duplicate_payment.
     *
     * @param array<int, array<string, mixed>> $payments
     */
    private function closeOtherPendingAttempts(array &$payments, int $paidPaymentId): void
    {
        foreach ($payments as $id => $q) {
            if ($id === $paidPaymentId || $q['status'] !== 'pending') {
                continue;
            }
            $this->expectAffected($this->exec(
                "UPDATE %i SET status = 'cancelled' WHERE id = %d AND status = 'pending'",
                $this->db->table('book_payments'),
                (int) $id
            ), 1, 'other attempt pending → cancelled');
            $this->audit->record('payment.status_changed', 'payment', (int) $id, 'pending', 'cancelled', [
                'reason' => 'order_paid_by_other_attempt', 'paid_payment_id' => $paidPaymentId,
            ], $this->actor);
            $payments[$id]['status'] = 'cancelled';
            if ($q['provider_payment_id'] !== null) {
                $this->sessionsToCancel[] = $q;
            }
        }
    }

    /** @param array<int, array<string, mixed>> $payments */
    private function isLatestAttempt(array $payments, int $paymentId): bool
    {
        $max = max(array_map(static fn (array $p): int => (int) $p['attempt_no'], $payments));

        return (int) $payments[$paymentId]['attempt_no'] === $max;
    }

    /**
     * Платёж, которым оплачен заказ: тот, на который ссылаются продажи; если продаж нет
     * (поздний платёж без свободных экземпляров) — первый платёж с полученными деньгами.
     *
     * @param array<int, array<string, mixed>> $payments
     */
    private function primaryPaymentId(int $orderId, array $payments): ?int
    {
        $fromSales = $this->scalar(
            'SELECT payment_id FROM %i WHERE order_id = %d ORDER BY id LIMIT 1',
            $this->db->table('book_sales'),
            $orderId
        );
        if ($fromSales !== null) {
            return (int) $fromSales;
        }
        foreach ($payments as $id => $p) { // ORDER BY id
            if (in_array($p['status'], self::MONEY_RECEIVED, true)) {
                return (int) $id;
            }
        }

        return null;
    }

    // =================================================================================================
    // Inbox, поиск платежа, задачи после COMMIT
    // =================================================================================================

    /** INSERT … ON DUPLICATE KEY UPDATE: новая строка или attempts + 1; возвращает id строки. */
    private function storeEvent(ProviderPaymentResult $event, string $rawBody): int
    {
        $payload = wp_json_encode(self::redact($event->redactedPayload));
        // LAST_INSERT_ID(id) в ветке дубля: $wpdb->insert_id вернёт id существующей строки.
        $this->exec(
            "INSERT INTO %i
               (provider, provider_event_id, event_type, processing_status, payload_redacted, payload_sha256, received_at, attempts)
             VALUES (%s, %s, %s, 'received', %s, %s, UTC_TIMESTAMP(6), 1)
             ON DUPLICATE KEY UPDATE attempts = LEAST(attempts + 1, 65535), id = LAST_INSERT_ID(id)",
            $this->db->table('book_payment_events'),
            $event->provider,
            $event->eventId ?? hash('sha256', $rawBody),
            $event->eventType ?? $event->status->value,
            is_string($payload) ? $payload : '{}',
            hash('sha256', $rawBody)
        );
        $id = (int) $this->wpdb->insert_id;
        if ($id <= 0) {
            throw new \RuntimeException('payment event insert returned no id');
        }

        return $id;
    }

    private function markEventFailed(int $eventId, string $error): void
    {
        $this->exec(
            "UPDATE %i SET error_message = %s, processing_status = 'failed'
              WHERE id = %d AND processing_status IN ('received', 'failed')",
            $this->db->table('book_payment_events'),
            mb_substr($error, 0, 500),
            $eventId
        );
    }

    /** @return ?array<string, mixed> */
    private function locatePayment(ProviderPaymentResult $r): ?array
    {
        $table = $this->db->table('book_payments');
        if ($r->providerPaymentId !== null) {
            $row = $this->row(
                'SELECT id, order_id, idempotency_key, provider_payment_id FROM %i WHERE provider = %s AND provider_payment_id = %s',
                $table,
                $r->provider,
                $r->providerPaymentId
            );
            if ($row !== null) {
                return $row;
            }
        }
        if ($r->idempotencyKey !== null) {
            // Событие обогнало Tx2 checkout: provider_payment_id ещё не записан.
            return $this->row(
                'SELECT id, order_id, idempotency_key, provider_payment_id FROM %i WHERE idempotency_key = %s AND provider = %s',
                $table,
                $r->idempotencyKey,
                $r->provider
            );
        }

        return null;
    }

    private function enqueueAfterCommit(): void
    {
        $actions = $this->afterCommit;
        $this->afterCommit = [];
        foreach ($actions as [$hook, $args]) {
            if (!function_exists('as_enqueue_async_action')) {
                error_log('[uniundata] Action Scheduler is not loaded; action skipped: ' . $hook);
                continue;
            }
            // unique = true: одинаковая задача с теми же аргументами не ставится дважды.
            // Обработчики идемпотентны (проверяют аудит / статус возврата), т.к. AS даёт at-least-once.
            as_enqueue_async_action($hook, $args, self::AS_GROUP, true);
        }
    }

    private function cancelSessionsAfterCommit(): void
    {
        $sessions = $this->sessionsToCancel;
        $this->sessionsToCancel = [];
        foreach ($sessions as $q) {
            try {
                $this->provider->cancelSession((string) $q['provider_payment_id'], (string) $q['idempotency_key']);
            } catch (\Throwable $e) {
                error_log(sprintf('[uniundata] cancelSession failed for payment #%d: %s', (int) $q['id'], $e::class));
            }
        }
    }

    /** Невалидная подпись: в аудит не чаще раза в минуту (защита от заливки таблицы), тело не пишем. */
    private function noteRejectedWebhook(string $reason): void
    {
        error_log('[uniundata] webhook rejected: ' . $reason);
        if (function_exists('wp_cache_add') && !wp_cache_add('webhook_rejected_' . intdiv(time(), 60), 1, 'uniundata', 120)) {
            return;
        }
        $this->audit->record('payment.webhook_rejected', 'payment_event', 0, null, null, ['reason' => $reason], 'webhook');
    }

    // =================================================================================================
    // SQL-хелперы
    // =================================================================================================

    /** @return array<string, string> */
    private function tables(): array
    {
        return [
            'items' => $this->db->table('book_items'),
            'reservations' => $this->db->table('book_reservations'),
            'orders' => $this->db->table('book_orders'),
            'order_items' => $this->db->table('book_order_items'),
            'payments' => $this->db->table('book_payments'),
            'events' => $this->db->table('book_payment_events'),
            'sales' => $this->db->table('book_sales'),
        ];
    }

    /** @return ?array<string, mixed> */
    private function row(string $sql, mixed ...$args): ?array
    {
        $r = $this->wpdb->get_row($this->wpdb->prepare($sql, ...$args), ARRAY_A);
        $this->assertNoDbError();

        return is_array($r) ? $r : null;
    }

    /** @return array<int, array<string, mixed>> */
    private function rowsById(string $sql, mixed ...$args): array
    {
        $r = $this->wpdb->get_results($this->wpdb->prepare($sql, ...$args), ARRAY_A);
        $this->assertNoDbError();
        $out = [];
        foreach (is_array($r) ? $r : [] as $row) {
            $out[(int) $row['id']] = $row;
        }

        return $out;
    }

    /** @return list<string> */
    private function col(string $sql, mixed ...$args): array
    {
        $r = $this->wpdb->get_col($this->wpdb->prepare($sql, ...$args));
        $this->assertNoDbError();

        return array_values(array_map('strval', $r));
    }

    private function scalar(string $sql, mixed ...$args): ?string
    {
        $r = $this->wpdb->get_var($this->wpdb->prepare($sql, ...$args));
        $this->assertNoDbError();

        return $r === null ? null : (string) $r;
    }

    private function exec(string $sql, mixed ...$args): int
    {
        $r = $this->wpdb->query($this->wpdb->prepare($sql, ...$args));
        if ($r === false) {
            throw new \RuntimeException('DB error: ' . $this->wpdb->last_error, $this->db->lastErrno());
        }

        return (int) $r;
    }

    private function assertNoDbError(): void
    {
        if ($this->wpdb->last_error !== '') {
            throw new \RuntimeException('DB error: ' . $this->wpdb->last_error, $this->db->lastErrno());
        }
    }

    private function expectAffected(int $actual, int $expected, string $what): void
    {
        if ($actual !== $expected) {
            throw new \RuntimeException(sprintf('Unexpected affected rows for %s: %d instead of %d', $what, $actual, $expected));
        }
    }

    /** @param array<mixed> $values @return list<int> */
    private function ids(array $values): array
    {
        $ids = array_values(array_unique(array_map('intval', $values)));
        sort($ids);

        return $ids;
    }

    /** @param list<int> $ids */
    private function in(array $ids): string
    {
        return implode(',', array_fill(0, count($ids), '%d'));
    }
}
