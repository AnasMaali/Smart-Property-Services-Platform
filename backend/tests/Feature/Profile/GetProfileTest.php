<?php

namespace Tests\Feature\Profile;

use App\Support\Uuid\UuidBinary;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class GetProfileTest extends TestCase
{
    use DatabaseTransactions;

    private const GENERIC_SESSION_MESSAGE = 'This session is invalid or has expired.';

    private static int $sequence = 0;

    private int $dubaiCityId;

    private int $dubaiAreaId;

    private int $propertyOwnerTypeId;

    private array $serviceCategoryIds;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dubaiCityId = (int) DB::table('cities')->where('code', 'DUBAI')->value('id');
        $this->dubaiAreaId = (int) DB::table('areas')
            ->where('city_id', $this->dubaiCityId)->where('code', 'DUBAI_MARINA')->value('id');
        $this->propertyOwnerTypeId = (int) DB::table('property_relationship_types')->where('code', 'PROPERTY_OWNER')->value('id');
        $this->serviceCategoryIds = DB::table('service_categories')->whereIn('code', ['AC', 'CLEANING'])->pluck('id')->all();
    }

    private function roleId(string $code): int
    {
        return (int) DB::table('roles')->where('code', $code)->value('id');
    }

    private function accountStatusId(string $code): int
    {
        return (int) DB::table('user_account_statuses')->where('code', $code)->value('id');
    }

    /**
     * @return array{user_uuid: string, phone_number: string, email: string, password: string}
     */
    private function createCustomer(array $overrides = []): array
    {
        self::$sequence++;

        $userUuid = UuidBinary::generate();
        $phoneNumber = '+97156000'.str_pad((string) self::$sequence, 4, '0', STR_PAD_LEFT);
        $email = $overrides['email'] ?? 'get.profile.'.self::$sequence.'@example.com';
        $password = 'OldPassw0rd';
        $now = now();

        DB::table('users')->insert([
            'id' => UuidBinary::toBinary($userUuid),
            'phone_number' => $phoneNumber,
            'email' => $email,
            'password_hash' => Hash::make($password),
            'account_status_id' => $this->accountStatusId('ACTIVE'),
            'phone_verified_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('user_profiles')->insert([
            'user_id' => UuidBinary::toBinary($userUuid),
            'full_name' => $overrides['full_name'] ?? 'Profile Test Customer',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('customer_profiles')->insert([
            'user_id' => UuidBinary::toBinary($userUuid),
            'area_id' => $overrides['area_id'] ?? $this->dubaiAreaId,
            'property_relationship_type_id' => $overrides['property_relationship_type_id'] ?? $this->propertyOwnerTypeId,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('customer_service_interests')->insert(
            collect($overrides['service_interests'] ?? $this->serviceCategoryIds)
                ->map(fn (int $id) => [
                    'customer_user_id' => UuidBinary::toBinary($userUuid),
                    'service_category_id' => $id,
                    'created_at' => $now,
                ])
                ->all()
        );

        DB::table('user_roles')->insert([
            'user_id' => UuidBinary::toBinary($userUuid),
            'role_id' => $this->roleId('CUSTOMER'),
            'assigned_by_user_id' => null,
            'assigned_at' => $now,
        ]);

        return ['user_uuid' => $userUuid, 'phone_number' => $phoneNumber, 'email' => $email, 'password' => $password];
    }

    private function loginCustomer(array $customer): array
    {
        $response = $this->postJson('/api/v1/auth/login', [
            'phone_number' => $customer['phone_number'],
            'password' => $customer['password'],
            'client_type' => 'MOBILE_IOS',
        ])->assertStatus(200);

        return ['access_token' => $response->json('data.access_token')];
    }

    public function test_unauthenticated_request_is_rejected(): void
    {
        $this->getJson('/api/v1/profile')
            ->assertStatus(401)
            ->assertExactJson(['success' => false, 'message' => self::GENERIC_SESSION_MESSAGE]);
    }

    public function test_authenticated_profile_shape_is_correct(): void
    {
        $customer = $this->createCustomer(['full_name' => 'Sara Al Mansoori']);
        $session = $this->loginCustomer($customer);

        $response = $this->getJson('/api/v1/profile', ['Authorization' => 'Bearer '.$session['access_token']]);

        $response->assertStatus(200)->assertJsonStructure([
            'success',
            'message',
            'data' => [
                'user_uuid', 'full_name', 'email', 'phone_number', 'phone_verified', 'account_status',
                'location' => ['city' => ['id', 'code', 'name'], 'area' => ['id', 'code', 'name']],
                'property_relationship' => ['id', 'code', 'name'],
                'service_interests',
            ],
        ])->assertJson([
            'success' => true,
            'data' => [
                'full_name' => 'Sara Al Mansoori',
                'email' => $customer['email'],
                'phone_number' => $customer['phone_number'],
                'phone_verified' => true,
                'account_status' => 'ACTIVE',
            ],
        ]);

        $json = strtolower(json_encode($response->json()));
        $this->assertStringNotContainsString('password', $json);
        $this->assertStringNotContainsString('refresh_token_hash', $json);
    }

    public function test_phone_number_is_returned_as_read_only_field(): void
    {
        $customer = $this->createCustomer();
        $session = $this->loginCustomer($customer);

        $this->getJson('/api/v1/profile', ['Authorization' => 'Bearer '.$session['access_token']])
            ->assertStatus(200)
            ->assertJsonPath('data.phone_number', $customer['phone_number']);
    }

    public function test_city_and_area_are_correct(): void
    {
        $customer = $this->createCustomer();
        $session = $this->loginCustomer($customer);

        $this->getJson('/api/v1/profile', ['Authorization' => 'Bearer '.$session['access_token']])
            ->assertJson([
                'data' => [
                    'location' => [
                        'city' => ['id' => $this->dubaiCityId, 'code' => 'DUBAI'],
                        'area' => ['id' => $this->dubaiAreaId, 'code' => 'DUBAI_MARINA'],
                    ],
                ],
            ]);
    }

    public function test_property_relationship_is_correct(): void
    {
        $customer = $this->createCustomer();
        $session = $this->loginCustomer($customer);

        $this->getJson('/api/v1/profile', ['Authorization' => 'Bearer '.$session['access_token']])
            ->assertJson([
                'data' => [
                    'property_relationship' => ['id' => $this->propertyOwnerTypeId, 'code' => 'PROPERTY_OWNER'],
                ],
            ]);
    }

    public function test_service_interests_are_correct(): void
    {
        $customer = $this->createCustomer();
        $session = $this->loginCustomer($customer);

        $response = $this->getJson('/api/v1/profile', ['Authorization' => 'Bearer '.$session['access_token']]);

        $returnedIds = collect($response->json('data.service_interests'))->pluck('id')->sort()->values()->all();
        $expectedIds = collect($this->serviceCategoryIds)->sort()->values()->all();

        $this->assertSame($expectedIds, $returnedIds);
    }

    public function test_previously_selected_inactive_reference_rows_are_still_displayed(): void
    {
        $now = now();

        $inactiveAreaId = DB::table('areas')->insertGetId([
            'city_id' => $this->dubaiCityId,
            'code' => 'INACTIVE_TEST_AREA',
            'name' => 'Inactive Test Area',
            'display_order' => 199,
            'is_active' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $inactiveRelationshipTypeId = DB::table('property_relationship_types')->insertGetId([
            'code' => 'INACTIVE_TEST_RELATIONSHIP',
            'name' => 'Inactive Test Relationship',
            'display_order' => 199,
            'is_active' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $inactiveServiceCategoryId = DB::table('service_categories')->insertGetId([
            'code' => 'INACTIVE_TEST_CATEGORY',
            'name' => 'Inactive Test Category',
            'display_order' => 199,
            'is_active' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $customer = $this->createCustomer([
            'area_id' => $inactiveAreaId,
            'property_relationship_type_id' => $inactiveRelationshipTypeId,
            'service_interests' => [$inactiveServiceCategoryId],
        ]);
        $session = $this->loginCustomer($customer);

        $response = $this->getJson('/api/v1/profile', ['Authorization' => 'Bearer '.$session['access_token']]);

        $response->assertStatus(200);
        $response->assertJsonPath('data.location.area.id', $inactiveAreaId);
        $response->assertJsonPath('data.property_relationship.id', $inactiveRelationshipTypeId);
        $this->assertContains(
            $inactiveServiceCategoryId,
            collect($response->json('data.service_interests'))->pluck('id')->all()
        );
    }
}
