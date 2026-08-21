<?php

namespace App\Actions\Contract;

use App\Actions\Contract\Concerns\AppliesContractExpiry;
use App\Support\Auth\PendingAccountDeletionGuard;
use App\Support\Booking\BookingItemStatuses;
use App\Support\Booking\BookingNumberGenerator;
use App\Support\Booking\BookingPresenter;
use App\Support\Booking\BookingSources;
use App\Support\Booking\BookingStatuses;
use App\Support\Cart\CartStatuses;
use App\Support\Cart\Concerns\BuildsCartResult;
use App\Support\Contract\Billing\ContractBillingStatuses;
use App\Support\Contract\ContractStatusMachine;
use App\Support\Payment\CanonicalJson;
use App\Support\Pricing\PricingSchemeRepository;
use App\Support\Pricing\PricingSchemeSelector;
use App\Support\Uuid\UuidBinary;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

/**
 * The one canonical entry point for consuming an active Service Contract
 * entitlement into a real, fulfillable Booking (POST
 * /v1/contracts/{contract}/services/{contractItem}/book, BLUE V1 Phase
 * 10F). Never creates a customer PaymentAttempt and never fakes a
 * zero-value one - `bookings.payment_attempt_id` is simply left NULL, with
 * `booking_source_id` = CONTRACT and `service_contract_id` /
 * `service_contract_item_id` populated instead (see
 * chk_bookings_source_pairing in database/blue_v1_schema.sql).
 *
 * Reuses the existing Booking / Booking Item fulfillment system completely
 * unchanged rather than inventing a parallel job system: this Action still
 * creates a real `carts` + `cart_items` + `appointment_holds` row (the same
 * capacity-protected slot-holding mechanism
 * App\Actions\Checkout\CreateAppointmentHoldAction uses), immediately
 * "converted" since there is no separate payment step to wait for. The
 * resulting `bookings` / `booking_items` rows are indistinguishable in
 * shape from a STANDARD Booking to every downstream Action (technician
 * assignment, start/complete work, App\Actions\Booking\
 * SyncBookingStatusFromItemsAction, App\Actions\Booking\CancelBookingAction)
 * - only `booking_source_id` and the two contract columns differ. The
 * Booking Item's price is recorded as 0.000000 - not a fake payment, simply
 * the true fact that no further amount is due for a visit already paid for
 * by the Contract.
 *
 * BLUE V1 Phase 11 adds one more precondition between the term check and
 * the entitlement item lookup: `service_contract_billings.status_id` must
 * currently authorize new Bookings (App\Support\Contract\Billing\
 * ContractBillingStatuses::authorizesBooking() - ACTIVE or
 * CANCEL_AT_PERIOD_END only). A Contract's operational status alone is no
 * longer sufficient - PENDING_PAYMENT, INCOMPLETE, and PAST_DUE billing all
 * block new CONTRACT Bookings even though the Contract row itself may
 * already read ACTIVE (PAST_DUE in particular: the Contract is deliberately
 * NOT suspended on the first failed renewal - see
 * App\Actions\Contract\Billing\SuspendContractsPastDueBillingAction's
 * grace-period docblock - so this booking-time billing check is the actual
 * enforcement point, not a Contract-status transition). This billing read
 * is `lockForUpdate()`, exactly mirroring App\Actions\Contract\Billing\
 * ProcessContractBillingWebhookAction's own SERVICE_CONTRACT ->
 * SERVICE_CONTRACT_BILLING lock order (a strict prefix of this Action's own
 * chain below, so the two can never deadlock against each other) - a
 * concurrent webhook delivery that is mid-transaction degrading this same
 * billing row (e.g. an in-flight invoice.payment_failed) is therefore
 * either fully applied before this read (this Action then correctly sees
 * and enforces the new status) or fully after it (serialized behind this
 * Action's own row lock) - never observed half-applied or via a stale
 * snapshot.
 *
 * Lock order: (BLUE V1 Phase 13) USER -> SERVICE_CONTRACT ->
 * SERVICE_CONTRACT_BILLING -> SERVICE_CONTRACT_ITEM -> (new) CART ->
 * APPOINTMENT_SLOT -> APPOINTMENT_HOLD -> BOOKING. The USER lock exists
 * solely for PendingAccountDeletionGuard's race-freedom (see that class's
 * docblock) and is otherwise inert here - this Action still never reads
 * or mutates anything about the user row itself. Below USER, this chain
 * is disjoint from
 * every existing lock chain: SERVICE_CONTRACT/SERVICE_CONTRACT_ITEM are a
 * new root nothing else in the codebase locks, and the CART this Action
 * locks is one it creates itself inside this same transaction - no other
 * transaction can reference it before it commits, so there is no possible
 * lock-order cycle with
 * App\Actions\Checkout\CreateAppointmentHoldAction's USER -> CART -> SLOT ->
 * HOLD chain or App\Actions\Booking\CreateBookingFromSuccessfulPaymentAction's
 * PAYMENT_ATTEMPT -> CART -> APPOINTMENT_HOLD chain.
 *
 * Entitlement race-safety ("concurrent booking attempts cannot oversubscribe
 * the last included visit"): the `service_contract_items` row is locked FOR
 * UPDATE before the LIMITED_VISITS count is taken, and the count itself is
 * also a FOR UPDATE read (mirroring
 * App\Actions\Checkout\CreateAppointmentHoldAction's own capacity-count
 * pattern) rather than a plain SELECT, so it is never left to InnoDB
 * snapshot-timing subtlety. Two concurrent requests for the same item's
 * last visit serialize on that row lock: the first to commit its new
 * Booking is counted by the second, which then correctly rejects. "Used
 * visits" is deliberately never a separate mutable counter - it is always
 * derived from real, non-CANCELLED `bookings` rows (see
 * App\Support\Contract\ContractEntitlementCalculator's docblock for why a
 * counter/ledger table was judged unnecessary), which also means a
 * cancelled CONTRACT Booking automatically and permanently frees its visit
 * with zero extra bookkeeping, and a retried book() call can never
 * "double-consume" anything it didn't itself insert - every call inserts at
 * most one new Booking, counted once.
 */
class CreateContractBookingAction
{
    use AppliesContractExpiry;
    use BuildsCartResult;

    public function __construct(
        private readonly ContractStatusMachine $contractMachine = new ContractStatusMachine,
        private readonly PricingSchemeRepository $schemeRepository = new PricingSchemeRepository,
        private readonly PricingSchemeSelector $schemeSelector = new PricingSchemeSelector,
        private readonly PendingAccountDeletionGuard $deletionGuard = new PendingAccountDeletionGuard,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function handle(string $userUuid, string $contractUuid, string $contractItemUuid, string $appointmentSlotUuid): array
    {
        try {
            $contractIdBinary = UuidBinary::toBinary($contractUuid);
            $contractItemIdBinary = UuidBinary::toBinary($contractItemUuid);
        } catch (InvalidArgumentException) {
            return $this->notFound('Service contract not found.');
        }

        try {
            $slotIdBinary = UuidBinary::toBinary($appointmentSlotUuid);
        } catch (InvalidArgumentException) {
            return $this->notFound('Appointment slot not found.');
        }

        $userIdBinary = UuidBinary::toBinary($userUuid);

        return DB::transaction(function () use ($contractIdBinary, $contractItemIdBinary, $slotIdBinary, $userIdBinary): array {
            // BLUE V1 Phase 13: locks `users` first, ahead of the
            // SERVICE_CONTRACT root below, purely to make the
            // PendingAccountDeletionGuard check race-free against
            // DeleteAccountAction's own `users` lock - see that guard's
            // class docblock. This is additive to, not a replacement for,
            // this Action's own documented lock chain.
            $userExists = DB::table('users')->where('id', $userIdBinary)->lockForUpdate()->exists();

            if (! $userExists) {
                throw new RuntimeException('Authenticated user with binary id not found.');
            }

            if ($this->deletionGuard->isPending($userIdBinary)) {
                return $this->conflict(PendingAccountDeletionGuard::REJECTION_MESSAGE);
            }

            $contract = DB::table('service_contracts')
                ->where('id', $contractIdBinary)
                ->where('customer_user_id', $userIdBinary)
                ->lockForUpdate()
                ->first();

            if ($contract === null) {
                return $this->notFound('Service contract not found.');
            }

            $now = now();
            $contract = $this->applyLazyExpiry($contract, $now);

            if (! $this->contractMachine->isInStatus($contract, 'ACTIVE')) {
                return $this->conflict('This contract is not active.');
            }

            if ($contract->starts_at === null || $now->lessThan(Carbon::parse($contract->starts_at))) {
                return $this->conflict("This contract's term has not started yet.");
            }

            $billing = DB::table('service_contract_billings')->where('service_contract_id', $contract->id)->lockForUpdate()->first(['status_id']);

            if ($billing === null || ! ContractBillingStatuses::authorizesBooking(ContractBillingStatuses::code((int) $billing->status_id))) {
                return $this->conflict('This contract\'s billing is not currently active.');
            }

            $item = DB::table('service_contract_items')
                ->where('id', $contractItemIdBinary)
                ->where('service_contract_id', $contract->id)
                ->lockForUpdate()
                ->first();

            if ($item === null) {
                return $this->notFound('Contract service not found.');
            }

            if ($item->entitlement_mode === 'LIMITED_VISITS') {
                $usedVisits = DB::table('bookings')
                    ->join('booking_statuses', 'booking_statuses.id', '=', 'bookings.status_id')
                    ->where('bookings.service_contract_item_id', $item->id)
                    ->where('booking_statuses.code', '!=', 'CANCELLED')
                    ->lockForUpdate()
                    ->count();

                if ($usedVisits >= (int) $item->included_visits) {
                    return $this->conflict('No remaining visits for this service under the contract.');
                }
            }

            $slot = DB::table('appointment_slots')
                ->join('appointment_time_windows', 'appointment_time_windows.id', '=', 'appointment_slots.time_window_id')
                ->where('appointment_slots.id', $slotIdBinary)
                ->where('appointment_slots.is_active', 1)
                ->where('appointment_time_windows.is_active', 1)
                ->lockForUpdate()
                ->first(['appointment_slots.*']);

            if ($slot === null) {
                return $this->notFound('Appointment slot not found.');
            }

            if (Carbon::parse($slot->starts_at)->lessThanOrEqualTo($now)) {
                return $this->unprocessable('This appointment slot has already passed.');
            }

            $occupied = DB::table('appointment_holds')
                ->where('appointment_slot_id', $slotIdBinary)
                ->whereNull('released_at')
                ->where(function ($query) use ($now) {
                    $query->whereNotNull('converted_at')->orWhere('expires_at', '>', $now);
                })
                ->lockForUpdate()
                ->count();

            if ($occupied >= (int) $slot->booking_capacity) {
                return $this->unprocessable('This appointment slot is fully booked.');
            }

            $currency = DB::table('currencies')->where('is_active', 1)->orderBy('id')->first(['id', 'code']);

            if ($currency === null) {
                return $this->conflict('No active currency is configured.');
            }

            $schemeVersions = $this->schemeRepository->schemeVersionsFor($item->service_id, (int) $currency->id);
            $currentScheme = $this->schemeSelector->selectCurrent($schemeVersions, $now);

            if ($currentScheme === null) {
                return $this->conflict('This service is not currently priced and cannot be booked.');
            }

            $property = DB::table('customer_properties')->where('id', $contract->customer_property_id)->first();

            if ($property === null) {
                return $this->conflict('This contract\'s property could not be found.');
            }

            $location = $this->resolveLocationSnapshot($property);

            if ($location === null) {
                return $this->conflict('This contract\'s property location could not be resolved.');
            }

            $timestamp = $now->format('Y-m-d H:i:s.u');

            $cartIdBinary = UuidBinary::toBinary(UuidBinary::generate());

            DB::table('carts')->insert([
                'id' => $cartIdBinary,
                'customer_user_id' => $userIdBinary,
                'status_id' => CartStatuses::id('CONVERTED'),
                'currency_id' => $currency->id,
                'last_activity_at' => $timestamp,
                'status_changed_at' => $timestamp,
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ]);

            $cartItemIdBinary = UuidBinary::toBinary(UuidBinary::generate());

            DB::table('cart_items')->insert([
                'id' => $cartItemIdBinary,
                'cart_id' => $cartIdBinary,
                'service_id' => $item->service_id,
                'quantity' => 1,
                'display_order' => 0,
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ]);

            $ttlMinutes = (int) config('checkout.appointment_hold_ttl_minutes');

            DB::table('appointment_holds')->insert([
                'id' => UuidBinary::toBinary(UuidBinary::generate()),
                'cart_id' => $cartIdBinary,
                'appointment_slot_id' => $slotIdBinary,
                'held_at' => $timestamp,
                'expires_at' => $now->copy()->addMinutes($ttlMinutes)->format('Y-m-d H:i:s.u'),
                'converted_at' => $timestamp,
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ]);

            $bookingIdBinary = UuidBinary::toBinary(UuidBinary::generate());
            $paidStatusId = BookingStatuses::id('PAID');

            DB::table('bookings')->insert([
                'id' => $bookingIdBinary,
                'booking_number' => BookingNumberGenerator::generate(),
                'cart_id' => $cartIdBinary,
                'payment_attempt_id' => null,
                'booking_source_id' => BookingSources::id('CONTRACT'),
                'service_contract_id' => $contract->id,
                'service_contract_item_id' => $item->id,
                'appointment_slot_id' => $slotIdBinary,
                'status_id' => $paidStatusId,
                'status_changed_at' => $timestamp,
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ]);

            DB::table('booking_status_history')->insert([
                'id' => UuidBinary::toBinary(UuidBinary::generate()),
                'booking_id' => $bookingIdBinary,
                'from_status_id' => null,
                'to_status_id' => $paidStatusId,
                'changed_by_user_id' => $userIdBinary,
                'reason' => 'Created from an active service contract entitlement.',
                'changed_at' => $timestamp,
            ]);

            DB::table('booking_locations')->insert([
                'booking_id' => $bookingIdBinary,
                ...$location,
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ]);

            DB::table('booking_items')->insert([
                'id' => UuidBinary::toBinary(UuidBinary::generate()),
                'booking_id' => $bookingIdBinary,
                'source_cart_item_id' => $cartItemIdBinary,
                'service_id' => $item->service_id,
                'pricing_scheme_version_id' => UuidBinary::toBinary($currentScheme['id']),
                'status_id' => BookingItemStatuses::id('PENDING_ASSIGNMENT'),
                'service_code_snapshot' => $item->service_code_snapshot,
                'service_name_snapshot' => $item->service_name_snapshot,
                'quantity' => 1,
                'pricing_status_snapshot' => 'PRICED',
                'base_amount_snapshot' => null,
                'pricing_breakdown' => CanonicalJson::encode(['source' => 'CONTRACT', 'contract_number' => $contract->contract_number]),
                'unit_total_amount' => '0.000000',
                'line_total_amount' => '0.000000',
                'display_order' => 0,
                'status_changed_at' => $timestamp,
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ]);

            $row = DB::table('bookings')
                ->join('carts', 'carts.id', '=', 'bookings.cart_id')
                ->where('bookings.id', $bookingIdBinary)
                ->first(['bookings.*', 'carts.currency_id as cart_currency_id']);

            return $this->ok(201, 'Booking created from service contract entitlement.', ['booking' => BookingPresenter::present($row)]);
        });
    }

    /**
     * @return array<string, mixed>|null
     */
    private function resolveLocationSnapshot(object $property): ?array
    {
        $propertyTypeName = DB::table('property_types')->where('id', $property->property_type_id)->value('name');

        $area = DB::table('areas')
            ->join('cities', 'cities.id', '=', 'areas.city_id')
            ->join('countries', 'countries.id', '=', 'cities.country_id')
            ->where('areas.id', $property->area_id)
            ->first(['areas.name as area_name', 'cities.name as city_name', 'countries.name as country_name']);

        if ($propertyTypeName === null || $area === null) {
            return null;
        }

        return [
            'property_type_id' => $property->property_type_id,
            'area_id' => $property->area_id,
            'property_type_name_snapshot' => $propertyTypeName,
            'country_name_snapshot' => $area->country_name,
            'city_name_snapshot' => $area->city_name,
            'area_name_snapshot' => $area->area_name,
            'other_property_type_name' => $property->other_property_type_name,
            'street_name' => $property->street_name,
            'address_line' => $property->address_line,
            'building_name_or_number' => $property->building_name_or_number,
            'floor_number' => $property->floor_number,
            'unit_number' => $property->unit_number,
            'nearby_landmark' => $property->nearby_landmark,
            'additional_location_notes' => $property->additional_location_notes,
            'visit_contact_phone' => $property->visit_contact_phone,
        ];
    }
}
