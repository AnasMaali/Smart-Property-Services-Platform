/**
 * Admin Bookings list (BLUE V1 Phase B14).
 *
 * This page is an operational view over the existing Admin Booking API.
 * It never re-implements Booking lifecycle, assignment eligibility,
 * payment rules, or technician scheduling. Every value rendered here
 * comes from GET /v1/admin/bookings or the already-existing Dashboard
 * summary endpoint.
 */

import { request, ApiError } from '../lib/api-client.js';
import { adminAuthReady } from '../auth/restore.js';
import { statusBadgeClasses, statusLabel, formatDateTime } from '../lib/format.js';

const ASSIGNMENT_BADGE_CLASSES = {
    PENDING: 'bg-amber-50 text-amber-700 ring-1 ring-inset ring-amber-200',
    PARTIAL: 'bg-blue-50 text-blue-700 ring-1 ring-inset ring-blue-200',
    FULL: 'bg-emerald-50 text-emerald-700 ring-1 ring-inset ring-emerald-200',
};

const ASSIGNMENT_LABELS = {
    PENDING: 'Needs assignment',
    PARTIAL: 'Partially assigned',
    FULL: 'Fully assigned',
};

const QUICK_FILTER_ACTIVE_CLASSES = [
    'bg-slate-950',
    'text-white',
    'shadow-sm',
];

const QUICK_FILTER_INACTIVE_CLASSES = [
    'text-slate-600',
];

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

    return date.toLocaleDateString(undefined, {
        year: 'numeric',
        month: 'short',
        day: 'numeric',
    });
}

function formatTimeOnly(iso) {
    if (!iso) {
        return '—';
    }

    const date = new Date(iso);

    if (Number.isNaN(date.getTime())) {
        return '—';
    }

    return date.toLocaleTimeString(undefined, {
        hour: '2-digit',
        minute: '2-digit',
    });
}

function formatAppointmentTime(slot) {
    if (!slot?.starts_at || !slot?.ends_at) {
        return '—';
    }

    return `${formatTimeOnly(slot.starts_at)} – ${formatTimeOnly(slot.ends_at)}`;
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

function itemsSummary(count) {
    const value = Number(count || 0);

    if (value === 1) {
        return '1 booking item';
    }

    return `${value} booking items`;
}

function moneySummary(booking) {
    if (booking.source === 'CONTRACT' && !booking.payment) {
        return 'Included in contract';
    }

    const amount = Number.parseFloat(booking.total);

    if (!Number.isFinite(amount)) {
        return '—';
    }

    const decimals = Number.isInteger(booking.currency?.decimal_places)
        ? booking.currency.decimal_places
        : 2;

    const formatted = amount.toLocaleString(undefined, {
        minimumFractionDigits: decimals,
        maximumFractionDigits: decimals,
    });

    const symbol = booking.currency?.symbol?.trim();
    const code = booking.currency?.code?.trim();

    if (symbol) {
        return `${symbol} ${formatted}`;
    }

    return code ? `${formatted} ${code}` : formatted;
}

function attentionMeta(booking) {
    if (booking.status === 'CANCELLED') {
        return {
            label: 'Cancelled',
            classes: 'bg-red-50 text-red-700 ring-1 ring-inset ring-red-200',
            boxClasses: 'border-red-100 bg-red-50/50',
            rowClasses: [],
        };
    }

    if (booking.status === 'COMPLETED') {
        return {
            label: 'Completed',
            classes: 'bg-emerald-50 text-emerald-700 ring-1 ring-inset ring-emerald-200',
            boxClasses: 'border-emerald-100 bg-emerald-50/50',
            rowClasses: [],
        };
    }

    if (booking.assignment_state === 'PENDING') {
        return {
            label: 'Needs technician',
            classes: 'bg-amber-50 text-amber-800 ring-1 ring-inset ring-amber-200',
            boxClasses: 'border-amber-200 bg-amber-50/60',
            rowClasses: ['bg-amber-50/20'],
        };
    }

    if (booking.assignment_state === 'PARTIAL') {
        return {
            label: 'Partial assignment',
            classes: 'bg-blue-50 text-blue-700 ring-1 ring-inset ring-blue-200',
            boxClasses: 'border-blue-100 bg-blue-50/50',
            rowClasses: [],
        };
    }

    if (booking.status === 'IN_PROGRESS') {
        return {
            label: 'Work in progress',
            classes: 'bg-indigo-50 text-indigo-700 ring-1 ring-inset ring-indigo-200',
            boxClasses: 'border-indigo-100 bg-indigo-50/50',
            rowClasses: [],
        };
    }

    if (booking.status === 'ASSIGNED') {
        return {
            label: 'Ready for service',
            classes: 'bg-blue-50 text-blue-700 ring-1 ring-inset ring-blue-200',
            boxClasses: 'border-blue-100 bg-blue-50/50',
            rowClasses: [],
        };
    }

    return {
        label: 'On track',
        classes: 'bg-slate-100 text-slate-600',
        boxClasses: 'border-slate-100 bg-slate-50/70',
        rowClasses: [],
    };
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
    const errorMessageEl = page.querySelector('[data-bookings-error-message]');
    const errorRetryButton = page.querySelector('[data-bookings-error-retry]');
    const emptyEl = page.querySelector('[data-bookings-empty]');
    const emptyClearButton = page.querySelector('[data-bookings-empty-clear]');
    const tableWrapper = page.querySelector('[data-bookings-table-wrapper]');
    const tableBody = page.querySelector('[data-bookings-body]');
    const cardsContainer = page.querySelector('[data-bookings-cards]');
    const pagination = page.querySelector('[data-bookings-pagination]');
    const paginationSummary = page.querySelector('[data-bookings-pagination-summary]');
    const prevPageButton = page.querySelector('[data-bookings-prev-page]');
    const nextPageButton = page.querySelector('[data-bookings-next-page]');
    const summaryCardsEl = page.querySelector('[data-summary-cards]');
    const lastRefreshedEl = page.querySelector('[data-bookings-last-refreshed]');
    const resultsCountEl = page.querySelector('[data-bookings-results-count]');
    const activeFilterSummaryEl = page.querySelector('[data-bookings-active-filter-summary]');
    const quickFilterButtons = [...page.querySelectorAll('[data-bookings-quick-filter]')];

    const summaryCardTemplate = document.querySelector('[data-summary-card-template]');
    const rowTemplate = document.querySelector('[data-booking-row-template]');
    const cardTemplate = document.querySelector('[data-booking-card-template]');

    const SUMMARY_CARDS = [
        ['active', 'Active bookings', 'Non-terminal bookings'],
        ['pending_assignment', 'Needs assignment', 'Booking items waiting for a technician'],
        ['in_progress', 'In progress', 'Booking items currently being serviced'],
        ['created_last_24h', 'Created (24h)', 'New bookings in the rolling 24-hour window'],
    ];

    const FILTER_FIELDS = [
        'status',
        'booking_number',
        'customer_uuid',
        'technician_uuid',
        'service_uuid',
        'assignment_state',
        'from',
        'to',
        'appointment_date',
    ];

    const ADVANCED_FIELDS = [
        'customer_uuid',
        'technician_uuid',
        'service_uuid',
        'from',
        'to',
    ];

    let currentPage = 1;

    function currentParams() {
        return new URLSearchParams(window.location.search);
    }

    function setAdvancedFiltersVisible(visible) {
        advancedFilters.classList.toggle('hidden', !visible);
        advancedToggle.setAttribute('aria-expanded', visible ? 'true' : 'false');
    }

    function applyParamsToForm(params) {
        FILTER_FIELDS.forEach((field) => {
            const input = filterForm.elements.namedItem(field);

            if (input) {
                input.value = params.get(field) || '';
            }
        });

        setAdvancedFiltersVisible(
            ADVANCED_FIELDS.some((field) => Boolean(params.get(field))),
        );
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

    function filterSummary(params) {
        const parts = [];

        if (params.get('booking_number')) {
            parts.push(`Booking ${params.get('booking_number')}`);
        }

        if (params.get('status')) {
            parts.push(statusLabel(params.get('status')));
        }

        if (params.get('assignment_state')) {
            parts.push(assignmentLabel(params.get('assignment_state')));
        }

        if (params.get('appointment_date')) {
            parts.push(`Appointment ${params.get('appointment_date')}`);
        }

        if (ADVANCED_FIELDS.some((field) => params.get(field))) {
            parts.push('Advanced filters');
        }

        return parts.length > 0 ? parts.join(' · ') : 'All bookings';
    }

    function updateQuickFilterState(params) {
        const currentStatus = params.get('status') || '';
        const currentAssignment = params.get('assignment_state') || '';

        quickFilterButtons.forEach((button) => {
            const buttonStatus = button.dataset.filterStatus || '';
            const buttonAssignment = button.dataset.filterAssignmentState || '';

            const active =
                buttonStatus === currentStatus &&
                buttonAssignment === currentAssignment;

            button.setAttribute('aria-pressed', active ? 'true' : 'false');

            QUICK_FILTER_ACTIVE_CLASSES.forEach((className) => {
                button.classList.toggle(className, active);
            });

            QUICK_FILTER_INACTIVE_CLASSES.forEach((className) => {
                button.classList.toggle(className, !active);
            });
        });

        activeFilterSummaryEl.textContent = filterSummary(params);
    }

    function syncUiFromParams(params) {
        applyParamsToForm(params);
        updateQuickFilterState(params);
    }

    async function loadSummaryCards() {
        try {
            const response = await request('/api/v1/admin/dashboard');
            const bookings = response.data.summary.bookings;

            summaryCardsEl.replaceChildren(
                ...SUMMARY_CARDS.map(([field, title, subtitle]) => {
                    const node = summaryCardTemplate.content.cloneNode(true);

                    const titleEl = node.querySelector('[data-field="title"]');
                    const subtitleEl = node.querySelector('[data-field="subtitle"]');
                    const valueEl = node.querySelector('[data-field="value"]');

                    if (titleEl) {
                        titleEl.textContent = title;
                    }

                    if (subtitleEl) {
                        subtitleEl.textContent = subtitle;
                    }

                    if (valueEl) {
                        valueEl.textContent = String(bookings[field] ?? 0);
                    }

                    return node;
                }),
            );
        } catch {
            summaryCardsEl.replaceChildren();
        }
    }

    function renderBadge(el, code, classesFn, labelFn) {
        if (!el) {
            return;
        }

        el.textContent = labelFn(code);
        el.className = `inline-flex rounded-full px-2.5 py-1 text-xs font-semibold ${classesFn(code)}`;
    }

    function setText(root, selector, value) {
        const el = root.querySelector(selector);

        if (el) {
            el.textContent = value ?? '—';
        }
    }

    function fillCommonFields(root, booking) {
        const bookingHref = `/admin/bookings/${encodeURIComponent(booking.uuid)}`;

        root.querySelectorAll('[data-row-link]').forEach((link) => {
            link.href = bookingHref;
            link.textContent = booking.booking_number || 'Booking';
        });

        root.querySelectorAll('[data-open-link]').forEach((link) => {
            link.href = bookingHref;
        });

        const paymentEl = root.querySelector('[data-field="payment"]');

        if (paymentEl) {
            if (booking.payment) {
                renderBadge(
                    paymentEl,
                    booking.payment.status,
                    statusBadgeClasses,
                    statusLabel,
                );
            } else {
                paymentEl.textContent = 'Contract covered';
                paymentEl.className =
                    'inline-flex rounded-full bg-slate-100 px-2.5 py-1 text-xs font-semibold text-slate-600';
            }
        }

        renderBadge(
            root.querySelector('[data-field="assignment"]'),
            booking.assignment_state,
            assignmentBadgeClasses,
            assignmentLabel,
        );

        renderBadge(
            root.querySelector('[data-field="status"]'),
            booking.status,
            statusBadgeClasses,
            statusLabel,
        );

        setText(
            root,
            '[data-field="services"]',
            servicesSummary(booking.services),
        );

        setText(
            root,
            '[data-field="items_count"]',
            itemsSummary(booking.items_count),
        );

        setText(
            root,
            '[data-field="source"]',
            statusLabel(booking.source),
        );

        setText(
            root,
            '[data-field="created_at"]',
            formatDateTime(booking.created_at),
        );

        setText(
            root,
            '[data-field="total"]',
            moneySummary(booking),
        );

        const slot = booking.appointment?.slot;

        setText(
            root,
            '[data-field="appointment_date"]',
            slot ? formatDateOnly(slot.starts_at) : '—',
        );

        setText(
            root,
            '[data-field="appointment_time"]',
            slot ? formatAppointmentTime(slot) : '—',
        );

        setText(
            root,
            '[data-field="appointment_window"]',
            slot?.time_window?.name || 'No window label',
        );

        const attention = attentionMeta(booking);
        const attentionEl = root.querySelector('[data-field="attention"]');

        if (attentionEl) {
            attentionEl.textContent = attention.label;
            attentionEl.className =
                `inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-semibold ${attention.classes}`;
        }

        const attentionBox = root.querySelector(
            '[data-field="attention_box"]',
        );

        if (attentionBox) {
            attentionBox.className =
                `mt-4 rounded-xl border px-3.5 py-3 ${attention.boxClasses}`;
        }

        attention.rowClasses.forEach((className) => {
            root.classList.add(className);
        });
    }

    function renderRow(booking) {
        const fragment = rowTemplate.content.cloneNode(true);
        const root = fragment.querySelector('tr');

        if (!root) {
            return fragment;
        }

        fillCommonFields(root, booking);

        const customerLink = root.querySelector('[data-customer-link]');

        if (customerLink) {
            if (booking.customer) {
                customerLink.textContent =
                    booking.customer.full_name || 'Customer';

                customerLink.href =
                    `/admin/customers/${encodeURIComponent(booking.customer.uuid)}`;
            } else {
                customerLink.textContent = '—';
                customerLink.removeAttribute('href');
            }
        }

        setText(
            root,
            '[data-field="customer_phone"]',
            booking.customer?.phone_number || '',
        );

        root.addEventListener('click', (event) => {
            if (
                event.target.closest(
                    'a, button, input, select, textarea',
                )
            ) {
                return;
            }

            window.location.assign(
                `/admin/bookings/${encodeURIComponent(booking.uuid)}`,
            );
        });

        return fragment;
    }

    function renderCard(booking) {
        const fragment = cardTemplate.content.cloneNode(true);
        const root = fragment.querySelector('article');

        if (!root) {
            return fragment;
        }

        fillCommonFields(root, booking);

        setText(
            root,
            '[data-field="customer_name"]',
            booking.customer?.full_name || 'Unknown customer',
        );

        return fragment;
    }

    function setState(state) {
        loadingEl.classList.toggle('hidden', state !== 'loading');
        errorEl.classList.toggle('hidden', state !== 'error');
        emptyEl.classList.toggle('hidden', state !== 'empty');
        tableWrapper.classList.toggle('hidden', state !== 'ready');

        pagination.style.display =
            state === 'ready' ? 'flex' : 'none';
    }

    function showError(message) {
        errorMessageEl.textContent = message;
        resultsCountEl.textContent = '—';
        setState('error');
    }

    function setLastRefreshed() {
        lastRefreshedEl.textContent =
            new Date().toLocaleTimeString(undefined, {
                hour: '2-digit',
                minute: '2-digit',
                second: '2-digit',
            });
    }

    async function loadBookings(params) {
        setState('loading');

        try {
            const query = new URLSearchParams(params);

            query.set(
                'page',
                params.get('page') || '1',
            );

            currentPage =
                Number.parseInt(query.get('page'), 10) || 1;

            const response = await request(
                `/api/v1/admin/bookings?${query.toString()}`,
            );

            const {
                bookings,
                pagination: meta,
            } = response.data;

            resultsCountEl.textContent = String(meta.total);
            setLastRefreshed();

            if (bookings.length === 0) {
                tableBody.replaceChildren();
                cardsContainer.replaceChildren();
                setState('empty');
                return;
            }

            tableBody.replaceChildren(
                ...bookings.map(renderRow),
            );

            cardsContainer.replaceChildren(
                ...bookings.map(renderCard),
            );

            const start =
                (meta.page - 1) * meta.per_page + 1;

            const end =
                Math.min(
                    meta.page * meta.per_page,
                    meta.total,
                );

            paginationSummary.textContent =
                `Showing ${start}–${end} of ${meta.total}`;

            prevPageButton.disabled =
                meta.page <= 1;

            nextPageButton.disabled =
                meta.page >= meta.last_page;

            setState('ready');
        } catch (error) {
            const message =
                error instanceof ApiError
                    ? error.message
                    : 'Unable to load bookings.';

            showError(message);
        }
    }

    function navigate(params) {
        const next = new URLSearchParams(params);

        if (next.get('page') === '1') {
            next.delete('page');
        }

        const query = next.toString();

        const url =
            query
                ? `/admin/bookings?${query}`
                : '/admin/bookings';

        window.history.pushState(
            {},
            '',
            url,
        );

        syncUiFromParams(next);
        loadBookings(next);
    }

    function goToPage(params, targetPage) {
        const next = new URLSearchParams(params);

        next.set(
            'page',
            String(Math.max(targetPage, 1)),
        );

        navigate(next);
    }

    function clearFilters() {
        filterForm.reset();
        setAdvancedFiltersVisible(false);

        navigate(
            new URLSearchParams(),
        );
    }

    filterForm.addEventListener(
        'submit',
        (event) => {
            event.preventDefault();

            const params =
                paramsFromForm();

            params.delete('page');

            navigate(params);
        },
    );

    clearButton.addEventListener(
        'click',
        clearFilters,
    );

    emptyClearButton.addEventListener(
        'click',
        clearFilters,
    );

    advancedToggle.addEventListener(
        'click',
        () => {
            setAdvancedFiltersVisible(
                advancedFilters.classList.contains(
                    'hidden',
                ),
            );
        },
    );

    quickFilterButtons.forEach(
        (button) => {
            button.addEventListener(
                'click',
                () => {
                    const params =
                        currentParams();

                    params.delete('status');
                    params.delete(
                        'assignment_state',
                    );
                    params.delete('page');

                    const status =
                        button.dataset
                            .filterStatus || '';

                    const assignmentState =
                        button.dataset
                            .filterAssignmentState || '';

                    if (status) {
                        params.set(
                            'status',
                            status,
                        );
                    }

                    if (assignmentState) {
                        params.set(
                            'assignment_state',
                            assignmentState,
                        );
                    }

                    navigate(params);
                },
            );
        },
    );

    refreshButton.addEventListener(
        'click',
        () => {
            loadSummaryCards();
            loadBookings(currentParams());
        },
    );

    errorRetryButton.addEventListener(
        'click',
        () => {
            loadBookings(currentParams());
        },
    );

    prevPageButton.addEventListener(
        'click',
        () => {
            goToPage(
                currentParams(),
                currentPage - 1,
            );
        },
    );

    nextPageButton.addEventListener(
        'click',
        () => {
            goToPage(
                currentParams(),
                currentPage + 1,
            );
        },
    );

    window.addEventListener(
        'popstate',
        () => {
            const params =
                currentParams();

            syncUiFromParams(params);
            loadBookings(params);
        },
    );

    const initialParams =
        currentParams();

    syncUiFromParams(initialParams);

    adminAuthReady().then(
        (ready) => {
            if (ready) {
                loadSummaryCards();
                loadBookings(initialParams);
            }
        },
    );
}