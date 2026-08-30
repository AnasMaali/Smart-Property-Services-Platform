/**
 * Admin Pricing Scheme Version list (BLUE V1 Phase B9). Reuses the
 * centralized Admin API client against GET /v1/admin/pricing-schemes
 * (App\Actions\Admin\Pricing\AdminListPricingSchemesAction / App\Support\
 * Admin\AdminPricingSchemePresenter). Only the filters the backend
 * actually supports (service_uuid, status, currency) are exposed.
 */

import { request, ApiError } from '../lib/api-client.js';
import { adminAuthReady } from '../auth/restore.js';
import { statusBadgeClasses, statusLabel, formatDateTime } from '../lib/format.js';

const page = document.querySelector('[data-pricing-page]');

if (page) {
    const filterForm = page.querySelector('[data-pricing-filter-form]');
    const clearButton = page.querySelector('[data-pricing-clear-filters]');
    const loadingEl = page.querySelector('[data-pricing-loading]');
    const errorEl = page.querySelector('[data-pricing-error]');
    const emptyEl = page.querySelector('[data-pricing-empty]');
    const tableWrapper = page.querySelector('[data-pricing-table-wrapper]');
    const tableBody = page.querySelector('[data-pricing-body]');
    const pagination = page.querySelector('[data-pricing-pagination]');
    const paginationSummary = page.querySelector('[data-pricing-pagination-summary]');
    const prevPageButton = page.querySelector('[data-pricing-prev-page]');
    const nextPageButton = page.querySelector('[data-pricing-next-page]');

    const createDraftForm = page.querySelector('[data-create-draft-form]');
    const createDraftSubmit = createDraftForm.querySelector('[data-create-draft-submit]');
    const createDraftError = page.querySelector('[data-create-draft-error]');

    const FILTER_FIELDS = ['status', 'currency', 'service_uuid'];

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

    function renderRow(scheme) {
        const row = document.createElement('tr');
        row.className = 'hover:bg-slate-50';

        const serviceCell = document.createElement('td');
        serviceCell.className = 'px-5 py-3.5 font-medium text-slate-900';
        serviceCell.textContent = scheme.service.name;

        const currencyCell = document.createElement('td');
        currencyCell.className = 'px-5 py-3.5 text-slate-700';
        currencyCell.textContent = scheme.currency.code;

        const statusCell = document.createElement('td');
        statusCell.className = 'px-5 py-3.5';
        const badge = document.createElement('span');
        badge.className = `rounded-full px-2.5 py-1 text-xs font-semibold ${statusBadgeClasses(scheme.status)}`;
        badge.textContent = statusLabel(scheme.status);
        statusCell.appendChild(badge);

        const fromCell = document.createElement('td');
        fromCell.className = 'px-5 py-3.5 text-slate-500';
        fromCell.textContent = scheme.effective_from ? formatDateTime(scheme.effective_from) : '—';

        const toCell = document.createElement('td');
        toCell.className = 'px-5 py-3.5 text-slate-500';
        toCell.textContent = scheme.effective_to ? formatDateTime(scheme.effective_to) : (scheme.effective_from ? 'Open-ended' : '—');

        const rulesCell = document.createElement('td');
        rulesCell.className = 'px-5 py-3.5 text-slate-500';
        rulesCell.textContent = String(scheme.rules_count);

        const updatedCell = document.createElement('td');
        updatedCell.className = 'px-5 py-3.5 text-slate-500';
        updatedCell.textContent = formatDateTime(scheme.updated_at);

        const linkCell = document.createElement('td');
        linkCell.className = 'px-5 py-3.5 text-right';
        const link = document.createElement('a');
        link.href = `/admin/pricing/${encodeURIComponent(scheme.uuid)}`;
        link.className = 'text-sm font-medium text-blue-600 hover:text-blue-800';
        link.textContent = 'View';
        linkCell.appendChild(link);

        row.append(serviceCell, currencyCell, statusCell, fromCell, toCell, rulesCell, updatedCell, linkCell);

        return row;
    }

    async function loadSchemes(params) {
        setState('loading');

        try {
            const response = await request(`/api/v1/admin/pricing-schemes?${params.toString()}`);
            const schemes = response.data.pricing_schemes || [];
            const pageInfo = response.data.pagination;

            tableBody.replaceChildren(...schemes.map(renderRow));

            if (schemes.length === 0) {
                setState('empty');
                return;
            }

            setState('ready');
            renderPagination(pageInfo, params);
        } catch (error) {
            const message = error instanceof ApiError ? error.message : 'Unable to load pricing schemes.';
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
        const url = query ? `/admin/pricing?${query}` : '/admin/pricing';
        window.history.pushState({}, '', url);
        loadSchemes(params);
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
        loadSchemes(params);
    });

    createDraftForm.addEventListener('submit', async (event) => {
        event.preventDefault();
        createDraftError.classList.add('hidden');
        createDraftSubmit.disabled = true;

        try {
            const response = await request('/api/v1/admin/pricing-schemes', {
                method: 'POST',
                body: {
                    service_uuid: createDraftForm.elements.namedItem('service_uuid').value.trim(),
                    currency_code: createDraftForm.elements.namedItem('currency_code').value.trim(),
                },
            });

            window.location.assign(`/admin/pricing/${encodeURIComponent(response.data.pricing_scheme.uuid)}`);
        } catch (error) {
            createDraftError.textContent = error instanceof ApiError ? error.message : 'Unable to create this draft.';
            createDraftError.classList.remove('hidden');
            createDraftSubmit.disabled = false;
        }
    });

    const initialParams = currentParams();
    applyParamsToForm(initialParams);

    adminAuthReady().then((ready) => {
        if (ready) {
            loadSchemes(initialParams);
        }
    });
}
