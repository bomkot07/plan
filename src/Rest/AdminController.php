<?php

declare(strict_types=1);

namespace Uniundata\Books\Rest;

use Uniundata\Books\Domain\DomainError;
use Uniundata\Books\Domain\ItemStatus;
use Uniundata\Books\Infrastructure\AuditLog;
use Uniundata\Books\Infrastructure\Db;
use Uniundata\Books\Service\ReservationService;

/**
 * Административные действия. Вызываются из wp-admin (cookie + X-WP-Nonce) или скриптами персонала
 * через Application Passwords (только HTTPS). Маршруты скрыты из индекса /wp-json (show_in_index=false),
 * но защищены capability, а не скрытием.
 *
 *   POST /admin/reservations/{id}/release — manage_book_reservations: снять резерв, попытка возвращается;
 *   POST /admin/items/{id}/block          — manage_book_catalog: available → blocked;
 *   POST /admin/items/{id}/unblock        — manage_book_catalog: blocked → available;
 *   POST /admin/sync/run                  — manage_book_sync: поставить синхронизацию в Action Scheduler (202);
 *   GET  /admin/sync/runs                 — manage_book_sync: журнал прогонов.
 */
final class AdminController extends RestController
{
    /**
     * Action Scheduler hook синхронизации (регистрирует src/Cron/Scheduler.php). Ручной запуск ставит
     * разовую async-задачу с args [triggered_by, source]; AS передаёт их обработчику позиционно.
     */
    public const HOOK_SYNC = 'uniundata_sync_daily';
    public const AS_GROUP = 'uniundata';

    /** heartbeat старше этого — прогон считается зависшим, новую задачу можно ставить (SyncService его прервёт). */
    private const STALE_HEARTBEAT_SECONDS = 600;
    private const SYNC_STATUSES = ['running', 'succeeded', 'partial', 'failed', 'aborted'];
    private const SOURCE_PATTERN = '^[a-z0-9_]{1,64}$';

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
                    'description' => 'Код источника (wp_book_sync_runs.source_name).',
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
     * По контракту: available → blocked и blocked → available. reserved/checkout_pending сначала освобождаются
     * (release резерва / отмена заказа), sold — терминальный. Повтор (уже blocked / уже available) — 200, changed=false.
     * Блокируется только строка экземпляра (уровень 2 порядка блокировок): у available/blocked экземпляра нет
     * активного резерва и открытого заказа, поэтому корзины и заказы не затрагиваются.
     */
    public function setBlocked(\WP_REST_Request $request, bool $block): \WP_REST_Response|\WP_Error
    {
        return $this->respond($request, function () use ($request, $block): array {
            $this->enforceRateLimit('admin_write');
            $adminId = $this->currentUserId();
            $itemId = (int) $request->get_param('id');
            $note = trim((string) $request->get_param('reason'));

            $result = $this->db->transaction(function () use ($adminId, $itemId, $block, $note): array {
                $item = $this->db->getRow(
                    "SELECT id, availability_status, source_status
                       FROM {$this->db->table('book_items')}
                      WHERE id = %d
                      FOR UPDATE",
                    $itemId,
                );
                if ($item === null) {
                    throw DomainError::itemNotFound($itemId);
                }

                $from = ItemStatus::from((string) $item['availability_status']);
                $to = $block ? ItemStatus::Blocked : ItemStatus::Available;
                if ($from === $to) {
                    return ['changed' => false, 'status' => $from->value];
                }
                $expectedFrom = $block ? ItemStatus::Available : ItemStatus::Blocked;
                if ($from !== $expectedFrom || !$from->canTransitionTo($to)) {
                    throw DomainError::itemUnavailable($itemId, $from->value);
                }
                if (!$block && $item['source_status'] !== 'present') {
                    // Источник сообщает, что книги нет / снята: вернуть в продажу нельзя (контракт: blocked → available).
                    throw new DomainError(
                        'uniundata_item_unavailable',
                        \__('The source reports this book as missing or withdrawn; it can not be put back on sale.', 'uniundata-books'),
                        409,
                        ['book_item_id' => $itemId, 'availability_status' => $from->value, 'source_status' => (string) $item['source_status']],
                    );
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

                $context = ['reason' => $block ? 'admin_block' : 'admin_unblock'];
                if ($note !== '') {
                    $context['note'] = $note;
                }
                $this->audit->record('item.status_changed', 'item', $itemId, $from->value, $to->value, $context, 'admin', $adminId);

                return ['changed' => true, 'status' => $to->value];
            });

            return [
                'book_item_id' => $itemId,
                'availability_status' => $result['status'],
                'changed' => $result['changed'],
            ];
        });
    }

    // =============================================================================================
    // Синхронизация
    // =============================================================================================

    /**
     * Синхронизация длится минуты, поэтому HTTP-запрос её только ставит в очередь (202 Accepted).
     * Защита от параллельных прогонов — в SyncService (GET_LOCK + uq_sync_runs_one_running); здесь —
     * быстрый ответ «уже идёт» и unique-задача Action Scheduler.
     */
    public function runSync(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        return $this->respond($request, function () use ($request): \WP_REST_Response {
            $this->enforceRateLimit('admin_write');
            $adminId = $this->currentUserId();
            $source = (string) $request->get_param('source');

            $running = $this->db->getRow(
                "SELECT id, source_name, triggered_by, status, started_at, heartbeat_at,
                        TIMESTAMPDIFF(SECOND, heartbeat_at, UTC_TIMESTAMP(6)) AS heartbeat_age
                   FROM {$this->db->table('book_sync_runs')}
                  WHERE running_source = %s",
                $source,
            );
            if ($running !== null && (int) $running['heartbeat_age'] < self::STALE_HEARTBEAT_SECONDS) {
                return new \WP_REST_Response([
                    'queued' => false,
                    'reason' => 'already_running',
                    'running_run' => self::presentRun($running),
                ], 202);
            }

            if (!\function_exists('as_enqueue_async_action')) {
                throw new \RuntimeException('Action Scheduler is not loaded');
            }
            // unique=true: пока такая задача ждёт или выполняется, вторая не ставится (возвращается 0).
            $actionId = (int) as_enqueue_async_action(self::HOOK_SYNC, ['admin', $source], self::AS_GROUP, true);

            $this->audit->record('sync.requested', 'sync_run', $running !== null ? (int) $running['id'] : 0, null, null, [
                'source' => $source,
                'action_id' => $actionId,
                'stale_running' => $running !== null,
            ], 'admin', $adminId);

            return new \WP_REST_Response([
                'queued' => $actionId > 0,
                'reason' => $actionId > 0 ? ($running !== null ? 'stale_running_replaced' : null) : 'already_queued',
                'action_id' => $actionId > 0 ? $actionId : null,
                'running_run' => $running !== null ? self::presentRun($running) : null,
            ], 202);
        });
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
