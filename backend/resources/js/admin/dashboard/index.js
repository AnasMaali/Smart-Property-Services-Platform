/**
 * Admin Dashboard (BLUE V1 Phase B10). Reuses the centralized Admin API
 * client against the single GET /v1/admin/dashboard endpoint
 * (App\Actions\Admin\Dashboard\AdminGetDashboardAction / App\Support\
 * Admin\AdminDashboardPresenter) - every number and list rendered below
 * comes directly from that response; nothing is computed or guessed
 * client-side. Every "Needs attention" item links straight into the
 * existing domain detail page that already owns the real mutation for it
 * (e.g. a pending-assignment item links to /admin/bookings/{uuid}, never a
 * duplicate "assign" action here). A metric value of 0 is valid data and
 * is always rendered as "0", never as "—".
 */

import { request, ApiError } from '../lib/api-client.js';
import { statusLabel, formatDateTime, formatMoney } from '../lib/format.js';
import { adminAuthReady } from '../auth/restore.js';

const page = document.querySelector('[data-dashboard-page]');

if (page) {
    const loadingEl = page.querySelector('[data-dashboard-loading]');
    const errorEl = page.querySelector('[data-dashboard-error]');
    const contentEl = page.querySelector('[data-dashboard-content]');
    const summaryCardsEl = page.querySelector('[data-summary-cards]');
    const financialSnapshotEl = page.querySelector('[data-financial-snapshot]');
    const attentionGroupsEl = page.querySelector('[data-attention-groups]');
    const attentionEmptyEl = page.querySelector('[data-attention-empty]');
    const activityListEl = page.querySelector('[data-activity-list]');
    const activityEmptyEl = page.querySelector('[data-activity-empty]');

    const summaryCardTemplate = document.querySelector('[data-summary-card-template]');
    const metricRowTemplate = document.querySelector('[data-metric-row-template]');
    const attentionGroupTemplate = document.querySelector('[data-attention-group-template]');
    const attentionItemTemplate = document.querySelector('[data-attention-item-template]');
    const activityRowTemplate = document.querySelector('[data-activity-row-template]');

    const SUMMARY_CARDS = [
        {
            key: 'bookings',
            title: 'Bookings',
            metrics: [
                ['active', 'Active'],
                ['created_last_24h', 'Created (24h)'],
                ['pending_assignment', 'Pending assignment'],
                ['in_progress', 'In progress'],
            ],
        },
        {
            key: 'contracts',
            title: 'Contracts',
            metrics: [
                ['active', 'Active'],
                ['awaiting_approval', 'Awaiting approval'],
                ['pending_customer_acceptance', 'Pending acceptance'],
                ['pending_payment', 'Pending payment'],
                ['suspended', 'Suspended'],
            ],
        },
        {
            key: 'financial',
            title: 'Financial',
            metrics: [
                ['payments_successful_last_24h', 'Successful (24h)'],
                ['payments_pending', 'Pending'],
                ['payments_requiring_reconciliation', 'Needs reconciliation'],
                ['billings_past_due', 'Billings past due'],
            ],
        },
        {
            key: 'customers',
            title: 'Customers',
            metrics: [
                ['active', 'Active'],
                ['registered_last_24h', 'Registered (24h)'],
            ],
        },
        {
            key: 'support',
            title: 'Support',
            metrics: [
                ['open_or_in_progress', 'Open / In progress'],
                ['unassigned_open', 'Unassigned'],
            ],
        },
        {
            key: 'technicians',
            title: 'Technicians',
            metrics: [
                ['assignable', 'Assignable'],
                ['busy', 'Busy'],
            ],
        },
    ];

    const ATTENTION_GROUPS = [
        {
            key: 'booking_items_pending_assignment',
            title: 'Pending technician assignment',
            item: (item) => ({
                primary: `${item.booking_number} — ${item.service_name}`,
                secondary: formatDateTime(item.created_at),
                href: `/admin/bookings/${encodeURIComponent(item.booking_uuid)}`,
            }),
        },
        {
            key: 'contracts_awaiting_approval',
            title: 'Contracts awaiting approval',
            item: (item) => ({
                primary: item.contract_number,
                secondary: item.customer_name,
                href: `/admin/contracts/${encodeURIComponent(item.contract_uuid)}`,
            }),
        },
        {
            key: 'payments_requiring_reconciliation',
            title: 'Payments requiring reconciliation',
            item: (item) => ({
                primary: item.checkout_reference,
                secondary: formatMoney(item.requested_amount, { code: item.currency_code, decimal_places: 2 }),
                href: `/admin/payments/${encodeURIComponent(item.payment_uuid)}`,
            }),
        },
        {
            key: 'billings_past_due',
            title: 'Contract billings past due',
            item: (item) => ({
                primary: item.contract_number,
                secondary: `Past due since ${formatDateTime(item.past_due_since)}`,
                href: `/admin/billing/${encodeURIComponent(item.billing_uuid)}`,
            }),
        },
        {
            key: 'support_unassigned_open',
            title: 'Unassigned support requests',
            item: (item) => ({
                primary: item.request_number,
                secondary: item.subject,
                href: `/admin/support/${encodeURIComponent(item.support_request_uuid)}`,
            }),
        },
    ];

    function setState(state) {
        loadingEl.classList.toggle('hidden', state !== 'loading');
        errorEl.classList.toggle('hidden', state !== 'error');
        contentEl.style.display = state === 'ready' ? 'flex' : 'none';
    }

    function showError(message) {
        errorEl.textContent = message;
        setState('error');
    }

    function renderSummaryCards(summary) {
        summaryCardsEl.replaceChildren(...SUMMARY_CARDS.map((card) => {
            const node = summaryCardTemplate.content.cloneNode(true);
            node.querySelector('[data-field="title"]').textContent = card.title;

            const metricsEl = node.querySelector('[data-metrics]');
            const values = summary[card.key] ?? {};

            metricsEl.replaceChildren(...card.metrics.map(([field, label]) => {
                const row = metricRowTemplate.content.cloneNode(true);
                row.querySelector('[data-field="label"]').textContent = label;
                row.querySelector('[data-field="value"]').textContent = String(values[field] ?? 0);
                return row;
            }));

            return node;
        }));
    }

    function renderAttentionItem(item) {
        const node = attentionItemTemplate.content.cloneNode(true);
        const link = node.querySelector('[data-item-link]');
        link.href = item.href;
        node.querySelector('[data-field="primary"]').textContent = item.primary;
        node.querySelector('[data-field="secondary"]').textContent = item.secondary ?? '';
        return node;
    }

    function renderAttentionGroups(attention) {
        let anyItems = false;

        const groups = ATTENTION_GROUPS.map((group) => {
            const node = attentionGroupTemplate.content.cloneNode(true);
            node.querySelector('[data-field="title"]').textContent = group.title;

            const rawItems = attention[group.key] ?? [];
            const itemsEl = node.querySelector('[data-items]');
            const emptyNote = node.querySelector('[data-empty-note]');

            if (rawItems.length === 0) {
                emptyNote.classList.remove('hidden');
            } else {
                anyItems = true;
                itemsEl.replaceChildren(...rawItems.map((raw) => renderAttentionItem(group.item(raw))));
            }

            return node;
        });

        attentionGroupsEl.replaceChildren(...groups);
        attentionEmptyEl.classList.toggle('hidden', anyItems);
        attentionGroupsEl.style.display = anyItems ? 'block' : 'none';
    }

    function renderActivity(entries) {
        if (entries.length === 0) {
            activityEmptyEl.classList.remove('hidden');
            activityListEl.replaceChildren();
            return;
        }

        activityEmptyEl.classList.add('hidden');

        activityListEl.replaceChildren(...entries.map((entry) => {
            const node = activityRowTemplate.content.cloneNode(true);

            node.querySelector('[data-field="description"]').textContent = statusLabel(entry.action_code);

            if (!entry.was_successful) {
                node.querySelector('[data-field="failure"]').classList.remove('hidden');
            }

            const identifier = entry.entity_identifier ? ` ${entry.entity_identifier}` : '';
            const actor = entry.actor_name ?? 'System';
            node.querySelector('[data-field="meta"]').textContent = `${entry.entity_type}${identifier} · ${actor} · ${formatDateTime(entry.created_at)}`;

            return node;
        }));
    }

    function renderFinancialSnapshot(snapshot) {
        const currency = snapshot.currency;

        financialSnapshotEl.querySelector('[data-field="gross_revenue"]').textContent = formatMoney(snapshot.gross_revenue, currency);
        financialSnapshotEl.querySelector('[data-field="net_revenue"]').textContent = formatMoney(snapshot.net_revenue, currency);
        financialSnapshotEl.querySelector('[data-field="refunds"]').textContent = formatMoney(snapshot.refunds, currency);
        financialSnapshotEl.querySelector('[data-field="pay_on_site_pending"]').textContent = formatMoney(snapshot.pay_on_site_pending, currency);
    }

    async function loadDashboard() {
        setState('loading');

        try {
            const response = await request('/api/v1/admin/dashboard');
            const data = response.data;

            renderSummaryCards(data.summary);
            renderFinancialSnapshot(data.financial_snapshot);
            renderAttentionGroups(data.attention);
            renderActivity(data.recent_activity);

            setState('ready');
        } catch (error) {
            const message = error instanceof ApiError ? error.message : 'Unable to load the dashboard.';
            showError(message);
        }
    }

    adminAuthReady().then((ready) => {
        if (ready) {
            loadDashboard();
        }
    });
}
