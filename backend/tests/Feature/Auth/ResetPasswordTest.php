<?php

namespace Tests\Feature\Auth;

use App\Support\Uuid\UuidBinary;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\Support\AuthenticatesCustomersForTests;
use Tests\TestCase;

class ResetPasswordTest extends TestCase
{
    use DatabaseTransactions;
    use AuthenticatesCustomersForTests;

    private static int $sequence = 0;

    private function purposeId(string $code): int
    {
        return (int) DB::table('otp_verification_purposes')->where('code', $code)->value('id');
    }

    private function otpStatusId(string $code): int
    {
        return (int) DB::table('otp_verification_statuses')->where('code', $code)->value('id');
    }

    private function accountStatusId(string $code): int
    {
        return (int) DB::table('user_account_statuses')->where('code', $code)->value('id');
    }

    private function roleId(string $code): int
    {
        return (int) DB::table('roles')->where('code', $code)->value('id');
    }

    /**
     * @return array{user_uuid: string, phone_number: string, password: string}
     */
    private function createCustomer(string $rawPassword = 'OldPassw0rd', array $overrides = []): array
    {
        self::$sequence++;

        $userUuid = UuidBinary::generate();
        $phoneNumber = '+97156000'.str_pad((string) self::$sequence, 4, '0', STR_PAD_LEFT);
        $now = now();

        DB::table('users')->insert(array_merge([
            'id' => UuidBinary::toBinary($userUuid),
            'phone_number' => $phoneNumber,
            'email' => 'reset.password.'.self::$sequence.'@example.com',
            'password_hash' => Hash::make($rawPassword),
            'account_status_id' => $this->accountStatusId('ACTIVE'),
            'phone_verified_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ], $overrides));

        DB::table('user_profiles')->insert([
            'user_id' => UuidBinary::toBinary($userUuid),
            'full_name' => 'Reset Password Customer',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('user_roles')->insert([
            'user_id' => UuidBinary::toBinary($userUuid),
            'role_id' => $this->roleId('CUSTOMER'),
            'assigned_by_user_id' => null,
            'assigned_at' => $now,
        ]);

        return ['user_uuid' => $userUuid, 'phone_number' => $phoneNumber, 'password' => $rawPassword];
    }

    private function loginCustomer(array $customer): array
    {
        return $this->issueCustomerSession($customer['user_uuid']);
    }

    /**
     * Creates a VERIFIED PASSWORD_RESET OTP plus its password_reset_sessions
     * row directly, returning the raw reset token, so reset-password can be
     * tested without going through the full OTP flow each time.
     */
    private function createVerifiedResetSession(string $userUuid, array $sessionOverrides = []): array
    {
        $otpUuid = UuidBinary::generate();
        $now = now();

        DB::table('otp_verifications')->insert([
            'id' => UuidBinary::toBinary($otpUuid),
            'user_id' => UuidBinary::toBinary($userUuid),
            'purpose_id' => $this->purposeId('PASSWORD_RESET'),
            'status_id' => $this->otpStatusId('VERIFIED'),
            'target_phone_number' => '+971560000000',
            'code_hash' => Hash::make('123456'),
            'failed_attempt_count' => 0,
            'max_attempts' => 5,
            'expires_at' => $now->copy()->addMinutes(5),
            'verified_at' => $now,
            'last_attempt_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $rawToken = random_bytes(32);
        $sessionUuid = UuidBinary::generate();

        DB::table('password_reset_sessions')->insert(array_merge([
            'id' => UuidBinary::toBinary($sessionUuid),
            'otp_verification_id' => UuidBinary::toBinary($otpUuid),
            'reset_token_hash' => hash('sha256', $rawToken, true),
            'expires_at' => $now->copy()->addMinutes(15),
            'used_at' => null,
            'revoked_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ], $sessionOverrides));

        return [
            'session_uuid' => $sessionUuid,
            'otp_uuid' => $otpUuid,
            'reset_token' => bin2hex($rawToken),
        ];
    }

    private function reset(string $token, string $password, ?string $confirmation = null)
    {
        return $this->postJson('/api/v1/auth/reset-password', [
            'reset_token' => $token,
            'password' => $password,
            'password_confirmation' => $confirmation ?? $password,
        ]);
    }

    // End-to-end: forgot-password -> verify-password-reset-otp -> reset-password.
    public function test_full_reset_flow_changes_the_password(): void
    {
        $customer = $this->createCustomer();

        $this->postJson('/api/v1/auth/forgot-password', [
            'phone_number' => $customer['phone_number'],
        ])->assertStatus(200);

        $otp = DB::table('otp_verifications')
            ->where('user_id', UuidBinary::toBinary($customer['user_uuid']))
            ->where('purpose_id', $this->purposeId('PASSWORD_RESET'))
            ->first();

        // The raw code is never persisted; re-derive it deterministically by
        // overwriting the hash the same way ForgotPasswordAction would, so
        // this test can drive the real endpoint end-to-end.
        DB::table('otp_verifications')->where('id', $otp->id)->update(['code_hash' => Hash::make('654321')]);

        $verifyResponse = $this->postJson('/api/v1/auth/verify-password-reset-otp', [
            'phone_number' => $customer['phone_number'],
            'otp_code' => '654321',
        ])->assertStatus(200);

        $resetToken = $verifyResponse->json('data.reset_token');

        $this->reset($resetToken, 'BrandNewPassw0rd')->assertStatus(200)->assertJson([
            'success' => true,
            'data' => null,
        ]);

        $user = DB::table('users')->where('id', UuidBinary::toBinary($customer['user_uuid']))->first();
        $this->assertTrue(Hash::check('BrandNewPassw0rd', $user->password_hash));
    }

    // Valid reset session changes the password hash.
    public function test_valid_reset_token_changes_password_hash(): void
    {
        $customer = $this->createCustomer();
        $session = $this->createVerifiedResetSession($customer['user_uuid']);

        $hashBefore = DB::table('users')->where('id', UuidBinary::toBinary($customer['user_uuid']))->value('password_hash');

        $this->reset($session['reset_token'], 'BrandNewPassw0rd')->assertStatus(200);

        $hashAfter = DB::table('users')->where('id', UuidBinary::toBinary($customer['user_uuid']))->value('password_hash');

        $this->assertNotSame($hashBefore, $hashAfter);
        $this->assertTrue(Hash::check('BrandNewPassw0rd', $hashAfter));
    }

    // The reset token is marked used and cannot be reused.
    public function test_reset_token_cannot_be_reused(): void
    {
        $customer = $this->createCustomer();
        $session = $this->createVerifiedResetSession($customer['user_uuid']);

        $this->reset($session['reset_token'], 'BrandNewPassw0rd')->assertStatus(200);

        $sessionRow = DB::table('password_reset_sessions')
            ->where('id', UuidBinary::toBinary($session['session_uuid']))
            ->first();
        $this->assertNotNull($sessionRow->used_at);

        $second = $this->reset($session['reset_token'], 'AnotherPassw0rd');
        $second->assertStatus(422)->assertJson(['success' => false]);

        $userHash = DB::table('users')->where('id', UuidBinary::toBinary($customer['user_uuid']))->value('password_hash');
        $this->assertTrue(Hash::check('BrandNewPassw0rd', $userHash));
    }

    // Unknown token is rejected.
    public function test_unknown_reset_token_is_rejected(): void
    {
        $this->reset(bin2hex(random_bytes(32)), 'BrandNewPassw0rd')
            ->assertStatus(422)
            ->assertJson(['success' => false]);
    }

    // Expired reset session is rejected.
    public function test_expired_reset_session_is_rejected(): void
    {
        $customer = $this->createCustomer();
        $session = $this->createVerifiedResetSession($customer['user_uuid'], [
            'created_at' => now()->subMinutes(20),
            'expires_at' => now()->subMinutes(5),
        ]);

        $this->reset($session['reset_token'], 'BrandNewPassw0rd')
            ->assertStatus(422)
            ->assertJson(['success' => false]);
    }

    // Used session is rejected.
    public function test_already_used_reset_session_is_rejected(): void
    {
        $customer = $this->createCustomer();
        $session = $this->createVerifiedResetSession($customer['user_uuid'], [
            'used_at' => now(),
        ]);

        $this->reset($session['reset_token'], 'BrandNewPassw0rd')
            ->assertStatus(422)
            ->assertJson(['success' => false]);
    }

    // Revoked session is rejected.
    public function test_revoked_reset_session_is_rejected(): void
    {
        $customer = $this->createCustomer();
        $session = $this->createVerifiedResetSession($customer['user_uuid'], [
            'revoked_at' => now(),
        ]);

        $this->reset($session['reset_token'], 'BrandNewPassw0rd')
            ->assertStatus(422)
            ->assertJson(['success' => false]);
    }

    // Password policy is enforced.
    public function test_password_policy_is_enforced(): void
    {
        $customer = $this->createCustomer();
        $session = $this->createVerifiedResetSession($customer['user_uuid']);

        $this->reset($session['reset_token'], 'short1')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['password']);
    }

    public function test_password_confirmation_mismatch_fails_validation(): void
    {
        $customer = $this->createCustomer();
        $session = $this->createVerifiedResetSession($customer['user_uuid']);

        $this->reset($session['reset_token'], 'BrandNewPassw0rd', 'DifferentPassw0rd')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['password']);
    }

    // Successful reset revokes every existing auth_sessions row for the user.
    public function test_successful_reset_revokes_all_existing_sessions(): void
    {
        $customer = $this->createCustomer();
        $sessionA = $this->loginCustomer($customer);
        $sessionB = $this->loginCustomer($customer);

        $resetSession = $this->createVerifiedResetSession($customer['user_uuid']);

        $this->reset($resetSession['reset_token'], 'BrandNewPassw0rd')->assertStatus(200);

        $rowA = DB::table('auth_sessions')->where('id', UuidBinary::toBinary($sessionA['session_uuid']))->first();
        $rowB = DB::table('auth_sessions')->where('id', UuidBinary::toBinary($sessionB['session_uuid']))->first();

        $this->assertNotNull($rowA->revoked_at);
        $this->assertNotNull($rowB->revoked_at);
    }

    // Never returns password/hash/reset-token hash.
    public function test_response_never_leaks_secrets(): void
    {
        $customer = $this->createCustomer();
        $session = $this->createVerifiedResetSession($customer['user_uuid']);

        $response = $this->reset($session['reset_token'], 'BrandNewPassw0rd');
        $response->assertStatus(200);

        $json = strtolower(json_encode($response->json()));

        $this->assertStringNotContainsString('password_hash', $json);
        $this->assertStringNotContainsString('reset_token_hash', $json);
        $this->assertStringNotContainsString($session['reset_token'], $json);
        $this->assertNull($response->json('data'));
    }

    // Lock ordering: the users row is always the first FOR UPDATE lock, before any otp_verifications row.
    public function test_user_row_is_locked_for_update_before_otp_row(): void
    {
        $customer = $this->createCustomer();
        $session = $this->createVerifiedResetSession($customer['user_uuid']);

        DB::enableQueryLog();

        $this->reset($session['reset_token'], 'BrandNewPassw0rd')->assertStatus(200);

        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $lockingQueries = collect($queries)
            ->filter(fn (array $entry) => str_contains(strtolower($entry['query']), 'for update'))
            ->values();

        $this->assertGreaterThanOrEqual(2, $lockingQueries->count());
        $this->assertStringContainsString('from `users`', strtolower($lockingQueries->first()['query']));
    }
}
