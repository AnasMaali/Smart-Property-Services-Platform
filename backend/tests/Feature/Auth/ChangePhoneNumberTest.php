<?php

namespace Tests\Feature\Auth;

use App\Support\Uuid\UuidBinary;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\Support\AuthenticatesCustomersForTests;
use Tests\TestCase;

class ChangePhoneNumberTest extends TestCase
{
    use DatabaseTransactions;
    use AuthenticatesCustomersForTests;

    private const GENERIC_SESSION_MESSAGE = 'This session is invalid or has expired.';

    private static int $sequence = 0;

    private function purposeId(string $code): int
    {
        return (int) DB::table('otp_verification_purposes')->where('code', $code)->value('id');
    }

    private function otpStatusId(string $code): int
    {
        return (int) DB::table('otp_verification_statuses')->where('code', $code)->value('id');
    }

    private function accountStatusId(string $code): int
    {
        return (int) DB::table('user_account_statuses')->where('code', $code)->value('id');
    }

    private function roleId(string $code): int
    {
        return (int) DB::table('roles')->where('code', $code)->value('id');
    }

    /**
     * @return array{user_uuid: string, phone_number: string, password: string}
     */
    private function createCustomer(string $rawPassword = 'Passw0rd123'): array
    {
        self::$sequence++;

        $userUuid = UuidBinary::generate();
        $phoneNumber = '+97158000'.str_pad((string) self::$sequence, 4, '0', STR_PAD_LEFT);
        $now = now();

        DB::table('users')->insert([
            'id' => UuidBinary::toBinary($userUuid),
            'phone_number' => $phoneNumber,
            'email' => 'change.phone.'.self::$sequence.'@example.com',
            'password_hash' => Hash::make($rawPassword),
            'account_status_id' => $this->accountStatusId('ACTIVE'),
            'phone_verified_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('user_profiles')->insert([
            'user_id' => UuidBinary::toBinary($userUuid),
            'full_name' => 'Change Phone Customer',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('user_roles')->insert([
            'user_id' => UuidBinary::toBinary($userUuid),
            'role_id' => $this->roleId('CUSTOMER'),
            'assigned_by_user_id' => null,
            'assigned_at' => $now,
        ]);

        return ['user_uuid' => $userUuid, 'phone_number' => $phoneNumber, 'password' => $rawPassword];
    }

    private function loginCustomer(array $customer): array
    {
        return $this->issueCustomerSession($customer['user_uuid']);
    }

    private function newPhoneNumber(): string
    {
        self::$sequence++;

        return '+97159000'.str_pad((string) self::$sequence, 4, '0', STR_PAD_LEFT);
    }

    private function requestChange(?string $accessToken, string $newPhoneNumber)
    {
        $headers = $accessToken === null ? [] : ['Authorization' => 'Bearer '.$accessToken];

        return $this->postJson('/api/v1/auth/change-phone-number', [
            'new_phone_number' => $newPhoneNumber,
        ], $headers);
    }

    private function verifyChange(string $accessToken, string $otpVerificationUuid, string $otpCode)
    {
        return $this->postJson('/api/v1/auth/verify-phone-number-change-otp', [
            'otp_verification_uuid' => $otpVerificationUuid,
            'otp_code' => $otpCode,
        ], ['Authorization' => 'Bearer '.$accessToken]);
    }

    private function resendChange(string $accessToken, string $otpVerificationUuid)
    {
        return $this->postJson('/api/v1/auth/resend-phone-number-change-otp', [
            'otp_verification_uuid' => $otpVerificationUuid,
        ], ['Authorization' => 'Bearer '.$accessToken]);
    }

    // 1. Requesting a change to a valid, unused number creates a PENDING PHONE_NUMBER_CHANGE OTP.
    public function test_request_valid_new_number_creates_otp(): void
    {
        $customer = $this->createCustomer();
        $session = $this->loginCustomer($customer);
        $newPhone = $this->newPhoneNumber();

        $response = $this->requestChange($session['access_token'], $newPhone);

        $response->assertStatus(200)->assertJson([
            'success' => true,
            'data' => ['new_phone_number' => $newPhone],
        ]);
        $response->assertJsonStructure([
            'data' => ['otp_verification_uuid', 'new_phone_number', 'otp_expires_at', 'resend_available_at'],
        ]);

        $otp = DB::table('otp_verifications')
            ->where('user_id', UuidBinary::toBinary($customer['user_uuid']))
            ->where('purpose_id', $this->purposeId('PHONE_NUMBER_CHANGE'))
            ->first();

        $this->assertNotNull($otp);
        $this->assertSame($this->otpStatusId('PENDING'), (int) $otp->status_id);
        $this->assertSame($newPhone, $otp->target_phone_number);

        // Phone number is not changed yet.
        $user = DB::table('users')->where('id', UuidBinary::toBinary($customer['user_uuid']))->first();
        $this->assertSame($customer['phone_number'], $user->phone_number);
    }

    // 2. Requesting the current phone number is rejected.
    public function test_requesting_current_phone_number_is_rejected(): void
    {
        $customer = $this->createCustomer();
        $session = $this->loginCustomer($customer);

        $this->requestChange($session['access_token'], $customer['phone_number'])
            ->assertStatus(422)
            ->assertJson(['success' => false]);

        $this->assertDatabaseCount('otp_verifications', 0);
    }

    // 2b. Requesting a number already used by another account is rejected.
    public function test_requesting_a_phone_number_used_by_another_account_is_rejected(): void
    {
        $customer = $this->createCustomer();
        $otherCustomer = $this->createCustomer();
        $session = $this->loginCustomer($customer);

        $this->requestChange($session['access_token'], $otherCustomer['phone_number'])
            ->assertStatus(422)
            ->assertJson(['success' => false]);
    }

    public function test_requesting_a_phone_number_used_by_another_account_fails_request_validation(): void
    {
        $customer = $this->createCustomer();
        $otherCustomer = $this->createCustomer();
        $session = $this->loginCustomer($customer);

        $this->requestChange($session['access_token'], $otherCustomer['phone_number'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['new_phone_number']);
    }

    // 3. Cooldown: a second request inside 60 seconds does not create a new OTP.
    public function test_resend_cooldown_blocks_a_second_request_within_60_seconds(): void
    {
        $customer = $this->createCustomer();
        $session = $this->loginCustomer($customer);
        $firstPhone = $this->newPhoneNumber();
        $secondPhone = $this->newPhoneNumber();

        $this->requestChange($session['access_token'], $firstPhone)->assertStatus(200);

        $this->requestChange($session['access_token'], $secondPhone)
            ->assertStatus(422)
            ->assertJson(['success' => false]);

        $this->assertDatabaseCount('otp_verifications', 1);
    }

    // 4. Resend after cooldown invalidates the previous OTP and issues exactly one PENDING row.
    public function test_resend_after_cooldown_invalidates_previous_otp(): void
    {
        $customer = $this->createCustomer();
        $session = $this->loginCustomer($customer);
        $newPhone = $this->newPhoneNumber();

        $requestResponse = $this->requestChange($session['access_token'], $newPhone)->assertStatus(200);
        $firstOtpUuid = $requestResponse->json('data.otp_verification_uuid');

        DB::table('otp_verifications')
            ->where('id', UuidBinary::toBinary($firstOtpUuid))
            ->update(['created_at' => now()->subMinutes(2), 'updated_at' => now()->subMinutes(2)]);

        $resendResponse = $this->resendChange($session['access_token'], $firstOtpUuid);
        $resendResponse->assertStatus(200)->assertJson([
            'success' => true,
            'data' => ['new_phone_number' => $newPhone],
        ]);

        $firstOtp = DB::table('otp_verifications')->where('id', UuidBinary::toBinary($firstOtpUuid))->first();
        $this->assertSame($this->otpStatusId('INVALIDATED'), (int) $firstOtp->status_id);

        $pendingCount = DB::table('otp_verifications')
            ->where('user_id', UuidBinary::toBinary($customer['user_uuid']))
            ->where('purpose_id', $this->purposeId('PHONE_NUMBER_CHANGE'))
            ->where('status_id', $this->otpStatusId('PENDING'))
            ->count();

        $this->assertSame(1, $pendingCount);
        $this->assertDatabaseCount('otp_verifications', 2);
    }

    public function test_resend_before_cooldown_is_rejected(): void
    {
        $customer = $this->createCustomer();
        $session = $this->loginCustomer($customer);
        $newPhone = $this->newPhoneNumber();

        $requestResponse = $this->requestChange($session['access_token'], $newPhone)->assertStatus(200);
        $otpUuid = $requestResponse->json('data.otp_verification_uuid');

        $this->resendChange($session['access_token'], $otpUuid)
            ->assertStatus(422)
            ->assertJson(['success' => false]);
    }

    // 5. Wrong OTP code increments failed_attempt_count, without changing the phone number.
    public function test_wrong_otp_code_is_rejected_and_phone_unchanged(): void
    {
        $customer = $this->createCustomer();
        $session = $this->loginCustomer($customer);
        $newPhone = $this->newPhoneNumber();

        $requestResponse = $this->requestChange($session['access_token'], $newPhone)->assertStatus(200);
        $otpUuid = $requestResponse->json('data.otp_verification_uuid');

        $this->verifyChange($session['access_token'], $otpUuid, '000000')
            ->assertStatus(422)
            ->assertJson(['success' => false]);

        $otp = DB::table('otp_verifications')->where('id', UuidBinary::toBinary($otpUuid))->first();
        $this->assertSame(1, (int) $otp->failed_attempt_count);

        $user = DB::table('users')->where('id', UuidBinary::toBinary($customer['user_uuid']))->first();
        $this->assertSame($customer['phone_number'], $user->phone_number);
    }

    // 6. Expired OTP is marked EXPIRED and rejected.
    public function test_expired_otp_is_rejected(): void
    {
        $customer = $this->createCustomer();
        $session = $this->loginCustomer($customer);
        $newPhone = $this->newPhoneNumber();

        $requestResponse = $this->requestChange($session['access_token'], $newPhone)->assertStatus(200);
        $otpUuid = $requestResponse->json('data.otp_verification_uuid');

        DB::table('otp_verifications')->where('id', UuidBinary::toBinary($otpUuid))->update([
            'created_at' => now()->subMinutes(10),
            'expires_at' => now()->subMinutes(5),
        ]);

        $this->verifyChange($session['access_token'], $otpUuid, '000000')
            ->assertStatus(422)
            ->assertJson(['success' => false]);

        $otp = DB::table('otp_verifications')->where('id', UuidBinary::toBinary($otpUuid))->first();
        $this->assertSame($this->otpStatusId('EXPIRED'), (int) $otp->status_id);
    }

    // 7. Max attempts exceeded.
    public function test_max_attempts_exceeded_blocks_further_verification(): void
    {
        $customer = $this->createCustomer();
        $session = $this->loginCustomer($customer);
        $newPhone = $this->newPhoneNumber();

        $requestResponse = $this->requestChange($session['access_token'], $newPhone)->assertStatus(200);
        $otpUuid = $requestResponse->json('data.otp_verification_uuid');

        for ($i = 0; $i < 5; $i++) {
            $this->verifyChange($session['access_token'], $otpUuid, '000000')->assertStatus(422);
        }

        $otp = DB::table('otp_verifications')->where('id', UuidBinary::toBinary($otpUuid))->first();
        $this->assertSame($this->otpStatusId('ATTEMPTS_EXCEEDED'), (int) $otp->status_id);

        $user = DB::table('users')->where('id', UuidBinary::toBinary($customer['user_uuid']))->first();
        $this->assertSame($customer['phone_number'], $user->phone_number);
    }

    // 8 & 9. Correct OTP updates the phone number, sets phone_verified_at, marks the OTP VERIFIED.
    public function test_correct_otp_updates_phone_number_and_marks_otp_verified(): void
    {
        $customer = $this->createCustomer();
        $session = $this->loginCustomer($customer);
        $newPhone = $this->newPhoneNumber();

        $requestResponse = $this->requestChange($session['access_token'], $newPhone)->assertStatus(200);
        $otpUuid = $requestResponse->json('data.otp_verification_uuid');
        $rawCode = $this->latestOtpRawCode($otpUuid);

        $response = $this->verifyChange($session['access_token'], $otpUuid, $rawCode);

        $response->assertStatus(200)->assertJson([
            'success' => true,
            'data' => ['phone_number' => $newPhone, 'phone_verified' => true],
        ]);

        $user = DB::table('users')->where('id', UuidBinary::toBinary($customer['user_uuid']))->first();
        $this->assertSame($newPhone, $user->phone_number);
        $this->assertNotNull($user->phone_verified_at);

        $otp = DB::table('otp_verifications')->where('id', UuidBinary::toBinary($otpUuid))->first();
        $this->assertSame($this->otpStatusId('VERIFIED'), (int) $otp->status_id);
        $this->assertNotNull($otp->verified_at);
    }

    // 10. OTP reuse is prevented.
    public function test_verified_otp_cannot_be_reused(): void
    {
        $customer = $this->createCustomer();
        $session = $this->loginCustomer($customer);
        $newPhone = $this->newPhoneNumber();

        $requestResponse = $this->requestChange($session['access_token'], $newPhone)->assertStatus(200);
        $otpUuid = $requestResponse->json('data.otp_verification_uuid');
        $rawCode = $this->latestOtpRawCode($otpUuid);

        $this->verifyChange($session['access_token'], $otpUuid, $rawCode)->assertStatus(200);

        $second = $this->verifyChange($session['access_token'], $otpUuid, $rawCode);
        $second->assertStatus(422)->assertJson(['success' => false]);
    }

    // An OTP issued for Customer A's own PHONE_NUMBER_CHANGE request must
    // never be usable by Customer B, even though the OTP UUID itself is a
    // freely-readable request parameter - VerifyPhoneNumberChangeOtpAction's
    // `$otp->user_id !== $user->id` check is the only thing standing between
    // an authenticated-but-unrelated customer and hijacking someone else's
    // pending phone change.
    public function test_otp_belonging_to_another_customer_cannot_be_used_to_verify_or_resend(): void
    {
        $owner = $this->createCustomer();
        $ownerSession = $this->loginCustomer($owner);
        $newPhone = $this->newPhoneNumber();

        $requestResponse = $this->requestChange($ownerSession['access_token'], $newPhone)->assertStatus(200);
        $otpUuid = $requestResponse->json('data.otp_verification_uuid');
        $rawCode = $this->latestOtpRawCode($otpUuid);

        $stranger = $this->createCustomer();
        $strangerSession = $this->loginCustomer($stranger);

        $this->verifyChange($strangerSession['access_token'], $otpUuid, $rawCode)
            ->assertStatus(422)
            ->assertJson(['success' => false]);

        $this->resendChange($strangerSession['access_token'], $otpUuid)
            ->assertStatus(422)
            ->assertJson(['success' => false]);

        // Neither the owner's phone nor the OTP's PENDING state was touched
        // by the stranger's attempts - the owner can still verify normally.
        $ownerRow = DB::table('users')->where('id', UuidBinary::toBinary($owner['user_uuid']))->first();
        $this->assertSame($owner['phone_number'], $ownerRow->phone_number);

        $this->verifyChange($ownerSession['access_token'], $otpUuid, $rawCode)->assertStatus(200);
    }

    // The defensive re-check in VerifyPhoneNumberChangeOtpAction (the target
    // number may have been claimed by another account after this OTP was
    // issued) is currently exercised by zero tests. Simulates the race by
    // directly claiming the target number for a second account between
    // request and verify - verification must fail safely, never overwrite
    // the second account's number or silently succeed with a now-duplicate
    // number.
    public function test_verification_is_rejected_when_the_target_number_was_claimed_by_another_account_meanwhile(): void
    {
        $customer = $this->createCustomer();
        $session = $this->loginCustomer($customer);
        $newPhone = $this->newPhoneNumber();

        $requestResponse = $this->requestChange($session['access_token'], $newPhone)->assertStatus(200);
        $otpUuid = $requestResponse->json('data.otp_verification_uuid');
        $rawCode = $this->latestOtpRawCode($otpUuid);

        // Another account claims the target number after the OTP was issued
        // but before verification - the OTP row itself is left untouched.
        $interloper = $this->createCustomer();
        DB::table('users')->where('id', UuidBinary::toBinary($interloper['user_uuid']))->update(['phone_number' => $newPhone]);

        $response = $this->verifyChange($session['access_token'], $otpUuid, $rawCode);

        $response->assertStatus(422)->assertJson(['success' => false]);

        $customerRow = DB::table('users')->where('id', UuidBinary::toBinary($customer['user_uuid']))->first();
        $this->assertSame($customer['phone_number'], $customerRow->phone_number);

        $interloperRow = DB::table('users')->where('id', UuidBinary::toBinary($interloper['user_uuid']))->first();
        $this->assertSame($newPhone, $interloperRow->phone_number);

        // The OTP stays PENDING (not silently marked VERIFIED), matching
        // the Action's documented "left PENDING so the customer can request
        // a fresh OTP for a different number" behavior.
        $otp = DB::table('otp_verifications')->where('id', UuidBinary::toBinary($otpUuid))->first();
        $this->assertSame($this->otpStatusId('PENDING'), (int) $otp->status_id);
    }

    // 11. Required session behavior after change: current session stays valid, others are revoked.
    public function test_current_session_stays_valid_and_other_sessions_are_revoked_after_change(): void
    {
        $customer = $this->createCustomer();
        $currentSession = $this->loginCustomer($customer);
        $otherSession = $this->loginCustomer($customer);
        $newPhone = $this->newPhoneNumber();

        $requestResponse = $this->requestChange($currentSession['access_token'], $newPhone)->assertStatus(200);
        $otpUuid = $requestResponse->json('data.otp_verification_uuid');
        $rawCode = $this->latestOtpRawCode($otpUuid);

        $this->verifyChange($currentSession['access_token'], $otpUuid, $rawCode)->assertStatus(200);

        $currentRow = DB::table('auth_sessions')->where('id', UuidBinary::toBinary($currentSession['session_uuid']))->first();
        $otherRow = DB::table('auth_sessions')->where('id', UuidBinary::toBinary($otherSession['session_uuid']))->first();

        $this->assertNull($currentRow->revoked_at);
        $this->assertNotNull($otherRow->revoked_at);
    }

    // Missing/invalid access token is rejected by the shared customer-auth middleware, for every phone-change endpoint.
    public function test_missing_or_invalid_token_is_rejected_on_every_endpoint(): void
    {
        $this->requestChange(null, $this->newPhoneNumber())->assertStatus(401)->assertExactJson([
            'success' => false,
            'message' => self::GENERIC_SESSION_MESSAGE,
        ]);

        $this->postJson('/api/v1/auth/verify-phone-number-change-otp', [
            'otp_verification_uuid' => UuidBinary::generate(),
            'otp_code' => '123456',
        ])->assertStatus(401)->assertExactJson([
            'success' => false,
            'message' => self::GENERIC_SESSION_MESSAGE,
        ]);

        $this->postJson('/api/v1/auth/resend-phone-number-change-otp', [
            'otp_verification_uuid' => UuidBinary::generate(),
        ])->assertStatus(401)->assertExactJson([
            'success' => false,
            'message' => self::GENERIC_SESSION_MESSAGE,
        ]);
    }

    // Response never leaks the OTP code/hash.
    public function test_response_never_leaks_secrets(): void
    {
        $customer = $this->createCustomer();
        $session = $this->loginCustomer($customer);
        $newPhone = $this->newPhoneNumber();

        $requestResponse = $this->requestChange($session['access_token'], $newPhone);
        $requestResponse->assertStatus(200);

        $json = strtolower(json_encode($requestResponse->json()));
        $this->assertStringNotContainsString('code_hash', $json);
    }

    // Lock ordering: the users row is always the first FOR UPDATE lock, before any otp_verifications row.
    public function test_user_row_is_locked_for_update_before_otp_row_on_request(): void
    {
        $customer = $this->createCustomer();
        $session = $this->loginCustomer($customer);

        DB::enableQueryLog();

        $this->requestChange($session['access_token'], $this->newPhoneNumber())->assertStatus(200);

        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $lockingQueries = collect($queries)
            ->filter(fn (array $entry) => str_contains(strtolower($entry['query']), 'for update'))
            ->values();

        $this->assertGreaterThanOrEqual(1, $lockingQueries->count());
        $this->assertStringContainsString('from `users`', strtolower($lockingQueries->first()['query']));
    }

    /**
     * Raw OTP codes are never persisted, only their bcrypt hash. To assert
     * against the *correct* code in verification tests without weakening
     * the action itself, this overwrites the stored hash with a
     * known/expected raw code the same way ForgotPasswordAction's real code
     * would have been hashed.
     */
    private function latestOtpRawCode(string $otpUuid): string
    {
        $rawCode = '135790';

        DB::table('otp_verifications')
            ->where('id', UuidBinary::toBinary($otpUuid))
            ->update(['code_hash' => Hash::make($rawCode)]);

        return $rawCode;
    }
}
