<?php

namespace App\Support\Contract\Billing;

use App\Support\Uuid\UuidBinary;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The safe, provider-neutral Service Contract Billing JSON shapes (BLUE V1
 * Phase 11) shared by App\Support\Contract\ContractPresenter (customer) and
 * App\Support\Admin\AdminContractPresenter (admin) - mirrors the existing
 * split between App\Support\Payment\PaymentPresenter and any admin
 * equivalent: the customer view never exposes Stripe object ids
 * (stripe_customer_id / stripe_subscription_id / stripe_price_id /
 * stripe_product_id) or the transient checkout_session_id/checkout_url
 * (those are only ever returned once, directly by
 * App\Actions\Contract\Billing\CreateContractBillingCheckoutAction's own
 * response) - only the admin view includes them, for support/operations
 * use.
 */
final class ContractBillingPresenter
{
    /**
     * @return array<string, mixed>|null
     */
    public static function customerView(string $contractIdBinary): ?array
    {
        $billing = self::row($contractIdBinary);

        if ($billing === null) {
            return null;
        }

        $currency = DB::table('currencies')->where('id', $billing->currency_id)->first(['code', 'symbol', 'minor_unit']);

        return [
            'status' => ContractBillingStatuses::code((int) $billing->status_id),
            'billing_interval' => $billing->billing_interval,
            'recurring_amount' => $billing->recurring_amount,
            'currency' => $currency === null ? null : ['code' => $currency->code, 'symbol' => $currency->symbol, 'decimal_places' => (int) $currency->minor_unit],
            'current_period_end' => $billing->current_period_end === null ? null : Carbon::parse($billing->current_period_end)->toIso8601String(),
            'cancel_at' => $billing->cancel_at === null ? null : Carbon::parse($billing->cancel_at)->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function adminView(string $contractIdBinary): ?array
    {
        $billing = self::row($contractIdBinary);

        if ($billing === null) {
            return null;
        }

        $currency = DB::table('currencies')->where('id', $billing->currency_id)->first(['code', 'symbol', 'minor_unit']);

        return [
            'uuid' => UuidBinary::toString($billing->id),
            'provider' => $billing->provider_code,
            'status' => ContractBillingStatuses::code((int) $billing->status_id),
            'billing_interval' => $billing->billing_interval,
            'recurring_amount' => $billing->recurring_amount,
            'currency' => $currency === null ? null : ['code' => $currency->code, 'symbol' => $currency->symbol, 'decimal_places' => (int) $currency->minor_unit],
            'stripe_customer_id' => $billing->stripe_customer_id,
            'stripe_subscription_id' => $billing->stripe_subscription_id,
            'stripe_price_id' => $billing->stripe_price_id,
            'stripe_product_id' => $billing->stripe_product_id,
            'current_period_start' => $billing->current_period_start === null ? null : Carbon::parse($billing->current_period_start)->toIso8601String(),
            'current_period_end' => $billing->current_period_end === null ? null : Carbon::parse($billing->current_period_end)->toIso8601String(),
            'past_due_since' => $billing->past_due_since === null ? null : Carbon::parse($billing->past_due_since)->toIso8601String(),
            'cancel_at' => $billing->cancel_at === null ? null : Carbon::parse($billing->cancel_at)->toIso8601String(),
            'cancelled_at' => $billing->cancelled_at === null ? null : Carbon::parse($billing->cancelled_at)->toIso8601String(),
            'created_at' => Carbon::parse($billing->created_at)->toIso8601String(),
            'updated_at' => Carbon::parse($billing->updated_at)->toIso8601String(),
        ];
    }

    private static function row(string $contractIdBinary): ?object
    {
        return DB::table('service_contract_billings')->where('service_contract_id', $contractIdBinary)->first();
    }
}
