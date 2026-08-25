/**
 * Admin Audit Log entry detail (BLUE V1 Phase B12). Reuses the centralized
 * Admin API client against the existing GET /v1/admin/audit-logs/{auditLog}
 * endpoint (App\Actions\Admin\Audit\AdminGetAuditLogAction / App\Support\
 * Admin\AdminAuditLogPresenter) - every field rendered below comes
 * directly from that response. Read-only - an audit ledger is append-only,
 * so there is no mutation for this module.
 */

import { request, ApiError } from '../lib/api-client.js';
import { statusLabel, formatDateTime } from '../lib/format.js';

const page = document.querySelector('[data-audit-log-detail-page]');

if (page) {
    const auditLogUuid = page.dataset.auditLogUuid;
    const loadingEl = page.querySelector('[data-audit-log-loading]');
    const errorEl = page.querySelector('[data-audit-log-error]');
    const contentEl = page.querySelector('[data-audit-log-content]');
    const failureRow = page.querySelector('[data-failure-row]');

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

    async function loadAuditLog() {
        setState('loading');

        try {
            const response = await request(`/api/v1/admin/audit-logs/${encodeURIComponent(auditLogUuid)}`);
            renderAuditLog(response.data.audit_log);
            setState('ready');
        } catch (error) {
            const message = error instanceof ApiError ? error.message : 'Unable to load this audit log entry.';
            showError(message);
        }
    }

    function renderAuditLog(entry) {
        setText('action_code', statusLabel(entry.action_code));
        setText('created_at', formatDateTime(entry.created_at));

        const outcomeBadge = field('outcome');
        outcomeBadge.textContent = entry.was_successful ? 'Success' : 'Failed';
        outcomeBadge.className = `rounded-full px-3 py-1.5 text-xs font-semibold ${entry.was_successful ? 'bg-emerald-50 text-emerald-700' : 'bg-red-50 text-red-700'}`;

        setText('entity_type', entry.entity_type);
        setText('entity_identifier', entry.entity_identifier);

        failureRow.style.display = entry.failure_reason ? 'flex' : 'none';
        setText('failure_reason', entry.failure_reason);

        setText('actor_name', entry.actor ? entry.actor.full_name : 'Unknown');
        setText('ip_address', entry.ip_address);
        setText('user_agent', entry.user_agent);
    }

    loadAuditLog();
}
