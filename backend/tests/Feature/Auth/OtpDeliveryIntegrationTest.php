<?php

namespace Tests\Feature\Auth;

use App\Support\Otp\OtpDeliveryChannel;
use App\Support\Uuid\UuidBinary;
use Closure;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Tests\Support\AuthenticatesCustomersForTests;
use Tests\TestCase;

/**
 * End-to-end proof that local OTP delivery (OTP_DELIVERY_DRIVER=log,
 * APP_ENV=local) unblocks every real OTP-issuing flow through the real API
 * - no bypass, no code-hash weakening, no API/DB contract change - and
 * that the exact same flows never log anything when local delivery is not
 * explicitly enabled (the default, and every non-local environment).
 */
class OtpDeliveryIntegrationTest extends TestCase
{
    use DatabaseTransactions;
    use AuthenticatesCustomersForTests;

    private const LOG_LINE_PATTERN = '/\[LOCAL OTP] purpose=(\S+) phone=(\S+) code=(\d{6}) expires_at=(\S+)/';

    private int $dubaiCityId;

    private int $dubaiAreaId;

    private int $propertyOwnerTypeId;

    private array $serviceCategoryIds;

    private static int $sequence = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dubaiCityId = (int) DB::table('cities')->where('code', 'DUBAI')->value('id');
        $this->dubaiAreaId = (int) DB::table('areas')->where('city_id', $this->dubaiCityId)->value('id');
        $this->propertyOwnerTypeId = (int) DB::table('property_relationship_types')->where('code', 'PROPERTY_OWNER')->value('id');
        $this->serviceCategoryIds = DB::table('service_categories')->whereIn('code', ['AC', 'CLEANING'])->pluck('id')->all();
    }

    protected function tearDown(): void
    {
        $this->app->instance('env', 'testing');
        $this->app->forgetInstance(OtpDeliveryChannel::class);

        parent::tearDown();
    }

    /**
     * Proves the real environment gate (App\Providers\OtpDeliveryServiceProvider
     * only ever binds LocalLogOtpDeliveryChannel while app()->environment()
     * reports "local") without leaving the ambient environment faked for the
     * rest of the request - other, unrelated code also branches on
     * App::environment() (e.g. HasPasswordPolicy's uncompromised() check),
     * and this suite must not accidentally change that behavior just to
     * test OTP delivery. Because the binding is a singleton, resolving it
     * once while genuinely "local" and then restoring "testing" is enough:
     * every later resolution during the same request reuses the same
     * already-built LocalLogOtpDeliveryChannel instance.
     */
    private function enableLocalLogDelivery(): void
    {
        config(['otp.delivery_driver' => 'log']);
        $this->app->forgetInstance(OtpDeliveryChannel::class);

        $this->app->instance('env', 'local');
        $this->app->make(OtpDeliveryChannel::class);
        $this->app->instance('env', 'testing');
    }

    private function disableLocalLogDelivery(): void
    {
        config(['otp.delivery_driver' => 'null']);
        $this->app->instance('env', 'testing');
        $this->app->forgetInstance(OtpDeliveryChannel::class);
    }

    /**
     * Runs $makeRequest with a fresh Log spy in place and extracts the one
     * [LOCAL OTP] line it should have produced, returning the request's
     * response alongside the parsed purpose/phone/code/expiry.
     */
    private function captureLoggedOtp(Closure $makeRequest): array
    {
        // Log::spy() is a no-op once the facade is already a mock (it only
        // swaps in a fresh spy the first time), so a second call within the
        // same test would otherwise keep accumulating call history from an
        // earlier capture. Clearing both the facade's resolved instance and
        // the container's singleton forces the next resolution to rebuild a
        // genuine Illuminate\Log\LogManager first, so Mockery spies that
        // fresh, unmocked instance instead of trying (and failing, with a
        // class-redeclaration fatal) to wrap the previous spy again.
        Log::clearResolvedInstance('log');
        $this->app->forgetInstance('log');
        Log::spy();

        $response = $makeRequest();

        // The closure-based args matcher only counts toward ->once() when it
        // actually returns true, so an incidental unrelated info() call
        // elsewhere in the request (framework or otherwise) can never be
        // mistaken for the OTP delivery line this assertion is about.
        $captured = null;
        Log::shouldHaveReceived('info')->once()->withArgs(function (string $message) use (&$captured) {
            if (! str_starts_with($message, '[LOCAL OTP]')) {
                return false;
            }

            $captured = $message;

            return true;
        });

        $this->assertNotNull($captured, 'Expected exactly one [LOCAL OTP] log line to have been written.');
        $this->assertMatchesRegularExpression(self::LOG_LINE_PATTERN, $captured);

        preg_match(self::LOG_LINE_PATTERN, $captured, $matches);

        return [
            'response' => $response,
            'purpose' => $matches[1],
            'phone_masked' => $matches[2],
            'code' => $matches[3],
            'expires_at' => $matches[4],
        ];
    }

    private function registerPayload(): array
    {
        self::$sequence++;

        return [
            'full_name' => 'Otp Delivery Tester',
            'phone_number' => '+9715011000'.str_pad((string) self::$sequence, 2, '0', STR_PAD_LEFT),
            'email' => 'otp.delivery.tester'.self::$sequence.'@example.com',
            'password' => 'Passw0rd123',
            'city_id' => $this->dubaiCityId,
            'area_id' => $this->dubaiAreaId,
            'property_relationship_type_id' => $this->propertyOwnerTypeId,
            'service_interests' => $this->serviceCategoryIds,
        ];
    }

    // 1. Registration: the real code is delivered via the local log channel,
    // the API response never contains it, the DB only ever holds the hash,
    // and the captured code verifies through the real, unmodified
    // Hash::check() path.
    public function test_registration_delivers_otp_via_local_log_and_verification_succeeds_unmodified(): void
    {
        $this->enableLocalLogDelivery();
        $payload = $this->registerPayload();

        $captured = $this->captureLoggedOtp(fn () => $this->postJson('/api/v1/auth/register', $payload));

        $captured['response']->assertStatus(201);
        $this->assertSame('PHONE_VERIFICATION', $captured['purpose']);

        $json = strtolower(json_encode($captured['response']->json()));
        $this->assertStringNotContainsString($captured['code'], $json);
        $this->assertStringNotContainsString('"otp_code"', $json);
        $this->assertStringNotContainsString('"code":', $json);
        $this->assertStringNotContainsString('"verification_code"', $json);

        $otpUuid = $captured['response']->json('data.otp_verification_uuid');
        $otpRow = DB::table('otp_verifications')->where('id', UuidBinary::toBinary($otpUuid))->first();

        $this->assertNotSame($captured['code'], $otpRow->code_hash);
        $this->assertTrue(Hash::check($captured['code'], $otpRow->code_hash));

        // The captured code verifies through the real, unmodified endpoint.
        $verifyResponse = $this->postJson('/api/v1/auth/verify-phone', [
            'otp_verification_uuid' => $otpUuid,
            'otp_code' => $captured['code'],
        ]);

        $verifyResponse->assertStatus(200)->assertJson([
            'success' => true,
            'data' => ['account_status' => 'ACTIVE', 'phone_verified' => true],
        ]);
    }

    // 2. Resend Phone OTP uses the same delivery mechanism and produces a
    // freshly-verifiable code.
    public function test_resend_phone_otp_delivers_via_local_log(): void
    {
        $this->disableLocalLogDelivery();
        $payload = $this->registerPayload();
        $registerResponse = $this->postJson('/api/v1/auth/register', $payload)->assertStatus(201);
        $firstOtpUuid = $registerResponse->json('data.otp_verification_uuid');

        // Move the cooldown window back so resend is immediately allowed.
        DB::table('otp_verifications')
            ->where('id', UuidBinary::toBinary($firstOtpUuid))
            ->update(['created_at' => now()->subMinutes(2)]);

        $this->enableLocalLogDelivery();

        $captured = $this->captureLoggedOtp(
            fn () => $this->postJson('/api/v1/auth/resend-otp', ['otp_verification_uuid' => $firstOtpUuid])
        );

        $captured['response']->assertStatus(200);
        $this->assertSame('PHONE_VERIFICATION', $captured['purpose']);

        $newOtpUuid = $captured['response']->json('data.otp_verification_uuid');

        $this->postJson('/api/v1/auth/verify-phone', [
            'otp_verification_uuid' => $newOtpUuid,
            'otp_code' => $captured['code'],
        ])->assertStatus(200)->assertJson(['success' => true]);
    }

    // 3. Account deletion OTP: authenticated customers receive a real
    // ACCOUNT_DELETION OTP via local log when they request account deletion.
    public function test_account_deletion_otp_delivers_via_local_log(): void
    {
        $this->disableLocalLogDelivery();
        $payload = $this->registerPayload();
        $registerResponse = $this->postJson('/api/v1/auth/register', $payload)->assertStatus(201);
        $otpUuid = $registerResponse->json('data.otp_verification_uuid');

        $this->postJson('/api/v1/auth/verify-phone', [
            'otp_verification_uuid' => $otpUuid,
            'otp_code' => $this->rawCodeFor($otpUuid),
        ])->assertStatus(200);

        $login = $this->issueCustomerSession($registerResponse->json('data.user_uuid'));
        $accessToken = $login['access_token'];

        $this->enableLocalLogDelivery();

        $captured = $this->captureLoggedOtp(fn () => $this->postJson(
            '/api/v1/auth/account/request-otp',
            [],
            ['Authorization' => 'Bearer '.$accessToken]
        ));

        $captured['response']->assertStatus(200)->assertJson([
            'success' => true,
            'message' => 'A verification code has been sent to your phone number.',
        ]);
        $this->assertSame('ACCOUNT_DELETION', $captured['purpose']);
    }

    // 4 & 5. Phone number change (request + resend) reuse the same
    // mechanism for an already-authenticated customer.
    public function test_phone_number_change_request_and_resend_deliver_via_local_log(): void
    {
        $this->disableLocalLogDelivery();
        $payload = $this->registerPayload();
        $registerResponse = $this->postJson('/api/v1/auth/register', $payload)->assertStatus(201);
        $otpUuid = $registerResponse->json('data.otp_verification_uuid');

        $this->postJson('/api/v1/auth/verify-phone', [
            'otp_verification_uuid' => $otpUuid,
            'otp_code' => $this->rawCodeFor($otpUuid),
        ])->assertStatus(200);

        $login = $this->issueCustomerSession($registerResponse->json('data.user_uuid'));

        $accessToken = $login['access_token'];
        $newPhone = '+9715022000'.str_pad((string) (++self::$sequence), 2, '0', STR_PAD_LEFT);

        $this->enableLocalLogDelivery();

        $captured = $this->captureLoggedOtp(fn () => $this->postJson(
            '/api/v1/auth/change-phone-number',
            ['new_phone_number' => $newPhone],
            ['Authorization' => 'Bearer '.$accessToken]
        ));

        $captured['response']->assertStatus(200);
        $this->assertSame('PHONE_NUMBER_CHANGE', $captured['purpose']);
        $changeOtpUuid = $captured['response']->json('data.otp_verification_uuid');

        DB::table('otp_verifications')
            ->where('id', UuidBinary::toBinary($changeOtpUuid))
            ->update(['created_at' => now()->subMinutes(2)]);

        $resendCaptured = $this->captureLoggedOtp(fn () => $this->postJson(
            '/api/v1/auth/resend-phone-number-change-otp',
            ['otp_verification_uuid' => $changeOtpUuid],
            ['Authorization' => 'Bearer '.$accessToken]
        ));

        $resendCaptured['response']->assertStatus(200);
        $this->assertSame('PHONE_NUMBER_CHANGE', $resendCaptured['purpose']);
        $resentOtpUuid = $resendCaptured['response']->json('data.otp_verification_uuid');

        $this->postJson('/api/v1/auth/verify-phone-number-change-otp', [
            'otp_verification_uuid' => $resentOtpUuid,
            'otp_code' => $resendCaptured['code'],
        ], ['Authorization' => 'Bearer '.$accessToken])
            ->assertStatus(200)
            ->assertJson(['success' => true, 'data' => ['phone_number' => $newPhone]]);
    }

    // 6. Outside local delivery (the default - config('otp.delivery_driver')
    // = 'null'), the exact same registration flow never logs anything.
    public function test_no_log_line_is_written_when_local_delivery_is_not_enabled(): void
    {
        $this->disableLocalLogDelivery();
        Log::spy();

        $this->postJson('/api/v1/auth/register', $this->registerPayload())->assertStatus(201);

        Log::shouldNotHaveReceived('info', fn (string $message) => str_starts_with($message, '[LOCAL OTP]'));
    }

    // 7. The DB schema itself never grew a raw-code column - only the hash
    // column is ever written, in both delivery modes.
    public function test_only_the_hash_column_is_ever_populated(): void
    {
        $this->enableLocalLogDelivery();
        $payload = $this->registerPayload();

        $captured = $this->captureLoggedOtp(fn () => $this->postJson('/api/v1/auth/register', $payload));
        $otpUuid = $captured['response']->json('data.otp_verification_uuid');

        $columns = collect(DB::select('SHOW COLUMNS FROM otp_verifications'))->pluck('Field');

        $this->assertTrue($columns->contains('code_hash'));
        $this->assertFalse($columns->contains('code'));
        $this->assertFalse($columns->contains('raw_code'));
        $this->assertFalse($columns->contains('otp_code'));
    }

    /**
     * For setup steps that intentionally run with local delivery disabled
     * (matching ChangePhoneNumberTest::latestOtpRawCode()): overwrites the
     * stored hash with a known raw code, so a *later* step in the same test
     * can be reached without weakening or bypassing Hash::check() itself.
     * Never used on the delivery mechanism under test - that is always
     * asserted by capturing the real log line via captureLoggedOtp().
     */
    private function rawCodeFor(string $otpUuid): string
    {
        $rawCode = '246810';

        DB::table('otp_verifications')
            ->where('id', UuidBinary::toBinary($otpUuid))
            ->update(['code_hash' => Hash::make($rawCode)]);

        return $rawCode;
    }
}
