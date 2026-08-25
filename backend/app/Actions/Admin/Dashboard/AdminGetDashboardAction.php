<?php

namespace App\Actions\Admin\Dashboard;

use App\Support\Admin\AdminDashboardPresenter;
use App\Support\Cart\Concerns\BuildsCartResult;
use Illuminate\Support\Facades\DB;

/**
 * BLUE V1 Phase B10 - the single read-only Admin Dashboard query. Every
 * number and list here is a plain aggregate/bounded query over the exact
 * canonical tables/status codes every other Admin module already reads
 * (booking_statuses, booking_item_statuses, service_contract_statuses,
 * payment_attempts.requires_reconciliation, service_contract_billing_statuses,
 * support_request_statuses, technician_statuses.is_assignable) - no business
 * logic (status machines, pricing, eligibility) is reimplemented or
 * duplicated here; this only counts/lists what those already-canonical
 * columns say. No writes, no side effects, fully deterministic.
 *
 * Timezone note: PHP/Laravel's `now()` uses `config('app.timezone')` (UTC
 * in BLUE V1), but this environment's MySQL server default timestamp
 * columns (`DEFAULT CURRENT_TIMESTAMP`) are stamped using the MySQL
 * server's own session timezone, which is NOT UTC here - confirmed by
 * directly comparing `now()` against `SELECT NOW()` during this phase's
 * own testing. A calendar-day ("today") boundary computed from one clock
 * and compared against timestamps stamped by the other would misclassify
 * records near either midnight. Per BLUE V1 standing guidance for exactly
 * this ambiguity, every "recent" metric here therefore uses a rolling
 * `now()->subDay()` ("last 24 hours") window instead of `startOfDay()`
 * ("today") - a fixed multi-hour offset shifts a 24-hour rolling window
 * only slightly, whereas it can flip a calendar-day boundary entirely.
 */
final class AdminGetDashboardAction
{
    use BuildsCartResult;

    private const ATTENTION_LIST_LIMIT = 10;

    private const RECENT_ACTIVITY_LIMIT = 10;

    public function handle(): array
    {
        $last24Hours = now()->subDay();

        return $this->ok(200, 'Dashboard retrieved successfully.', [
            'summary' => [
                'bookings' => $this->bookingsSummary($last24Hours),
                'contracts' => $this->contractsSummary(),
                'financial' => $this->financialSummary($last24Hours),
                'customers' => $this->customersSummary($last24Hours),
                'support' => $this->supportSummary(),
                'technicians' => $this->techniciansSummary(),
            ],
            'attention' => [
                'booking_items_pending_assignment' => AdminDashboardPresenter::pendingAssignmentItems($this->pendingAssignmentItems()),
                'contracts_awaiting_approval' => AdminDashboardPresenter::contractsAwaitingApproval($this->contractsAwaitingApproval()),
                'payments_requiring_reconciliation' => AdminDashboardPresenter::paymentsRequiringReconciliation($this->paymentsRequiringReconciliation()),
                'billings_past_due' => AdminDashboardPresenter::billingsPastDue($this->billingsPastDue()),
                'support_unassigned_open' => AdminDashboardPresenter::unassignedOpenSupportRequests($this->unassignedOpenSupportRequests()),
            ],
            'recent_activity' => AdminDashboardPresenter::recentActivity($this->recentActivity()),
            'generated_at' => now()->toIso8601String(),
        ]);
    }

    /**
     * @return array{active: int, created_last_24h: int, pending_assignment: int, in_progress: int}
     */
    private function bookingsSummary($last24Hours): array
    {
        $active = DB::table('bookings')
            ->join('booking_statuses', 'booking_statuses.id', '=', 'bookings.status_id')
            ->where('booking_statuses.is_terminal', 0)
            ->count();

        $createdLast24h = DB::table('bookings')->where('created_at', '>=', $last24Hours)->count();

        $pendingAssignment = DB::table('booking_items')
            ->join('booking_item_statuses', 'booking_item_statuses.id', '=', 'booking_items.status_id')
            ->where('booking_item_statuses.code', 'PENDING_ASSIGNMENT')
            ->count();

        $inProgress = DB::table('booking_items')
            ->join('booking_item_statuses', 'booking_item_statuses.id', '=', 'booking_items.status_id')
            ->where('booking_item_statuses.code', 'IN_PROGRESS')
            ->count();

        return [
            'active' => $active,
            'created_last_24h' => $createdLast24h,
            'pending_assignment' => $pendingAssignment,
            'in_progress' => $inProgress,
        ];
    }

    /**
     * @return array{active: int, awaiting_approval: int, pending_customer_acceptance: int, pending_payment: int, suspended: int}
     */
    private function contractsSummary(): array
    {
        $counts = DB::table('service_contracts')
            ->join('service_contract_statuses', 'service_contract_statuses.id', '=', 'service_contracts.status_id')
            ->whereIn('service_contract_statuses.code', ['ACTIVE', 'REQUESTED', 'PENDING_CUSTOMER_ACCEPTANCE', 'PENDING_PAYMENT', 'SUSPENDED'])
            ->selectRaw('service_contract_statuses.code, COUNT(*) as total')
            ->groupBy('service_contract_statuses.code')
            ->pluck('total', 'code');

        return [
            'active' => (int) ($counts['ACTIVE'] ?? 0),
            'awaiting_approval' => (int) ($counts['REQUESTED'] ?? 0),
            'pending_customer_acceptance' => (int) ($counts['PENDING_CUSTOMER_ACCEPTANCE'] ?? 0),
            'pending_payment' => (int) ($counts['PENDING_PAYMENT'] ?? 0),
            'suspended' => (int) ($counts['SUSPENDED'] ?? 0),
        ];
    }

    /**
     * @return array{payments_successful_last_24h: int, payments_pending: int, payments_requiring_reconciliation: int, billings_past_due: int}
     */
    private function financialSummary($last24Hours): array
    {
        $successfulLast24h = DB::table('payment_attempts')
            ->where('successful_at', '>=', $last24Hours)
            ->count();

        $pending = DB::table('payment_attempts')
            ->join('payment_statuses', 'payment_statuses.id', '=', 'payment_attempts.status_id')
            ->where('payment_statuses.code', 'PENDING')
            ->count();

        $requiringReconciliation = DB::table('payment_attempts')
            ->where('requires_reconciliation', 1)
            ->whereNull('reconciled_at')
            ->count();

        $billingsPastDue = DB::table('service_contract_billings')
            ->join('service_contract_billing_statuses', 'service_contract_billing_statuses.id', '=', 'service_contract_billings.status_id')
            ->where('service_contract_billing_statuses.code', 'PAST_DUE')
            ->count();

        return [
            'payments_successful_last_24h' => $successfulLast24h,
            'payments_pending' => $pending,
            'payments_requiring_reconciliation' => $requiringReconciliation,
            'billings_past_due' => $billingsPastDue,
        ];
    }

    /**
     * @return array{active: int, registered_last_24h: int}
     */
    private function customersSummary($last24Hours): array
    {
        $base = fn () => DB::table('users')->join('customer_profiles', 'customer_profiles.user_id', '=', 'users.id');

        $active = $base()
            ->join('user_account_statuses', 'user_account_statuses.id', '=', 'users.account_status_id')
            ->where('user_account_statuses.code', 'ACTIVE')
            ->count();

        $registeredLast24h = $base()->where('users.created_at', '>=', $last24Hours)->count();

        return ['active' => $active, 'registered_last_24h' => $registeredLast24h];
    }

    /**
     * @return array{open_or_in_progress: int, unassigned_open: int}
     */
    private function supportSummary(): array
    {
        $openOrInProgress = DB::table('support_requests')
            ->join('support_request_statuses', 'support_request_statuses.id', '=', 'support_requests.status_id')
            ->where('support_request_statuses.is_terminal', 0)
            ->count();

        $unassignedOpen = DB::table('support_requests')
            ->join('support_request_statuses', 'support_request_statuses.id', '=', 'support_requests.status_id')
            ->where('support_request_statuses.is_terminal', 0)
            ->whereNull('support_requests.assigned_admin_user_id')
            ->count();

        return ['open_or_in_progress' => $openOrInProgress, 'unassigned_open' => $unassignedOpen];
    }

    /**
     * @return array{assignable: int, busy: int}
     */
    private function techniciansSummary(): array
    {
        $assignable = DB::table('technicians')
            ->join('technician_statuses', 'technician_statuses.id', '=', 'technicians.status_id')
            ->where('technician_statuses.is_assignable', 1)
            ->count();

        $busy = DB::table('technicians')
            ->join('technician_statuses', 'technician_statuses.id', '=', 'technicians.status_id')
            ->where('technician_statuses.code', 'BUSY')
            ->count();

        return ['assignable' => $assignable, 'busy' => $busy];
    }

    private function pendingAssignmentItems()
    {
        return DB::table('booking_items')
            ->join('booking_item_statuses', 'booking_item_statuses.id', '=', 'booking_items.status_id')
            ->join('bookings', 'bookings.id', '=', 'booking_items.booking_id')
            ->join('services', 'services.id', '=', 'booking_items.service_id')
            ->where('booking_item_statuses.code', 'PENDING_ASSIGNMENT')
            ->orderBy('booking_items.created_at')
            ->limit(self::ATTENTION_LIST_LIMIT)
            ->get([
                'bookings.id as booking_id',
                'bookings.booking_number',
                'services.name as service_name',
                'booking_items.created_at',
            ]);
    }

    private function contractsAwaitingApproval()
    {
        return DB::table('service_contracts')
            ->join('service_contract_statuses', 'service_contract_statuses.id', '=', 'service_contracts.status_id')
            ->join('users', 'users.id', '=', 'service_contracts.customer_user_id')
            ->join('user_profiles', 'user_profiles.user_id', '=', 'users.id')
            ->where('service_contract_statuses.code', 'REQUESTED')
            ->orderBy('service_contracts.created_at')
            ->limit(self::ATTENTION_LIST_LIMIT)
            ->get([
                'service_contracts.id',
                'service_contracts.contract_number',
                'service_contracts.created_at',
                'user_profiles.full_name as customer_name',
            ]);
    }

    private function paymentsRequiringReconciliation()
    {
        return DB::table('payment_attempts')
            ->join('currencies', 'currencies.id', '=', 'payment_attempts.currency_id')
            ->where('payment_attempts.requires_reconciliation', 1)
            ->whereNull('payment_attempts.reconciled_at')
            ->orderBy('payment_attempts.created_at')
            ->limit(self::ATTENTION_LIST_LIMIT)
            ->get([
                'payment_attempts.id',
                'payment_attempts.checkout_reference',
                'payment_attempts.requested_amount',
                'payment_attempts.reconciliation_reason_code',
                'payment_attempts.created_at',
                'currencies.code as currency_code',
            ]);
    }

    private function billingsPastDue()
    {
        return DB::table('service_contract_billings')
            ->join('service_contract_billing_statuses', 'service_contract_billing_statuses.id', '=', 'service_contract_billings.status_id')
            ->join('service_contracts', 'service_contracts.id', '=', 'service_contract_billings.service_contract_id')
            ->where('service_contract_billing_statuses.code', 'PAST_DUE')
            ->orderBy('service_contract_billings.past_due_since')
            ->limit(self::ATTENTION_LIST_LIMIT)
            ->get([
                'service_contract_billings.id',
                'service_contract_billings.past_due_since',
                'service_contracts.contract_number',
            ]);
    }

    private function unassignedOpenSupportRequests()
    {
        return DB::table('support_requests')
            ->join('support_request_statuses', 'support_request_statuses.id', '=', 'support_requests.status_id')
            ->where('support_request_statuses.is_terminal', 0)
            ->whereNull('support_requests.assigned_admin_user_id')
            ->orderBy('support_requests.created_at')
            ->limit(self::ATTENTION_LIST_LIMIT)
            ->get(['support_requests.id', 'support_requests.request_number', 'support_requests.subject', 'support_requests.created_at']);
    }

    private function recentActivity()
    {
        return DB::table('admin_audit_logs')
            ->leftJoin('users', 'users.id', '=', 'admin_audit_logs.admin_user_id')
            ->leftJoin('user_profiles', 'user_profiles.user_id', '=', 'users.id')
            ->orderByDesc('admin_audit_logs.created_at')
            ->orderByDesc('admin_audit_logs.id')
            ->limit(self::RECENT_ACTIVITY_LIMIT)
            ->get([
                'admin_audit_logs.action_code',
                'admin_audit_logs.entity_type',
                'admin_audit_logs.entity_identifier',
                'admin_audit_logs.was_successful',
                'admin_audit_logs.failure_reason',
                'admin_audit_logs.created_at',
                'user_profiles.full_name as actor_name',
            ]);
    }
}
