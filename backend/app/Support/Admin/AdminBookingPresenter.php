<?php

namespace App\Support\Admin;

use App\Support\Contract\ContractEntitlementCalculator;
use App\Support\Uuid\UuidBinary;
use App\Support\WhatsApp\WhatsAppLinkBuilder;
use App\Support\WhatsApp\WhatsAppMessagePresenter;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The Admin-facing Booking JSON shape (BLUE V1 Phase 9B) - deliberately
 * separate from App\Support\Booking\BookingPresenter rather than reusing it,
 * for two reasons: (1) an Admin list page must stay batch-loaded across many
 * Bookings at once (see presentList()), which the customer presenter's
 * per-Booking query shape does not support without N+1, and (2) the Admin
 * detail view needs operational fields (customer summary, technician
 * assignment per item, trusted payment summary) the customer presenter must
 * never expose.
 *
 * Same safety boundary as BookingPresenter: every id is a UUID string, every
 * amount a decimal string, every date ISO-8601. Never exposes
 * `payment_attempt_id`, `checkout_snapshot`/`checkout_snapshot_hash`,
 * `client_secret`, provider session/webhook internals, password hashes, or
 * any raw binary(16) id.
 */
final class AdminBookingPresenter
{
    /**
     * Batch-loaded Admin Booking list row shape - never issues a query per
     * Booking. Every row in $rows must already carry `carts.customer_user_id`
     * and `carts.currency_id as cart_currency_id` (see
     * App\Actions\Admin\Booking\AdminListBookingsAction) alongside the raw
     * `bookings` columns.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function presentList(Collection $rows): array
    {
        if ($rows->isEmpty()) {
            return [];
        }

        $bookingIds = $rows->pluck('id')->all();
        $customerIds = $rows->pluck('customer_user_id')->unique()->values()->all();
        $currencyIds = $rows->pluck('cart_currency_id')->unique()->values()->all();
        $appointmentSlotIds = $rows->pluck('appointment_slot_id')->unique()->values()->all();
        $paymentAttemptIds = $rows->pluck('payment_attempt_id')->filter()->unique()->values()->all();

        $aggregates = DB::table('booking_items')
            ->whereIn('booking_id', $bookingIds)
            ->selectRaw('booking_id, COUNT(*) as items_count, SUM(line_total_amount) as total')
            ->groupBy('booking_id')
            ->get()
            ->keyBy(fn ($row) => $row->booking_id);

        $assignedCounts = DB::table('booking_items')
            ->whereIn('booking_items.booking_id', $bookingIds)
            ->whereExists(function ($query) {
                $query->select(DB::raw(1))
                    ->from('technician_assignments')
                    ->whereColumn('technician_assignments.booking_item_id', 'booking_items.id')
                    ->whereNull('technician_assignments.released_at');
            })
            ->selectRaw('booking_id, COUNT(*) as assigned_count')
            ->groupBy('booking_id')
            ->get()
            ->keyBy(fn ($row) => $row->booking_id);

        $serviceNames = DB::table('booking_items')
            ->whereIn('booking_id', $bookingIds)
            ->orderBy('display_order')
            ->get(['booking_id', 'service_name_snapshot'])
            ->groupBy('booking_id');

        $customers = DB::table('users')
            ->join('user_profiles', 'user_profiles.user_id', '=', 'users.id')
            ->whereIn('users.id', $customerIds)
            ->get(['users.id', 'users.phone_number', 'users.email', 'user_profiles.full_name'])
            ->keyBy(fn ($row) => $row->id);

        $currencies = DB::table('currencies')
            ->whereIn('id', $currencyIds)
            ->get(['id', 'code', 'symbol', 'minor_unit'])
            ->keyBy(fn ($row) => $row->id);

        $slots = DB::table('appointment_slots')
            ->join('appointment_time_windows', 'appointment_time_windows.id', '=', 'appointment_slots.time_window_id')
            ->whereIn('appointment_slots.id', $appointmentSlotIds)
            ->get(['appointment_slots.id', 'appointment_slots.starts_at', 'appointment_slots.ends_at', 'appointment_time_windows.code as window_code', 'appointment_time_windows.name as window_name'])
            ->keyBy('id');

        $payments = $paymentAttemptIds === [] ? collect() : DB::table('payment_attempts')
            ->join('payment_statuses', 'payment_statuses.id', '=', 'payment_attempts.status_id')
            ->whereIn('payment_attempts.id', $paymentAttemptIds)
            ->get(['payment_attempts.id', 'payment_statuses.code as status_code'])
            ->keyBy('id');

        $statuses = DB::table('booking_statuses')->get(['id', 'code'])->keyBy('id');
        $sources = DB::table('booking_sources')->get(['id', 'code'])->keyBy('id');

        return $rows->map(function (object $row) use ($aggregates, $assignedCounts, $serviceNames, $customers, $currencies, $slots, $payments, $statuses, $sources): array {
            $customer = $customers->get($row->customer_user_id);
            $currency = $currencies->get($row->cart_currency_id);
            $aggregate = $aggregates->get($row->id);
            $itemsCount = $aggregate === null ? 0 : (int) $aggregate->items_count;
            $assignedCount = $assignedCounts->get($row->id)?->assigned_count ?? 0;
            $slot = $slots->get($row->appointment_slot_id);
            $payment = $row->payment_attempt_id === null ? null : $payments->get($row->payment_attempt_id);

            return [
                'uuid' => UuidBinary::toString($row->id),
                'booking_number' => $row->booking_number,
                'status' => $statuses->get($row->status_id)?->code,
                'source' => $sources->get($row->booking_source_id)?->code,
                'customer' => $customer === null ? null : [
                    'uuid' => UuidBinary::toString($customer->id),
                    'full_name' => $customer->full_name,
                    'phone_number' => $customer->phone_number,
                ],
                'currency' => $currency === null ? null : [
                    'code' => $currency->code,
                    'symbol' => $currency->symbol,
                    'decimal_places' => (int) $currency->minor_unit,
                ],
                'services' => ($serviceNames->get($row->id) ?? collect())->pluck('service_name_snapshot')->values()->all(),
                'items_count' => $itemsCount,
                'total' => $aggregate === null ? '0.000000' : (string) $aggregate->total,
                'appointment' => $slot === null ? null : self::appointmentPayload($slot),
                'payment' => $payment === null ? null : ['status' => $payment->status_code],
                'assignment_state' => self::assignmentState($itemsCount, (int) $assignedCount),
                'created_at' => Carbon::parse($row->created_at)->toIso8601String(),
                'status_changed_at' => Carbon::parse($row->status_changed_at)->toIso8601String(),
            ];
        })->all();
    }

    /**
     * Factually derived from Booking Item counts only - never a persisted
     * column, and never invents a state beyond what the existing
     * `technician_assignments`/`booking_items` rows already say: `null` for
     * a Booking with no items (should not occur for a real Booking, but
     * guarded rather than divide-by-zero), `PENDING` when zero items have
     * an active assignment, `FULL` when every item does, `PARTIAL`
     * otherwise.
     */
    private static function assignmentState(int $itemsCount, int $assignedCount): ?string
    {
        if ($itemsCount === 0) {
            return null;
        }

        if ($assignedCount === 0) {
            return 'PENDING';
        }

        return $assignedCount === $itemsCount ? 'FULL' : 'PARTIAL';
    }

    /**
     * Full Admin Booking detail shape - operational information needed to
     * actually manage the Booking: customer summary, location, appointment,
     * every Booking Item with its current + historical technician
     * assignments, and a trusted payment summary. $row must carry
     * `carts.customer_user_id` and `carts.currency_id as cart_currency_id`
     * alongside the raw `bookings` columns (see
     * App\Actions\Admin\Booking\AdminGetBookingAction).
     *
     * @return array<string, mixed>
     */
    public static function detail(object $row): array
    {
        $statusCode = DB::table('booking_statuses')->where('id', $row->status_id)->value('code');
        $currency = DB::table('currencies')->where('id', $row->cart_currency_id)->first(['code', 'symbol', 'minor_unit']);
        $sourceCode = DB::table('booking_sources')->where('id', $row->booking_source_id)->value('code');

        $contract = $row->service_contract_id === null ? null : DB::table('service_contracts')
            ->join('service_contract_statuses', 'service_contract_statuses.id', '=', 'service_contracts.status_id')
            ->where('service_contracts.id', $row->service_contract_id)
            ->first(['service_contracts.id', 'service_contracts.contract_number', 'service_contract_statuses.code as status_code']);

        $contractEntitlement = null;

        if ($contract !== null) {
            $contractItem = DB::table('service_contract_items')->where('id', $row->service_contract_item_id)->first();

            if ($contractItem !== null) {
                $contractEntitlement = (new ContractEntitlementCalculator)
                    ->summarizeMany(collect([$contractItem]))
                    ->get(bin2hex($contractItem->id));
            }
        }

        $rating = DB::table('ratings')->where('booking_id', $row->id)->first(['rating_value', 'comment', 'created_at']);

        $statusHistory = DB::table('booking_status_history')
            ->leftJoin('booking_statuses as from_status', 'from_status.id', '=', 'booking_status_history.from_status_id')
            ->join('booking_statuses as to_status', 'to_status.id', '=', 'booking_status_history.to_status_id')
            ->where('booking_status_history.booking_id', $row->id)
            ->orderByDesc('booking_status_history.changed_at')
            ->get([
                'from_status.code as from_code',
                'to_status.code as to_code',
                'booking_status_history.changed_by_user_id',
                'booking_status_history.reason',
                'booking_status_history.changed_at',
            ]);

        $customer = DB::table('users')
            ->join('user_profiles', 'user_profiles.user_id', '=', 'users.id')
            ->where('users.id', $row->customer_user_id)
            ->first(['users.id', 'users.phone_number', 'users.email', 'user_profiles.full_name']);

        $location = DB::table('booking_locations')->where('booking_id', $row->id)->first();

        $slot = DB::table('appointment_slots')
            ->join('appointment_time_windows', 'appointment_time_windows.id', '=', 'appointment_slots.time_window_id')
            ->where('appointment_slots.id', $row->appointment_slot_id)
            ->first(['appointment_slots.id', 'appointment_slots.starts_at', 'appointment_slots.ends_at', 'appointment_time_windows.code as window_code', 'appointment_time_windows.name as window_name']);

        $payment = DB::table('payment_attempts')
            ->join('payment_statuses', 'payment_statuses.id', '=', 'payment_attempts.status_id')
            ->where('payment_attempts.id', $row->payment_attempt_id)
            ->first(['payment_statuses.code as status_code', 'payment_attempts.requested_amount', 'payment_attempts.confirmed_amount', 'payment_attempts.provider_code', 'payment_attempts.provider_transaction_reference', 'payment_attempts.successful_at']);

        $items = DB::table('booking_items')
            ->join('booking_item_statuses', 'booking_item_statuses.id', '=', 'booking_items.status_id')
            ->where('booking_items.booking_id', $row->id)
            ->orderBy('booking_items.display_order')
            ->get([
                'booking_items.id',
                'booking_items.service_id',
                'booking_items.service_code_snapshot',
                'booking_items.service_name_snapshot',
                'booking_items.quantity',
                'booking_items.pricing_scheme_version_id',
                'booking_items.base_amount_snapshot',
                'booking_items.pricing_breakdown',
                'booking_items.unit_total_amount',
                'booking_items.line_total_amount',
                'booking_items.completed_at',
                'booking_items.cancelled_at',
                'booking_item_statuses.code as status_code',
            ]);

        $itemIds = $items->pluck('id')->all();

        $optionSelections = DB::table('booking_item_option_selections')
            ->whereIn('booking_item_id', $itemIds)
            ->get()
            ->groupBy('booking_item_id');

        $choiceSelections = DB::table('booking_item_option_choice_selections')
            ->whereIn('booking_item_id', $itemIds)
            ->get()
            ->groupBy('booking_item_id');

        $itemStatusHistories = DB::table('booking_item_status_history')
            ->leftJoin('booking_item_statuses as from_status', 'from_status.id', '=', 'booking_item_status_history.from_status_id')
            ->join('booking_item_statuses as to_status', 'to_status.id', '=', 'booking_item_status_history.to_status_id')
            ->whereIn('booking_item_status_history.booking_item_id', $itemIds)
            ->orderByDesc('booking_item_status_history.changed_at')
            ->get([
                'booking_item_status_history.booking_item_id',
                'from_status.code as from_code',
                'to_status.code as to_code',
                'booking_item_status_history.changed_by_user_id',
                'booking_item_status_history.reason',
                'booking_item_status_history.changed_at',
            ])
            ->groupBy('booking_item_id');

        $assignments = DB::table('technician_assignments')
            ->join('technicians', 'technicians.id', '=', 'technician_assignments.technician_id')
            ->join('specializations', 'specializations.id', '=', 'technician_assignments.specialization_id')
            ->whereIn('technician_assignments.booking_item_id', $itemIds)
            ->orderByDesc('technician_assignments.assigned_at')
            ->get([
                'technician_assignments.id',
                'technician_assignments.booking_item_id',
                'technician_assignments.assigned_at',
                'technician_assignments.released_at',
                'technician_assignments.release_reason',
                'technician_assignments.internal_note',
                'technicians.id as technician_id',
                'technicians.full_name as technician_full_name',
                'technicians.phone_number as technician_phone_number',
                'specializations.code as specialization_code',
                'specializations.name as specialization_name',
            ])
            ->groupBy(fn ($assignment) => $assignment->booking_item_id);

        $total = '0.000000';
        foreach ($items as $item) {
            $total = bcadd($total, (string) $item->line_total_amount, 6);
        }

        $whatsappContext = self::whatsappContext($row->booking_number, $customer, $location, $slot, $payment);

        return [
            'uuid' => UuidBinary::toString($row->id),
            'booking_number' => $row->booking_number,
            'status' => $statusCode,
            'source' => $sourceCode,
            'contract' => $contract === null ? null : [
                'contract_uuid' => UuidBinary::toString($contract->id),
                'contract_number' => $contract->contract_number,
                'contract_item_uuid' => UuidBinary::toString($row->service_contract_item_id),
                'status' => $contract->status_code,
                'entitlement' => $contractEntitlement === null ? null : [
                    'entitlement_mode' => $contractEntitlement['entitlement_mode'],
                    'included_visits' => $contractEntitlement['included_visits'],
                    'used_visits' => $contractEntitlement['used_visits'],
                    'remaining_visits' => $contractEntitlement['remaining_visits'],
                ],
            ],
            'rating' => $rating === null ? null : [
                'rating_value' => (int) $rating->rating_value,
                'comment' => $rating->comment,
                'created_at' => Carbon::parse($rating->created_at)->toIso8601String(),
            ],
            'customer' => $customer === null ? null : [
                'uuid' => UuidBinary::toString($customer->id),
                'full_name' => $customer->full_name,
                'phone_number' => $customer->phone_number,
                'email' => $customer->email,
            ],
            'currency' => $currency === null ? null : [
                'code' => $currency->code,
                'symbol' => $currency->symbol,
                'decimal_places' => (int) $currency->minor_unit,
            ],
            'total' => $total,
            'payment' => $payment === null ? null : [
                'uuid' => UuidBinary::toString($row->payment_attempt_id),
                'status' => $payment->status_code,
                'amount' => (string) ($payment->confirmed_amount ?? $payment->requested_amount),
                'provider' => $payment->provider_code,
                'reference' => $payment->provider_transaction_reference,
                'successful_at' => $payment->successful_at === null ? null : Carbon::parse($payment->successful_at)->toIso8601String(),
            ],
            'location' => $location === null ? null : self::locationPayload($location),
            'appointment' => $slot === null ? null : self::appointmentPayload($slot),
            'items' => $items->map(fn ($item) => self::itemPayload(
                $item,
                $assignments->get($item->id, collect()),
                $optionSelections->get($item->id, collect()),
                $choiceSelections->get($item->id, collect()),
                $itemStatusHistories->get($item->id, collect()),
                $whatsappContext,
            ))->all(),
            'status_history' => self::statusHistoryPayload($statusHistory),
            'created_at' => Carbon::parse($row->created_at)->toIso8601String(),
            'status_changed_at' => Carbon::parse($row->status_changed_at)->toIso8601String(),
            'completed_at' => $row->completed_at === null ? null : Carbon::parse($row->completed_at)->toIso8601String(),
            'cancelled_at' => $row->cancelled_at === null ? null : Carbon::parse($row->cancelled_at)->toIso8601String(),
            'refund_due' => $statusCode === 'CANCELLED' ? self::refundDuePayload($row) : null,
        ];
    }

    /**
     * Shared shape for both Booking-level and Booking-Item-level status
     * history (`booking_status_history` / `booking_item_status_history`) -
     * mirrors App\Support\Admin\AdminContractPresenter's status_history
     * exactly: the actor is exposed only as a UUID, never a resolved name,
     * consistent with that existing precedent.
     *
     * @return array<int, array<string, mixed>>
     */
    private static function statusHistoryPayload(Collection $history): array
    {
        return $history->map(fn (object $entry): array => [
            'from_status' => $entry->from_code,
            'to_status' => $entry->to_code,
            'changed_by_user_uuid' => $entry->changed_by_user_id === null ? null : UuidBinary::toString($entry->changed_by_user_id),
            'reason' => $entry->reason,
            'changed_at' => Carbon::parse($entry->changed_at)->toIso8601String(),
        ])->values()->all();
    }

    /**
     * Refund eligibility for an already-CANCELLED Booking only - identical
     * to (and never a separate implementation from)
     * App\Support\Booking\BookingPresenter::refundDuePayload(): the
     * percentage/amount are the frozen policy snapshot
     * App\Actions\Booking\CancelBookingAction persisted at cancellation
     * time, and the execution status/provider fields (BLUE V1 Phase B20)
     * are read live from the `booking_refunds` obligation row - never
     * promised as already returned while still PENDING.
     *
     * failure_code/failure_message are included here (Admin-only - never on
     * the customer-facing App\Support\Booking\BookingPresenter equivalent)
     * since a FAILED (or fix-phase-2 RECONCILIATION_REQUIRED - an
     * authoritative Stripe webhook whose amount/currency did not match the
     * obligation; BLUE V1 is AED-only, so this is always an anomaly, never
     * a currency the platform legitimately supports) refund is exactly the
     * case an Admin needs to see and may need to act on; both are already
     * safe, provider-neutral strings this Action itself wrote
     * (StripePaymentGateway::classifyRefundFailure /
     * ProcessPaymentWebhookAction::processRefundEvent), never a raw
     * exception message or Stripe object. `status` for
     * RECONCILIATION_REQUIRED must be rendered by the Admin UI as clearly
     * distinct from ordinary PENDING/processing - see
     * resources/js/admin/bookings/show.js.
     *
     * @return array{percentage: int, amount: string, execution: string, status: ?string, provider: ?string, provider_refund_reference: ?string, requested_at: ?string, succeeded_at: ?string, failed_at: ?string, failure_code: ?string, failure_message: ?string}|null
     */
    private static function refundDuePayload(object $row): ?array
    {
        if ($row->cancellation_refund_percentage === null || $row->cancellation_refund_amount === null) {
            return null;
        }

        $refundRow = DB::table('booking_refunds')->where('booking_id', $row->id)->first();

        return [
            'percentage' => (int) $row->cancellation_refund_percentage,
            'amount' => (string) $row->cancellation_refund_amount,
            'execution' => 'STRIPE_AUTOMATIC',
            'status' => $refundRow === null ? null : DB::table('booking_refund_statuses')->where('id', $refundRow->status_id)->value('code'),
            'provider' => $refundRow?->provider_code,
            'provider_refund_reference' => $refundRow?->provider_refund_reference,
            'requested_at' => $refundRow === null ? null : Carbon::parse($refundRow->requested_at)->toIso8601String(),
            'succeeded_at' => $refundRow?->succeeded_at === null ? null : Carbon::parse($refundRow->succeeded_at)->toIso8601String(),
            'failed_at' => $refundRow?->failed_at === null ? null : Carbon::parse($refundRow->failed_at)->toIso8601String(),
            'failure_code' => $refundRow?->failure_code,
            'failure_message' => $refundRow?->failure_message,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function locationPayload(object $location): array
    {
        return [
            'property_type_name' => $location->property_type_name_snapshot,
            'other_property_type_name' => $location->other_property_type_name,
            'country_name' => $location->country_name_snapshot,
            'city_name' => $location->city_name_snapshot,
            'area_name' => $location->area_name_snapshot,
            'street_name' => $location->street_name,
            'address_line' => $location->address_line,
            'building_name_or_number' => $location->building_name_or_number,
            'floor_number' => $location->floor_number,
            'unit_number' => $location->unit_number,
            'nearby_landmark' => $location->nearby_landmark,
            'additional_location_notes' => $location->additional_location_notes,
            'visit_contact_phone' => $location->visit_contact_phone,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function appointmentPayload(object $slot): array
    {
        return [
            'slot' => [
                'uuid' => UuidBinary::toString($slot->id),
                'starts_at' => Carbon::parse($slot->starts_at)->toIso8601String(),
                'ends_at' => Carbon::parse($slot->ends_at)->toIso8601String(),
                'time_window' => [
                    'code' => $slot->window_code,
                    'name' => $slot->window_name,
                ],
            ],
        ];
    }

    /**
     * @param  array<string, string|null>  $whatsappContext  BLUE V1 Simple WhatsApp Handoff -
     *                                                       the Booking-level fields (customer/
     *                                                       location/appointment/paid-amount)
     *                                                       every WhatsApp message on this
     *                                                       Booking shares - see whatsappContext().
     * @return array<string, mixed>
     */
    private static function itemPayload(
        object $item,
        Collection $assignments,
        Collection $optionSelections,
        Collection $choiceSelections,
        Collection $statusHistory,
        array $whatsappContext,
    ): array {
        $active = $assignments->first(fn ($assignment) => $assignment->released_at === null);

        // BLUE V1 Simple WhatsApp Handoff - everything both the Technician
        // and Customer message templates need for THIS item, minus
        // technician_name (which varies per assignment - see
        // assignmentPayload()).
        $itemWhatsappFields = array_merge($whatsappContext, [
            'service_name' => $item->service_name_snapshot,
            'service_details' => self::serviceWhatsappDetails($item, $optionSelections, $choiceSelections),
        ]);

        // A Booking Item with more than one assignment row has been
        // reassigned at least once - the customer message must then read
        // as an UPDATE, not a first-time assignment (BLUE V1 Simple
        // WhatsApp Handoff spec section 7).
        $isReassignment = $assignments->count() > 1;

        return [
            'uuid' => UuidBinary::toString($item->id),
            'service' => [
                'uuid' => UuidBinary::toString($item->service_id),
                'code' => $item->service_code_snapshot,
                'name' => $item->service_name_snapshot,
            ],
            'quantity' => (int) $item->quantity,
            'status' => $item->status_code,
            'completed_at' => $item->completed_at === null ? null : Carbon::parse($item->completed_at)->toIso8601String(),
            'cancelled_at' => $item->cancelled_at === null ? null : Carbon::parse($item->cancelled_at)->toIso8601String(),
            'pricing' => [
                'pricing_scheme_version_uuid' => UuidBinary::toString($item->pricing_scheme_version_id),
                'base_amount' => $item->base_amount_snapshot,
                'adjustments' => json_decode((string) $item->pricing_breakdown, true) ?? [],
                'unit_total' => $item->unit_total_amount,
                'line_total' => $item->line_total_amount,
            ],
            'selected_options' => $optionSelections->map(fn (object $selection): array => [
                'option_name' => $selection->option_name_snapshot,
                'option_type' => $selection->option_type_code_snapshot,
                'numeric_value' => $selection->numeric_value,
                'boolean_value' => $selection->boolean_value === null ? null : (bool) $selection->boolean_value,
                'measurement_unit_symbol' => $selection->measurement_unit_symbol_snapshot,
                'additional_amount' => $selection->additional_unit_amount_snapshot,
            ])->values()->all(),
            'selected_choices' => $choiceSelections->map(fn (object $choice): array => [
                'option_name' => $choice->option_name_snapshot,
                'choice_name' => $choice->choice_name_snapshot,
                'choice_description' => $choice->choice_description_snapshot,
                'additional_amount' => $choice->additional_unit_amount_snapshot,
            ])->values()->all(),
            'active_assignment' => $active === null ? null : self::assignmentPayload($active, $itemWhatsappFields),
            'assignment_history' => $assignments->values()->map(fn ($assignment) => self::assignmentPayload($assignment, $itemWhatsappFields))->all(),
            'customer_whatsapp' => $active === null ? null : self::customerWhatsappPayload($active, $itemWhatsappFields, $isReassignment),
            'status_history' => self::statusHistoryPayload($statusHistory),
        ];
    }

    /**
     * @param  array<string, string|null>  $itemWhatsappFields
     * @return array<string, mixed>
     */
    private static function assignmentPayload(object $assignment, array $itemWhatsappFields): array
    {
        return [
            'uuid' => UuidBinary::toString($assignment->id),
            'technician' => [
                'uuid' => UuidBinary::toString($assignment->technician_id),
                'full_name' => $assignment->technician_full_name,
                'phone_number' => $assignment->technician_phone_number,
            ],
            'specialization' => [
                'code' => $assignment->specialization_code,
                'name' => $assignment->specialization_name,
            ],
            'assigned_at' => Carbon::parse($assignment->assigned_at)->toIso8601String(),
            'released_at' => $assignment->released_at === null ? null : Carbon::parse($assignment->released_at)->toIso8601String(),
            'release_reason' => $assignment->release_reason,
            'internal_note' => $assignment->internal_note,
            'whatsapp' => self::technicianWhatsappPayload($assignment, $itemWhatsappFields),
        ];
    }

    /**
     * BLUE V1 Simple WhatsApp Handoff - `null` (never a broken/unsafe URL)
     * when the Technician's phone is missing/not valid E.164, or when the
     * appointment context could not be resolved (a data-integrity
     * anomaly, not the normal case - `bookings.appointment_slot_id` is
     * required by schema). An active assignment gets the NEW ASSIGNMENT
     * message; a released one gets the REMOVAL message (which never
     * reveals the replacement Technician - it only ever needs the booking
     * number).
     *
     * @param  array<string, string|null>  $itemWhatsappFields
     * @return array{message: string, url: string}|null
     */
    private static function technicianWhatsappPayload(object $assignment, array $itemWhatsappFields): ?array
    {
        if ($assignment->released_at !== null) {
            $message = WhatsAppMessagePresenter::technicianRemoved($itemWhatsappFields['booking_number']);

            return WhatsAppLinkBuilder::build($assignment->technician_phone_number, $message);
        }

        if ($itemWhatsappFields['starts_at'] === null) {
            return null;
        }

        $fields = array_merge($itemWhatsappFields, ['technician_name' => $assignment->technician_full_name]);
        $message = WhatsAppMessagePresenter::technicianNewAssignment($fields);

        return WhatsAppLinkBuilder::build($assignment->technician_phone_number, $message);
    }

    /**
     * BLUE V1 Simple WhatsApp Handoff - the "Message customer" handoff for
     * the ACTIVE Technician on this item only (a released assignment has
     * no customer-facing counterpart - the customer is never told about
     * an intermediate Technician they were never actually informed of
     * beyond the current one).
     *
     * @param  array<string, string|null>  $itemWhatsappFields
     * @return array{message: string, url: string}|null
     */
    private static function customerWhatsappPayload(object $activeAssignment, array $itemWhatsappFields, bool $isReassignment): ?array
    {
        if ($itemWhatsappFields['starts_at'] === null || $itemWhatsappFields['customer_phone'] === null) {
            return null;
        }

        $fields = array_merge($itemWhatsappFields, ['technician_name' => $activeAssignment->technician_full_name]);

        $message = $isReassignment
            ? WhatsAppMessagePresenter::customerChanged($fields)
            : WhatsAppMessagePresenter::customerAssigned($fields);

        return WhatsAppLinkBuilder::build($itemWhatsappFields['customer_phone'], $message);
    }

    /**
     * BLUE V1 Simple WhatsApp Handoff - the Booking-level fields shared by
     * every Technician/Customer WhatsApp message on this Booking, resolved
     * ONCE here from data `detail()` already fetched (never a second
     * query, never anything client-supplied). `paid_amount` is the
     * authoritative historical Booking/payment snapshot (`payment_attempts.
     * confirmed_amount ?? requested_amount`) - `null` only for a
     * contract-billed Booking (no `payment_attempt_id` at all), never
     * recomputed from the Service's current live price.
     *
     * @return array<string, string|null>
     */
    private static function whatsappContext(string $bookingNumber, ?object $customer, ?object $location, ?object $slot, ?object $payment): array
    {
        return [
            'booking_number' => $bookingNumber,
            'customer_name' => $customer?->full_name ?? 'Customer',
            'customer_phone' => $customer?->phone_number,
            'visit_contact_phone' => (string) ($location?->visit_contact_phone ?? ''),
            'starts_at' => $slot?->starts_at,
            'ends_at' => $slot?->ends_at,
            'time_window' => $slot?->window_name,
            'property_type' => (string) ($location?->property_type_name_snapshot ?? ''),
            'building' => (string) ($location?->building_name_or_number ?? ''),
            'floor' => (string) ($location?->floor_number ?? ''),
            'unit' => (string) ($location?->unit_number ?? ''),
            'street' => (string) ($location?->street_name ?? ''),
            'area' => (string) ($location?->area_name_snapshot ?? ''),
            'city' => (string) ($location?->city_name_snapshot ?? ''),
            'landmark' => (string) ($location?->nearby_landmark ?? ''),
            'location_notes' => (string) ($location?->additional_location_notes ?? ''),
            'paid_amount' => $payment === null ? null : (string) ($payment->confirmed_amount ?? $payment->requested_amount),
        ];
    }

    /**
     * "2x Deep clean" plus a short, clean summary of selected options/
     * choices - never blank/ugly placeholder text when none exist. Built
     * from the already-fetched $optionSelections/$choiceSelections
     * Collections detail() batch-loaded - never a second per-item query.
     */
    private static function serviceWhatsappDetails(object $item, Collection $optionSelections, Collection $choiceSelections): string
    {
        $parts = [((int) $item->quantity).'x '.$item->service_name_snapshot];

        $extras = array_merge(
            $optionSelections->pluck('option_name_snapshot')->all(),
            $choiceSelections->pluck('choice_name_snapshot')->all(),
        );

        if ($extras !== []) {
            $parts[] = implode(', ', $extras);
        }

        return implode(' - ', $parts);
    }
}
