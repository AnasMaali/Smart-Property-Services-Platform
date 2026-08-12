<?php

namespace App\Support\Admin;

use App\Models\User;
use App\Support\Uuid\UuidBinary;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Writes one `admin_audit_logs` row for a successful privileged Admin
 * mutation (BLUE V1 Phase 9B) - technician assigned/reassigned, work
 * started/completed, Booking completed. Never called for a rejected or
 * idempotent-no-op outcome (see each App\Actions\Admin\* wrapper's own
 * outcome mapping) - only a real state change is audited, so a retried call
 * never produces a duplicate row.
 *
 * Deliberately a second, immediate write after the domain Action's own
 * transaction has already committed - not a shared transaction with it.
 * This mirrors the existing
 * App\Actions\Booking\CreateBookingFromSuccessfulPaymentAction /
 * ProcessPaymentWebhookAction precedent in this codebase (a best-effort
 * follow-up write kept deliberately outside the domain transaction so a
 * problem in it can never roll back or block already-committed domain
 * state). `admin_audit_logs` has no foreign key or trigger tying it to the
 * mutation it describes, so the schema does not require - and Phase 9B does
 * not attempt to fake - single-transaction atomicity between the two: a
 * crash in the narrow window between the domain commit and this insert
 * would leave the mutation applied but unaudited, a residual gap reported
 * rather than silently ignored (see the Phase 9B implementation report
 * "Audit Logging").
 *
 * Only the actor's own server-resolved identity
 * (`$request->attributes->get('auth_user')`, never a request field) is ever
 * recorded as `admin_user_id`. Never stores secrets, full request bodies, or
 * raw binary ids - `new_values`/`old_values` are small, caller-chosen JSON
 * objects of already-safe, already-public identifiers.
 */
final class AdminAuditLogger
{
    /**
     * @param  array<string, mixed>|null  $newValues
     * @param  array<string, mixed>|null  $oldValues
     */
    public static function record(
        Request $request,
        User $actor,
        string $actionCode,
        string $entityType,
        string $entityIdentifier,
        ?array $newValues = null,
        ?array $oldValues = null,
    ): void {
        $now = now();

        DB::table('admin_audit_logs')->insert([
            'id' => UuidBinary::toBinary(UuidBinary::generate()),
            'admin_user_id' => UuidBinary::toBinary($actor->id),
            'action_code' => $actionCode,
            'entity_type' => $entityType,
            'entity_identifier' => $entityIdentifier,
            'action_description' => null,
            'old_values' => $oldValues === null ? null : json_encode($oldValues),
            'new_values' => $newValues === null ? null : json_encode($newValues),
            'was_successful' => 1,
            'failure_reason' => null,
            'request_trace_id' => null,
            'ip_address' => self::packIp($request->ip()),
            'user_agent' => self::truncatedUserAgent($request->userAgent()),
            'created_at' => $now->format('Y-m-d H:i:s.u'),
        ]);
    }

    private static function packIp(?string $ip): ?string
    {
        if ($ip === null || $ip === '') {
            return null;
        }

        $packed = inet_pton($ip);

        return $packed === false ? null : $packed;
    }

    private static function truncatedUserAgent(?string $userAgent): ?string
    {
        if ($userAgent === null || trim($userAgent) === '') {
            return null;
        }

        return mb_substr(trim($userAgent), 0, 512);
    }
}
