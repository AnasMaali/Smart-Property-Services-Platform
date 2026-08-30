<?php

namespace App\Actions\Admin\Customer;

use App\Support\Admin\AdminCustomerPresenter;
use App\Support\Cart\Concerns\BuildsCartResult;
use App\Support\Uuid\UuidBinary;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Read-only Admin Customer detail lookup (BLUE V1 Phase B6) - looked up by
 * the user's own uuid, never scoped to an actor. Only a `users` row that
 * also has a `customer_profiles` row is a "Customer" for this endpoint -
 * a pure-Admin account is reported as not found, matching the existing
 * "unknown vs. wrong-kind-of-record" convention of never distinguishing
 * the two.
 */
final class AdminGetCustomerAction
{
    use BuildsCartResult;

    /**
     * @return array<string, mixed>
     */
    public function handle(string $customerUuid): array
    {
        try {
            $userIdBinary = UuidBinary::toBinary($customerUuid);
        } catch (InvalidArgumentException) {
            return $this->notFound('Customer not found.');
        }

        $row = DB::table('users')
            ->join('user_profiles', 'user_profiles.user_id', '=', 'users.id')
            ->join('customer_profiles', 'customer_profiles.user_id', '=', 'users.id')
            ->join('user_account_statuses', 'user_account_statuses.id', '=', 'users.account_status_id')
            ->where('users.id', $userIdBinary)
            ->first([
                'users.*',
                'user_profiles.full_name',
                'user_account_statuses.code as account_status',
                'customer_profiles.area_id',
                'customer_profiles.property_relationship_type_id',
            ]);

        if ($row === null) {
            return $this->notFound('Customer not found.');
        }

        return $this->ok(200, 'Customer retrieved successfully.', ['customer' => AdminCustomerPresenter::detail($row)]);
    }
}
