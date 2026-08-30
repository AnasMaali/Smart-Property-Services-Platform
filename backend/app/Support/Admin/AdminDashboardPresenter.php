<?php

namespace App\Support\Admin;

use App\Support\Uuid\UuidBinary;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * BLUE V1 Phase B10. Pure formatting of the already-fetched, already-bounded
 * rows App\Actions\Admin\Dashboard\AdminGetDashboardAction queries - never a
 * second query, never a second source of truth. Every attention-list row
 * carries the exact identifier its own domain's existing Admin detail page
 * already accepts (e.g. `booking_uuid` for `/admin/bookings/{booking}`), so
 * the frontend never has to guess a route.
 */
final class AdminDashboardPresenter
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public static function pendingAssignmentItems(Collection $rows): array
    {
        return $rows->map(fn (object $row) => [
            'booking_uuid' => UuidBinary::toString($row->booking_id),
            'booking_number' => $row->booking_number,
            'service_name' => $row->service_name,
            'created_at' => Carbon::parse($row->created_at)->toIso8601String(),
        ])->values()->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function contractsAwaitingApproval(Collection $rows): array
    {
        return $rows->map(fn (object $row) => [
            'contract_uuid' => UuidBinary::toString($row->id),
            'contract_number' => $row->contract_number,
            'customer_name' => $row->customer_name,
            'created_at' => Carbon::parse($row->created_at)->toIso8601String(),
        ])->values()->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function paymentsRequiringReconciliation(Collection $rows): array
    {
        return $rows->map(fn (object $row) => [
            'payment_uuid' => UuidBinary::toString($row->id),
            'checkout_reference' => $row->checkout_reference,
            'requested_amount' => $row->requested_amount,
            'currency_code' => $row->currency_code,
            'reconciliation_reason_code' => $row->reconciliation_reason_code,
            'created_at' => Carbon::parse($row->created_at)->toIso8601String(),
        ])->values()->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function billingsPastDue(Collection $rows): array
    {
        return $rows->map(fn (object $row) => [
            'billing_uuid' => UuidBinary::toString($row->id),
            'contract_number' => $row->contract_number,
            'past_due_since' => Carbon::parse($row->past_due_since)->toIso8601String(),
        ])->values()->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function unassignedOpenSupportRequests(Collection $rows): array
    {
        return $rows->map(fn (object $row) => [
            'support_request_uuid' => UuidBinary::toString($row->id),
            'request_number' => $row->request_number,
            'subject' => $row->subject,
            'created_at' => Carbon::parse($row->created_at)->toIso8601String(),
        ])->values()->all();
    }

    /**
     * Never includes `old_values`/`new_values` - identifiers and safe
     * metadata only, matching the same standard every Admin mutation
     * Action already follows when writing these rows in the first place.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function recentActivity(Collection $rows): array
    {
        return $rows->map(fn (object $row) => [
            'action_code' => $row->action_code,
            'entity_type' => $row->entity_type,
            'entity_identifier' => $row->entity_identifier,
            'was_successful' => (bool) $row->was_successful,
            'failure_reason' => $row->failure_reason,
            'actor_name' => $row->actor_name,
            'created_at' => Carbon::parse($row->created_at)->toIso8601String(),
        ])->values()->all();
    }
}
