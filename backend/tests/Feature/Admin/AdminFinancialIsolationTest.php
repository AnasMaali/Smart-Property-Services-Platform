<?php

namespace Tests\Feature\Admin;

use App\Support\Uuid\UuidBinary;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Admin\Concerns\CreatesAdminFixtures;
use Tests\TestCase;

/**
 * BLUE V1 Phase 9B must never mutate financial state (`payment_attempts`,
 * `checkout_snapshot`, Booking Item pricing snapshots) and must never make a
 * live Stripe call. This suite drives every Phase 9B mutating endpoint once
 * and asserts the full payment/pricing byte-for-byte survives unchanged -
 * mirrors the same regression pattern already used by
 * tests/Feature/Technician/TechnicianAssignmentTest.php and
 * TechnicianJobExecutionTest.php.
 */
class AdminFinancialIsolationTest extends TestCase
{
    use CreatesAdminFixtures;
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCheckoutFixtures();
    }

    public function test_full_assign_start_complete_flow_never_mutates_payment_or_pricing_state(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);
        $fixture = $this->bookingWithAssignableItem();
        $technician = $this->createEligibleTechnician($fixture['specialization_id']);
        $itemUrl = fn (string $action) => '/api/v1/admin/booking-items/'.UuidBinary::toString($fixture['item']->id).'/'.$action;

        $paymentBefore = DB::table('payment_attempts')->where('id', $fixture['payment']->id)->first();
        $itemBefore = DB::table('booking_items')->where('id', $fixture['item']->id)->first();

        $this->postJson($itemUrl('assign-technician'), ['technician_uuid' => $technician['uuid']], $this->bearer($admin['access_token']))->assertStatus(201);
        $this->postJson($itemUrl('start-work'), ['technician_uuid' => $technician['uuid']], $this->bearer($admin['access_token']))->assertStatus(200);
        $this->postJson($itemUrl('complete-work'), ['technician_uuid' => $technician['uuid']], $this->bearer($admin['access_token']))->assertStatus(200);

        // 44 & 45. Payment remains SUCCESSFUL; amounts unchanged.
        $paymentAfter = DB::table('payment_attempts')->where('id', $fixture['payment']->id)->first();
        $this->assertSame('SUCCESSFUL', DB::table('payment_statuses')->where('id', $paymentAfter->status_id)->value('code'));
        $this->assertSame((string) $paymentBefore->requested_amount, (string) $paymentAfter->requested_amount);
        $this->assertSame((string) $paymentBefore->confirmed_amount, (string) $paymentAfter->confirmed_amount);

        // 46. checkout snapshot unchanged (byte-for-byte).
        $this->assertSame($paymentBefore->checkout_snapshot, $paymentAfter->checkout_snapshot);
        $this->assertSame($paymentBefore->checkout_snapshot_hash, $paymentAfter->checkout_snapshot_hash);

        // 47. Booking Item pricing snapshot unchanged.
        $itemAfter = DB::table('booking_items')->where('id', $fixture['item']->id)->first();
        $this->assertSame((string) $itemBefore->base_amount_snapshot, (string) $itemAfter->base_amount_snapshot);
        $this->assertSame((string) $itemBefore->unit_total_amount, (string) $itemAfter->unit_total_amount);
        $this->assertSame((string) $itemBefore->line_total_amount, (string) $itemAfter->line_total_amount);
        $this->assertSame($itemBefore->pricing_breakdown, $itemAfter->pricing_breakdown);
    }

    public function test_no_stripe_client_is_referenced_anywhere_in_the_admin_operations_source(): void
    {
        $adminOperationsFiles = array_merge(
            glob(base_path('app/Actions/Admin/**/*.php')),
            glob(base_path('app/Http/Controllers/Api/V1/Admin/**/*.php')),
        );

        foreach ($adminOperationsFiles as $file) {
            $contents = file_get_contents($file);
            $this->assertStringNotContainsStringIgnoringCase('stripe', $contents, "{$file} must never reference Stripe.");
            $this->assertStringNotContainsString('PricingEngine', $contents, "{$file} must never call PricingEngine.");
        }
    }

    // 49. No arbitrary/generic status-setter endpoint exists.
    //
    // BLUE V1 Phase B15 added a scoped PATCH /v1/admin/bookings/{booking}
    // route (Edit Booking - App\Actions\Admin\Booking\
    // AdminUpdateBookingAction), so this URI now legitimately answers to
    // PATCH. It is not a generic status-setter: `status` is not one of the
    // eight operational visit/location fields UpdateAdminBookingRequest
    // validates, so `$request->validated()` drops it before the Action ever
    // sees it - a `status` key in the body can never change
    // `bookings.status_id`. There is still no booking-item-level PATCH
    // route at all.
    public function test_no_generic_admin_status_endpoint_exists(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);
        $fixture = $this->bookingWithAssignableItem();

        $statusBefore = DB::table('bookings')->where('id', $fixture['booking']->id)->value('status_id');

        $this->patchJson('/api/v1/admin/bookings/'.UuidBinary::toString($fixture['booking']->id), [
            'status' => 'COMPLETED',
        ], $this->bearer($admin['access_token']))->assertStatus(200);

        $this->assertSame(
            $statusBefore,
            DB::table('bookings')->where('id', $fixture['booking']->id)->value('status_id'),
            'A status field in the Edit Booking request body must never change bookings.status_id.'
        );

        $this->patchJson('/api/v1/admin/booking-items/'.UuidBinary::toString($fixture['item']->id), [
            'status' => 'COMPLETED',
        ], $this->bearer($admin['access_token']))->assertStatus(404);
    }

    // 50. No customer mutation route exists for Admin-only operations.
    public function test_no_customer_facing_route_can_perform_admin_mutations(): void
    {
        $fixture = $this->bookingWithAssignableItem();
        $technician = $this->createEligibleTechnician($fixture['specialization_id']);

        $this->postJson('/api/v1/bookings/'.UuidBinary::toString($fixture['booking']->id).'/complete', [], $this->bearer($fixture['customer']['access_token']))
            ->assertStatus(404);
        $this->postJson('/api/v1/booking-items/'.UuidBinary::toString($fixture['item']->id).'/assign-technician', [
            'technician_uuid' => $technician['uuid'],
        ], $this->bearer($fixture['customer']['access_token']))->assertStatus(404);
    }

    // 55. A rejected mutation leaves no partial operational or audit write behind.
    public function test_a_rejected_mutation_leaves_no_partial_write(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);
        $fixture = $this->bookingWithAssignableItem();
        $otherSpecializationId = $this->createSpecialization();
        $mismatchedTechnician = $this->createEligibleTechnician($otherSpecializationId);
        $itemUuid = UuidBinary::toString($fixture['item']->id);

        $this->postJson('/api/v1/admin/booking-items/'.$itemUuid.'/assign-technician', [
            'technician_uuid' => $mismatchedTechnician['uuid'],
        ], $this->bearer($admin['access_token']))->assertStatus(422);

        $this->assertSame(0, DB::table('technician_assignments')->where('booking_item_id', $fixture['item']->id)->count());
        $this->assertSame(0, DB::table('booking_item_status_history')->where('booking_item_id', $fixture['item']->id)->count());
        $this->assertSame(0, $this->auditLogsFor($itemUuid)->count());
        $freshItem = DB::table('booking_items')->where('id', $fixture['item']->id)->first();
        $this->assertSame('PENDING_ASSIGNMENT', DB::table('booking_item_statuses')->where('id', $freshItem->status_id)->value('code'));
    }
}
