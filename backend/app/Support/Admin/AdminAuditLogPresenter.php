<?php

namespace App\Support\Admin;

use App\Support\Uuid\UuidBinary;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * BLUE V1 Phase B12. Presents the exact canonical `admin_audit_logs` rows
 * every prior Admin phase already writes through App\Support\Admin\
 * AdminAuditLogger - never a second audit trail. Deliberately never
 * returns `old_values`/`new_values`: no per-`action_code` safe-field
 * whitelist exists anywhere in this codebase, matching the same
 * conservative choice App\Actions\Admin\Dashboard\AdminGetDashboardAction
 * (B10) already made for its own 10-row "recent activity" snippet.
 * `action_description`/`request_trace_id` are confirmed always-null at
 * every AdminAuditLogger::write() call site (never populated anywhere)
 * and are omitted as meaningless.
 */
final class AdminAuditLogPresenter
{
    /**
     * $rows must already carry `admin_audit_logs.admin_user_id` alongside
     * the raw `admin_audit_logs` columns (see
     * App\Actions\Admin\Audit\AdminListAuditLogsAction).
     *
     * @return array<int, array<string, mixed>>
     */
    public static function presentList(Collection $rows): array
    {
        if ($rows->isEmpty()) {
            return [];
        }

        $actorIds = $rows->pluck('admin_user_id')->unique()->values()->all();
        $actors = self::actorSummaries($actorIds);

        return $rows->map(fn (object $row) => self::baseFields($row, $actors))->all();
    }

    /**
     * @return array<string, mixed>
     */
    public static function detail(object $row): array
    {
        $actors = self::actorSummaries([$row->admin_user_id]);

        return array_merge(self::baseFields($row, $actors), [
            'ip_address' => self::unpackIp($row->ip_address),
            'user_agent' => $row->user_agent,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private static function baseFields(object $row, Collection $actors): array
    {
        $actor = $actors->get($row->admin_user_id);

        return [
            'uuid' => UuidBinary::toString($row->id),
            'action_code' => $row->action_code,
            'entity_type' => $row->entity_type,
            'entity_identifier' => $row->entity_identifier,
            'was_successful' => (bool) $row->was_successful,
            'failure_reason' => $row->failure_reason,
            'actor' => $actor === null ? null : [
                'uuid' => UuidBinary::toString($row->admin_user_id),
                'full_name' => $actor->full_name,
            ],
            'created_at' => Carbon::parse($row->created_at)->toIso8601String(),
        ];
    }

    private static function unpackIp(?string $packed): ?string
    {
        if ($packed === null || $packed === '') {
            return null;
        }

        $unpacked = inet_ntop($packed);

        return $unpacked === false ? null : $unpacked;
    }

    /**
     * @param  array<int, string>  $actorIdsBinary
     */
    private static function actorSummaries(array $actorIdsBinary): Collection
    {
        return DB::table('users')
            ->join('user_profiles', 'user_profiles.user_id', '=', 'users.id')
            ->whereIn('users.id', $actorIdsBinary)
            ->get(['users.id', 'user_profiles.full_name'])
            ->keyBy('id');
    }
}
