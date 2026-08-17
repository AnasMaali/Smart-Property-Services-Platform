<?php

namespace App\Support\Contract;

use App\Support\Property\PropertyPresenter;
use App\Support\Uuid\UuidBinary;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The one safe, Flutter-facing Service Contract JSON shape (BLUE V1 Phase
 * 10D) - mirrors App\Support\Booking\BookingPresenter's conventions. Never
 * exposes `customer_user_id`, `internal_note`, `agreement_hash` (raw
 * bytes), or any raw binary(16) id.
 */
final class ContractPresenter
{
    /**
     * "Is this Contract usable right now" for display, computed purely -
     * never writes. A stored ACTIVE row whose ends_at has already passed is
     * shown as EXPIRED even before
     * App\Actions\Contract\Concerns\AppliesContractExpiry has had a
     * write-path reason to perform the real transition (see that trait's
     * docblock for why reads are never the ones to trigger the write).
     */
    public static function effectiveStatus(object $contract, CarbonInterface $now): string
    {
        $statusCode = ContractStatuses::code((int) $contract->status_id);

        if ($statusCode === 'ACTIVE' && $contract->ends_at !== null && $now->greaterThanOrEqualTo(Carbon::parse($contract->ends_at))) {
            return 'EXPIRED';
        }

        return $statusCode;
    }

    /**
     * @return array<string, mixed>
     */
    public static function summary(object $contract, CarbonInterface $now): array
    {
        return [
            'uuid' => UuidBinary::toString($contract->id),
            'contract_number' => $contract->contract_number,
            'status' => self::effectiveStatus($contract, $now),
            'starts_at' => $contract->starts_at === null ? null : Carbon::parse($contract->starts_at)->toIso8601String(),
            'ends_at' => $contract->ends_at === null ? null : Carbon::parse($contract->ends_at)->toIso8601String(),
            'requested_all_services' => (bool) $contract->requested_all_services,
            'created_at' => Carbon::parse($contract->created_at)->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function detail(object $contract, CarbonInterface $now): array
    {
        $property = DB::table('customer_properties')->where('id', $contract->customer_property_id)->first();

        $items = DB::table('service_contract_items')->where('service_contract_id', $contract->id)->orderBy('created_at')->get();
        $entitlements = (new ContractEntitlementCalculator)->summarizeMany($items);

        $currency = $contract->currency_id === null ? null : DB::table('currencies')->where('id', $contract->currency_id)->first(['code', 'symbol', 'minor_unit']);

        $bookings = DB::table('bookings')
            ->join('booking_statuses', 'booking_statuses.id', '=', 'bookings.status_id')
            ->where('bookings.service_contract_id', $contract->id)
            ->orderByDesc('bookings.created_at')
            ->get(['bookings.id', 'bookings.booking_number', 'bookings.service_contract_item_id', 'bookings.appointment_slot_id', 'booking_statuses.code as status_code', 'bookings.created_at']);

        return [
            'uuid' => UuidBinary::toString($contract->id),
            'contract_number' => $contract->contract_number,
            'status' => self::effectiveStatus($contract, $now),
            'property' => $property === null ? null : PropertyPresenter::present($property),
            'term' => [
                'starts_at' => $contract->starts_at === null ? null : Carbon::parse($contract->starts_at)->toIso8601String(),
                'ends_at' => $contract->ends_at === null ? null : Carbon::parse($contract->ends_at)->toIso8601String(),
                'term_months' => $contract->term_months === null ? null : (int) $contract->term_months,
            ],
            'quoted_amount' => $contract->quoted_amount,
            'currency' => $currency === null ? null : ['code' => $currency->code, 'symbol' => $currency->symbol, 'decimal_places' => (int) $currency->minor_unit],
            'requested_all_services' => (bool) $contract->requested_all_services,
            'customer_note' => $contract->customer_note,
            'covered_services' => $items->map(function (object $item) use ($entitlements): array {
                $entitlement = $entitlements->get(bin2hex($item->id));

                return [
                    'contract_item_uuid' => UuidBinary::toString($item->id),
                    'service' => [
                        'uuid' => UuidBinary::toString($item->service_id),
                        'code' => $item->service_code_snapshot,
                        'name' => $item->service_name_snapshot,
                    ],
                    'entitlement_mode' => $entitlement['entitlement_mode'],
                    'included_visits' => $entitlement['included_visits'],
                    'used_visits' => $entitlement['used_visits'],
                    'remaining_visits' => $entitlement['remaining_visits'],
                ];
            })->all(),
            'acceptance' => [
                'accepted' => $contract->accepted_at !== null,
                'accepted_at' => $contract->accepted_at === null ? null : Carbon::parse($contract->accepted_at)->toIso8601String(),
            ],
            'bookings' => $bookings->map(fn (object $booking): array => [
                'uuid' => UuidBinary::toString($booking->id),
                'booking_number' => $booking->booking_number,
                'status' => $booking->status_code,
                'contract_item_uuid' => UuidBinary::toString($booking->service_contract_item_id),
                'created_at' => Carbon::parse($booking->created_at)->toIso8601String(),
            ])->all(),
            'created_at' => Carbon::parse($contract->created_at)->toIso8601String(),
            'updated_at' => Carbon::parse($contract->updated_at)->toIso8601String(),
        ];
    }
}
