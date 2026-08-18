<?php

namespace App\Support\Contract\Billing;

use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Resolves service_contract_billing_statuses.id by code instead of
 * hardcoding numeric lookup ids anywhere in Contract Billing Actions -
 * mirrors App\Support\Contract\ContractStatuses exactly. The seeded codes
 * (BLUE V1 Phase 11) are PENDING_CHECKOUT, INCOMPLETE, ACTIVE, PAST_DUE,
 * CANCEL_AT_PERIOD_END, CANCELLED - see database/blue_v1_seed.sql
 * "32. SERVICE CONTRACT BILLING STATUSES".
 */
final class ContractBillingStatuses
{
    /**
     * Billing statuses under which a CONTRACT Booking may be created - see
     * App\Actions\Contract\CreateContractBookingAction. ACTIVE is the
     * ordinary case; CANCEL_AT_PERIOD_END still authorizes new Bookings
     * because the subscription has been scheduled to stop at a future
     * period end but the customer has already paid through that date (BLUE
     * V1 Phase 11 only requires PENDING_CHECKOUT / INCOMPLETE / PAST_DUE /
     * CANCELLED to never authorize - it does not require blocking a period
     * the customer already paid for). PAST_DUE deliberately never appears
     * here - BLUE V1 Phase 11 "prefer BLOCKING NEW CONTRACT BOOKINGS while
     * billing is PAST_DUE".
     */
    private const BOOKING_AUTHORIZING_CODES = ['ACTIVE', 'CANCEL_AT_PERIOD_END'];

    public static function id(string $code): int
    {
        $id = DB::table('service_contract_billing_statuses')->where('code', $code)->where('is_active', 1)->value('id');

        if ($id === null) {
            throw new RuntimeException("Missing required reference row: service_contract_billing_statuses.code = {$code}");
        }

        return (int) $id;
    }

    public static function code(int $id): string
    {
        $code = DB::table('service_contract_billing_statuses')->where('id', $id)->value('code');

        if ($code === null) {
            throw new RuntimeException("Missing required reference row: service_contract_billing_statuses.id = {$id}");
        }

        return $code;
    }

    public static function authorizesBooking(string $code): bool
    {
        return in_array($code, self::BOOKING_AUTHORIZING_CODES, true);
    }
}
