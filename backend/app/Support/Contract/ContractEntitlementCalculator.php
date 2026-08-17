<?php

namespace App\Support\Contract;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * "Used visits" for a Service Contract Item is always derived from actual
 * `bookings` rows, never from a separate mutable counter (BLUE V1 Phase
 * 10C) - a Booking whose current status is not CANCELLED counts as one
 * consumed visit; a CANCELLED CONTRACT Booking counts as zero, so
 * cancelling one automatically and permanently "returns" the entitlement
 * with no extra bookkeeping. This is safe for both reads (this class) and
 * writes (App\Actions\Contract\CreateContractBookingAction locks the
 * service_contract_items row FOR UPDATE before counting and inserting, so
 * the count this class would compute after that lock is released is always
 * exactly what the write path already observed under lock - see that
 * Action's docblock for the full race-safety argument).
 *
 * Never issues one query per item - every caller with more than one item
 * must go through summarizeMany().
 */
final class ContractEntitlementCalculator
{
    /**
     * @param  array<int, string>  $itemIdsBinary
     * @return Collection<string, int> Keyed by hex service_contract_items id.
     */
    public function usedVisitsFor(array $itemIdsBinary): Collection
    {
        if ($itemIdsBinary === []) {
            return collect();
        }

        return DB::table('bookings')
            ->join('booking_statuses', 'booking_statuses.id', '=', 'bookings.status_id')
            ->whereIn('bookings.service_contract_item_id', $itemIdsBinary)
            ->where('booking_statuses.code', '!=', 'CANCELLED')
            ->select('bookings.service_contract_item_id', DB::raw('COUNT(*) as used'))
            ->groupBy('bookings.service_contract_item_id')
            ->get()
            ->keyBy(fn ($row) => bin2hex($row->service_contract_item_id))
            ->map(fn ($row) => (int) $row->used);
    }

    /**
     * @param  Collection<int, object>  $items  service_contract_items rows (must expose id, entitlement_mode, included_visits).
     * @return Collection<string, array{entitlement_mode: string, included_visits: ?int, used_visits: int, remaining_visits: ?int}> Keyed by hex item id.
     */
    public function summarizeMany(Collection $items): Collection
    {
        $usedByItem = $this->usedVisitsFor($items->pluck('id')->all());

        return $items->mapWithKeys(function (object $item) use ($usedByItem): array {
            $key = bin2hex($item->id);
            $used = $usedByItem->get($key, 0);
            $includedVisits = $item->included_visits === null ? null : (int) $item->included_visits;

            return [$key => [
                'entitlement_mode' => $item->entitlement_mode,
                'included_visits' => $includedVisits,
                'used_visits' => $used,
                'remaining_visits' => $includedVisits === null ? null : max($includedVisits - $used, 0),
            ]];
        });
    }
}
