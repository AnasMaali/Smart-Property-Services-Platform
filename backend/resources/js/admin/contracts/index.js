/**
 * Admin Contracts list (BLUE V1 Phase B4). Reuses the centralized Admin API
 * client against the existing GET /v1/admin/contracts endpoint
 * (App\Actions\Admin\Contract\AdminListContractsAction / App\Support\Admin\
 * AdminContractPresenter). Only the three filters the backend actually
 * supports (status, contract_number, customer_uuid) are exposed; pagination
 * reflects exactly what the API returns. Mirrors resources/js/admin/
 * bookings/index.js and technicians/index.js exactly.
 */

import { request, ApiError } from '../lib/api-client.js';
import { adminAuthReady } from '../auth/restore.js';
import { statusBadgeClasses, statusLabel, formatDateTime } from '../lib/format.js';

const page = document.querySelector('[data-contracts-page]');

if (page) {
    const filterForm = page.querySelector('[data-contracts-filter-form]');
    const clearButton = page.querySelector('[data-contracts-clear-filters]');
    const loadingEl = page.querySelector('[data-contracts-loading]');
    const errorEl = page.querySelector('[data-contracts-error]');
    const emptyEl = page.querySelector('[data-contracts-empty]');
    const tableWrapper = page.querySelector('[data-contracts-table-wrapper]');
    const tableBody = page.querySelector('[data-contracts-body]');
    const pagination = page.querySelector('[data-contracts-pagination]');
    const paginationSummary = page.querySelector('[data-contracts-pagination-summary]');
    const prevPageButton = page.querySelector('[data-contracts-prev-page]');
    const nextPageButton = page.querySelector('[data-contracts-next-page]');

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

    function formatTerm(contract) {
        if (!contract.starts_at && !contract.ends_at) {
            return '—';
        }

        return `${formatDateTime(contract.starts_at)} → ${formatDateTime(contract.ends_at)}`;
    }

    function renderRow(contract) {
        const row = document.createElement('tr');
        row.className = 'hover:bg-slate-50';

        const numberCell = document.createElement('td');
        numberCell.className = 'px-5 py-3.5 font-medium text-slate-900';
        numberCell.textContent = contract.contract_number || '—';

        const statusCell = document.createElement('td');
        statusCell.className = 'px-5 py-3.5';
        const badge = document.createElement('span');
        badge.className = `rounded-full px-2.5 py-1 text-xs font-semibold ${statusBadgeClasses(contract.status)}`;
        badge.textContent = statusLabel(contract.status);
        statusCell.appendChild(badge);

        const customerCell = document.createElement('td');
        customerCell.className = 'px-5 py-3.5 text-slate-700';
        customerCell.textContent = contract.customer
            ? `${contract.customer.full_name || ''} (${contract.customer.phone_number || ''})`
            : '—';

        const itemsCell = document.createElement('td');
        itemsCell.className = 'px-5 py-3.5 text-slate-700';
        itemsCell.textContent = String(contract.items_count ?? 0);

        const termCell = document.createElement('td');
        termCell.className = 'px-5 py-3.5 text-slate-500';
        termCell.textContent = formatTerm(contract);

        const createdCell = document.createElement('td');
        createdCell.className = 'px-5 py-3.5 text-slate-500';
        createdCell.textContent = formatDateTime(contract.created_at);

        const linkCell = document.createElement('td');
        linkCell.className = 'px-5 py-3.5 text-right';

        const link = document.createElement('a');
        link.href = `/admin/contracts/${encodeURIComponent(contract.uuid)}`;
        link.className = 'text-sm font-medium text-blue-600 hover:text-blue-800';
        link.textContent = 'View';

        linkCell.appendChild(link);
        row.append(numberCell, statusCell, customerCell, itemsCell, termCell, createdCell, linkCell);

        return row;
    }

    async function loadContracts(params) {
        setState('loading');

        try {
            const response = await request(`/api/v1/admin/contracts?${params.toString()}`);
            const contracts = response.data.contracts || [];
            const pageInfo = response.data.pagination;

            tableBody.replaceChildren(...contracts.map(renderRow));

            if (contracts.length === 0) {
                setState('empty');
                return;
            }

            setState('ready');
            renderPagination(pageInfo, params);
        } catch (error) {
            const message = error instanceof ApiError ? error.message : 'Unable to load contracts.';
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
        const url = query ? `/admin/contracts?${query}` : '/admin/contracts';
        window.history.pushState({}, '', url);
        loadContracts(params);
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
        loadContracts(params);
    });

    const initialParams = currentParams();
    applyParamsToForm(initialParams);

    adminAuthReady().then((ready) => {
        if (ready) {
            loadContracts(initialParams);
        }
    });
}
