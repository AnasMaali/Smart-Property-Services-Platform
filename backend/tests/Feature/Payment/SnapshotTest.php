<?php

namespace Tests\Feature\Payment;

use App\Support\Payment\CanonicalJson;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Payment\Concerns\CreatesPaymentFixtures;
use Tests\TestCase;

class SnapshotTest extends TestCase
{
    use CreatesPaymentFixtures;
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCheckoutFixtures();
    }

    public function test_snapshot_is_stored_and_hash_matches_its_own_canonical_encoding(): void
    {
        $customer = $this->readyForPaymentCustomer();
        $response = $this->createPayment($customer['access_token'], (string) Str::uuid());
        $row = $this->paymentRow($response->json('data.payment.uuid'));

        $decoded = json_decode((string) $row->checkout_snapshot, true);
        $recomputed = CanonicalJson::sha256(CanonicalJson::encode($decoded));

        $this->assertSame($recomputed, $row->checkout_snapshot_hash);
        $this->assertSame('100.000000', $decoded['requested_total']);
        $this->assertSame('AED', $decoded['currency']['code']);
        $this->assertCount(1, $decoded['items']);
    }

    public function test_canonical_hash_is_deterministic_regardless_of_key_order(): void
    {
        $a = ['b' => 1, 'a' => ['y' => 2, 'x' => 1], 'c' => [3, 1, 2]];
        $b = ['a' => ['x' => 1, 'y' => 2], 'c' => [3, 1, 2], 'b' => 1];

        $this->assertSame(CanonicalJson::encode($a), CanonicalJson::encode($b));
        $this->assertSame(CanonicalJson::sha256(CanonicalJson::encode($a)), CanonicalJson::sha256(CanonicalJson::encode($b)));
    }

    public function test_canonical_hash_preserves_list_order_as_semantic(): void
    {
        $ascending = ['items' => [1, 2, 3]];
        $descending = ['items' => [3, 2, 1]];

        $this->assertNotSame(CanonicalJson::encode($ascending), CanonicalJson::encode($descending));
    }

    public function test_snapshot_contains_no_raw_binary_ids(): void
    {
        $customer = $this->readyForPaymentCustomer();
        $response = $this->createPayment($customer['access_token'], (string) Str::uuid());
        $row = $this->paymentRow($response->json('data.payment.uuid'));

        $decoded = json_decode((string) $row->checkout_snapshot, true);
        $uuidPattern = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

        $this->assertMatchesRegularExpression($uuidPattern, $decoded['cart']['uuid']);
        $this->assertMatchesRegularExpression($uuidPattern, $decoded['appointment']['hold_uuid']);
        $this->assertMatchesRegularExpression($uuidPattern, $decoded['items'][0]['cart_item_uuid']);
        $this->assertMatchesRegularExpression($uuidPattern, $decoded['items'][0]['service']['uuid']);
    }

    public function test_snapshot_contains_no_secret_or_card_data(): void
    {
        $customer = $this->readyForPaymentCustomer();
        $response = $this->createPayment($customer['access_token'], (string) Str::uuid());
        $row = $this->paymentRow($response->json('data.payment.uuid'));

        $raw = strtolower((string) $row->checkout_snapshot);

        foreach (['card', 'cvv', 'secret', 'password', 'token', 'pan'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $raw);
        }
    }

    public function test_later_catalog_price_change_does_not_mutate_the_frozen_attempt(): void
    {
        $customer = $this->readyForPaymentCustomer();
        $response = $this->createPayment($customer['access_token'], (string) Str::uuid());
        $row = $this->paymentRow($response->json('data.payment.uuid'));

        DB::table('pricing_rules')->update(['effect_amount' => '999.000000']);

        $fresh = $this->paymentRow($response->json('data.payment.uuid'));
        $decoded = json_decode((string) $fresh->checkout_snapshot, true);

        $this->assertSame($row->requested_amount, $fresh->requested_amount);
        $this->assertSame('100.000000', $decoded['requested_total']);
        $this->assertSame($row->checkout_snapshot_hash, $fresh->checkout_snapshot_hash);
    }

    public function test_later_location_change_does_not_mutate_the_frozen_snapshot(): void
    {
        $customer = $this->readyForPaymentCustomer();
        $response = $this->createPayment($customer['access_token'], (string) Str::uuid());
        $row = $this->paymentRow($response->json('data.payment.uuid'));
        $decodedBefore = json_decode((string) $row->checkout_snapshot, true);

        DB::table('cart_locations')->update(['street_name' => 'A Totally Different Street']);

        $fresh = $this->paymentRow($response->json('data.payment.uuid'));
        $decodedAfter = json_decode((string) $fresh->checkout_snapshot, true);

        $this->assertSame($decodedBefore['location']['street_name'], $decodedAfter['location']['street_name']);
        $this->assertNotSame('A Totally Different Street', $decodedAfter['location']['street_name']);
        $this->assertSame($row->checkout_snapshot_hash, $fresh->checkout_snapshot_hash);
    }
}
