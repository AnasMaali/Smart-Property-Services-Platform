<?php

namespace Tests\Feature\Auth;

use App\Support\Otp\OtpDeliveryException;
use App\Support\Otp\TwilioOtpDeliveryChannel;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Exercises App\Support\Otp\TwilioOtpDeliveryChannel directly against
 * Http::fake() - never a real Twilio call. Provider resolution/config
 * validation (App\Providers\OtpDeliveryServiceProvider) is covered
 * separately by OtpDeliveryServiceProviderTest; this file is only about
 * what the channel itself sends and how it reacts to Twilio's response.
 */
class TwilioOtpDeliveryChannelTest extends TestCase
{
    private const ENDPOINT = 'https://api.twilio.com/2010-04-01/Accounts/AC_test_sid/Messages.json';

    private function channel(?string $messagingServiceSid = 'MG_test_service_sid', ?string $fromNumber = null): TwilioOtpDeliveryChannel
    {
        return new TwilioOtpDeliveryChannel(
            accountSid: 'AC_test_sid',
            authToken: 'super-secret-auth-token',
            messagingServiceSid: $messagingServiceSid,
            fromNumber: $fromNumber,
            timeoutSeconds: 5,
        );
    }

    public function test_posts_to_the_expected_twilio_messages_endpoint(): void
    {
        Http::fake([self::ENDPOINT => Http::response(['sid' => 'SM_fake'], 201)]);

        $this->channel()->deliver('PHONE_VERIFICATION', '+971501234567', '135790', Carbon::now()->addMinutes(5));

        Http::assertSent(fn ($request) => $request->url() === self::ENDPOINT);
    }

    public function test_uses_basic_auth_with_the_configured_account_sid_and_token(): void
    {
        Http::fake([self::ENDPOINT => Http::response(['sid' => 'SM_fake'], 201)]);

        $this->channel()->deliver('PHONE_VERIFICATION', '+971501234567', '135790', Carbon::now()->addMinutes(5));

        Http::assertSent(function ($request) {
            $header = $request->header('Authorization')[0] ?? '';

            return $header === 'Basic '.base64_encode('AC_test_sid:super-secret-auth-token');
        });
    }

    public function test_to_field_is_the_exact_phone_number_passed_by_the_caller(): void
    {
        Http::fake([self::ENDPOINT => Http::response(['sid' => 'SM_fake'], 201)]);

        $this->channel()->deliver('PHONE_VERIFICATION', '+971501234567', '135790', Carbon::now()->addMinutes(5));

        Http::assertSent(fn ($request) => $request['To'] === '+971501234567');
    }

    public function test_body_contains_the_exact_otp_code_and_nothing_else_sensitive(): void
    {
        Http::fake([self::ENDPOINT => Http::response(['sid' => 'SM_fake'], 201)]);

        $this->channel()->deliver('PHONE_VERIFICATION', '+971501234567', '135790', Carbon::now()->addMinutes(5));

        Http::assertSent(function ($request) {
            $body = $request['Body'];

            return str_contains($body, '135790')
                && ! str_contains($body, 'super-secret-auth-token')
                && ! str_contains($body, 'AC_test_sid');
        });
    }

    public function test_uses_messaging_service_sid_when_configured_and_omits_from(): void
    {
        Http::fake([self::ENDPOINT => Http::response(['sid' => 'SM_fake'], 201)]);

        $this->channel(messagingServiceSid: 'MG_test_service_sid', fromNumber: null)
            ->deliver('PHONE_VERIFICATION', '+971501234567', '135790', Carbon::now()->addMinutes(5));

        Http::assertSent(fn ($request) => $request['MessagingServiceSid'] === 'MG_test_service_sid'
            && ! array_key_exists('From', $request->data()));
    }

    public function test_uses_from_number_when_messaging_service_sid_absent(): void
    {
        Http::fake([self::ENDPOINT => Http::response(['sid' => 'SM_fake'], 201)]);

        $this->channel(messagingServiceSid: null, fromNumber: '+15005550006')
            ->deliver('PHONE_VERIFICATION', '+971501234567', '135790', Carbon::now()->addMinutes(5));

        Http::assertSent(fn ($request) => $request['From'] === '+15005550006'
            && ! array_key_exists('MessagingServiceSid', $request->data()));
    }

    public function test_messaging_service_sid_takes_precedence_when_both_configured(): void
    {
        Http::fake([self::ENDPOINT => Http::response(['sid' => 'SM_fake'], 201)]);

        $this->channel(messagingServiceSid: 'MG_test_service_sid', fromNumber: '+15005550006')
            ->deliver('PHONE_VERIFICATION', '+971501234567', '135790', Carbon::now()->addMinutes(5));

        Http::assertSent(fn ($request) => $request['MessagingServiceSid'] === 'MG_test_service_sid'
            && ! array_key_exists('From', $request->data()));
    }

    public function test_successful_2xx_response_returns_cleanly(): void
    {
        Http::fake([self::ENDPOINT => Http::response(['sid' => 'SM_fake'], 201)]);

        $this->channel()->deliver('PHONE_VERIFICATION', '+971501234567', '135790', Carbon::now()->addMinutes(5));

        $this->addToAssertionCount(1);
    }

    public function test_non_2xx_response_throws_a_sanitized_delivery_exception(): void
    {
        Http::fake([self::ENDPOINT => Http::response([
            'code' => 21211,
            'message' => 'The "To" number is not a valid phone number.',
            'more_info' => 'https://www.twilio.com/docs/errors/21211',
            'status' => 400,
        ], 400)]);

        try {
            $this->channel()->deliver('PHONE_VERIFICATION', '+971501234567', '135790', Carbon::now()->addMinutes(5));
            $this->fail('Expected OtpDeliveryException to be thrown.');
        } catch (OtpDeliveryException $e) {
            $this->assertStringNotContainsString('super-secret-auth-token', $e->getMessage());
            $this->assertStringNotContainsString('not a valid phone number', $e->getMessage());
            $this->assertStringNotContainsString('21211', $e->getMessage());
            $this->assertStringContainsString('400', $e->getMessage());
        }
    }

    public function test_connection_failure_throws_a_sanitized_delivery_exception(): void
    {
        Http::fake(fn () => throw new ConnectionException('Connection timed out contacting Twilio internals.'));

        try {
            $this->channel()->deliver('PHONE_VERIFICATION', '+971501234567', '135790', Carbon::now()->addMinutes(5));
            $this->fail('Expected OtpDeliveryException to be thrown.');
        } catch (OtpDeliveryException $e) {
            $this->assertStringNotContainsString('super-secret-auth-token', $e->getMessage());
        }
    }

    public function test_raw_otp_is_never_present_in_the_thrown_exception_message(): void
    {
        Http::fake([self::ENDPOINT => Http::response(['message' => 'failure'], 500)]);

        try {
            $this->channel()->deliver('PHONE_VERIFICATION', '+971501234567', '135790', Carbon::now()->addMinutes(5));
            $this->fail('Expected OtpDeliveryException to be thrown.');
        } catch (OtpDeliveryException $e) {
            $this->assertStringNotContainsString('135790', $e->getMessage());
        }
    }

    public function test_full_phone_number_is_never_present_in_the_thrown_exception_message(): void
    {
        Http::fake([self::ENDPOINT => Http::response(['message' => 'failure'], 500)]);

        try {
            $this->channel()->deliver('PHONE_VERIFICATION', '+971501234567', '135790', Carbon::now()->addMinutes(5));
            $this->fail('Expected OtpDeliveryException to be thrown.');
        } catch (OtpDeliveryException $e) {
            $this->assertStringNotContainsString('+971501234567', $e->getMessage());
            $this->assertStringContainsString('4567', $e->getMessage());
        }
    }

}
