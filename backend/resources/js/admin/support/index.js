/**
 * Admin Support Requests list (BLUE V1 Phase B7). Reuses the centralized
 * Admin API client against GET /v1/admin/support-requests
 * (App\Actions\Admin\Support\AdminListSupportRequestsAction /
 * App\Support\Admin\AdminSupportRequestPresenter). Only the filters the
 * backend actually supports (status, search, unassigned, customer_uuid) are
 * exposed. Mirrors resources/js/admin/customers/index.js exactly.
 */

import { request, ApiError } from '../lib/api-client.js';
import { statusBadgeClasses, statusLabel, formatDateTime } from '../lib/format.js';

const page = document.querySelector('[data-support-page]');

if (page) {
    const filterForm = page.querySelector('[data-support-filter-form]');
    const clearButton = page.querySelector('[data-support-clear-filters]');
    const loadingEl = page.querySelector('[data-support-loading]');
    const errorEl = page.querySelector('[data-support-error]');
    const emptyEl = page.querySelector('[data-support-empty]');
    const tableWrapper = page.querySelector('[data-support-table-wrapper]');
    const tableBody = page.querySelector('[data-support-body]');
    const pagination = page.querySelector('[data-support-pagination]');
    const paginationSummary = page.querySelector('[data-support-pagination-summary]');
    const prevPageButton = page.querySelector('[data-support-prev-page]');
    const nextPageButton = page.querySelector('[data-support-next-page]');

    const FILTER_FIELDS = ['status', 'search', 'unassigned', 'customer_uuid'];

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

    function renderRow(supportRequest) {
        const row = document.createElement('tr');
        row.className = 'hover:bg-slate-50';

        const requestCell = document.createElement('td');
        requestCell.className = 'px-5 py-3.5 font-medium text-slate-900';
        requestCell.textContent = supportRequest.request_number;

        const customerCell = document.createElement('td');
        customerCell.className = 'px-5 py-3.5 text-slate-700';
        customerCell.textContent = supportRequest.customer
            ? `${supportRequest.customer.full_name} · ${supportRequest.customer.phone_number}`
            : '—';

        const subjectCell = document.createElement('td');
        subjectCell.className = 'px-5 py-3.5 text-slate-700';
        subjectCell.textContent = supportRequest.subject;

        const statusCell = document.createElement('td');
        statusCell.className = 'px-5 py-3.5';
        const badge = document.createElement('span');
        badge.className = `rounded-full px-2.5 py-1 text-xs font-semibold ${statusBadgeClasses(supportRequest.status)}`;
        badge.textContent = statusLabel(supportRequest.status);
        statusCell.appendChild(badge);

        const assignedCell = document.createElement('td');
        assignedCell.className = 'px-5 py-3.5 text-slate-500';
        assignedCell.textContent = supportRequest.assigned_admin ? supportRequest.assigned_admin.full_name : 'Unassigned';

        const createdCell = document.createElement('td');
        createdCell.className = 'px-5 py-3.5 text-slate-500';
        createdCell.textContent = formatDateTime(supportRequest.created_at);

        const linkCell = document.createElement('td');
        linkCell.className = 'px-5 py-3.5 text-right';
        const link = document.createElement('a');
        link.href = `/admin/support/${encodeURIComponent(supportRequest.uuid)}`;
        link.className = 'text-sm font-medium text-blue-600 hover:text-blue-800';
        link.textContent = 'View';
        linkCell.appendChild(link);

        row.append(requestCell, customerCell, subjectCell, statusCell, assignedCell, createdCell, linkCell);

        return row;
    }

    async function loadSupportRequests(params) {
        setState('loading');

        try {
            const response = await request(`/api/v1/admin/support-requests?${params.toString()}`);
            const supportRequests = response.data.support_requests || [];
            const pageInfo = response.data.pagination;

            tableBody.replaceChildren(...supportRequests.map(renderRow));

            if (supportRequests.length === 0) {
                setState('empty');
                return;
            }

            setState('ready');
            renderPagination(pageInfo, params);
        } catch (error) {
            const message = error instanceof ApiError ? error.message : 'Unable to load support requests.';
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
        const url = query ? `/admin/support?${query}` : '/admin/support';
        window.history.pushState({}, '', url);
        loadSupportRequests(params);
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
        loadSupportRequests(params);
    });

    const initialParams = currentParams();
    applyParamsToForm(initialParams);
    loadSupportRequests(initialParams);
}
