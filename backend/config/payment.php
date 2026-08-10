<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Payment Provider
    |--------------------------------------------------------------------------
    |
    | BLUE V1 Phase 6A approved provider is Stripe. This selects which
    | PaymentGateway binding App\Providers\PaymentServiceProvider resolves
    | for the "stripe" -> App\Support\Payment\Gateway\StripePaymentGateway
    | binding used outside of tests. The automated test suite always binds
    | App\Support\Payment\Gateway\FakePaymentGateway instead (see
    | PaymentServiceProvider) regardless of this value, so tests never
    | reach the Stripe network.
    |
    */

    'provider' => env('PAYMENT_PROVIDER', 'stripe'),

    /*
    |--------------------------------------------------------------------------
    | Provider Codes
    |--------------------------------------------------------------------------
    |
    | The exact payment_attempts.provider_code / payment_webhook_events.
    | provider_code value each supported PaymentGateway implementation
    | writes. Never hardcode the literal string elsewhere - always resolve
    | it through the bound PaymentGateway::providerCode().
    |
    */

    'stripe_provider_code' => 'STRIPE',

];
