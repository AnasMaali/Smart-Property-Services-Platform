/**
 * Admin Payment detail (BLUE V1 Phase B5). Reuses the centralized Admin API
 * client against GET /v1/admin/payments/{payment} (App\Actions\Admin\
 * Payment\AdminGetPaymentAction / App\Support\Admin\AdminPaymentPresenter) -
 * every field rendered below comes directly from that response. Read-only:
 * no mutation (refund/retry/status-override) exists for this module.
 */

import { request, ApiError } from '../lib/api-client.js';
import { adminAuthReady } from '../auth/restore.js';
import { statusBadgeClasses, statusLabel, formatDateTime, formatMoney } from '../lib/format.js';

const page = document.querySelector('[data-payment-detail-page]');

if (page) {
    const paymentUuid = page.dataset.paymentUuid;
    const loadingEl = page.querySelector('[data-payment-loading]');
    const errorEl = page.querySelector('[data-payment-error]');
    const contentEl = page.querySelector('[data-payment-content]');

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

    async function loadPayment() {
        setState('loading');

        try {
            const response = await request(`/api/v1/admin/payments/${encodeURIComponent(paymentUuid)}`);
            renderPayment(response.data.payment);
            setState('ready');
        } catch (error) {
            const message = error instanceof ApiError ? error.message : 'Unable to load this payment.';
            showError(message);
        }
    }

    function renderPayment(payment) {
        setText('checkout_reference', payment.checkout_reference);
        setText('provider', payment.provider);
        setText('created_at', formatDateTime(payment.created_at));
        renderBadge(field('status'), payment.status);

        const failureBox = page.querySelector('[data-failure-box]');

        if (payment.failure_code || payment.failure_message) {
            setText('failure_code', payment.failure_code);
            setText('failure_message', payment.failure_message);
            failureBox.style.display = 'block';
        } else {
            failureBox.style.display = 'none';
        }

        const reconciliationBox = page.querySelector('[data-reconciliation-box]');

        if (payment.requires_reconciliation) {
            setText('reconciliation_reason_code', payment.reconciliation_reason_code);
            setText('reconciled_at', payment.reconciled_at ? formatDateTime(payment.reconciled_at) : 'Not yet reconciled');
            reconciliationBox.style.display = 'block';
        } else {
            reconciliationBox.style.display = 'none';
        }

        setText('customer_name', payment.customer?.full_name);
        setText('customer_phone', payment.customer?.phone_number);
        setText('customer_email', payment.customer?.email);

        setText('requested_amount', formatMoney(payment.requested_amount, payment.currency));
        setText('confirmed_amount', payment.confirmed_amount ? formatMoney(payment.confirmed_amount, payment.currency) : '—');
        setText('payment_method_type', payment.payment_method_type);

        const noBooking = page.querySelector('[data-no-booking]');
        const bookingLink = page.querySelector('[data-booking-link]');

        if (payment.booking) {
            noBooking.style.display = 'none';
            bookingLink.style.display = 'inline';
            bookingLink.href = `/admin/bookings/${encodeURIComponent(payment.booking.uuid)}`;
            setText('booking_number', payment.booking.booking_number);
        } else {
            noBooking.style.display = 'block';
            bookingLink.style.display = 'none';
        }

        setText('provider_session_reference', payment.provider_session_reference);
        setText('provider_transaction_reference', payment.provider_transaction_reference);
        setText('provider_status_code', payment.provider_status_code);
        setText('expires_at', payment.expires_at ? formatDateTime(payment.expires_at) : '—');
        setText('successful_at', payment.successful_at ? formatDateTime(payment.successful_at) : '—');
        setText('finalized_at', payment.finalized_at ? formatDateTime(payment.finalized_at) : '—');

        const eventsEl = page.querySelector('[data-webhook-events]');
        const eventsEmptyEl = page.querySelector('[data-webhook-events-empty]');

        if (payment.recent_webhook_events.length === 0) {
            eventsEl.replaceChildren();
            eventsEmptyEl.classList.remove('hidden');
        } else {
            eventsEmptyEl.classList.add('hidden');
            eventsEl.replaceChildren(...payment.recent_webhook_events.map(renderWebhookEventRow));
        }
    }

    adminAuthReady().then((ready) => {
        if (ready) {
            loadPayment();
        }
    });
}
