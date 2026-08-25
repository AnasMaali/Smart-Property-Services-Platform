/**
 * Admin Payments list (BLUE V1 Phase B5). Reuses the centralized Admin API
 * client against GET /v1/admin/payments (App\Actions\Admin\Payment\
 * AdminListPaymentsAction / App\Support\Admin\AdminPaymentPresenter). Only
 * the filters the backend actually supports (status, checkout_reference,
 * customer_uuid) are exposed. Mirrors resources/js/admin/bookings/index.js
 * exactly. Read-only - no mutation exists for this module.
 */

import { request, ApiError } from '../lib/api-client.js';
import { statusBadgeClasses, statusLabel, formatDateTime, formatMoney } from '../lib/format.js';

const page = document.querySelector('[data-payments-page]');

if (page) {
    const filterForm = page.querySelector('[data-payments-filter-form]');
    const clearButton = page.querySelector('[data-payments-clear-filters]');
    const loadingEl = page.querySelector('[data-payments-loading]');
    const errorEl = page.querySelector('[data-payments-error]');
    const emptyEl = page.querySelector('[data-payments-empty]');
    const tableWrapper = page.querySelector('[data-payments-table-wrapper]');
    const tableBody = page.querySelector('[data-payments-body]');
    const pagination = page.querySelector('[data-payments-pagination]');
    const paginationSummary = page.querySelector('[data-payments-pagination-summary]');
    const prevPageButton = page.querySelector('[data-payments-prev-page]');
    const nextPageButton = page.querySelector('[data-payments-next-page]');

    const FILTER_FIELDS = ['status', 'checkout_reference', 'customer_uuid'];

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

    function renderRow(payment) {
        const row = document.createElement('tr');
        row.className = 'hover:bg-slate-50';

        const referenceCell = document.createElement('td');
        referenceCell.className = 'px-5 py-3.5 font-medium text-slate-900';
        referenceCell.textContent = payment.checkout_reference;

        const statusCell = document.createElement('td');
        statusCell.className = 'px-5 py-3.5';
        const badge = document.createElement('span');
        badge.className = `rounded-full px-2.5 py-1 text-xs font-semibold ${statusBadgeClasses(payment.status)}`;
        badge.textContent = statusLabel(payment.status);
        statusCell.appendChild(badge);

        const customerCell = document.createElement('td');
        customerCell.className = 'px-5 py-3.5 text-slate-700';
        customerCell.textContent = payment.customer
            ? `${payment.customer.full_name || ''} (${payment.customer.phone_number || ''})`
            : '—';

        const amountCell = document.createElement('td');
        amountCell.className = 'px-5 py-3.5 text-slate-700';
        amountCell.textContent = formatMoney(payment.confirmed_amount ?? payment.requested_amount, payment.currency);

        const bookingCell = document.createElement('td');
        bookingCell.className = 'px-5 py-3.5';

        if (payment.booking_uuid) {
            const bookingLink = document.createElement('a');
            bookingLink.href = `/admin/bookings/${encodeURIComponent(payment.booking_uuid)}`;
            bookingLink.className = 'text-sm font-medium text-blue-600 hover:text-blue-800';
            bookingLink.textContent = 'View booking';
            bookingCell.appendChild(bookingLink);
        } else {
            bookingCell.textContent = '—';
            bookingCell.className += ' text-slate-400';
        }

        const createdCell = document.createElement('td');
        createdCell.className = 'px-5 py-3.5 text-slate-500';
        createdCell.textContent = formatDateTime(payment.created_at);

        const linkCell = document.createElement('td');
        linkCell.className = 'px-5 py-3.5 text-right';

        const link = document.createElement('a');
        link.href = `/admin/payments/${encodeURIComponent(payment.uuid)}`;
        link.className = 'text-sm font-medium text-blue-600 hover:text-blue-800';
        link.textContent = 'View';

        linkCell.appendChild(link);
        row.append(referenceCell, statusCell, customerCell, amountCell, bookingCell, createdCell, linkCell);

        return row;
    }

    async function loadPayments(params) {
        setState('loading');

        try {
            const response = await request(`/api/v1/admin/payments?${params.toString()}`);
            const payments = response.data.payments || [];
            const pageInfo = response.data.pagination;

            tableBody.replaceChildren(...payments.map(renderRow));

            if (payments.length === 0) {
                setState('empty');
                return;
            }

            setState('ready');
            renderPagination(pageInfo, params);
        } catch (error) {
            const message = error instanceof ApiError ? error.message : 'Unable to load payments.';
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
        const url = query ? `/admin/payments?${query}` : '/admin/payments';
        window.history.pushState({}, '', url);
        loadPayments(params);
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
        loadPayments(params);
    });

    const initialParams = currentParams();
    applyParamsToForm(initialParams);
    loadPayments(initialParams);
}
