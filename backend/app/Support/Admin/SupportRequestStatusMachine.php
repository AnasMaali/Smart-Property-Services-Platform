<?php

namespace App\Support\Admin;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The one place a `support_requests` row's status_id (and the resolved_at/
 * closed_at timestamps that track it) is ever written after creation -
 * BLUE V1 Admin Support Management. Every method requires the caller to
 * have already locked the row (SELECT ... FOR UPDATE, see
 * App\Actions\Admin\Support\AdminUpdateSupportRequestStatusAction) and to
 * have already resolved `$supportRequest->status` from a join against
 * `support_request_statuses` (the same `.*, support_request_statuses.code
 * as status` shape every other Support Action already selects).
 *
 * Unlike App\Support\Contract\ContractStatusMachine (a small, fixed number
 * of semantically distinct transitions, each triggered by its own specific
 * business event/endpoint), a Support Request's status is a single
 * Admin-operator control - "move this request to any of its allowed next
 * statuses" - so this machine exposes one generic isAllowed()/transition()
 * pair keyed by the actual target code, mirroring
 * App\Actions\Admin\Technician\AdminSetTechnicianStatusAction's
 * generic-target design instead.
 *
 * The allowed graph (BLUE V1 Admin Support Management - no prior lifecycle
 * policy existed anywhere in this codebase or docs/03-features-and-
 * requirements/09-human-support.md to reuse; this is a new, explicit
 * policy, not a guess):
 *
 *   OPEN <-> IN_PROGRESS
 *   OPEN -> RESOLVED
 *   IN_PROGRESS -> RESOLVED
 *   RESOLVED -> CLOSED
 *   RESOLVED -> IN_PROGRESS   (reopen for further work)
 *   CLOSED -> IN_PROGRESS     (reopen a closed request)
 *
 * OPEN -> CLOSED and CLOSED -> {OPEN, RESOLVED} are deliberately NOT
 * allowed: a request must always pass through RESOLVED on its way to
 * CLOSED (closing is "confirming a resolution", never a shortcut around
 * one), and reopening a CLOSED request always hands it back to active work
 * (IN_PROGRESS), never straight back to an unclaimed OPEN queue.
 *
 * Entering RESOLVED always stamps `resolved_at` fresh and clears
 * `closed_at` (keeps `chk_support_requests_resolution_order` satisfied on
 * every reachable path, including a request that was previously CLOSED and
 * is now being resolved again after reopening). Entering CLOSED stamps
 * `closed_at` and leaves the existing `resolved_at` untouched - CLOSED is
 * only ever reached from RESOLVED per the graph above, so it is always
 * already set. Entering OPEN or IN_PROGRESS always clears both - the
 * request is no longer resolved or closed.
 */
final class SupportRequestStatusMachine
{
    /**
     * @var array<string, array<int, string>>
     */
    private const ALLOWED = [
        'OPEN' => ['IN_PROGRESS', 'RESOLVED'],
        'IN_PROGRESS' => ['OPEN', 'RESOLVED'],
        'RESOLVED' => ['IN_PROGRESS', 'CLOSED'],
        'CLOSED' => ['IN_PROGRESS'],
    ];

    public function isAllowed(string $from, string $to): bool
    {
        return in_array($to, self::ALLOWED[$from] ?? [], true);
    }

    public function transition(object $supportRequest, string $targetCode, Carbon $at): void
    {
        $timestamp = $at->format('Y-m-d H:i:s.u');

        [$resolvedAt, $closedAt] = match ($targetCode) {
            'RESOLVED' => [$timestamp, null],
            'CLOSED' => [$supportRequest->resolved_at, $timestamp],
            default => [null, null],
        };

        DB::table('support_requests')->where('id', $supportRequest->id)->update([
            'status_id' => SupportRequestStatuses::id($targetCode),
            'status_changed_at' => $timestamp,
            'resolved_at' => $resolvedAt,
            'closed_at' => $closedAt,
            'updated_at' => $timestamp,
        ]);
    }
}
