<?php

namespace App\Actions\Admin\Customer;

use App\Support\Admin\AdminCustomerPresenter;
use App\Support\Cart\Concerns\BuildsCartResult;
use App\Support\Uuid\UuidBinary;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Read-only, paginated Customer listing for Admin operators (BLUE V1 Phase
 * B6) - mirrors App\Actions\Admin\Booking\AdminListBookingsAction's
 * pagination/filter conventions exactly. Scoped to genuine Customers only
 * (a `users` row also holding a `customer_profiles` row) - a pure-Admin
 * account that never registered as a customer is never listed here.
 * Deterministic ordering (`created_at DESC, id DESC`) and a bounded page
 * size make this safe to call against an unbounded table.
 */
final class AdminListCustomersAction
{
    use BuildsCartResult;

    public const DEFAULT_PER_PAGE = 20;

    public const MAX_PER_PAGE = 100;

    /**
     * @param  array{account_status?: string, phone_number?: string, email?: string, customer_uuid?: string, search?: string}  $filters
     * @return array<string, mixed>
     */
    public function handle(array $filters, int $page, int $perPage): array
    {
        $page = max($page, 1);
        $perPage = min(max($perPage, 1), self::MAX_PER_PAGE);

        if (isset($filters['customer_uuid'])) {
            try {
                $filters['customer_uuid'] = UuidBinary::toBinary($filters['customer_uuid']);
            } catch (InvalidArgumentException) {
                return $this->ok(200, 'Customers retrieved successfully.', [
                    'customers' => [],
                    'pagination' => ['page' => $page, 'per_page' => $perPage, 'total' => 0, 'last_page' => 1],
                ]);
            }
        }

        $query = DB::table('users')
            ->join('user_profiles', 'user_profiles.user_id', '=', 'users.id')
            ->join('customer_profiles', 'customer_profiles.user_id', '=', 'users.id')
            ->join('user_account_statuses', 'user_account_statuses.id', '=', 'users.account_status_id');

        if (isset($filters['account_status'])) {
            $query->where('user_account_statuses.code', $filters['account_status']);
        }

        if (isset($filters['phone_number'])) {
            $query->where('users.phone_number', $filters['phone_number']);
        }

        if (isset($filters['email'])) {
            $query->where('users.email', $filters['email']);
        }

        if (isset($filters['customer_uuid'])) {
            $query->where('users.id', $filters['customer_uuid']);
        }

        if (isset($filters['search'])) {
            $query->where('user_profiles.full_name', 'like', '%'.$filters['search'].'%');
        }

        $total = (clone $query)->count('users.id');
        $lastPage = max((int) ceil($total / $perPage), 1);

        $rows = $query
            ->orderByDesc('users.created_at')
            ->orderByDesc('users.id')
            ->forPage($page, $perPage)
            ->get([
                'users.*',
                'user_profiles.full_name',
                'user_account_statuses.code as account_status',
                'customer_profiles.area_id',
            ]);

        return $this->ok(200, 'Customers retrieved successfully.', [
            'customers' => AdminCustomerPresenter::presentList($rows),
            'pagination' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'last_page' => $lastPage,
            ],
        ]);
    }
}
