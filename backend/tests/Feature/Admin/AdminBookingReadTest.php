<?php

namespace Tests\Feature\Admin;

use App\Support\Uuid\UuidBinary;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\Feature\Admin\Concerns\CreatesAdminFixtures;
use Tests\TestCase;

class AdminBookingReadTest extends TestCase
{
    use CreatesAdminFixtures;
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCheckoutFixtures();
    }

    // 1. Admin can list Bookings.
    public function test_admin_can_list_bookings(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);
        $fixture = $this->successfulPayment();

        $response = $this->getJson('/api/v1/admin/bookings', $this->bearer($admin['access_token']));

        $response->assertStatus(200)->assertJson(['success' => true]);
        $booking = $this->bookingRowForPayment($fixture['payment']);
        $uuids = collect($response->json('data.bookings'))->pluck('uuid')->all();
        $this->assertContains(UuidBinary::toString($booking->id), $uuids);
    }

    // 2. Super Admin can list Bookings.
    public function test_super_admin_can_list_bookings(): void
    {
        $admin = $this->createAndLoginAdmin(['SUPER_ADMIN']);
        $this->successfulPayment();

        $this->getJson('/api/v1/admin/bookings', $this->bearer($admin['access_token']))
            ->assertStatus(200)->assertJson(['success' => true]);
    }

    // 3. Customer cannot list Admin Bookings.
    public function test_customer_cannot_list_admin_bookings(): void
    {
        $fixture = $this->successfulPayment();

        $response = $this->getJson('/api/v1/admin/bookings', $this->bearer($fixture['customer']['access_token']));

        $response->assertStatus(401);
    }

    public function test_unauthenticated_request_cannot_list_admin_bookings(): void
    {
        $this->getJson('/api/v1/admin/bookings')->assertStatus(401);
    }

    // 4 & 5. Pagination works and ordering is deterministic (created_at DESC, id DESC).
    public function test_pagination_and_deterministic_ordering(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);

        $bookingUuids = [];
        for ($i = 0; $i < 3; $i++) {
            $slot = ['starts_at' => now()->addDays(20 + $i)->setTime(9, 0), 'ends_at' => now()->addDays(20 + $i)->setTime(11, 0)];
            $fixture = $this->successfulPayment($slot);
            $bookingUuids[] = UuidBinary::toString($this->bookingRowForPayment($fixture['payment'])->id);
        }

        $page1 = $this->getJson('/api/v1/admin/bookings?per_page=2&page=1', $this->bearer($admin['access_token']))
            ->assertStatus(200);
        $page2 = $this->getJson('/api/v1/admin/bookings?per_page=2&page=2', $this->bearer($admin['access_token']))
            ->assertStatus(200);

        $this->assertSame(2, count($page1->json('data.bookings')));
        $this->assertSame(2, $page1->json('data.pagination.per_page'));
        $this->assertSame(1, $page1->json('data.pagination.page'));
        $this->assertGreaterThanOrEqual(3, $page1->json('data.pagination.total'));

        $page1Uuids = collect($page1->json('data.bookings'))->pluck('uuid')->all();
        $page2Uuids = collect($page2->json('data.bookings'))->pluck('uuid')->all();
        $this->assertEmpty(array_intersect($page1Uuids, $page2Uuids));

        // Newest-first: the last booking created must appear before earlier ones.
        $this->assertSame(end($bookingUuids), $page1->json('data.bookings.0.uuid'));

        // Re-requesting the same page returns the exact same order.
        $repeat = $this->getJson('/api/v1/admin/bookings?per_page=2&page=1', $this->bearer($admin['access_token']))->assertStatus(200);
        $this->assertSame($page1Uuids, collect($repeat->json('data.bookings'))->pluck('uuid')->all());
    }

    public function test_per_page_is_capped_at_the_documented_maximum(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);
        $this->successfulPayment();

        $response = $this->getJson('/api/v1/admin/bookings?per_page=99999', $this->bearer($admin['access_token']));

        $response->assertStatus(422);
    }

    // 6. Filters work as documented: status, booking_number, customer_uuid.
    public function test_status_filter_only_returns_matching_bookings(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);
        $fixture = $this->successfulPayment();
        $booking = $this->bookingRowForPayment($fixture['payment']);

        $matching = $this->getJson('/api/v1/admin/bookings?status=PAID', $this->bearer($admin['access_token']))->assertStatus(200);
        $this->assertContains(UuidBinary::toString($booking->id), collect($matching->json('data.bookings'))->pluck('uuid')->all());

        $nonMatching = $this->getJson('/api/v1/admin/bookings?status=COMPLETED', $this->bearer($admin['access_token']))->assertStatus(200);
        $this->assertNotContains(UuidBinary::toString($booking->id), collect($nonMatching->json('data.bookings'))->pluck('uuid')->all());
    }

    public function test_unknown_status_filter_value_is_rejected(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);

        $this->getJson('/api/v1/admin/bookings?status=NOT_A_REAL_STATUS', $this->bearer($admin['access_token']))
            ->assertStatus(422);
    }

    public function test_booking_number_filter_returns_exactly_that_booking(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);
        $fixture = $this->successfulPayment();
        $booking = $this->bookingRowForPayment($fixture['payment']);

        $response = $this->getJson('/api/v1/admin/bookings?booking_number='.$booking->booking_number, $this->bearer($admin['access_token']))
            ->assertStatus(200);

        $uuids = collect($response->json('data.bookings'))->pluck('uuid')->all();
        $this->assertSame([UuidBinary::toString($booking->id)], $uuids);
    }

    public function test_customer_uuid_filter_scopes_to_that_customer_only(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);
        $fixtureA = $this->successfulPayment(['starts_at' => now()->addDays(30)->setTime(9, 0), 'ends_at' => now()->addDays(30)->setTime(11, 0)]);
        $fixtureB = $this->successfulPayment(['starts_at' => now()->addDays(31)->setTime(9, 0), 'ends_at' => now()->addDays(31)->setTime(11, 0)]);
        $bookingA = $this->bookingRowForPayment($fixtureA['payment']);

        $response = $this->getJson('/api/v1/admin/bookings?customer_uuid='.$fixtureA['customer']['user_uuid'], $this->bearer($admin['access_token']))
            ->assertStatus(200);

        $uuids = collect($response->json('data.bookings'))->pluck('uuid')->all();
        $this->assertSame([UuidBinary::toString($bookingA->id)], $uuids);
    }

    // 7 & 9. Admin can view Booking detail for any customer's Booking.
    public function test_admin_can_view_booking_detail_for_any_customer(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);
        $fixture = $this->bookingWithAssignableItem();

        $response = $this->getJson('/api/v1/admin/bookings/'.UuidBinary::toString($fixture['booking']->id), $this->bearer($admin['access_token']));

        $response->assertStatus(200)->assertJson([
            'success' => true,
            'data' => [
                'booking' => [
                    'uuid' => UuidBinary::toString($fixture['booking']->id),
                    'booking_number' => $fixture['booking']->booking_number,
                    'status' => 'PAID',
                    'customer' => ['uuid' => $fixture['customer']['user_uuid']],
                ],
            ],
        ]);
        $this->assertSame(1, count($response->json('data.booking.items')));
        $this->assertNull($response->json('data.booking.items.0.active_assignment'));
    }

    // 8. Malformed UUID is a safe 404, not a 500 or 422.
    public function test_malformed_booking_uuid_returns_404(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);

        $this->getJson('/api/v1/admin/bookings/not-a-uuid', $this->bearer($admin['access_token']))
            ->assertStatus(404);
    }

    public function test_unknown_booking_uuid_returns_404(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);

        $this->getJson('/api/v1/admin/bookings/'.UuidBinary::generate(), $this->bearer($admin['access_token']))
            ->assertStatus(404);
    }

    public function test_customer_cannot_view_admin_booking_detail(): void
    {
        $fixture = $this->successfulPayment();
        $booking = $this->bookingRowForPayment($fixture['payment']);

        $this->getJson('/api/v1/admin/bookings/'.UuidBinary::toString($booking->id), $this->bearer($fixture['customer']['access_token']))
            ->assertStatus(401);
    }

    // 10. Response contains no secrets/binary ids/payment provider internals.
    public function test_booking_detail_never_exposes_secrets_or_raw_ids(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);
        $fixture = $this->bookingWithAssignableItem();

        $response = $this->getJson('/api/v1/admin/bookings/'.UuidBinary::toString($fixture['booking']->id), $this->bearer($admin['access_token']))
            ->assertStatus(200);

        $json = json_encode($response->json());
        $this->assertStringNotContainsString('client_secret', $json);
        $this->assertStringNotContainsString('checkout_snapshot', $json);
        $this->assertStringNotContainsString('password', $json);
        $this->assertStringNotContainsString('idempotency_key', $json);

        $booking = $response->json('data.booking');
        $this->assertSame(36, strlen($booking['uuid']));
        $this->assertSame(36, strlen($booking['customer']['uuid']));
    }
}
