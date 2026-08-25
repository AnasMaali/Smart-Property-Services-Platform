<?php

namespace App\Support\Admin\WebAuthn;

use App\Models\User;
use App\Support\Uuid\UuidBinary;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
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
 *
 * SESSION BINDING (BLUE V1 Phase A2.5): every method here accepts an
 * optional `$authSessionIdBinary`, written to/matched against
 * `admin_webauthn_challenges.auth_session_id`. REGISTRATION and
 * LOGIN_ASSERTION challenges are always issued with this null - no
 * authenticated session exists yet at those points in the flow. STEP_UP
 * challenges are always issued and consumed with the CURRENT authenticated
 * Admin session's id (enforced by AdminWebAuthnAssertionService, the only
 * caller that ever passes a non-null value for STEP_UP) - a STEP_UP
 * challenge requested under session A can therefore never be consumed to
 * step up session B, even for the same Admin, because consume()'s lookup
 * filters on the exact session id when one is supplied.
 */
final class AdminWebAuthnChallengeService
{
    public function __construct(private readonly AdminWebAuthnConfig $config) {}

    /**
     * Generates a cryptographically secure challenge, persists only its
     * hash, and returns the raw challenge bytes (used exactly once, to
     * build the WebAuthn options shown to the browser) plus the challenge
     * row's own uuid (usable as an opaque, single-use login/enrollment/
     * step-up ticket - see AdminWebAuthnChallengeIssued).
     */
    public function issue(User $user, AdminWebAuthnChallengePurpose $purpose, ?string $authSessionIdBinary = null): AdminWebAuthnChallengeIssued
    {
        $rawChallenge = random_bytes(32);
        $ticket = UuidBinary::generate();
        $now = now();

        DB::table('admin_webauthn_challenges')->insert([
            'id' => UuidBinary::toBinary($ticket),
            'user_id' => UuidBinary::toBinary($user->id),
            'auth_session_id' => $authSessionIdBinary,
            'purpose_id' => $this->purposeId($purpose),
            'challenge_hash' => hash('sha256', $rawChallenge, true),
            'expires_at' => $now->copy()->addSeconds($this->config->challengeTtlSeconds()),
            'consumed_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return new AdminWebAuthnChallengeIssued($ticket, $rawChallenge);
    }

    /**
     * Read-only lookup used by Stage 2 (MFA verify) / first-credential
     * enrollment to resolve which Admin a client-presented $ticket belongs
     * to, without consuming it - actual consumption still happens exactly
     * once, atomically, inside consume() (called by
     * AdminWebAuthnRegistrationService/AdminWebAuthnAssertionService's own
     * verify() methods against the hash of the client's actual WebAuthn
     * response). A ticket that is unknown, already consumed, or expired
     * all return null - never distinguished for the caller.
     * $authSessionIdBinary (BLUE V1 Phase A2.5), when supplied, additionally
     * requires the ticket's own bound session to match exactly - used by
     * the STEP_UP verify flow so a ticket issued under a different session
     * (even for the same Admin) is rejected here, before any WebAuthn
     * response is even deserialized.
     */
    public function resolvePendingTicket(string $ticket, AdminWebAuthnChallengePurpose $purpose, ?string $authSessionIdBinary = null): ?User
    {
        if (! Str::isUuid($ticket)) {
            return null;
        }

        $row = DB::table('admin_webauthn_challenges')
            ->where('id', UuidBinary::toBinary($ticket))
            ->where('purpose_id', $this->purposeId($purpose))
            ->when($authSessionIdBinary !== null, fn ($query) => $query->where('auth_session_id', $authSessionIdBinary))
            ->whereNull('consumed_at')
            ->where('expires_at', '>', now())
            ->first();

        if ($row === null) {
            return null;
        }

        return User::where('id', $row->user_id)->first();
    }

    /**
     * Atomically validates and consumes a challenge previously issued for
     * this exact user + purpose. $rawChallenge is the raw challenge bytes
     * decoded from the client's response (Webauthn\CollectedClientData::$challenge
     * after deserialization) - never the base64url string directly.
     *
     * $authSessionIdBinary (BLUE V1 Phase A2.5): when supplied, the lookup
     * additionally requires the challenge row's own `auth_session_id` to
     * match exactly. This is the actual structural enforcement of STEP_UP
     * session binding - independent of, and in addition to, the ticket-based
     * check in resolvePendingTicket() above - because this is the lookup a
     * client-presented WebAuthn response is matched against by its
     * challenge hash alone; without this filter, a validly-signed assertion
     * whose embedded challenge belongs to a *different* session (e.g.
     * obtained via that other session's own step-up/request call) could
     * otherwise be consumed here regardless of which ticket the caller
     * separately supplied.
     */
    public function consume(string $userIdBinary, AdminWebAuthnChallengePurpose $purpose, string $rawChallenge, ?string $authSessionIdBinary = null): AdminWebAuthnChallengeOutcome
    {
        $purposeId = $this->purposeId($purpose);
        $hash = hash('sha256', $rawChallenge, true);

        return DB::transaction(function () use ($userIdBinary, $purposeId, $hash, $authSessionIdBinary): AdminWebAuthnChallengeOutcome {
            $row = DB::table('admin_webauthn_challenges')
                ->where('challenge_hash', $hash)
                ->where('user_id', $userIdBinary)
                ->where('purpose_id', $purposeId)
                ->when($authSessionIdBinary !== null, fn ($query) => $query->where('auth_session_id', $authSessionIdBinary))
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
