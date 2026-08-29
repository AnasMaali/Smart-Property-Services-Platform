<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * BLUE V1 Phase B22 - the CUSTOMER_TECHNICIAN_CHANGED_EMAIL Mailable. Never
 * reveals the internal reassignment reason to the customer (BLUE V1 email
 * spec section 6) - `fields` carries only the new technician's identity and
 * the same safe booking/appointment/address/paid-amount fields
 * CustomerTechnicianAssignedMail does.
 */
class CustomerTechnicianChangedMail extends Mailable
{
    use Queueable;

    /**
     * @param  array<string, string|null>  $fields
     */
    public function __construct(public readonly array $fields) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'BLUE | Technician Updated for Your Booking',
        );
    }

    public function content(): Content
    {
        return new Content(view: 'emails.customer.technician-changed', with: ['fields' => $this->fields]);
    }
}
