<?php

namespace App\Actions\Auth;

use App\Support\Auth\AccountDeletionRequestStore;
use App\Support\Uuid\UuidBinary;
use Illuminate\Support\Carbon;

/**
 * Read-only status for the authenticated customer's own deletion request
 * (GET /v1/auth/account-deletion) - lets the Flutter client recover
 * PENDING deletion state after an app restart without re-deriving it from
 * anywhere else. The target is always the authenticated caller; there is
 * no way to query another customer's state through this endpoint since
 * there is no user-id input at all. No database/request UUID is ever
 * returned - only the derived NONE/PENDING status and requested_at.
 *
 * A completed deletion cannot normally reach this endpoint: auth.customer
 * already rejects the request once the account's session is revoked and
 * its CUSTOMER role removed (see AuthenticateCustomer), independent of
 * anything this Action does.
 */
class GetAccountDeletionStatusAction
{
    public function __construct(
        private readonly AccountDeletionRequestStore $requestStore = new AccountDeletionRequestStore,
    ) {}

    /**
     * @return array{success: bool, status: int, message: string, data: array<string, mixed>}
     */
    public function handle(string $userUuid): array
    {
        $userIdBinary = UuidBinary::toBinary($userUuid);

        $request = $this->requestStore->findPending($userIdBinary);

        if ($request === null) {
            return $this->ok(['deletion_status' => 'NONE', 'requested_at' => null]);
        }

        return $this->ok([
            'deletion_status' => 'PENDING',
            'requested_at' => Carbon::parse($request->requested_at)->toIso8601String(),
        ]);
    }

    private function ok(array $data): array
    {
        return [
            'success' => true,
            'status' => 200,
            'message' => 'Account deletion status retrieved successfully.',
            'data' => $data,
        ];
    }
}
