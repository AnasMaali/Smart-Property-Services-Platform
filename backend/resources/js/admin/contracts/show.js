/**
 * Admin Contract detail (BLUE V1 Phase B4). Reuses the centralized Admin API
 * client against the existing GET /v1/admin/contracts/{contract} endpoint
 * (App\Actions\Admin\Contract\AdminGetContractAction / App\Support\Admin\
 * AdminContractPresenter) - every field rendered below comes directly from
 * that response; nothing is invented or recomputed client-side.
 *
 * Action buttons (Approve/Send for acceptance/Suspend/Cancel) are shown
 * purely as a UX hint based on the CURRENT known status - App\Support\
 * Contract\ContractStatusMachine remains the sole authority on whether a
 * transition actually succeeds; every click still calls the real endpoint
 * (contracts/actions.js) and reloads this page's authoritative state
 * (loadContract()) after a successful mutation. A rejection (403/409/422)
 * is shown as a normal error, never silently retried or faked.
 */

import { request, ApiError } from '../lib/api-client.js';
import { adminAuthReady } from '../auth/restore.js';
import { statusBadgeClasses, statusLabel, formatDateTime, formatMoney } from '../lib/format.js';
import { openApproveModal, openConfirmAction } from './actions.js';

const page = document.querySelector('[data-contract-detail-page]');

if (page) {
    const contractUuid = page.dataset.contractUuid;
    const loadingEl = page.querySelector('[data-contract-loading]');
    const errorEl = page.querySelector('[data-contract-error]');
    const contentEl = page.querySelector('[data-contract-content]');
    const actionsContainer = page.querySelector('[data-contract-actions]');

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

    async function loadContract() {
        setState('loading');

        try {
            const response = await request(`/api/v1/admin/contracts/${encodeURIComponent(contractUuid)}`);
            renderContract(response.data.contract);
            setState('ready');
        } catch (error) {
            const message = error instanceof ApiError ? error.message : 'Unable to load this contract.';
            showError(message);
        }
    }

    function renderCoveredService(item) {
        const template = document.querySelector('[data-covered-service-template]');
        const fragment = template.content.cloneNode(true);

        fragment.querySelector('[data-field="service_name"]').textContent = item.service.name;
        fragment.querySelector('[data-field="service_code"]').textContent = item.service.code;

        const entitlement = item.entitlement_mode === 'UNLIMITED'
            ? 'Unlimited visits'
            : `${item.used_visits ?? 0} / ${item.included_visits ?? '—'} visits used`;

        fragment.querySelector('[data-field="entitlement_summary"]').textContent = entitlement;

        return fragment;
    }

    function renderStatusHistoryRow(entry) {
        const template = document.querySelector('[data-status-history-row-template]');
        const fragment = template.content.cloneNode(true);

        fragment.querySelector('[data-field="from_status"]').textContent = entry.from_status ? statusLabel(entry.from_status) : 'Created';
        fragment.querySelector('[data-field="to_status"]').textContent = statusLabel(entry.to_status);
        fragment.querySelector('[data-field="reason"]').textContent = entry.reason || '';
        fragment.querySelector('[data-field="changed_at"]').textContent = formatDateTime(entry.changed_at);

        return fragment;
    }

    function renderLinkedBookingRow(booking) {
        const template = document.querySelector('[data-linked-booking-row-template]');
        const fragment = template.content.cloneNode(true);

        const link = fragment.querySelector('[data-booking-link]');
        link.href = `/admin/bookings/${encodeURIComponent(booking.uuid)}`;

        fragment.querySelector('[data-field="booking_number"]').textContent = booking.booking_number;
        fragment.querySelector('[data-field="created_at"]').textContent = formatDateTime(booking.created_at);
        renderBadge(fragment.querySelector('[data-field="status"]'), booking.status);

        return fragment;
    }

    function renderBilling(billing) {
        const card = page.querySelector('[data-billing-card]');

        if (!billing) {
            card.style.display = 'none';
            return;
        }

        card.style.display = 'block';
        page.querySelector('[data-billing-detail-link]').href = `/admin/billing/${encodeURIComponent(billing.uuid)}`;
        renderBadge(field('billing_status'), billing.status);
        setText('billing_provider', billing.provider);
        setText('billing_recurring_amount', formatMoney(billing.recurring_amount, billing.currency));
        setText('billing_interval', statusLabel(billing.billing_interval));
        setText(
            'billing_current_period',
            billing.current_period_start
                ? `${formatDateTime(billing.current_period_start)} → ${formatDateTime(billing.current_period_end)}`
                : '—',
        );
        setText('billing_past_due_since', billing.past_due_since ? formatDateTime(billing.past_due_since) : '—');
        setText('billing_cancel_at', billing.cancel_at ? formatDateTime(billing.cancel_at) : '—');
        setText('billing_stripe_subscription_id', billing.stripe_subscription_id);
        setText('billing_stripe_customer_id', billing.stripe_customer_id);
    }

    function renderActions(contract) {
        actionsContainer.replaceChildren();

        const addButton = (label, className, onClick) => {
            const button = document.createElement('button');
            button.type = 'button';
            button.className = className;
            button.textContent = label;
            button.addEventListener('click', onClick);
            actionsContainer.appendChild(button);
        };

        const secondaryClass = 'rounded-lg border border-slate-300 bg-white px-3.5 py-2 '
            + 'text-xs font-semibold text-slate-700 hover:bg-slate-50';
        const primaryClass = 'rounded-lg bg-slate-950 px-3.5 py-2 text-xs font-semibold '
            + 'text-white hover:bg-slate-800';
        const dangerClass = 'rounded-lg bg-red-600 px-3.5 py-2 text-xs font-semibold '
            + 'text-white hover:bg-red-700';

        const runMutation = async (path, body, successMessage) => {
            await request(`/api/v1/admin/contracts/${encodeURIComponent(contractUuid)}${path}`, {
                method: 'POST',
                body,
            });
            await loadContract();
        };

        if (contract.status === 'REQUESTED') {
            addButton('Approve', primaryClass, () => openApproveModal(contract, loadContract));
        }

        if (contract.status === 'APPROVED') {
            addButton('Send for acceptance', primaryClass, () => {
                openConfirmAction({
                    title: 'Send for acceptance',
                    message: `Send "${contract.contract_number}" to the customer for acceptance?`,
                    confirmLabel: 'Send for acceptance',
                    showReason: false,
                    onConfirm: () => runMutation('/send-for-acceptance', {}),
                });
            });
        }

        if (contract.status === 'ACTIVE') {
            addButton('Suspend', secondaryClass, () => {
                openConfirmAction({
                    title: 'Suspend contract',
                    message: `Suspend "${contract.contract_number}"? Existing bookings are unaffected, but no new bookings will be authorized.`,
                    confirmLabel: 'Suspend',
                    onConfirm: (reason) => runMutation('/suspend', { reason }),
                });
            });
        }

        if (!['CANCELLED', 'EXPIRED'].includes(contract.status)) {
            addButton('Cancel contract', dangerClass, () => {
                openConfirmAction({
                    title: 'Cancel contract',
                    message: `Cancel "${contract.contract_number}"? This cannot be undone and may require a fresh security-key verification.`,
                    confirmLabel: 'Cancel contract',
                    // Reuses the existing centralized Step-Up flow
                    // transparently - see lib/api-client.js's request():
                    // a 428/STEP_UP_REQUIRED response here triggers the
                    // real WebAuthn ceremony and retries this exact call
                    // once. A Step-Up failure/cancellation surfaces as a
                    // normal error in this modal - the Admin session and
                    // this page both remain fully usable.
                    onConfirm: (reason) => runMutation('/cancel', { reason }),
                });
            });
        }

        if (actionsContainer.children.length === 0) {
            const note = document.createElement('p');
            note.className = 'text-xs text-slate-400';
            note.textContent = 'No further lifecycle actions are available for this contract.';
            actionsContainer.appendChild(note);
        }
    }

    function renderContract(contract) {
        setText('contract_number', contract.contract_number);
        setText('created_at', formatDateTime(contract.created_at));
        setText('updated_at', formatDateTime(contract.updated_at));
        renderBadge(field('status'), contract.status);

        setText('customer_name', contract.customer?.full_name);
        setText('customer_phone', contract.customer?.phone_number);
        setText('customer_email', contract.customer?.email);

        setText('starts_at', contract.term.starts_at ? formatDateTime(contract.term.starts_at) : '—');
        setText('ends_at', contract.term.ends_at ? formatDateTime(contract.term.ends_at) : '—');
        setText('term_months', contract.term.term_months ?? '—');

        setText('accepted', contract.acceptance.accepted ? 'Yes' : 'No');
        setText('accepted_at', contract.acceptance.accepted_at ? formatDateTime(contract.acceptance.accepted_at) : '—');
        setText('quoted_amount', contract.quoted_amount ? formatMoney(contract.quoted_amount, contract.currency) : '—');

        renderBilling(contract.billing);

        const coveredServicesEl = page.querySelector('[data-covered-services]');
        const coveredServicesEmptyEl = page.querySelector('[data-covered-services-empty]');

        if (contract.covered_services.length === 0) {
            coveredServicesEl.replaceChildren();
            coveredServicesEmptyEl.classList.remove('hidden');
        } else {
            coveredServicesEmptyEl.classList.add('hidden');
            coveredServicesEl.replaceChildren(...contract.covered_services.map(renderCoveredService));
        }

        setText('requested_all_services', contract.requested_all_services ? 'Yes' : 'No');
        setText('requested_starts_on', contract.requested_starts_on || '—');
        setText('customer_note', contract.customer_note || 'None provided.');
        setText('internal_note', contract.internal_note || 'None recorded.');

        page.querySelector('[data-status-history]').replaceChildren(
            ...contract.status_history.map(renderStatusHistoryRow),
        );

        const bookingsEl = page.querySelector('[data-linked-bookings]');
        const bookingsEmptyEl = page.querySelector('[data-linked-bookings-empty]');

        if (contract.bookings.length === 0) {
            bookingsEl.replaceChildren();
            bookingsEmptyEl.classList.remove('hidden');
        } else {
            bookingsEmptyEl.classList.add('hidden');
            bookingsEl.replaceChildren(...contract.bookings.map(renderLinkedBookingRow));
        }

        renderActions(contract);
    }

    adminAuthReady().then((ready) => {
        if (ready) {
            loadContract();
        }
    });
}
