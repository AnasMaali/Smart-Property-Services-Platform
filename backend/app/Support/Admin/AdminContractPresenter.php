<?php

namespace App\Support\Admin;

use App\Support\Contract\ContractEntitlementCalculator;
use App\Support\Contract\ContractPresenter;
use App\Support\Property\PropertyPresenter;
use App\Support\Uuid\UuidBinary;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The Admin-facing Service Contract JSON shape (BLUE V1 Phase 10E) -
 * deliberately separate from App\Support\Contract\ContractPresenter, for the
 * same reasons App\Support\Admin\AdminBookingPresenter is separate from
 * App\Support\Booking\BookingPresenter: a batch-loaded list shape
 * (presentList()), and operational fields (internal_note, the customer's
 * identity, the raw requested-service snapshot) the customer presenter must
 * never expose. Never exposes password hashes, agreement_hash raw bytes, or
 * any raw binary(16) id.
 */
final class AdminContractPresenter
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public static function presentList(Collection $rows): array
    {
        if ($rows->isEmpty()) {
            return [];
        }

        $now = now();
        $customerIds = $rows->pluck('customer_user_id')->unique()->values()->all();

        $customers = DB::table('users')
            ->join('user_profiles', 'user_profiles.user_id', '=', 'users.id')
            ->whereIn('users.id', $customerIds)
            ->get(['users.id', 'users.phone_number', 'user_profiles.full_name'])
            ->keyBy(fn ($row) => $row->id);

        $itemCounts = DB::table('service_contract_items')
            ->whereIn('service_contract_id', $rows->pluck('id')->all())
            ->select('service_contract_id', DB::raw('COUNT(*) as items_count'))
            ->groupBy('service_contract_id')
            ->pluck('items_count', 'service_contract_id');

        return $rows->map(function (object $row) use ($customers, $itemCounts, $now): array {
            $customer = $customers->get($row->customer_user_id);

            return [
                'uuid' => UuidBinary::toString($row->id),
                'contract_number' => $row->contract_number,
                'status' => ContractPresenter::effectiveStatus($row, $now),
                'customer' => $customer === null ? null : [
                    'uuid' => UuidBinary::toString($customer->id),
                    'full_name' => $customer->full_name,
                    'phone_number' => $customer->phone_number,
                ],
                'items_count' => (int) ($itemCounts[$row->id] ?? 0),
                'starts_at' => $row->starts_at === null ? null : Carbon::parse($row->starts_at)->toIso8601String(),
                'ends_at' => $row->ends_at === null ? null : Carbon::parse($row->ends_at)->toIso8601String(),
                'created_at' => Carbon::parse($row->created_at)->toIso8601String(),
            ];
        })->all();
    }

    /**
     * @return array<string, mixed>
     */
    public static function detail(object $contract): array
    {
        $now = now();

        $property = DB::table('customer_properties')->where('id', $contract->customer_property_id)->first();

        $customer = DB::table('users')
            ->join('user_profiles', 'user_profiles.user_id', '=', 'users.id')
            ->where('users.id', $contract->customer_user_id)
            ->first(['users.id', 'users.phone_number', 'users.email', 'user_profiles.full_name']);

        $items = DB::table('service_contract_items')->where('service_contract_id', $contract->id)->orderBy('created_at')->get();
        $entitlements = (new ContractEntitlementCalculator)->summarizeMany($items);

        $currency = $contract->currency_id === null ? null : DB::table('currencies')->where('id', $contract->currency_id)->first(['code', 'symbol', 'minor_unit']);

        $history = DB::table('service_contract_status_history')
            ->leftJoin('service_contract_statuses as from_s', 'from_s.id', '=', 'service_contract_status_history.from_status_id')
            ->join('service_contract_statuses as to_s', 'to_s.id', '=', 'service_contract_status_history.to_status_id')
            ->where('service_contract_id', $contract->id)
            ->orderByDesc('changed_at')
            ->get(['from_s.code as from_code', 'to_s.code as to_code', 'changed_by_user_id', 'reason', 'changed_at']);

        $bookings = DB::table('bookings')
            ->join('booking_statuses', 'booking_statuses.id', '=', 'bookings.status_id')
            ->where('bookings.service_contract_id', $contract->id)
            ->orderByDesc('bookings.created_at')
            ->get(['bookings.id', 'bookings.booking_number', 'bookings.service_contract_item_id', 'booking_statuses.code as status_code', 'bookings.created_at']);

        $requestedServiceUuids = $contract->requested_service_ids === null ? [] : (json_decode((string) $contract->requested_service_ids, true) ?? []);

        return [
            'uuid' => UuidBinary::toString($contract->id),
            'contract_number' => $contract->contract_number,
            'status' => ContractPresenter::effectiveStatus($contract, $now),
            'customer' => $customer === null ? null : [
                'uuid' => UuidBinary::toString($customer->id),
                'full_name' => $customer->full_name,
                'phone_number' => $customer->phone_number,
                'email' => $customer->email,
            ],
            'property' => $property === null ? null : PropertyPresenter::present($property),
            'term' => [
                'starts_at' => $contract->starts_at === null ? null : Carbon::parse($contract->starts_at)->toIso8601String(),
                'ends_at' => $contract->ends_at === null ? null : Carbon::parse($contract->ends_at)->toIso8601String(),
                'term_months' => $contract->term_months === null ? null : (int) $contract->term_months,
            ],
            'quoted_amount' => $contract->quoted_amount,
            'currency' => $currency === null ? null : ['code' => $currency->code, 'symbol' => $currency->symbol, 'decimal_places' => (int) $currency->minor_unit],
            'requested_all_services' => (bool) $contract->requested_all_services,
            'requested_service_uuids' => $requestedServiceUuids,
            'requested_starts_on' => $contract->requested_starts_on,
            'customer_note' => $contract->customer_note,
            'internal_note' => $contract->internal_note,
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
                'accepted_by_user_uuid' => $contract->accepted_by_user_id === null ? null : UuidBinary::toString($contract->accepted_by_user_id),
            ],
            'status_history' => $history->map(fn (object $row): array => [
                'from_status' => $row->from_code,
                'to_status' => $row->to_code,
                'changed_by_user_uuid' => $row->changed_by_user_id === null ? null : UuidBinary::toString($row->changed_by_user_id),
                'reason' => $row->reason,
                'changed_at' => Carbon::parse($row->changed_at)->toIso8601String(),
            ])->all(),
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
