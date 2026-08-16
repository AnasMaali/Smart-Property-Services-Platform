<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Booking Cancellation Policy
    |--------------------------------------------------------------------------
    |
    | Refunds are currently executed manually by the company through Stripe.
    | BLUE only calculates the amount that should be refunded.
    |
    | Before the calendar day of the appointment:
    |     100% refund due.
    |
    | From 00:00 on the appointment day:
    |     75% refund due.
    |
    */

    'timezone' => env('BOOKING_CANCELLATION_TIMEZONE', 'UTC'),

    'before_appointment_day_percentage' => 100,

    'appointment_day_percentage' => 75,

];