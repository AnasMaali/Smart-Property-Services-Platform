<?php

namespace App\Actions\Admin\Payment;

use App\Support\Admin\AdminPaymentPresenter;
use App\Support\Cart\Concerns\BuildsCartResult;
use App\Support\Uuid\UuidBinary;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Read-only Admin Payment Attempt detail lookup (BLUE V1 Phase B5) - unlike
 * App\Actions\Payment\GetPaymentAction, never ownership-scoped to one
 * customer. A malformed or unknown Payment UUID is reported identically as
 * 404, matching the existing customer-facing convention.
 */
final class AdminGetPaymentAction
{
    use BuildsCartResult;

    /**
     * @return array<string, mixed>
     */
    public function handle(string $paymentUuid): array
    {
        try {
            $paymentIdBinary = UuidBinary::toBinary($paymentUuid);
        } catch (InvalidArgumentException) {
            return $this->notFound('Payment not found.');
        }

        $row = DB::table('payment_attempts')
            ->join('carts', 'carts.id', '=', 'payment_attempts.cart_id')
            ->where('payment_attempts.id', $paymentIdBinary)
            ->first(['payment_attempts.*', 'carts.customer_user_id']);

        if ($row === null) {
            return $this->notFound('Payment not found.');
        }

        return $this->ok(200, 'Payment retrieved successfully.', ['payment' => AdminPaymentPresenter::detail($row)]);
    }
}
