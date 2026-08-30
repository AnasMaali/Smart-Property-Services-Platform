/**
 * Admin Technicians list (BLUE V1 Phase B3, extended by BLUE V1 Technician
 * Admin Management). Reuses the centralized Admin API client against the
 * existing GET /v1/admin/technicians endpoint (App\Actions\Admin\
 * Technician\AdminListTechniciansAction / App\Support\Admin\
 * AdminTechnicianPresenter). Mirrors resources/js/admin/services/index.js's
 * list + "Add" modal structure exactly.
 */

import { request, ApiError } from '../lib/api-client.js';
import { adminAuthReady } from '../auth/restore.js';
import { statusBadgeClasses, statusLabel, formatDateTime } from '../lib/format.js';

const page = document.querySelector('[data-technicians-page]');

if (page) {
    const filterForm = page.querySelector('[data-technicians-filter-form]');
    const clearButton = page.querySelector('[data-technicians-clear-filters]');
    const loadingEl = page.querySelector('[data-technicians-loading]');
    const errorEl = page.querySelector('[data-technicians-error]');
    const emptyEl = page.querySelector('[data-technicians-empty]');
    const tableWrapper = page.querySelector('[data-technicians-table-wrapper]');
    const tableBody = page.querySelector('[data-technicians-body]');
    const pagination = page.querySelector('[data-technicians-pagination]');
    const paginationSummary = page.querySelector('[data-technicians-pagination-summary]');
    const prevPageButton = page.querySelector('[data-technicians-prev-page]');
    const nextPageButton = page.querySelector('[data-technicians-next-page]');

    const addModal = document.querySelector('[data-add-technician-modal]');
    const addOpenButton = page.querySelector('[data-add-technician-open]');
    const addForm = addModal.querySelector('[data-add-technician-form]');
    const addSubmit = addModal.querySelector('[data-add-technician-submit]');
    const addError = addModal.querySelector('[data-add-technician-error]');

    const FILTER_FIELDS = ['q', 'status', 'assignable', 'specialization', 'sort'];

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

    function formatRating(performance) {
        if (performance.average_rating === null) {
            return 'No ratings yet';
        }

        return `${performance.average_rating.toFixed(1)} / 5 (${performance.rating_count})`;
    }

    function renderRow(technician) {
        const row = document.createElement('tr');
        row.className = 'cursor-pointer hover:bg-slate-50';
        row.addEventListener('click', () => {
            window.location.assign(`/admin/technicians/${technician.uuid}`);
        });

        const nameCell = document.createElement('td');
        nameCell.className = 'px-5 py-3.5';

        const nameLine = document.createElement('div');
        nameLine.className = 'font-medium text-slate-900';
        nameLine.textContent = technician.full_name;

        const codeLine = document.createElement('div');
        codeLine.className = 'text-xs text-slate-400';
        codeLine.textContent = technician.employee_code || '—';

        nameCell.append(nameLine, codeLine);

        const contactCell = document.createElement('td');
        contactCell.className = 'px-5 py-3.5 text-slate-700';
        contactCell.textContent = technician.phone_number;

        const specializationsCell = document.createElement('td');
        specializationsCell.className = 'px-5 py-3.5 text-slate-700';
        specializationsCell.textContent = technician.specializations.length > 0
            ? technician.specializations.map((s) => s.name).join(', ')
            : '—';

        const statusCell = document.createElement('td');
        statusCell.className = 'px-5 py-3.5';
        const badge = document.createElement('span');
        badge.className = `rounded-full px-2.5 py-1 text-xs font-semibold ${statusBadgeClasses(technician.status)}`;
        badge.textContent = statusLabel(technician.status);
        statusCell.appendChild(badge);

        if (!technician.is_assignable) {
            const note = document.createElement('div');
            note.className = 'mt-1 text-xs text-slate-400';
            note.textContent = 'Not assignable';
            statusCell.appendChild(note);
        }

        const ratingCell = document.createElement('td');
        ratingCell.className = 'px-5 py-3.5 text-slate-700';
        ratingCell.textContent = formatRating(technician.performance);

        const completedCell = document.createElement('td');
        completedCell.className = 'px-5 py-3.5 text-slate-700';
        completedCell.textContent = String(technician.performance.completed_jobs);

        const activeCell = document.createElement('td');
        activeCell.className = 'px-5 py-3.5 text-slate-700';
        activeCell.textContent = String(technician.performance.active_jobs);

        const sinceCell = document.createElement('td');
        sinceCell.className = 'px-5 py-3.5 text-slate-500';
        sinceCell.textContent = formatDateTime(technician.created_at);

        row.append(nameCell, contactCell, specializationsCell, statusCell, ratingCell, completedCell, activeCell, sinceCell);

        return row;
    }

    async function loadTechnicians(params) {
        setState('loading');

        try {
            const response = await request(`/api/v1/admin/technicians?${params.toString()}`);
            const technicians = response.data.technicians || [];
            const pageInfo = response.data.pagination;

            tableBody.replaceChildren(...technicians.map(renderRow));

            if (technicians.length === 0) {
                setState('empty');
                return;
            }

            setState('ready');
            renderPagination(pageInfo, params);
        } catch (error) {
            const message = error instanceof ApiError ? error.message : 'Unable to load technicians.';
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
        const url = query ? `/admin/technicians?${query}` : '/admin/technicians';
        window.history.pushState({}, '', url);
        loadTechnicians(params);
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

    function closeAddModal() {
        addModal.style.display = 'none';
        addForm.reset();
        addError.classList.add('hidden');
    }

    addOpenButton.addEventListener('click', () => {
        addError.classList.add('hidden');
        addModal.style.display = 'flex';
    });

    addModal.querySelector('[data-add-technician-cancel]').addEventListener('click', closeAddModal);

    addForm.addEventListener('submit', async (event) => {
        event.preventDefault();
        addError.classList.add('hidden');
        addSubmit.disabled = true;

        try {
            const response = await request('/api/v1/admin/technicians', {
                method: 'POST',
                body: {
                    employee_code: addForm.elements.namedItem('employee_code').value.trim(),
                    full_name: addForm.elements.namedItem('full_name').value.trim(),
                    phone_number: addForm.elements.namedItem('phone_number').value.trim(),
                    email: addForm.elements.namedItem('email').value.trim() || null,
                    is_phone_visible_to_customer: addForm.elements.namedItem('is_phone_visible_to_customer').checked,
                    internal_note: addForm.elements.namedItem('internal_note').value.trim() || null,
                },
            });

            window.location.assign(`/admin/technicians/${response.data.technician.uuid}`);
        } catch (error) {
            addError.textContent = error instanceof ApiError ? error.message : 'Unable to create this technician.';
            addError.classList.remove('hidden');
        } finally {
            addSubmit.disabled = false;
        }
    });

    window.addEventListener('popstate', () => {
        const params = currentParams();
        applyParamsToForm(params);
        loadTechnicians(params);
    });

    const initialParams = currentParams();
    applyParamsToForm(initialParams);

    adminAuthReady().then((ready) => {
        if (ready) {
            loadTechnicians(initialParams);
        }
    });
}
