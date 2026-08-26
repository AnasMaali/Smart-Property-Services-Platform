/**
 * Admin Bookings list (BLUE V1 Phase B2, redesigned as the primary
 * Bookings operations workspace in Phase B14). Reuses the centralized
 * Admin API client (lib/api-client.js) - no raw fetch() here - against the
 * existing GET /v1/admin/bookings endpoint (App\Actions\Admin\Booking\
 * AdminListBookingsAction / App\Support\Admin\AdminBookingPresenter). Only
 * filters the backend actually supports are exposed; pagination reflects
 * exactly what the API returns.
 *
 * The four summary cards reuse the exact same GET /v1/admin/dashboard
 * `summary.bookings` numbers the Admin Dashboard already computes (BLUE V1
 * Phase B10) - never a second, page-local aggregation of the same facts.
 *
 * Filters/page are kept in the URL query string (history.pushState) so the
 * list is bookmarkable/shareable and survives back/forward navigation,
 * without ever inventing client-side filtering of data the server did not
 * already filter.
 *
 * `assignment_state` (PENDING/PARTIAL/FULL) is a read-only, UI-facing
 * vocabulary the backend derives from real `technician_assignments` rows
 * (App\Support\Admin\AdminBookingPresenter::assignmentState()) - never a
 * persisted status, so its badge styling lives here rather than in
 * lib/format.js (which only ever styles real backend status codes).
 */

import { request, ApiError } from '../lib/api-client.js';
import { adminAuthReady } from '../auth/restore.js';
import { statusBadgeClasses, statusLabel, formatDateTime } from '../lib/format.js';

const ASSIGNMENT_BADGE_CLASSES = {
    PENDING: 'bg-amber-50 text-amber-700',
    PARTIAL: 'bg-blue-50 text-blue-700',
    FULL: 'bg-emerald-50 text-emerald-700',
};

const ASSIGNMENT_LABELS = {
    PENDING: 'Pending assignment',
    PARTIAL: 'Partially assigned',
    FULL: 'Fully assigned',
};

function assignmentBadgeClasses(state) {
    return ASSIGNMENT_BADGE_CLASSES[state] || 'bg-slate-100 text-slate-600';
}

function assignmentLabel(state) {
    return ASSIGNMENT_LABELS[state] || '—';
}

function formatDateOnly(iso) {
    if (!iso) {
        return '—';
    }

    const date = new Date(iso);

    if (Number.isNaN(date.getTime())) {
        return '—';
    }

    return date.toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: 'numeric' });
}

function servicesSummary(services) {
    if (!services || services.length === 0) {
        return '—';
    }

    if (services.length === 1) {
        return services[0];
    }

    return `${services[0]} +${services.length - 1} more`;
}

const page = document.querySelector('[data-bookings-page]');

if (page) {
    const filterForm = page.querySelector('[data-bookings-filter-form]');
    const clearButton = page.querySelector('[data-bookings-clear-filters]');
    const advancedToggle = page.querySelector('[data-advanced-filters-toggle]');
    const advancedFilters = page.querySelector('[data-advanced-filters]');
    const refreshButton = page.querySelector('[data-bookings-refresh]');
    const loadingEl = page.querySelector('[data-bookings-loading]');
    const errorEl = page.querySelector('[data-bookings-error]');
    const emptyEl = page.querySelector('[data-bookings-empty]');
    const tableWrapper = page.querySelector('[data-bookings-table-wrapper]');
    const tableBody = page.querySelector('[data-bookings-body]');
    const cardsContainer = page.querySelector('[data-bookings-cards]');
    const pagination = page.querySelector('[data-bookings-pagination]');
    const paginationSummary = page.querySelector('[data-bookings-pagination-summary]');
    const prevPageButton = page.querySelector('[data-bookings-prev-page]');
    const nextPageButton = page.querySelector('[data-bookings-next-page]');
    const summaryCardsEl = page.querySelector('[data-summary-cards]');

    const summaryCardTemplate = document.querySelector('[data-summary-card-template]');
    const rowTemplate = document.querySelector('[data-booking-row-template]');
    const cardTemplate = document.querySelector('[data-booking-card-template]');

    const SUMMARY_CARDS = [
        ['active', 'Active bookings'],
        ['pending_assignment', 'Pending assignment'],
        ['in_progress', 'In progress'],
        ['created_last_24h', 'Created (24h)'],
    ];

    const FILTER_FIELDS = [
        'status', 'booking_number', 'customer_uuid', 'technician_uuid', 'service_uuid',
        'assignment_state', 'from', 'to', 'appointment_date',
    ];

    const ADVANCED_FIELDS = ['customer_uuid', 'technician_uuid', 'service_uuid', 'from', 'to'];

    function currentParams() {
        return new URLSearchParams(window.location.search);
    }

    function applyParamsToForm(params) {
        FILTER_FIELDS.forEach((field) => {
            const input = filterForm.elements.namedItem(field);

            if (input) {
                input.value = params.get(field) || '';
            }
        });

        if (ADVANCED_FIELDS.some((field) => params.get(field))) {
            advancedFilters.classList.remove('hidden');
        }
    }

    function paramsFromForm() {
        const params = new URLSearchParams();

        FILTER_FIELDS.forEach((field) => {
            const value = filterForm.elements.namedItem(field)?.value?.trim();

            if (value) {
                params.set(field, value);
            }
        });

        return params;
    }

    async function loadSummaryCards() {
        try {
            const response = await request('/api/v1/admin/dashboard');
            const bookings = response.data.summary.bookings;

            summaryCardsEl.replaceChildren(...SUMMARY_CARDS.map(([field, title]) => {
                const node = summaryCardTemplate.content.cloneNode(true);
                node.querySelector('[data-field="title"]').textContent = title;
                node.querySelector('[data-field="value"]').textContent = String(bookings[field] ?? 0);
                return node;
            }));
        } catch {
            // Summary cards are a convenience, not the primary operation of
            // this page - a failure here never blocks the Bookings list
            // itself from loading.
            summaryCardsEl.replaceChildren();
        }
    }

    function renderBadge(el, code, classesFn, labelFn) {
        el.textContent = labelFn(code);
        el.className = `rounded-full px-2.5 py-1 text-xs font-semibold ${classesFn(code)}`;
    }

    function fillCommonFields(root, booking) {
        const bookingHref = `/admin/bookings/${encodeURIComponent(booking.uuid)}`;

        root.querySelectorAll('[data-row-link]').forEach((link) => {
            link.href = bookingHref;
            link.textContent = booking.booking_number;
        });

        const paymentEl = root.querySelector('[data-field="payment"]');
        if (booking.payment) {
            renderBadge(paymentEl, booking.payment.status, statusBadgeClasses, statusLabel);
        } else {
            paymentEl.textContent = 'Contract';
            paymentEl.className = 'rounded-full px-2.5 py-1 text-xs font-semibold bg-slate-100 text-slate-600';
        }

        renderBadge(
            root.querySelector('[data-field="assignment"]'),
            booking.assignment_state,
            assignmentBadgeClasses,
            assignmentLabel,
        );

        renderBadge(root.querySelector('[data-field="status"]'), booking.status, statusBadgeClasses, statusLabel);

        const servicesEl = root.querySelector('[data-field="services"]');
        if (servicesEl) {
            servicesEl.textContent = servicesSummary(booking.services);
        }
    }

    function renderRow(booking) {
        const fragment = rowTemplate.content.cloneNode(true);
        const root = fragment.querySelector('tr');

        fillCommonFields(root, booking);

        root.querySelector('[data-field="source"]').textContent = statusLabel(booking.source);

        const customerLink = root.querySelector('[data-customer-link]');
        if (booking.customer) {
            customerLink.textContent = booking.customer.full_name || 'Customer';
            customerLink.href = `/admin/customers/${encodeURIComponent(booking.customer.uuid)}`;
        } else {
            customerLink.textContent = '—';
            customerLink.removeAttribute('href');
        }
        root.querySelector('[data-field="customer_phone"]').textContent = booking.customer?.phone_number || '';

        const slot = booking.appointment?.slot;
        root.querySelector('[data-field="appointment_date"]').textContent = slot ? formatDateOnly(slot.starts_at) : '—';
        root.querySelector('[data-field="appointment_window"]').textContent = slot?.time_window?.name || '';

        root.querySelector('[data-field="created_at"]').textContent = formatDateTime(booking.created_at);

        root.addEventListener('click', (event) => {
            if (event.target.closest('a')) {
                return;
            }

            window.location.assign(`/admin/bookings/${encodeURIComponent(booking.uuid)}`);
        });

        return fragment;
    }

    function renderCard(booking) {
        const fragment = cardTemplate.content.cloneNode(true);
        const root = fragment.querySelector('div');

        fillCommonFields(root, booking);

        root.querySelector('[data-field="customer_name"]').textContent = booking.customer?.full_name || 'Unknown customer';

        const slot = booking.appointment?.slot;
        root.querySelector('[data-field="appointment_date"]').textContent = slot
            ? `${formatDateOnly(slot.starts_at)} · ${slot.time_window?.name || ''}`.trim()
            : '—';

        return fragment;
    }

    function setState(state) {
        loadingEl.classList.toggle('hidden', state !== 'loading');
        errorEl.classList.toggle('hidden', state !== 'error');
        emptyEl.classList.toggle('hidden', state !== 'empty');
        tableWrapper.classList.toggle('hidden', state !== 'ready');
        pagination.style.display = state === 'ready' ? 'flex' : 'none';
    }

    function showError(message) {
        errorEl.textContent = message;
        setState('error');
    }

    let currentPage = 1;

    async function loadBookings(params) {
        setState('loading');

        try {
            const query = new URLSearchParams(params);
            query.set('page', params.get('page') || '1');
            currentPage = Number.parseInt(query.get('page'), 10) || 1;

            const response = await request(`/api/v1/admin/bookings?${query.toString()}`);
            const { bookings, pagination: meta } = response.data;

            if (bookings.length === 0) {
                setState('empty');
                pagination.style.display = 'none';
                return;
            }

            tableBody.replaceChildren(...bookings.map(renderRow));
            cardsContainer.replaceChildren(...bookings.map(renderCard));
            setState('ready');

            const start = (meta.page - 1) * meta.per_page + 1;
            const end = Math.min(meta.page * meta.per_page, meta.total);
            paginationSummary.textContent = `Showing ${start}–${end} of ${meta.total}`;

            prevPageButton.disabled = meta.page <= 1;
            nextPageButton.disabled = meta.page >= meta.last_page;
        } catch (error) {
            const message = error instanceof ApiError ? error.message : 'Unable to load bookings.';
            showError(message);
        }
    }

    function navigate(params) {
        const query = params.toString();
        const url = query ? `/admin/bookings?${query}` : '/admin/bookings';
        window.history.pushState({}, '', url);
        loadBookings(params);
    }

    function goToPage(params, targetPage) {
        const next = new URLSearchParams(params);
        next.set('page', String(targetPage));
        navigate(next);
    }

    filterForm.addEventListener('submit', (event) => {
        event.preventDefault();
        const params = paramsFromForm();
        params.set('page', '1');
        navigate(params);
    });

    clearButton.addEventListener('click', () => {
        filterForm.reset();
        advancedFilters.classList.add('hidden');
        navigate(new URLSearchParams());
    });

    advancedToggle.addEventListener('click', () => {
        advancedFilters.classList.toggle('hidden');
    });

    refreshButton.addEventListener('click', () => {
        loadSummaryCards();
        loadBookings(currentParams());
    });

    prevPageButton.addEventListener('click', () => goToPage(currentParams(), currentPage - 1));
    nextPageButton.addEventListener('click', () => goToPage(currentParams(), currentPage + 1));

    window.addEventListener('popstate', () => {
        const params = currentParams();
        applyParamsToForm(params);
        loadBookings(params);
    });

    const initialParams = currentParams();
    applyParamsToForm(initialParams);

    adminAuthReady().then((ready) => {
        if (ready) {
            loadSummaryCards();
            loadBookings(initialParams);
        }
    });
}
