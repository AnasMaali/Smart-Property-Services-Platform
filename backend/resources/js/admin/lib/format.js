/**
 * Small, shared display-formatting helpers for Admin operational pages
 * (BLUE V1 Phase B2+) - never business logic, purely presentation. Status
 * badge colors are a fixed, generic lookup over the exact status codes the
 * Admin APIs already return (booking_statuses / booking_item_statuses /
 * technician_assignment lifecycle / service_contract_statuses /
 * service_contract_billing_statuses) - no new status vocabulary is invented
 * here.
 */

const STATUS_BADGE_CLASSES = {
    PAID: 'bg-blue-50 text-blue-700',
    PENDING_ASSIGNMENT: 'bg-amber-50 text-amber-700',
    ASSIGNED: 'bg-blue-50 text-blue-700',
    IN_PROGRESS: 'bg-indigo-50 text-indigo-700',
    COMPLETED: 'bg-emerald-50 text-emerald-700',
    CANCELLED: 'bg-red-50 text-red-700',

    // service_contract_statuses
    REQUESTED: 'bg-amber-50 text-amber-700',
    APPROVED: 'bg-blue-50 text-blue-700',
    PENDING_CUSTOMER_ACCEPTANCE: 'bg-amber-50 text-amber-700',
    PENDING_PAYMENT: 'bg-amber-50 text-amber-700',
    ACTIVE: 'bg-emerald-50 text-emerald-700',
    SUSPENDED: 'bg-orange-50 text-orange-700',
    EXPIRED: 'bg-slate-100 text-slate-600',

    // service_contract_billing_statuses (ACTIVE/CANCELLED above already
    // cover the shared codes)
    PENDING_CHECKOUT: 'bg-amber-50 text-amber-700',
    INCOMPLETE: 'bg-amber-50 text-amber-700',
    PAST_DUE: 'bg-red-50 text-red-700',
    CANCEL_AT_PERIOD_END: 'bg-amber-50 text-amber-700',
};

const DEFAULT_STATUS_BADGE_CLASSES = 'bg-slate-100 text-slate-600';

export function statusBadgeClasses(code) {
    return STATUS_BADGE_CLASSES[code] || DEFAULT_STATUS_BADGE_CLASSES;
}

export function statusLabel(code) {
    if (!code) {
        return '—';
    }

    return code
        .toLowerCase()
        .split('_')
        .map((word) => word.charAt(0).toUpperCase() + word.slice(1))
        .join(' ');
}

export function formatDateTime(iso) {
    if (!iso) {
        return '—';
    }

    const date = new Date(iso);

    if (Number.isNaN(date.getTime())) {
        return '—';
    }

    return date.toLocaleString(undefined, {
        year: 'numeric',
        month: 'short',
        day: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    });
}

/**
 * $currency is the { code, symbol, decimal_places } object the Admin
 * Booking/Contract presenters already return - never guessed. $amount is
 * the API's own decimal string.
 */
export function formatMoney(amount, currency) {
    if (amount === null || amount === undefined) {
        return '—';
    }

    const decimals = currency?.decimal_places ?? 2;
    const numeric = Number.parseFloat(amount);

    if (Number.isNaN(numeric)) {
        return amount;
    }

    const formatted = numeric.toFixed(decimals);

    return currency?.symbol ? `${currency.symbol} ${formatted}` : `${formatted} ${currency?.code || ''}`.trim();
}
