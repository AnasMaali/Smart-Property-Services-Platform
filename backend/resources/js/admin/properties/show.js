/**
 * Admin Property detail (BLUE V1 Phase B6). Reuses the centralized Admin
 * API client against GET /v1/admin/properties/{property} (App\Actions\
 * Admin\Property\AdminGetPropertyAction, which itself reuses
 * App\Support\Property\PropertyPresenter::present() verbatim) - every
 * field rendered below comes directly from that response. Read-only: no
 * mutation exists for this module. Links back to the owning Customer's
 * detail page (B6) via the customer's own uuid.
 */

import { request, ApiError } from '../lib/api-client.js';
import { statusBadgeClasses, statusLabel, formatDateTime } from '../lib/format.js';

const page = document.querySelector('[data-property-detail-page]');

if (page) {
    const propertyUuid = page.dataset.propertyUuid;
    const loadingEl = page.querySelector('[data-property-loading]');
    const errorEl = page.querySelector('[data-property-error]');
    const contentEl = page.querySelector('[data-property-content]');

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

    function renderContractRow(contract) {
        const template = document.querySelector('[data-contract-row-template]');
        const fragment = template.content.cloneNode(true);

        const link = fragment.querySelector('[data-contract-link]');
        link.href = `/admin/contracts/${encodeURIComponent(contract.uuid)}`;

        fragment.querySelector('[data-field="contract_number"]').textContent = contract.contract_number;
        fragment.querySelector('[data-field="term"]').textContent = contract.starts_at
            ? `${formatDateTime(contract.starts_at)} → ${formatDateTime(contract.ends_at)}`
            : 'Term not yet set';

        const badge = fragment.querySelector('[data-field="status"]');
        badge.textContent = statusLabel(contract.status);
        badge.className = `rounded-full px-2.5 py-1 text-xs font-semibold ${statusBadgeClasses(contract.status)}`;

        return fragment;
    }

    async function loadProperty() {
        setState('loading');

        try {
            const response = await request(`/api/v1/admin/properties/${encodeURIComponent(propertyUuid)}`);
            render(response.data);
            setState('ready');
        } catch (error) {
            const message = error instanceof ApiError ? error.message : 'Unable to load this property.';
            showError(message);
        }
    }

    function render({ property, customer, contracts }) {
        const backLink = page.querySelector('[data-back-to-customer]');

        if (customer) {
            backLink.href = `/admin/customers/${encodeURIComponent(customer.uuid)}`;
        } else {
            backLink.href = '/admin/customers';
        }

        setText('label', property.label);
        setText('created_at', formatDateTime(property.created_at));
        setText('updated_at', formatDateTime(property.updated_at));

        const activeBadge = field('is_active');
        activeBadge.textContent = property.is_active ? 'Active' : 'Archived';
        activeBadge.className = `rounded-full px-3 py-1.5 text-xs font-semibold ${property.is_active ? 'bg-emerald-50 text-emerald-700' : 'bg-slate-100 text-slate-600'}`;

        setText('customer_name', customer?.full_name);
        setText('customer_phone', customer?.phone_number);
        setText('relationship_type', property.relationship_type?.name);

        setText('property_type', property.property_type?.name);
        setText('other_property_type_name', property.other_property_type_name || '—');

        setText('area', property.area ? `${property.area.name}, ${property.area.city_name}, ${property.area.country_name}` : '—');
        setText('street_name', property.street_name);
        setText('building_name_or_number', property.building_name_or_number);
        setText('floor_unit', [property.floor_number, property.unit_number].filter(Boolean).join(' / ') || '—');
        setText('address_line', property.address_line);
        setText('nearby_landmark', property.nearby_landmark ? `Near: ${property.nearby_landmark}` : '');

        setText('visit_contact_phone', property.visit_contact_phone);
        setText('additional_location_notes', property.additional_location_notes || 'None recorded.');

        const contractsEl = page.querySelector('[data-contracts]');
        const contractsEmptyEl = page.querySelector('[data-contracts-empty]');

        if (contracts.length === 0) {
            contractsEl.replaceChildren();
            contractsEmptyEl.classList.remove('hidden');
        } else {
            contractsEmptyEl.classList.add('hidden');
            contractsEl.replaceChildren(...contracts.map(renderContractRow));
        }
    }

    loadProperty();
}
