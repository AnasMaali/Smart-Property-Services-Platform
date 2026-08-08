<?php

namespace App\Support\Pricing;

use Carbon\Carbon;
use Carbon\CarbonInterface;

/**
 * Pure selection of "the one currently-effective PUBLISHED pricing scheme
 * version" for a service+currency, given an evaluation timestamp. No
 * database access - the candidate list is already loaded by the caller.
 *
 * A version is eligible when status = PUBLISHED and
 * effective_from <= $at < effective_to (or effective_to is null / open
 * -ended). Both bounded and future-dated PUBLISHED versions are supported;
 * a future version is simply not yet eligible.
 */
final class PricingSchemeSelector
{
    /**
     * @param  array<int, array{id: string, status: string, effective_from: ?string, effective_to: ?string}>  $schemeVersions
     * @return array{id: string, status: string, effective_from: ?string, effective_to: ?string}|null
     */
    public function selectCurrent(array $schemeVersions, CarbonInterface $at): ?array
    {
        foreach ($schemeVersions as $version) {
            if ($version['status'] !== 'PUBLISHED') {
                continue;
            }

            if ($version['effective_from'] === null) {
                continue;
            }

            $effectiveFrom = Carbon::parse($version['effective_from']);
            $effectiveTo = $version['effective_to'] === null ? null : Carbon::parse($version['effective_to']);

            $hasStarted = $at->greaterThanOrEqualTo($effectiveFrom);
            $hasNotEnded = $effectiveTo === null || $at->lessThan($effectiveTo);

            if ($hasStarted && $hasNotEnded) {
                return $version;
            }
        }

        return null;
    }
}
