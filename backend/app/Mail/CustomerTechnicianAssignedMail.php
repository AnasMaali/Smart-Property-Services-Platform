<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * BLUE V1 Phase B22 - the CUSTOMER_TECHNICIAN_ASSIGNED_EMAIL Mailable.
 * `fields['paid_amount']` is the authoritative historical Booking/payment
 * snapshot (App\Support\Notifications\Email\CustomerAssignmentEmailContent)
 * - never Stripe/provider internals, never the Service's current live
 * price.
 */
class CustomerTechnicianAssignedMail extends Mailable
{
    use Queueable;

    /**
     * @param  array<string, string|null>  $fields
     */
    public function __construct(public readonly array $fields) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'BLUE | Technician Assigned to Your Booking',
        );
    }

    public function content(): Content
    {
        return new Content(view: 'emails.customer.technician-assigned', with: ['fields' => $this->fields]);
    }
}
