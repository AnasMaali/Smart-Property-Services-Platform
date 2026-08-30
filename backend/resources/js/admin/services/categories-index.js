/**
 * Admin Service Categories list (BLUE V1 Phase B8). Reuses the centralized
 * Admin API client against GET /v1/admin/service-categories
 * (App\Actions\Admin\Service\AdminListServiceCategoriesAction /
 * App\Support\Admin\AdminServiceCategoryPresenter). Only 18 categories
 * exist (see database/blue_v1_seed.sql), so this is deliberately a small,
 * unpaginated list - no filter card beyond a simple status dropdown.
 */

import { request, ApiError } from '../lib/api-client.js';
import { adminAuthReady } from '../auth/restore.js';

const page = document.querySelector('[data-categories-page]');

if (page) {
    const statusFilter = page.querySelector('[data-status-filter]');
    const searchFilter = page.querySelector('[data-search-filter]');
    const loadingEl = page.querySelector('[data-categories-loading]');
    const errorEl = page.querySelector('[data-categories-error]');
    const emptyEl = page.querySelector('[data-categories-empty]');
    const tableWrapper = page.querySelector('[data-categories-table-wrapper]');
    const tableBody = page.querySelector('[data-categories-body]');

    const addCategoryModal = document.querySelector('[data-add-category-modal]');
    const addCategoryOpenButton = page.querySelector('[data-add-category-open]');
    const addCategoryForm = addCategoryModal.querySelector('[data-add-category-form]');
    const addCategorySubmit = addCategoryModal.querySelector('[data-add-category-submit]');
    const addCategoryError = addCategoryModal.querySelector('[data-add-category-error]');

    function setState(state) {
        loadingEl.classList.toggle('hidden', state !== 'loading');
        errorEl.classList.toggle('hidden', state !== 'error');
        emptyEl.classList.toggle('hidden', state !== 'empty');
        tableWrapper.classList.toggle('hidden', state !== 'ready');
    }

    function showError(message) {
        errorEl.textContent = message;
        setState('error');
    }

    function activeBadge(isActive) {
        const badge = document.createElement('span');
        badge.className = `rounded-full px-2.5 py-1 text-xs font-semibold ${isActive ? 'bg-emerald-50 text-emerald-700' : 'bg-slate-100 text-slate-600'}`;
        badge.textContent = isActive ? 'Active' : 'Inactive';

        return badge;
    }

    function renderRow(category) {
        const row = document.createElement('tr');
        row.className = 'hover:bg-slate-50';

        const nameCell = document.createElement('td');
        nameCell.className = 'px-5 py-3.5 font-medium text-slate-900';
        nameCell.textContent = category.name;

        const codeCell = document.createElement('td');
        codeCell.className = 'px-5 py-3.5 text-slate-500';
        codeCell.textContent = category.code;

        const statusCell = document.createElement('td');
        statusCell.className = 'px-5 py-3.5';
        statusCell.appendChild(activeBadge(category.is_active));

        const orderCell = document.createElement('td');
        orderCell.className = 'px-5 py-3.5 text-slate-500';
        orderCell.textContent = String(category.display_order);

        const countCell = document.createElement('td');
        countCell.className = 'px-5 py-3.5 text-slate-500';
        countCell.textContent = String(category.services_count);

        const linkCell = document.createElement('td');
        linkCell.className = 'px-5 py-3.5 text-right';
        const link = document.createElement('a');
        link.href = `/admin/service-categories/${category.id}`;
        link.className = 'text-sm font-medium text-blue-600 hover:text-blue-800';
        link.textContent = 'View';
        linkCell.appendChild(link);

        row.append(nameCell, codeCell, statusCell, orderCell, countCell, linkCell);

        return row;
    }

    async function loadCategories() {
        setState('loading');

        const params = new URLSearchParams();
        if (statusFilter.value) {
            params.set('is_active', statusFilter.value);
        }
        if (searchFilter.value.trim()) {
            params.set('search', searchFilter.value.trim());
        }

        try {
            const response = await request(`/api/v1/admin/service-categories?${params.toString()}`);
            const categories = response.data.service_categories || [];

            tableBody.replaceChildren(...categories.map(renderRow));

            if (categories.length === 0) {
                setState('empty');
                return;
            }

            setState('ready');
        } catch (error) {
            const message = error instanceof ApiError ? error.message : 'Unable to load service categories.';
            showError(message);
        }
    }

    statusFilter.addEventListener('change', loadCategories);

    let searchDebounce = null;
    searchFilter.addEventListener('input', () => {
        clearTimeout(searchDebounce);
        searchDebounce = setTimeout(loadCategories, 300);
    });

    function closeAddCategoryModal() {
        addCategoryModal.style.display = 'none';
        addCategoryForm.reset();
        addCategoryForm.elements.namedItem('is_active').checked = true;
        addCategoryError.classList.add('hidden');
    }

    addCategoryOpenButton.addEventListener('click', () => {
        addCategoryError.classList.add('hidden');
        addCategoryModal.style.display = 'flex';
    });

    addCategoryModal.querySelector('[data-add-category-cancel]').addEventListener('click', closeAddCategoryModal);

    addCategoryForm.addEventListener('submit', async (event) => {
        event.preventDefault();
        addCategoryError.classList.add('hidden');
        addCategorySubmit.disabled = true;

        try {
            const response = await request('/api/v1/admin/service-categories', {
                method: 'POST',
                body: {
                    code: addCategoryForm.elements.namedItem('code').value.trim(),
                    name: addCategoryForm.elements.namedItem('name').value.trim(),
                    description: addCategoryForm.elements.namedItem('description').value.trim() || null,
                    display_order: Number(addCategoryForm.elements.namedItem('display_order').value || 0),
                    is_active: addCategoryForm.elements.namedItem('is_active').checked,
                },
            });

            window.location.assign(`/admin/service-categories/${response.data.service_category.id}`);
        } catch (error) {
            addCategoryError.textContent = error instanceof ApiError ? error.message : 'Unable to create this category.';
            addCategoryError.classList.remove('hidden');
        } finally {
            addCategorySubmit.disabled = false;
        }
    });

    adminAuthReady().then((ready) => {
        if (ready) {
            loadCategories();
        }
    });
}
