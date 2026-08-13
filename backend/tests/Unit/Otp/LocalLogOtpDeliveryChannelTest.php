<?php

namespace Tests\Unit\Otp;

use App\Support\Otp\LocalLogOtpDeliveryChannel;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class LocalLogOtpDeliveryChannelTest extends TestCase
{
    public function test_it_logs_one_line_containing_purpose_code_and_expiry(): void
    {
        Log::spy();

        $expiresAt = Carbon::parse('2026-08-13T14:20:00+00:00');

        (new LocalLogOtpDeliveryChannel)->deliver('PHONE_VERIFICATION', '+971500000001', '123456', $expiresAt);

        Log::shouldHaveReceived('info')->once()->withArgs(
            fn (string $message) => str_contains($message, '[LOCAL OTP]')
                && str_contains($message, 'purpose=PHONE_VERIFICATION')
                && str_contains($message, 'code=123456')
                && str_contains($message, 'expires_at=2026-08-13T14:20:00+00:00')
        );
    }

    public function test_it_masks_all_but_the_last_four_digits_of_the_phone_number(): void
    {
        Log::spy();

        (new LocalLogOtpDeliveryChannel)->deliver('PHONE_VERIFICATION', '+971500000001', '123456', Carbon::now());

        Log::shouldHaveReceived('info')->once()->withArgs(
            fn (string $message) => str_contains($message, 'phone=*********0001')
                && ! str_contains($message, '+971500000001')
        );
    }

    public function test_short_phone_numbers_are_left_unmasked(): void
    {
        Log::spy();

        (new LocalLogOtpDeliveryChannel)->deliver('PHONE_VERIFICATION', '1234', '654321', Carbon::now());

        Log::shouldHaveReceived('info')->once()->withArgs(
            fn (string $message) => str_contains($message, 'phone=1234')
        );
    }

    public function test_it_never_logs_a_password_jwt_or_stripe_looking_value(): void
    {
        Log::spy();

        // PASSWORD_RESET is a legitimate OTP purpose name (expected to
        // appear verbatim), so this checks for actual secret-shaped
        // substrings, never the word "password" on its own.
        (new LocalLogOtpDeliveryChannel)->deliver('PASSWORD_RESET', '+971500000002', '000111', Carbon::now());

        Log::shouldHaveReceived('info')->once()->withArgs(function (string $message) {
            $lower = strtolower($message);

            return ! str_contains($lower, 'password_hash')
                && ! str_contains($lower, 'password=')
                && ! str_contains($lower, 'sk_')
                && ! str_contains($lower, 'whsec_')
                && ! str_contains($lower, 'bearer');
        });
    }
}
