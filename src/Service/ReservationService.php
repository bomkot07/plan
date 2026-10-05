<?php

declare(strict_types=1);

namespace Uniundata\Books\Service;

use Uniundata\Books\Domain\DomainError;
use Uniundata\Books\Domain\ItemStatus;
use Uniundata\Books\Domain\ReservationStatus;
use Uniundata\Books\Infrastructure\AuditLog;
use Uniundata\Books\Infrastructure\Db;

/**
 * Резервирование экземпляров и корзина.
 *
 * Глобальный порядок блокировок (docs/08): carts → items → reservations → cart_items → orders → …
 * Решения принимаются только по строкам, прочитанным SELECT … FOR UPDATE внутри транзакции.
 * UNIQUE/CHECK схемы — второй рубеж: их нарушения (1062/3819) переводятся в DomainError.
 *
 * Настройки (wp_options, autoload): uniundata_currency — валюта магазина, ISO 4217 (обязательна:
 * без неё резерв отвечает 500, чтобы в корзину не попали книги в чужой валюте);
 * uniundata_max_active_reservations — сколько книг пользователь держит одновременно (по умолчанию 10).
 *
 * Методы с пометкой @internal — общие шаги для ReservationExpiryService; они рассчитывают на блокировки,
 * уже взятые вызывающим кодом в правильном порядке.
 *
 * @phpstan-type CartRow array{id: int, user_id: int, status: string}
 * @phpstan-type ItemRow array{id: int, book_record_id: int, availability_status: string, source_status: string,
 *                              is_active: bool, record_active: bool, price_amount: int, currency: string}
 * @phpstan-type ReservationRow array{id: int, user_id: int, cart_id: ?int, book_item_id: int, status: string,
 *                              attempt_no: ?int, reserved_at: string, expires_at: string, is_due: bool}
 */
final class ReservationService
{
    public const MAX_ATTEMPTS = 3;
    public const DEFAULT_MAX_ACTIVE_RESERVATIONS = 10;

    /**
     * @param int         $reservationMinutes    Резерв — ровно 1 час (option uniundata_reservation_minutes
     *                                           меняется только вместе с бизнес-правилами).
     * @param int|null    $maxActiveReservations null — option uniundata_max_active_reservations при каждом резерве.
     * @param string|null $shopCurrency          null — option uniundata_currency при каждом резерве.
     */
    public function __construct(
        private readonly Db $db,
        private readonly AuditLog $audit,
        private readonly int $reservationMinutes = 60,
        private readonly ?int $maxActiveReservations = null,
        private readonly ?string $shopCurrency = null,
    ) {
        if ($reservationMinutes < 1 || $reservationMinutes > 1440) {
            throw new \InvalidArgumentException('Reservation length must be between 1 and 1440 minutes');
        }
        if ($maxActiveReservations !== null && $maxActiveReservations < 1) {
            throw new \InvalidArgumentException('maxActiveReservations must be >= 1');
        }
        if ($shopCurrency !== null && preg_match('/^[A-Z]{3}$/', $shopCurrency) !== 1) {
            throw new \InvalidArgumentException('Shop currency must be an ISO 4217 code');
        }
    }

    // =============================================================================================
    // Публичные операции
    // =============================================================================================

    /**
     * POST /cart/reserve. Вызывающий уже проверил is_user_logged_in, reserve_books, nonce и rate limit.
     *
     * Ошибки: 404 uniundata_item_not_found; 409 uniundata_item_unavailable (data.availability_status;
     * data.reason = 'currency', если цена не в валюте магазина); 409 uniundata_reservation_limit_reached
     * (3 попытки на экземпляр); 409 uniundata_active_reservation_limit (слишком много книг одновременно);
     * 503 uniundata_conflict_retry.
     *
     * @return array{created: bool, reservation: array<string, mixed>, cart: array<string, mixed>}
     *         created=false — повторное «Отложить» на свой активный резерв (HTTP 200 вместо 201, попытка не тратится).
     */
    public function reserve(int $userId, int $itemId): array
    {
        self::assertUserId($userId);
        self::assertItemId($itemId);
        // Настройки читаются до транзакции: get_option() может сходить в БД, а повтор $fn их не меняет.
        $shopCurrency = $this->shopCurrency();
        $maxActive = $this->maxActiveReservations();

        $result = $this->db->transaction(
            fn (): array => $this->reserveInTransaction($userId, $itemId, $shopCurrency, $maxActive),
        );
        // Корзину читаем уже после COMMIT, без блокировок.
        $result['cart'] = $this->getCart($userId);

        return $result;
    }

    /**
     * POST /cart/remove-item. Удаление разрешено, пока позиция активна: резерв → cancelled (user_removed,
     * считается попыткой), позиция → removed, экземпляр → целевой статус освобождения.
     * Повторное удаление идемпотентно; книга, уже ушедшая в заказ, — 409 uniundata_cart_changed.
     *
     * @return array{outcome: 'removed'|'expired'|'already_removed', cart: array<string, mixed>}
     */
    public function removeFromCart(int $userId, int $itemId): array
    {
        self::assertUserId($userId);
        self::assertItemId($itemId);

        $outcome = $this->db->transaction(function () use ($userId, $itemId): string {
            $cart = $this->lockOpenCart($userId);                  // 1. carts
            $item = $this->lockItem($itemId);                      // 2. items
            if ($item === null) {
                throw DomainError::itemNotFound($itemId);
            }
            $active = $this->lockActiveReservationForItem($itemId); // 3. reservations

            if ($active !== null && $active['user_id'] === $userId) {
                if ($cart === null || $active['cart_id'] !== $cart['id']) {
                    throw new \LogicException(\sprintf('Active reservation #%d is outside the open cart of user #%d', $active['id'], $userId));
                }
                $now = $this->db->now();
                // Срок вышел до прихода cron — честно закрываем как expired; для лимита это одинаково попытка.
                $to = $active['is_due'] ? ReservationStatus::Expired : ReservationStatus::Cancelled;
                $this->releaseLocked($active, $item, $to, $active['is_due'] ? 'expired' : 'user_removed', $now, 'user', $userId);
                $this->refreshCartLocked($cart, $now, true, null, 'user', $userId);

                return $to === ReservationStatus::Expired ? 'expired' : 'removed';
            }

            // Своего активного резерва нет: повторный запрос, книга уже в заказе или книги не было в корзине.
            $lastStatus = $this->db->getVar(
                "SELECT ci.status
                   FROM {$this->t('cart_items')} ci
                   JOIN {$this->t('carts')} c ON c.id = ci.cart_id
                  WHERE ci.book_item_id = %d AND c.user_id = %d
                  ORDER BY ci.id DESC
                  LIMIT 1",
                $itemId,
                $userId,
            );

            return match ($lastStatus) {
                // Не раскрываем, что книгу держит другой пользователь.
                null => throw DomainError::itemNotFound($itemId),
                'converted_to_order' => throw DomainError::cartChanged(['book_item_id' => $itemId, 'reason' => 'item_in_order']),
                'removed', 'expired' => 'already_removed',
                default => throw new \LogicException(\sprintf('Cart item for book item #%d is "%s" without an active reservation', $itemId, $lastStatus)),
            };
        });

        return ['outcome' => $outcome, 'cart' => $this->getCart($userId)];
    }

    /**
     * POST /admin/reservations/{id}/release (capability manage_book_reservations проверена контроллером).
     * Резерв → released_by_admin с attempt_no = NULL: попытка пользователю возвращается.
     * Повтор по уже снятому администратором резерву — no-op.
     */
    public function adminRelease(int $adminId, int $reservationId, string $reason): void
    {
        if ($adminId <= 0) {
            throw DomainError::authRequired();
        }
        if ($reservationId <= 0) {
            throw DomainError::invalidParam('id', \__('Invalid reservation ID.', 'uniundata-books'));
        }
        // В release_reason — машинный код; свободный текст администратора — только в аудит.
        $reason = trim($reason);
        $reasonCode = preg_match('/^[a-z][a-z0-9_]{0,31}$/', $reason) === 1 ? $reason : 'admin_release';
        $note = $reason !== '' && $reason !== $reasonCode ? mb_substr($reason, 0, 255) : null;

        // ID верхних уровней — обычным чтением, затем блокировка по порядку и перепроверка.
        $ref = $this->db->getRow(
            "SELECT cart_id, book_item_id FROM {$this->t('reservations')} WHERE id = %d",
            $reservationId,
        );
        if ($ref === null) {
            throw DomainError::reservationNotFound($reservationId);
        }
        $cartId = $ref['cart_id'] !== null ? (int) $ref['cart_id'] : null;
        $itemId = (int) $ref['book_item_id'];

        $this->db->transaction(function () use ($adminId, $reservationId, $cartId, $itemId, $reasonCode, $note): void {
            $cart = $cartId !== null ? $this->lockCartById($cartId) : null; // 1. carts
            $item = $this->lockItem($itemId);                              // 2. items
            $reservation = $this->lockReservation($reservationId);         // 3. reservations
            if ($item === null || $reservation === null) {
                throw new \LogicException(\sprintf('Reservation #%d or its item disappeared', $reservationId));
            }

            if ($reservation['status'] === ReservationStatus::ReleasedByAdmin->value) {
                return;
            }
            if ($reservation['status'] !== ReservationStatus::Active->value) {
                throw DomainError::reservationExpired([
                    'reservation_id' => $reservationId,
                    'reservation_status' => $reservation['status'],
                ]);
            }

            $now = $this->db->now();
            $this->releaseLocked(
                $reservation,
                $item,
                ReservationStatus::ReleasedByAdmin,
                $reasonCode,
                $now,
                'admin',
                $adminId,
                $note !== null ? ['note' => $note] : [],
            );
            if ($cart !== null) {
                // Корзина остаётся открытой (даже пустой): это не истечение срока.
                $this->refreshCartLocked($cart, $now, false, null, 'admin', $adminId);
            }
        });
    }

    /**
     * GET /cart — только чтение: без блокировок и без продления резерва.
     * Позиция, чей срок вышел до прихода cron, помечается is_expired и не входит в сумму.
     *
     * @return array<string, mixed>
     */
    public function getCart(int $userId): array
    {
        self::assertUserId($userId);

        $cart = $this->db->getRow(
            "SELECT id, status, started_at, last_activity_at, UTC_TIMESTAMP(6) AS server_now
               FROM {$this->t('carts')}
              WHERE open_cart_user_id = %d",
            $userId,
        );
        if ($cart === null) {
            return [
                'cart_id' => null,
                'status' => null,
                'items' => [],
                'items_count' => 0,
                'totals' => [],
                'subtotal_amount' => 0,
                'currency' => null,
                'expires_at' => null,
                'server_time' => Db::toIso8601($this->db->now()),
            ];
        }

        $rows = $this->db->getResults(
            "SELECT ci.book_item_id, ci.reservation_id, ci.unit_price_amount, ci.currency,
                    ci.added_at, ci.expires_at,
                    (ci.expires_at <= UTC_TIMESTAMP(6)) AS is_expired,
                    GREATEST(0, TIMESTAMPDIFF(SECOND, UTC_TIMESTAMP(6), ci.expires_at)) AS seconds_left,
                    r.attempt_no,
                    i.price_amount AS current_price_amount, i.currency AS current_currency, i.condition_code,
                    COALESCE(i.cover_url, rec.cover_url) AS cover_url,
                    rec.id AS book_record_id, rec.title, rec.subtitle, rec.authors_text, rec.publication_year
               FROM {$this->t('cart_items')} ci
               JOIN {$this->t('reservations')} r ON r.id = ci.reservation_id
               JOIN {$this->t('items')} i ON i.id = ci.book_item_id
               JOIN {$this->t('records')} rec ON rec.id = i.book_record_id
              WHERE ci.cart_id = %d AND ci.status = 'active'
              ORDER BY ci.added_at, ci.id",
            (int) $cart['id'],
        );

        $items = [];
        $totals = [];
        $earliest = null;
        foreach ($rows as $row) {
            $isExpired = $row['is_expired'] === '1';
            $price = (int) $row['unit_price_amount'];
            $currency = (string) $row['currency'];
            $attemptNo = $row['attempt_no'] !== null ? (int) $row['attempt_no'] : null;
            if (!$isExpired) {
                $totals[$currency] = ($totals[$currency] ?? 0) + $price;
                $earliest = $earliest === null || $row['expires_at'] < $earliest ? (string) $row['expires_at'] : $earliest;
            }
            $items[] = [
                'book_item_id' => (int) $row['book_item_id'],
                'book_record_id' => (int) $row['book_record_id'],
                'reservation_id' => (int) $row['reservation_id'],
                'title' => (string) $row['title'],
                'subtitle' => $row['subtitle'],
                'authors' => $row['authors_text'],
                'publication_year' => $row['publication_year'] !== null ? (int) $row['publication_year'] : null,
                'condition_code' => (string) $row['condition_code'],
                'cover_url' => $row['cover_url'],
                // Цена — снимок на момент резерва; текущая цена каталога — только для пометки «цена изменилась».
                'unit_price_amount' => $price,
                'currency' => $currency,
                'price_changed' => (int) $row['current_price_amount'] !== $price
                    || (string) $row['current_currency'] !== $currency,
                'added_at' => Db::toIso8601($row['added_at']),
                'expires_at' => Db::toIso8601($row['expires_at']),
                'seconds_left' => (int) $row['seconds_left'],
                'is_expired' => $isExpired,
                'attempt_no' => $attemptNo,
                'attempts_left' => $attemptNo !== null ? max(0, self::MAX_ATTEMPTS - $attemptNo) : null,
            ];
        }

        return [
            'cart_id' => (int) $cart['id'],
            'status' => (string) $cart['status'],
            'items' => $items,
            'items_count' => \count(array_filter($items, static fn (array $i): bool => !$i['is_expired'])),
            'totals' => $totals,
            // Позиции в разных валютах возможны только после смены uniundata_currency: тогда не суммируем,
            // клиент смотрит totals.
            'subtotal_amount' => \count($totals) <= 1 ? (int) array_sum($totals) : null,
            'currency' => \count($totals) === 1 ? (string) array_key_first($totals) : null,
            'expires_at' => Db::toIso8601($earliest),
            // Таймер на клиенте считается от времени сервера, а не от часов браузера.
            'server_time' => Db::toIso8601((string) $cart['server_now']),
        ];
    }

    // =============================================================================================
    // Общие шаги под блокировками (@internal)
    // =============================================================================================

    /**
     * @internal Снятие активного резерва — общий шаг для removeFromCart(), adminRelease(),
     *           reserve() (свой просроченный резерв) и ReservationExpiryService.
     *
     * Вызывающий ОБЯЗАН уже держать FOR UPDATE в порядке: корзина резерва → экземпляр → резерв.
     * Здесь берётся последний уровень (позиция корзины) и выполняются переходы:
     *   reservation active → $to, cart_item active → $to->cartItemStatus(),
     *   item reserved → releaseTarget(source_status).
     * Экземпляр освобождается только из reserved: под блокировкой активный резерв экземпляра — именно этот,
     * значит, reserved принадлежит ему. Корзину не трогает — см. refreshCartLocked().
     *
     * @param ReservationRow       $reservation
     * @param ItemRow              $item
     * @param array<string, mixed> $auditContext
     * @return string Статус экземпляра после операции.
     */
    public function releaseLocked(
        array $reservation,
        array $item,
        ReservationStatus $to,
        string $reason,
        string $now,
        string $actorType,
        ?int $actorUserId,
        array $auditContext = [],
    ): string {
        $this->db->assertInTransaction();
        if (!\in_array($to, [ReservationStatus::Expired, ReservationStatus::Cancelled, ReservationStatus::ReleasedByAdmin], true)) {
            // converted_to_order — переход checkout-а, он делается в CheckoutService вместе с заказом.
            throw new \InvalidArgumentException(\sprintf('Cannot release a reservation into "%s"', $to->value));
        }
        if (preg_match('/^[a-z0-9_.:-]{1,32}$/', $reason) !== 1) {
            throw new \InvalidArgumentException(\sprintf('Invalid release reason "%s"', $reason));
        }
        if ($item['id'] !== $reservation['book_item_id']) {
            throw new \LogicException('Locked item does not belong to the reservation');
        }

        // 4. cart_items
        $cartItem = $this->db->getRow(
            "SELECT id, status FROM {$this->t('cart_items')} WHERE reservation_id = %d FOR UPDATE",
            $reservation['id'],
        );

        // attempt_no = NULL только вместе с released_by_admin (wp_book_reservations_chk_attempt_admin): слот попытки освобождается.
        $attemptSql = $to === ReservationStatus::ReleasedByAdmin ? ', attempt_no = NULL' : '';
        $changed = $this->db->execute(
            "UPDATE {$this->t('reservations')}
                SET reservation_status = %s, released_at = %s, release_reason = %s{$attemptSql}
              WHERE id = %d AND reservation_status = 'active'",
            $to->value,
            $now,
            $reason,
            $reservation['id'],
        );
        self::expectOneRow($changed, \sprintf('reservation #%d active → %s', $reservation['id'], $to->value));

        $cartItemId = null;
        if ($cartItem !== null && $cartItem['status'] === 'active') {
            $cartItemId = (int) $cartItem['id'];
            $changed = $this->db->execute(
                "UPDATE {$this->t('cart_items')} SET status = %s, closed_at = %s WHERE id = %d AND status = 'active'",
                $to->cartItemStatus(),
                $now,
                $cartItemId,
            );
            self::expectOneRow($changed, \sprintf('cart item #%d active → %s', $cartItemId, $to->cartItemStatus()));
        }

        $itemFrom = $item['availability_status'];
        $itemTo = $itemFrom;
        if ($itemFrom === ItemStatus::Reserved->value) {
            $itemTo = ItemStatus::releaseTarget($item['source_status'])->value;
            $changed = $this->db->execute(
                "UPDATE {$this->t('items')} SET availability_status = %s, status_changed_at = %s
                  WHERE id = %d AND availability_status = 'reserved'",
                $itemTo,
                $now,
                $item['id'],
            );
            self::expectOneRow($changed, \sprintf('item #%d reserved → %s', $item['id'], $itemTo));
        }

        $this->audit->record(
            'reservation.' . $to->value,
            'reservation',
            $reservation['id'],
            ReservationStatus::Active->value,
            $to->value,
            [
                'book_item_id' => $item['id'],
                'user_id' => $reservation['user_id'],
                'cart_id' => $reservation['cart_id'],
                'cart_item_id' => $cartItemId,
                'attempt_no' => $reservation['attempt_no'],
                'reason' => $reason,
            ] + $auditContext,
            $actorType,
            $actorUserId,
        );
        if ($itemTo !== $itemFrom) {
            $this->audit->record('item.status_changed', 'item', $item['id'], $itemFrom, $itemTo, [
                'reservation_id' => $reservation['id'],
                'source_status' => $item['source_status'],
                'reason' => $reason,
            ], $actorType, $actorUserId);
        } else {
            // Активный резерв, а экземпляр не reserved — нарушение инварианта. Экземпляр не трогаем, фиксируем.
            $this->audit->record('item.release_skipped', 'item', $item['id'], $itemFrom, null, [
                'reservation_id' => $reservation['id'],
                'reason' => $reason,
            ], $actorType, $actorUserId);
        }

        return $itemTo;
    }

    /**
     * @internal Пересчёт корзины после изменения её позиций. Вызывающий держит FOR UPDATE корзины,
     *           поэтому обычное чтение cart_items стабильно: все, кто меняет позиции, сначала берут корзину.
     *
     * @param CartRow     $cart
     * @param string|null $closeEmptyAs 'expired' — закрыть корзину, если активных позиций не осталось
     *                                  (истекла последняя); null — оставить открытой (удаление, admin release).
     * @return bool true, если корзина закрыта.
     */
    public function refreshCartLocked(
        array $cart,
        string $now,
        bool $touchActivity,
        ?string $closeEmptyAs,
        string $actorType,
        ?int $actorUserId,
    ): bool {
        $this->db->assertInTransaction();

        $agg = $this->db->getRow(
            "SELECT COUNT(*) AS active_items, MIN(expires_at) AS min_expires_at
               FROM {$this->t('cart_items')}
              WHERE cart_id = %d AND status = 'active'",
            $cart['id'],
        );
        $activeItems = (int) ($agg['active_items'] ?? 0);

        if ($activeItems === 0 && $closeEmptyAs !== null) {
            if (!\in_array($closeEmptyAs, ['expired', 'abandoned'], true)) {
                throw new \InvalidArgumentException(\sprintf('Cannot close a cart as "%s"', $closeEmptyAs));
            }
            $changed = $this->db->execute(
                "UPDATE {$this->t('carts')}
                    SET status = %s, closed_at = %s, expires_at = NULL
                  WHERE id = %d AND status IN ('active', 'checkout_started')",
                $closeEmptyAs,
                $now,
                $cart['id'],
            );
            if ($changed === 1) {
                $this->audit->record('cart.' . $closeEmptyAs, 'cart', $cart['id'], $cart['status'], $closeEmptyAs, [
                    'user_id' => $cart['user_id'],
                ], $actorType, $actorUserId);
            }

            return $changed === 1;
        }

        // expires_at корзины — только для отображения: MIN по активным позициям.
        $sets = [];
        $args = [];
        if ($agg['min_expires_at'] === null) {
            $sets[] = 'expires_at = NULL';
        } else {
            $sets[] = 'expires_at = %s';
            $args[] = (string) $agg['min_expires_at'];
        }
        if ($touchActivity) {
            $sets[] = 'last_activity_at = %s';
            $args[] = $now;
        }
        $args[] = $cart['id'];
        // Число строк не проверяем: UPDATE теми же значениями даёт 0 affected rows.
        $this->db->execute("UPDATE {$this->t('carts')} SET " . implode(', ', $sets) . ' WHERE id = %d', ...$args);

        return false;
    }

    /**
     * @internal 1. carts
     * @return CartRow|null
     */
    public function lockCartById(int $cartId): ?array
    {
        $row = $this->db->getRow(
            "SELECT id, user_id, status FROM {$this->t('carts')} WHERE id = %d FOR UPDATE",
            $cartId,
        );

        return $row === null ? null : self::cartRow($row);
    }

    /**
     * @internal 2. items. Запись книги читается без блокировки (FOR UPDATE OF i): нужен только её флаг активности.
     * @return ItemRow|null
     */
    public function lockItem(int $itemId): ?array
    {
        $row = $this->db->getRow(
            "SELECT i.id, i.book_record_id, i.availability_status, i.source_status, i.is_active,
                    i.price_amount, i.currency, rec.is_active AS record_active
               FROM {$this->t('items')} i
               JOIN {$this->t('records')} rec ON rec.id = i.book_record_id
              WHERE i.id = %d
                FOR UPDATE OF i",
            $itemId,
        );
        if ($row === null) {
            return null;
        }

        return [
            'id' => (int) $row['id'],
            'book_record_id' => (int) $row['book_record_id'],
            'availability_status' => (string) $row['availability_status'],
            'source_status' => (string) $row['source_status'],
            'is_active' => $row['is_active'] === '1',
            'record_active' => $row['record_active'] === '1',
            'price_amount' => (int) $row['price_amount'],
            'currency' => (string) $row['currency'], // utf8mb4_bin + CHECK '^[A-Z]{3}$': только верхний регистр
        ];
    }

    /**
     * @internal 3. reservations. is_due вычисляется по часам БД в момент блокировки.
     * @return ReservationRow|null
     */
    public function lockReservation(int $reservationId): ?array
    {
        $row = $this->db->getRow(
            "SELECT id, user_id, cart_id, book_item_id, reservation_status, attempt_no, reserved_at, expires_at,
                    (expires_at <= UTC_TIMESTAMP(6)) AS is_due
               FROM {$this->t('reservations')}
              WHERE id = %d
                FOR UPDATE",
            $reservationId,
        );

        return $row === null ? null : self::reservationRow($row);
    }

    // =============================================================================================
    // Внутреннее
    // =============================================================================================

    /** @return array{created: bool, reservation: array<string, mixed>} */
    private function reserveInTransaction(int $userId, int $itemId, string $shopCurrency, int $maxActive): array
    {
        // (a) Корзина — первый уровень порядка блокировок; сериализует параллельные запросы одного пользователя:
        // все резервы пользователя создаются под блокировкой его единственной открытой корзины.
        $cart = $this->lockOrCreateOpenCart($userId);

        // (b) Экземпляр: второй пользователь ждёт здесь, пока первый не сделает COMMIT, и затем видит reserved.
        $item = $this->lockItem($itemId);
        if ($item === null || !$item['is_active'] || !$item['record_active']) {
            throw DomainError::itemNotFound($itemId);
        }

        // (c) Текущий активный резерв экземпляра (любого пользователя).
        $active = $this->lockActiveReservationForItem($itemId);
        if ($active !== null) {
            if ($active['user_id'] !== $userId) {
                // Даже просроченный чужой резерв освобождает только cron: иначе пришлось бы блокировать
                // чужую корзину после экземпляра — нарушение порядка блокировок.
                throw DomainError::itemUnavailable($itemId, $item['availability_status']);
            }
            if (!$active['is_due']) {
                return ['created' => false, 'reservation' => $this->presentReservation($active)];
            }
            if ($active['cart_id'] !== $cart['id']) {
                throw new \LogicException(\sprintf('Active reservation #%d is outside the open cart of user #%d', $active['id'], $userId));
            }
            // Свой резерв истёк, а cron ещё не дошёл: закрываем как expired (это была попытка) и идём дальше.
            $item['availability_status'] = $this->releaseLocked(
                $active,
                $item,
                ReservationStatus::Expired,
                'expired',
                $this->db->now(),
                'user',
                $userId,
            );
        }

        // (d) Статус экземпляра.
        if ($item['availability_status'] !== ItemStatus::Available->value) {
            throw DomainError::itemUnavailable($itemId, $item['availability_status']);
        }
        // Цена не в валюте магазина — экземпляр не продаётся (sync такие пропускает; это страховка).
        if ($item['currency'] !== $shopCurrency) {
            throw DomainError::itemCurrencyNotAccepted($itemId, $item['availability_status'], $item['currency'], $shopCurrency);
        }

        // (e) Лимит попыток. Все резервы экземпляра создаются под его блокировкой, поэтому COUNT стабилен.
        // Индекс: uq_reservations_attempt (user_id, book_item_id, attempt_no) или ix_reservations_user — оба по user_id.
        $used = (int) $this->db->getVar(
            "SELECT COUNT(*) FROM {$this->t('reservations')}
              WHERE user_id = %d AND book_item_id = %d AND attempt_no IS NOT NULL",
            $userId,
            $itemId,
        );
        if ($used >= self::MAX_ATTEMPTS) {
            throw DomainError::reservationLimitReached($itemId, self::MAX_ATTEMPTS);
        }

        // (e2) Сколько книг пользователь держит сейчас. Стабильно под блокировкой корзины из (a): новые
        // резервы пользователя появляются только под ней, остальные операции число лишь уменьшают.
        // Просроченные, но ещё не снятые cron-ом резервы не считаются. Индекс ix_reservations_user.
        $activeNow = (int) $this->db->getVar(
            "SELECT COUNT(*) FROM {$this->t('reservations')}
              WHERE user_id = %d AND reservation_status = 'active' AND expires_at > UTC_TIMESTAMP(6)",
            $userId,
        );
        if ($activeNow >= $maxActive) {
            throw DomainError::activeReservationLimit($maxActive);
        }

        // (f) Резерв. Время берётся у БД после всех ожиданий блокировок; одно выражение — одно значение NOW.
        $window = $this->db->getRow(
            'SELECT UTC_TIMESTAMP(6) AS now_utc, UTC_TIMESTAMP(6) + INTERVAL %d MINUTE AS expires_at',
            $this->reservationMinutes,
        );
        $now = (string) $window['now_utc'];
        $expiresAt = (string) $window['expires_at'];
        // Номера попыток идут подряд: admin release обнуляет только активный (последний) резерв.
        $attemptNo = $used + 1;

        try {
            $reservationId = $this->db->insert('book_reservations', [
                'book_item_id' => $itemId,
                'user_id' => $userId,
                'cart_id' => $cart['id'],
                'reservation_status' => ReservationStatus::Active->value,
                'attempt_no' => $attemptNo,
                'reserved_at' => $now,
                'expires_at' => $expiresAt,
            ]);
        } catch (\mysqli_sql_exception $e) {
            throw $this->mapConstraintViolation($e, $itemId);
        }

        // (g) Экземпляр → reserved. Условный UPDATE: при неверном исходном статусе — 0 строк и откат.
        $changed = $this->db->execute(
            "UPDATE {$this->t('items')} SET availability_status = 'reserved', status_changed_at = %s
              WHERE id = %d AND availability_status = 'available'",
            $now,
            $itemId,
        );
        self::expectOneRow($changed, \sprintf('item #%d available → reserved', $itemId));

        // (h) Позиция корзины со снимком цены; срок — копия срока резерва.
        try {
            $cartItemId = $this->db->insert('book_cart_items', [
                'cart_id' => $cart['id'],
                'book_item_id' => $itemId,
                'reservation_id' => $reservationId,
                'unit_price_amount' => $item['price_amount'],
                'currency' => $item['currency'],
                'status' => 'active',
                'added_at' => $now,
                'expires_at' => $expiresAt,
            ]);
        } catch (\mysqli_sql_exception $e) {
            throw $this->mapConstraintViolation($e, $itemId);
        }

        // (i) Активность и срок корзины.
        $this->refreshCartLocked($cart, $now, true, null, 'user', $userId);

        // (j) Аудит — в той же транзакции.
        $this->audit->record('reservation.created', 'reservation', $reservationId, null, ReservationStatus::Active->value, [
            'book_item_id' => $itemId,
            'cart_id' => $cart['id'],
            'cart_item_id' => $cartItemId,
            'attempt_no' => $attemptNo,
            'expires_at' => $expiresAt,
            'unit_price_amount' => $item['price_amount'],
            'currency' => $item['currency'],
        ], 'user', $userId);
        $this->audit->record('item.status_changed', 'item', $itemId, ItemStatus::Available->value, ItemStatus::Reserved->value, [
            'reservation_id' => $reservationId,
        ], 'user', $userId);

        return [
            'created' => true,
            'reservation' => $this->presentReservation([
                'id' => $reservationId,
                'book_item_id' => $itemId,
                'attempt_no' => $attemptNo,
                'reserved_at' => $now,
                'expires_at' => $expiresAt,
            ]),
        ];
    }

    /**
     * Открытая корзина пользователя FOR UPDATE; если нет — создаётся.
     *
     * Гонка двух первых запросов одного пользователя решается на uq_carts_one_open_per_user через
     * INSERT … ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id): проигравший сразу берёт X-lock на строку
     * победителя, а LAST_INSERT_ID(id) возвращает её id; rows_affected: 1 — создана, 0 — уже была.
     * Вариант «INSERT → 1062 → повторный SELECT FOR UPDATE» хуже: после 1062 каждый проигравший держит
     * S-lock на дубликате и просит X — при 3+ параллельных запросах это deadlock (воспроизведено на 8.0.46).
     *
     * Deadlock возможен и здесь, но редко: ключ новой корзины совпадает с ключом только что закрытой
     * (checkout, истечение) корзины, чья запись в uq_carts_one_open_per_user ещё не вычищена purge-ом;
     * проверка дубликата берёт gap-lock даже в READ COMMITTED, и параллельные checkout + reserve соседних
     * пользователей могут встретиться на одном промежутке (docs/10). Инварианты это не нарушает:
     * InnoDB откатывает одну транзакцию, Db::transaction() её повторяет.
     *
     * @return CartRow
     */
    private function lockOrCreateOpenCart(int $userId): array
    {
        $cart = $this->lockOpenCart($userId);
        if ($cart !== null) {
            return $cart;
        }

        $created = $this->db->execute(
            "INSERT INTO {$this->t('carts')} (user_id, status, started_at, last_activity_at)
             VALUES (%d, 'active', UTC_TIMESTAMP(6), UTC_TIMESTAMP(6))
             ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)",
            $userId,
        ) === 1;
        $cart = $this->lockCartById($this->db->lastInsertId()); // X-lock уже наш, ожидания нет
        if ($cart === null || $cart['user_id'] !== $userId) {
            throw new \LogicException(\sprintf('Could not obtain the open cart of user #%d', $userId));
        }
        if ($created) {
            $this->audit->record('cart.created', 'cart', $cart['id'], null, $cart['status'], [], 'user', $userId);
        }

        return $cart;
    }

    /** @return CartRow|null */
    private function lockOpenCart(int $userId): ?array
    {
        // open_cart_user_id = user_id только у active/checkout_started (STORED generated column + UNIQUE).
        $row = $this->db->getRow(
            "SELECT id, user_id, status FROM {$this->t('carts')} WHERE open_cart_user_id = %d FOR UPDATE",
            $userId,
        );

        return $row === null ? null : self::cartRow($row);
    }

    /** @return ReservationRow|null */
    private function lockActiveReservationForItem(int $itemId): ?array
    {
        // active_book_item_id = book_item_id только у active (uq_reservations_one_active_per_item).
        $row = $this->db->getRow(
            "SELECT id, user_id, cart_id, book_item_id, reservation_status, attempt_no, reserved_at, expires_at,
                    (expires_at <= UTC_TIMESTAMP(6)) AS is_due
               FROM {$this->t('reservations')}
              WHERE active_book_item_id = %d
                FOR UPDATE",
            $itemId,
        );

        return $row === null ? null : self::reservationRow($row);
    }

    /** Второй рубеж: нарушение UNIQUE/CHECK, которое не должно случаться при корректных блокировках. */
    private function mapConstraintViolation(\mysqli_sql_exception $e, int $itemId): \Throwable
    {
        if ($this->db->isDuplicateKey('uq_reservations_one_active_per_item', $e)
            || $this->db->isDuplicateKey('uq_cart_items_one_active_per_item', $e)) {
            return DomainError::itemUnavailable($itemId, ItemStatus::Reserved->value, $e);
        }
        if ($this->db->isDuplicateKey('uq_reservations_attempt', $e)
            || $this->db->isCheckViolation('_chk_attempt_range', $e)) {
            return DomainError::reservationLimitReached($itemId, self::MAX_ATTEMPTS, $e);
        }

        return $e;
    }

    /**
     * @param array{id: int, book_item_id: int, attempt_no: ?int, reserved_at: string, expires_at: string} $r
     * @return array<string, mixed>
     */
    private function presentReservation(array $r): array
    {
        return [
            'id' => $r['id'],
            'book_item_id' => $r['book_item_id'],
            'status' => ReservationStatus::Active->value,
            'attempt_no' => $r['attempt_no'],
            'attempts_left' => max(0, self::MAX_ATTEMPTS - (int) $r['attempt_no']),
            'reserved_at' => Db::toIso8601($r['reserved_at']),
            'expires_at' => Db::toIso8601($r['expires_at']),
        ];
    }

    /** Валюта магазина (ISO 4217). Без корректной настройки резервировать нельзя — fail closed. */
    private function shopCurrency(): string
    {
        $currency = $this->shopCurrency ?? strtoupper(trim((string) get_option('uniundata_currency', '')));
        if (preg_match('/^[A-Z]{3}$/', $currency) !== 1) {
            throw new \LogicException('Option uniundata_currency must be set to an ISO 4217 code (e.g. RUB)');
        }

        return $currency;
    }

    private function maxActiveReservations(): int
    {
        if ($this->maxActiveReservations !== null) {
            return $this->maxActiveReservations;
        }
        $value = (int) get_option('uniundata_max_active_reservations', self::DEFAULT_MAX_ACTIVE_RESERVATIONS);

        // 0, отрицательное или мусор в опции — не «запретить всё», а значение по умолчанию.
        return $value >= 1 ? $value : self::DEFAULT_MAX_ACTIVE_RESERVATIONS;
    }

    private function t(string $table): string
    {
        // Каждый раз через Db::table(): $wpdb->prefix меняется при switch_to_blog() в мультисайте.
        return $this->db->table($table);
    }

    /**
     * @param array<string, string|null> $row
     * @return CartRow
     */
    private static function cartRow(array $row): array
    {
        return ['id' => (int) $row['id'], 'user_id' => (int) $row['user_id'], 'status' => (string) $row['status']];
    }

    /**
     * @param array<string, string|null> $row
     * @return ReservationRow
     */
    private static function reservationRow(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'user_id' => (int) $row['user_id'],
            'cart_id' => $row['cart_id'] !== null ? (int) $row['cart_id'] : null,
            'book_item_id' => (int) $row['book_item_id'],
            'status' => (string) $row['reservation_status'],
            'attempt_no' => $row['attempt_no'] !== null ? (int) $row['attempt_no'] : null,
            'reserved_at' => (string) $row['reserved_at'],
            'expires_at' => (string) $row['expires_at'],
            'is_due' => $row['is_due'] === '1',
        ];
    }

    private static function expectOneRow(int $affected, string $transition): void
    {
        if ($affected !== 1) {
            // Состояние проверено под блокировкой, поэтому 0 строк — ошибка логики, а не гонка: откат и 500.
            throw new \LogicException(\sprintf('Conditional update failed (%d rows): %s', $affected, $transition));
        }
    }

    private static function assertUserId(int $userId): void
    {
        if ($userId <= 0) {
            throw DomainError::authRequired();
        }
    }

    private static function assertItemId(int $itemId): void
    {
        if ($itemId <= 0) {
            throw DomainError::invalidParam('book_item_id', \__('Invalid book item ID.', 'uniundata-books'));
        }
    }
}
