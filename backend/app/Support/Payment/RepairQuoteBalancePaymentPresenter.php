<?php

namespace App\Support\Payment;

use App\Support\Payment\Gateway\PaymentCreationResult;
use App\Support\Uuid\UuidBinary;
use Illuminate\Support\Facades\DB;

/**
 * BLUE V1 Phase B25 - the repair-quote-balance-payment counterpart to
 * App\Support\Payment\PaymentPresenter, same safety rules exactly (never
 * exposes idempotency_key, a raw provider reference beyond the safe
 * client_secret, or any provider secret).
 */
final class RepairQuoteBalancePaymentPresenter
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
            'reference' => $row->reference,
            'status' => $status,
            'requested_amount' => $row->requested_amount,
            'currency' => [
                'code' => $currency->code,
                'symbol' => $currency->symbol,
                'decimal_places' => (int) $currency->minor_unit,
            ],
            'provider' => $row->provider_code,
        ];

        if ($creationResult !== null && $creationResult->clientSecret !== null) {
            $payload['client_secret'] = $creationResult->clientSecret;
            $payload['publishable_key'] = config('services.stripe.publishable_key');
        }

        return $payload;
    }
}
