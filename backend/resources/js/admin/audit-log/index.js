/**
 * Admin Audit Log list (BLUE V1 Phase B12). Reuses the centralized Admin
 * API client against GET /v1/admin/audit-logs (App\Actions\Admin\Audit\
 * AdminListAuditLogsAction / App\Support\Admin\AdminAuditLogPresenter).
 * Read-only - an audit ledger is append-only, so there is no mutation for
 * this module. `action_code`/`entity_type` are rendered as human-readable
 * labels via the same statusLabel() helper every other status code in this
 * Admin frontend already uses.
 */

import { request, ApiError } from '../lib/api-client.js';
import { adminAuthReady } from '../auth/restore.js';
import { statusLabel, formatDateTime } from '../lib/format.js';

const page = document.querySelector('[data-audit-log-page]');

if (page) {
    const filterForm = page.querySelector('[data-audit-log-filter-form]');
    const clearButton = page.querySelector('[data-audit-log-clear-filters]');
    const loadingEl = page.querySelector('[data-audit-log-loading]');
    const errorEl = page.querySelector('[data-audit-log-error]');
    const emptyEl = page.querySelector('[data-audit-log-empty]');
    const tableWrapper = page.querySelector('[data-audit-log-table-wrapper]');
    const tableBody = page.querySelector('[data-audit-log-body]');
    const pagination = page.querySelector('[data-audit-log-pagination]');
    const paginationSummary = page.querySelector('[data-audit-log-pagination-summary]');
    const prevPageButton = page.querySelector('[data-audit-log-prev-page]');
    const nextPageButton = page.querySelector('[data-audit-log-next-page]');

    const FILTER_FIELDS = ['action_code', 'entity_type', 'entity_identifier', 'was_successful', 'actor_uuid', 'from', 'to'];

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

        const actionCell = document.createElement('td');
        actionCell.className = 'px-5 py-3.5 font-medium text-slate-900';
        actionCell.textContent = statusLabel(entry.action_code);

        const entityCell = document.createElement('td');
        entityCell.className = 'px-5 py-3.5 text-slate-700';
        entityCell.textContent = entry.entity_identifier ? `${entry.entity_type} ${entry.entity_identifier}` : entry.entity_type;

        const actorCell = document.createElement('td');
        actorCell.className = 'px-5 py-3.5 text-slate-500';
        actorCell.textContent = entry.actor ? entry.actor.full_name : '—';

        const outcomeCell = document.createElement('td');
        outcomeCell.className = 'px-5 py-3.5';
        const badge = document.createElement('span');
        badge.className = `rounded-full px-2.5 py-1 text-xs font-semibold ${entry.was_successful ? 'bg-emerald-50 text-emerald-700' : 'bg-red-50 text-red-700'}`;
        badge.textContent = entry.was_successful ? 'Success' : 'Failed';
        outcomeCell.appendChild(badge);

        const whenCell = document.createElement('td');
        whenCell.className = 'px-5 py-3.5 text-slate-500';
        whenCell.textContent = formatDateTime(entry.created_at);

        const linkCell = document.createElement('td');
        linkCell.className = 'px-5 py-3.5 text-right';
        const link = document.createElement('a');
        link.href = `/admin/audit-log/${encodeURIComponent(entry.uuid)}`;
        link.className = 'text-sm font-medium text-blue-600 hover:text-blue-800';
        link.textContent = 'View';
        linkCell.appendChild(link);

        row.append(actionCell, entityCell, actorCell, outcomeCell, whenCell, linkCell);

        return row;
    }

    async function loadAuditLog(params) {
        setState('loading');

        try {
            const response = await request(`/api/v1/admin/audit-logs?${params.toString()}`);
            const entries = response.data.audit_logs || [];
            const pageInfo = response.data.pagination;

            tableBody.replaceChildren(...entries.map(renderRow));

            if (entries.length === 0) {
                setState('empty');
                return;
            }

            setState('ready');
            renderPagination(pageInfo, params);
        } catch (error) {
            const message = error instanceof ApiError ? error.message : 'Unable to load the audit log.';
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
        const url = query ? `/admin/audit-log?${query}` : '/admin/audit-log';
        window.history.pushState({}, '', url);
        loadAuditLog(params);
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
        loadAuditLog(params);
    });

    const initialParams = currentParams();
    applyParamsToForm(initialParams);

    adminAuthReady().then((ready) => {
        if (ready) {
            loadAuditLog(initialParams);
        }
    });
}
