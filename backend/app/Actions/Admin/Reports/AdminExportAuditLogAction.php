<?php

namespace App\Actions\Admin\Reports;

use App\Support\Admin\AdminAuditLogPresenter;
use App\Support\Cart\Concerns\BuildsCartResult;
use App\Support\Uuid\UuidBinary;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Print/CSV/PDF export of the exact same filtered `admin_audit_logs` result
 * set App\Actions\Admin\Audit\AdminListAuditLogsAction already lists on
 * screen - this Action deliberately mirrors that Action's WHERE-clause
 * construction field-for-field (see baseQuery() below) rather than
 * refactoring the already-shipped, tested AdminListAuditLogsAction to share
 * code, so this feature carries zero risk of changing that Action's
 * existing behavior. It is never a second audit trail and never mutates
 * anything - the Audit Log stays read-only. Row shape is
 * App\Support\Admin\AdminAuditLogPresenter::presentList()'s own shape -
 * reused as-is, never re-derived.
 */
final class AdminExportAuditLogAction
{
    use BuildsCartResult;

    public const MAX_PDF_ROWS = 2000;

    private const EXPORT_WINDOW_SIZE = 500;

    /**
     * @param  array{action_code?: string, entity_type?: string, entity_identifier?: string, was_successful?: bool, actor_uuid?: string, from?: string, to?: string}  $filters
     * @return array{summary: array{total: int}, rows: iterable<int, array<string, mixed>>, truncated: bool, total: int}|null
     */
    public function exportRows(array $filters, ?int $limit = null): ?array
    {
        if (isset($filters['actor_uuid'])) {
            try {
                $filters['actor_uuid'] = UuidBinary::toBinary($filters['actor_uuid']);
            } catch (InvalidArgumentException) {
                return null;
            }
        }

        $total = (clone $this->baseQuery($filters))->count('id');

        return [
            'summary' => ['total' => $total],
            'rows' => $limit === null ? $this->windowedRows($filters) : AdminAuditLogPresenter::presentList($this->baseQuery($filters)->orderByDesc('created_at')->orderByDesc('id')->limit($limit)->get($this->selectColumns())),
            'truncated' => $limit !== null && $total > $limit,
            'total' => $total,
        ];
    }

    /**
     * Mirrors App\Actions\Admin\Audit\AdminListAuditLogsAction::handle()'s
     * WHERE-clause construction exactly - see this class's own docblock for
     * why it is a deliberate mirror, not a shared refactor.
     */
    private function baseQuery(array $filters): Builder
    {
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

        return $query;
    }

    /**
     * @return array<int, string>
     */
    private function selectColumns(): array
    {
        return ['id', 'admin_user_id', 'action_code', 'entity_type', 'entity_identifier', 'was_successful', 'failure_reason', 'created_at'];
    }

    /**
     * @return \Generator<int, array<string, mixed>>
     */
    private function windowedRows(array $filters): \Generator
    {
        $page = 1;

        do {
            $chunk = $this->baseQuery($filters)
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->forPage($page, self::EXPORT_WINDOW_SIZE)
                ->get($this->selectColumns());

            foreach (AdminAuditLogPresenter::presentList($chunk) as $row) {
                yield $row;
            }

            $page++;
        } while ($chunk->count() === self::EXPORT_WINDOW_SIZE);
    }
}
