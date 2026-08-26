/**
 * Admin Contract Billing list (BLUE V1 Phase B5). Reuses the centralized
 * Admin API client against GET /v1/admin/contract-billings
 * (App\Actions\Admin\ContractBilling\AdminListContractBillingsAction /
 * App\Support\Admin\AdminContractBillingPresenter). Only the filters the
 * backend actually supports (status, contract_number, customer_uuid) are
 * exposed. Mirrors resources/js/admin/contracts/index.js exactly. Read-only
 * - no mutation exists for this module.
 */

import { request, ApiError } from '../lib/api-client.js';
import { adminAuthReady } from '../auth/restore.js';
import { statusBadgeClasses, statusLabel, formatDateTime, formatMoney } from '../lib/format.js';

const page = document.querySelector('[data-billing-page]');

if (page) {
    const filterForm = page.querySelector('[data-billing-filter-form]');
    const clearButton = page.querySelector('[data-billing-clear-filters]');
    const loadingEl = page.querySelector('[data-billing-loading]');
    const errorEl = page.querySelector('[data-billing-error]');
    const emptyEl = page.querySelector('[data-billing-empty]');
    const tableWrapper = page.querySelector('[data-billing-table-wrapper]');
    const tableBody = page.querySelector('[data-billing-body]');
    const pagination = page.querySelector('[data-billing-pagination]');
    const paginationSummary = page.querySelector('[data-billing-pagination-summary]');
    const prevPageButton = page.querySelector('[data-billing-prev-page]');
    const nextPageButton = page.querySelector('[data-billing-next-page]');

    const FILTER_FIELDS = ['status', 'contract_number', 'customer_uuid'];

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

    function renderRow(billing) {
        const row = document.createElement('tr');
        row.className = 'hover:bg-slate-50';

        const contractCell = document.createElement('td');
        contractCell.className = 'px-5 py-3.5';
        const contractLink = document.createElement('a');
        contractLink.href = `/admin/contracts/${encodeURIComponent(billing.contract.uuid)}`;
        contractLink.className = 'font-medium text-blue-600 hover:text-blue-800';
        contractLink.textContent = billing.contract.contract_number;
        contractCell.appendChild(contractLink);

        const customerCell = document.createElement('td');
        customerCell.className = 'px-5 py-3.5 text-slate-700';
        customerCell.textContent = billing.customer
            ? `${billing.customer.full_name || ''} (${billing.customer.phone_number || ''})`
            : '—';

        const statusCell = document.createElement('td');
        statusCell.className = 'px-5 py-3.5';
        const badge = document.createElement('span');
        badge.className = `rounded-full px-2.5 py-1 text-xs font-semibold ${statusBadgeClasses(billing.status)}`;
        badge.textContent = statusLabel(billing.status);
        statusCell.appendChild(badge);

        const amountCell = document.createElement('td');
        amountCell.className = 'px-5 py-3.5 text-slate-700';
        amountCell.textContent = `${formatMoney(billing.recurring_amount, billing.currency)} / ${statusLabel(billing.billing_interval).toLowerCase()}`;

        const periodEndCell = document.createElement('td');
        periodEndCell.className = 'px-5 py-3.5 text-slate-500';
        periodEndCell.textContent = billing.current_period_end ? formatDateTime(billing.current_period_end) : '—';

        const cancelAtCell = document.createElement('td');
        cancelAtCell.className = 'px-5 py-3.5 text-slate-500';
        cancelAtCell.textContent = billing.cancel_at ? formatDateTime(billing.cancel_at) : '—';

        const linkCell = document.createElement('td');
        linkCell.className = 'px-5 py-3.5 text-right';
        const link = document.createElement('a');
        link.href = `/admin/billing/${encodeURIComponent(billing.uuid)}`;
        link.className = 'text-sm font-medium text-blue-600 hover:text-blue-800';
        link.textContent = 'View';
        linkCell.appendChild(link);

        row.append(contractCell, customerCell, statusCell, amountCell, periodEndCell, cancelAtCell, linkCell);

        return row;
    }

    async function loadBillings(params) {
        setState('loading');

        try {
            const response = await request(`/api/v1/admin/contract-billings?${params.toString()}`);
            const billings = response.data.contract_billings || [];
            const pageInfo = response.data.pagination;

            tableBody.replaceChildren(...billings.map(renderRow));

            if (billings.length === 0) {
                setState('empty');
                return;
            }

            setState('ready');
            renderPagination(pageInfo, params);
        } catch (error) {
            const message = error instanceof ApiError ? error.message : 'Unable to load contract billings.';
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
        const url = query ? `/admin/billing?${query}` : '/admin/billing';
        window.history.pushState({}, '', url);
        loadBillings(params);
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
        loadBillings(params);
    });

    const initialParams = currentParams();
    applyParamsToForm(initialParams);

    adminAuthReady().then((ready) => {
        if (ready) {
            loadBillings(initialParams);
        }
    });
}
