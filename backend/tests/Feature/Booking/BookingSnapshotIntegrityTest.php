<?php

namespace Tests\Feature\Booking;

use App\Support\Uuid\UuidBinary;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Booking\Concerns\CreatesBookingFixtures;
use Tests\TestCase;

class BookingSnapshotIntegrityTest extends TestCase
{
    use CreatesBookingFixtures;
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCheckoutFixtures();
    }

    // 15 & 19. Pricing totals are copied verbatim from the frozen
    // checkout_snapshot, computed only with decimal-string BCMath - never a
    // PHP float anywhere in the stored/returned values.
    public function test_booking_item_pricing_is_copied_from_the_frozen_snapshot_never_recomputed(): void
    {
        ['payment' => $payment] = $this->successfulPayment();
        $booking = $this->bookingRowForPayment($payment);
        $snapshot = json_decode((string) $payment->checkout_snapshot, true);

        $item = DB::table('booking_items')->where('booking_id', $booking->id)->first();
        $snapshotItem = $snapshot['items'][0];

        $this->assertSame($snapshotItem['pricing']['base_amount'], $item->base_amount_snapshot);
        $this->assertSame($snapshotItem['pricing']['unit_total'], $item->unit_total_amount);
        $this->assertSame($snapshotItem['pricing']['line_total'], $item->line_total_amount);

        foreach ([$item->base_amount_snapshot, $item->unit_total_amount, $item->line_total_amount] as $amount) {
            $this->assertMatchesRegularExpression('/^\d+\.\d{6}$/', (string) $amount);
        }
    }

    // 16. A later catalog price change does not alter the Booking.
    public function test_later_catalog_price_change_does_not_mutate_the_booking(): void
    {
        ['payment' => $payment] = $this->successfulPayment();
        $booking = $this->bookingRowForPayment($payment);
        $itemBefore = DB::table('booking_items')->where('booking_id', $booking->id)->first();

        DB::table('pricing_rules')->update(['effect_amount' => '999.000000']);

        $itemAfter = DB::table('booking_items')->where('booking_id', $booking->id)->first();

        $this->assertSame($itemBefore->base_amount_snapshot, $itemAfter->base_amount_snapshot);
        $this->assertSame($itemBefore->line_total_amount, $itemAfter->line_total_amount);
        $this->assertSame('100.000000', $itemAfter->line_total_amount);
    }

    // 17. A later service name/details change does not alter historical
    // financial or descriptive values already written to the Booking.
    public function test_later_service_name_change_does_not_mutate_the_booking(): void
    {
        ['payment' => $payment] = $this->successfulPayment();
        $booking = $this->bookingRowForPayment($payment);
        $itemBefore = DB::table('booking_items')->where('booking_id', $booking->id)->first();

        DB::table('services')->where('id', $itemBefore->service_id)->update(['name' => 'A Totally Renamed Service']);

        $itemAfter = DB::table('booking_items')->where('booking_id', $booking->id)->first();

        $this->assertSame($itemBefore->service_name_snapshot, $itemAfter->service_name_snapshot);
        $this->assertNotSame('A Totally Renamed Service', $itemAfter->service_name_snapshot);
    }

    // 18. sum(booking_items.line_total_amount) equals the Booking's total
    // as returned by the read API (bookings itself stores no total column
    // by schema design - it is always derived from its Booking Items).
    public function test_sum_of_booking_items_equals_the_bookings_reported_total(): void
    {
        ['customer' => $customer, 'payment' => $payment] = $this->successfulPayment();
        $booking = $this->bookingRowForPayment($payment);

        $items = DB::table('booking_items')->where('booking_id', $booking->id)->get();
        $expectedTotal = '0.000000';
        foreach ($items as $item) {
            $expectedTotal = bcadd($expectedTotal, (string) $item->line_total_amount, 6);
        }

        $response = $this->getBooking($customer['access_token'], UuidBinary::toString($booking->id));
        $response->assertStatus(200);

        $this->assertSame($expectedTotal, $response->json('data.booking.total'));
    }

    public function test_booking_location_survives_a_later_cart_location_edit_elsewhere(): void
    {
        ['payment' => $payment] = $this->successfulPayment();
        $booking = $this->bookingRowForPayment($payment);
        $locationBefore = DB::table('booking_locations')->where('booking_id', $booking->id)->first();

        DB::table('cart_locations')->update(['street_name' => 'A Totally Different Street']);

        $locationAfter = DB::table('booking_locations')->where('booking_id', $booking->id)->first();

        $this->assertSame($locationBefore->street_name, $locationAfter->street_name);
        $this->assertNotSame('A Totally Different Street', $locationAfter->street_name);
    }
}
