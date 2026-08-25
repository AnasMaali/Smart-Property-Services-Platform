<?php

namespace App\Support\Admin\WebAuthn;

use App\Models\User;
use App\Support\Uuid\UuidBinary;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Centralized WebAuthn challenge issuance/consumption (BLUE V1 Phase A2.2),
 * shared by registration, login-assertion, and step-up ceremonies via
 * AdminWebAuthnChallengePurpose - no duplicated challenge logic between them.
 *
 * Uses the admin_webauthn_challenges table exactly as Phase A2.1 defined it:
 * only SHA-256(raw challenge) is ever persisted (`challenge_hash`), never the
 * raw challenge itself. issue() returns the raw challenge bytes to the
 * caller exactly once, to be embedded in the WebAuthn options shown to the
 * browser; consume() re-hashes whatever the caller later presents and
 * compares against the stored hash.
 *
 * consume() is atomic and single-use: it locks the matching row
 * (SELECT ... FOR UPDATE) inside a transaction, checks expiry/consumed
 * state, and marks it consumed in the same transaction - a concurrent
 * replay of the same challenge cannot both see it as valid.
 *
 * A challenge row is looked up by the exact (challenge_hash, user_id,
 * purpose_id) triple. A wrong user, a wrong purpose, or an unknown
 * challenge all collapse to the same NOT_FOUND outcome (see
 * AdminWebAuthnChallengeOutcome) - never distinguished for the caller.
 */
final class AdminWebAuthnChallengeService
{
    public function __construct(private readonly AdminWebAuthnConfig $config) {}

    /**
     * Generates a cryptographically secure challenge, persists only its
     * hash, and returns the raw challenge bytes (used exactly once, to
     * build the WebAuthn options shown to the browser).
     */
    public function issue(User $user, AdminWebAuthnChallengePurpose $purpose): string
    {
        $rawChallenge = random_bytes(32);
        $now = now();

        DB::table('admin_webauthn_challenges')->insert([
            'id' => UuidBinary::toBinary(UuidBinary::generate()),
            'user_id' => UuidBinary::toBinary($user->id),
            'purpose_id' => $this->purposeId($purpose),
            'challenge_hash' => hash('sha256', $rawChallenge, true),
            'expires_at' => $now->copy()->addSeconds($this->config->challengeTtlSeconds()),
            'consumed_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $rawChallenge;
    }

    /**
     * Atomically validates and consumes a challenge previously issued for
     * this exact user + purpose. $rawChallenge is the raw challenge bytes
     * decoded from the client's response (Webauthn\CollectedClientData::$challenge
     * after deserialization) - never the base64url string directly.
     */
    public function consume(string $userIdBinary, AdminWebAuthnChallengePurpose $purpose, string $rawChallenge): AdminWebAuthnChallengeOutcome
    {
        $purposeId = $this->purposeId($purpose);
        $hash = hash('sha256', $rawChallenge, true);

        return DB::transaction(function () use ($userIdBinary, $purposeId, $hash): AdminWebAuthnChallengeOutcome {
            $row = DB::table('admin_webauthn_challenges')
                ->where('challenge_hash', $hash)
                ->where('user_id', $userIdBinary)
                ->where('purpose_id', $purposeId)
                ->lockForUpdate()
                ->first();

            if ($row === null) {
                return AdminWebAuthnChallengeOutcome::NOT_FOUND;
            }

            if ($row->consumed_at !== null) {
                return AdminWebAuthnChallengeOutcome::ALREADY_CONSUMED;
            }

            if (Carbon::parse($row->expires_at)->isPast()) {
                return AdminWebAuthnChallengeOutcome::EXPIRED;
            }

            DB::table('admin_webauthn_challenges')
                ->where('id', $row->id)
                ->update(['consumed_at' => now()]);

            return AdminWebAuthnChallengeOutcome::VALID;
        });
    }

    private function purposeId(AdminWebAuthnChallengePurpose $purpose): int
    {
        $id = DB::table('admin_webauthn_challenge_purposes')
            ->where('code', $purpose->value)
            ->where('is_active', 1)
            ->value('id');

        if ($id === null) {
            throw new RuntimeException("Missing required reference row: admin_webauthn_challenge_purposes.code = {$purpose->value}");
        }

        return (int) $id;
    }
}
