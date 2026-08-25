<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use App\Support\Admin\WebAuthn\AdminWebAuthnChallengePurpose;
use App\Support\Admin\WebAuthn\AdminWebAuthnChallengeService;
use App\Support\Admin\WebAuthn\AdminWebAuthnRegistrationOutcome;
use App\Support\Admin\WebAuthn\AdminWebAuthnRegistrationService;
use App\Support\Contract\ContractStatuses;
use App\Support\Uuid\UuidBinary;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use ParagonIE\ConstantTime\Base64UrlSafe;
use Tests\Feature\Contract\Concerns\CreatesContractFixtures;
use Tests\Support\WebAuthn\WebAuthnTestAuthenticator;
use Tests\TestCase;

/**
 * BLUE V1 Phase A2.5 - Admin WebAuthn Step-Up Authentication.
 *
 * Covers POST /v1/admin/auth/step-up/request + /verify end-to-end over real
 * HTTP, using real ECDSA cryptography (Tests\Support\WebAuthn\
 * WebAuthnTestAuthenticator, as in A2.2/A2.3), plus the admin.stepup
 * middleware's enforcement on the first protected route (contracts.cancel).
 *
 * Uses CreatesContractFixtures (which pulls in CreatesAdminFixtures) both
 * for the lightweight createAndLoginAdmin()/markStepUpVerified() session
 * helpers and for activeContractWithItem() - the middleware test group
 * needs a real, cancellable Contract to exercise admin.stepup against a
 * real sensitive route, not a synthetic one.
 *
 * Session/request timing is controlled via Carbon::setTestNow(), always
 * anchored to the REAL wall-clock instant captured at the start of each test
 * (mirrors AdminSessionSecurityTest's own documented rationale: Firebase\JWT\JWT
 * validates a Bearer token's `exp`/`nbf` against the REAL system clock, never
 * Carbon's mocked time).
 */
class AdminStepUpTest extends TestCase
{
    use CreatesContractFixtures;
    use DatabaseTransactions;

    private const GENERIC_FAILURE_MESSAGE = 'This WebAuthn verification could not be completed.';

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCheckoutFixtures();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow(null);
        parent::tearDown();
    }

    private function userModel(string $userUuid): User
    {
        return User::where('id', UuidBinary::toBinary($userUuid))->firstOrFail();
    }

    /**
     * Registers a real WebAuthn credential directly via the Phase A2.2
     * service layer (never through HTTP) - setup state, not the thing under
     * test in most scenarios below. Mirrors AdminMfaLoginTest::
     * registerCredentialDirectly() exactly.
     */
    private function registerCredentialDirectly(User $user): WebAuthnTestAuthenticator
    {
        $registrationService = app(AdminWebAuthnRegistrationService::class);
        $authenticator = new WebAuthnTestAuthenticator;

        $optionsResult = $registrationService->options($user, stepUpVerified: false);
        $rpId = $optionsResult->options->rp->id;
        $json = $authenticator->attestationResponseJson($rpId, $optionsResult->options->challenge, $this->originFor($rpId));
        $result = $registrationService->verify($user, false, $json, $rpId);

        if ($result->outcome !== AdminWebAuthnRegistrationOutcome::REGISTERED) {
            throw new \RuntimeException('Fixture setup failed: '.$result->outcome->name);
        }

        return $authenticator;
    }

    private function originFor(string $rpId): string
    {
        return 'https://'.$rpId;
    }

    private function requestStepUp(string $accessToken): TestResponse
    {
        return $this->postJson('/api/v1/admin/auth/step-up/request', [], $this->bearer($accessToken));
    }

    /**
     * @param  array<string, mixed>  $credential
     */
    private function verifyStepUp(string $accessToken, string $ticket, array $credential): TestResponse
    {
        return $this->postJson('/api/v1/admin/auth/step-up/verify', [
            'step_up_ticket' => $ticket,
            'credential' => $credential,
        ], $this->bearer($accessToken));
    }

    /**
     * Drives a full, successful step-up request -> verify round trip over
     * real HTTP for $admin, using $authenticator's already-registered
     * credential. Used as setup for tests where "this session already has a
     * fresh step-up" is the precondition, not the thing under test.
     */
    private function completeStepUpOverHttp(array $admin, WebAuthnTestAuthenticator $authenticator): void
    {
        $request = $this->requestStepUp($admin['access_token'])->assertStatus(200);

        $ticket = $request->json('data.step_up_ticket');
        $rpId = $request->json('data.webauthn.rp_id');
        $challenge = $this->decodeB64Url($request->json('data.webauthn.challenge'));

        $credential = json_decode(
            $authenticator->assertionResponseJson($rpId, $challenge, $this->originFor($rpId), UuidBinary::toBinary($admin['user_uuid'])),
            true
        );

        $this->verifyStepUp($admin['access_token'], $ticket, $credential)->assertStatus(200);
    }

    private function decodeB64Url(string $value): string
    {
        return Base64UrlSafe::decode($value);
    }

    private function stepUpVerifiedAt(string $sessionUuid): ?Carbon
    {
        $raw = DB::table('auth_sessions')->where('id', UuidBinary::toBinary($sessionUuid))->value('step_up_verified_at');

        return $raw === null ? null : Carbon::parse($raw);
    }

    private function challengePurposeCode(string $ticket): ?string
    {
        return DB::table('admin_webauthn_challenges')
            ->join('admin_webauthn_challenge_purposes', 'admin_webauthn_challenge_purposes.id', '=', 'admin_webauthn_challenges.purpose_id')
            ->where('admin_webauthn_challenges.id', UuidBinary::toBinary($ticket))
            ->value('admin_webauthn_challenge_purposes.code');
    }

    // -----------------------------------------------------------------
    // REQUEST (1-7)
    // -----------------------------------------------------------------

    public function test_unauthenticated_caller_cannot_request_step_up(): void
    {
        $this->postJson('/api/v1/admin/auth/step-up/request', [])->assertStatus(401);
    }

    public function test_customer_session_cannot_request_step_up(): void
    {
        $customer = $this->createAuthenticatedCartCustomer();

        $this->requestStepUp($customer['access_token'])->assertStatus(401);
    }

    public function test_valid_admin_can_request_step_up(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);
        $this->registerCredentialDirectly($this->userModel($admin['user_uuid']));

        $response = $this->requestStepUp($admin['access_token']);

        $response->assertStatus(200)->assertJson(['success' => true])->assertJsonStructure([
            'data' => ['step_up_ticket', 'webauthn' => ['rp_id', 'challenge', 'allow_credentials', 'user_verification', 'timeout']],
        ]);
    }

    public function test_valid_super_admin_can_request_step_up(): void
    {
        $superAdmin = $this->createAndLoginAdmin(['SUPER_ADMIN']);
        $this->registerCredentialDirectly($this->userModel($superAdmin['user_uuid']));

        $this->requestStepUp($superAdmin['access_token'])->assertStatus(200);
    }

    public function test_admin_with_no_active_credential_fails_safely(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);

        $response = $this->requestStepUp($admin['access_token']);

        $response->assertStatus(422)->assertExactJson([
            'success' => false,
            'message' => self::GENERIC_FAILURE_MESSAGE,
            'data' => null,
        ]);
    }

    public function test_step_up_challenge_purpose_is_step_up(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);
        $this->registerCredentialDirectly($this->userModel($admin['user_uuid']));

        $ticket = $this->requestStepUp($admin['access_token'])->assertStatus(200)->json('data.step_up_ticket');

        $this->assertSame('STEP_UP', $this->challengePurposeCode($ticket));
    }

    public function test_step_up_request_creates_no_new_auth_session(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);
        $this->registerCredentialDirectly($this->userModel($admin['user_uuid']));

        $before = DB::table('auth_sessions')->count();
        $this->requestStepUp($admin['access_token'])->assertStatus(200);
        $after = DB::table('auth_sessions')->count();

        $this->assertSame($before, $after);
    }

    // -----------------------------------------------------------------
    // VERIFY (8-21)
    // -----------------------------------------------------------------

    public function test_valid_step_up_assertion_succeeds(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);
        $authenticator = $this->registerCredentialDirectly($this->userModel($admin['user_uuid']));

        $this->completeStepUpOverHttp($admin, $authenticator);

        $this->assertNotNull($this->stepUpVerifiedAt($admin['session_uuid']));
    }

    public function test_step_up_writes_only_the_current_sessions_step_up_verified_at(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);
        $authenticator = $this->registerCredentialDirectly($this->userModel($admin['user_uuid']));
        $otherSession = $this->issueAdminSession($admin['user_uuid'], ['ADMIN']);

        $this->completeStepUpOverHttp($admin, $authenticator);

        $this->assertNotNull($this->stepUpVerifiedAt($admin['session_uuid']));
        $this->assertNull($this->stepUpVerifiedAt($otherSession['session_uuid']));
    }

    public function test_wrong_credential_fails(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);
        $this->registerCredentialDirectly($this->userModel($admin['user_uuid']));
        $unregisteredAuthenticator = new WebAuthnTestAuthenticator;

        $request = $this->requestStepUp($admin['access_token'])->assertStatus(200);
        $ticket = $request->json('data.step_up_ticket');
        $rpId = $request->json('data.webauthn.rp_id');
        $challenge = $this->decodeB64Url($request->json('data.webauthn.challenge'));

        $credential = json_decode(
            $unregisteredAuthenticator->assertionResponseJson($rpId, $challenge, $this->originFor($rpId), UuidBinary::toBinary($admin['user_uuid'])),
            true
        );

        $this->verifyStepUp($admin['access_token'], $ticket, $credential)
            ->assertStatus(422)
            ->assertExactJson(['success' => false, 'message' => self::GENERIC_FAILURE_MESSAGE, 'data' => null]);

        $this->assertNull($this->stepUpVerifiedAt($admin['session_uuid']));
    }

    public function test_revoked_credential_fails(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);
        $authenticator = $this->registerCredentialDirectly($this->userModel($admin['user_uuid']));

        $request = $this->requestStepUp($admin['access_token'])->assertStatus(200);
        $ticket = $request->json('data.step_up_ticket');
        $rpId = $request->json('data.webauthn.rp_id');
        $challenge = $this->decodeB64Url($request->json('data.webauthn.challenge'));

        DB::table('admin_webauthn_credentials')
            ->where('user_id', UuidBinary::toBinary($admin['user_uuid']))
            ->update(['revoked_at' => now(), 'revoke_reason' => 'QA revoke.']);

        $credential = json_decode(
            $authenticator->assertionResponseJson($rpId, $challenge, $this->originFor($rpId), UuidBinary::toBinary($admin['user_uuid'])),
            true
        );

        $this->verifyStepUp($admin['access_token'], $ticket, $credential)->assertStatus(422);
        $this->assertNull($this->stepUpVerifiedAt($admin['session_uuid']));
    }

    public function test_a_challenge_that_was_never_issued_fails(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);
        $authenticator = $this->registerCredentialDirectly($this->userModel($admin['user_uuid']));

        $request = $this->requestStepUp($admin['access_token'])->assertStatus(200);
        $ticket = $request->json('data.step_up_ticket');
        $rpId = $request->json('data.webauthn.rp_id');

        $credential = json_decode(
            $authenticator->assertionResponseJson($rpId, random_bytes(32), $this->originFor($rpId), UuidBinary::toBinary($admin['user_uuid'])),
            true
        );

        $this->verifyStepUp($admin['access_token'], $ticket, $credential)->assertStatus(422);
        $this->assertNull($this->stepUpVerifiedAt($admin['session_uuid']));
    }

    public function test_an_expired_challenge_fails(): void
    {
        $created = Carbon::now();
        Carbon::setTestNow($created);

        $admin = $this->createAndLoginAdmin(['ADMIN']);
        $authenticator = $this->registerCredentialDirectly($this->userModel($admin['user_uuid']));

        $request = $this->requestStepUp($admin['access_token'])->assertStatus(200);
        $ticket = $request->json('data.step_up_ticket');
        $rpId = $request->json('data.webauthn.rp_id');
        $challenge = $this->decodeB64Url($request->json('data.webauthn.challenge'));

        // ADMIN_WEBAUTHN_CHALLENGE_TTL_SECONDS=300 in phpunit.xml.
        Carbon::setTestNow($created->copy()->addSeconds(301));

        $credential = json_decode(
            $authenticator->assertionResponseJson($rpId, $challenge, $this->originFor($rpId), UuidBinary::toBinary($admin['user_uuid'])),
            true
        );

        $this->verifyStepUp($admin['access_token'], $ticket, $credential)->assertStatus(422);
    }

    public function test_replaying_a_consumed_step_up_verification_fails_and_does_not_extend_freshness(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);
        $authenticator = $this->registerCredentialDirectly($this->userModel($admin['user_uuid']));

        $request = $this->requestStepUp($admin['access_token'])->assertStatus(200);
        $ticket = $request->json('data.step_up_ticket');
        $rpId = $request->json('data.webauthn.rp_id');
        $challenge = $this->decodeB64Url($request->json('data.webauthn.challenge'));

        $credential = json_decode(
            $authenticator->assertionResponseJson($rpId, $challenge, $this->originFor($rpId), UuidBinary::toBinary($admin['user_uuid'])),
            true
        );

        $this->verifyStepUp($admin['access_token'], $ticket, $credential)->assertStatus(200);
        $firstVerifiedAt = $this->stepUpVerifiedAt($admin['session_uuid']);

        Carbon::setTestNow(Carbon::now()->addSecond());
        $this->verifyStepUp($admin['access_token'], $ticket, $credential)->assertStatus(422);

        $this->assertTrue($firstVerifiedAt->equalTo($this->stepUpVerifiedAt($admin['session_uuid'])));
    }

    public function test_wrong_origin_fails(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);
        $authenticator = $this->registerCredentialDirectly($this->userModel($admin['user_uuid']));

        $request = $this->requestStepUp($admin['access_token'])->assertStatus(200);
        $ticket = $request->json('data.step_up_ticket');
        $rpId = $request->json('data.webauthn.rp_id');
        $challenge = $this->decodeB64Url($request->json('data.webauthn.challenge'));

        $credential = json_decode(
            $authenticator->assertionResponseJson($rpId, $challenge, 'https://evil.test', UuidBinary::toBinary($admin['user_uuid'])),
            true
        );

        $this->verifyStepUp($admin['access_token'], $ticket, $credential)->assertStatus(422);
        $this->assertNull($this->stepUpVerifiedAt($admin['session_uuid']));
    }

    public function test_wrong_rp_id_fails(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);
        $authenticator = $this->registerCredentialDirectly($this->userModel($admin['user_uuid']));

        $request = $this->requestStepUp($admin['access_token'])->assertStatus(200);
        $ticket = $request->json('data.step_up_ticket');
        $rpId = $request->json('data.webauthn.rp_id');
        $challenge = $this->decodeB64Url($request->json('data.webauthn.challenge'));

        $credential = json_decode(
            $authenticator->assertionResponseJson('evil.test', $challenge, $this->originFor($rpId), UuidBinary::toBinary($admin['user_uuid'])),
            true
        );

        $this->verifyStepUp($admin['access_token'], $ticket, $credential)->assertStatus(422);
        $this->assertNull($this->stepUpVerifiedAt($admin['session_uuid']));
    }

    public function test_missing_user_verification_fails(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);
        $authenticator = $this->registerCredentialDirectly($this->userModel($admin['user_uuid']));

        $request = $this->requestStepUp($admin['access_token'])->assertStatus(200);
        $ticket = $request->json('data.step_up_ticket');
        $rpId = $request->json('data.webauthn.rp_id');
        $challenge = $this->decodeB64Url($request->json('data.webauthn.challenge'));

        $credential = json_decode(
            $authenticator->assertionResponseJson($rpId, $challenge, $this->originFor($rpId), UuidBinary::toBinary($admin['user_uuid']), userVerified: false),
            true
        );

        $this->verifyStepUp($admin['access_token'], $ticket, $credential)->assertStatus(422);
        $this->assertNull($this->stepUpVerifiedAt($admin['session_uuid']));
    }

    public function test_invalid_signature_fails(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);
        $authenticator = $this->registerCredentialDirectly($this->userModel($admin['user_uuid']));

        $request = $this->requestStepUp($admin['access_token'])->assertStatus(200);
        $ticket = $request->json('data.step_up_ticket');
        $rpId = $request->json('data.webauthn.rp_id');
        $challenge = $this->decodeB64Url($request->json('data.webauthn.challenge'));

        $credential = json_decode(
            $authenticator->assertionResponseJson($rpId, $challenge, $this->originFor($rpId), UuidBinary::toBinary($admin['user_uuid']), tamperSignature: true),
            true
        );

        $this->verifyStepUp($admin['access_token'], $ticket, $credential)->assertStatus(422);
        $this->assertNull($this->stepUpVerifiedAt($admin['session_uuid']));
    }

    public function test_a_challenge_issued_under_one_session_cannot_step_up_another_session(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);
        $authenticator = $this->registerCredentialDirectly($this->userModel($admin['user_uuid']));
        $otherSession = $this->issueAdminSession($admin['user_uuid'], ['ADMIN']);

        // Request step-up under the FIRST session.
        $request = $this->requestStepUp($admin['access_token'])->assertStatus(200);
        $ticket = $request->json('data.step_up_ticket');
        $rpId = $request->json('data.webauthn.rp_id');
        $challenge = $this->decodeB64Url($request->json('data.webauthn.challenge'));

        $credential = json_decode(
            $authenticator->assertionResponseJson($rpId, $challenge, $this->originFor($rpId), UuidBinary::toBinary($admin['user_uuid'])),
            true
        );

        // Attempt to verify it using the SECOND session's own Bearer token.
        $this->verifyStepUp($otherSession['access_token'], $ticket, $credential)
            ->assertStatus(422)
            ->assertExactJson(['success' => false, 'message' => self::GENERIC_FAILURE_MESSAGE, 'data' => null]);

        $this->assertNull($this->stepUpVerifiedAt($admin['session_uuid']));
        $this->assertNull($this->stepUpVerifiedAt($otherSession['session_uuid']));
    }

    public function test_deactivated_account_between_request_and_verify_fails(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);
        $authenticator = $this->registerCredentialDirectly($this->userModel($admin['user_uuid']));

        $request = $this->requestStepUp($admin['access_token'])->assertStatus(200);
        $ticket = $request->json('data.step_up_ticket');
        $rpId = $request->json('data.webauthn.rp_id');
        $challenge = $this->decodeB64Url($request->json('data.webauthn.challenge'));

        DB::table('users')
            ->where('id', UuidBinary::toBinary($admin['user_uuid']))
            ->update(['account_status_id' => DB::table('user_account_statuses')->where('code', 'DEACTIVATED')->value('id')]);

        $credential = json_decode(
            $authenticator->assertionResponseJson($rpId, $challenge, $this->originFor($rpId), UuidBinary::toBinary($admin['user_uuid'])),
            true
        );

        // Rejected by auth.admin (re-checked fresh on this exact request)
        // before AdminStepUpVerifyAction ever runs.
        $this->verifyStepUp($admin['access_token'], $ticket, $credential)->assertStatus(401);
    }

    public function test_removed_admin_role_between_request_and_verify_fails(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);
        $authenticator = $this->registerCredentialDirectly($this->userModel($admin['user_uuid']));

        $request = $this->requestStepUp($admin['access_token'])->assertStatus(200);
        $ticket = $request->json('data.step_up_ticket');
        $rpId = $request->json('data.webauthn.rp_id');
        $challenge = $this->decodeB64Url($request->json('data.webauthn.challenge'));

        DB::table('user_roles')
            ->where('user_id', UuidBinary::toBinary($admin['user_uuid']))
            ->delete();

        $credential = json_decode(
            $authenticator->assertionResponseJson($rpId, $challenge, $this->originFor($rpId), UuidBinary::toBinary($admin['user_uuid'])),
            true
        );

        $this->verifyStepUp($admin['access_token'], $ticket, $credential)->assertStatus(401);
    }

    // -----------------------------------------------------------------
    // MIDDLEWARE - contracts.cancel (22-30)
    // -----------------------------------------------------------------

    public function test_contracts_cancel_without_step_up_is_blocked(): void
    {
        $ctx = $this->activeContractWithItem();
        $contractUuid = UuidBinary::toString($ctx['contract']->id);

        $response = $this->adminCancelContract($ctx['admin']['access_token'], $contractUuid, ['reason' => 'QA cancel.']);

        $response->assertStatus(428)->assertExactJson([
            'success' => false,
            'message' => 'This action requires a fresh WebAuthn verification.',
            'code' => 'STEP_UP_REQUIRED',
        ]);

        $contract = $this->contractRow($contractUuid);
        $this->assertNotSame('CANCELLED', ContractStatuses::code((int) $contract->status_id));
    }

    public function test_contracts_cancel_with_fresh_step_up_succeeds(): void
    {
        $ctx = $this->activeContractWithItem();
        $contractUuid = UuidBinary::toString($ctx['contract']->id);
        $this->markStepUpVerified($ctx['admin']['session_uuid']);

        $this->adminCancelContract($ctx['admin']['access_token'], $contractUuid, ['reason' => 'QA cancel.'])->assertStatus(200);
    }

    public function test_contracts_cancel_exactly_at_step_up_expiry_boundary_is_blocked(): void
    {
        // Documented convention (see AdminSessionPolicy::isStepUpFresh):
        // the boundary instant itself (step_up_verified_at + TTL, exactly)
        // is already the first invalid instant - mirrors the identical idle
        // -timeout convention from Phase A2.4.
        $created = Carbon::now();
        Carbon::setTestNow($created);

        $ctx = $this->activeContractWithItem();
        $contractUuid = UuidBinary::toString($ctx['contract']->id);
        $this->markStepUpVerified($ctx['admin']['session_uuid'], $created);

        // AUTH_ADMIN_STEP_UP_TTL_MINUTES=5 in phpunit.xml.
        Carbon::setTestNow($created->copy()->addMinutes(5));

        $this->adminCancelContract($ctx['admin']['access_token'], $contractUuid, ['reason' => 'QA cancel.'])->assertStatus(428);
    }

    public function test_contracts_cancel_before_step_up_expiry_boundary_succeeds(): void
    {
        $created = Carbon::now();
        Carbon::setTestNow($created);

        $ctx = $this->activeContractWithItem();
        $contractUuid = UuidBinary::toString($ctx['contract']->id);
        $this->markStepUpVerified($ctx['admin']['session_uuid'], $created);

        Carbon::setTestNow($created->copy()->addMinutes(5)->subSecond());

        $this->adminCancelContract($ctx['admin']['access_token'], $contractUuid, ['reason' => 'QA cancel.'])->assertStatus(200);
    }

    public function test_step_up_expiry_does_not_revoke_the_admin_session(): void
    {
        $created = Carbon::now();
        Carbon::setTestNow($created);

        $ctx = $this->activeContractWithItem();
        $this->markStepUpVerified($ctx['admin']['session_uuid'], $created);

        Carbon::setTestNow($created->copy()->addMinutes(5));

        // The blocked cancel attempt above proves 428, not 401/revocation -
        // an unrelated non-sensitive route must still succeed on this same
        // (still fully authenticated) session.
        $this->getJson('/api/v1/admin/me', $this->bearer($ctx['admin']['access_token']))->assertStatus(200);
    }

    public function test_permission_denial_remains_denial_even_with_valid_step_up(): void
    {
        $ctx = $this->activeContractWithItem();
        $contractUuid = UuidBinary::toString($ctx['contract']->id);
        $this->markStepUpVerified($ctx['admin']['session_uuid']);

        $adminRoleId = DB::table('roles')->where('code', 'ADMIN')->value('id');
        $permissionId = DB::table('admin_permissions')->where('code', 'contracts.cancel')->value('id');

        DB::table('admin_role_permissions')
            ->where('role_id', $adminRoleId)
            ->where('permission_id', $permissionId)
            ->delete();

        $response = $this->adminCancelContract($ctx['admin']['access_token'], $contractUuid, ['reason' => 'QA cancel.']);

        $response->assertStatus(403)->assertExactJson([
            'success' => false,
            'message' => 'You are not authorized to perform this action.',
        ]);
    }

    public function test_super_admin_override_still_requires_step_up(): void
    {
        $customer = $this->createAuthenticatedCartCustomer();
        $property = $this->createProperty($customer['access_token']);
        $service = $this->createSubscriptionEligibleService();
        $created = $this->requestContract($customer['access_token'], [
            'property_uuid' => $property['uuid'],
            'all_services' => false,
            'service_uuids' => [$service['uuid']],
        ])->assertStatus(201);
        $contractUuid = $created->json('data.contract.uuid');

        $superAdmin = $this->createAndLoginAdmin(['SUPER_ADMIN']);

        // SUPER_ADMIN's centralized authorization override grants the
        // capability with no admin_role_permissions row at all - but must
        // still be blocked here for lack of step-up.
        $this->adminCancelContract($superAdmin['access_token'], $contractUuid, ['reason' => 'QA cancel.'])
            ->assertStatus(428);
    }

    public function test_contract_mutation_does_not_occur_when_step_up_is_missing(): void
    {
        $ctx = $this->activeContractWithItem();
        $contractUuid = UuidBinary::toString($ctx['contract']->id);

        $this->adminCancelContract($ctx['admin']['access_token'], $contractUuid, ['reason' => 'QA cancel.'])->assertStatus(428);

        $this->assertSame(0, DB::table('admin_audit_logs')->where('entity_identifier', $contractUuid)->where('action_code', 'CONTRACT_CANCELLED')->count());
        $contract = $this->contractRow($contractUuid);
        $this->assertNotSame('CANCELLED', ContractStatuses::code((int) $contract->status_id));
    }

    public function test_step_up_is_reusable_within_the_freshness_window(): void
    {
        $ctx = $this->activeContractWithItem();
        $contractUuid = UuidBinary::toString($ctx['contract']->id);
        $this->markStepUpVerified($ctx['admin']['session_uuid']);

        // First call performs the real transition; the retry is the
        // action's own idempotent no-op - BOTH must pass admin.stepup from
        // the SAME single grant, proving the window is reusable, not
        // consumed by the first sensitive action.
        $this->adminCancelContract($ctx['admin']['access_token'], $contractUuid, ['reason' => 'QA cancel.'])->assertStatus(200);
        $this->adminCancelContract($ctx['admin']['access_token'], $contractUuid, ['reason' => 'QA cancel retry.'])->assertStatus(200);
    }

    // -----------------------------------------------------------------
    // SESSION (31-35)
    // -----------------------------------------------------------------

    public function test_mfa_issued_login_leaves_step_up_verified_at_null(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);

        $this->assertNull($this->stepUpVerifiedAt($admin['session_uuid']));
    }

    public function test_refresh_does_not_alter_step_up_verified_at(): void
    {
        $created = Carbon::now();
        Carbon::setTestNow($created);

        $admin = $this->createAndLoginAdmin(['ADMIN']);
        $this->markStepUpVerified($admin['session_uuid'], $created);
        $before = $this->stepUpVerifiedAt($admin['session_uuid']);

        Carbon::setTestNow($created->copy()->addMinutes(2));
        $this->postJson('/api/v1/admin/auth/refresh', ['refresh_token' => $admin['refresh_token']])
            ->assertStatus(200);

        $this->assertTrue($before->equalTo($this->stepUpVerifiedAt($admin['session_uuid'])));
    }

    public function test_revoked_session_cannot_use_a_prior_step_up(): void
    {
        $ctx = $this->activeContractWithItem();
        $contractUuid = UuidBinary::toString($ctx['contract']->id);
        $this->markStepUpVerified($ctx['admin']['session_uuid']);

        DB::table('auth_sessions')
            ->where('id', UuidBinary::toBinary($ctx['admin']['session_uuid']))
            ->update(['revoked_at' => now()]);

        $this->adminCancelContract($ctx['admin']['access_token'], $contractUuid, ['reason' => 'QA cancel.'])->assertStatus(401);
    }

    public function test_idle_expired_session_cannot_request_step_up(): void
    {
        $created = Carbon::now();
        Carbon::setTestNow($created);

        $admin = $this->createAndLoginAdmin(['ADMIN']);
        $this->registerCredentialDirectly($this->userModel($admin['user_uuid']));

        // AUTH_ADMIN_IDLE_TIMEOUT_MINUTES=20 in phpunit.xml.
        Carbon::setTestNow($created->copy()->addMinutes(21));

        $this->requestStepUp($admin['access_token'])->assertStatus(401);
    }

    public function test_a_new_admin_session_does_not_inherit_another_sessions_step_up(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);
        $this->markStepUpVerified($admin['session_uuid']);

        $newSession = $this->issueAdminSession($admin['user_uuid'], ['ADMIN']);

        $this->assertNull($this->stepUpVerifiedAt($newSession['session_uuid']));
    }

    // -----------------------------------------------------------------
    // SECURITY (36-40)
    // -----------------------------------------------------------------

    public function test_unknown_ticket_fails_generically(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);
        $authenticator = $this->registerCredentialDirectly($this->userModel($admin['user_uuid']));

        $credential = json_decode(
            $authenticator->assertionResponseJson('admin.blue.test', random_bytes(32), 'https://admin.blue.test', UuidBinary::toBinary($admin['user_uuid'])),
            true
        );

        $response = $this->verifyStepUp($admin['access_token'], UuidBinary::generate(), $credential);

        $response->assertStatus(422)->assertExactJson(['success' => false, 'message' => self::GENERIC_FAILURE_MESSAGE, 'data' => null]);
    }

    public function test_wrong_purpose_ticket_fails_generically(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);
        $authenticator = $this->registerCredentialDirectly($this->userModel($admin['user_uuid']));

        // A LOGIN_ASSERTION-purpose ticket (from a real login) must never be
        // usable to satisfy a STEP_UP verify.
        $loginTicket = app(AdminWebAuthnChallengeService::class)
            ->issue($this->userModel($admin['user_uuid']), AdminWebAuthnChallengePurpose::LOGIN_ASSERTION)
            ->ticket;

        $credential = json_decode(
            $authenticator->assertionResponseJson('admin.blue.test', random_bytes(32), 'https://admin.blue.test', UuidBinary::toBinary($admin['user_uuid'])),
            true
        );

        $this->verifyStepUp($admin['access_token'], $loginTicket, $credential)->assertStatus(422);
    }

    public function test_step_up_request_response_never_exposes_credential_internals(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);
        $this->registerCredentialDirectly($this->userModel($admin['user_uuid']));

        $response = $this->requestStepUp($admin['access_token'])->assertStatus(200);

        $response->assertJsonMissingPath('data.webauthn.allow_credentials.0.public_key')
            ->assertJsonMissingPath('data.webauthn.credential_public_key');
        $this->assertArrayHasKey('id', $response->json('data.webauthn.allow_credentials.0'));
    }

    public function test_step_up_request_endpoint_is_rate_limited(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);
        $this->registerCredentialDirectly($this->userModel($admin['user_uuid']));

        for ($i = 0; $i < 10; $i++) {
            $this->requestStepUp($admin['access_token']);
        }

        $this->requestStepUp($admin['access_token'])->assertStatus(429);
    }

    public function test_step_up_verify_endpoint_is_rate_limited(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);

        for ($i = 0; $i < 10; $i++) {
            $this->verifyStepUp($admin['access_token'], UuidBinary::generate(), [
                'id' => 'x', 'rawId' => 'x', 'type' => 'public-key',
                'response' => ['clientDataJSON' => 'x', 'authenticatorData' => 'x', 'signature' => 'x'],
            ]);
        }

        $this->verifyStepUp($admin['access_token'], UuidBinary::generate(), [
            'id' => 'x', 'rawId' => 'x', 'type' => 'public-key',
            'response' => ['clientDataJSON' => 'x', 'authenticatorData' => 'x', 'signature' => 'x'],
        ])->assertStatus(429);
    }

    public function test_existing_non_sensitive_admin_routes_remain_unaffected_by_step_up(): void
    {
        $ctx = $this->activeContractWithItem();

        // No step-up granted at all - GET (read-only) routes and the other
        // non-admin.stepup contract mutations must all still work exactly
        // as before Phase A2.5.
        $this->getJson('/api/v1/admin/contracts', $this->bearer($ctx['admin']['access_token']))->assertStatus(200);
        $this->getJson('/api/v1/admin/me', $this->bearer($ctx['admin']['access_token']))->assertStatus(200);
    }
}
