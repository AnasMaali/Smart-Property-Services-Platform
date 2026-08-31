<?php

namespace App\Support\Admin;

use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Resolves support_request_statuses.id by code instead of hardcoding
 * numeric lookup ids anywhere in Support Actions - mirrors
 * App\Support\Contract\ContractStatuses exactly. The seeded lifecycle
 * codes (BLUE V1 Phase B7, extended by Admin Support Management's status
 * transitions) are OPEN, IN_PROGRESS, RESOLVED, CLOSED - see
 * database/blue_v1_seed.sql "25. SUPPORT REQUEST STATUSES".
 */
final class SupportRequestStatuses
{
    public static function id(string $code): int
    {
        $id = DB::table('support_request_statuses')->where('code', $code)->where('is_active', 1)->value('id');

        if ($id === null) {
            throw new RuntimeException("Missing required reference row: support_request_statuses.code = {$code}");
        }

        return (int) $id;
    }
}
