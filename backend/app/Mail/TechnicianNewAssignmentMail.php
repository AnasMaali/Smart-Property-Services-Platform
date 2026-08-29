<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * BLUE V1 Phase B22 - the TECHNICIAN_NEW_ASSIGNMENT_EMAIL Mailable. Built
 * entirely from the already-safe `outbound_notifications.payload_snapshot`
 * fields App\Support\Notifications\TechnicianJobNotificationContent
 * assembled at obligation-creation time (see App\Actions\Notifications\
 * SendEmailNotificationAction) - never re-queries the database itself, and
 * never queued (BLUE V1 email spec section 7: the best-effort send happens
 * synchronously, immediately after the assignment transaction commits).
 */
class TechnicianNewAssignmentMail extends Mailable
{
    use Queueable;

    /**
     * @param  array<string, string>  $fields
     */
    public function __construct(public readonly array $fields) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'BLUE | New Service Assignment - '.$this->fields['booking_number'],
        );
    }

    public function content(): Content
    {
        return new Content(view: 'emails.technician.new-assignment', with: ['fields' => $this->fields]);
    }
}
