<?php

namespace App\Support\Admin\WebAuthn;

use App\Models\User;
use App\Support\Uuid\UuidBinary;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Uid\Uuid;
use Webauthn\CredentialRecord;
use Webauthn\TrustPath\EmptyTrustPath;

/**
 * The only place `admin_webauthn_credentials` is read or written (BLUE V1
 * Phase A2.2) - no raw DB query for this table exists in any controller,
 * Action, or ceremony service. Translates between the plain DB row and
 * web-auth/webauthn-lib's own Webauthn\CredentialRecord value object, so
 * the ceremony services never touch SQL directly.
 *
 * Every read here excludes revoked credentials (`revoked_at IS NULL`) -
 * a revoked credential can never be resolved for assertion verification,
 * regardless of caller. Revocation itself (setting revoked_at) is not
 * implemented in this phase - no route exists yet that can revoke a
 * credential - so no revoke() method exists here either; it is added when
 * that capability is actually built.
 */
final class AdminWebAuthnCredentialRepository
{
    public function activeCount(string $userIdBinary): int
    {
        return DB::table('admin_webauthn_credentials')
            ->where('user_id', $userIdBinary)
            ->whereNull('revoked_at')
            ->count();
    }

    /**
     * @return list<CredentialRecord>
     */
    public function listActive(string $userIdBinary): array
    {
        return DB::table('admin_webauthn_credentials')
            ->where('user_id', $userIdBinary)
            ->whereNull('revoked_at')
            ->get()
            ->map(fn (object $row): CredentialRecord => $this->toCredentialRecord($row))
            ->all();
    }

    public function findActiveByCredentialId(string $rawCredentialId): ?CredentialRecord
    {
        $row = DB::table('admin_webauthn_credentials')
            ->where('credential_id', $rawCredentialId)
            ->whereNull('revoked_at')
            ->first();

        return $row === null ? null : $this->toCredentialRecord($row);
    }

    /**
     * Persists only public credential material - never a private key,
     * biometric data, or authenticator PIN, none of which this object ever
     * carries in the first place.
     */
    public function store(CredentialRecord $record, User $owner, ?string $label = null): void
    {
        $now = now();

        DB::table('admin_webauthn_credentials')->insert([
            'id' => UuidBinary::toBinary(UuidBinary::generate()),
            'user_id' => UuidBinary::toBinary($owner->id),
            'label' => $label,
            'credential_id' => $record->publicKeyCredentialId,
            'public_key' => $record->credentialPublicKey,
            'sign_count' => $record->counter,
            'transports' => $record->transports === [] ? null : json_encode($record->transports),
            'aaguid' => $this->aaguidToBinary($record->aaguid),
            'backup_eligible' => $record->backupEligible,
            'backup_state' => $record->backupStatus,
            'revoked_at' => null,
            'revoked_by_user_id' => null,
            'revoke_reason' => null,
            'last_used_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /**
     * Updates sign_count, backup metadata, and last_used_at after a
     * successful assertion (login or step-up) or registration verification.
     * Deliberately scoped to non-revoked rows only, defense in depth even
     * though a revoked credential should already have been excluded before
     * this is ever called.
     */
    public function updateAfterVerification(CredentialRecord $record): void
    {
        DB::table('admin_webauthn_credentials')
            ->where('credential_id', $record->publicKeyCredentialId)
            ->whereNull('revoked_at')
            ->update([
                'sign_count' => $record->counter,
                'backup_eligible' => $record->backupEligible,
                'backup_state' => $record->backupStatus,
                'last_used_at' => now(),
                'updated_at' => now(),
            ]);
    }

    private function toCredentialRecord(object $row): CredentialRecord
    {
        return CredentialRecord::create(
            publicKeyCredentialId: $row->credential_id,
            type: 'public-key',
            transports: $row->transports === null ? [] : json_decode((string) $row->transports, true),
            attestationType: 'none',
            trustPath: EmptyTrustPath::create(),
            aaguid: $this->aaguidToUuid($row->aaguid),
            credentialPublicKey: $row->public_key,
            userHandle: $row->user_id,
            counter: (int) $row->sign_count,
            backupEligible: $row->backup_eligible === null ? null : (bool) $row->backup_eligible,
            backupStatus: $row->backup_state === null ? null : (bool) $row->backup_state,
        );
    }

    /**
     * aaguid is stored in its natural (non-index-optimized) byte order -
     * unlike this schema's own generated ids, it is an externally-supplied
     * opaque authenticator-model identifier, never passed through
     * UuidBinary's index-locality byte-swap. Symfony\Component\Uid\Uuid's
     * own toBinary()/fromBinary() already do exactly this natural-order
     * conversion.
     */
    private function aaguidToBinary(Uuid $aaguid): ?string
    {
        if ($aaguid->toRfc4122() === '00000000-0000-0000-0000-000000000000') {
            return null;
        }

        return $aaguid->toBinary();
    }

    private function aaguidToUuid(?string $binary): Uuid
    {
        return $binary === null
            ? Uuid::fromString('00000000-0000-0000-0000-000000000000')
            : Uuid::fromBinary($binary);
    }
}
