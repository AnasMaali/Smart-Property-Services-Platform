<?php

namespace App\Actions\Auth;

use App\Actions\Auth\Concerns\RevokesAuthSessions;
use App\Models\User;
use App\Support\Uuid\UuidBinary;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

class ChangePasswordAction
{
    use RevokesAuthSessions;

    private const GENERIC_INVALID_MESSAGE = 'Your session is no longer valid. Please log in again.';

    /**
     * Change an already-authenticated customer's password, keeping the
     * current session valid and revoking every other auth_sessions row for
     * that user as a security measure.
     *
     * $userUuid/$currentSessionUuid come from the request's already-
     * validated access token (AuthenticateCustomer middleware); this action
     * still locks the user row itself before acting, matching every other
     * write action in this codebase.
     *
     * @param  array{current_password: string, new_password: string}  $data
     * @return array{success: bool, message: string, data: null}
     */
    public function handle(string $userUuid, string $currentSessionUuid, array $data): array
    {
        return DB::transaction(function () use ($userUuid, $currentSessionUuid, $data) {
            $user = User::where('id', UuidBinary::toBinary($userUuid))
                ->lockForUpdate()
                ->first();

            $activeAccountStatusId = $this->lookupId('user_account_statuses', 'ACTIVE');

            if ($user === null || $user->account_status_id !== $activeAccountStatusId) {
                return $this->failure(self::GENERIC_INVALID_MESSAGE);
            }

            if (! Hash::check($data['current_password'], $user->password_hash)) {
                return $this->failure('The current password you entered is incorrect.');
            }

            if (Hash::check($data['new_password'], $user->password_hash)) {
                return $this->failure('The new password must be different from your current password.');
            }

            $user->password_hash = Hash::make($data['new_password']);
            $user->save();

            $now = now();

            $this->revokeOtherSessions(
                UuidBinary::toBinary($user->id),
                $now,
                UuidBinary::toBinary($currentSessionUuid)
            );

            return [
                'success' => true,
                'message' => 'Password changed successfully.',
                'data' => null,
            ];
        });
    }

    private function failure(string $message): array
    {
        return [
            'success' => false,
            'message' => $message,
            'data' => null,
        ];
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
