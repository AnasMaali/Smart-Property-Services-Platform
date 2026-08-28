<?php

namespace Tests\Feature\Admin;

use App\Support\Uuid\UuidBinary;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Contract\Concerns\CreatesContractFixtures;
use Tests\TestCase;

class AdminBookingReadTest extends TestCase
{
    use CreatesContractFixtures;
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCheckoutFixtures();

        config([
            'cancellation.timezone' => 'UTC',
            'cancellation.before_appointment_day_percentage' => 100,
            'cancellation.appointment_day_percentage' => 75,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function cancelBooking(string $accessToken, object $booking)
    {
        return $this->postJson(
            '/api/v1/bookings/'.UuidBinary::toString($booking->id).'/cancel',
            [],
            ['Authorization' => 'Bearer '.$accessToken]
        );
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

    // 11. Non-cancelled Booking detail never claims a refund is due.
    public function test_booking_detail_does_not_incorrectly_claim_a_refund_is_due_when_not_cancelled(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);
        $fixture = $this->successfulPayment();
        $booking = $this->bookingRowForPayment($fixture['payment']);

        $response = $this->getJson('/api/v1/admin/bookings/'.UuidBinary::toString($booking->id), $this->bearer($admin['access_token']))
            ->assertStatus(200);

        $data = $response->json('data.booking');
        $this->assertArrayHasKey('refund_due', $data);
        $this->assertNull($data['refund_due']);
    }

    // 12. Admin GET on a CANCELLED Booking exposes the same cancelled_at /
    // refund_due information as the Customer read API, using the same
    // RefundEligibilityCalculator and the Booking's original cancelled_at.
    public function test_admin_can_view_refund_due_information_on_a_cancelled_booking(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);
        $fixture = $this->successfulPayment([
            'starts_at' => Carbon::parse('2026-09-15 10:00:00'),
        ]);
        $booking = $this->bookingRowForPayment($fixture['payment']);

        Carbon::setTestNow('2026-09-15 05:00:00');

        $this->cancelBooking($fixture['customer']['access_token'], $booking)->assertStatus(200);

        // The refund_due snapshot was already computed and persisted at
        // cancellation time above; the Admin read below never recomputes
        // it (see test 14), so resetting the clock here is safe and
        // avoids an unrelated collision with the BLUE V1 Phase A2.4 Admin
        // idle/absolute session timeout - $admin's session/token were
        // minted at the real wall-clock instant above, before this test
        // jumped Carbon::setTestNow() weeks into the future for the
        // cancellation-policy scenario itself.
        Carbon::setTestNow();

        $response = $this->getJson('/api/v1/admin/bookings/'.UuidBinary::toString($booking->id), $this->bearer($admin['access_token']))
            ->assertStatus(200);

        $data = $response->json('data.booking');

        $this->assertSame('CANCELLED', $data['status']);
        $this->assertNotNull($data['cancelled_at']);

        $paidAmount = (string) DB::table('payment_attempts')->where('id', $fixture['payment']->id)->value('confirmed_amount');
        $expectedRefund = bcdiv(bcmul($paidAmount, '75', 6), '100', 6);

        $this->assertSame(75, $data['refund_due']['percentage']);
        $this->assertSame($expectedRefund, $data['refund_due']['amount']);
        $this->assertSame('STRIPE_AUTOMATIC', $data['refund_due']['execution']);
    }

    // 13. Admin Booking detail refund_due never exposes provider/Stripe
    // internals beyond the explicit, safe execution-status fields BLUE V1
    // Phase B20 added (status/provider/provider_refund_reference/
    // requested_at/succeeded_at/failed_at) - never a raw Stripe object,
    // client_secret, or any other provider-internal field.
    public function test_admin_booking_detail_refund_due_never_leaks_payment_or_provider_internals(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);
        $fixture = $this->successfulPayment([
            'starts_at' => Carbon::parse('2026-09-15 10:00:00'),
        ]);
        $booking = $this->bookingRowForPayment($fixture['payment']);

        Carbon::setTestNow('2026-09-14 20:00:00');
        $this->cancelBooking($fixture['customer']['access_token'], $booking)->assertStatus(200);

        // See the identical reset in the previous test above - the refund
        // snapshot is already persisted; this avoids an unrelated
        // collision with the Admin idle/absolute session timeout.
        Carbon::setTestNow();

        $response = $this->getJson('/api/v1/admin/bookings/'.UuidBinary::toString($booking->id), $this->bearer($admin['access_token']))
            ->assertStatus(200);

        $refundDue = $response->json('data.booking.refund_due');
        $this->assertSame(
            ['percentage', 'amount', 'execution', 'status', 'provider', 'provider_refund_reference', 'requested_at', 'succeeded_at', 'failed_at', 'failure_code', 'failure_message'],
            array_keys($refundDue)
        );

        $this->assertStringNotContainsString('client_secret', strtolower($response->getContent()));
    }

    // 14. Admin GET on a CANCELLED Booking keeps showing the ORIGINAL
    // refund_due percentage/amount after the cancellation policy config
    // changes - the read API must never recompute from current config.
    public function test_admin_get_cancelled_booking_refund_due_survives_a_later_policy_config_change(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);
        $fixture = $this->successfulPayment([
            'starts_at' => Carbon::parse('2026-09-15 10:00:00'),
        ]);
        $booking = $this->bookingRowForPayment($fixture['payment']);

        Carbon::setTestNow('2026-09-14 20:00:00');

        $this->cancelBooking($fixture['customer']['access_token'], $booking)->assertStatus(200);

        $paidAmount = (string) DB::table('payment_attempts')->where('id', $fixture['payment']->id)->value('confirmed_amount');
        $expectedAmount = bcdiv(bcmul($paidAmount, '100', 6), '100', 6);

        config([
            'cancellation.before_appointment_day_percentage' => 90,
            'cancellation.appointment_day_percentage' => 50,
        ]);

        // See the identical reset above - the refund snapshot is already
        // persisted; this avoids an unrelated collision with the Admin
        // idle/absolute session timeout.
        Carbon::setTestNow();

        $response = $this->getJson('/api/v1/admin/bookings/'.UuidBinary::toString($booking->id), $this->bearer($admin['access_token']))
            ->assertStatus(200);

        $this->assertSame(100, $response->json('data.booking.refund_due.percentage'));
        $this->assertSame($expectedAmount, $response->json('data.booking.refund_due.amount'));
        $this->assertSame('STRIPE_AUTOMATIC', $response->json('data.booking.refund_due.execution'));
    }

    // ---------------------------------------------------------------
    // BLUE V1 Phase B14 - Booking operations workspace: list row fields,
    // new filters, and detail sections (status history, selected
    // options/choices, rating, contract entitlement).
    // ---------------------------------------------------------------

    // 15. List rows carry services/appointment/payment/assignment_state,
    // and a freshly-paid, unassigned item is factually PENDING.
    public function test_list_row_includes_services_appointment_payment_and_pending_assignment_state(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);
        $fixture = $this->bookingWithAssignableItem();

        $response = $this->getJson(
            '/api/v1/admin/bookings?booking_number='.$fixture['booking']->booking_number,
            $this->bearer($admin['access_token'])
        )->assertStatus(200);

        $row = $response->json('data.bookings.0');

        $this->assertSame([$fixture['item']->service_name_snapshot], $row['services']);
        $this->assertNotNull($row['appointment']['slot']['starts_at']);
        $this->assertSame('SUCCESSFUL', $row['payment']['status']);
        $this->assertSame('PENDING', $row['assignment_state']);
    }

    // 16. Assigning the only item on a single-item Booking makes the list
    // row's assignment_state FULL, and technician_uuid/assignment_state
    // filters both find it.
    public function test_assignment_state_becomes_full_and_technician_filter_matches_after_a_real_assignment(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);
        $fixture = $this->bookingWithAssignableItem();
        $technician = $this->createEligibleTechnician($fixture['specialization_id']);

        $this->postJson(
            '/api/v1/admin/booking-items/'.UuidBinary::toString($fixture['item']->id).'/assign-technician',
            ['technician_uuid' => $technician['uuid']],
            $this->bearer($admin['access_token'])
        )->assertStatus(201);

        $bookingUuid = UuidBinary::toString($fixture['booking']->id);

        $full = $this->getJson('/api/v1/admin/bookings?assignment_state=FULL', $this->bearer($admin['access_token']))->assertStatus(200);
        $this->assertContains($bookingUuid, collect($full->json('data.bookings'))->pluck('uuid')->all());

        $pending = $this->getJson('/api/v1/admin/bookings?assignment_state=PENDING', $this->bearer($admin['access_token']))->assertStatus(200);
        $this->assertNotContains($bookingUuid, collect($pending->json('data.bookings'))->pluck('uuid')->all());

        $byTechnician = $this->getJson(
            '/api/v1/admin/bookings?technician_uuid='.$technician['uuid'],
            $this->bearer($admin['access_token'])
        )->assertStatus(200);
        $this->assertSame([$bookingUuid], collect($byTechnician->json('data.bookings'))->pluck('uuid')->all());

        $detail = $this->getJson('/api/v1/admin/bookings/'.$bookingUuid, $this->bearer($admin['access_token']))->assertStatus(200);
        $this->assertSame('FULL', $this->deriveAssignmentStateFromDetail($detail->json('data.booking')));
    }

    private function deriveAssignmentStateFromDetail(array $booking): string
    {
        $items = $booking['items'];
        $assigned = count(array_filter($items, fn (array $item) => $item['active_assignment'] !== null));

        return $assigned === count($items) ? 'FULL' : ($assigned === 0 ? 'PENDING' : 'PARTIAL');
    }

    // 17. A non-UUID-shaped technician_uuid/service_uuid is rejected at the
    // request-validation layer (422), mirroring the existing
    // customer_uuid/status convention - never a 500.
    public function test_malformed_technician_or_service_uuid_filter_is_rejected(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);
        $this->successfulPayment();

        $this->getJson('/api/v1/admin/bookings?technician_uuid=not-a-uuid', $this->bearer($admin['access_token']))
            ->assertStatus(422);

        $this->getJson('/api/v1/admin/bookings?service_uuid=not-a-uuid', $this->bearer($admin['access_token']))
            ->assertStatus(422);
    }

    public function test_unknown_assignment_state_filter_value_is_rejected(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);

        $this->getJson('/api/v1/admin/bookings?assignment_state=NOT_A_REAL_STATE', $this->bearer($admin['access_token']))
            ->assertStatus(422);
    }

    // 18. service_uuid filter scopes to Bookings that actually contain
    // that Service.
    public function test_service_uuid_filter_scopes_to_bookings_containing_that_service(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);
        $fixtureA = $this->bookingWithAssignableItem(['slot' => ['starts_at' => now()->addDays(32)->setTime(9, 0), 'ends_at' => now()->addDays(32)->setTime(11, 0)]]);
        $fixtureB = $this->bookingWithAssignableItem(['slot' => ['starts_at' => now()->addDays(33)->setTime(9, 0), 'ends_at' => now()->addDays(33)->setTime(11, 0)]]);

        $response = $this->getJson(
            '/api/v1/admin/bookings?service_uuid='.$fixtureA['service']['uuid'],
            $this->bearer($admin['access_token'])
        )->assertStatus(200);

        $uuids = collect($response->json('data.bookings'))->pluck('uuid')->all();
        $this->assertContains(UuidBinary::toString($fixtureA['booking']->id), $uuids);
        $this->assertNotContains(UuidBinary::toString($fixtureB['booking']->id), $uuids);
    }

    // 19. Booking detail exposes booking-level status_history (every real
    // Booking gets at least one row - PAID - at creation) and Booking Item
    // status_history/selected_options/selected_choices, without leaking
    // raw binary ids anywhere in them.
    public function test_booking_detail_includes_status_history_and_item_options(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);
        $fixture = $this->bookingWithAssignableItem();

        $optionUuid = UuidBinary::generate();
        $now = now();

        DB::table('service_options')->insert([
            'id' => UuidBinary::toBinary($optionUuid),
            'service_id' => $fixture['item']->service_id,
            'option_type_id' => 2, // NUMBER
            'code' => 'ADMIN_QA_ROOMS',
            'name' => 'Number of rooms',
            'description' => 'QA fixture option, not real catalog content.',
            'is_required' => 0,
            'display_order' => 0,
            'is_active' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('booking_item_option_selections')->insert([
            'booking_item_id' => $fixture['item']->id,
            'service_option_id' => UuidBinary::toBinary($optionUuid),
            'measurement_unit_id' => 1,
            'option_code_snapshot' => 'ADMIN_QA_ROOMS',
            'option_name_snapshot' => 'Number of rooms',
            'option_type_code_snapshot' => 'NUMBER',
            'numeric_value' => '3.000000',
            'boolean_value' => null,
            'measurement_unit_code_snapshot' => 'ROOM',
            'measurement_unit_name_snapshot' => 'Room',
            'measurement_unit_symbol_snapshot' => 'room',
            'additional_unit_amount_snapshot' => '15.000000',
            'created_at' => $now,
        ]);

        $choiceUuid = UuidBinary::generate();

        DB::table('service_option_choices')->insert([
            'id' => UuidBinary::toBinary($choiceUuid),
            'service_option_id' => UuidBinary::toBinary($optionUuid),
            'code' => 'ADMIN_QA_CHOICE',
            'name' => 'Deep clean',
            'description' => 'QA fixture choice.',
            'display_order' => 0,
            'is_active' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('booking_item_option_choice_selections')->insert([
            'booking_item_id' => $fixture['item']->id,
            'service_option_choice_id' => UuidBinary::toBinary($choiceUuid),
            'option_code_snapshot' => 'ADMIN_QA_ROOMS',
            'option_name_snapshot' => 'Number of rooms',
            'option_type_code_snapshot' => 'SINGLE_SELECT',
            'choice_code_snapshot' => 'ADMIN_QA_CHOICE',
            'choice_name_snapshot' => 'Deep clean',
            'choice_description_snapshot' => 'QA fixture choice.',
            'additional_unit_amount_snapshot' => '25.000000',
            'created_at' => $now,
        ]);

        $response = $this->getJson(
            '/api/v1/admin/bookings/'.UuidBinary::toString($fixture['booking']->id),
            $this->bearer($admin['access_token'])
        )->assertStatus(200);

        $booking = $response->json('data.booking');

        $this->assertNotEmpty($booking['status_history']);
        $this->assertSame('PAID', $booking['status_history'][0]['to_status']);
        $this->assertNull($booking['status_history'][0]['from_status']);
        $this->assertSame(36, strlen($booking['payment']['uuid']));

        $item = $booking['items'][0];
        $this->assertSame([
            'option_name' => 'Number of rooms',
            'option_type' => 'NUMBER',
            'numeric_value' => '3.000000',
            'boolean_value' => null,
            'measurement_unit_symbol' => 'room',
            'additional_amount' => '15.000000',
        ], $item['selected_options'][0]);

        $this->assertSame([
            'option_name' => 'Number of rooms',
            'choice_name' => 'Deep clean',
            'choice_description' => 'QA fixture choice.',
            'additional_amount' => '25.000000',
        ], $item['selected_choices'][0]);

        $this->assertSame([], $item['status_history']);

        $json = json_encode($response->json());
        $this->assertStringNotContainsString('option_code_snapshot', $json);
        $this->assertStringNotContainsString('choice_code_snapshot', $json);
    }

    // 20. A completed Booking Item's real status transition (via the same
    // technician job endpoints AdminJobExecutionTest already drives) shows
    // up as a Booking Item status_history entry.
    public function test_booking_item_status_history_reflects_a_real_start_and_complete_transition(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);
        $fixture = $this->bookingWithAssignableItem();
        $technician = $this->createEligibleTechnician($fixture['specialization_id']);
        $itemUuid = UuidBinary::toString($fixture['item']->id);

        $this->postJson("/api/v1/admin/booking-items/{$itemUuid}/assign-technician", [
            'technician_uuid' => $technician['uuid'],
        ], $this->bearer($admin['access_token']))->assertStatus(201);

        $this->postJson("/api/v1/admin/booking-items/{$itemUuid}/start-work", [
            'technician_uuid' => $technician['uuid'],
        ], $this->bearer($admin['access_token']))->assertStatus(200);

        $this->postJson("/api/v1/admin/booking-items/{$itemUuid}/complete-work", [
            'technician_uuid' => $technician['uuid'],
        ], $this->bearer($admin['access_token']))->assertStatus(200);

        $response = $this->getJson(
            '/api/v1/admin/bookings/'.UuidBinary::toString($fixture['booking']->id),
            $this->bearer($admin['access_token'])
        )->assertStatus(200);

        $itemHistory = $response->json('data.booking.items.0.status_history');
        $this->assertNotEmpty($itemHistory);
        $this->assertContains('COMPLETED', array_column($itemHistory, 'to_status'));
    }

    // 21. A real Rating on a completed Booking is exposed read-only;
    // absent otherwise.
    public function test_booking_detail_exposes_rating_when_present_and_null_otherwise(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);
        $fixture = $this->successfulPayment();
        $booking = $this->bookingRowForPayment($fixture['payment']);

        $withoutRating = $this->getJson(
            '/api/v1/admin/bookings/'.UuidBinary::toString($booking->id),
            $this->bearer($admin['access_token'])
        )->assertStatus(200);
        $this->assertNull($withoutRating->json('data.booking.rating'));

        DB::table('ratings')->insert([
            'booking_id' => $booking->id,
            'rating_value' => 5,
            'comment' => 'Great service.',
            'created_at' => now(),
        ]);

        $withRating = $this->getJson(
            '/api/v1/admin/bookings/'.UuidBinary::toString($booking->id),
            $this->bearer($admin['access_token'])
        )->assertStatus(200);

        $this->assertSame(5, $withRating->json('data.booking.rating.rating_value'));
        $this->assertSame('Great service.', $withRating->json('data.booking.rating.comment'));
        $this->assertNotNull($withRating->json('data.booking.rating.created_at'));
    }

    // 22. A real CONTRACT-sourced Booking's detail exposes the Contract's
    // status and entitlement summary via the existing
    // ContractEntitlementCalculator - never invented, never recomputed
    // differently from the Admin Contract page.
    public function test_booking_detail_exposes_contract_status_and_entitlement_for_a_contract_booking(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);
        $built = $this->activeContractWithItem();
        $slot = $this->createAppointmentSlot();

        $bookResponse = $this->bookContractService(
            $built['customer']['access_token'],
            UuidBinary::toString($built['contract']->id),
            UuidBinary::toString($built['item']->id),
            $slot['uuid']
        )->assertStatus(201);

        $bookingUuid = $bookResponse->json('data.booking.uuid');

        $response = $this->getJson('/api/v1/admin/bookings/'.$bookingUuid, $this->bearer($admin['access_token']))
            ->assertStatus(200);

        $contract = $response->json('data.booking.contract');
        $this->assertSame(UuidBinary::toString($built['contract']->id), $contract['contract_uuid']);
        $this->assertSame($built['contract']->contract_number, $contract['contract_number']);
        $this->assertNotNull($contract['status']);
        $this->assertSame(1, $contract['entitlement']['used_visits']);
        $this->assertNull($response->json('data.booking.payment'));
    }
}
