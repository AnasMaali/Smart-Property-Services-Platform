/**
 * Admin Support Request detail (BLUE V1 Phase B7). Reuses the centralized
 * Admin API client against GET /v1/admin/support-requests/{supportRequest}
 * (App\Actions\Admin\Support\AdminGetSupportRequestAction / App\Support\
 * Admin\AdminSupportRequestPresenter) - every field rendered below comes
 * directly from that response; nothing is invented or recomputed
 * client-side.
 *
 * CRITICAL: message bodies are untrusted, customer-controlled (and
 * Admin-controlled) text. They are rendered exclusively via textContent
 * below - never innerHTML - to prevent stored XSS.
 *
 * Only one mutation exists for this module: posting an Admin reply
 * message (POST .../messages). Status transitions and assignment are
 * deliberately NOT implemented in this phase (no existing lifecycle
 * policy to reuse - see App\Actions\Admin\Support\
 * AdminSendSupportMessageAction's docblock) and are rendered read-only.
 * After a successful reply, this page reloads the authoritative server
 * state (loadSupportRequest()) rather than patching local state.
 */

import { request, ApiError } from '../lib/api-client.js';
import { adminAuthReady } from '../auth/restore.js';
import { statusBadgeClasses, statusLabel, formatDateTime } from '../lib/format.js';

const page = document.querySelector('[data-support-detail-page]');

if (page) {
    const supportRequestUuid = page.dataset.supportRequestUuid;
    const loadingEl = page.querySelector('[data-support-loading]');
    const errorEl = page.querySelector('[data-support-error]');
    const contentEl = page.querySelector('[data-support-content]');
    const messagesEl = page.querySelector('[data-messages]');
    const messagesEmptyEl = page.querySelector('[data-messages-empty]');
    const messageTemplate = document.querySelector('[data-message-template]');
    const replyForm = page.querySelector('[data-reply-form]');
    const replySubmit = replyForm.querySelector('[data-reply-submit]');
    const replyError = replyForm.querySelector('[data-reply-error]');

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

    function renderCustomer(customer) {
        setText('customer_name', customer?.full_name);
        setText('customer_phone', customer?.phone_number);
        setText('customer_email', customer?.email);

        const link = page.querySelector('[data-customer-link]');
        if (customer) {
            link.href = `/admin/customers/${encodeURIComponent(customer.uuid)}`;
            link.style.display = 'inline-block';
        } else {
            link.style.display = 'none';
        }
    }

    function renderBooking(booking) {
        const noneEl = page.querySelector('[data-booking-none]');
        const detailsEl = page.querySelector('[data-booking-details]');
        const link = page.querySelector('[data-booking-link]');

        if (!booking) {
            noneEl.classList.remove('hidden');
            detailsEl.classList.add('hidden');
            link.classList.add('hidden');
            return;
        }

        noneEl.classList.add('hidden');
        detailsEl.classList.remove('hidden');
        setText('booking_number', booking.booking_number);
        setText('booking_status', statusLabel(booking.status));

        link.href = `/admin/bookings/${encodeURIComponent(booking.uuid)}`;
        link.classList.remove('hidden');
    }

    function renderMessage(message) {
        const node = messageTemplate.content.cloneNode(true);
        const row = node.querySelector('[data-message-row]');

        const senderLabel = message.sender.type === 'CUSTOMER'
            ? `${message.sender.full_name || 'Customer'} (Customer)`
            : message.sender.type === 'ADMIN'
                ? `${message.sender.full_name || 'Admin'} (Admin)`
                : 'Unknown sender';

        row.querySelector('[data-field="sender_label"]').textContent = senderLabel;
        row.querySelector('[data-field="created_at"]').textContent = formatDateTime(message.created_at);
        row.querySelector('[data-field="message_body"]').textContent = message.message_body;

        return node;
    }

    async function loadSupportRequest() {
        setState('loading');

        try {
            const response = await request(`/api/v1/admin/support-requests/${encodeURIComponent(supportRequestUuid)}`);
            renderSupportRequest(response.data.support_request);
            setState('ready');
        } catch (error) {
            const message = error instanceof ApiError ? error.message : 'Unable to load this support request.';
            showError(message);
        }
    }

    function renderSupportRequest(supportRequest) {
        setText('request_number', supportRequest.request_number);
        setText('subject', supportRequest.subject);
        setText('created_at', formatDateTime(supportRequest.created_at));
        renderBadge(field('status'), supportRequest.status);

        renderCustomer(supportRequest.customer);
        renderBooking(supportRequest.booking);

        setText('assigned_admin', supportRequest.assigned_admin ? supportRequest.assigned_admin.full_name : 'Unassigned');
        setText('status_changed_at', formatDateTime(supportRequest.status_changed_at));
        setText('resolved_at', supportRequest.resolved_at ? formatDateTime(supportRequest.resolved_at) : '—');
        setText('closed_at', supportRequest.closed_at ? formatDateTime(supportRequest.closed_at) : '—');

        if (supportRequest.messages.length === 0) {
            messagesEmptyEl.classList.remove('hidden');
            messagesEl.replaceChildren();
        } else {
            messagesEmptyEl.classList.add('hidden');
            messagesEl.replaceChildren(...supportRequest.messages.map(renderMessage));
        }
    }

    replyForm.addEventListener('submit', async (event) => {
        event.preventDefault();
        replyError.classList.add('hidden');

        const messageBody = replyForm.elements.namedItem('message_body').value.trim();

        if (!messageBody) {
            return;
        }

        replySubmit.disabled = true;

        try {
            await request(`/api/v1/admin/support-requests/${encodeURIComponent(supportRequestUuid)}/messages`, {
                method: 'POST',
                body: { message_body: messageBody },
            });

            replyForm.reset();
            await loadSupportRequest();
        } catch (error) {
            replyError.textContent = error instanceof ApiError ? error.message : 'Unable to send this reply.';
            replyError.classList.remove('hidden');
        } finally {
            replySubmit.disabled = false;
        }
    });

    adminAuthReady().then((ready) => {
        if (ready) {
            loadSupportRequest();
        }
    });
}
