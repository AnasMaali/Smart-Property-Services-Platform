<?php

namespace App\Support\Otp;

use RuntimeException;

/**
 * Thrown by an OtpDeliveryChannel implementation when it cannot deliver an
 * already-generated, already-hashed, already-persisted OTP. Illuminate's
 * default exception handler reports (logs) an uncaught exception's
 * getMessage() and, outside APP_DEBUG, returns only a generic error to the
 * caller - so this message is the one place a provider failure may ever
 * surface, and it must never contain a credential, an Authorization
 * header, or a raw provider response body.
 *
 * Delivery and verification are unrelated: this is always thrown strictly
 * after the OTP row has already committed (every OtpDeliveryChannel::
 * deliver() call happens post-commit - see the calling Actions), so it
 * never rolls back or otherwise touches otp_verifications. The customer
 * simply does not receive a false "your code was sent" response for a
 * delivery that actually failed - they see a safe generic error, exactly
 * like any other unexpected exception in this codebase.
 */
final class OtpDeliveryException extends RuntimeException
{
}
