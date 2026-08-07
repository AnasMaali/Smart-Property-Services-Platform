<?php

namespace Tests\Feature\Auth;

use App\Actions\Auth\RegisterCustomerAction;
use App\Support\Uuid\UuidBinary;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class RegisterCustomerTest extends TestCase
{
    use DatabaseTransactions;

    private int $dubaiCityId;

    private int $abuDhabiCityId;

    private int $dubaiAreaId;

    private int $abuDhabiAreaId;

    private int $propertyOwnerTypeId;

    private array $serviceCategoryIds;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dubaiCityId = (int) DB::table('cities')->where('code', 'DUBAI')->value('id');
        $this->abuDhabiCityId = (int) DB::table('cities')->where('code', 'ABU_DHABI')->value('id');
        $this->dubaiAreaId = (int) DB::table('areas')->where('city_id', $this->dubaiCityId)->value('id');
        $this->abuDhabiAreaId = (int) DB::table('areas')->where('city_id', $this->abuDhabiCityId)->value('id');
        $this->propertyOwnerTypeId = (int) DB::table('property_relationship_types')->where('code', 'PROPERTY_OWNER')->value('id');
        $this->serviceCategoryIds = DB::table('service_categories')->whereIn('code', ['AC', 'CLEANING'])->pluck('id')->all();
    }

    private function validPayload(array $overrides = []): array
    {
        static $sequence = 0;
        $sequence++;

        return array_merge([
            'full_name' => 'Sara Al Mansoori',
            'phone_number' => '+9715012345'.str_pad((string) $sequence, 2, '0', STR_PAD_LEFT),
            'email' => "sara.mansoori{$sequence}@example.com",
            'password' => 'Passw0rd123',
            'city_id' => $this->dubaiCityId,
            'area_id' => $this->dubaiAreaId,
            'property_relationship_type_id' => $this->propertyOwnerTypeId,
            'service_interests' => $this->serviceCategoryIds,
        ], $overrides);
    }

    public function test_successful_registration_returns_201_with_expected_payload(): void
    {
        $payload = $this->validPayload();

        $response = $this->postJson('/api/v1/auth/register', $payload);

        $response->assertStatus(201)
            ->assertJson([
                'success' => true,
                'data' => [
                    'full_name' => $payload['full_name'],
                    'phone_number' => $payload['phone_number'],
                    'email' => $payload['email'],
                    'account_status' => 'PENDING_VERIFICATION',
                    'phone_verified' => false,
                ],
            ])
            ->assertJsonStructure([
                'success',
                'message',
                'data' => [
                    'user_uuid',
                    'full_name',
                    'phone_number',
                    'email',
                    'account_status',
                    'phone_verified',
                    'otp_verification_uuid',
                    'otp_expires_at',
                ],
            ]);

        $userUuid = $response->json('data.user_uuid');
        $otpUuid = $response->json('data.otp_verification_uuid');

        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/',
            $userUuid
        );
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/',
            $otpUuid
        );

        $this->assertDatabaseHas('users', [
            'id' => UuidBinary::toBinary($userUuid),
            'phone_number' => $payload['phone_number'],
            'email' => $payload['email'],
        ]);
    }

    public function test_response_never_leaks_password_otp_or_secrets(): void
    {
        $response = $this->postJson('/api/v1/auth/register', $this->validPayload());

        $response->assertStatus(201);

        $json = json_encode($response->json());

        $this->assertStringNotContainsString('password', strtolower($json));
        $this->assertStringNotContainsString('code_hash', strtolower($json));
        $this->assertStringNotContainsString('"code"', strtolower($json));
    }

    public function test_email_is_required(): void
    {
        $payload = $this->validPayload(['email' => '']);

        $this->postJson('/api/v1/auth/register', $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['email']);
    }

    public function test_email_must_be_unique(): void
    {
        $payload = $this->validPayload();
        $this->postJson('/api/v1/auth/register', $payload)->assertStatus(201);

        $duplicate = $this->validPayload([
            'email' => $payload['email'],
            'phone_number' => '+971509999999',
        ]);

        $this->postJson('/api/v1/auth/register', $duplicate)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['email']);
    }

    public function test_phone_number_must_be_unique(): void
    {
        $payload = $this->validPayload();
        $this->postJson('/api/v1/auth/register', $payload)->assertStatus(201);

        $duplicate = $this->validPayload([
            'phone_number' => $payload['phone_number'],
            'email' => 'someoneelse@example.com',
        ]);

        $this->postJson('/api/v1/auth/register', $duplicate)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['phone_number']);
    }

    public function test_invalid_city_is_rejected(): void
    {
        $payload = $this->validPayload(['city_id' => 999999]);

        $this->postJson('/api/v1/auth/register', $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['city_id']);
    }

    public function test_inactive_city_is_rejected(): void
    {
        $inactiveCityId = DB::table('cities')->insertGetId([
            'country_id' => DB::table('countries')->value('id'),
            'code' => 'INACTIVE_CITY',
            'name' => 'Inactive City',
            'is_active' => 0,
        ]);

        $payload = $this->validPayload(['city_id' => $inactiveCityId]);

        $this->postJson('/api/v1/auth/register', $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['city_id']);
    }

    public function test_invalid_area_is_rejected(): void
    {
        $payload = $this->validPayload(['area_id' => 999999]);

        $this->postJson('/api/v1/auth/register', $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['area_id']);
    }

    public function test_area_not_matching_city_is_rejected(): void
    {
        $payload = $this->validPayload([
            'city_id' => $this->dubaiCityId,
            'area_id' => $this->abuDhabiAreaId,
        ]);

        $this->postJson('/api/v1/auth/register', $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['area_id']);
    }

    public function test_invalid_property_relationship_type_is_rejected(): void
    {
        $payload = $this->validPayload(['property_relationship_type_id' => 999999]);

        $this->postJson('/api/v1/auth/register', $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['property_relationship_type_id']);
    }

    public function test_service_interests_are_required(): void
    {
        $payload = $this->validPayload(['service_interests' => []]);

        $this->postJson('/api/v1/auth/register', $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['service_interests']);
    }

    public function test_duplicate_service_interests_are_rejected(): void
    {
        $categoryId = $this->serviceCategoryIds[0];
        $payload = $this->validPayload(['service_interests' => [$categoryId, $categoryId]]);

        $this->postJson('/api/v1/auth/register', $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['service_interests.0', 'service_interests.1']);
    }

    public function test_invalid_service_interest_is_rejected(): void
    {
        $payload = $this->validPayload(['service_interests' => [999999]]);

        $this->postJson('/api/v1/auth/register', $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['service_interests.0']);
    }

    public function test_inactive_service_interest_is_rejected(): void
    {
        $inactiveCategoryId = DB::table('service_categories')->insertGetId([
            'code' => 'INACTIVE_CATEGORY',
            'name' => 'Inactive Category',
            'is_active' => 0,
        ]);

        $payload = $this->validPayload(['service_interests' => [$inactiveCategoryId]]);

        $this->postJson('/api/v1/auth/register', $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['service_interests.0']);
    }

    public function test_password_must_be_at_least_8_characters(): void
    {
        $payload = $this->validPayload(['password' => 'Ab1234']);

        $this->postJson('/api/v1/auth/register', $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['password']);
    }

    public function test_password_must_contain_a_letter(): void
    {
        $payload = $this->validPayload(['password' => '12345678']);

        $this->postJson('/api/v1/auth/register', $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['password']);
    }

    public function test_password_must_contain_a_number(): void
    {
        $payload = $this->validPayload(['password' => 'Password']);

        $this->postJson('/api/v1/auth/register', $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['password']);
    }

    public function test_password_is_stored_only_as_a_secure_hash(): void
    {
        $payload = $this->validPayload();

        $this->postJson('/api/v1/auth/register', $payload)->assertStatus(201);

        $storedHash = DB::table('users')->where('email', $payload['email'])->value('password_hash');

        $this->assertNotSame($payload['password'], $storedHash);
        $this->assertTrue(Hash::check($payload['password'], $storedHash));
    }

    public function test_new_account_has_pending_verification_status_and_customer_role(): void
    {
        $payload = $this->validPayload();
        $this->postJson('/api/v1/auth/register', $payload)->assertStatus(201);

        $user = DB::table('users')->where('email', $payload['email'])->first();

        $statusCode = DB::table('user_account_statuses')->where('id', $user->account_status_id)->value('code');
        $this->assertSame('PENDING_VERIFICATION', $statusCode);

        $roleId = DB::table('roles')->where('code', 'CUSTOMER')->value('id');
        $this->assertDatabaseHas('user_roles', [
            'user_id' => $user->id,
            'role_id' => $roleId,
        ]);
    }

    public function test_phone_verified_at_is_null_after_registration(): void
    {
        $payload = $this->validPayload();
        $this->postJson('/api/v1/auth/register', $payload)->assertStatus(201);

        $user = DB::table('users')->where('email', $payload['email'])->first();

        $this->assertNull($user->phone_verified_at);
    }

    public function test_otp_verification_record_matches_the_expected_policy(): void
    {
        $payload = $this->validPayload();
        $this->postJson('/api/v1/auth/register', $payload)->assertStatus(201);

        $user = DB::table('users')->where('email', $payload['email'])->first();
        $otp = DB::table('otp_verifications')->where('user_id', $user->id)->first();

        $this->assertNotNull($otp);

        $purposeCode = DB::table('otp_verification_purposes')->where('id', $otp->purpose_id)->value('code');
        $statusCode = DB::table('otp_verification_statuses')->where('id', $otp->status_id)->value('code');

        $this->assertSame('PHONE_VERIFICATION', $purposeCode);
        $this->assertSame('PENDING', $statusCode);
        $this->assertSame(5, (int) $otp->max_attempts);
        $this->assertSame(0, (int) $otp->failed_attempt_count);
        $this->assertSame($payload['phone_number'], $otp->target_phone_number);
        $this->assertNotEmpty($otp->code_hash);
        $this->assertSame(16, strlen($otp->id));

        $expiresAt = Carbon::parse($otp->expires_at);
        $createdAt = Carbon::parse($otp->created_at);
        $this->assertEqualsWithDelta(300, $createdAt->diffInSeconds($expiresAt), 5);
    }

    public function test_transaction_rolls_back_completely_when_a_later_insert_fails(): void
    {
        $data = [
            'full_name' => 'Rollback Test',
            'phone_number' => '+971500000999',
            'email' => 'rollback.test@example.com',
            'password' => 'Passw0rd123',
            'city_id' => $this->dubaiCityId,
            'area_id' => $this->dubaiAreaId,
            'property_relationship_type_id' => 999999, // does not exist -> FK violation
            'service_interests' => $this->serviceCategoryIds,
        ];

        try {
            app(RegisterCustomerAction::class)->handle($data);
            $this->fail('Expected a database exception due to invalid foreign key.');
        } catch (\Throwable $exception) {
            // expected
        }

        $this->assertDatabaseMissing('users', ['phone_number' => $data['phone_number']]);
        $this->assertDatabaseMissing('users', ['email' => $data['email']]);
    }
}
