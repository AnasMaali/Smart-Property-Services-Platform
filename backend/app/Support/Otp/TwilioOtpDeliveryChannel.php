<?php

namespace App\Support\Otp;

use Carbon\CarbonInterface;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * BLUE V1's first real production SMS provider: delivers an already-
 * generated, already-hashed-and-persisted OTP through Twilio's Messages
 * REST API (https://www.twilio.com/docs/sms/api/message-resource#create-a-message-resource).
 *
 * One HTTP request per deliver() call, no automatic retry - App\Providers\
 * OtpDeliveryServiceProvider already validated every required credential
 * exists before constructing this class, so the only failure mode left
 * here is the Twilio API call itself, and the existing resend/cooldown
 * flow (see e.g. App\Actions\Auth\ResendPhoneOtpAction) is this
 * codebase's one, explicit retry mechanism - never a second implicit one
 * inside a delivery channel.
 *
 * Never logs the raw OTP, never logs $authToken, and never lets a Twilio
 * response body (which - unlike the account SID/auth token - is not
 * secret, but may still carry request-identifying detail this codebase
 * has no established policy for retaining) reach the thrown exception;
 * only the HTTP status code does. See App\Support\Otp\OtpDeliveryException
 * for why that boundary matters.
 */
final readonly class TwilioOtpDeliveryChannel implements OtpDeliveryChannel
{
    private const MESSAGES_ENDPOINT = 'https://api.twilio.com/2010-04-01/Accounts/%s/Messages.json';

    public function __construct(
        private string $accountSid,
        private string $authToken,
        private ?string $messagingServiceSid,
        private ?string $fromNumber,
        private int $timeoutSeconds,
    ) {}

    public function deliver(string $purpose, string $phoneNumber, string $rawOtp, CarbonInterface $expiresAt): void
    {
        $parameters = [
            'To' => $phoneNumber,
            'Body' => "Your BLUE verification code is: {$rawOtp}. Do not share this code.",
        ];

        if ($this->messagingServiceSid !== null) {
            $parameters['MessagingServiceSid'] = $this->messagingServiceSid;
        } else {
            $parameters['From'] = $this->fromNumber;
        }

        try {
            $response = Http::asForm()
                ->withBasicAuth($this->accountSid, $this->authToken)
                ->timeout($this->timeoutSeconds)
                ->post(sprintf(self::MESSAGES_ENDPOINT, $this->accountSid), $parameters);
        } catch (ConnectionException) {
            throw new OtpDeliveryException(sprintf(
                'Twilio OTP delivery failed for phone %s: could not reach the provider.',
                OtpPhoneMasking::mask($phoneNumber),
            ));
        }

        if ($response->failed()) {
            throw new OtpDeliveryException(sprintf(
                'Twilio OTP delivery failed for phone %s with HTTP status %d.',
                OtpPhoneMasking::mask($phoneNumber),
                $response->status(),
            ));
        }
    }
}
