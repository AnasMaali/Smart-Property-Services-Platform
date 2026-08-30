/**
 * Admin Services global list (BLUE V1 Phase B8). Reuses the centralized
 * Admin API client against GET /v1/admin/services (App\Actions\Admin\
 * Service\AdminListServicesAction / App\Support\Admin\AdminServicePresenter).
 * Only the filters the backend actually supports (category_id, is_active,
 * search) are exposed. Mirrors resources/js/admin/customers/index.js
 * exactly for the list/pagination shell.
 */

import { request, ApiError } from '../lib/api-client.js';
import { adminAuthReady } from '../auth/restore.js';
import { formatDateTime } from '../lib/format.js';

const page = document.querySelector('[data-services-page]');

if (page) {
    const filterForm = page.querySelector('[data-services-filter-form]');
    const clearButton = page.querySelector('[data-services-clear-filters]');
    const categorySelect = page.querySelector('[data-category-select]');
    const loadingEl = page.querySelector('[data-services-loading]');
    const errorEl = page.querySelector('[data-services-error]');
    const emptyEl = page.querySelector('[data-services-empty]');
    const tableWrapper = page.querySelector('[data-services-table-wrapper]');
    const tableBody = page.querySelector('[data-services-body]');
    const pagination = page.querySelector('[data-services-pagination]');
    const paginationSummary = page.querySelector('[data-services-pagination-summary]');
    const prevPageButton = page.querySelector('[data-services-prev-page]');
    const nextPageButton = page.querySelector('[data-services-next-page]');

    const addServiceModal = document.querySelector('[data-add-service-modal]');
    const addServiceOpenButton = page.querySelector('[data-add-service-open]');
    const addServiceForm = addServiceModal.querySelector('[data-add-service-form]');
    const addServiceCategorySelect = addServiceModal.querySelector('[data-add-service-category-select]');
    const addServiceSubmit = addServiceModal.querySelector('[data-add-service-submit]');
    const addServiceError = addServiceModal.querySelector('[data-add-service-error]');

    const FILTER_FIELDS = ['category_id', 'is_active', 'search'];

    async function loadCategoryOptions(selectedCategoryId) {
        try {
            const response = await request('/api/v1/admin/service-categories');
            const categories = response.data.service_categories || [];

            categories.forEach((category) => {
                const option = document.createElement('option');
                option.value = String(category.id);
                option.textContent = category.name;
                categorySelect.appendChild(option);

                const createOption = document.createElement('option');
                createOption.value = String(category.id);
                createOption.textContent = category.name;
                addServiceCategorySelect.appendChild(createOption);
            });

            if (selectedCategoryId) {
                categorySelect.value = selectedCategoryId;
            }
        } catch {
            // Non-fatal: the category filter dropdown simply stays empty:
            // the rest of the page (unfiltered service list) still works.
        }
    }

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

    function renderRow(service) {
        const row = document.createElement('tr');
        row.className = 'hover:bg-slate-50';

        const nameCell = document.createElement('td');
        nameCell.className = 'px-5 py-3.5';
        const nameLine = document.createElement('div');
        nameLine.className = 'font-medium text-slate-900';
        nameLine.textContent = service.name;
        const codeLine = document.createElement('div');
        codeLine.className = 'text-xs text-slate-400';
        codeLine.textContent = service.code;
        nameCell.append(nameLine, codeLine);

        const categoryCell = document.createElement('td');
        categoryCell.className = 'px-5 py-3.5 text-slate-700';
        categoryCell.textContent = service.category.name;

        const statusCell = document.createElement('td');
        statusCell.className = 'px-5 py-3.5';
        const statusBadge = document.createElement('span');
        statusBadge.className = `rounded-full px-2.5 py-1 text-xs font-semibold ${service.is_active ? 'bg-emerald-50 text-emerald-700' : 'bg-slate-100 text-slate-600'}`;
        statusBadge.textContent = service.is_active ? 'Active' : 'Inactive';
        statusCell.appendChild(statusBadge);

        const priceCell = document.createElement('td');
        priceCell.className = 'px-5 py-3.5 text-slate-700';
        priceCell.appendChild(renderPriceCell(service.pricing));

        const orderCell = document.createElement('td');
        orderCell.className = 'px-5 py-3.5 text-slate-500';
        orderCell.textContent = String(service.display_order);

        const capabilitiesCell = document.createElement('td');
        capabilitiesCell.className = 'px-5 py-3.5 text-slate-500';
        capabilitiesCell.textContent = service.capabilities.length > 0
            ? service.capabilities.map((capability) => capability.code).join(', ')
            : '—';

        const updatedCell = document.createElement('td');
        updatedCell.className = 'px-5 py-3.5 text-slate-500';
        updatedCell.textContent = formatDateTime(service.updated_at);

        const linkCell = document.createElement('td');
        linkCell.className = 'px-5 py-3.5 text-right';
        const link = document.createElement('a');
        link.href = `/admin/services/${encodeURIComponent(service.uuid)}`;
        link.className = 'text-sm font-medium text-blue-600 hover:text-blue-800';
        link.textContent = 'View';
        linkCell.appendChild(link);

        row.append(nameCell, categoryCell, statusCell, priceCell, orderCell, capabilitiesCell, updatedCell, linkCell);

        return row;
    }

    function renderPriceCell(pricing) {
        const wrapper = document.createElement('div');

        if (!pricing || pricing.current_amount === null) {
            wrapper.className = 'text-slate-400';
            wrapper.textContent = 'Not priced';
            return wrapper;
        }

        const current = document.createElement('span');
        current.className = 'font-medium text-slate-900';
        current.textContent = pricing.current_amount;
        wrapper.appendChild(current);

        if (pricing.has_discount) {
            const original = document.createElement('span');
            original.className = 'ml-1.5 text-xs text-slate-400 line-through';
            original.textContent = pricing.original_amount;
            wrapper.appendChild(original);
        }

        return wrapper;
    }

    async function loadServices(params) {
        setState('loading');

        try {
            const response = await request(`/api/v1/admin/services?${params.toString()}`);
            const services = response.data.services || [];
            const pageInfo = response.data.pagination;

            tableBody.replaceChildren(...services.map(renderRow));

            if (services.length === 0) {
                setState('empty');
                return;
            }

            setState('ready');
            renderPagination(pageInfo, params);
        } catch (error) {
            const message = error instanceof ApiError ? error.message : 'Unable to load services.';
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
        const url = query ? `/admin/services?${query}` : '/admin/services';
        window.history.pushState({}, '', url);
        loadServices(params);
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

    function closeAddServiceModal() {
        addServiceModal.style.display = 'none';
        addServiceForm.reset();
        addServiceError.classList.add('hidden');
    }

    addServiceOpenButton.addEventListener('click', () => {
        addServiceError.classList.add('hidden');
        addServiceModal.style.display = 'flex';
    });

    addServiceModal.querySelector('[data-add-service-cancel]').addEventListener('click', closeAddServiceModal);

    addServiceForm.addEventListener('submit', async (event) => {
        event.preventDefault();
        addServiceError.classList.add('hidden');
        addServiceSubmit.disabled = true;

        try {
            const response = await request('/api/v1/admin/services', {
                method: 'POST',
                body: {
                    category_id: Number(addServiceCategorySelect.value),
                    code: addServiceForm.elements.namedItem('code').value.trim(),
                    slug: addServiceForm.elements.namedItem('slug').value.trim(),
                    name: addServiceForm.elements.namedItem('name').value.trim(),
                    short_description: addServiceForm.elements.namedItem('short_description').value.trim() || null,
                    description: addServiceForm.elements.namedItem('description').value.trim() || null,
                    display_order: Number(addServiceForm.elements.namedItem('display_order').value || 0),
                },
            });

            window.location.assign(`/admin/services/${response.data.service.uuid}`);
        } catch (error) {
            addServiceError.textContent = error instanceof ApiError ? error.message : 'Unable to create this service.';
            addServiceError.classList.remove('hidden');
        } finally {
            addServiceSubmit.disabled = false;
        }
    });

    window.addEventListener('popstate', () => {
        const params = currentParams();
        applyParamsToForm(params);
        loadServices(params);
    });

    const initialParams = currentParams();
    applyParamsToForm(initialParams);

    adminAuthReady().then((ready) => {
        if (ready) {
            loadCategoryOptions(initialParams.get('category_id'));
            loadServices(initialParams);
        }
    });
}
