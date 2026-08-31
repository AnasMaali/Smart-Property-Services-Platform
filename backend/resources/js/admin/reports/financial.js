/**
 * Admin Reports - Financial Summary Report. Reads GET
 * /v1/admin/reports/financial (App\Actions\Admin\Reports\
 * AdminFinancialSummaryReportAction) - every figure rendered is the exact
 * App\Support\Admin\AdminFinancialSummaryCalculator output the Financial
 * Dashboard already shows, never recomputed client-side. Print/CSV/PDF
 * export all read the same currently-applied filters.
 */

import { request, ApiError } from '../lib/api-client.js';
import { formatMoney, formatDateTime } from '../lib/format.js';
import { adminAuthReady } from '../auth/restore.js';
import { wireDateRangeToggle, paramsFromForm, applyParamsToForm } from '../lib/report-filters.js';
import { downloadReportFile } from '../lib/download.js';

const page = document.querySelector('[data-report-page="financial"]');

if (page) {
    const FIELDS = ['range', 'from', 'to'];
    const filterForm = page.querySelector('[data-report-filter-form]');
    const rangeSelect = page.querySelector('[data-range-select]');
    const customRangeFields = page.querySelectorAll('[data-custom-range-fields]');
    const loadingEl = page.querySelector('[data-report-loading]');
    const errorEl = page.querySelector('[data-report-error]');
    const contentEl = page.querySelector('[data-report-content]');
    const rangeSummaryEl = page.querySelector('[data-report-range-summary]');
    const printRangeEl = page.querySelector('[data-print-range]');
    const breakdownBody = page.querySelector('[data-breakdown-body]');
    const breakdownTruncated = page.querySelector('[data-breakdown-truncated]');

    wireDateRangeToggle(rangeSelect, customRangeFields);

    function currentParams() {
        return new URLSearchParams(window.location.search);
    }

    function setState(state) {
        loadingEl.classList.toggle('hidden', state !== 'loading');
        errorEl.classList.toggle('hidden', state !== 'error');
        contentEl.style.display = state === 'ready' ? 'flex' : 'none';
    }

    function showError(message) {
        errorEl.textContent = message;
        setState('error');
    }

    function renderBreakdownRow(day, currency) {
        const row = document.createElement('tr');
        row.className = 'hover:bg-slate-50';
        row.innerHTML = `
            <td class="px-5 py-3 text-slate-700">${day.date}</td>
            <td class="px-5 py-3">${formatMoney(day.gross_revenue, currency)}</td>
            <td class="px-5 py-3 text-red-600">${formatMoney(day.refunds, currency)}</td>
            <td class="px-5 py-3 text-emerald-600">${formatMoney(day.net_revenue, currency)}</td>
        `;

        return row;
    }

    function render(data) {
        const summary = data.summary;
        const currency = summary.currency;

        page.querySelector('[data-field="gross_revenue"]').textContent = formatMoney(summary.gross_revenue, currency);
        page.querySelector('[data-field="refunds"]').textContent = formatMoney(summary.refunds, currency);
        page.querySelector('[data-field="net_revenue"]').textContent = formatMoney(summary.net_revenue, currency);
        page.querySelector('[data-field="credit_card"]').textContent = formatMoney(summary.breakdown.credit_card, currency);
        page.querySelector('[data-field="apple_pay"]').textContent = formatMoney(summary.breakdown.apple_pay, currency);
        page.querySelector('[data-field="pay_on_site_collected"]').textContent = formatMoney(summary.breakdown.pay_on_site.collected, currency);
        page.querySelector('[data-field="pay_on_site_pending"]').textContent = formatMoney(summary.breakdown.pay_on_site.pending, currency);

        breakdownTruncated.classList.toggle('hidden', !data.breakdown_truncated);
        breakdownTruncated.textContent = data.breakdown_truncated
            ? 'Daily breakdown omitted — the selected range is too wide for a per-day table. Totals above remain accurate; use CSV export for the full period.'
            : '';

        breakdownBody.replaceChildren(...data.breakdown_by_day.map((day) => renderBreakdownRow(day, currency)));

        const rangeText = `${formatDateTime(data.range.from)} — ${formatDateTime(data.range.to)}`;
        rangeSummaryEl.textContent = `Showing ${rangeText}`;
        printRangeEl.textContent = `Period: ${rangeText}`;
    }

    async function load(params) {
        setState('loading');

        try {
            const response = await request(`/api/v1/admin/reports/financial?${params.toString()}`);
            render(response.data);
            setState('ready');
        } catch (error) {
            showError(error instanceof ApiError ? error.message : 'Unable to load the report.');
        }
    }

    function navigate(params) {
        const query = params.toString();
        window.history.pushState({}, '', query ? `/admin/reports/financial?${query}` : '/admin/reports/financial');
        load(params);
    }

    filterForm.addEventListener('submit', (event) => {
        event.preventDefault();
        navigate(paramsFromForm(filterForm, FIELDS));
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
            await downloadReportFile(`/api/v1/admin/reports/financial/csv?${paramsFromForm(filterForm, FIELDS).toString()}`, 'financial-summary-report.csv');
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
            await downloadReportFile(`/api/v1/admin/reports/financial/pdf?${paramsFromForm(filterForm, FIELDS).toString()}`, 'financial-summary-report.pdf');
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
