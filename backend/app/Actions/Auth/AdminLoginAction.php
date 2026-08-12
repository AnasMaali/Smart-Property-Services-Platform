<?php

namespace App\Actions\Auth;

use App\Models\AuthSession;
use App\Models\User;
use App\Services\Auth\JwtTokenService;
use App\Support\Uuid\UuidBinary;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

class AdminLoginAction
{
    private const GENERIC_INVALID_MESSAGE = 'The phone number or password you entered is incorrect.';

    private const CLIENT_TYPE_CODE = 'ADMIN_WEB';

    /** Priority order used only to pick the single `role` JWT claim when a user holds more than one Admin role. */
    private const ADMIN_ROLE_PRIORITY = ['SUPER_ADMIN', 'ADMIN'];

    public function __construct(private readonly JwtTokenService $jwtTokenService) {}

    /**
     * Authenticate an Admin/Super Admin by phone number and password, then
     * atomically create the auth_sessions row and update
     * users.last_login_at.
     *
     * Every rejection reason below - unknown phone number, wrong password,
     * non-ACTIVE account status, or a missing/inactive ADMIN/SUPER_ADMIN
     * role (including a normal CUSTOMER account) - returns the exact same
     * generic message so a caller cannot use the response to determine
     * whether a given phone number is registered, or that it exists but
     * lacks Admin privileges. Unlike customer login, phone verification is
     * not required: Admin accounts are provisioned directly, not through
     * the OTP-verified customer registration flow.
     *
     * @param  array{
     *     phone_number: string,
     *     password: string,
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

            $userId = UuidBinary::toBinary($user->id);

            $activeAdminRoleCodes = DB::table('user_roles')
                ->join('roles', 'roles.id', '=', 'user_roles.role_id')
                ->where('user_roles.user_id', $userId)
                ->whereIn('roles.code', self::ADMIN_ROLE_PRIORITY)
                ->where('roles.is_active', 1)
                ->pluck('roles.code')
                ->all();

            if ($activeAdminRoleCodes === []) {
                return $this->failure();
            }

            $clientType = DB::table('auth_client_types')
                ->where('code', self::CLIENT_TYPE_CODE)
                ->first();

            if ($clientType === null || ! $clientType->is_active) {
                return $this->failure();
            }

            $now = now();
            $sessionUuid = UuidBinary::generate();
            $rawRefreshToken = random_bytes(32);
            $sessionExpiresAt = $now->copy()->addDays((int) config('jwt.session_ttl_days'));

            // See LoginAction for why created_at/last_used_at are set
            // explicitly to the same $now instant with $timestamps disabled.
            $session = new AuthSession([
                'id' => $sessionUuid,
                'user_id' => $user->id,
                'client_type_id' => $clientType->id,
                'refresh_token_hash' => hash('sha256', $rawRefreshToken, true),
                'device_name' => $data['device_name'] ?? null,
                'app_version' => $data['app_version'] ?? null,
                'ip_address' => $this->packIp($data['ip_address'] ?? null),
                'user_agent' => $data['user_agent'] ?? null,
                'last_used_at' => $now,
                'expires_at' => $sessionExpiresAt,
                'revoked_at' => null,
            ]);
            $session->timestamps = false;
            $session->created_at = $now;
            $session->updated_at = $now;
            $session->save();

            $user->last_login_at = $now;
            $user->save();

            $fullName = DB::table('user_profiles')
                ->where('user_id', $userId)
                ->value('full_name');

            $primaryRole = $this->resolvePrimaryRole($activeAdminRoleCodes);

            $accessToken = $this->jwtTokenService->issueAccessToken(
                $user->id,
                $sessionUuid,
                $primaryRole,
                self::CLIENT_TYPE_CODE
            );

            return [
                'success' => true,
                'message' => 'Login successful.',
                'data' => [
                    'user_uuid' => $user->id,
                    'full_name' => $fullName,
                    'phone_number' => $user->phone_number,
                    'email' => $user->email,
                    'role' => $primaryRole,
                    'roles' => $activeAdminRoleCodes,
                    'session_uuid' => $sessionUuid,
                    'access_token' => $accessToken['token'],
                    'access_token_expires_at' => $accessToken['expires_at']->toIso8601String(),
                    'refresh_token' => bin2hex($rawRefreshToken),
                    'session_expires_at' => $sessionExpiresAt->toIso8601String(),
                ],
            ];
        });
    }

    /**
     * @param  array<int, string>  $activeAdminRoleCodes
     */
    private function resolvePrimaryRole(array $activeAdminRoleCodes): string
    {
        foreach (self::ADMIN_ROLE_PRIORITY as $roleCode) {
            if (in_array($roleCode, $activeAdminRoleCodes, true)) {
                return $roleCode;
            }
        }

        // Unreachable: $activeAdminRoleCodes is always a non-empty subset of
        // self::ADMIN_ROLE_PRIORITY at the only call site.
        throw new RuntimeException('No Admin role found among active role codes.');
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
