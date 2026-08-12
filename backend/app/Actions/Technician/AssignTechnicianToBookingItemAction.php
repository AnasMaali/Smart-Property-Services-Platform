<?php

namespace App\Actions\Technician;

use App\Actions\Booking\TransitionBookingItemStatusAction;
use App\Support\Booking\BookingItemStatuses;
use App\Support\Technician\TechnicianAssignmentOutcome;
use App\Support\Technician\TechnicianAssignmentResult;
use App\Support\Uuid\UuidBinary;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * The one canonical, server/internal-only entry point for assigning a
 * Technician to a Booking Item (BLUE V1 Phase 8A). Never reachable from any
 * HTTP route: docs/03-features-and-requirements/07-technician-assignment.md
 * is explicit that "only the admin can assign technicians in Version 1," and
 * - exactly like App\Actions\Booking\TransitionBookingStatusAction before it
 * - no admin authentication layer exists yet, so there is currently no safe
 * caller for this outside server-side code. A future admin-panel Action or
 * Controller can call assign()/reassign() directly once that auth layer
 * exists; this phase builds only the domain foundation.
 *
 * Assignment is Booking-Item-level, never Booking-level: technician_
 * assignments.booking_item_id is the only foreign key the table has to the
 * Booking graph (database/blue_v1_schema.sql), and
 * docs/02-user-roles-and-stakeholders/04-technician-service-employee.md
 * confirms "the technician may be assigned to a specific service item inside
 * the booking, not necessarily to the whole booking." Assigning the same
 * Technician to every item in a Booking (the "one technician for the whole
 * booking" case the docs also describe) is simply calling assign() once per
 * item - never a separate Booking-level code path.
 *
 * Only the *primary* assignment per Booking Item is implemented here
 * (technician_assignments.is_primary = 1 on every row this class writes).
 * The schema's own uq_technician_assignments_active_technician /
 * uq_technician_assignments_active_primary constraints show it was designed
 * to also allow secondary (non-primary) technicians on the same item, but
 * nothing in the Version 1 requirements docs describes assigning more than
 * one technician per service item - so that path is deliberately left
 * unbuilt rather than invented.
 *
 * Never touches `payment_attempts`, `carts`, `checkout_snapshot`, or calls
 * PricingEngine/Stripe - assignment is fulfillment state, matching the same
 * boundary TransitionBookingStatusAction already documents. Never updates
 * the parent `bookings` row or calls TransitionBookingStatusAction -
 * Booking/Booking-Item lifecycles are deliberately independent in Phase 7B,
 * and no current requirement defines an aggregate "all items assigned ->
 * Booking ASSIGNED" rule, so this phase does not invent one.
 *
 * Lock order: `booking_items` (the row being assigned) -> `technicians` (the
 * Technician being assigned). Both assign() and reassign() acquire locks in
 * this exact order, so two concurrent calls for the same item simply queue
 * on the `booking_items` lock, and two concurrent calls for the same
 * Technician (different items) simply queue on the `technicians` lock -
 * never a reversed pair, so never a deadlock cycle. This never locks
 * `bookings`, `payment_attempts`, `carts`, or `appointment_holds` -
 * disjoint from every Payment/Booking-lifecycle lock path.
 *
 * Double-booking safety: the schema has no unique constraint that can
 * enforce "a Technician may not hold two overlapping active assignments" -
 * appointment period only exists at the Booking level (bookings.
 * appointment_slot_id -> appointment_slots.starts_at/ends_at), not on
 * technician_assignments itself. Overlap is therefore detected purely at
 * the application level (see hasOverlappingAssignment()), made race-safe by
 * locking the `technicians` row FOR UPDATE before checking: any concurrent
 * assignment INSERT that references the same technician_id must first take
 * an implicit FK-parent read lock on that same `technicians` row, so it
 * blocks until this transaction commits or rolls back - at which point the
 * blocked caller re-reads and correctly sees the just-committed row. Two
 * Booking Items inside the *same* Booking (identical appointment_slot,
 * since bookings.appointment_slot_id is singular) are never treated as a
 * conflict for each other - assigning the same Technician to every item of
 * one Booking is exactly the "one technician for the whole booking" case
 * the requirements docs describe.
 */
class AssignTechnicianToBookingItemAction
{
    public function __construct(
        private readonly TransitionBookingItemStatusAction $itemLifecycle = new TransitionBookingItemStatusAction,
    ) {}

    public function assign(string $bookingItemUuid, string $technicianUuid, string $assignedByUserUuid, ?string $internalNote = null): TechnicianAssignmentResult
    {
        $itemIdBinary = UuidBinary::toBinary($bookingItemUuid);
        $technicianIdBinary = UuidBinary::toBinary($technicianUuid);
        $actorIdBinary = UuidBinary::toBinary($assignedByUserUuid);

        try {
            return DB::transaction(function () use ($itemIdBinary, $technicianIdBinary, $actorIdBinary, $internalNote): TechnicianAssignmentResult {
                $item = DB::table('booking_items')->where('id', $itemIdBinary)->lockForUpdate()->first();

                if ($item === null) {
                    return new TechnicianAssignmentResult(TechnicianAssignmentOutcome::ITEM_NOT_FOUND);
                }

                $itemStatusCode = BookingItemStatuses::code((int) $item->status_id);

                if (in_array($itemStatusCode, ['IN_PROGRESS', 'COMPLETED', 'CANCELLED'], true)) {
                    return new TechnicianAssignmentResult(TechnicianAssignmentOutcome::ITEM_NOT_ELIGIBLE, itemStatusFrom: $itemStatusCode);
                }

                if ($itemStatusCode === 'ASSIGNED') {
                    $active = $this->activePrimaryAssignment($itemIdBinary);

                    if ($active !== null && $active->technician_id === $technicianIdBinary) {
                        return new TechnicianAssignmentResult(
                            TechnicianAssignmentOutcome::ALREADY_ASSIGNED,
                            UuidBinary::toString($active->id),
                            UuidBinary::toString($itemIdBinary),
                            UuidBinary::toString($technicianIdBinary),
                        );
                    }

                    return new TechnicianAssignmentResult(
                        TechnicianAssignmentOutcome::ASSIGNED_TO_ANOTHER_TECHNICIAN,
                        previousTechnicianUuid: $active === null ? null : UuidBinary::toString($active->technician_id),
                    );
                }

                // Only PENDING_ASSIGNMENT reaches here.
                $eligibility = $this->validateEligibility($item, $technicianIdBinary, $actorIdBinary);

                if ($eligibility instanceof TechnicianAssignmentResult) {
                    return $eligibility;
                }

                [$specializationId] = $eligibility;

                $assignmentUuid = $this->writeAssignment($itemIdBinary, $technicianIdBinary, $actorIdBinary, $specializationId, $internalNote);

                $this->itemLifecycle->assign(UuidBinary::toString($itemIdBinary));

                return new TechnicianAssignmentResult(
                    TechnicianAssignmentOutcome::ASSIGNED,
                    $assignmentUuid,
                    UuidBinary::toString($itemIdBinary),
                    UuidBinary::toString($technicianIdBinary),
                    itemStatusFrom: 'PENDING_ASSIGNMENT',
                    itemStatusTo: 'ASSIGNED',
                );
            });
        } catch (UniqueConstraintViolationException) {
            return $this->resolveAssignmentRace($itemIdBinary, $technicianIdBinary);
        }
    }

    /**
     * Replaces the current active primary Technician on a Booking Item that
     * is already ASSIGNED or IN_PROGRESS. The previous assignment row is
     * never deleted - it is released (released_at/released_by_user_id/
     * release_reason) so it remains a permanent, queryable audit trail
     * (database/blue_v1_schema.sql's chk_technician_assignments_release_data
     * requires all three or none). The Booking Item's own status is never
     * changed by reassignment - an IN_PROGRESS item stays IN_PROGRESS under
     * its new Technician, never regressed back to ASSIGNED, since
     * BookingItemStatusMachine has no such reverse transition and none is
     * required by the current requirements docs.
     */
    public function reassign(string $bookingItemUuid, string $newTechnicianUuid, string $assignedByUserUuid, string $releaseReason, ?string $internalNote = null): TechnicianAssignmentResult
    {
        $itemIdBinary = UuidBinary::toBinary($bookingItemUuid);
        $technicianIdBinary = UuidBinary::toBinary($newTechnicianUuid);
        $actorIdBinary = UuidBinary::toBinary($assignedByUserUuid);

        try {
            return DB::transaction(function () use ($itemIdBinary, $technicianIdBinary, $actorIdBinary, $releaseReason, $internalNote): TechnicianAssignmentResult {
                $item = DB::table('booking_items')->where('id', $itemIdBinary)->lockForUpdate()->first();

                if ($item === null) {
                    return new TechnicianAssignmentResult(TechnicianAssignmentOutcome::ITEM_NOT_FOUND);
                }

                $itemStatusCode = BookingItemStatuses::code((int) $item->status_id);

                if (! in_array($itemStatusCode, ['ASSIGNED', 'IN_PROGRESS'], true)) {
                    return new TechnicianAssignmentResult(TechnicianAssignmentOutcome::ITEM_NOT_ELIGIBLE, itemStatusFrom: $itemStatusCode);
                }

                $active = $this->activePrimaryAssignment($itemIdBinary);

                if ($active === null) {
                    return new TechnicianAssignmentResult(TechnicianAssignmentOutcome::NO_ACTIVE_ASSIGNMENT);
                }

                if ($active->technician_id === $technicianIdBinary) {
                    return new TechnicianAssignmentResult(
                        TechnicianAssignmentOutcome::ALREADY_ASSIGNED,
                        UuidBinary::toString($active->id),
                        UuidBinary::toString($itemIdBinary),
                        UuidBinary::toString($technicianIdBinary),
                    );
                }

                $eligibility = $this->validateEligibility($item, $technicianIdBinary, $actorIdBinary);

                if ($eligibility instanceof TechnicianAssignmentResult) {
                    return $eligibility;
                }

                [$specializationId] = $eligibility;

                $now = now();
                $timestamp = $now->format('Y-m-d H:i:s.u');

                DB::table('technician_assignments')->where('id', $active->id)->update([
                    'released_at' => $timestamp,
                    'released_by_user_id' => $actorIdBinary,
                    'release_reason' => $releaseReason,
                    'updated_at' => $timestamp,
                ]);

                $assignmentUuid = $this->writeAssignment($itemIdBinary, $technicianIdBinary, $actorIdBinary, $specializationId, $internalNote, $now);

                return new TechnicianAssignmentResult(
                    TechnicianAssignmentOutcome::REASSIGNED,
                    $assignmentUuid,
                    UuidBinary::toString($itemIdBinary),
                    UuidBinary::toString($technicianIdBinary),
                    UuidBinary::toString($active->technician_id),
                    itemStatusFrom: $itemStatusCode,
                    itemStatusTo: $itemStatusCode,
                );
            });
        } catch (UniqueConstraintViolationException) {
            return $this->resolveAssignmentRace($itemIdBinary, $technicianIdBinary);
        }
    }

    /**
     * Shared assign()/reassign() precondition chain: Technician exists and
     * is currently in an is_assignable status, the actor exists and holds
     * ADMIN/SUPER_ADMIN, the service has a resolvable specialization the
     * Technician actually holds, and the Technician has no overlapping
     * active assignment on a different Booking. Returns either a rejection
     * TechnicianAssignmentResult, or a single-element array carrying the
     * resolved specializations.id to store on the new row.
     *
     * @return TechnicianAssignmentResult|array{0: int}
     */
    private function validateEligibility(object $item, string $technicianIdBinary, string $actorIdBinary): TechnicianAssignmentResult|array
    {
        $technician = DB::table('technicians')->where('id', $technicianIdBinary)->lockForUpdate()->first();

        if ($technician === null) {
            return new TechnicianAssignmentResult(TechnicianAssignmentOutcome::TECHNICIAN_NOT_FOUND);
        }

        $isAssignable = DB::table('technician_statuses')->where('id', $technician->status_id)->value('is_assignable');

        if ((int) $isAssignable !== 1) {
            return new TechnicianAssignmentResult(TechnicianAssignmentOutcome::TECHNICIAN_NOT_ELIGIBLE);
        }

        $actorExists = DB::table('users')->where('id', $actorIdBinary)->exists();

        if (! $actorExists) {
            return new TechnicianAssignmentResult(TechnicianAssignmentOutcome::ACTOR_NOT_FOUND);
        }

        $actorIsAdmin = DB::table('user_roles')
            ->join('roles', 'roles.id', '=', 'user_roles.role_id')
            ->where('user_roles.user_id', $actorIdBinary)
            ->where('roles.is_active', 1)
            ->whereIn('roles.code', ['ADMIN', 'SUPER_ADMIN'])
            ->exists();

        if (! $actorIsAdmin) {
            return new TechnicianAssignmentResult(TechnicianAssignmentOutcome::ACTOR_NOT_AUTHORIZED);
        }

        $requiredSpecializationIds = $this->requiredSpecializationIds($item->service_id);

        if ($requiredSpecializationIds === []) {
            return new TechnicianAssignmentResult(TechnicianAssignmentOutcome::SERVICE_SPECIALIZATION_NOT_CONFIGURED);
        }

        $specializationId = $this->matchTechnicianSpecialization($requiredSpecializationIds, $technicianIdBinary);

        if ($specializationId === null) {
            return new TechnicianAssignmentResult(TechnicianAssignmentOutcome::SPECIALIZATION_MISMATCH);
        }

        if ($this->hasOverlappingAssignment($item->booking_id, $technicianIdBinary)) {
            return new TechnicianAssignmentResult(TechnicianAssignmentOutcome::TECHNICIAN_DOUBLE_BOOKED);
        }

        return [$specializationId];
    }

    /**
     * The service's own active service_specializations rows, its primary
     * specialization first (the schema's own is_primary/display_order
     * ordering) - never a hardcoded service id or slug branch. An empty
     * result means the service has no specialization configured at all yet
     * (a catalog data-completeness gap - see
     * SERVICE_SPECIALIZATION_NOT_CONFIGURED), which is distinct from the
     * service having requirements a given Technician simply does not hold
     * (see matchTechnicianSpecialization()).
     *
     * @return array<int, int>
     */
    private function requiredSpecializationIds(string $serviceIdBinary): array
    {
        return DB::table('service_specializations')
            ->where('service_id', $serviceIdBinary)
            ->where('is_active', 1)
            ->orderByDesc('is_primary')
            ->orderBy('display_order')
            ->pluck('specialization_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * The first of the service's required specializations (in the service's
     * own preference order) that the Technician actually holds as an active
     * technician_specializations row - never a hardcoded service id or slug
     * branch.
     *
     * @param  array<int, int>  $requiredSpecializationIds
     */
    private function matchTechnicianSpecialization(array $requiredSpecializationIds, string $technicianIdBinary): ?int
    {
        $technicianSpecializationIds = DB::table('technician_specializations')
            ->where('technician_id', $technicianIdBinary)
            ->where('is_active', 1)
            ->pluck('specialization_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        foreach ($requiredSpecializationIds as $specializationId) {
            if (in_array($specializationId, $technicianSpecializationIds, true)) {
                return $specializationId;
            }
        }

        return null;
    }

    /**
     * True if the Technician already holds another active (released_at is
     * null) assignment on a different Booking whose appointment_slot period
     * overlaps this Booking's appointment_slot period. Cancelled Booking
     * Items and cancelled Bookings are excluded - a cancelled job no longer
     * occupies the Technician's calendar.
     */
    private function hasOverlappingAssignment(string $bookingIdBinary, string $technicianIdBinary): bool
    {
        $slot = DB::table('bookings')
            ->join('appointment_slots', 'appointment_slots.id', '=', 'bookings.appointment_slot_id')
            ->where('bookings.id', $bookingIdBinary)
            ->first(['appointment_slots.starts_at', 'appointment_slots.ends_at']);

        if ($slot === null) {
            return false;
        }

        return DB::table('technician_assignments')
            ->join('booking_items', 'booking_items.id', '=', 'technician_assignments.booking_item_id')
            ->join('bookings', 'bookings.id', '=', 'booking_items.booking_id')
            ->join('appointment_slots', 'appointment_slots.id', '=', 'bookings.appointment_slot_id')
            ->join('booking_item_statuses', 'booking_item_statuses.id', '=', 'booking_items.status_id')
            ->join('booking_statuses', 'booking_statuses.id', '=', 'bookings.status_id')
            ->where('technician_assignments.technician_id', $technicianIdBinary)
            ->whereNull('technician_assignments.released_at')
            ->where('bookings.id', '!=', $bookingIdBinary)
            ->where('booking_item_statuses.code', '!=', 'CANCELLED')
            ->where('booking_statuses.code', '!=', 'CANCELLED')
            ->where('appointment_slots.starts_at', '<', $slot->ends_at)
            ->where('appointment_slots.ends_at', '>', $slot->starts_at)
            ->exists();
    }

    private function activePrimaryAssignment(string $itemIdBinary): ?object
    {
        return DB::table('technician_assignments')
            ->where('booking_item_id', $itemIdBinary)
            ->where('is_primary', 1)
            ->whereNull('released_at')
            ->first();
    }

    private function writeAssignment(string $itemIdBinary, string $technicianIdBinary, string $actorIdBinary, int $specializationId, ?string $internalNote, ?Carbon $at = null): string
    {
        $now = $at ?? now();
        $timestamp = $now->format('Y-m-d H:i:s.u');
        $assignmentUuid = UuidBinary::generate();

        DB::table('technician_assignments')->insert([
            'id' => UuidBinary::toBinary($assignmentUuid),
            'booking_item_id' => $itemIdBinary,
            'technician_id' => $technicianIdBinary,
            'specialization_id' => $specializationId,
            'assigned_by_user_id' => $actorIdBinary,
            'is_primary' => 1,
            'assigned_at' => $timestamp,
            'internal_note' => $internalNote,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        return $assignmentUuid;
    }

    /**
     * The application-level idempotency check (activePrimaryAssignment())
     * already makes a normal retry a safe no-op before any insert is
     * attempted - this only handles the narrow window where two callers
     * evaluated eligibility concurrently and both reached the insert (never
     * possible for the *same* Booking Item, since both must hold its row
     * lock first; retained as defense-in-depth exactly like
     * CreateBookingFromSuccessfulPaymentAction::resolveInsertRace()).
     */
    private function resolveAssignmentRace(string $itemIdBinary, string $technicianIdBinary): TechnicianAssignmentResult
    {
        $existing = DB::table('technician_assignments')
            ->where('booking_item_id', $itemIdBinary)
            ->where('technician_id', $technicianIdBinary)
            ->whereNull('released_at')
            ->first();

        if ($existing !== null) {
            return new TechnicianAssignmentResult(
                TechnicianAssignmentOutcome::ALREADY_ASSIGNED,
                UuidBinary::toString($existing->id),
                UuidBinary::toString($itemIdBinary),
                UuidBinary::toString($technicianIdBinary),
            );
        }

        throw new RuntimeException('Technician assignment insert violated a unique constraint but no resolvable assignment row was found.');
    }
}
