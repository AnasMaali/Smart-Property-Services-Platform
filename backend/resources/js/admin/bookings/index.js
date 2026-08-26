/**
 * Admin Bookings list (BLUE V1 Phase B2). Reuses the centralized Admin API
 * client (lib/api-client.js) - no raw fetch() here - against the existing
 * GET /v1/admin/bookings endpoint (App\Actions\Admin\Booking\
 * AdminListBookingsAction / App\Support\Admin\AdminBookingPresenter). Only
 * filters the backend actually supports are exposed; pagination reflects
 * exactly what the API returns.
 *
 * Filters/page are kept in the URL query string (history.pushState) so the
 * list is bookmarkable/shareable and survives back/forward navigation,
 * without ever inventing client-side filtering of data the server did not
 * already filter.
 */

import { request, ApiError } from '../lib/api-client.js';
import { adminAuthReady } from '../auth/restore.js';
import { statusBadgeClasses, statusLabel, formatDateTime, formatMoney } from '../lib/format.js';

const page = document.querySelector('[data-bookings-page]');

if (page) {
    const filterForm = page.querySelector('[data-bookings-filter-form]');
    const clearButton = page.querySelector('[data-bookings-clear-filters]');
    const loadingEl = page.querySelector('[data-bookings-loading]');
    const errorEl = page.querySelector('[data-bookings-error]');
    const emptyEl = page.querySelector('[data-bookings-empty]');
    const tableWrapper = page.querySelector('[data-bookings-table-wrapper]');
    const tableBody = page.querySelector('[data-bookings-body]');
    const pagination = page.querySelector('[data-bookings-pagination]');
    const paginationSummary = page.querySelector('[data-bookings-pagination-summary]');
    const prevPageButton = page.querySelector('[data-bookings-prev-page]');
    const nextPageButton = page.querySelector('[data-bookings-next-page]');

    const FILTER_FIELDS = ['status', 'booking_number', 'customer_uuid', 'from', 'to', 'appointment_date'];

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

    function renderRow(booking) {
        const row = document.createElement('tr');
        row.className = 'hover:bg-slate-50';

        const cells = [
            booking.booking_number || '—',
            null, // status - rendered separately below
            statusLabel(booking.source),
            booking.customer ? `${booking.customer.full_name || ''} (${booking.customer.phone_number || ''})` : '—',
            String(booking.items_count ?? 0),
            formatMoney(booking.total, booking.currency),
            formatDateTime(booking.created_at),
        ];

        cells.forEach((value, index) => {
            const cell = document.createElement('td');
            cell.className = 'px-5 py-3.5 text-slate-700';

            if (index === 1) {
                const badge = document.createElement('span');
                badge.className = `rounded-full px-2.5 py-1 text-xs font-semibold ${statusBadgeClasses(booking.status)}`;
                badge.textContent = statusLabel(booking.status);
                cell.appendChild(badge);
            } else {
                cell.textContent = value;
            }

            row.appendChild(cell);
        });

        const linkCell = document.createElement('td');
        linkCell.className = 'px-5 py-3.5 text-right';

        const link = document.createElement('a');
        link.href = `/admin/bookings/${encodeURIComponent(booking.uuid)}`;
        link.className = 'text-sm font-medium text-blue-600 hover:text-blue-800';
        link.textContent = 'View';

        linkCell.appendChild(link);
        row.appendChild(linkCell);

        return row;
    }

    async function loadBookings(params) {
        setState('loading');

        try {
            const response = await request(`/api/v1/admin/bookings?${params.toString()}`);
            const bookings = response.data.bookings || [];
            const pageInfo = response.data.pagination;

            tableBody.replaceChildren(...bookings.map(renderRow));

            if (bookings.length === 0) {
                setState('empty');
                return;
            }

            setState('ready');
            renderPagination(pageInfo, params);
        } catch (error) {
            const message = error instanceof ApiError ? error.message : 'Unable to load bookings.';
            showError(message);
        }
    }

    function renderPagination(pageInfo, params) {
        const { page: currentPage, per_page: perPage, total, last_page: lastPage } = pageInfo;
        const start = total === 0 ? 0 : (currentPage - 1) * perPage + 1;
        const end = Math.min(currentPage * perPage, total);

        paginationSummary.textContent = `Showing ${start}-${end} of ${total}`;

        prevPageButton.disabled = currentPage <= 1;
        nextPageButton.disabled = currentPage >= lastPage;

        prevPageButton.onclick = () => goToPage(params, currentPage - 1);
        nextPageButton.onclick = () => goToPage(params, currentPage + 1);
    }

    function goToPage(params, targetPage) {
        const next = new URLSearchParams(params);
        next.set('page', String(targetPage));
        navigate(next);
    }

    function navigate(params) {
        const query = params.toString();
        const url = query ? `/admin/bookings?${query}` : '/admin/bookings';
        window.history.pushState({}, '', url);
        loadBookings(params);
    }

    filterForm.addEventListener('submit', (event) => {
        event.preventDefault();
        const params = paramsFromForm();
        params.set('page', '1');
        navigate(params);
    });

    clearButton.addEventListener('click', () => {
        filterForm.reset();
        navigate(new URLSearchParams());
    });

    window.addEventListener('popstate', () => {
        const params = currentParams();
        applyParamsToForm(params);
        loadBookings(params);
    });

    const initialParams = currentParams();
    applyParamsToForm(initialParams);

    adminAuthReady().then((ready) => {
        if (ready) {
            loadBookings(initialParams);
        }
    });
}
