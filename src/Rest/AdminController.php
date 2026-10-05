<?php

declare(strict_types=1);

namespace Uniundata\Books\Rest;

use Uniundata\Books\Domain\DomainError;
use Uniundata\Books\Domain\ItemStatus;
use Uniundata\Books\Domain\ReservationStatus;
use Uniundata\Books\Infrastructure\AuditLog;
use Uniundata\Books\Infrastructure\Db;
use Uniundata\Books\Service\ReservationService;
use Uniundata\Books\Sync\SourceClientInterface;
use Uniundata\Books\Sync\SyncService;

/**
 * Административные действия. Вызываются из wp-admin (cookie + X-WP-Nonce) или скриптами персонала
 * через Application Passwords (только HTTPS). Маршруты скрыты из индекса /wp-json (show_in_index=false),
 * но защищены capability, а не скрытием. Права — как в docs/07 § 7.2:
 *
 *   POST /admin/reservations/{id}/release — manage_book_reservations: снять резерв, попытка возвращается;
 *   POST /admin/items/{id}/block          — manage_book_catalog: available → blocked; экземпляр в чужой
 *                                           корзине (reserved) — дополнительно manage_book_reservations:
 *                                           released_by_admin + blocked в одной транзакции; checkout_pending —
 *                                           409 (сначала отмена заказа менеджером заказов);
 *   POST /admin/items/{id}/unblock        — manage_book_catalog: blocked → release target по source_status
 *                                           (available | sync_missing | withdrawn);
 *   POST /admin/sync/run                  — manage_book_sync: поставить синхронизацию в Action Scheduler (202);
 *   GET  /admin/sync/runs                 — manage_book_sync: журнал прогонов.
 */
final class AdminController extends RestController
{
    /**
     * Action Scheduler hook синхронизации (= Cron\Scheduler::HOOK_SYNC_DAILY). Ручной запуск ставит
     * разовую async-задачу с args [triggered_by, source]; AS передаёт их обработчику позиционно.
     */
    public const HOOK_SYNC = 'uniundata_sync_daily';
    public const AS_GROUP = 'uniundata';

    private const SYNC_STATUSES = ['running', 'succeeded', 'partial', 'failed', 'aborted'];
    /** Имя источника — как проверяет SyncService::__construct() (и ≤ 64 символов имени GET_LOCK). */
    private const SOURCE_PATTERN = '^[a-z0-9_-]{1,32}$';

    public function __construct(
        AuditLog $audit,
        private readonly Db $db,
        private readonly ReservationService $reservations,
    ) {
        parent::__construct($audit);
    }

    public function register_routes(): void
    {
        $this->addCommonHooks();

        register_rest_route(self::NAMESPACE, '/admin/reservations/(?P<id>\d+)/release', [
            'methods' => \WP_REST_Server::CREATABLE,
            'callback' => [$this, 'releaseReservation'],
            'permission_callback' => $this->requireCapability('manage_book_reservations'),
            'args' => [
                'id' => self::idArg('ID резерва (wp_book_reservations.id).'),
                'reason' => self::reasonArg(),
            ],
            'show_in_index' => false,
        ]);

        foreach (['block' => true, 'unblock' => false] as $action => $block) {
            // Базовое право — каталог; блокировка экземпляра в чужой корзине дополнительно проверяется в callback.
            register_rest_route(self::NAMESPACE, '/admin/items/(?P<id>\d+)/' . $action, [
                'methods' => \WP_REST_Server::CREATABLE,
                'callback' => fn (\WP_REST_Request $r): \WP_REST_Response|\WP_Error => $this->setBlocked($r, $block),
                'permission_callback' => $this->requireCapability('manage_book_catalog'),
                'args' => [
                    'id' => self::idArg('ID экземпляра (wp_book_items.id).'),
                    'reason' => self::reasonArg(),
                ],
                'show_in_index' => false,
            ]);
        }

        register_rest_route(self::NAMESPACE, '/admin/sync/run', [
            'methods' => \WP_REST_Server::CREATABLE,
            'callback' => [$this, 'runSync'],
            'permission_callback' => $this->requireCapability('manage_book_sync'),
            'args' => [
                'source' => [
                    'description' => 'Код источника (wp_book_sync_runs.source_name), зарегистрированного фильтром uniundata_sync_sources.',
                    'type' => 'string',
                    'pattern' => self::SOURCE_PATTERN,
                    'default' => 'primary',
                    'validate_callback' => 'rest_validate_request_arg',
                    'sanitize_callback' => 'sanitize_key',
                ],
            ],
            'show_in_index' => false,
        ]);

        register_rest_route(self::NAMESPACE, '/admin/sync/runs', [
            'methods' => \WP_REST_Server::READABLE,
            'callback' => [$this, 'listSyncRuns'],
            'permission_callback' => $this->requireCapability('manage_book_sync'),
            'args' => self::paginationArgs(100) + [
                'source' => [
                    'description' => 'Фильтр по источнику.',
                    'type' => 'string',
                    'pattern' => self::SOURCE_PATTERN,
                    'required' => false,
                    'validate_callback' => 'rest_validate_request_arg',
                    'sanitize_callback' => 'sanitize_key',
                ],
                'status' => [
                    'description' => 'Фильтр по статусу прогона.',
                    'type' => 'string',
                    'enum' => self::SYNC_STATUSES,
                    'required' => false,
                    'validate_callback' => 'rest_validate_request_arg',
                    'sanitize_callback' => 'sanitize_key',
                ],
            ],
            'show_in_index' => false,
        ]);
    }

    // =============================================================================================
    // Резервы
    // =============================================================================================

    public function releaseReservation(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        return $this->respond($request, function () use ($request): array {
            $this->enforceRateLimit('admin_write');
            $reservationId = (int) $request->get_param('id');

            // Резерв → released_by_admin, attempt_no = NULL (попытка возвращается), экземпляр → release target.
            // Повтор по уже снятому администратором резерву — no-op; по истёкшему/отменённому — 410.
            $this->reservations->adminRelease($this->currentUserId(), $reservationId, (string) $request->get_param('reason'));

            $row = $this->db->getRow(
                "SELECT r.id, r.book_item_id, r.user_id, r.reservation_status, r.attempt_no, r.released_at, r.release_reason,
                        i.availability_status
                   FROM {$this->db->table('book_reservations')} r
                   JOIN {$this->db->table('book_items')} i ON i.id = r.book_item_id
                  WHERE r.id = %d",
                $reservationId,
            );
            if ($row === null) {
                throw DomainError::reservationNotFound($reservationId);
            }

            return [
                'reservation' => [
                    'id' => (int) $row['id'],
                    'book_item_id' => (int) $row['book_item_id'],
                    'user_id' => (int) $row['user_id'],
                    'reservation_status' => (string) $row['reservation_status'],
                    'attempt_no' => $row['attempt_no'] !== null ? (int) $row['attempt_no'] : null,
                    'released_at' => Db::toIso8601($row['released_at']),
                    'release_reason' => $row['release_reason'],
                ],
                'item_availability_status' => (string) $row['availability_status'],
            ];
        });
    }

    // =============================================================================================
    // Блокировка экземпляра
    // =============================================================================================

    /**
     * block:   available → blocked; повтор (уже blocked) — 200, changed=false.
     *          reserved → сначала released_by_admin (attempt_no = NULL, попытка покупателю возвращается),
     *          затем blocked — одна транзакция, нужна ещё manage_book_reservations (иначе 403). Если источник
     *          сообщает missing/withdrawn, после освобождения экземпляр уже sync_missing/withdrawn и не продаётся —
     *          блокировка не нужна, ответ 200 с этим статусом.
     *          checkout_pending — 409 (data.required_action = cancel_order): держит заказ, сначала его отмена;
     *          sold, withdrawn, sync_missing — 409 (из них в blocked перехода нет).
     * unblock: blocked → release target по source_status; не blocked — 200, changed=false (нечего снимать).
     *
     * Порядок блокировок (docs/08): корзина резерва → экземпляр → резерв → позиция корзины. ID корзины и резерва
     * читаются обычным SELECT до транзакции и перепроверяются после блокировки.
     */
    public function setBlocked(\WP_REST_Request $request, bool $block): \WP_REST_Response|\WP_Error
    {
        return $this->respond($request, function () use ($request, $block): array {
            $this->enforceRateLimit('admin_write');
            $adminId = $this->currentUserId();
            $itemId = (int) $request->get_param('id');
            $note = trim((string) $request->get_param('reason'));
            $canRelease = current_user_can('manage_book_reservations');

            $ref = $block ? $this->db->getRow(
                "SELECT id, cart_id FROM {$this->db->table('book_reservations')} WHERE active_book_item_id = %d",
                $itemId,
            ) : null;

            $result = $this->db->transaction(function () use ($adminId, $itemId, $block, $note, $ref, $canRelease): array {
                $cart = $ref !== null && $ref['cart_id'] !== null ? $this->reservations->lockCartById((int) $ref['cart_id']) : null; // 1
                $item = $this->reservations->lockItem($itemId);                                                                       // 2
                if ($item === null) {
                    throw DomainError::itemNotFound($itemId);
                }
                $from = ItemStatus::from($item['availability_status']);
                $context = ['reason' => $block ? 'admin_block' : 'admin_unblock'] + ($note !== '' ? ['note' => $note] : []);

                if (!$block) {
                    if ($from !== ItemStatus::Blocked) {
                        return ['changed' => false, 'status' => $from->value, 'released_reservation_id' => null];
                    }

                    return $this->moveItem($itemId, $from, ItemStatus::releaseTarget($item['source_status']), $context, $adminId)
                        + ['released_reservation_id' => null];
                }

                $releasedId = null;
                if ($from === ItemStatus::Reserved) {
                    if (!$canRelease) {
                        throw new DomainError(
                            'uniundata_forbidden',
                            \__('The book is in a customer cart: releasing it requires the manage_book_reservations capability.', 'uniundata-books'),
                            403,
                            ['book_item_id' => $itemId, 'availability_status' => $from->value, 'required_capability' => 'manage_book_reservations'],
                        );
                    }
                    $reservation = $ref !== null ? $this->reservations->lockReservation((int) $ref['id']) : null;      // 3
                    if ($reservation === null || $reservation['status'] !== ReservationStatus::Active->value
                        || $reservation['book_item_id'] !== $itemId || $reservation['cart_id'] !== ($cart['id'] ?? null)) {
                        // Пока брали блокировки, резерв сменился (истёк и экземпляр отложил другой): решение по
                        // устаревшему чтению не принимаем — администратор повторяет действие.
                        throw DomainError::itemUnavailable($itemId, $from->value, null, ['reason' => 'reservation_changed']);
                    }
                    $now = $this->db->now();
                    $after = $this->reservations->releaseLocked(
                        $reservation, $item, ReservationStatus::ReleasedByAdmin, 'admin_block', $now, 'admin', $adminId, $context,
                    );                                                                                                    // 4
                    if ($cart !== null) {
                        $this->reservations->refreshCartLocked($cart, $now, false, null, 'admin', $adminId);
                    }
                    $releasedId = $reservation['id'];
                    $from = ItemStatus::from($after);
                    if ($from !== ItemStatus::Available) {
                        return ['changed' => true, 'status' => $from->value, 'released_reservation_id' => $releasedId];
                    }
                }

                if ($from === ItemStatus::Blocked) {
                    return ['changed' => false, 'status' => $from->value, 'released_reservation_id' => null];
                }
                if ($from === ItemStatus::CheckoutPending) {
                    throw DomainError::itemUnavailable($itemId, $from->value, null, ['required_action' => 'cancel_order']);
                }
                if (!$from->canTransitionTo(ItemStatus::Blocked)) {
                    throw DomainError::itemUnavailable($itemId, $from->value); // sold, withdrawn, sync_missing
                }

                return $this->moveItem($itemId, $from, ItemStatus::Blocked, $context, $adminId) + ['released_reservation_id' => $releasedId];
            });

            return [
                'book_item_id' => $itemId,
                'availability_status' => $result['status'],
                'changed' => $result['changed'],
                'released_reservation_id' => $result['released_reservation_id'],
            ];
        });
    }

    /**
     * Условный переход под уже взятой блокировкой экземпляра + аудит.
     *
     * @param array<string, mixed> $context
     * @return array{changed: true, status: string}
     */
    private function moveItem(int $itemId, ItemStatus $from, ItemStatus $to, array $context, int $adminId): array
    {
        if (!$from->canTransitionTo($to)) {
            throw new \LogicException(\sprintf('Transition %s → %s is not allowed for item #%d', $from->value, $to->value, $itemId));
        }
        $changed = $this->db->execute(
            "UPDATE {$this->db->table('book_items')}
                SET availability_status = %s, status_changed_at = UTC_TIMESTAMP(6)
              WHERE id = %d AND availability_status = %s",
            $to->value,
            $itemId,
            $from->value,
        );
        if ($changed !== 1) {
            throw new \LogicException(\sprintf('Conditional update failed for item #%d %s → %s', $itemId, $from->value, $to->value));
        }
        $this->audit->record('item.status_changed', 'item', $itemId, $from->value, $to->value, $context, 'admin', $adminId);

        return ['changed' => true, 'status' => $to->value];
    }

    // =============================================================================================
    // Синхронизация
    // =============================================================================================

    /**
     * Синхронизация длится минуты, поэтому HTTP-запрос её только ставит в очередь (202 Accepted).
     * Защита от параллельных прогонов — в SyncService (GET_LOCK + uq_sync_runs_one_running); здесь —
     * быстрый ответ «уже идёт / уже в очереди».
     *
     * Флаг unique у as_enqueue_async_action не используется: в Action Scheduler 3.x уникальность — hook + group
     * БЕЗ args (проверено по 3.9.0), и ожидающая ежедневная recurring-задача того же hook-а блокировала бы
     * ручной запуск навсегда. Вместо него — as_has_scheduled_action() с args; редкий двойной клик двух
     * администраторов даст две задачи, вторая получит от SyncService «locked/busy» и ничего не сделает.
     */
    public function runSync(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        return $this->respond($request, function () use ($request): \WP_REST_Response {
            $this->enforceRateLimit('admin_write');
            $adminId = $this->currentUserId();
            $source = (string) $request->get_param('source');

            $known = self::registeredSources();
            if (!\in_array($source, $known, true)) {
                throw new DomainError(
                    'uniundata_invalid_param',
                    \__('Unknown sync source.', 'uniundata-books'),
                    400,
                    ['param' => 'source', 'known_sources' => $known],
                );
            }

            $running = $this->db->getRow(
                "SELECT id, source_name, triggered_by, status, started_at, heartbeat_at,
                        TIMESTAMPDIFF(SECOND, heartbeat_at, UTC_TIMESTAMP(6)) AS heartbeat_age
                   FROM {$this->db->table('book_sync_runs')}
                  WHERE running_source = %s",
                $source,
            );
            // Порог «завис» — тот же, что у SyncService: иначе админ поставил бы задачу, а SyncService ответил бы busy.
            if ($running !== null && (int) $running['heartbeat_age'] < SyncService::STALE_AFTER_SECONDS) {
                return new \WP_REST_Response([
                    'queued' => false,
                    'reason' => 'already_running',
                    'action_id' => null,
                    'running_run' => self::presentRun($running),
                ], 202);
            }

            if (!\function_exists('as_enqueue_async_action') || !\function_exists('as_has_scheduled_action')) {
                throw new \RuntimeException('Action Scheduler is not loaded');
            }
            $args = ['admin', $source];
            if (as_has_scheduled_action(self::HOOK_SYNC, $args, self::AS_GROUP)) {
                return new \WP_REST_Response([
                    'queued' => false,
                    'reason' => 'already_queued',
                    'action_id' => null,
                    'running_run' => $running !== null ? self::presentRun($running) : null,
                ], 202);
            }
            $actionId = (int) as_enqueue_async_action(self::HOOK_SYNC, $args, self::AS_GROUP);

            // Прогона ещё нет: entity_id = NULL (wp_book_audit_log.entity_id NULL-able).
            $this->audit->record('sync.requested', 'sync_run', $running !== null ? (int) $running['id'] : null, null, null, [
                'source' => $source,
                'action_id' => $actionId,
                'stale_running' => $running !== null,
            ], 'admin', $adminId);

            return new \WP_REST_Response([
                'queued' => $actionId > 0,
                'reason' => $running !== null ? 'stale_running_replaced' : null,
                'action_id' => $actionId > 0 ? $actionId : null,
                'running_run' => $running !== null ? self::presentRun($running) : null,
            ], 202);
        });
    }

    /**
     * Имена источников, как их видит SyncService в Plugin.php (фильтр uniundata_sync_sources). Читается только
     * на этом маршруте: SyncService на каждом REST-запросе не создаётся.
     *
     * @return list<string>
     */
    private static function registeredSources(): array
    {
        $names = [];
        foreach ((array) apply_filters('uniundata_sync_sources', []) as $client) {
            if ($client instanceof SourceClientInterface) {
                $names[] = $client->name();
            }
        }

        return array_values(array_unique($names));
    }

    public function listSyncRuns(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        return $this->respond($request, function () use ($request): \WP_REST_Response {
            $page = (int) $request->get_param('page');
            $perPage = (int) $request->get_param('per_page');
            $source = $request->get_param('source');
            $status = $request->get_param('status');

            $where = ['1 = 1'];
            $args = [];
            if (\is_string($source) && $source !== '') {
                $where[] = 'source_name = %s';
                $args[] = $source;
            }
            if (\is_string($status) && \in_array($status, self::SYNC_STATUSES, true)) {
                $where[] = 'status = %s';
                $args[] = $status;
            }
            $whereSql = implode(' AND ', $where);
            $table = $this->db->table('book_sync_runs');

            $total = (int) $this->db->getVar("SELECT COUNT(*) FROM {$table} WHERE {$whereSql}", ...$args);
            $rows = $total === 0 ? [] : $this->db->getResults(
                "SELECT id, source_name, triggered_by, status, started_at, heartbeat_at, finished_at, source_cursor,
                        records_received, records_created, records_updated, records_skipped,
                        items_created, items_updated, items_skipped, items_conflicts, items_missing, items_withdrawn,
                        errors_count, error_log
                   FROM {$table}
                  WHERE {$whereSql}
                  ORDER BY started_at DESC, id DESC
                  LIMIT %d OFFSET %d",
                ...[...$args, $perPage, ($page - 1) * $perPage],
            );

            $runs = array_map(static function (array $r): array {
                $counters = [];
                foreach (['records_received', 'records_created', 'records_updated', 'records_skipped', 'items_created',
                    'items_updated', 'items_skipped', 'items_conflicts', 'items_missing', 'items_withdrawn', 'errors_count'] as $c) {
                    $counters[$c] = (int) $r[$c];
                }
                $errors = $r['error_log'] !== null ? json_decode((string) $r['error_log'], true) : null;

                return self::presentRun($r) + [
                    'finished_at' => Db::toIso8601($r['finished_at']),
                    'source_cursor' => $r['source_cursor'],
                    'counters' => $counters,
                    'error_log' => \is_array($errors) ? $errors : null,
                ];
            }, $rows);

            return self::withPagination(new \WP_REST_Response([
                'runs' => $runs,
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
            ], 200), $total, $perPage);
        });
    }

    /**
     * @param array<string, string|null> $r
     * @return array<string, mixed>
     */
    private static function presentRun(array $r): array
    {
        return [
            'id' => (int) $r['id'],
            'source_name' => (string) $r['source_name'],
            'triggered_by' => (string) $r['triggered_by'],
            'status' => (string) $r['status'],
            'started_at' => Db::toIso8601($r['started_at']),
            'heartbeat_at' => Db::toIso8601($r['heartbeat_at']),
        ];
    }
}
