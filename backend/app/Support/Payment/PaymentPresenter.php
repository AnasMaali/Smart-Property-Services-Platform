<?php

namespace App\Support\Payment;

use App\Support\Payment\Gateway\PaymentCreationResult;
use App\Support\Uuid\UuidBinary;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The one safe, Flutter-facing payment JSON shape - never exposes
 * idempotency_key, checkout_snapshot, checkout_snapshot_hash,
 * reconciliation fields, or any provider secret. $creationResult is only
 * ever passed by CreatePaymentAttemptAction, right after a fresh
 * PaymentGateway::createPayment() call, to attach a safe client-initiation
 * field (e.g. Stripe's PaymentIntent client_secret) that is never persisted
 * and therefore never available on a later GET.
 */
final class PaymentPresenter
{
    /**
     * @return array<string, mixed>
     */
    public static function present(object $row, ?PaymentCreationResult $creationResult = null): array
    {
        $status = DB::table('payment_statuses')->where('id', $row->status_id)->value('code');
        $currency = DB::table('currencies')->where('id', $row->currency_id)->first(['code', 'symbol', 'minor_unit']);

        $payload = [
            'uuid' => UuidBinary::toString($row->id),
            'checkout_reference' => $row->checkout_reference,
            'status' => $status,
            'requested_amount' => $row->requested_amount,
            'currency' => [
                'code' => $currency->code,
                'symbol' => $currency->symbol,
                'decimal_places' => (int) $currency->minor_unit,
            ],
            'expires_at' => $row->expires_at === null ? null : Carbon::parse($row->expires_at)->toIso8601String(),
            'provider' => $row->provider_code,
        ];

        if ($creationResult !== null && $creationResult->clientSecret !== null) {
            $payload['client_secret'] = $creationResult->clientSecret;
        }

        return $payload;
    }
}
