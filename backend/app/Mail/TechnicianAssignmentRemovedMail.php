<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * BLUE V1 Phase B22 - the TECHNICIAN_ASSIGNMENT_REMOVED_EMAIL Mailable.
 * Deliberately carries only `booking_number` - the released Technician has
 * no operational need to know who replaced them (mirrors
 * App\Support\Notifications\TechnicianJobNotificationContent::
 * forAssignmentRemoved()'s own exclusion exactly).
 */
class TechnicianAssignmentRemovedMail extends Mailable
{
    use Queueable;

    /**
     * @param  array<string, string>  $fields
     */
    public function __construct(public readonly array $fields) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'BLUE | Assignment Update - '.$this->fields['booking_number'],
        );
    }

    public function content(): Content
    {
        return new Content(view: 'emails.technician.assignment-removed', with: ['fields' => $this->fields]);
    }
}
