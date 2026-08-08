<?php

namespace Tests\Feature\Profile;

use App\Actions\Profile\UpdateProfileAction;
use App\Support\Uuid\UuidBinary;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class UpdateProfileTest extends TestCase
{
    use DatabaseTransactions;

    private const GENERIC_SESSION_MESSAGE = 'This session is invalid or has expired.';

    private static int $sequence = 0;

    private int $dubaiCityId;

    private int $dubaiAreaId;

    private int $propertyOwnerTypeId;

    private array $serviceCategoryIds;

    private int $extraServiceCategoryId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dubaiCityId = (int) DB::table('cities')->where('code', 'DUBAI')->value('id');
        $this->dubaiAreaId = (int) DB::table('areas')
            ->where('city_id', $this->dubaiCityId)->where('code', 'DUBAI_MARINA')->value('id');
        $this->propertyOwnerTypeId = (int) DB::table('property_relationship_types')->where('code', 'PROPERTY_OWNER')->value('id');
        $this->serviceCategoryIds = DB::table('service_categories')->whereIn('code', ['AC', 'CLEANING'])->pluck('id')->all();
        $this->extraServiceCategoryId = (int) DB::table('service_categories')->where('code', 'PLUMBING')->value('id');
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
        $phoneNumber = '+97155000'.str_pad((string) self::$sequence, 4, '0', STR_PAD_LEFT);
        $email = $overrides['email'] ?? 'update.profile.'.self::$sequence.'@example.com';
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
            'full_name' => $overrides['full_name'] ?? 'Update Profile Customer',
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

    private function patchProfile(?string $accessToken, array $payload)
    {
        if ($accessToken === null) {
            return $this->patchJson('/api/v1/profile', $payload);
        }

        return $this->patchJson('/api/v1/profile', $payload, ['Authorization' => 'Bearer '.$accessToken]);
    }

    public function test_partial_single_field_update_works(): void
    {
        $customer = $this->createCustomer();
        $session = $this->loginCustomer($customer);

        $response = $this->patchProfile($session['access_token'], ['full_name' => 'Updated Name']);

        $response->assertStatus(200)->assertJsonPath('data.full_name', 'Updated Name');
        $this->assertSame($customer['email'], $response->json('data.email'));

        $this->assertDatabaseHas('user_profiles', [
            'user_id' => UuidBinary::toBinary($customer['user_uuid']),
            'full_name' => 'Updated Name',
        ]);
    }

    public function test_full_name_validation_rejects_too_short_value(): void
    {
        $customer = $this->createCustomer();
        $session = $this->loginCustomer($customer);

        $this->patchProfile($session['access_token'], ['full_name' => 'A'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['full_name']);
    }

    public function test_email_is_normalized_before_persisting(): void
    {
        $customer = $this->createCustomer();
        $session = $this->loginCustomer($customer);

        $response = $this->patchProfile($session['access_token'], ['email' => '  NEW.Email@Example.COM ']);

        $response->assertStatus(200)->assertJsonPath('data.email', 'new.email@example.com');
        $this->assertDatabaseHas('users', [
            'id' => UuidBinary::toBinary($customer['user_uuid']),
            'email' => 'new.email@example.com',
        ]);
    }

    public function test_duplicate_email_is_rejected(): void
    {
        $this->createCustomer(['email' => 'taken@example.com']);
        $customer = $this->createCustomer();
        $session = $this->loginCustomer($customer);

        $this->patchProfile($session['access_token'], ['email' => 'taken@example.com'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['email']);
    }

    public function test_own_unchanged_email_is_accepted(): void
    {
        $customer = $this->createCustomer();
        $session = $this->loginCustomer($customer);

        $this->patchProfile($session['access_token'], ['email' => $customer['email']])
            ->assertStatus(200)
            ->assertJsonPath('data.email', $customer['email']);
    }

    public function test_inactive_or_nonexistent_area_is_rejected(): void
    {
        $customer = $this->createCustomer();
        $session = $this->loginCustomer($customer);

        $this->patchProfile($session['access_token'], ['area_id' => 999999999])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['area_id']);

        $inactiveAreaId = DB::table('areas')->insertGetId([
            'city_id' => $this->dubaiCityId,
            'code' => 'INACTIVE_UPDATE_AREA',
            'name' => 'Inactive Update Area',
            'display_order' => 199,
            'is_active' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->patchProfile($session['access_token'], ['area_id' => $inactiveAreaId])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['area_id']);
    }

    public function test_inactive_or_nonexistent_property_relationship_type_is_rejected(): void
    {
        $customer = $this->createCustomer();
        $session = $this->loginCustomer($customer);

        $this->patchProfile($session['access_token'], ['property_relationship_type_id' => 999999999])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['property_relationship_type_id']);

        $inactiveTypeId = DB::table('property_relationship_types')->insertGetId([
            'code' => 'INACTIVE_UPDATE_TYPE',
            'name' => 'Inactive Update Type',
            'display_order' => 199,
            'is_active' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->patchProfile($session['access_token'], ['property_relationship_type_id' => $inactiveTypeId])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['property_relationship_type_id']);
    }

    public function test_empty_service_interests_array_is_rejected(): void
    {
        $customer = $this->createCustomer();
        $session = $this->loginCustomer($customer);

        $this->patchProfile($session['access_token'], ['service_interests' => []])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['service_interests']);
    }

    public function test_duplicate_service_interests_are_rejected(): void
    {
        $customer = $this->createCustomer();
        $session = $this->loginCustomer($customer);

        $this->patchProfile($session['access_token'], [
            'service_interests' => [$this->extraServiceCategoryId, $this->extraServiceCategoryId],
        ])->assertStatus(422)->assertJsonValidationErrors(['service_interests.0']);
    }

    public function test_inactive_or_nonexistent_service_interest_is_rejected(): void
    {
        $customer = $this->createCustomer();
        $session = $this->loginCustomer($customer);

        $this->patchProfile($session['access_token'], ['service_interests' => [999999999]])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['service_interests.0']);

        $inactiveCategoryId = DB::table('service_categories')->insertGetId([
            'code' => 'INACTIVE_UPDATE_CATEGORY',
            'name' => 'Inactive Update Category',
            'display_order' => 199,
            'is_active' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->patchProfile($session['access_token'], ['service_interests' => [$inactiveCategoryId]])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['service_interests.0']);
    }

    public function test_service_interests_replace_the_full_previous_set(): void
    {
        $customer = $this->createCustomer(['service_interests' => $this->serviceCategoryIds]);
        $session = $this->loginCustomer($customer);

        $response = $this->patchProfile($session['access_token'], [
            'service_interests' => [$this->extraServiceCategoryId],
        ]);

        $response->assertStatus(200);

        $storedIds = DB::table('customer_service_interests')
            ->where('customer_user_id', UuidBinary::toBinary($customer['user_uuid']))
            ->pluck('service_category_id')
            ->all();

        $this->assertSame([$this->extraServiceCategoryId], $storedIds);
    }

    public function test_phone_number_cannot_be_modified_through_profile(): void
    {
        $customer = $this->createCustomer();
        $session = $this->loginCustomer($customer);

        $this->patchProfile($session['access_token'], [
            'full_name' => 'Name Changed',
            'phone_number' => '+971500000000',
        ])->assertStatus(200);

        $this->assertDatabaseHas('users', [
            'id' => UuidBinary::toBinary($customer['user_uuid']),
            'phone_number' => $customer['phone_number'],
        ]);
    }

    public function test_password_cannot_be_modified_through_profile(): void
    {
        $customer = $this->createCustomer();
        $session = $this->loginCustomer($customer);

        $hashBefore = DB::table('users')->where('id', UuidBinary::toBinary($customer['user_uuid']))->value('password_hash');

        $this->patchProfile($session['access_token'], [
            'full_name' => 'Name Changed Again',
            'password' => 'BrandNewPassw0rd',
        ])->assertStatus(200);

        $hashAfter = DB::table('users')->where('id', UuidBinary::toBinary($customer['user_uuid']))->value('password_hash');
        $this->assertSame($hashBefore, $hashAfter);
    }

    public function test_account_status_and_role_fields_cannot_be_modified_through_profile(): void
    {
        $customer = $this->createCustomer();
        $session = $this->loginCustomer($customer);

        $this->patchProfile($session['access_token'], [
            'full_name' => 'Still Active',
            'account_status_id' => $this->accountStatusId('SUSPENDED'),
            'phone_verified_at' => null,
            'role' => 'ADMIN',
        ])->assertStatus(200);

        $this->assertDatabaseHas('users', [
            'id' => UuidBinary::toBinary($customer['user_uuid']),
            'account_status_id' => $this->accountStatusId('ACTIVE'),
        ]);

        $this->assertDatabaseHas('user_roles', [
            'user_id' => UuidBinary::toBinary($customer['user_uuid']),
            'role_id' => $this->roleId('CUSTOMER'),
        ]);
    }

    public function test_failed_update_rolls_back_every_write(): void
    {
        $customer = $this->createCustomer();

        $before = $this->snapshotProfile($customer['user_uuid']);

        $threwException = false;

        try {
            // Calls the Action directly (bypassing Form Request validation) so an
            // invalid service_category_id reaches the DB layer and forces a
            // mid-transaction failure, proving every prior write in the same
            // transaction (full_name, email) is rolled back too.
            app(UpdateProfileAction::class)->handle($customer['user_uuid'], [
                'full_name' => 'Should Not Persist',
                'email' => 'should-not-persist@example.com',
                'service_interests' => [999999999],
            ]);
        } catch (QueryException) {
            $threwException = true;
        }

        $this->assertTrue($threwException);
        $this->assertSame($before, $this->snapshotProfile($customer['user_uuid']));
    }

    public function test_missing_or_invalid_token_is_rejected(): void
    {
        $this->patchProfile(null, ['full_name' => 'Whoever'])
            ->assertStatus(401)
            ->assertExactJson(['success' => false, 'message' => self::GENERIC_SESSION_MESSAGE]);
    }

    private function snapshotProfile(string $userUuid): array
    {
        $userId = UuidBinary::toBinary($userUuid);

        return [
            'full_name' => DB::table('user_profiles')->where('user_id', $userId)->value('full_name'),
            'email' => DB::table('users')->where('id', $userId)->value('email'),
            'service_interests' => DB::table('customer_service_interests')
                ->where('customer_user_id', $userId)
                ->pluck('service_category_id')
                ->sort()
                ->values()
                ->all(),
        ];
    }
}
