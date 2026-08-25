<?php

namespace App\Support\Admin;

use App\Models\User;
use App\Support\Uuid\UuidBinary;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Central writer for BLUE Admin audit records.
 *
 * Existing privileged Admin domain mutations use record() for successful
 * state changes. BLUE V1 Phase A2.6 extends the same logger with
 * recordFailure() for authenticated security failures such as failed MFA
 * and failed WebAuthn step-up verification.
 *
 * This logger deliberately does not open or commit a transaction itself.
 * The calling Action owns transaction boundaries. This allows successful
 * security/domain state changes and their audit row to be committed
 * atomically whenever the caller places both inside the same transaction.
 *
 * Never pass secrets, raw request bodies, passwords, access/refresh tokens,
 * WebAuthn challenges/assertions/signatures, credential IDs, or public keys
 * through old_values/new_values.
 */
final class AdminAuditLogger
{
    /**
     * Record a successful Admin audit event.
     *
     * Existing call sites remain source-compatible with the pre-A2.6 API.
     *
     * @param  array<string, mixed>|null  $newValues
     * @param  array<string, mixed>|null  $oldValues
     */
    public static function record(
        Request $request,
        User $actor,
        string $actionCode,
        string $entityType,
        ?string $entityIdentifier,
        ?array $newValues = null,
        ?array $oldValues = null,
    ): void {
        self::write(
            request: $request,
            actor: $actor,
            actionCode: $actionCode,
            entityType: $entityType,
            entityIdentifier: $entityIdentifier,
            wasSuccessful: true,
            failureReason: null,
            newValues: $newValues,
            oldValues: $oldValues,
        );
    }

    /**
     * Record a failed authenticated Admin security event.
     *
     * failureReason must remain generic and must never contain raw
     * WebAuthn/credential/token material or attacker-controlled request
     * payloads.
     *
     * @param  array<string, mixed>|null  $newValues
     * @param  array<string, mixed>|null  $oldValues
     */
    public static function recordFailure(
        Request $request,
        User $actor,
        string $actionCode,
        string $entityType,
        ?string $entityIdentifier,
        string $failureReason,
        ?array $newValues = null,
        ?array $oldValues = null,
    ): void {
        self::write(
            request: $request,
            actor: $actor,
            actionCode: $actionCode,
            entityType: $entityType,
            entityIdentifier: $entityIdentifier,
            wasSuccessful: false,
            failureReason: $failureReason,
            newValues: $newValues,
            oldValues: $oldValues,
        );
    }

    /**
     * @param  array<string, mixed>|null  $newValues
     * @param  array<string, mixed>|null  $oldValues
     */
    private static function write(
        Request $request,
        User $actor,
        string $actionCode,
        string $entityType,
        ?string $entityIdentifier,
        bool $wasSuccessful,
        ?string $failureReason,
        ?array $newValues,
        ?array $oldValues,
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
            'was_successful' => $wasSuccessful ? 1 : 0,
            'failure_reason' => $failureReason,
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
