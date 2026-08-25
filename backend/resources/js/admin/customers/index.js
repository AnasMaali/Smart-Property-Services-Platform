/**
 * Admin Customers list (BLUE V1 Phase B6). Reuses the centralized Admin API
 * client against GET /v1/admin/customers (App\Actions\Admin\Customer\
 * AdminListCustomersAction / App\Support\Admin\AdminCustomerPresenter).
 * Only the filters the backend actually supports (account_status, search,
 * phone_number, email) are exposed. Mirrors resources/js/admin/technicians/
 * index.js exactly. Read-only - no mutation exists for this module.
 */

import { request, ApiError } from '../lib/api-client.js';
import { statusBadgeClasses, statusLabel, formatDateTime } from '../lib/format.js';

const page = document.querySelector('[data-customers-page]');

if (page) {
    const filterForm = page.querySelector('[data-customers-filter-form]');
    const clearButton = page.querySelector('[data-customers-clear-filters]');
    const loadingEl = page.querySelector('[data-customers-loading]');
    const errorEl = page.querySelector('[data-customers-error]');
    const emptyEl = page.querySelector('[data-customers-empty]');
    const tableWrapper = page.querySelector('[data-customers-table-wrapper]');
    const tableBody = page.querySelector('[data-customers-body]');
    const pagination = page.querySelector('[data-customers-pagination]');
    const paginationSummary = page.querySelector('[data-customers-pagination-summary]');
    const prevPageButton = page.querySelector('[data-customers-prev-page]');
    const nextPageButton = page.querySelector('[data-customers-next-page]');

    const FILTER_FIELDS = ['account_status', 'search', 'phone_number', 'email'];

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

    function renderRow(customer) {
        const row = document.createElement('tr');
        row.className = 'hover:bg-slate-50';

        const nameCell = document.createElement('td');
        nameCell.className = 'px-5 py-3.5';

        const nameLine = document.createElement('div');
        nameLine.className = 'font-medium text-slate-900';
        nameLine.textContent = customer.full_name;

        const locationLine = document.createElement('div');
        locationLine.className = 'text-xs text-slate-400';
        locationLine.textContent = customer.area ? `${customer.area.name}, ${customer.area.city_name}` : '—';

        nameCell.append(nameLine, locationLine);

        const contactCell = document.createElement('td');
        contactCell.className = 'px-5 py-3.5 text-slate-700';
        contactCell.textContent = `${customer.phone_number} · ${customer.email}`;

        const statusCell = document.createElement('td');
        statusCell.className = 'px-5 py-3.5';
        const badge = document.createElement('span');
        badge.className = `rounded-full px-2.5 py-1 text-xs font-semibold ${statusBadgeClasses(customer.account_status)}`;
        badge.textContent = statusLabel(customer.account_status);
        statusCell.appendChild(badge);

        if (customer.deletion_pending) {
            const deletionBadge = document.createElement('span');
            deletionBadge.className = 'ml-1.5 rounded-full bg-red-50 px-2.5 py-1 text-xs font-semibold text-red-700';
            deletionBadge.textContent = 'Deletion pending';
            statusCell.appendChild(deletionBadge);
        }

        const propertiesCell = document.createElement('td');
        propertiesCell.className = 'px-5 py-3.5 text-slate-700';
        propertiesCell.textContent = String(customer.active_properties_count);

        const lastLoginCell = document.createElement('td');
        lastLoginCell.className = 'px-5 py-3.5 text-slate-500';
        lastLoginCell.textContent = customer.last_login_at ? formatDateTime(customer.last_login_at) : 'Never';

        const createdCell = document.createElement('td');
        createdCell.className = 'px-5 py-3.5 text-slate-500';
        createdCell.textContent = formatDateTime(customer.created_at);

        const linkCell = document.createElement('td');
        linkCell.className = 'px-5 py-3.5 text-right';
        const link = document.createElement('a');
        link.href = `/admin/customers/${encodeURIComponent(customer.uuid)}`;
        link.className = 'text-sm font-medium text-blue-600 hover:text-blue-800';
        link.textContent = 'View';
        linkCell.appendChild(link);

        row.append(nameCell, contactCell, statusCell, propertiesCell, lastLoginCell, createdCell, linkCell);

        return row;
    }

    async function loadCustomers(params) {
        setState('loading');

        try {
            const response = await request(`/api/v1/admin/customers?${params.toString()}`);
            const customers = response.data.customers || [];
            const pageInfo = response.data.pagination;

            tableBody.replaceChildren(...customers.map(renderRow));

            if (customers.length === 0) {
                setState('empty');
                return;
            }

            setState('ready');
            renderPagination(pageInfo, params);
        } catch (error) {
            const message = error instanceof ApiError ? error.message : 'Unable to load customers.';
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
        const url = query ? `/admin/customers?${query}` : '/admin/customers';
        window.history.pushState({}, '', url);
        loadCustomers(params);
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
        loadCustomers(params);
    });

    const initialParams = currentParams();
    applyParamsToForm(initialParams);
    loadCustomers(initialParams);
}
