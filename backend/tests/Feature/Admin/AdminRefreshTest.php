<?php

namespace Tests\Feature\Admin;

use App\Support\Uuid\UuidBinary;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AdminRefreshTest extends TestCase
{
    use DatabaseTransactions;

    private const GENERIC_MESSAGE = 'This refresh token is invalid or has expired.';

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
     * Creates an ADMIN user and logs it in through the real HTTP endpoint,
     * returning the raw refresh token issued for that session.
     *
     * @return array{user_uuid: string, refresh_token: string, session_uuid: string}
     */
    private function createLoggedInAdmin(): array
    {
        self::$sequence++;

        $userUuid = UuidBinary::generate();
        $phoneNumber = '+97150400'.str_pad((string) self::$sequence, 4, '0', STR_PAD_LEFT);
        $now = now();

        DB::table('users')->insert([
            'id' => UuidBinary::toBinary($userUuid),
            'phone_number' => $phoneNumber,
            'email' => 'admin.refresh.'.self::$sequence.'@example.com',
            'password_hash' => Hash::make('Passw0rd123'),
            'account_status_id' => $this->accountStatusId('ACTIVE'),
            'phone_verified_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('user_profiles')->insert([
            'user_id' => UuidBinary::toBinary($userUuid),
            'full_name' => 'Omar Al Admin',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('user_roles')->insert([
            'user_id' => UuidBinary::toBinary($userUuid),
            'role_id' => $this->roleId('ADMIN'),
            'assigned_by_user_id' => null,
            'assigned_at' => $now,
        ]);

        $login = $this->postJson('/api/v1/admin/auth/login', [
            'phone_number' => $phoneNumber,
            'password' => 'Passw0rd123',
        ])->assertStatus(200);

        return [
            'user_uuid' => $userUuid,
            'refresh_token' => $login->json('data.refresh_token'),
            'session_uuid' => $login->json('data.session_uuid'),
        ];
    }

    public function test_admin_refresh_returns_new_access_and_refresh_tokens(): void
    {
        $admin = $this->createLoggedInAdmin();

        $response = $this->postJson('/api/v1/admin/auth/refresh', [
            'refresh_token' => $admin['refresh_token'],
        ]);

        $response->assertStatus(200)->assertJsonStructure([
            'success', 'message',
            'data' => ['access_token', 'access_token_expires_at', 'refresh_token', 'session_uuid', 'session_expires_at'],
        ]);

        $this->assertNotSame($admin['refresh_token'], $response->json('data.refresh_token'));

        $decoded = JWT::decode($response->json('data.access_token'), new Key(config('jwt.secret'), 'HS256'));
        $this->assertSame('ADMIN', $decoded->role);
        $this->assertSame('ADMIN_WEB', $decoded->client);
    }

    public function test_old_refresh_token_cannot_be_reused_after_rotation(): void
    {
        $admin = $this->createLoggedInAdmin();

        $this->postJson('/api/v1/admin/auth/refresh', ['refresh_token' => $admin['refresh_token']])
            ->assertStatus(200);

        $reuse = $this->postJson('/api/v1/admin/auth/refresh', ['refresh_token' => $admin['refresh_token']]);

        $reuse->assertStatus(422)->assertExactJson([
            'success' => false,
            'message' => self::GENERIC_MESSAGE,
            'data' => null,
        ]);
    }

    public function test_refresh_fails_after_admin_role_is_removed(): void
    {
        $admin = $this->createLoggedInAdmin();

        DB::table('user_roles')
            ->where('user_id', UuidBinary::toBinary($admin['user_uuid']))
            ->where('role_id', $this->roleId('ADMIN'))
            ->delete();

        $response = $this->postJson('/api/v1/admin/auth/refresh', [
            'refresh_token' => $admin['refresh_token'],
        ]);

        $response->assertStatus(422)->assertExactJson([
            'success' => false,
            'message' => self::GENERIC_MESSAGE,
            'data' => null,
        ]);
    }

    public function test_refresh_fails_after_admin_role_is_deactivated(): void
    {
        $admin = $this->createLoggedInAdmin();

        DB::table('roles')->where('code', 'ADMIN')->update(['is_active' => 0]);

        $response = $this->postJson('/api/v1/admin/auth/refresh', [
            'refresh_token' => $admin['refresh_token'],
        ]);

        $response->assertStatus(422)->assertExactJson([
            'success' => false,
            'message' => self::GENERIC_MESSAGE,
            'data' => null,
        ]);
    }

    public function test_refresh_fails_after_account_deactivated(): void
    {
        $admin = $this->createLoggedInAdmin();

        DB::table('users')
            ->where('id', UuidBinary::toBinary($admin['user_uuid']))
            ->update(['account_status_id' => $this->accountStatusId('DEACTIVATED')]);

        $response = $this->postJson('/api/v1/admin/auth/refresh', [
            'refresh_token' => $admin['refresh_token'],
        ]);

        $response->assertStatus(422)->assertExactJson([
            'success' => false,
            'message' => self::GENERIC_MESSAGE,
            'data' => null,
        ]);
    }

    public function test_customer_refresh_token_is_rejected_by_admin_refresh_endpoint(): void
    {
        $now = now();
        $userUuid = UuidBinary::generate();
        $phoneNumber = '+971504009999';

        DB::table('users')->insert([
            'id' => UuidBinary::toBinary($userUuid),
            'phone_number' => $phoneNumber,
            'email' => 'customer.refresh.cross@example.com',
            'password_hash' => Hash::make('Passw0rd123'),
            'account_status_id' => $this->accountStatusId('ACTIVE'),
            'phone_verified_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('user_profiles')->insert([
            'user_id' => UuidBinary::toBinary($userUuid),
            'full_name' => 'Cross Customer',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('user_roles')->insert([
            'user_id' => UuidBinary::toBinary($userUuid),
            'role_id' => $this->roleId('CUSTOMER'),
            'assigned_by_user_id' => null,
            'assigned_at' => $now,
        ]);

        $login = $this->postJson('/api/v1/auth/login', [
            'phone_number' => $phoneNumber,
            'password' => 'Passw0rd123',
            'client_type' => 'MOBILE_IOS',
        ])->assertStatus(200);

        $response = $this->postJson('/api/v1/admin/auth/refresh', [
            'refresh_token' => $login->json('data.refresh_token'),
        ]);

        $response->assertStatus(422)->assertExactJson([
            'success' => false,
            'message' => self::GENERIC_MESSAGE,
            'data' => null,
        ]);
    }

    public function test_malformed_refresh_token_is_rejected(): void
    {
        $response = $this->postJson('/api/v1/admin/auth/refresh', [
            'refresh_token' => 'not-a-valid-token',
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors(['refresh_token']);
    }
}
