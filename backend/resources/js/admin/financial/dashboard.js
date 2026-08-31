/**
 * Admin Financial Dashboard. Reuses the centralized Admin API client
 * against GET /v1/admin/financial-dashboard
 * (App\Actions\Admin\Financial\AdminGetFinancialDashboardAction /
 * App\Support\Admin\AdminFinancialSummaryCalculator) - every number
 * rendered below comes directly from that response; nothing is computed
 * or guessed client-side. Mirrors resources/js/admin/payments/index.js's
 * filter/URL-sync conventions.
 */

import { request, ApiError } from '../lib/api-client.js';
import { formatMoney, formatDateTime } from '../lib/format.js';
import { adminAuthReady } from '../auth/restore.js';

const page = document.querySelector('[data-financial-dashboard-page]');

if (page) {
    const filterForm = page.querySelector('[data-financial-filter-form]');
    const rangeSelect = page.querySelector('[data-range-select]');
    const customRangeFields = page.querySelectorAll('[data-custom-range-fields]');
    const rangeSummaryEl = page.querySelector('[data-financial-range-summary]');
    const loadingEl = page.querySelector('[data-financial-loading]');
    const errorEl = page.querySelector('[data-financial-error]');
    const contentEl = page.querySelector('[data-financial-content]');

    function currentParams() {
        return new URLSearchParams(window.location.search);
    }

    function applyParamsToForm(params) {
        rangeSelect.value = params.get('range') || 'TODAY';
        filterForm.elements.namedItem('from').value = params.get('from') || '';
        filterForm.elements.namedItem('to').value = params.get('to') || '';
        toggleCustomRangeFields();
    }

    function paramsFromForm() {
        const params = new URLSearchParams();
        const range = rangeSelect.value;
        params.set('range', range);

        if (range === 'CUSTOM') {
            const from = filterForm.elements.namedItem('from').value;
            const to = filterForm.elements.namedItem('to').value;

            if (from) params.set('from', from);
            if (to) params.set('to', to);
        }

        return params;
    }

    function toggleCustomRangeFields() {
        const isCustom = rangeSelect.value === 'CUSTOM';
        customRangeFields.forEach((field) => field.classList.toggle('hidden', !isCustom));
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

    function renderSummary(data) {
        const summary = data.summary;
        const currency = summary.currency;

        page.querySelector('[data-field="gross_revenue"]').textContent = formatMoney(summary.gross_revenue, currency);
        page.querySelector('[data-field="refunds"]').textContent = formatMoney(summary.refunds, currency);
        page.querySelector('[data-field="net_revenue"]').textContent = formatMoney(summary.net_revenue, currency);
        page.querySelector('[data-field="pay_on_site_collected"]').textContent = formatMoney(summary.breakdown.pay_on_site.collected, currency);
        page.querySelector('[data-field="pay_on_site_pending"]').textContent = formatMoney(summary.breakdown.pay_on_site.pending, currency);

        page.querySelector('[data-field="breakdown_credit_card"]').textContent = formatMoney(summary.breakdown.credit_card, currency);
        page.querySelector('[data-field="breakdown_apple_pay"]').textContent = formatMoney(summary.breakdown.apple_pay, currency);
        page.querySelector('[data-field="breakdown_pay_on_site"]').textContent = formatMoney(summary.breakdown.pay_on_site.collected, currency);

        page.querySelector('[data-field="paid_count"]').textContent = String(summary.bookings.paid_count);
        page.querySelector('[data-field="refunded_count"]').textContent = String(summary.bookings.refunded_count);
        page.querySelector('[data-field="pay_on_site_pending_count"]').textContent = String(summary.bookings.pay_on_site_pending_count);
        page.querySelector('[data-field="repair_quote_balance_collected"]').textContent = formatMoney(summary.repair_quote_balance_collected, currency);

        rangeSummaryEl.textContent = `Showing ${formatDateTime(data.range.from)} — ${formatDateTime(data.range.to)}`;
    }

    async function loadDashboard(params) {
        setState('loading');

        try {
            const response = await request(`/api/v1/admin/financial-dashboard?${params.toString()}`);
            renderSummary(response.data);
            setState('ready');
        } catch (error) {
            const message = error instanceof ApiError ? error.message : 'Unable to load the financial dashboard.';
            showError(message);
        }
    }

    function navigate(params) {
        const query = params.toString();
        window.history.pushState({}, '', query ? `/admin/finance?${query}` : '/admin/finance');
        loadDashboard(params);
    }

    rangeSelect.addEventListener('change', toggleCustomRangeFields);

    filterForm.addEventListener('submit', (event) => {
        event.preventDefault();
        navigate(paramsFromForm());
    });

    window.addEventListener('popstate', () => {
        const params = currentParams();
        applyParamsToForm(params);
        loadDashboard(params);
    });

    const initialParams = currentParams();

    if (!initialParams.has('range')) {
        initialParams.set('range', 'TODAY');
    }

    applyParamsToForm(initialParams);

    adminAuthReady().then((ready) => {
        if (ready) {
            loadDashboard(initialParams);
        }
    });
}
