<?php

namespace App\Actions\Auth;

use App\Support\Uuid\UuidBinary;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class AdminMeAction
{
    /**
     * Reads the safe identity fields for the currently authenticated Admin,
     * for the Admin UI to bootstrap with after login. Deliberately separate
     * from Profile\GetProfileAction, which returns customer-only data
     * (customer_profiles, service interests, property relationship) that an
     * Admin user does not have.
     *
     * @return array{
     *     user_uuid: string,
     *     full_name: string,
     *     phone_number: string,
     *     email: string,
     *     roles: array<int, string>,
     * }
     */
    public function handle(string $userUuid): array
    {
        $userId = UuidBinary::toBinary($userUuid);

        $identity = DB::table('users')
            ->join('user_profiles', 'user_profiles.user_id', '=', 'users.id')
            ->where('users.id', $userId)
            ->select(['users.phone_number', 'users.email', 'user_profiles.full_name'])
            ->first();

        if ($identity === null) {
            throw new RuntimeException("Admin identity not found for user {$userUuid}.");
        }

        $roles = DB::table('user_roles')
            ->join('roles', 'roles.id', '=', 'user_roles.role_id')
            ->where('user_roles.user_id', $userId)
            ->whereIn('roles.code', ['ADMIN', 'SUPER_ADMIN'])
            ->where('roles.is_active', 1)
            ->pluck('roles.code')
            ->all();

        return [
            'user_uuid' => $userUuid,
            'full_name' => $identity->full_name,
            'phone_number' => $identity->phone_number,
            'email' => $identity->email,
            'roles' => $roles,
        ];
    }
}
