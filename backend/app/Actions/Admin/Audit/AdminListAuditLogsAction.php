<?php

namespace App\Actions\Admin\Audit;

use App\Support\Admin\AdminAuditLogPresenter;
use App\Support\Cart\Concerns\BuildsCartResult;
use App\Support\Uuid\UuidBinary;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Searchable Admin audit log listing (BLUE V1 Phase B12) - reads the exact
 * canonical `admin_audit_logs` rows every prior phase already writes
 * through App\Support\Admin\AdminAuditLogger, never a second ledger.
 * Deterministic ordering (`created_at DESC, id DESC`) and a bounded page
 * size make this safe against an unbounded, ever-growing table. Every
 * filter maps directly onto one of the table's own existing indexes
 * (`idx_admin_audit_logs_admin_time`, `_action_time`, `_entity`,
 * `_success_time`, `_created_at`).
 */
final class AdminListAuditLogsAction
{
    use BuildsCartResult;

    public const DEFAULT_PER_PAGE = 20;

    public const MAX_PER_PAGE = 100;

    /**
     * @param  array{action_code?: string, entity_type?: string, entity_identifier?: string, was_successful?: bool, actor_uuid?: string, from?: string, to?: string}  $filters
     */
    public function handle(array $filters, int $page, int $perPage): array
    {
        $page = max($page, 1);
        $perPage = min(max($perPage, 1), self::MAX_PER_PAGE);

        if (isset($filters['actor_uuid'])) {
            try {
                $filters['actor_uuid'] = UuidBinary::toBinary($filters['actor_uuid']);
            } catch (InvalidArgumentException) {
                return $this->ok(200, 'Audit log retrieved successfully.', [
                    'audit_logs' => [],
                    'pagination' => ['page' => $page, 'per_page' => $perPage, 'total' => 0, 'last_page' => 1],
                ]);
            }
        }

        $query = DB::table('admin_audit_logs');

        if (isset($filters['action_code'])) {
            $query->where('action_code', $filters['action_code']);
        }

        if (isset($filters['entity_type'])) {
            $query->where('entity_type', $filters['entity_type']);
        }

        if (isset($filters['entity_identifier'])) {
            $query->where('entity_identifier', $filters['entity_identifier']);
        }

        if (isset($filters['was_successful'])) {
            $query->where('was_successful', $filters['was_successful'] ? 1 : 0);
        }

        if (isset($filters['actor_uuid'])) {
            $query->where('admin_user_id', $filters['actor_uuid']);
        }

        if (isset($filters['from'])) {
            $query->where('created_at', '>=', $filters['from']);
        }

        if (isset($filters['to'])) {
            $query->where('created_at', '<=', $filters['to']);
        }

        $total = (clone $query)->count('id');
        $lastPage = max((int) ceil($total / $perPage), 1);

        $rows = $query
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->forPage($page, $perPage)
            ->get([
                'id',
                'admin_user_id',
                'action_code',
                'entity_type',
                'entity_identifier',
                'was_successful',
                'failure_reason',
                'created_at',
            ]);

        return $this->ok(200, 'Audit log retrieved successfully.', [
            'audit_logs' => AdminAuditLogPresenter::presentList($rows),
            'pagination' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'last_page' => $lastPage,
            ],
        ]);
    }
}
