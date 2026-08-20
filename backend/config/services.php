<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Stripe (BLUE V1 Phase 6A payment provider)
    |--------------------------------------------------------------------------
    |
    | Empty values are valid before a Stripe account exists - see
    | docs/api-contracts/payments-v1.md. App\Support\Payment\Gateway\
    | StripePaymentGateway checks these are non-empty before making any
    | Stripe API call and fails safely (throws a configuration error, never
    | a fake success) when they are not. Never commit real values here.
    |
    */

    'stripe' => [
        'secret_key' => env('STRIPE_SECRET_KEY'),
        'publishable_key' => env('STRIPE_PUBLISHABLE_KEY'),
        'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),

        // BLUE V1 Phase 11 Service Contract Billing (Stripe Subscriptions)
        // webhook endpoint - a DIFFERENT Stripe webhook endpoint than the
        // Booking PaymentIntent one above, so Stripe issues it its own,
        // separate signing secret. Reuses the same account 'secret_key'
        // above for API calls (creating Checkout Sessions/Customers) -
        // only the webhook secret is endpoint-specific.
        'contract_billing_webhook_secret' => env('STRIPE_CONTRACT_BILLING_WEBHOOK_SECRET'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Twilio (BLUE V1 production OTP delivery)
    |--------------------------------------------------------------------------
    |
    | Read only by App\Support\Otp\TwilioOtpDeliveryChannel, bound by
    | App\Providers\OtpDeliveryServiceProvider when OTP_DELIVERY_DRIVER=twilio
    | - see config/otp.php. The provider validates account_sid/auth_token
    | and exactly one sender strategy are present before ever constructing
    | the channel, so it fails closed at resolution time, never silently.
    | messaging_service_sid takes precedence over from_number when both are
    | set. Never commit real values here.
    |
    */

    'twilio' => [
        'account_sid' => env('TWILIO_ACCOUNT_SID'),
        'auth_token' => env('TWILIO_AUTH_TOKEN'),
        'messaging_service_sid' => env('TWILIO_MESSAGING_SERVICE_SID'),
        'from_number' => env('TWILIO_FROM_NUMBER'),
        'timeout_seconds' => (int) env('TWILIO_TIMEOUT_SECONDS', 10),
    ],

];
