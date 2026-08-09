<?php

namespace Tests\Feature\Checkout;

use App\Support\Uuid\UuidBinary;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Checkout\Concerns\CreatesCheckoutFixtures;
use Tests\TestCase;

/**
 * Proves the same "no partial writes" invariant already established for
 * Cart (see CartTransactionIntegrityTest) holds for Checkout's tables: a
 * genuine mid-transaction failure rolls back every prior write in the same
 * transaction, never a half-persisted row.
 */
class CheckoutTransactionIntegrityTest extends TestCase
{
    use CreatesCheckoutFixtures;
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCheckoutFixtures();
    }

    public function test_rollback_leaves_no_partial_cart_location_write(): void
    {
        $customer = $this->createAuthenticatedCartCustomer();
        $service = $this->createPricedCartService();
        $this->addCartItem($customer['access_token'], ['service_uuid' => $service['uuid']])->assertStatus(201);

        $cartId = DB::table('carts')->where('customer_user_id', UuidBinary::toBinary($customer['user_uuid']))->value('id');
        [$areaId] = $this->twoDistinctAreaIds();
        $propertyTypeId = (int) DB::table('property_types')->where('code', 'APARTMENT')->value('id');
        $now = now();

        $row = [
            'cart_id' => $cartId,
            'property_type_id' => $propertyTypeId,
            'area_id' => $areaId,
            'street_name' => 'Rollback QA Street',
            'address_line' => 'Rollback QA fixture address line',
            'building_name_or_number' => 'Tower 1',
            'visit_contact_phone' => '+971500000000',
            'created_at' => $now,
            'updated_at' => $now,
        ];

        $threw = false;

        try {
            DB::transaction(function () use ($row) {
                DB::table('cart_locations')->insert($row);
                // Second insert with the same cart_id primary key forces a genuine failure.
                DB::table('cart_locations')->insert($row);
            });
        } catch (QueryException) {
            $threw = true;
        }

        $this->assertTrue($threw, 'Expected a duplicate-key QueryException.');
        $this->assertDatabaseCount('cart_locations', 0);
    }

    public function test_rollback_leaves_no_partial_appointment_hold_write(): void
    {
        $customer = $this->createAuthenticatedCartCustomer();
        $service = $this->createPricedCartService();
        $this->addCartItem($customer['access_token'], ['service_uuid' => $service['uuid']])->assertStatus(201);

        $cartId = DB::table('carts')->where('customer_user_id', UuidBinary::toBinary($customer['user_uuid']))->value('id');
        $slot = $this->createAppointmentSlot();
        $holdId = UuidBinary::toBinary(UuidBinary::generate());
        $now = now();

        $row = [
            'id' => $holdId,
            'cart_id' => $cartId,
            'appointment_slot_id' => UuidBinary::toBinary($slot['uuid']),
            'held_at' => $now,
            'expires_at' => $now->copy()->addMinutes(10),
            'created_at' => $now,
            'updated_at' => $now,
        ];

        $threw = false;

        try {
            DB::transaction(function () use ($row) {
                DB::table('appointment_holds')->insert($row);
                // Second insert with the same id primary key forces a genuine failure.
                DB::table('appointment_holds')->insert($row);
            });
        } catch (QueryException) {
            $threw = true;
        }

        $this->assertTrue($threw, 'Expected a duplicate-key QueryException.');
        $this->assertDatabaseCount('appointment_holds', 0);
    }
}
