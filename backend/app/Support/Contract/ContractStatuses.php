<?php

namespace App\Support\Contract;

use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Resolves service_contract_statuses.id by code instead of hardcoding
 * numeric lookup ids anywhere in Contract Actions - mirrors
 * App\Support\Booking\BookingStatuses exactly. The seeded lifecycle codes
 * (BLUE V1 Phase 10B) are REQUESTED, APPROVED, PENDING_CUSTOMER_ACCEPTANCE,
 * ACTIVE, SUSPENDED, EXPIRED, CANCELLED - see database/blue_v1_seed.sql
 * "31. SERVICE CONTRACT STATUSES".
 */
final class ContractStatuses
{
    public static function id(string $code): int
    {
        $id = DB::table('service_contract_statuses')->where('code', $code)->where('is_active', 1)->value('id');

        if ($id === null) {
            throw new RuntimeException("Missing required reference row: service_contract_statuses.code = {$code}");
        }

        return (int) $id;
    }

    public static function code(int $id): string
    {
        $code = DB::table('service_contract_statuses')->where('id', $id)->value('code');

        if ($code === null) {
            throw new RuntimeException("Missing required reference row: service_contract_statuses.id = {$id}");
        }

        return $code;
    }
}
