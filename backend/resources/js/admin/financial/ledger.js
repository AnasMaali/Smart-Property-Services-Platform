/**
 * Admin Financial Ledger list. Reuses the centralized Admin API client
 * against GET /v1/admin/financial-ledger (App\Actions\Admin\Financial\
 * AdminListFinancialLedgerAction / App\Support\Admin\
 * AdminFinancialLedgerPresenter). Mirrors resources/js/admin/payments/
 * index.js's filter/pagination/URL-sync conventions exactly. Read-only -
 * no mutation exists for this module.
 */

import { request, ApiError } from '../lib/api-client.js';
import { statusLabel, formatDateTime, formatMoney } from '../lib/format.js';
import { adminAuthReady } from '../auth/restore.js';

const page = document.querySelector('[data-ledger-page]');

if (page) {
    const filterForm = page.querySelector('[data-ledger-filter-form]');
    const clearButton = page.querySelector('[data-ledger-clear-filters]');
    const loadingEl = page.querySelector('[data-ledger-loading]');
    const errorEl = page.querySelector('[data-ledger-error]');
    const emptyEl = page.querySelector('[data-ledger-empty]');
    const tableWrapper = page.querySelector('[data-ledger-table-wrapper]');
    const tableBody = page.querySelector('[data-ledger-body]');
    const pagination = page.querySelector('[data-ledger-pagination]');
    const paginationSummary = page.querySelector('[data-ledger-pagination-summary]');
    const prevPageButton = page.querySelector('[data-ledger-prev-page]');
    const nextPageButton = page.querySelector('[data-ledger-next-page]');

    const FILTER_FIELDS = ['event_type', 'direction', 'from', 'to', 'booking_uuid'];

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

    function renderRow(entry) {
        const row = document.createElement('tr');
        row.className = 'hover:bg-slate-50';

        const occurredCell = document.createElement('td');
        occurredCell.className = 'px-5 py-3.5 text-slate-500';
        occurredCell.textContent = formatDateTime(entry.occurred_at);

        const eventCell = document.createElement('td');
        eventCell.className = 'px-5 py-3.5 font-medium text-slate-900';
        eventCell.textContent = statusLabel(entry.event_type);

        const directionCell = document.createElement('td');
        directionCell.className = 'px-5 py-3.5';
        const badge = document.createElement('span');
        badge.className = `rounded-full px-2.5 py-1 text-xs font-semibold ${entry.direction === 'CREDIT' ? 'bg-emerald-50 text-emerald-700' : 'bg-red-50 text-red-700'}`;
        badge.textContent = entry.direction === 'CREDIT' ? 'Credit' : 'Debit';
        directionCell.appendChild(badge);

        const methodCell = document.createElement('td');
        methodCell.className = 'px-5 py-3.5 text-slate-700';
        methodCell.textContent = statusLabel(entry.payment_method);

        const amountCell = document.createElement('td');
        amountCell.className = `px-5 py-3.5 font-medium ${entry.direction === 'CREDIT' ? 'text-emerald-700' : 'text-red-700'}`;
        amountCell.textContent = `${entry.direction === 'CREDIT' ? '+' : '-'}${formatMoney(entry.amount, entry.currency)}`;

        const customerCell = document.createElement('td');
        customerCell.className = 'px-5 py-3.5 text-slate-700';
        customerCell.textContent = entry.customer
            ? `${entry.customer.full_name || ''} (${entry.customer.phone_number || ''})`
            : '—';

        const bookingCell = document.createElement('td');
        bookingCell.className = 'px-5 py-3.5';

        if (entry.booking) {
            const bookingLink = document.createElement('a');
            bookingLink.href = `/admin/bookings/${encodeURIComponent(entry.booking.uuid)}`;
            bookingLink.className = 'text-sm font-medium text-blue-600 hover:text-blue-800';
            bookingLink.textContent = entry.booking.booking_number;
            bookingCell.appendChild(bookingLink);
        } else {
            bookingCell.textContent = '—';
            bookingCell.className += ' text-slate-400';
        }

        row.append(occurredCell, eventCell, directionCell, methodCell, amountCell, customerCell, bookingCell);

        return row;
    }

    async function loadLedger(params) {
        setState('loading');

        try {
            const response = await request(`/api/v1/admin/financial-ledger?${params.toString()}`);
            const entries = response.data.entries || [];
            const pageInfo = response.data.pagination;

            tableBody.replaceChildren(...entries.map(renderRow));

            if (entries.length === 0) {
                setState('empty');
                return;
            }

            setState('ready');
            renderPagination(pageInfo, params);
        } catch (error) {
            const message = error instanceof ApiError ? error.message : 'Unable to load the financial ledger.';
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
        const url = query ? `/admin/finance/ledger?${query}` : '/admin/finance/ledger';
        window.history.pushState({}, '', url);
        loadLedger(params);
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
        loadLedger(params);
    });

    const initialParams = currentParams();
    applyParamsToForm(initialParams);

    adminAuthReady().then((ready) => {
        if (ready) {
            loadLedger(initialParams);
        }
    });
}
