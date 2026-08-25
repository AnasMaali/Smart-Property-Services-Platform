<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use App\Support\Admin\WebAuthn\AdminWebAuthnAssertionOutcome;
use App\Support\Admin\WebAuthn\AdminWebAuthnAssertionService;
use App\Support\Admin\WebAuthn\AdminWebAuthnChallengeOutcome;
use App\Support\Admin\WebAuthn\AdminWebAuthnChallengePurpose;
use App\Support\Admin\WebAuthn\AdminWebAuthnChallengeService;
use App\Support\Admin\WebAuthn\AdminWebAuthnCredentialRepository;
use App\Support\Admin\WebAuthn\AdminWebAuthnRegistrationOutcome;
use App\Support\Admin\WebAuthn\AdminWebAuthnRegistrationService;
use App\Support\Uuid\UuidBinary;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Support\WebAuthn\WebAuthnTestAuthenticator;
use Tests\TestCase;

/**
 * BLUE V1 Phase A2.2 - WebAuthn Library + Ceremony Foundation.
 *
 * Exercises AdminWebAuthnChallengeService, AdminWebAuthnCredentialRepository,
 * AdminWebAuthnRegistrationService, and AdminWebAuthnAssertionService
 * directly - no HTTP route exists for any of this yet (that is Phase
 * A2.3+). WebAuthnTestAuthenticator (tests/Support/WebAuthn) builds real,
 * spec-compliant attestation/assertion JSON using real ECDSA keys - the
 * exact same shape a browser would send - so every ceremony step under
 * test runs through the real web-auth/webauthn-lib validation logic, never
 * a shortcut.
 */
class AdminWebAuthnCeremonyTest extends TestCase
{
    use DatabaseTransactions;

    private const RP_ID = 'admin.blue.test';

    private const ORIGIN = 'https://admin.blue.test';

    private static int $sequence = 0;

    private function registrationService(): AdminWebAuthnRegistrationService
    {
        return app(AdminWebAuthnRegistrationService::class);
    }

    private function assertionService(): AdminWebAuthnAssertionService
    {
        return app(AdminWebAuthnAssertionService::class);
    }

    private function challengeService(): AdminWebAuthnChallengeService
    {
        return app(AdminWebAuthnChallengeService::class);
    }

    private function credentialRepository(): AdminWebAuthnCredentialRepository
    {
        return app(AdminWebAuthnCredentialRepository::class);
    }

    /**
     * @param  array<int, string>  $roleCodes
     */
    private function createUser(array $roleCodes): User
    {
        self::$sequence++;
        $userUuid = UuidBinary::generate();
        $now = now();

        DB::table('users')->insert([
            'id' => UuidBinary::toBinary($userUuid),
            'phone_number' => '+97157'.str_pad((string) self::$sequence, 7, '0', STR_PAD_LEFT),
            'email' => 'webauthn.ceremony.'.self::$sequence.'@example.com',
            'password_hash' => bcrypt('Passw0rd123'),
            'account_status_id' => DB::table('user_account_statuses')->where('code', 'ACTIVE')->value('id'),
            'phone_verified_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('user_profiles')->insert([
            'user_id' => UuidBinary::toBinary($userUuid),
            'full_name' => 'WebAuthn Ceremony Test '.self::$sequence,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        foreach ($roleCodes as $roleCode) {
            DB::table('user_roles')->insert([
                'user_id' => UuidBinary::toBinary($userUuid),
                'role_id' => DB::table('roles')->where('code', $roleCode)->value('id'),
                'assigned_by_user_id' => null,
                'assigned_at' => $now,
            ]);
        }

        return User::where('id', UuidBinary::toBinary($userUuid))->firstOrFail();
    }

    private function registerCredential(User $admin, ?WebAuthnTestAuthenticator $authenticator = null, bool $stepUpVerified = false): WebAuthnTestAuthenticator
    {
        $authenticator ??= new WebAuthnTestAuthenticator;

        $options = $this->registrationService()->options($admin, $stepUpVerified);
        $json = $authenticator->attestationResponseJson(self::RP_ID, $options->options->challenge, self::ORIGIN);
        $result = $this->registrationService()->verify($admin, $stepUpVerified, $json, self::RP_ID);

        if ($result->outcome !== AdminWebAuthnRegistrationOutcome::REGISTERED) {
            throw new \RuntimeException('Test fixture setup failed: expected REGISTERED, got '.$result->outcome->name);
        }

        return $authenticator;
    }

    // -----------------------------------------------------------------
    // Challenge (AdminWebAuthnChallengeService)
    // -----------------------------------------------------------------

    public function test_challenge_is_cryptographically_generated_and_unique(): void
    {
        $admin = $this->createUser(['ADMIN']);

        $first = $this->challengeService()->issue($admin, AdminWebAuthnChallengePurpose::REGISTRATION);
        $second = $this->challengeService()->issue($admin, AdminWebAuthnChallengePurpose::REGISTRATION);

        $this->assertSame(32, strlen($first));
        $this->assertNotSame($first, $second);
    }

    public function test_challenge_purpose_binding_is_enforced(): void
    {
        $admin = $this->createUser(['ADMIN']);
        $raw = $this->challengeService()->issue($admin, AdminWebAuthnChallengePurpose::REGISTRATION);

        $wrongPurpose = $this->challengeService()->consume(UuidBinary::toBinary($admin->id), AdminWebAuthnChallengePurpose::LOGIN_ASSERTION, $raw);
        $rightPurpose = $this->challengeService()->consume(UuidBinary::toBinary($admin->id), AdminWebAuthnChallengePurpose::REGISTRATION, $raw);

        $this->assertSame(AdminWebAuthnChallengeOutcome::NOT_FOUND, $wrongPurpose);
        $this->assertSame(AdminWebAuthnChallengeOutcome::VALID, $rightPurpose);
    }

    public function test_challenge_wrong_purpose_step_up_is_rejected(): void
    {
        $admin = $this->createUser(['ADMIN']);
        $raw = $this->challengeService()->issue($admin, AdminWebAuthnChallengePurpose::REGISTRATION);

        $outcome = $this->challengeService()->consume(UuidBinary::toBinary($admin->id), AdminWebAuthnChallengePurpose::STEP_UP, $raw);

        $this->assertSame(AdminWebAuthnChallengeOutcome::NOT_FOUND, $outcome);
    }

    public function test_challenge_user_binding_is_enforced(): void
    {
        $admin = $this->createUser(['ADMIN']);
        $otherAdmin = $this->createUser(['ADMIN']);
        $raw = $this->challengeService()->issue($admin, AdminWebAuthnChallengePurpose::LOGIN_ASSERTION);

        $wrongUser = $this->challengeService()->consume(UuidBinary::toBinary($otherAdmin->id), AdminWebAuthnChallengePurpose::LOGIN_ASSERTION, $raw);
        $rightUser = $this->challengeService()->consume(UuidBinary::toBinary($admin->id), AdminWebAuthnChallengePurpose::LOGIN_ASSERTION, $raw);

        $this->assertSame(AdminWebAuthnChallengeOutcome::NOT_FOUND, $wrongUser);
        $this->assertSame(AdminWebAuthnChallengeOutcome::VALID, $rightUser);
    }

    public function test_expired_challenge_is_rejected(): void
    {
        $admin = $this->createUser(['ADMIN']);
        $raw = $this->challengeService()->issue($admin, AdminWebAuthnChallengePurpose::REGISTRATION);

        // Advance virtual time past the configured TTL (ADMIN_WEBAUTHN_CHALLENGE_TTL_SECONDS=300
        // in phpunit.xml) rather than back-dating expires_at directly, which
        // would violate the expires_at > created_at CHECK constraint.
        Carbon::setTestNow(now()->addSeconds(301));

        try {
            $outcome = $this->challengeService()->consume(UuidBinary::toBinary($admin->id), AdminWebAuthnChallengePurpose::REGISTRATION, $raw);
        } finally {
            Carbon::setTestNow(null);
        }

        $this->assertSame(AdminWebAuthnChallengeOutcome::EXPIRED, $outcome);
    }

    public function test_challenge_is_single_use(): void
    {
        $admin = $this->createUser(['ADMIN']);
        $raw = $this->challengeService()->issue($admin, AdminWebAuthnChallengePurpose::REGISTRATION);

        $first = $this->challengeService()->consume(UuidBinary::toBinary($admin->id), AdminWebAuthnChallengePurpose::REGISTRATION, $raw);
        $second = $this->challengeService()->consume(UuidBinary::toBinary($admin->id), AdminWebAuthnChallengePurpose::REGISTRATION, $raw);

        $this->assertSame(AdminWebAuthnChallengeOutcome::VALID, $first);
        $this->assertSame(AdminWebAuthnChallengeOutcome::ALREADY_CONSUMED, $second);
    }

    public function test_replaying_a_consumed_registration_response_is_rejected(): void
    {
        $admin = $this->createUser(['ADMIN']);
        $authenticator = new WebAuthnTestAuthenticator;

        $options = $this->registrationService()->options($admin, false);
        $json = $authenticator->attestationResponseJson(self::RP_ID, $options->options->challenge, self::ORIGIN);

        $first = $this->registrationService()->verify($admin, false, $json, self::RP_ID);
        $second = $this->registrationService()->verify($admin, true, $json, self::RP_ID);

        $this->assertSame(AdminWebAuthnRegistrationOutcome::REGISTERED, $first->outcome);
        $this->assertSame(AdminWebAuthnRegistrationOutcome::CHALLENGE_INVALID, $second->outcome);
    }

    // -----------------------------------------------------------------
    // Credentials (AdminWebAuthnCredentialRepository)
    // -----------------------------------------------------------------

    public function test_multiple_credentials_can_belong_to_one_admin(): void
    {
        $admin = $this->createUser(['ADMIN']);
        $this->registerCredential($admin);
        $this->registerCredential($admin, null, stepUpVerified: true);

        // A third registration must assert step-up since >=1 credential now exists.
        $authenticator = new WebAuthnTestAuthenticator;
        $options = $this->registrationService()->options($admin, stepUpVerified: true);
        $json = $authenticator->attestationResponseJson(self::RP_ID, $options->options->challenge, self::ORIGIN);
        $result = $this->registrationService()->verify($admin, true, $json, self::RP_ID);

        $this->assertSame(AdminWebAuthnRegistrationOutcome::REGISTERED, $result->outcome);
        $this->assertSame(3, $this->credentialRepository()->activeCount(UuidBinary::toBinary($admin->id)));
    }

    public function test_duplicate_credential_id_is_rejected(): void
    {
        $admin = $this->createUser(['ADMIN']);
        $authenticator = $this->registerCredential($admin);

        $options = $this->registrationService()->options($admin, stepUpVerified: true);
        $json = $authenticator->attestationResponseJson(self::RP_ID, $options->options->challenge, self::ORIGIN);
        $result = $this->registrationService()->verify($admin, true, $json, self::RP_ID);

        $this->assertSame(AdminWebAuthnRegistrationOutcome::DUPLICATE_CREDENTIAL, $result->outcome);
    }

    public function test_revoked_credential_is_excluded_from_active_lookups(): void
    {
        $admin = $this->createUser(['ADMIN']);
        $authenticator = $this->registerCredential($admin);

        DB::table('admin_webauthn_credentials')
            ->where('user_id', UuidBinary::toBinary($admin->id))
            ->update(['revoked_at' => now(), 'revoke_reason' => 'Test revocation.']);

        $this->assertNull($this->credentialRepository()->findActiveByCredentialId($authenticator->credentialId));
        $this->assertSame([], $this->credentialRepository()->listActive(UuidBinary::toBinary($admin->id)));
        $this->assertSame(0, $this->credentialRepository()->activeCount(UuidBinary::toBinary($admin->id)));
    }

    public function test_registration_persists_only_public_credential_material(): void
    {
        $admin = $this->createUser(['ADMIN']);
        $authenticator = $this->registerCredential($admin);

        $row = DB::table('admin_webauthn_credentials')
            ->where('user_id', UuidBinary::toBinary($admin->id))
            ->first();

        $this->assertSame($authenticator->credentialId, $row->credential_id);
        $this->assertNotEmpty($row->public_key);
        $this->assertSame(0, (int) $row->sign_count);
    }

    public function test_last_used_at_is_updated_after_a_successful_assertion(): void
    {
        $admin = $this->createUser(['ADMIN']);
        $authenticator = $this->registerCredential($admin);

        $this->assertNull(
            DB::table('admin_webauthn_credentials')->where('user_id', UuidBinary::toBinary($admin->id))->value('last_used_at')
        );

        $options = $this->assertionService()->options($admin, AdminWebAuthnChallengePurpose::LOGIN_ASSERTION);
        $json = $authenticator->assertionResponseJson(self::RP_ID, $options->challenge, self::ORIGIN, UuidBinary::toBinary($admin->id));
        $result = $this->assertionService()->verify($admin, AdminWebAuthnChallengePurpose::LOGIN_ASSERTION, $json, self::RP_ID);

        $this->assertSame(AdminWebAuthnAssertionOutcome::VERIFIED, $result->outcome);
        $this->assertNotNull(
            DB::table('admin_webauthn_credentials')->where('user_id', UuidBinary::toBinary($admin->id))->value('last_used_at')
        );
    }

    public function test_sign_counter_updates_and_a_zero_counter_authenticator_is_never_rejected(): void
    {
        // Per WebAuthn / this library's own CeremonyStep\CheckCounter: a
        // stored counter of 0 with a reported counter of 0 is treated as
        // "this authenticator does not support a counter" and is never
        // rejected - many resident-key/passkey authenticators always
        // report 0.
        $admin = $this->createUser(['ADMIN']);
        $authenticator = $this->registerCredential($admin);

        foreach (range(1, 3) as $_) {
            $options = $this->assertionService()->options($admin, AdminWebAuthnChallengePurpose::LOGIN_ASSERTION);
            $json = $authenticator->assertionResponseJson(self::RP_ID, $options->challenge, self::ORIGIN, UuidBinary::toBinary($admin->id), overrideSignCount: 0);
            $result = $this->assertionService()->verify($admin, AdminWebAuthnChallengePurpose::LOGIN_ASSERTION, $json, self::RP_ID);

            $this->assertSame(AdminWebAuthnAssertionOutcome::VERIFIED, $result->outcome);
        }

        $this->assertSame(0, (int) DB::table('admin_webauthn_credentials')->where('user_id', UuidBinary::toBinary($admin->id))->value('sign_count'));
    }

    public function test_a_regressing_nonzero_sign_counter_is_treated_as_a_clone_warning_and_fails(): void
    {
        $admin = $this->createUser(['ADMIN']);
        $authenticator = $this->registerCredential($admin);

        $optionsA = $this->assertionService()->options($admin, AdminWebAuthnChallengePurpose::LOGIN_ASSERTION);
        $jsonA = $authenticator->assertionResponseJson(self::RP_ID, $optionsA->challenge, self::ORIGIN, UuidBinary::toBinary($admin->id), overrideSignCount: 10);
        $resultA = $this->assertionService()->verify($admin, AdminWebAuthnChallengePurpose::LOGIN_ASSERTION, $jsonA, self::RP_ID);
        $this->assertSame(AdminWebAuthnAssertionOutcome::VERIFIED, $resultA->outcome);
        $this->assertSame(10, (int) DB::table('admin_webauthn_credentials')->where('user_id', UuidBinary::toBinary($admin->id))->value('sign_count'));

        $optionsB = $this->assertionService()->options($admin, AdminWebAuthnChallengePurpose::LOGIN_ASSERTION);
        $jsonB = $authenticator->assertionResponseJson(self::RP_ID, $optionsB->challenge, self::ORIGIN, UuidBinary::toBinary($admin->id), overrideSignCount: 5);
        $resultB = $this->assertionService()->verify($admin, AdminWebAuthnChallengePurpose::LOGIN_ASSERTION, $jsonB, self::RP_ID);

        $this->assertSame(AdminWebAuthnAssertionOutcome::VERIFICATION_FAILED, $resultB->outcome);
    }

    // -----------------------------------------------------------------
    // Registration foundation (AdminWebAuthnRegistrationService)
    // -----------------------------------------------------------------

    public function test_active_admin_can_register_a_first_credential(): void
    {
        $admin = $this->createUser(['ADMIN']);
        $authenticator = new WebAuthnTestAuthenticator;

        $optionsResult = $this->registrationService()->options($admin, stepUpVerified: false);
        $this->assertSame(AdminWebAuthnRegistrationOutcome::ELIGIBLE, $optionsResult->outcome);

        $json = $authenticator->attestationResponseJson(self::RP_ID, $optionsResult->options->challenge, self::ORIGIN);
        $result = $this->registrationService()->verify($admin, false, $json, self::RP_ID);

        $this->assertSame(AdminWebAuthnRegistrationOutcome::REGISTERED, $result->outcome);
        $this->assertNotNull($result->credential);
    }

    public function test_customer_only_user_is_rejected(): void
    {
        $customer = $this->createUser(['CUSTOMER']);

        $optionsResult = $this->registrationService()->options($customer, stepUpVerified: false);

        $this->assertSame(AdminWebAuthnRegistrationOutcome::ACTOR_NOT_ELIGIBLE, $optionsResult->outcome);
    }

    public function test_admin_with_globally_deactivated_role_is_rejected(): void
    {
        $admin = $this->createUser(['ADMIN']);
        DB::table('roles')->where('code', 'ADMIN')->update(['is_active' => 0]);

        $optionsResult = $this->registrationService()->options($admin, stepUpVerified: false);

        $this->assertSame(AdminWebAuthnRegistrationOutcome::ACTOR_NOT_ELIGIBLE, $optionsResult->outcome);
    }

    public function test_second_credential_without_step_up_is_rejected(): void
    {
        $admin = $this->createUser(['ADMIN']);
        $this->registerCredential($admin);

        $optionsResult = $this->registrationService()->options($admin, stepUpVerified: false);

        $this->assertSame(AdminWebAuthnRegistrationOutcome::STEP_UP_REQUIRED, $optionsResult->outcome);
        $this->assertNull($optionsResult->options);
    }

    public function test_registration_requires_user_verification(): void
    {
        $admin = $this->createUser(['ADMIN']);
        $authenticator = new WebAuthnTestAuthenticator;

        $options = $this->registrationService()->options($admin, false);
        $json = $authenticator->attestationResponseJson(self::RP_ID, $options->options->challenge, self::ORIGIN, userVerified: false);
        $result = $this->registrationService()->verify($admin, false, $json, self::RP_ID);

        $this->assertSame(AdminWebAuthnRegistrationOutcome::VERIFICATION_FAILED, $result->outcome);
    }

    public function test_registration_with_wrong_origin_is_rejected(): void
    {
        $admin = $this->createUser(['ADMIN']);
        $authenticator = new WebAuthnTestAuthenticator;

        $options = $this->registrationService()->options($admin, false);
        $json = $authenticator->attestationResponseJson(self::RP_ID, $options->options->challenge, 'https://evil.test');
        $result = $this->registrationService()->verify($admin, false, $json, self::RP_ID);

        $this->assertSame(AdminWebAuthnRegistrationOutcome::VERIFICATION_FAILED, $result->outcome);
    }

    public function test_registration_with_wrong_rp_id_is_rejected(): void
    {
        $admin = $this->createUser(['ADMIN']);
        $authenticator = new WebAuthnTestAuthenticator;

        $options = $this->registrationService()->options($admin, false);
        $json = $authenticator->attestationResponseJson('evil.test', $options->options->challenge, self::ORIGIN);
        $result = $this->registrationService()->verify($admin, false, $json, self::RP_ID);

        $this->assertSame(AdminWebAuthnRegistrationOutcome::VERIFICATION_FAILED, $result->outcome);
    }

    public function test_valid_registration_persists_a_credential(): void
    {
        $admin = $this->createUser(['SUPER_ADMIN']);
        $authenticator = new WebAuthnTestAuthenticator;

        $options = $this->registrationService()->options($admin, false);
        $json = $authenticator->attestationResponseJson(self::RP_ID, $options->options->challenge, self::ORIGIN);
        $result = $this->registrationService()->verify($admin, false, $json, self::RP_ID, label: 'Test Security Key');

        $this->assertSame(AdminWebAuthnRegistrationOutcome::REGISTERED, $result->outcome);

        $row = DB::table('admin_webauthn_credentials')->where('user_id', UuidBinary::toBinary($admin->id))->first();
        $this->assertSame('Test Security Key', $row->label);
        $this->assertNull($row->revoked_at);
    }

    // -----------------------------------------------------------------
    // Assertion foundation (AdminWebAuthnAssertionService)
    // -----------------------------------------------------------------

    public function test_assertion_with_the_correct_active_credential_succeeds(): void
    {
        $admin = $this->createUser(['ADMIN']);
        $authenticator = $this->registerCredential($admin);

        $options = $this->assertionService()->options($admin, AdminWebAuthnChallengePurpose::LOGIN_ASSERTION);
        $json = $authenticator->assertionResponseJson(self::RP_ID, $options->challenge, self::ORIGIN, UuidBinary::toBinary($admin->id));
        $result = $this->assertionService()->verify($admin, AdminWebAuthnChallengePurpose::LOGIN_ASSERTION, $json, self::RP_ID);

        $this->assertSame(AdminWebAuthnAssertionOutcome::VERIFIED, $result->outcome);
    }

    public function test_assertion_with_an_unregistered_credential_fails(): void
    {
        $admin = $this->createUser(['ADMIN']);
        $this->registerCredential($admin);
        $unregistered = new WebAuthnTestAuthenticator;

        $options = $this->assertionService()->options($admin, AdminWebAuthnChallengePurpose::LOGIN_ASSERTION);
        $json = $unregistered->assertionResponseJson(self::RP_ID, $options->challenge, self::ORIGIN, UuidBinary::toBinary($admin->id));
        $result = $this->assertionService()->verify($admin, AdminWebAuthnChallengePurpose::LOGIN_ASSERTION, $json, self::RP_ID);

        $this->assertSame(AdminWebAuthnAssertionOutcome::CREDENTIAL_NOT_FOUND, $result->outcome);
    }

    public function test_assertion_with_a_revoked_credential_fails(): void
    {
        $admin = $this->createUser(['ADMIN']);
        $authenticator = $this->registerCredential($admin);

        DB::table('admin_webauthn_credentials')
            ->where('user_id', UuidBinary::toBinary($admin->id))
            ->update(['revoked_at' => now(), 'revoke_reason' => 'Test revocation.']);

        $options = $this->assertionService()->options($admin, AdminWebAuthnChallengePurpose::LOGIN_ASSERTION);
        $json = $authenticator->assertionResponseJson(self::RP_ID, $options->challenge, self::ORIGIN, UuidBinary::toBinary($admin->id));
        $result = $this->assertionService()->verify($admin, AdminWebAuthnChallengePurpose::LOGIN_ASSERTION, $json, self::RP_ID);

        $this->assertSame(AdminWebAuthnAssertionOutcome::CREDENTIAL_NOT_FOUND, $result->outcome);
    }

    public function test_assertion_with_a_challenge_that_was_never_issued_fails(): void
    {
        $admin = $this->createUser(['ADMIN']);
        $authenticator = $this->registerCredential($admin);

        // Never call options()/issue() - the assertion embeds an arbitrary
        // challenge that has no matching admin_webauthn_challenges row.
        $bogusChallenge = random_bytes(32);
        $json = $authenticator->assertionResponseJson(self::RP_ID, $bogusChallenge, self::ORIGIN, UuidBinary::toBinary($admin->id));
        $result = $this->assertionService()->verify($admin, AdminWebAuthnChallengePurpose::LOGIN_ASSERTION, $json, self::RP_ID);

        $this->assertSame(AdminWebAuthnAssertionOutcome::CHALLENGE_INVALID, $result->outcome);
    }

    public function test_replaying_a_consumed_assertion_response_fails(): void
    {
        $admin = $this->createUser(['ADMIN']);
        $authenticator = $this->registerCredential($admin);

        $options = $this->assertionService()->options($admin, AdminWebAuthnChallengePurpose::LOGIN_ASSERTION);
        $json = $authenticator->assertionResponseJson(self::RP_ID, $options->challenge, self::ORIGIN, UuidBinary::toBinary($admin->id));

        $first = $this->assertionService()->verify($admin, AdminWebAuthnChallengePurpose::LOGIN_ASSERTION, $json, self::RP_ID);
        $second = $this->assertionService()->verify($admin, AdminWebAuthnChallengePurpose::LOGIN_ASSERTION, $json, self::RP_ID);

        $this->assertSame(AdminWebAuthnAssertionOutcome::VERIFIED, $first->outcome);
        $this->assertSame(AdminWebAuthnAssertionOutcome::CHALLENGE_INVALID, $second->outcome);
    }

    public function test_assertion_with_wrong_origin_fails(): void
    {
        $admin = $this->createUser(['ADMIN']);
        $authenticator = $this->registerCredential($admin);

        $options = $this->assertionService()->options($admin, AdminWebAuthnChallengePurpose::LOGIN_ASSERTION);
        $json = $authenticator->assertionResponseJson(self::RP_ID, $options->challenge, 'https://evil.test', UuidBinary::toBinary($admin->id));
        $result = $this->assertionService()->verify($admin, AdminWebAuthnChallengePurpose::LOGIN_ASSERTION, $json, self::RP_ID);

        $this->assertSame(AdminWebAuthnAssertionOutcome::VERIFICATION_FAILED, $result->outcome);
    }

    public function test_assertion_with_wrong_rp_id_fails(): void
    {
        $admin = $this->createUser(['ADMIN']);
        $authenticator = $this->registerCredential($admin);

        $options = $this->assertionService()->options($admin, AdminWebAuthnChallengePurpose::LOGIN_ASSERTION);
        $json = $authenticator->assertionResponseJson('evil.test', $options->challenge, self::ORIGIN, UuidBinary::toBinary($admin->id));
        $result = $this->assertionService()->verify($admin, AdminWebAuthnChallengePurpose::LOGIN_ASSERTION, $json, self::RP_ID);

        $this->assertSame(AdminWebAuthnAssertionOutcome::VERIFICATION_FAILED, $result->outcome);
    }

    public function test_assertion_without_user_verification_fails(): void
    {
        $admin = $this->createUser(['ADMIN']);
        $authenticator = $this->registerCredential($admin);

        $options = $this->assertionService()->options($admin, AdminWebAuthnChallengePurpose::LOGIN_ASSERTION);
        $json = $authenticator->assertionResponseJson(self::RP_ID, $options->challenge, self::ORIGIN, UuidBinary::toBinary($admin->id), userVerified: false);
        $result = $this->assertionService()->verify($admin, AdminWebAuthnChallengePurpose::LOGIN_ASSERTION, $json, self::RP_ID);

        $this->assertSame(AdminWebAuthnAssertionOutcome::VERIFICATION_FAILED, $result->outcome);
    }

    public function test_assertion_with_a_forged_signature_fails(): void
    {
        $admin = $this->createUser(['ADMIN']);
        $authenticator = $this->registerCredential($admin);

        $options = $this->assertionService()->options($admin, AdminWebAuthnChallengePurpose::LOGIN_ASSERTION);
        $json = $authenticator->assertionResponseJson(self::RP_ID, $options->challenge, self::ORIGIN, UuidBinary::toBinary($admin->id), tamperSignature: true);
        $result = $this->assertionService()->verify($admin, AdminWebAuthnChallengePurpose::LOGIN_ASSERTION, $json, self::RP_ID);

        $this->assertSame(AdminWebAuthnAssertionOutcome::VERIFICATION_FAILED, $result->outcome);
    }

    public function test_step_up_purpose_works_end_to_end(): void
    {
        $admin = $this->createUser(['SUPER_ADMIN']);
        $authenticator = $this->registerCredential($admin);

        $options = $this->assertionService()->options($admin, AdminWebAuthnChallengePurpose::STEP_UP);
        $json = $authenticator->assertionResponseJson(self::RP_ID, $options->challenge, self::ORIGIN, UuidBinary::toBinary($admin->id));
        $result = $this->assertionService()->verify($admin, AdminWebAuthnChallengePurpose::STEP_UP, $json, self::RP_ID);

        $this->assertSame(AdminWebAuthnAssertionOutcome::VERIFIED, $result->outcome);
    }

    public function test_assertion_service_rejects_the_registration_purpose(): void
    {
        $admin = $this->createUser(['ADMIN']);

        $this->expectException(\InvalidArgumentException::class);

        $this->assertionService()->options($admin, AdminWebAuthnChallengePurpose::REGISTRATION);
    }
}
