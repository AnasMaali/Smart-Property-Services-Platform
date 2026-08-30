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
    | BLUE V1 is a UAE-only operation (Dubai/Abu Dhabi properties and
    | appointments, AED-only payments - see App\Actions\Payment\
    | ExecuteBookingRefundAction) - Asia/Dubai is the one correct default
    | business timezone for the calendar-day interpretation above, never
    | the machine/server timezone this app happens to run on. Appointment
    | instants themselves stay stored under `config('app.timezone')` (UTC)
    | in the database; only the CALENDAR DAY comparison is done in this
    | timezone - see RefundEligibilityCalculator::evaluate()'s docblock.
    |
    */

    'timezone' => env('BOOKING_CANCELLATION_TIMEZONE', 'Asia/Dubai'),

    'before_appointment_day_percentage' => 100,

    'appointment_day_percentage' => 75,

];
