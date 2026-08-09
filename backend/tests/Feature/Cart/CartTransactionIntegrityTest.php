<?php

namespace Tests\Feature\Cart;

use App\Models\CartItem;
use App\Support\Cart\CartItemSelections;
use App\Support\Uuid\UuidBinary;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Cart\Concerns\CreatesCartFixtures;
use Tests\TestCase;

/**
 * Proves the "no partial writes" invariant every Cart Action relies on: a
 * genuine mid-transaction failure (here, a duplicate cart_item_option_
 * selections primary key) rolls back every prior write in the same
 * transaction, including the cart_items row inserted just before it -
 * never a half-persisted cart item with no/partial selections.
 */
class CartTransactionIntegrityTest extends TestCase
{
    use CreatesCartFixtures;
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCartFixtures();
    }

    public function test_transaction_rollback_leaves_no_orphan_item_or_selections(): void
    {
        $customer = $this->createAuthenticatedCartCustomer();
        $service = $this->createCartService();
        $option = $this->createCartOption($service['uuid'], $this->textTypeId);

        $cartUuid = UuidBinary::generate();
        $now = now();
        DB::table('carts')->insert([
            'id' => UuidBinary::toBinary($cartUuid),
            'customer_user_id' => UuidBinary::toBinary($customer['user_uuid']),
            'status_id' => (int) DB::table('cart_statuses')->where('code', 'ACTIVE')->value('id'),
            'currency_id' => $this->aedCurrencyId,
            'last_activity_at' => $now,
            'status_changed_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $normalizedOption = [
            'option_uuid' => $option,
            'option_id_binary' => UuidBinary::toBinary($option),
            'type' => 'TEXT',
            'numeric_value' => null,
            'boolean_value' => null,
            'text_value' => 'hello',
            'choice_uuids' => [],
        ];

        $threw = false;

        try {
            DB::transaction(function () use ($cartUuid, $service, $normalizedOption) {
                $itemUuid = UuidBinary::generate();

                CartItem::create([
                    'id' => $itemUuid,
                    'cart_id' => $cartUuid,
                    'service_id' => $service['uuid'],
                    'quantity' => 1,
                    'display_order' => 0,
                ]);

                // Second call reinserts the same (cart_item_id, service_option_id) primary key.
                CartItemSelections::persist(UuidBinary::toBinary($itemUuid), [$normalizedOption]);
                CartItemSelections::persist(UuidBinary::toBinary($itemUuid), [$normalizedOption]);
            });
        } catch (QueryException) {
            $threw = true;
        }

        $this->assertTrue($threw, 'Expected a duplicate-key QueryException.');
        $this->assertDatabaseCount('cart_items', 0);
        $this->assertDatabaseCount('cart_item_option_selections', 0);
    }
}
