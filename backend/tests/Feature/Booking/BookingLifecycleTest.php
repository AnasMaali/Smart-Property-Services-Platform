<?php

namespace Tests\Feature\Booking;

use App\Actions\Booking\TransitionBookingItemStatusAction;
use App\Actions\Booking\TransitionBookingStatusAction;
use App\Support\Booking\BookingItemStatusMachine;
use App\Support\Booking\BookingLifecycleOutcome;
use App\Support\Booking\BookingStatusMachine;
use App\Support\Uuid\UuidBinary;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Booking\Concerns\CreatesBookingFixtures;
use Tests\TestCase;

class BookingLifecycleTest extends TestCase
{
    use CreatesBookingFixtures;
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCheckoutFixtures();
    }

    // 1. Initial Booking status from Phase 7A is a valid lifecycle starting point.
    public function test_initial_booking_status_from_phase_7a_is_paid(): void
    {
        ['payment' => $payment] = $this->successfulPayment();
        $booking = $this->bookingRowForPayment($payment);

        $this->assertSame('PAID', DB::table('booking_statuses')->where('id', $booking->status_id)->value('code'));
    }

    // 2. Allowed transition succeeds, end to end through the full graph.
    public function test_allowed_booking_transitions_succeed_through_the_full_lifecycle(): void
    {
        ['payment' => $payment] = $this->successfulPayment();
        $booking = $this->bookingRowForPayment($payment);
        $action = app(TransitionBookingStatusAction::class);

        $toAssigned = $action->assign(UuidBinary::toString($booking->id));
        $this->assertSame(BookingLifecycleOutcome::TRANSITIONED, $toAssigned->outcome);
        $this->assertSame('PAID', $toAssigned->fromStatus);
        $this->assertSame('ASSIGNED', $toAssigned->toStatus);

        $toInProgress = $action->start(UuidBinary::toString($booking->id));
        $this->assertSame(BookingLifecycleOutcome::TRANSITIONED, $toInProgress->outcome);
        $this->assertSame('ASSIGNED', $toInProgress->fromStatus);
        $this->assertSame('IN_PROGRESS', $toInProgress->toStatus);

        $toCompleted = $action->complete(UuidBinary::toString($booking->id));
        $this->assertSame(BookingLifecycleOutcome::TRANSITIONED, $toCompleted->outcome);
        $this->assertSame('IN_PROGRESS', $toCompleted->fromStatus);
        $this->assertSame('COMPLETED', $toCompleted->toStatus);

        $fresh = DB::table('bookings')->where('id', $booking->id)->first();
        $this->assertSame('COMPLETED', DB::table('booking_statuses')->where('id', $fresh->status_id)->value('code'));
        $this->assertNotNull($fresh->completed_at);
        $this->assertNull($fresh->cancelled_at);
    }

    // 3. Forbidden transition (skipping states) is rejected, nothing written.
    public function test_forbidden_booking_transition_is_rejected(): void
    {
        ['payment' => $payment] = $this->successfulPayment();
        $booking = $this->bookingRowForPayment($payment);
        $action = app(TransitionBookingStatusAction::class);

        // Still PAID - completing directly is not a valid transition.
        $result = $action->complete(UuidBinary::toString($booking->id));

        $this->assertSame(BookingLifecycleOutcome::INVALID_TRANSITION, $result->outcome);
        $this->assertSame('PAID', $result->fromStatus);

        $fresh = DB::table('bookings')->where('id', $booking->id)->first();
        $this->assertSame($booking->status_id, $fresh->status_id);
        $this->assertNull($fresh->completed_at);
        // Only Phase 7A's own initial (null -> PAID) history row exists -
        // the rejected transition wrote nothing.
        $this->assertSame(1, DB::table('booking_status_history')->where('booking_id', $booking->id)->count());
    }

    // 4 & 5. Status history is created exactly once per real transition, and
    // its timestamp matches the transition's own status_changed_at.
    public function test_status_history_row_matches_the_transition_timestamp(): void
    {
        ['payment' => $payment] = $this->successfulPayment();
        $booking = $this->bookingRowForPayment($payment);
        $action = app(TransitionBookingStatusAction::class);

        $action->assign(UuidBinary::toString($booking->id));

        $fresh = DB::table('bookings')->where('id', $booking->id)->first();
        $history = DB::table('booking_status_history')
            ->where('booking_id', $booking->id)
            ->orderByDesc('changed_at')
            ->first();

        $this->assertNotNull($history);
        $this->assertSame((int) $booking->status_id, (int) $history->from_status_id);
        $this->assertSame((int) $fresh->status_id, (int) $history->to_status_id);
        $this->assertSame($fresh->status_changed_at, $history->changed_at);
    }

    // 6. Retrying the same transition is idempotent - never duplicate history.
    public function test_retrying_the_same_transition_does_not_duplicate_history(): void
    {
        ['payment' => $payment] = $this->successfulPayment();
        $booking = $this->bookingRowForPayment($payment);
        $action = app(TransitionBookingStatusAction::class);
        $bookingUuid = UuidBinary::toString($booking->id);

        $first = $action->assign($bookingUuid);
        $second = $action->assign($bookingUuid);
        $third = $action->assign($bookingUuid);

        $this->assertSame(BookingLifecycleOutcome::TRANSITIONED, $first->outcome);
        $this->assertSame(BookingLifecycleOutcome::ALREADY_IN_TARGET_STATE, $second->outcome);
        $this->assertSame(BookingLifecycleOutcome::ALREADY_IN_TARGET_STATE, $third->outcome);
        // Phase 7A's initial (null -> PAID) row, plus exactly one (PAID ->
        // ASSIGNED) row - the two idempotent retries wrote nothing.
        $this->assertSame(2, DB::table('booking_status_history')->where('booking_id', $booking->id)->count());
    }

    // 7. A stale caller that assumes an older status can never regress or
    // overwrite a Booking that has already moved further along.
    public function test_stale_transition_cannot_overwrite_a_newer_status(): void
    {
        ['payment' => $payment] = $this->successfulPayment();
        $booking = $this->bookingRowForPayment($payment);
        $action = app(TransitionBookingStatusAction::class);
        $bookingUuid = UuidBinary::toString($booking->id);

        $action->assign($bookingUuid);
        $action->start($bookingUuid);

        // A second caller still operating on the assumption the Booking is
        // PAID (stale) retries assign() - the Action re-reads the real,
        // currently-locked status (IN_PROGRESS) rather than trusting any
        // caller assumption, so this is safely rejected.
        $stale = $action->assign($bookingUuid);

        $this->assertSame(BookingLifecycleOutcome::INVALID_TRANSITION, $stale->outcome);
        $this->assertSame('IN_PROGRESS', $stale->fromStatus);

        $fresh = DB::table('bookings')->where('id', $booking->id)->first();
        $this->assertSame('IN_PROGRESS', DB::table('booking_statuses')->where('id', $fresh->status_id)->value('code'));
        // Initial PAID row + assign + start = 3; the rejected stale retry wrote nothing.
        $this->assertSame(3, DB::table('booking_status_history')->where('booking_id', $booking->id)->count());
    }

    // 8. An unknown Booking UUID cannot be mutated.
    public function test_unknown_booking_cannot_be_mutated(): void
    {
        $action = app(TransitionBookingStatusAction::class);

        $result = $action->assign((string) Str::uuid());

        $this->assertSame(BookingLifecycleOutcome::NOT_FOUND, $result->outcome);
    }

    // 10 & 15. Payment stays SUCCESSFUL across every Booking transition,
    // including cancellation - cancellation is fulfillment state only and
    // never implies or triggers a refund.
    public function test_payment_remains_successful_across_booking_transitions_including_cancellation(): void
    {
        ['payment' => $payment] = $this->successfulPayment();
        $booking = $this->bookingRowForPayment($payment);
        $action = app(TransitionBookingStatusAction::class);
        $bookingUuid = UuidBinary::toString($booking->id);

        $action->assign($bookingUuid);
        $this->assertPaymentStillSuccessful($payment);

        $cancelled = $action->cancel($bookingUuid, 'Address outside service area.');
        $this->assertSame(BookingLifecycleOutcome::TRANSITIONED, $cancelled->outcome);
        $this->assertPaymentStillSuccessful($payment);

        $fresh = DB::table('bookings')->where('id', $booking->id)->first();
        $this->assertSame('CANCELLED', DB::table('booking_statuses')->where('id', $fresh->status_id)->value('code'));
        $this->assertNotNull($fresh->cancelled_at);
        $this->assertNull($fresh->completed_at);
    }

    private function assertPaymentStillSuccessful(object $payment): void
    {
        $fresh = DB::table('payment_attempts')->where('id', $payment->id)->first();
        $this->assertSame('SUCCESSFUL', DB::table('payment_statuses')->where('id', $fresh->status_id)->value('code'));
        $this->assertSame((string) $payment->confirmed_amount, (string) $fresh->confirmed_amount);
        $this->assertSame((string) $payment->requested_amount, (string) $fresh->requested_amount);
        $this->assertSame((string) $payment->checkout_snapshot_hash, (string) $fresh->checkout_snapshot_hash);
    }

    // 11. Booking Item financial snapshots are untouched by any lifecycle transition.
    public function test_booking_item_financial_snapshots_are_unchanged_by_lifecycle_transitions(): void
    {
        ['payment' => $payment] = $this->successfulPayment();
        $booking = $this->bookingRowForPayment($payment);
        $itemBefore = DB::table('booking_items')->where('booking_id', $booking->id)->first();

        $bookingAction = app(TransitionBookingStatusAction::class);
        $itemAction = app(TransitionBookingItemStatusAction::class);
        $bookingUuid = UuidBinary::toString($booking->id);
        $itemUuid = UuidBinary::toString($itemBefore->id);

        $bookingAction->assign($bookingUuid);
        $bookingAction->start($bookingUuid);
        $itemAction->assign($itemUuid);
        $itemAction->start($itemUuid);
        $itemAction->complete($itemUuid);
        $bookingAction->complete($bookingUuid);

        $itemAfter = DB::table('booking_items')->where('booking_id', $booking->id)->first();

        $this->assertSame($itemBefore->base_amount_snapshot, $itemAfter->base_amount_snapshot);
        $this->assertSame($itemBefore->unit_total_amount, $itemAfter->unit_total_amount);
        $this->assertSame($itemBefore->line_total_amount, $itemAfter->line_total_amount);
        $this->assertSame($itemBefore->pricing_breakdown, $itemAfter->pricing_breakdown);
        $this->assertSame($itemBefore->service_code_snapshot, $itemAfter->service_code_snapshot);
        $this->assertSame($itemBefore->service_name_snapshot, $itemAfter->service_name_snapshot);
    }

    // 12. No PricingEngine reference exists anywhere in the Phase 7B lifecycle
    // code - a static regression guard, since PricingEngine is a `final`
    // class that cannot be swapped for a failing test double.
    public function test_lifecycle_classes_never_reference_pricing_engine(): void
    {
        $files = [
            app_path('Support/Booking/BookingStatusMachine.php'),
            app_path('Support/Booking/BookingItemStatusMachine.php'),
            app_path('Actions/Booking/TransitionBookingStatusAction.php'),
            app_path('Actions/Booking/TransitionBookingItemStatusAction.php'),
        ];

        foreach ($files as $file) {
            $source = file_get_contents($file);

            $this->assertStringNotContainsString('use App\Support\Pricing\PricingEngine', $source);
            $this->assertStringNotContainsString('PricingEngine::', $source);
            $this->assertStringNotContainsString('new PricingEngine', $source);
            $this->assertDoesNotMatchRegularExpression('/\$[A-Za-z_]*[Pp]ricing[A-Za-z]*Engine/', $source);
        }
    }

    // 13. Completion timestamp behavior: set once, never duplicated by retry.
    public function test_completion_sets_completed_at_exactly_once(): void
    {
        ['payment' => $payment] = $this->successfulPayment();
        $booking = $this->bookingRowForPayment($payment);
        $action = app(TransitionBookingStatusAction::class);
        $bookingUuid = UuidBinary::toString($booking->id);

        $action->assign($bookingUuid);
        $action->start($bookingUuid);
        $action->complete($bookingUuid);

        $firstCompletedAt = DB::table('bookings')->where('id', $booking->id)->value('completed_at');
        $this->assertNotNull($firstCompletedAt);

        $retry = $action->complete($bookingUuid);
        $this->assertSame(BookingLifecycleOutcome::ALREADY_IN_TARGET_STATE, $retry->outcome);

        $secondCompletedAt = DB::table('bookings')->where('id', $booking->id)->value('completed_at');
        $this->assertSame($firstCompletedAt, $secondCompletedAt);
        // Initial PAID row + assign + start + complete = 4; the idempotent retry wrote nothing.
        $this->assertSame(4, DB::table('booking_status_history')->where('booking_id', $booking->id)->count());
    }

    // 14. Cancellation timestamp behavior: set once, terminal, never reachable from COMPLETED.
    public function test_cancellation_sets_cancelled_at_and_is_terminal(): void
    {
        ['payment' => $payment] = $this->successfulPayment();
        $booking = $this->bookingRowForPayment($payment);
        $action = app(TransitionBookingStatusAction::class);
        $bookingUuid = UuidBinary::toString($booking->id);

        $action->assign($bookingUuid);
        $action->start($bookingUuid);
        $action->complete($bookingUuid);

        // A completed Booking can never be cancelled.
        $result = $action->cancel($bookingUuid);
        $this->assertSame(BookingLifecycleOutcome::INVALID_TRANSITION, $result->outcome);

        $fresh = DB::table('bookings')->where('id', $booking->id)->first();
        $this->assertNotNull($fresh->completed_at);
        $this->assertNull($fresh->cancelled_at);
    }

    public function test_cancellation_is_reachable_directly_from_paid(): void
    {
        ['payment' => $payment] = $this->successfulPayment();
        $booking = $this->bookingRowForPayment($payment);
        $action = app(TransitionBookingStatusAction::class);

        $result = $action->cancel(UuidBinary::toString($booking->id), 'Service cannot be provided.');

        $this->assertSame(BookingLifecycleOutcome::TRANSITIONED, $result->outcome);
        $this->assertSame('CANCELLED', $result->toStatus);
    }

    // 16. The customer read API reflects the current Booking status after a transition.
    public function test_booking_read_api_reflects_the_current_status_after_a_transition(): void
    {
        ['customer' => $customer, 'payment' => $payment] = $this->successfulPayment();
        $booking = $this->bookingRowForPayment($payment);
        app(TransitionBookingStatusAction::class)->assign(UuidBinary::toString($booking->id));

        $response = $this->getBooking($customer['access_token'], UuidBinary::toString($booking->id));

        $response->assertStatus(200);
        $this->assertSame('ASSIGNED', $response->json('data.booking.status'));
        $this->assertNull($response->json('data.booking.completed_at'));
        $this->assertNull($response->json('data.booking.cancelled_at'));
    }

    public function test_booking_read_api_reflects_completion_and_item_status(): void
    {
        ['customer' => $customer, 'payment' => $payment] = $this->successfulPayment();
        $booking = $this->bookingRowForPayment($payment);
        $item = DB::table('booking_items')->where('booking_id', $booking->id)->first();
        $bookingUuid = UuidBinary::toString($booking->id);
        $itemUuid = UuidBinary::toString($item->id);

        $bookingAction = app(TransitionBookingStatusAction::class);
        $itemAction = app(TransitionBookingItemStatusAction::class);

        $bookingAction->assign($bookingUuid);
        $bookingAction->start($bookingUuid);
        $itemAction->assign($itemUuid);
        $itemAction->start($itemUuid);
        $itemAction->complete($itemUuid);
        $bookingAction->complete($bookingUuid);

        $response = $this->getBooking($customer['access_token'], $bookingUuid);

        $response->assertStatus(200);
        $data = $response->json('data.booking');
        $this->assertSame('COMPLETED', $data['status']);
        $this->assertNotNull($data['completed_at']);
        $this->assertSame('COMPLETED', $data['items'][0]['status']);
        $this->assertNotNull($data['items'][0]['completed_at']);
    }

    // Atomicity: a forced failure on the history insert (a genuine DB CHECK
    // constraint violation - the same "real constraint collision, not a
    // mock" style Phase 7A already uses for its own rollback test) rolls
    // back the earlier `bookings` status write in the same transaction.
    public function test_transition_failure_rolls_back_the_status_write_completely(): void
    {
        ['payment' => $payment] = $this->successfulPayment();
        $booking = $this->bookingRowForPayment($payment);
        $action = app(TransitionBookingStatusAction::class);

        try {
            // chk_booking_status_history_reason requires NULL or 2-500
            // chars - an empty string forces the history insert itself to
            // fail after the `bookings` UPDATE has already run in the same
            // transaction.
            $action->assign(UuidBinary::toString($booking->id), reason: '');
            $this->fail('Expected a QueryException from the reason CHECK constraint.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('chk_booking_status_history_reason', $e->getMessage());
        }

        $fresh = DB::table('bookings')->where('id', $booking->id)->first();
        $this->assertSame($booking->status_id, $fresh->status_id);
        // Only Phase 7A's own initial (null -> PAID) history row exists -
        // the failed history insert rolled back along with the status write.
        $this->assertSame(1, DB::table('booking_status_history')->where('booking_id', $booking->id)->count());
    }

    // --- Booking Item lifecycle (Section 2) ---

    public function test_booking_item_allowed_transitions_succeed_through_the_full_lifecycle(): void
    {
        ['payment' => $payment] = $this->successfulPayment();
        $booking = $this->bookingRowForPayment($payment);
        $item = DB::table('booking_items')->where('booking_id', $booking->id)->first();
        $action = app(TransitionBookingItemStatusAction::class);
        $itemUuid = UuidBinary::toString($item->id);

        $this->assertSame(BookingLifecycleOutcome::TRANSITIONED, $action->assign($itemUuid)->outcome);
        $this->assertSame(BookingLifecycleOutcome::TRANSITIONED, $action->start($itemUuid)->outcome);
        $this->assertSame(BookingLifecycleOutcome::TRANSITIONED, $action->complete($itemUuid)->outcome);

        $fresh = DB::table('booking_items')->where('id', $item->id)->first();
        $this->assertSame('COMPLETED', DB::table('booking_item_statuses')->where('id', $fresh->status_id)->value('code'));
        $this->assertNotNull($fresh->completed_at);
    }

    public function test_booking_item_forbidden_transition_is_rejected(): void
    {
        ['payment' => $payment] = $this->successfulPayment();
        $booking = $this->bookingRowForPayment($payment);
        $item = DB::table('booking_items')->where('booking_id', $booking->id)->first();
        $action = app(TransitionBookingItemStatusAction::class);

        $result = $action->complete(UuidBinary::toString($item->id));

        $this->assertSame(BookingLifecycleOutcome::INVALID_TRANSITION, $result->outcome);
        $this->assertSame(0, DB::table('booking_item_status_history')->where('booking_item_id', $item->id)->count());
    }

    public function test_booking_item_retry_does_not_duplicate_history(): void
    {
        ['payment' => $payment] = $this->successfulPayment();
        $booking = $this->bookingRowForPayment($payment);
        $item = DB::table('booking_items')->where('booking_id', $booking->id)->first();
        $action = app(TransitionBookingItemStatusAction::class);
        $itemUuid = UuidBinary::toString($item->id);

        $action->assign($itemUuid);
        $action->assign($itemUuid);

        $this->assertSame(1, DB::table('booking_item_status_history')->where('booking_item_id', $item->id)->count());
    }

    // Item and Booking lifecycles are deliberately independent in Phase 7B.
    public function test_booking_item_and_booking_lifecycles_are_independent(): void
    {
        ['payment' => $payment] = $this->successfulPayment();
        $booking = $this->bookingRowForPayment($payment);
        $item = DB::table('booking_items')->where('booking_id', $booking->id)->first();

        // The Booking Item can be driven to COMPLETED while the parent
        // Booking is still PAID - Phase 7B does not couple or block either
        // direction (see TransitionBookingItemStatusAction's docblock).
        $itemAction = app(TransitionBookingItemStatusAction::class);
        $itemUuid = UuidBinary::toString($item->id);
        $itemAction->assign($itemUuid);
        $itemAction->start($itemUuid);
        $itemAction->complete($itemUuid);

        $freshBooking = DB::table('bookings')->where('id', $booking->id)->first();
        $this->assertSame('PAID', DB::table('booking_statuses')->where('id', $freshBooking->status_id)->value('code'));
    }

    public function test_unknown_booking_item_cannot_be_mutated(): void
    {
        $action = app(TransitionBookingItemStatusAction::class);

        $result = $action->assign((string) Str::uuid());

        $this->assertSame(BookingLifecycleOutcome::NOT_FOUND, $result->outcome);
    }

    // The pure state machines themselves are safe no-ops (never throw) for
    // an invalid starting state, mirroring PaymentAttemptStateMachine.
    public function test_status_machines_are_safe_no_ops_for_an_invalid_starting_state(): void
    {
        ['payment' => $payment] = $this->successfulPayment();
        $booking = $this->bookingRowForPayment($payment);
        $item = DB::table('booking_items')->where('booking_id', $booking->id)->first();

        $this->assertFalse((new BookingStatusMachine)->transitionToInProgress($booking, now()));
        $this->assertFalse((new BookingItemStatusMachine)->transitionToCompleted($item, now()));
    }
}
