<?php

namespace App\Actions\Payment;

use App\Support\Cart\Concerns\BuildsCartResult;
use App\Support\Payment\PaymentPresenter;
use App\Support\Uuid\UuidBinary;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Read-only, ownership-scoped payment lookup (GET /v1/payments/{payment}).
 * Ownership is always payment_attempt -> cart -> customer_user_id - there
 * is no separate customer_id column on payment_attempts by design (see
 * database/blue_v1_schema.sql). A foreign or unknown payment UUID is
 * reported identically as 404, matching Checkout's established convention
 * of never distinguishing "missing" from "not yours".
 */
class GetPaymentAction
{
    use BuildsCartResult;

    /**
     * @return array<string, mixed>
     */
    public function handle(string $userUuid, string $paymentUuid): array
    {
        try {
            $paymentIdBinary = UuidBinary::toBinary($paymentUuid);
        } catch (InvalidArgumentException) {
            return $this->notFound('Payment not found.');
        }

        $userIdBinary = UuidBinary::toBinary($userUuid);

        $row = DB::table('payment_attempts')
            ->join('carts', 'carts.id', '=', 'payment_attempts.cart_id')
            ->where('payment_attempts.id', $paymentIdBinary)
            ->where('carts.customer_user_id', $userIdBinary)
            ->first(['payment_attempts.*']);

        if ($row === null) {
            return $this->notFound('Payment not found.');
        }

        return $this->ok(200, 'Payment retrieved successfully.', ['payment' => PaymentPresenter::present($row)]);
    }
}
