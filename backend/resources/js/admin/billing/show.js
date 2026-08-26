/**
 * Admin Contract Billing detail (BLUE V1 Phase B5). Reuses the centralized
 * Admin API client against GET /v1/admin/contract-billings/{billing}
 * (App\Actions\Admin\ContractBilling\AdminGetContractBillingAction /
 * App\Support\Admin\AdminContractBillingPresenter) - every field rendered
 * below comes directly from that response. Read-only: no mutation exists
 * for this module. Links back to the existing B4 Contract detail page via
 * the contract's own uuid - never duplicates Contract detail here.
 */

import { request, ApiError } from '../lib/api-client.js';
import { adminAuthReady } from '../auth/restore.js';
import { statusBadgeClasses, statusLabel, formatDateTime, formatMoney } from '../lib/format.js';

const page = document.querySelector('[data-billing-detail-page]');

if (page) {
    const billingUuid = page.dataset.billingUuid;
    const loadingEl = page.querySelector('[data-billing-loading]');
    const errorEl = page.querySelector('[data-billing-error]');
    const contentEl = page.querySelector('[data-billing-content]');

    function field(name) {
        return page.querySelector(`[data-field="${name}"]`);
    }

    function setText(name, value) {
        const el = field(name);

        if (el) {
            el.textContent = value ?? '—';
        }
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

    function renderBadge(el, code) {
        if (!el) {
            return;
        }

        el.textContent = statusLabel(code);
        el.className = `rounded-full px-3 py-1.5 text-xs font-semibold ${statusBadgeClasses(code)}`;
    }

    function renderWebhookEventRow(event) {
        const template = document.querySelector('[data-webhook-event-row-template]');
        const fragment = template.content.cloneNode(true);

        fragment.querySelector('[data-field="event_type"]').textContent = event.event_type;
        fragment.querySelector('[data-field="provider_event_id"]').textContent = event.provider_event_id;
        fragment.querySelector('[data-field="received_at"]').textContent = formatDateTime(event.received_at);

        const errorEl = fragment.querySelector('[data-field="error"]');
        errorEl.textContent = event.last_error_message ? `${event.last_error_code}: ${event.last_error_message}` : '';

        renderBadge(fragment.querySelector('[data-field="status"]'), event.status);

        return fragment;
    }

    async function loadBilling() {
        setState('loading');

        try {
            const response = await request(`/api/v1/admin/contract-billings/${encodeURIComponent(billingUuid)}`);
            renderBilling(response.data.contract_billing);
            setState('ready');
        } catch (error) {
            const message = error instanceof ApiError ? error.message : 'Unable to load this contract billing.';
            showError(message);
        }
    }

    function renderBilling(billing) {
        setText('contract_number', billing.contract.contract_number);
        page.querySelector('[data-contract-link]').href = `/admin/contracts/${encodeURIComponent(billing.contract.uuid)}`;
        setText('contract_status', statusLabel(billing.contract.status));

        setText('provider', billing.provider);
        setText('created_at', formatDateTime(billing.created_at));
        renderBadge(field('status'), billing.status);

        setText('customer_name', billing.customer?.full_name);
        setText('customer_phone', billing.customer?.phone_number);
        setText('customer_email', billing.customer?.email);

        setText('billing_interval', statusLabel(billing.billing_interval));
        setText('recurring_amount', formatMoney(billing.recurring_amount, billing.currency));

        setText('current_period_start', billing.current_period_start ? formatDateTime(billing.current_period_start) : '—');
        setText('current_period_end', billing.current_period_end ? formatDateTime(billing.current_period_end) : '—');

        const pastDueBox = page.querySelector('[data-past-due-box]');

        if (billing.past_due_since) {
            setText('past_due_since', formatDateTime(billing.past_due_since));
            pastDueBox.style.display = 'block';
        } else {
            pastDueBox.style.display = 'none';
        }

        const cancellationBox = page.querySelector('[data-cancellation-box]');
        const hasCancellationState = billing.cancel_at || billing.cancelled_at || billing.provider_cancellation_requested_at;

        if (hasCancellationState) {
            setText('cancel_at', billing.cancel_at ? formatDateTime(billing.cancel_at) : '—');
            setText('cancelled_at', billing.cancelled_at ? formatDateTime(billing.cancelled_at) : '—');
            setText('provider_cancellation_requested_at', billing.provider_cancellation_requested_at ? formatDateTime(billing.provider_cancellation_requested_at) : '—');
            setText('provider_cancellation_last_attempt_at', billing.provider_cancellation_last_attempt_at ? formatDateTime(billing.provider_cancellation_last_attempt_at) : '—');
            setText('provider_cancellation_attempt_count', String(billing.provider_cancellation_attempt_count ?? 0));
            setText('billing_suspended_at', billing.billing_suspended_at ? formatDateTime(billing.billing_suspended_at) : '—');
            cancellationBox.style.display = 'block';
        } else {
            cancellationBox.style.display = 'none';
        }

        setText('stripe_customer_id', billing.stripe_customer_id);
        setText('stripe_subscription_id', billing.stripe_subscription_id);
        setText('stripe_price_id', billing.stripe_price_id);
        setText('stripe_product_id', billing.stripe_product_id);

        const eventsEl = page.querySelector('[data-webhook-events]');
        const eventsEmptyEl = page.querySelector('[data-webhook-events-empty]');

        if (billing.recent_webhook_events.length === 0) {
            eventsEl.replaceChildren();
            eventsEmptyEl.classList.remove('hidden');
        } else {
            eventsEmptyEl.classList.add('hidden');
            eventsEl.replaceChildren(...billing.recent_webhook_events.map(renderWebhookEventRow));
        }
    }

    adminAuthReady().then((ready) => {
        if (ready) {
            loadBilling();
        }
    });
}
