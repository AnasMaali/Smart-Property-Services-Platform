<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Booking Cancellation Policy
    |--------------------------------------------------------------------------
    |
    | BLUE V1 Phase B20 - refunds are executed automatically through Stripe
    | (App\Actions\Payment\ExecuteBookingRefundAction), back to the
    | original payment method, for the amount App\Support\Booking\
    | RefundEligibilityCalculator computes below.
    |
    | Before the calendar day of the appointment:
    |     100% refund due.
    |
    | From 00:00 on the appointment day, but before the appointment starts:
    |     75% refund due.
    |
    | At or after the appointment's start time:
    |     cancellation is not allowed at all - see
    |     RefundEligibilityCalculator::evaluate()'s 'cancellable' result.
    |
    */

    'timezone' => env('BOOKING_CANCELLATION_TIMEZONE', 'UTC'),

    'before_appointment_day_percentage' => 100,

    'appointment_day_percentage' => 75,

];
