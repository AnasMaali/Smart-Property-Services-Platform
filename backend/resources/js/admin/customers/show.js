/**
 * Admin Customer detail (BLUE V1 Phase B6). Reuses the centralized Admin
 * API client against GET /v1/admin/customers/{customer} (App\Actions\
 * Admin\Customer\AdminGetCustomerAction / App\Support\Admin\
 * AdminCustomerPresenter) - every field rendered below comes directly from
 * that response. Read-only: no mutation exists for this module.
 *
 * "Operational links" deep-link into the existing B2/B4/B5 list pages with
 * `?customer_uuid=...` - every one of those filters is already supported
 * server-side by the respective AdminList*Action, never a fabricated query
 * parameter.
 */

import { request, ApiError } from '../lib/api-client.js';
import { statusBadgeClasses, statusLabel, formatDateTime } from '../lib/format.js';

const page = document.querySelector('[data-customer-detail-page]');

if (page) {
    const customerUuid = page.dataset.customerUuid;
    const loadingEl = page.querySelector('[data-customer-loading]');
    const errorEl = page.querySelector('[data-customer-error]');
    const contentEl = page.querySelector('[data-customer-content]');

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

    function renderOperationalLinks(customerUuid) {
        const container = page.querySelector('[data-operational-links]');
        container.replaceChildren();

        const links = [
            ['Bookings', `/admin/bookings?customer_uuid=${encodeURIComponent(customerUuid)}`],
            ['Payments', `/admin/payments?customer_uuid=${encodeURIComponent(customerUuid)}`],
            ['Contracts', `/admin/contracts?customer_uuid=${encodeURIComponent(customerUuid)}`],
            ['Contract Billing', `/admin/billing?customer_uuid=${encodeURIComponent(customerUuid)}`],
            ['Support Requests', `/admin/support?customer_uuid=${encodeURIComponent(customerUuid)}`],
            ['Ratings', `/admin/ratings?customer_uuid=${encodeURIComponent(customerUuid)}`],
        ];

        links.forEach(([label, href]) => {
            const link = document.createElement('a');
            link.href = href;
            link.className = 'rounded-lg border border-slate-300 bg-white px-3.5 py-2 '
                + 'text-xs font-semibold text-slate-700 hover:bg-slate-50';
            link.textContent = `View ${label}`;
            container.appendChild(link);
        });
    }

    function renderProperty(property) {
        const template = document.querySelector('[data-property-row-template]');
        const fragment = template.content.cloneNode(true);

        const link = fragment.querySelector('[data-property-link]');
        link.href = `/admin/properties/${encodeURIComponent(property.uuid)}`;

        fragment.querySelector('[data-field="label"]').textContent = property.label;
        fragment.querySelector('[data-field="address_summary"]').textContent = [
            property.building_name_or_number,
            property.street_name,
            property.area?.name,
            property.area?.city_name,
        ].filter(Boolean).join(', ');
        fragment.querySelector('[data-field="relationship_type"]').textContent = property.relationship_type?.name || '';

        const statusBadge = fragment.querySelector('[data-field="is_active"]');
        statusBadge.textContent = property.is_active ? 'Active' : 'Archived';
        statusBadge.className = `rounded-full px-2.5 py-1 text-xs font-semibold ${property.is_active ? 'bg-emerald-50 text-emerald-700' : 'bg-slate-100 text-slate-600'}`;

        return fragment;
    }

    async function loadCustomer() {
        setState('loading');

        try {
            const response = await request(`/api/v1/admin/customers/${encodeURIComponent(customerUuid)}`);
            renderCustomer(response.data.customer);
            setState('ready');
        } catch (error) {
            const message = error instanceof ApiError ? error.message : 'Unable to load this customer.';
            showError(message);
        }
    }

    function renderCustomer(customer) {
        setText('full_name', customer.full_name);
        setText('created_at', formatDateTime(customer.created_at));
        renderBadge(field('account_status'), customer.account_status);

        page.querySelector('[data-deletion-badge]').style.display = customer.account_deletion.status === 'PENDING' ? 'inline-block' : 'none';

        setText('phone_number', customer.phone_number);
        setText('phone_verified', customer.phone_verified ? 'Yes' : 'No');
        setText('email', customer.email);

        setText('last_login_at', customer.last_login_at ? formatDateTime(customer.last_login_at) : 'Never');
        setText('updated_at', formatDateTime(customer.updated_at));
        setText('deletion_requested_at', customer.account_deletion.requested_at ? formatDateTime(customer.account_deletion.requested_at) : '—');

        setText('location', customer.location ? `${customer.location.area_name}, ${customer.location.city_name}` : '—');
        setText('property_relationship', customer.property_relationship?.name);

        renderOperationalLinks(customer.uuid);

        setText('properties_count', String(customer.activity.properties_count));

        const propertiesEl = page.querySelector('[data-properties]');
        const propertiesEmptyEl = page.querySelector('[data-properties-empty]');

        if (customer.properties.length === 0) {
            propertiesEl.replaceChildren();
            propertiesEmptyEl.classList.remove('hidden');
        } else {
            propertiesEmptyEl.classList.add('hidden');
            propertiesEl.replaceChildren(...customer.properties.map(renderProperty));
        }
    }

    loadCustomer();
}
