<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Contract Billing Provider
    |--------------------------------------------------------------------------
    |
    | BLUE V1 Phase 11 approved provider is Stripe (Checkout mode=subscription
    | + Subscriptions + Invoices). This selects which ContractBillingGateway
    | binding App\Providers\ContractBillingServiceProvider resolves for the
    | "stripe" -> App\Support\Contract\Billing\Gateway\
    | StripeContractBillingGateway binding used outside of tests. The
    | automated test suite always binds App\Support\Contract\Billing\
    | Gateway\FakeContractBillingGateway instead (see
    | ContractBillingServiceProvider) regardless of this value, so tests
    | never reach the Stripe network.
    |
    */

    'provider' => env('CONTRACT_BILLING_PROVIDER', 'stripe'),

    /*
    |--------------------------------------------------------------------------
    | Provider Codes
    |--------------------------------------------------------------------------
    |
    | The exact service_contract_billings.provider_code /
    | service_contract_billing_webhook_events.provider_code value each
    | supported ContractBillingGateway implementation writes. Never
    | hardcode the literal string elsewhere - always resolve it through the
    | bound ContractBillingGateway::providerCode().
    |
    */

    'stripe_provider_code' => 'STRIPE',

    /*
    |--------------------------------------------------------------------------
    | Checkout Redirect URLs
    |--------------------------------------------------------------------------
    |
    | Stripe Checkout Sessions require server-configured success/cancel
    | redirect URLs - the customer never supplies these (BLUE V1 Phase 11
    | "Never trust monetary data from the client" extends to every Checkout
    | Session parameter, not only amount/currency).
    |
    */

    'checkout_success_url' => env('CONTRACT_BILLING_CHECKOUT_SUCCESS_URL'),

    'checkout_cancel_url' => env('CONTRACT_BILLING_CHECKOUT_CANCEL_URL'),

    /*
    |--------------------------------------------------------------------------
    | Past-Due Grace Period
    |--------------------------------------------------------------------------
    |
    | How many days a Service Contract's billing may stay PAST_DUE (while
    | Stripe's own automatic retry schedule keeps trying to collect the
    | recurring charge - BLUE V1 never invents its own retry/collection
    | logic) before App\Actions\Contract\Billing\
    | SuspendContractsPastDueBillingAction escalates the Contract itself
    | ACTIVE -> SUSPENDED. New CONTRACT Bookings are already blocked for the
    | entire PAST_DUE window regardless of this value - see
    | App\Actions\Contract\CreateContractBookingAction.
    |
    */

    'grace_days' => (int) env('CONTRACT_BILLING_GRACE_DAYS', 3),

];
