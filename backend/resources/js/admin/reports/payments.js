/**
 * Admin Reports - Payment Report. Reads GET /v1/admin/reports/payments
 * (App\Actions\Admin\Reports\AdminPaymentReportAction). Never renders a
 * provider secret/credential/raw payload - only the safe fields the Action
 * already returns.
 */

import { request, ApiError } from '../lib/api-client.js';
import { formatMoney, formatDateTime, statusLabel, statusBadgeClasses } from '../lib/format.js';
import { adminAuthReady } from '../auth/restore.js';
import { wireDateRangeToggle, paramsFromForm, applyParamsToForm } from '../lib/report-filters.js';
import { downloadReportFile } from '../lib/download.js';

const page = document.querySelector('[data-report-page="payments"]');

if (page) {
    const FIELDS = ['range', 'from', 'to', 'status', 'payment_method'];
    const filterForm = page.querySelector('[data-report-filter-form]');
    const rangeSelect = page.querySelector('[data-range-select]');
    const customRangeFields = page.querySelectorAll('[data-custom-range-fields]');
    const loadingEl = page.querySelector('[data-report-loading]');
    const errorEl = page.querySelector('[data-report-error]');
    const emptyEl = page.querySelector('[data-report-empty]');
    const contentEl = page.querySelector('[data-report-content]');
    const rangeSummaryEl = page.querySelector('[data-report-range-summary]');
    const printRangeEl = page.querySelector('[data-print-range]');
    const tableBody = page.querySelector('[data-table-body]');
    const pagination = page.querySelector('[data-pagination]');
    const paginationSummary = page.querySelector('[data-pagination-summary]');
    const prevPageButton = page.querySelector('[data-prev-page]');
    const nextPageButton = page.querySelector('[data-next-page]');

    wireDateRangeToggle(rangeSelect, customRangeFields);

    function currentParams() {
        return new URLSearchParams(window.location.search);
    }

    function setState(state) {
        loadingEl.classList.toggle('hidden', state !== 'loading');
        errorEl.classList.toggle('hidden', state !== 'error');
        emptyEl.classList.toggle('hidden', state !== 'empty');
        contentEl.style.display = state === 'ready' ? 'flex' : 'none';
        pagination.style.display = state === 'ready' ? 'flex' : 'none';
    }

    function showError(message) {
        errorEl.textContent = message;
        setState('error');
    }

    function renderRow(row) {
        const tr = document.createElement('tr');
        tr.className = 'hover:bg-slate-50';
        tr.innerHTML = `
            <td class="px-5 py-3.5 text-slate-500">${formatDateTime(row.created_at)}</td>
            <td class="px-5 py-3.5 font-medium text-slate-900">${row.booking_number || '—'}</td>
            <td class="px-5 py-3.5 text-slate-700">${row.customer_name || '—'}</td>
            <td class="px-5 py-3.5">${statusLabel(row.payment_method)}</td>
            <td class="px-5 py-3.5 font-medium text-slate-900">${formatMoney(row.amount, { code: row.currency_code, decimal_places: 2 })}</td>
            <td class="px-5 py-3.5"><span class="rounded-full px-2.5 py-1 text-xs font-semibold ${statusBadgeClasses(row.status)}">${statusLabel(row.status)}</span></td>
            <td class="px-5 py-3.5 text-slate-500">${row.provider_reference || '—'}</td>
        `;

        return tr;
    }

    function renderSummary(summary) {
        page.querySelector('[data-field="total_payments"]').textContent = summary.total_payments;
        page.querySelector('[data-field="successful_count"]').textContent = summary.successful_count;
        page.querySelector('[data-field="failed_count"]').textContent = summary.failed_count;
        page.querySelector('[data-field="pending_count"]').textContent = summary.pending_count;
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

    async function load(params) {
        setState('loading');

        try {
            const response = await request(`/api/v1/admin/reports/payments?${params.toString()}`);
            const rows = response.data.payments || [];

            renderSummary(response.data.summary);
            tableBody.replaceChildren(...rows.map(renderRow));

            const rangeText = `${formatDateTime(response.data.range.from)} — ${formatDateTime(response.data.range.to)}`;
            rangeSummaryEl.textContent = `Showing ${rangeText}`;
            printRangeEl.textContent = `Period: ${rangeText}`;

            if (rows.length === 0) {
                setState('empty');
                return;
            }

            setState('ready');
            renderPagination(response.data.pagination, params);
        } catch (error) {
            showError(error instanceof ApiError ? error.message : 'Unable to load the report.');
        }
    }

    function navigate(params) {
        const query = params.toString();
        window.history.pushState({}, '', query ? `/admin/reports/payments?${query}` : '/admin/reports/payments');
        load(params);
    }

    filterForm.addEventListener('submit', (event) => {
        event.preventDefault();
        const params = paramsFromForm(filterForm, FIELDS);
        params.set('page', '1');
        navigate(params);
    });

    page.querySelector('[data-report-reset]').addEventListener('click', () => {
        filterForm.reset();
        rangeSelect.value = 'TODAY';
        navigate(new URLSearchParams({ range: 'TODAY' }));
    });

    page.querySelector('[data-report-print]').addEventListener('click', () => window.print());

    page.querySelector('[data-export-csv]').addEventListener('click', async (event) => {
        const button = event.currentTarget;
        button.disabled = true;

        try {
            await downloadReportFile(`/api/v1/admin/reports/payments/csv?${paramsFromForm(filterForm, FIELDS).toString()}`, 'payment-report.csv');
        } catch (error) {
            showError(error.message);
        } finally {
            button.disabled = false;
        }
    });

    page.querySelector('[data-export-pdf]').addEventListener('click', async (event) => {
        const button = event.currentTarget;
        button.disabled = true;

        try {
            await downloadReportFile(`/api/v1/admin/reports/payments/pdf?${paramsFromForm(filterForm, FIELDS).toString()}`, 'payment-report.pdf');
        } catch (error) {
            showError(error.message);
        } finally {
            button.disabled = false;
        }
    });

    window.addEventListener('popstate', () => {
        const params = currentParams();
        applyParamsToForm(filterForm, FIELDS, params);
        load(params);
    });

    const initialParams = currentParams();

    if (!initialParams.has('range')) {
        initialParams.set('range', 'THIS_MONTH');
    }

    applyParamsToForm(filterForm, FIELDS, initialParams);
    rangeSelect.dispatchEvent(new Event('change'));

    adminAuthReady().then((ready) => {
        if (ready) {
            load(initialParams);
        }
    });
}
