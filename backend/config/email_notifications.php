<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Recovery
    |--------------------------------------------------------------------------
    |
    | Bounded-retry policy for the EMAIL-channel `outbound_notifications`
    | rows this app writes for Technician assignment/removal and Customer
    | technician-assigned/technician-changed notifications (App\Actions\
    | Notifications\SendEmailNotificationAction). Mirrors
    | config('technician_notifications.max_attempts') exactly - a send
    | failure is retried up to this many times (linear backoff) before being
    | converted to a terminal FAILED, never retried forever.
    |
    */

    'max_attempts' => (int) env('EMAIL_NOTIFICATION_MAX_ATTEMPTS', 5),

];
