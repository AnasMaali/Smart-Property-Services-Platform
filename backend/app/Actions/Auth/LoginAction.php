<?php

namespace App\Actions\Auth;

use App\Models\AuthSession;
use App\Models\User;
use App\Services\Auth\JwtTokenService;
use App\Support\Uuid\UuidBinary;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

class LoginAction
{
    private const GENERIC_INVALID_MESSAGE = 'The phone number or password you entered is incorrect.';

    public function __construct(private readonly JwtTokenService $jwtTokenService) {}

    /**
     * Authenticate a customer by phone number and password, then atomically
     * create the auth_sessions row and update users.last_login_at.
     *
     * Every rejection reason below - unknown phone number, wrong password,
     * PENDING_VERIFICATION/SUSPENDED/DEACTIVATED status, unverified phone,
     * or a missing/inactive CUSTOMER role - returns the exact same generic
     * message so a caller cannot use the response to determine whether a
     * given phone number is registered.
     *
     * @param  array{
     *     phone_number: string,
     *     password: string,
     *     client_type: string,
     *     device_name: ?string,
     *     app_version: ?string,
     *     ip_address: ?string,
     *     user_agent: ?string,
     * }  $data
     * @return array{success: bool, message: string, data: array|null}
     */
    public function handle(array $data): array
    {
        return DB::transaction(function () use ($data) {
            $user = User::where('phone_number', $data['phone_number'])
                ->lockForUpdate()
                ->first();

            if ($user === null || ! Hash::check($data['password'], $user->password_hash)) {
                return $this->failure();
            }

            $activeAccountStatusId = $this->lookupId('user_account_statuses', 'ACTIVE');

            if ($user->account_status_id !== $activeAccountStatusId) {
                return $this->failure();
            }

            if ($user->phone_verified_at === null) {
                return $this->failure();
            }

            $hasActiveCustomerRole = DB::table('user_roles')
                ->join('roles', 'roles.id', '=', 'user_roles.role_id')
                ->where('user_roles.user_id', UuidBinary::toBinary($user->id))
                ->where('roles.code', 'CUSTOMER')
                ->where('roles.is_active', 1)
                ->exists();

            if (! $hasActiveCustomerRole) {
                return $this->failure();
            }

            $clientTypeId = DB::table('auth_client_types')
                ->where('code', $data['client_type'])
                ->value('id');

            if ($clientTypeId === null) {
                return $this->failure();
            }

            $now = now();
            $sessionUuid = UuidBinary::generate();
            $rawRefreshToken = random_bytes(32);
            $sessionExpiresAt = $now->copy()->addDays((int) config('jwt.session_ttl_days'));

            AuthSession::create([
                'id' => $sessionUuid,
                'user_id' => $user->id,
                'client_type_id' => $clientTypeId,
                'refresh_token_hash' => hash('sha256', $rawRefreshToken, true),
                'device_name' => $data['device_name'] ?? null,
                'app_version' => $data['app_version'] ?? null,
                'ip_address' => $this->packIp($data['ip_address'] ?? null),
                'user_agent' => $data['user_agent'] ?? null,
                'last_used_at' => $now,
                'expires_at' => $sessionExpiresAt,
                'revoked_at' => null,
            ]);

            $user->last_login_at = $now;
            $user->save();

            $fullName = DB::table('user_profiles')
                ->where('user_id', UuidBinary::toBinary($user->id))
                ->value('full_name');

            $accessToken = $this->jwtTokenService->issueAccessToken(
                $user->id,
                $sessionUuid,
                'CUSTOMER',
                $data['client_type']
            );

            return [
                'success' => true,
                'message' => 'Login successful.',
                'data' => [
                    'user_uuid' => $user->id,
                    'full_name' => $fullName,
                    'phone_number' => $user->phone_number,
                    'email' => $user->email,
                    'role' => 'CUSTOMER',
                    'session_uuid' => $sessionUuid,
                    'access_token' => $accessToken['token'],
                    'access_token_expires_at' => $accessToken['expires_at']->toIso8601String(),
                    'refresh_token' => bin2hex($rawRefreshToken),
                    'session_expires_at' => $sessionExpiresAt->toIso8601String(),
                ],
            ];
        });
    }

    private function failure(): array
    {
        return [
            'success' => false,
            'message' => self::GENERIC_INVALID_MESSAGE,
            'data' => null,
        ];
    }

    private function packIp(?string $ip): ?string
    {
        if ($ip === null || $ip === '') {
            return null;
        }

        $packed = inet_pton($ip);

        return $packed === false ? null : $packed;
    }

    private function lookupId(string $table, string $code): int
    {
        $id = DB::table($table)->where('code', $code)->value('id');

        if ($id === null) {
            throw new RuntimeException("Missing required reference row: {$table}.code = {$code}");
        }

        return (int) $id;
    }
}
