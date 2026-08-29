<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Technician Notification Driver
    |--------------------------------------------------------------------------
    |
    | BLUE V1 has no Technician mobile app yet - whenever an Admin assigns
    | (or reassigns) a Technician to a Booking Item, the Technician is
    | notified through WhatsApp instead (App\Actions\Notifications\
    | SendTechnicianNotificationAction). This setting selects the transport
    | ONLY - it never changes what is recorded in `outbound_notifications`
    | or `technician_assignments`, which remain the durable, provider-
    | independent source of truth. See App\Providers\
    | TechnicianNotificationServiceProvider for the only place this is read.
    |
    | Supported values:
    |   "log"          - writes the exact normalized notification payload to
    |                     the log instead of contacting Meta - safe for
    |                     local development with no WhatsApp credentials.
    |   "meta_whatsapp" - delivers via the official Meta WhatsApp Cloud API
    |                     (App\Support\Notifications\Gateway\
    |                     MetaWhatsAppTechnicianNotificationGateway). The
    |                     only supported production driver - never an
    |                     unofficial WhatsApp Web automation library.
    |
    | Under APP_ENV=testing this is never read at all -
    | TechnicianNotificationServiceProvider always binds
    | FakeTechnicianNotificationGateway there, mirroring
    | App\Providers\PaymentServiceProvider's FakePaymentGateway guard.
    |
    */

    'driver' => env('TECHNICIAN_NOTIFICATION_DRIVER', 'log'),

    /*
    |--------------------------------------------------------------------------
    | Recovery
    |--------------------------------------------------------------------------
    |
    | Bounded-retry policy for App\Console\Commands\
    | SendPendingTechnicianNotifications (scheduled every minute - see
    | routes/console.php). A transient/UNKNOWN provider outcome is retried
    | up to this many times before being converted to a terminal FAILED -
    | never retried forever.
    |
    */

    'max_attempts' => (int) env('TECHNICIAN_NOTIFICATION_MAX_ATTEMPTS', 5),

];
