<?php

declare(strict_types=1);

namespace Uniundata\Books\Service;

use Uniundata\Books\Domain\DomainError;
use Uniundata\Books\Infrastructure\AuditLog;
use Uniundata\Books\Infrastructure\Db;
use Uniundata\Books\Payment\FiscalReceipt;
use Uniundata\Books\Payment\PaymentProviderInterface;
use Uniundata\Books\Payment\ProviderPaymentResult;
use Uniundata\Books\Payment\ProviderRefundResult;
use Uniundata\Books\Payment\WebhookResult;

/**
 * Приём webhook-ов банка, применение результата платежа (общий путь для webhook и опроса банка) и возвраты.
 *
 * Факт оплаты — ТОЛЬКО серверное подтверждение: webhook с проверенной подписью (и, если включено,
 * подтверждённый server-to-server запросом fetchPayment) или ответ API банка при опросе cron-ом.
 * Return URL браузера оплату не подтверждает.
 *
 * Возвраты: решение (duplicate_payment, late_payment_conflict, решение менеджера) = INSERT в wp_book_refunds
 * со статусом requested В ТОЙ ЖЕ транзакции; после COMMIT — задача Action Scheduler uniundata_refund_payment
 * {refund_id} → processRefund(): запрос к банку вне транзакции с idempotency_key возврата, затем
 * requested → pending|succeeded|failed. На succeeded — payments.refunded_amount, а для основного платежа
 * заказа ещё orders.refunded_amount и статусы partially_refunded|refunded.
 *
 * Порядок блокировок (docs/08): items (asc) → orders → payments → payment_events → refunds.
 * Побочные эффекты (письма, документы, возвраты, уведомления) — только после COMMIT через Action Scheduler.
 */
final class PaymentService
{
    /** Action Scheduler: письмо/документы по оплаченному заказу. Обработчик: fn (int $orderId). */
    public const HOOK_ORDER_PAID = 'uniundata_order_paid';
    /** Возврат денег. Обработчик: fn (int $refundId) => PaymentService::processRefund($refundId). */
    public const HOOK_REFUND = 'uniundata_refund_payment';
    /** Уведомление менеджеру о needs_attention. Обработчик: fn (int $orderId, string $reason). */
    public const HOOK_ATTENTION = 'uniundata_order_needs_attention';
    public const AS_GROUP = 'uniundata';

    /** Допуск по времени подписанного timestamp (защита от replay). */
    public const SIGNATURE_TOLERANCE_SECONDS = 300;
    private const MAX_BODY_BYTES = 65536;

    /** Платёж: допустимые исходные статусы для целевого (контракт v2). Возвраты — completeRefundLocked(). */
    private const PAYMENT_FROM = [
        'pending' => ['created'],
        'processing' => ['pending'],
        'succeeded' => ['pending', 'processing', 'failed', 'expired', 'cancelled'],
        'failed' => ['pending', 'processing'],
        'cancelled' => ['created', 'pending'],
        'expired' => ['created', 'pending', 'processing'],
    ];

    /** Заказ: допустимые исходные статусы для целевого (контракт v2). */
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
    /** Причины разбора, которые держат заказ от автозакрытия: менее важные причины их не перетирают. */
    private const BLOCKING_ATTENTION = ['amount_mismatch', 'reference_mismatch'];
    /** Возвраты по решению менеджера (requestRefund). Автоматические: duplicate_payment, late_payment_conflict. */
    private const MANUAL_REFUND_REASONS = ['order_cancelled', 'customer_return', 'manual'];
    /** Незавершённый возврат старше суток — needs_attention 'refund_stuck' (опрос банка продолжается). */
    private const REFUND_STUCK_SECONDS = 86400;

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
    ) {
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
        if (\strlen($rawBody) > self::MAX_BODY_BYTES) {
            return WebhookResult::badRequest('body too large', 413);
        }
        $headers = array_change_key_case($headers, CASE_LOWER);

        // 1. Подпись и время — ДО любой записи в БД: иначе атакующий мог бы «занять» provider_event_id.
        try {
            $event = $this->provider->verifyWebhook($rawBody, $headers);
        } catch (DomainError $e) {
            $this->noteRejectedWebhook($e->errorCode());

            return WebhookResult::invalidSignature();
        } catch (\InvalidArgumentException) {
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

        // 2. Inbox: идемпотентность по (provider, provider_event_id).
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
        if ($this->confirmWithApi && \in_array($event->status->value, ['succeeded', 'processing', 'refunded', 'partially_refunded'], true)) {
            $payment = $this->locatePayment($event);
            if ($payment !== null) {
                try {
                    $fresh = $this->provider->fetchPayment(
                        $payment['provider_payment_id'] ?? $event->providerPaymentId,
                        (string) $payment['idempotency_key']
                    );
                } catch (\RuntimeException) {
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
                    && !\in_array($fresh->status->value, self::MONEY_RECEIVED, true)) {
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
            error_log(\sprintf('[uniundata] webhook event #%d failed: %s', $eventId, $e::class));

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
            if (\is_array($v)) {
                $out[$k] = self::redact($v, $depth + 1);
            } elseif (\is_string($v)) {
                $v = (string) preg_replace('/\b(?:\d[ -]?){12,18}\d\b/', '[pan-redacted]', $v);
                $v = (string) preg_replace('/[^\s@]+@[^\s@]+\.[^\s@]+/', '[email-redacted]', $v);
                $out[$k] = mb_substr($v, 0, 500);
            } elseif (\is_int($v) || \is_float($v) || \is_bool($v) || $v === null) {
                $out[$k] = $v;
            }
        }

        return $out;
    }

    // =================================================================================================
    // Возвраты
    // =================================================================================================

    /**
     * Возврат по решению менеджера (отмена оплаченного заказа, возврат книги, ручной). Экземпляр остаётся
     * sold: возврат денег не возвращает книгу в продажу. Блокировки: orders → payments → refunds.
     *
     * @param ?int $paymentId Null — основной платёж заказа.
     * @return int wp_book_refunds.id; сам запрос к банку — задача uniundata_refund_payment после COMMIT.
     */
    public function requestRefund(int $orderId, int $amount, string $reason, int $managerId, ?int $paymentId = null): int
    {
        if (!\in_array($reason, self::MANUAL_REFUND_REASONS, true)) {
            throw DomainError::invalidParam('reason', \__('Unknown refund reason.', 'uniundata-books'));
        }
        if ($amount <= 0) {
            throw DomainError::invalidParam('amount', \__('Refund amount must be positive.', 'uniundata-books'));
        }
        $t = $this->tables();
        $refundId = $this->db->transaction(function () use ($t, $orderId, $amount, $reason, $managerId, $paymentId): int {
            $this->resetTxState('admin', null);
            $order = $this->lockOrder($orderId);
            if ($order === null) {
                throw DomainError::orderNotFound();
            }
            $payments = $this->rowsById(
                'SELECT id, attempt_no, status, amount, refunded_amount, currency, provider_payment_id, idempotency_key
                   FROM %i WHERE order_id = %d ORDER BY id FOR UPDATE',
                $t['payments'],
                $orderId
            );
            $pid = $paymentId ?? $this->primaryPaymentId($orderId, $payments);
            $p = $pid !== null ? ($payments[$pid] ?? null) : null;
            if ($p === null || !\in_array($p['status'], ['succeeded', 'partially_refunded'], true) || $p['provider_payment_id'] === null) {
                throw DomainError::invalidParam('payment_id', \__('This payment cannot be refunded.', 'uniundata-books'));
            }

            return $this->createRefundLocked($order, $p, $amount, $reason, $managerId);
        });
        $this->enqueueAfterCommit();

        return $refundId;
    }

    /**
     * Обработчик задачи uniundata_refund_payment {refund_id}. Идемпотентен: финальный возврат — no-op;
     * requested/pending — запрос к банку с тем же idempotency_key (повтор возвращает тот же возврат).
     * Пока результат не финальный, ставит себе следующий запуск (AS, растущий интервал).
     *
     * @return string Статус возврата после обработки: requested|pending|succeeded|failed.
     */
    public function processRefund(int $refundId): string
    {
        $t = $this->tables();
        $ref = $this->row('SELECT id, order_id, payment_id, status FROM %i WHERE id = %d', $t['refunds'], $refundId);
        if ($ref === null) {
            throw new \InvalidArgumentException(\sprintf('Refund #%d not found', $refundId));
        }
        if (\in_array($ref['status'], ['succeeded', 'failed'], true)) {
            return (string) $ref['status'];
        }
        $orderId = (int) $ref['order_id'];
        $paymentId = (int) $ref['payment_id'];

        // Tx1 (orders → payments → refunds): перепроверка под блокировкой и данные для банка.
        $job = $this->db->transaction(function () use ($refundId, $orderId, $paymentId): array {
            $this->resetTxState('system', null);

            return $this->loadRefundJobTx($refundId, $orderId, $paymentId);
        });
        $this->enqueueAfterCommit();
        if (!\in_array($job['status'], ['requested', 'pending'], true)) {
            return (string) $job['status'];
        }

        // HTTP — вне транзакции. Ключ возврата делает повтор вызова безопасным.
        try {
            $res = $this->provider->refund(
                (string) $job['provider_payment_id'],
                (int) $job['amount'],
                (string) $job['currency'],
                (string) $job['idempotency_key'],
                $this->refundReceipt($orderId, $paymentId, (int) $job['amount'])
            );
        } catch (\RuntimeException $e) {
            error_log(\sprintf('[uniundata] refund #%d: provider call failed: %s', $refundId, $e::class));
            $this->scheduleRefundFollowUp($refundId, (int) $job['age_seconds']);

            return (string) $job['status'];
        }

        // Tx2: requested|pending → pending|succeeded|failed.
        $status = $this->db->transaction(function () use ($refundId, $orderId, $paymentId, $res): string {
            $this->resetTxState('system', null);

            return $this->applyRefundResultTx($refundId, $orderId, $paymentId, $res);
        });
        $this->enqueueAfterCommit();
        if ($status === 'pending') {
            $this->scheduleRefundFollowUp($refundId, (int) $job['age_seconds']);
        }

        return $status;
    }

    /** @return array<string, mixed> */
    private function loadRefundJobTx(int $refundId, int $orderId, int $paymentId): array
    {
        $t = $this->tables();
        $order = $this->lockOrder($orderId);
        $payment = $this->row(
            'SELECT id, status, amount, refunded_amount, currency, provider_payment_id FROM %i WHERE id = %d FOR UPDATE',
            $t['payments'],
            $paymentId
        );
        $refund = $this->row(
            'SELECT id, status, amount, currency, reason, idempotency_key,
                    TIMESTAMPDIFF(SECOND, requested_at, UTC_TIMESTAMP(6)) AS age_seconds
               FROM %i WHERE id = %d FOR UPDATE',
            $t['refunds'],
            $refundId
        );
        if ($order === null || $payment === null || $refund === null) {
            throw new \RuntimeException('order/payment/refund vanished');
        }
        if (!\in_array($refund['status'], ['requested', 'pending'], true)) {
            return ['status' => (string) $refund['status']];
        }
        if ($payment['provider_payment_id'] === null || !\in_array($payment['status'], self::MONEY_RECEIVED, true)) {
            // Через API банка этот возврат не провести: закрываем как failed, решение — за менеджером.
            $this->failRefundLocked($refund, $order, 'payment_not_refundable', null);

            return ['status' => 'failed'];
        }
        if ((int) $refund['age_seconds'] >= self::REFUND_STUCK_SECONDS) {
            $this->flagOrder($order, 'refund_stuck', ['refund_id' => $refundId]);
        }

        return [
            'status' => (string) $refund['status'],
            'amount' => (int) $refund['amount'],
            'currency' => (string) $refund['currency'],
            'idempotency_key' => (string) $refund['idempotency_key'],
            'provider_payment_id' => (string) $payment['provider_payment_id'],
            'age_seconds' => (int) $refund['age_seconds'],
        ];
    }

    private function applyRefundResultTx(int $refundId, int $orderId, int $paymentId, ProviderRefundResult $res): string
    {
        $t = $this->tables();
        $order = $this->lockOrder($orderId);
        $payments = $this->rowsById(
            'SELECT id, attempt_no, status, amount, refunded_amount, currency, provider_payment_id, idempotency_key
               FROM %i WHERE order_id = %d ORDER BY id FOR UPDATE',
            $t['payments'],
            $orderId
        );
        $refund = $this->row(
            'SELECT id, status, amount, provider_refund_id FROM %i WHERE id = %d FOR UPDATE',
            $t['refunds'],
            $refundId
        );
        if ($order === null || $refund === null || !isset($payments[$paymentId])) {
            throw new \RuntimeException('order/payment/refund vanished');
        }
        $from = (string) $refund['status'];
        if (!\in_array($from, ['requested', 'pending'], true)) {
            return $from; // webhook возврата успел раньше
        }

        if ($res->status === ProviderRefundResult::SUCCEEDED) {
            $this->completeRefundLocked($refund, $order, $payments, $paymentId, $res->providerRefundId);

            return 'succeeded';
        }
        if ($res->status === ProviderRefundResult::FAILED) {
            $this->failRefundLocked($refund, $order, $res->failureMessage ?? 'provider_declined', $res->providerRefundId);

            return 'failed';
        }
        $this->exec(
            "UPDATE %i SET provider_refund_id = COALESCE(provider_refund_id, %s), status = 'pending'
              WHERE id = %d AND status IN ('requested', 'pending')",
            $t['refunds'],
            $res->providerRefundId,
            $refundId
        );
        if ($from === 'requested') {
            $this->audit->record('refund.status_changed', 'refund', $refundId, 'requested', 'pending', ['order_id' => $orderId], $this->actor);
        }

        return 'pending';
    }

    /**
     * Решение о возврате: строка requested в той же транзакции. Вызывающий держит блокировки заказа
     * и платежа; здесь берётся последний уровень (refunds этого платежа).
     *
     * @param array<string, mixed> $order
     * @param array<string, mixed> $payment
     */
    private function createRefundLocked(array $order, array $payment, int $amount, string $reason, ?int $requestedBy, bool $enqueue = true): int
    {
        $t = $this->tables();
        $reserved = (int) $this->scalar(
            "SELECT COALESCE(SUM(amount), 0) FROM %i WHERE payment_id = %d AND status <> 'failed' FOR UPDATE",
            $t['refunds'],
            (int) $payment['id']
        );
        if ($amount <= 0 || $reserved + $amount > (int) $payment['amount']) {
            throw DomainError::invalidParam('amount', \__('Refund amount exceeds the refundable balance.', 'uniundata-books'));
        }
        $this->exec(
            "INSERT INTO %i (payment_id, order_id, amount, currency, reason, idempotency_key, status, requested_by, requested_at)
             VALUES (%d, %d, %d, %s, %s, %s, 'requested', NULLIF(%d, 0), UTC_TIMESTAMP(6))",
            $t['refunds'],
            (int) $payment['id'],
            (int) $order['id'],
            $amount,
            (string) $payment['currency'],
            $reason,
            wp_generate_uuid4(),
            $requestedBy ?? 0
        );
        $refundId = $this->db->lastInsertId();
        $this->audit->record('refund.requested', 'refund', $refundId, null, 'requested', [
            'order_id' => (int) $order['id'], 'payment_id' => (int) $payment['id'], 'amount' => $amount, 'reason' => $reason,
            'event_id' => $this->currentEventId,
        ], $this->actor, $requestedBy);
        if ($enqueue) {
            $this->afterCommit[] = [self::HOOK_REFUND, ['refund_id' => $refundId]];
        }

        return $refundId;
    }

    /**
     * Возврат прошёл: refund → succeeded; payments.refunded_amount += amount (succeeded → partially_refunded |
     * refunded); для основного платежа — orders.refunded_amount и статус заказа.
     *
     * @param array<string, mixed>             $refund  Заблокированная строка возврата.
     * @param array<string, mixed>             $order
     * @param array<int, array<string, mixed>> $payments
     */
    private function completeRefundLocked(array $refund, array &$order, array &$payments, int $paymentId, ?string $providerRefundId): void
    {
        $t = $this->tables();
        $refundId = (int) $refund['id'];
        $amount = (int) $refund['amount'];
        $this->expectAffected($this->exec(
            "UPDATE %i
                SET provider_refund_id = COALESCE(provider_refund_id, NULLIF(%s, '')), completed_at = UTC_TIMESTAMP(6),
                    failure_message = NULL, status = 'succeeded'
              WHERE id = %d AND status IN ('requested', 'pending')",
            $t['refunds'],
            $providerRefundId ?? '',
            $refundId
        ), 1, 'refund → succeeded');
        $this->audit->record('refund.status_changed', 'refund', $refundId, (string) $refund['status'], 'succeeded', [
            'order_id' => (int) $order['id'], 'payment_id' => $paymentId, 'amount' => $amount, 'event_id' => $this->currentEventId,
        ], $this->actor);

        // Платёж.
        $p = &$payments[$paymentId];
        $payFrom = (string) $p['status'];
        if (!\in_array($payFrom, ['succeeded', 'partially_refunded'], true)) {
            throw new \LogicException(\sprintf('Refund #%d for payment #%d in status %s', $refundId, $paymentId, $payFrom));
        }
        $refundedNow = (int) $p['refunded_amount'] + $amount;
        $payTo = $refundedNow >= (int) $p['amount'] ? 'refunded' : 'partially_refunded';
        $this->expectAffected($this->exec(
            'UPDATE %i SET refunded_amount = refunded_amount + %d, status = %s
              WHERE id = %d AND status = %s AND refunded_amount = %d',
            $t['payments'],
            $amount,
            $payTo,
            $paymentId,
            $payFrom,
            (int) $p['refunded_amount']
        ), 1, "payment refund $payFrom → $payTo");
        $this->audit->record('payment.status_changed', 'payment', $paymentId, $payFrom, $payTo, [
            'refund_id' => $refundId, 'refunded_amount' => $refundedNow,
        ], $this->actor);
        $p['refunded_amount'] = $refundedNow;
        $p['status'] = $payTo;
        if ($payTo === 'refunded') {
            // Экземпляр остаётся sold: возврат денег не возвращает книгу в продажу.
            $this->exec(
                'UPDATE %i SET refunded_at = COALESCE(refunded_at, UTC_TIMESTAMP(6)) WHERE payment_id = %d',
                $t['sales'],
                $paymentId
            );
        }

        // Заказ: только возврат основного платежа (возврат дубля сумму заказа не меняет).
        if ($this->primaryPaymentId((int) $order['id'], $payments) !== $paymentId) {
            return;
        }
        $orderRefunded = (int) $order['refunded_amount'] + $amount;
        $this->expectAffected($this->exec(
            'UPDATE %i SET refunded_amount = refunded_amount + %d WHERE id = %d AND refunded_amount = %d',
            $t['orders'],
            $amount,
            (int) $order['id'],
            (int) $order['refunded_amount']
        ), 1, 'order refunded_amount');
        $order['refunded_amount'] = $orderRefunded;
        $target = $orderRefunded >= (int) $order['total_amount'] ? 'refunded' : 'partially_refunded';
        if ($order['status'] !== $target) {
            $this->moveOrder($order, $target, ['refund_id' => $refundId, 'refunded_amount' => $orderRefunded]);
        }
    }

    /**
     * @param array<string, mixed> $refund
     * @param array<string, mixed> $order
     */
    private function failRefundLocked(array $refund, array &$order, string $message, ?string $providerRefundId): void
    {
        $this->expectAffected($this->exec(
            "UPDATE %i
                SET provider_refund_id = COALESCE(provider_refund_id, NULLIF(%s, '')), completed_at = UTC_TIMESTAMP(6),
                    failure_message = %s, status = 'failed'
              WHERE id = %d AND status IN ('requested', 'pending')",
            $this->db->table('book_refunds'),
            $providerRefundId ?? '',
            mb_substr($message, 0, 255),
            (int) $refund['id']
        ), 1, 'refund → failed');
        $this->audit->record('refund.status_changed', 'refund', (int) $refund['id'], (string) $refund['status'], 'failed', [
            'order_id' => (int) $order['id'], 'reason' => $message,
        ], $this->actor);
        $this->flagOrder($order, 'refund_failed', ['refund_id' => (int) $refund['id']]);
    }

    /**
     * Чек «возврат прихода»: все книги заказа (дубль, полный возврат); иначе книги, не проданные по этому
     * платежу (поздний платёж с конфликтом); иначе одна позиция на сумму возврата.
     */
    private function refundReceipt(int $orderId, int $paymentId, int $amount): ?FiscalReceipt
    {
        $t = $this->tables();
        $order = $this->row(
            'SELECT public_order_id, customer_email, customer_phone, pii_erased_at FROM %i WHERE id = %d',
            $t['orders'],
            $orderId
        );
        $lines = $this->rows(
            'SELECT book_item_id, title_snapshot, unit_price_amount FROM %i WHERE order_id = %d ORDER BY id',
            $t['order_items'],
            $orderId
        );
        if ($order === null || $lines === []) {
            return null;
        }
        $sold = array_map('intval', $this->col(
            'SELECT book_item_id FROM %i WHERE order_id = %d AND payment_id = %d',
            $t['sales'],
            $orderId,
            $paymentId
        ));
        $toLine = static fn (array $r): array => [
            'title' => (string) $r['title_snapshot'], 'amount' => (int) $r['unit_price_amount'], 'book_item_id' => (int) $r['book_item_id'],
        ];
        $all = array_map($toLine, $lines);
        $unsold = array_map($toLine, array_values(array_filter($lines, static fn (array $r): bool => !\in_array((int) $r['book_item_id'], $sold, true))));
        $sum = static fn (array $ls): int => array_sum(array_column($ls, 'amount'));
        $chosen = match (true) {
            $sum($all) === $amount => $all,
            $unsold !== [] && $sum($unsold) === $amount => $unsold,
            default => [[
                /* translators: %s: public order number */
                'title' => \sprintf(\__('Refund for order %s', 'uniundata-books'), (string) $order['public_order_id']),
                'amount' => $amount,
                'book_item_id' => null,
            ]],
        };
        // После обезличивания контактов чек отправить некуда: адаптер получит null и решит сам.
        $email = $order['pii_erased_at'] === null ? (string) $order['customer_email'] : null;
        $phone = $order['pii_erased_at'] === null ? $order['customer_phone'] : null;
        try {
            return FiscalReceipt::fromLines($chosen, $email, $phone, $orderId, $amount, 'refund');
        } catch (\InvalidArgumentException | \LogicException $e) {
            error_log(\sprintf('[uniundata] refund receipt for order #%d not built: %s', $orderId, $e::class));

            return null;
        }
    }

    /** Следующий опрос незавершённого возврата: 1 мин → 5 мин → 30 мин → 6 ч (по возрасту возврата). */
    private function scheduleRefundFollowUp(int $refundId, int $ageSeconds): void
    {
        if (!\function_exists('as_schedule_single_action')) {
            error_log('[uniundata] Action Scheduler is not loaded; refund follow-up skipped: #' . $refundId);

            return;
        }
        $delay = match (true) {
            $ageSeconds < 600 => 60,
            $ageSeconds < 3600 => 300,
            $ageSeconds < self::REFUND_STUCK_SECONDS => 1800,
            default => 21600,
        };
        // Без unique: текущий (in-progress) запуск той же задачи иначе заблокировал бы постановку.
        as_schedule_single_action(time() + $delay, self::HOOK_REFUND, ['refund_id' => $refundId], self::AS_GROUP);
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
            $this->db->transaction(function () use ($r, $eventId, $actor): void {
                if ($eventId !== null) {
                    $this->exec(
                        "UPDATE %i SET processed_at = UTC_TIMESTAMP(6), error_message = 'payment_not_found', processing_status = 'ignored'
                          WHERE id = %d AND processing_status IN ('received', 'failed')",
                        $this->db->table('book_payment_events'),
                        $eventId
                    );
                }
                $this->audit->record('payment.unmatched_result', 'payment_event', $eventId, null, $r->status->value, [
                    'provider' => $r->provider, 'provider_payment_id' => $r->providerPaymentId,
                ], $actor);
            });

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
            $this->resetTxState($actor, $eventId);

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
        $order = $this->lockOrder($orderId);
        // (6) Все платежи заказа: нужны для проверки дубля, «последней попытки» и основного платежа.
        $payments = $this->rowsById(
            'SELECT id, attempt_no, status, amount, refunded_amount, currency, provider_payment_id, idempotency_key
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
                'refunded', 'partially_refunded' => $this->applyRefundEvent($r, $order, $payments, $paymentId),
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
            $this->flagOrder($order, 'amount_mismatch', ['payment_id' => $paymentId]);

            return ['processed', 'amount_mismatch'];
        }
        if (\in_array($p['status'], self::MONEY_RECEIVED, true)) {
            return ['ignored', 'already_succeeded']; // повтор успеха: статусы уже такие
        }

        $this->movePayment($p, 'succeeded', $r);

        // Второй успешный платёж по уже оплаченному заказу: деньги вернуть, заказ не трогать.
        $otherSucceeded = false;
        foreach ($payments as $id => $q) {
            if ($id !== $paymentId && \in_array($q['status'], self::MONEY_RECEIVED, true)) {
                $otherSucceeded = true;
            }
        }
        if ($otherSucceeded || \in_array($order['status'], self::PAID_ORDER_STATUSES, true)) {
            $this->flagOrder($order, 'duplicate_payment', ['payment_id' => $paymentId]);
            $this->createRefundLocked($order, $p, (int) $p['amount'], 'duplicate_payment', null);

            return ['processed', 'duplicate_payment'];
        }

        // Поздний платёж: заказ уже payment_expired/cancelled, экземпляры освобождены.
        $late = \in_array($order['status'], ['payment_expired', 'cancelled'], true);
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
        $sourceCheck = [];
        foreach ($items as $id => $it) {
            $ok = $late
                ? \in_array($it['availability_status'], self::FREE_ITEM_STATUSES, true) && !\in_array($id, $activeReserved, true)
                : $it['availability_status'] === 'checkout_pending';
            if (!$ok) {
                $conflict[] = (int) $id;
                continue;
            }
            $sellable[] = (int) $id;
            if ($late && $it['source_status'] !== 'present') {
                $sourceCheck[] = (int) $id; // источник говорит «нет/снят»: книгу нужно проверить физически
            }
        }

        if ($sellable !== []) {
            $from = $late ? self::FREE_ITEM_STATUSES : ['checkout_pending'];
            $this->expectAffected($this->exec(
                "UPDATE %i
                    SET sold_at = UTC_TIMESTAMP(6), status_changed_at = UTC_TIMESTAMP(6), availability_status = 'sold'
                  WHERE id IN (" . $this->in($sellable) . ') AND availability_status IN (' . implode(',', array_fill(0, \count($from), '%s')) . ')',
                $t['items'],
                ...$sellable,
                ...$from
            ), \count($sellable), 'items → sold');
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
            ), \count($sellable), 'sales insert');
            foreach ($sellable as $id) {
                $this->audit->record('item.status_changed', 'item', $id, (string) $items[$id]['availability_status'], 'sold', [
                    'order_id' => $orderId, 'payment_id' => $paymentId, 'late_payment' => $late,
                ], $this->actor);
            }
        }

        $this->moveOrder($order, 'paid', ['payment_id' => $paymentId, 'late_payment' => $late]);
        $this->closeOtherOpenAttempts($payments, $paymentId);

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
            $this->flagOrder($order, 'late_payment_conflict', [
                'conflict_item_ids' => $conflict, 'refund_amount' => $refund, 'source_check_item_ids' => $sourceCheck,
            ]);
            $this->createRefundLocked($order, $p, $refund, 'late_payment_conflict', null);
        } elseif ($sourceCheck !== []) {
            $this->flagOrder($order, 'late_payment_source_check', ['source_check_item_ids' => $sourceCheck]);
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
            if ($to === 'pending' && \in_array($order['status'], ['draft', 'payment_failed'], true)) {
                $this->moveOrder($order, 'pending_payment', ['payment_id' => $paymentId]);
            }
            if ($to === 'processing' && \in_array($order['status'], ['draft', 'pending_payment', 'payment_failed'], true)) {
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
            && \in_array($order['status'], ['draft', 'pending_payment', 'payment_processing'], true)) {
            $this->moveOrder($order, 'payment_failed', ['payment_id' => $paymentId, 'failure_code' => $r->failureCode]);
        }

        return ['processed', ''];
    }

    /**
     * Событие возврата от банка (refunded / partially_refunded; refundedAmount — накопленная сумма).
     * Сопоставление со строкой wp_book_refunds: по provider_refund_id / ключу возврата; иначе по приросту
     * суммы — открытый возврат на ту же сумму; иначе возврат сделан мимо плагина (личный кабинет банка) и
     * записывается в журнал как reason='manual'. Итоговые суммы — completeRefundLocked().
     *
     * @param array<string, mixed>             $order
     * @param array<int, array<string, mixed>> $payments
     * @return array{0: string, 1: string}
     */
    private function applyRefundEvent(ProviderPaymentResult $r, array &$order, array &$payments, int $paymentId): array
    {
        $t = $this->tables();
        $p = $payments[$paymentId];
        if (!\in_array($p['status'], self::MONEY_RECEIVED, true)) {
            $this->flagOrder($order, 'refund_unexpected', ['payment_id' => $paymentId]);

            return ['processed', 'refund_for_unpaid_payment'];
        }
        // (8) Возвраты платежа.
        $refunds = $this->rowsById(
            'SELECT id, status, amount, idempotency_key, provider_refund_id FROM %i WHERE payment_id = %d ORDER BY id FOR UPDATE',
            $t['refunds'],
            $paymentId
        );
        $match = null;
        foreach ($refunds as $rf) {
            if (($r->providerRefundId !== null && $rf['provider_refund_id'] === $r->providerRefundId)
                || ($r->refundIdempotencyKey !== null && $rf['idempotency_key'] === $r->refundIdempotencyKey)) {
                $match = $rf;
                break;
            }
        }
        if ($match === null) {
            $delta = $r->refundedAmount === null ? 0 : $r->refundedAmount - (int) $p['refunded_amount'];
            if ($delta <= 0) {
                return ['ignored', 'stale_or_repeated_refund'];
            }
            foreach ($refunds as $rf) {
                if (\in_array($rf['status'], ['requested', 'pending'], true) && (int) $rf['amount'] === $delta) {
                    $match = $rf;
                    break;
                }
            }
            if ($match === null) {
                if ($delta > (int) $p['amount'] - (int) $p['refunded_amount']) {
                    $this->flagOrder($order, 'refund_mismatch', ['payment_id' => $paymentId, 'bank_refunded_amount' => $r->refundedAmount]);

                    return ['processed', 'refund_mismatch'];
                }
                $refundId = $this->createRefundLocked($order, $p, $delta, 'manual', null, false);
                $match = ['id' => $refundId, 'status' => 'requested', 'amount' => $delta];
            }
        }
        if (!\in_array($match['status'], ['requested', 'pending'], true)) {
            return ['ignored', 'refund_already_final'];
        }
        $this->completeRefundLocked($match, $order, $payments, $paymentId, $r->providerRefundId);

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
        // created → succeeded|processing|failed: прямого перехода нет — через pending (событие банка по сессии
        // пришло раньше, чем Tx2 checkout записал pending). created → cancelled|expired — напрямую (контракт v2).
        if ($from === 'created' && \in_array($to, ['succeeded', 'processing', 'failed'], true)) {
            if (!$this->movePayment($p, 'pending', $r)) {
                return false;
            }
            $from = 'pending';
        }
        if (!\in_array($from, self::PAYMENT_FROM[$to] ?? [], true)) {
            return false;
        }
        $isFailure = \in_array($to, ['failed', 'cancelled', 'expired'], true) ? 1 : 0;
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
            'late_confirmation' => \in_array($from, ['failed', 'expired', 'cancelled'], true),
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
        if (($from === 'draft' && \in_array($to, ['paid', 'payment_processing'], true))
            || ($from === 'payment_failed' && $to === 'payment_processing')) {
            $this->moveOrder($order, 'pending_payment', $context);
            $from = 'pending_payment';
        }
        if (!\in_array($from, self::ORDER_FROM[$to] ?? [], true)) {
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
     * needs_attention + причина + уведомление менеджеру после COMMIT. Блокирующую причину (amount/reference
     * mismatch держит заказ от автозакрытия) менее важная не перетирает; история причин — в аудите.
     *
     * @param array<string, mixed> $order
     * @param array<string, mixed> $context
     */
    private function flagOrder(array &$order, string $reason, array $context = []): void
    {
        $current = $order['attention_reason'] ?? null;
        $flagged = (int) $order['needs_attention'] === 1;
        if ($flagged && $current === $reason) {
            return; // уже на разборе по той же причине: без повторного аудита и уведомления
        }
        $stored = $flagged && \in_array($current, self::BLOCKING_ATTENTION, true) && !\in_array($reason, self::BLOCKING_ATTENTION, true)
            ? (string) $current
            : $reason;
        $this->exec(
            'UPDATE %i SET attention_reason = %s, needs_attention = 1 WHERE id = %d',
            $this->db->table('book_orders'),
            $stored,
            (int) $order['id']
        );
        $order['needs_attention'] = 1;
        $order['attention_reason'] = $stored;
        $this->audit->record('order.needs_attention', 'order', (int) $order['id'], null, null, $context + [
            'reason' => $reason, 'previous_reason' => $current, 'event_id' => $this->currentEventId,
        ], $this->actor);
        $this->afterCommit[] = [self::HOOK_ATTENTION, ['order_id' => (int) $order['id'], 'reason' => $reason]];
    }

    /**
     * Заказ оплачен: другие открытые попытки (created/pending) закрываем у себя и после COMMIT — у банка,
     * чтобы покупатель не оплатил второй раз из соседней вкладки. Если всё же оплатит — duplicate_payment.
     *
     * @param array<int, array<string, mixed>> $payments
     */
    private function closeOtherOpenAttempts(array &$payments, int $paidPaymentId): void
    {
        foreach ($payments as $id => $q) {
            if ($id === $paidPaymentId || !\in_array($q['status'], ['created', 'pending'], true)) {
                continue;
            }
            $this->expectAffected($this->exec(
                "UPDATE %i SET status = 'cancelled' WHERE id = %d AND status = %s",
                $this->db->table('book_payments'),
                (int) $id,
                (string) $q['status']
            ), 1, 'other attempt → cancelled');
            $this->audit->record('payment.status_changed', 'payment', (int) $id, (string) $q['status'], 'cancelled', [
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
            if (\in_array($p['status'], self::MONEY_RECEIVED, true)) {
                return (int) $id;
            }
        }

        return null;
    }

    /** @return ?array<string, mixed> (5) Заказ FOR UPDATE. */
    private function lockOrder(int $orderId): ?array
    {
        return $this->row(
            'SELECT id, user_id, public_order_id, status, total_amount, currency, refunded_amount, needs_attention, attention_reason
               FROM %i WHERE id = %d FOR UPDATE',
            $this->db->table('book_orders'),
            $orderId
        );
    }

    /** Db::transaction() может повторить замыкание после deadlock: состояние транзакции — с нуля. */
    private function resetTxState(string $actor, ?int $eventId): void
    {
        $this->afterCommit = [];
        $this->sessionsToCancel = [];
        $this->actor = $actor;
        $this->currentEventId = $eventId;
    }

    // =================================================================================================
    // Inbox, поиск платежа, задачи после COMMIT
    // =================================================================================================

    /** INSERT … ON DUPLICATE KEY UPDATE: новая строка или attempts + 1; возвращает id строки. */
    private function storeEvent(ProviderPaymentResult $event, string $rawBody): int
    {
        $payload = wp_json_encode(self::redact($event->redactedPayload));

        return $this->db->transaction(function () use ($event, $rawBody, $payload): int {
            // LAST_INSERT_ID(id) в ветке дубля: Db::lastInsertId() вернёт id существующей строки.
            $this->exec(
                "INSERT INTO %i
                   (provider, provider_event_id, event_type, processing_status, payload_redacted, payload_sha256, received_at, attempts)
                 VALUES (%s, %s, %s, 'received', %s, %s, UTC_TIMESTAMP(6), 1)
                 ON DUPLICATE KEY UPDATE attempts = LEAST(attempts + 1, 65535), id = LAST_INSERT_ID(id)",
                $this->db->table('book_payment_events'),
                $event->provider,
                $event->eventId ?? hash('sha256', $rawBody),
                $event->eventType ?? $event->status->value,
                \is_string($payload) ? $payload : '{}',
                hash('sha256', $rawBody)
            );
            $id = $this->db->lastInsertId();
            if ($id <= 0) {
                throw new \RuntimeException('payment event insert returned no id');
            }

            return $id;
        });
    }

    private function markEventFailed(int $eventId, string $error): void
    {
        $this->db->transaction(fn (): int => $this->exec(
            "UPDATE %i SET error_message = %s, processing_status = 'failed'
              WHERE id = %d AND processing_status IN ('received', 'failed')",
            $this->db->table('book_payment_events'),
            mb_substr($error, 0, 500),
            $eventId
        ));
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
            if (!\function_exists('as_enqueue_async_action')) {
                error_log('[uniundata] Action Scheduler is not loaded; action skipped: ' . $hook);
                continue;
            }
            // unique = true экономит дубли, но не гарантирует их отсутствия: обработчики идемпотентны
            // (письмо — по аудиту, возврат — по статусу строки wp_book_refunds).
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
                error_log(\sprintf('[uniundata] cancelSession failed for payment #%d: %s', (int) $q['id'], $e::class));
            }
        }
    }

    /**
     * Невалидная подпись: в аудит не чаще раза в минуту (защита от заливки таблицы), тело не пишем.
     * Троттлинг — по самому журналу (ix_audit_action), без зависимости от постоянного объектного кэша.
     */
    private function noteRejectedWebhook(string $reason): void
    {
        error_log('[uniundata] webhook rejected: ' . $reason);
        try {
            $this->db->transaction(function () use ($reason): void {
                $recent = $this->scalar(
                    "SELECT 1 FROM %i WHERE action = 'payment.webhook_rejected' AND occurred_at > UTC_TIMESTAMP(6) - INTERVAL 60 SECOND LIMIT 1",
                    $this->db->table('book_audit_log')
                );
                if ($recent === null) {
                    $this->audit->record('payment.webhook_rejected', 'payment_event', null, null, null, ['reason' => $reason], 'webhook');
                }
            });
        } catch (\Throwable $e) {
            error_log('[uniundata] webhook rejection audit failed: ' . $e::class);
        }
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
            'refunds' => $this->db->table('book_refunds'),
            'sales' => $this->db->table('book_sales'),
        ];
    }

    /** @return ?array<string, mixed> */
    private function row(string $sql, int|string|float ...$args): ?array
    {
        return $this->db->getRow($sql, ...$args);
    }

    /** @return list<array<string, mixed>> */
    private function rows(string $sql, int|string|float ...$args): array
    {
        return $this->db->getResults($sql, ...$args);
    }

    /** @return array<int, array<string, mixed>> */
    private function rowsById(string $sql, int|string|float ...$args): array
    {
        $out = [];
        foreach ($this->db->getResults($sql, ...$args) as $r) {
            $out[(int) $r['id']] = $r;
        }

        return $out;
    }

    /** @return list<string> Первая колонка результата. */
    private function col(string $sql, int|string|float ...$args): array
    {
        return array_map(static fn (array $r): string => (string) array_values($r)[0], $this->db->getResults($sql, ...$args));
    }

    private function scalar(string $sql, int|string|float ...$args): ?string
    {
        return $this->db->getVar($sql, ...$args);
    }

    /** INSERT/UPDATE: число изменённых строк (UPDATE теми же значениями даёт 0). Ошибка MySQL → исключение. */
    private function exec(string $sql, int|string|float ...$args): int
    {
        return $this->db->execute($sql, ...$args);
    }

    private function expectAffected(int $actual, int $expected, string $what): void
    {
        if ($actual !== $expected) {
            throw new \RuntimeException(\sprintf('Unexpected affected rows for %s: %d instead of %d', $what, $actual, $expected));
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
        return implode(',', array_fill(0, \count($ids), '%d'));
    }
}
