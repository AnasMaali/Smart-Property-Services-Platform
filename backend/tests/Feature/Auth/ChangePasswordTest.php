<?php

namespace Tests\Feature\Auth;

use App\Support\Uuid\UuidBinary;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\Support\AuthenticatesCustomersForTests;
use Tests\TestCase;

/**
 * Legacy customer password auth tests.
 *
 * Customer password routes were removed in favour of OTP-only auth.
 * Run explicitly with: php artisan test --group=legacy
 *
 * @group legacy
 */
class ChangePasswordTest extends TestCase
{
    use DatabaseTransactions;
    use AuthenticatesCustomersForTests;

    private const GENERIC_SESSION_MESSAGE = 'This session is invalid or has expired.';

    private static int $sequence = 0;

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
    private function createCustomer(string $rawPassword = 'OldPassw0rd', bool $assignCustomerRole = true): array
    {
        self::$sequence++;

        $userUuid = UuidBinary::generate();
        $phoneNumber = '+97157000'.str_pad((string) self::$sequence, 4, '0', STR_PAD_LEFT);
        $now = now();

        DB::table('users')->insert([
            'id' => UuidBinary::toBinary($userUuid),
            'phone_number' => $phoneNumber,
            'email' => 'change.password.'.self::$sequence.'@example.com',
            'password_hash' => Hash::make($rawPassword),
            'account_status_id' => $this->accountStatusId('ACTIVE'),
            'phone_verified_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('user_profiles')->insert([
            'user_id' => UuidBinary::toBinary($userUuid),
            'full_name' => 'Change Password Customer',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        if ($assignCustomerRole) {
            DB::table('user_roles')->insert([
                'user_id' => UuidBinary::toBinary($userUuid),
                'role_id' => $this->roleId('CUSTOMER'),
                'assigned_by_user_id' => null,
                'assigned_at' => $now,
            ]);
        }

        return ['user_uuid' => $userUuid, 'phone_number' => $phoneNumber, 'password' => $rawPassword];
    }

    private function loginCustomer(array $customer): array
    {
        return $this->issueCustomerSession($customer['user_uuid']);
    }

    private function changePassword(?string $accessToken, array $payload)
    {
        if ($accessToken === null) {
            return $this->postJson('/api/v1/auth/change-password', $payload);
        }

        return $this->postJson('/api/v1/auth/change-password', $payload, ['Authorization' => 'Bearer '.$accessToken]);
    }

    // Authenticated success: password hash changes.
    public function test_authenticated_customer_can_change_password(): void
    {
        $customer = $this->createCustomer();
        $session = $this->loginCustomer($customer);

        $hashBefore = DB::table('users')->where('id', UuidBinary::toBinary($customer['user_uuid']))->value('password_hash');

        $response = $this->changePassword($session['access_token'], [
            'current_password' => $customer['password'],
            'new_password' => 'BrandNewPassw0rd',
            'new_password_confirmation' => 'BrandNewPassw0rd',
        ]);

        $response->assertStatus(200)->assertJson(['success' => true, 'data' => null]);

        $hashAfter = DB::table('users')->where('id', UuidBinary::toBinary($customer['user_uuid']))->value('password_hash');
        $this->assertNotSame($hashBefore, $hashAfter);
        $this->assertTrue(Hash::check('BrandNewPassw0rd', $hashAfter));
    }

    // Wrong current_password is rejected and the password is unchanged.
    public function test_wrong_current_password_is_rejected(): void
    {
        $customer = $this->createCustomer();
        $session = $this->loginCustomer($customer);

        $response = $this->changePassword($session['access_token'], [
            'current_password' => 'TotallyWrong1',
            'new_password' => 'BrandNewPassw0rd',
            'new_password_confirmation' => 'BrandNewPassw0rd',
        ]);

        $response->assertStatus(422)->assertJson(['success' => false]);

        $hashAfter = DB::table('users')->where('id', UuidBinary::toBinary($customer['user_uuid']))->value('password_hash');
        $this->assertTrue(Hash::check($customer['password'], $hashAfter));
    }

    // Password policy is enforced on new_password.
    public function test_password_policy_is_enforced(): void
    {
        $customer = $this->createCustomer();
        $session = $this->loginCustomer($customer);

        $this->changePassword($session['access_token'], [
            'current_password' => $customer['password'],
            'new_password' => 'short1',
            'new_password_confirmation' => 'short1',
        ])->assertStatus(422)->assertJsonValidationErrors(['new_password']);
    }

    public function test_new_password_confirmation_mismatch_fails_validation(): void
    {
        $customer = $this->createCustomer();
        $session = $this->loginCustomer($customer);

        $this->changePassword($session['access_token'], [
            'current_password' => $customer['password'],
            'new_password' => 'BrandNewPassw0rd',
            'new_password_confirmation' => 'SomethingElse1',
        ])->assertStatus(422)->assertJsonValidationErrors(['new_password']);
    }

    // Same old/new password is rejected.
    public function test_same_old_and_new_password_is_rejected(): void
    {
        $customer = $this->createCustomer();
        $session = $this->loginCustomer($customer);

        $response = $this->changePassword($session['access_token'], [
            'current_password' => $customer['password'],
            'new_password' => $customer['password'],
            'new_password_confirmation' => $customer['password'],
        ]);

        $response->assertStatus(422)->assertJson(['success' => false]);
    }

    // The current session stays valid after a successful change.
    public function test_current_session_stays_valid_after_change(): void
    {
        $customer = $this->createCustomer();
        $session = $this->loginCustomer($customer);

        $this->changePassword($session['access_token'], [
            'current_password' => $customer['password'],
            'new_password' => 'BrandNewPassw0rd',
            'new_password_confirmation' => 'BrandNewPassw0rd',
        ])->assertStatus(200);

        $sessionRow = DB::table('auth_sessions')->where('id', UuidBinary::toBinary($session['session_uuid']))->first();
        $this->assertNull($sessionRow->revoked_at);
    }

    // Every other session for the same user is revoked.
    public function test_other_sessions_are_revoked(): void
    {
        $customer = $this->createCustomer();
        $currentSession = $this->loginCustomer($customer);
        $otherSession = $this->loginCustomer($customer);

        $this->changePassword($currentSession['access_token'], [
            'current_password' => $customer['password'],
            'new_password' => 'BrandNewPassw0rd',
            'new_password_confirmation' => 'BrandNewPassw0rd',
        ])->assertStatus(200);

        $currentRow = DB::table('auth_sessions')->where('id', UuidBinary::toBinary($currentSession['session_uuid']))->first();
        $otherRow = DB::table('auth_sessions')->where('id', UuidBinary::toBinary($otherSession['session_uuid']))->first();

        $this->assertNull($currentRow->revoked_at);
        $this->assertNotNull($otherRow->revoked_at);
    }

    // Missing/invalid access token is rejected by the shared customer-auth middleware.
    public function test_missing_or_invalid_token_is_rejected(): void
    {
        $this->changePassword(null, [
            'current_password' => 'whatever',
            'new_password' => 'BrandNewPassw0rd',
            'new_password_confirmation' => 'BrandNewPassw0rd',
        ])->assertStatus(401)->assertExactJson([
            'success' => false,
            'message' => self::GENERIC_SESSION_MESSAGE,
        ]);

        $this->changePassword('not-a-jwt', [
            'current_password' => 'whatever',
            'new_password' => 'BrandNewPassw0rd',
            'new_password_confirmation' => 'BrandNewPassw0rd',
        ])->assertStatus(401)->assertExactJson([
            'success' => false,
            'message' => self::GENERIC_SESSION_MESSAGE,
        ]);
    }

    // A revoked session's access token is rejected.
    public function test_revoked_session_token_is_rejected(): void
    {
        $customer = $this->createCustomer();
        $session = $this->loginCustomer($customer);

        DB::table('auth_sessions')
            ->where('id', UuidBinary::toBinary($session['session_uuid']))
            ->update(['revoked_at' => now()]);

        $this->changePassword($session['access_token'], [
            'current_password' => $customer['password'],
            'new_password' => 'BrandNewPassw0rd',
            'new_password_confirmation' => 'BrandNewPassw0rd',
        ])->assertStatus(401)->assertExactJson([
            'success' => false,
            'message' => self::GENERIC_SESSION_MESSAGE,
        ]);
    }

    // Response never leaks the password hash.
    public function test_response_never_leaks_secrets(): void
    {
        $customer = $this->createCustomer();
        $session = $this->loginCustomer($customer);

        $response = $this->changePassword($session['access_token'], [
            'current_password' => $customer['password'],
            'new_password' => 'BrandNewPassw0rd',
            'new_password_confirmation' => 'BrandNewPassw0rd',
        ]);

        $response->assertStatus(200);

        $json = strtolower(json_encode($response->json()));
        $this->assertStringNotContainsString('password_hash', $json);
        $this->assertStringNotContainsString('brandnewpassw0rd', $json);
        $this->assertNull($response->json('data'));
    }
}
