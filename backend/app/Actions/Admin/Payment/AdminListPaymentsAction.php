<?php

namespace App\Actions\Admin\Payment;

use App\Support\Admin\AdminPaymentPresenter;
use App\Support\Cart\Concerns\BuildsCartResult;
use App\Support\Uuid\UuidBinary;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Read-only, paginated Payment Attempt listing for Admin operators (BLUE V1
 * Phase B5) - mirrors App\Actions\Admin\Booking\AdminListBookingsAction's
 * pagination/filter conventions exactly. Never scoped to one customer,
 * unlike App\Actions\Payment\GetPaymentAction. Deterministic ordering
 * (`created_at DESC, id DESC`) and a bounded page size make this safe to
 * call against an unbounded table.
 */
final class AdminListPaymentsAction
{
    use BuildsCartResult;

    public const DEFAULT_PER_PAGE = 20;

    public const MAX_PER_PAGE = 100;

    /**
     * @param  array{status?: string, checkout_reference?: string, customer_uuid?: string, provider_transaction_reference?: string}  $filters
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
                return $this->ok(200, 'Payments retrieved successfully.', [
                    'payments' => [],
                    'pagination' => ['page' => $page, 'per_page' => $perPage, 'total' => 0, 'last_page' => 1],
                ]);
            }
        }

        $query = DB::table('payment_attempts')
            ->join('carts', 'carts.id', '=', 'payment_attempts.cart_id')
            ->join('payment_statuses', 'payment_statuses.id', '=', 'payment_attempts.status_id');

        if (isset($filters['status'])) {
            $query->where('payment_statuses.code', $filters['status']);
        }

        if (isset($filters['checkout_reference'])) {
            $query->where('payment_attempts.checkout_reference', $filters['checkout_reference']);
        }

        if (isset($filters['customer_uuid'])) {
            $query->where('carts.customer_user_id', $filters['customer_uuid']);
        }

        if (isset($filters['provider_transaction_reference'])) {
            $query->where('payment_attempts.provider_transaction_reference', $filters['provider_transaction_reference']);
        }

        $total = (clone $query)->count('payment_attempts.id');
        $lastPage = max((int) ceil($total / $perPage), 1);

        $rows = $query
            ->orderByDesc('payment_attempts.created_at')
            ->orderByDesc('payment_attempts.id')
            ->forPage($page, $perPage)
            ->get(['payment_attempts.*', 'carts.customer_user_id']);

        return $this->ok(200, 'Payments retrieved successfully.', [
            'payments' => AdminPaymentPresenter::presentList($rows),
            'pagination' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'last_page' => $lastPage,
            ],
        ]);
    }
}
