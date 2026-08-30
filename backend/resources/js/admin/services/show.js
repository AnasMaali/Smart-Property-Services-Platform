/**
 * Admin Service detail (BLUE V1 Phase B8). Reuses the centralized Admin API
 * client against the existing GET /v1/admin/services/{service} endpoint
 * (App\Actions\Admin\Service\AdminGetServiceAction / App\Support\Admin\
 * AdminServicePresenter) - every field rendered below comes directly from
 * that response; nothing is invented or recomputed client-side.
 *
 * Options/Capabilities/Specializations/Media/Pricing are rendered
 * read-only, matching the backend's deliberate scope decision (see
 * AdminGetServiceAction's docblock) - there is no mutation UI for any of
 * them here. Service/Category names/descriptions are database content
 * (Customer- and Admin-authored) and are rendered exclusively via
 * textContent/createElement below - never innerHTML.
 */

import { request, ApiError } from '../lib/api-client.js';
import { adminAuthReady } from '../auth/restore.js';
import { formatDateTime } from '../lib/format.js';
import { openToggleActiveModal } from './toggle-active.js';

const page = document.querySelector('[data-service-detail-page]');

if (page) {
    const serviceUuid = page.dataset.serviceUuid;
    const loadingEl = page.querySelector('[data-service-loading]');
    const errorEl = page.querySelector('[data-service-error]');
    const contentEl = page.querySelector('[data-service-content]');
    const metadataForm = page.querySelector('[data-metadata-form]');
    const metadataSubmit = metadataForm.querySelector('[data-metadata-submit]');
    const metadataError = metadataForm.querySelector('[data-metadata-error]');
    const toggleButton = page.querySelector('[data-toggle-active-button]');
    const optionRowTemplate = document.querySelector('[data-option-row-template]');
    const mediaRowTemplate = document.querySelector('[data-media-row-template]');

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

    function badge(text, activeClasses, inactiveClasses, isActive) {
        const el = document.createElement('span');
        el.className = `rounded-full px-2.5 py-1 text-xs font-semibold ${isActive ? activeClasses : inactiveClasses}`;
        el.textContent = text;

        return el;
    }

    function activeBadgeEl(isActive) {
        return badge(isActive ? 'Active' : 'Inactive', 'bg-emerald-50 text-emerald-700', 'bg-slate-100 text-slate-600', isActive);
    }

    function renderCapabilities(capabilities) {
        const container = page.querySelector('[data-capabilities]');
        const emptyEl = page.querySelector('[data-capabilities-empty]');
        container.replaceChildren();

        if (capabilities.length === 0) {
            emptyEl.classList.remove('hidden');
            return;
        }

        emptyEl.classList.add('hidden');

        capabilities.forEach((capability) => {
            const pill = document.createElement('span');
            pill.className = 'rounded-full bg-blue-50 px-2.5 py-1 text-xs font-semibold text-blue-700';
            pill.title = capability.description ?? '';
            pill.textContent = capability.name;
            container.appendChild(pill);
        });
    }

    function renderSpecializations(specializations) {
        const container = page.querySelector('[data-specializations]');
        const emptyEl = page.querySelector('[data-specializations-empty]');
        container.replaceChildren();

        if (specializations.length === 0) {
            emptyEl.classList.remove('hidden');
            return;
        }

        emptyEl.classList.add('hidden');

        specializations.forEach((specialization) => {
            const item = document.createElement('li');
            item.className = 'flex items-center justify-between gap-2';

            const label = document.createElement('span');
            label.className = 'text-slate-700';
            label.textContent = specialization.is_primary ? `${specialization.name} (primary)` : specialization.name;

            item.append(label, activeBadgeEl(specialization.is_active));
            container.appendChild(item);
        });
    }

    function optionRuleSummary(option) {
        if (option.numeric_rule) {
            const unit = option.numeric_rule.measurement_unit_symbol ? ` ${option.numeric_rule.measurement_unit_symbol}` : '';

            return `Range: ${option.numeric_rule.min_value}${unit} – ${option.numeric_rule.max_value}${unit}, step ${option.numeric_rule.step_value}${unit}`;
        }

        if (option.selection_rule) {
            return `Select ${option.selection_rule.minimum_selections}–${option.selection_rule.maximum_selections} choice(s)`;
        }

        return '';
    }

    function renderOptions(options) {
        const container = page.querySelector('[data-options]');
        const emptyEl = page.querySelector('[data-options-empty]');
        container.replaceChildren();

        if (options.length === 0) {
            emptyEl.classList.remove('hidden');
            return;
        }

        emptyEl.classList.add('hidden');

        options.forEach((option) => {
            const node = optionRowTemplate.content.cloneNode(true);

            node.querySelector('[data-field="name"]').textContent = option.name;
            node.querySelector('[data-field="type"]').textContent = option.type;

            const requiredBadge = node.querySelector('[data-field="required"]');
            requiredBadge.textContent = option.is_required ? 'Required' : 'Optional';
            requiredBadge.className = `rounded-full px-2.5 py-1 text-xs font-semibold ${option.is_required ? 'bg-amber-50 text-amber-700' : 'bg-slate-100 text-slate-600'}`;

            node.querySelector('[data-field="is_active"]').replaceWith(activeBadgeEl(option.is_active));

            const summary = optionRuleSummary(option);
            node.querySelector('[data-field="rule_summary"]').textContent = summary;

            const choicesEl = node.querySelector('[data-choices]');
            if (option.choices && option.choices.length > 0) {
                option.choices.forEach((choice) => {
                    const item = document.createElement('li');
                    item.textContent = choice.is_active ? choice.name : `${choice.name} (inactive)`;
                    choicesEl.appendChild(item);
                });
            }

            container.appendChild(node);
        });
    }

    function renderMedia(media) {
        const container = page.querySelector('[data-media]');
        const emptyEl = page.querySelector('[data-media-empty]');
        container.replaceChildren();

        if (media.length === 0) {
            emptyEl.classList.remove('hidden');
            return;
        }

        emptyEl.classList.add('hidden');

        media.forEach((item) => {
            const node = mediaRowTemplate.content.cloneNode(true);

            node.querySelector('[data-field="alt_text"]').textContent = item.alt_text;
            node.querySelector('[data-field="mime_type"]').textContent = item.mime_type;

            const primaryBadge = node.querySelector('[data-field="is_primary"]');
            primaryBadge.style.display = item.is_primary ? 'inline-block' : 'none';

            node.querySelector('[data-field="is_active"]').replaceWith(activeBadgeEl(item.is_active));

            container.appendChild(node);
        });
    }

    function renderPricing(pricing) {
        setText('pricing_currency', pricing.currency_code);

        const container = page.querySelector('[data-pricing-versions]');
        const emptyEl = page.querySelector('[data-pricing-empty]');
        container.replaceChildren();

        if (pricing.scheme_versions.length === 0) {
            emptyEl.classList.remove('hidden');
            return;
        }

        emptyEl.classList.add('hidden');

        pricing.scheme_versions.forEach((version) => {
            const item = document.createElement('li');
            item.className = 'flex items-center justify-between gap-2 text-slate-700';

            const label = document.createElement('a');
            label.href = `/admin/pricing/${encodeURIComponent(version.id)}`;
            label.className = 'font-medium text-blue-600 hover:text-blue-800';
            label.textContent = version.effective_from
                ? `${formatDateTime(version.effective_from)} → ${version.effective_to ? formatDateTime(version.effective_to) : 'open-ended'}`
                : 'Not yet effective';

            const statusBadge = document.createElement('span');
            statusBadge.className = 'rounded-full bg-slate-100 px-2.5 py-1 text-xs font-semibold text-slate-600';
            statusBadge.textContent = version.status;

            item.append(label, statusBadge);
            container.appendChild(item);
        });
    }

    async function loadService() {
        setState('loading');

        try {
            const response = await request(`/api/v1/admin/services/${encodeURIComponent(serviceUuid)}`);
            renderService(response.data.service);
            setState('ready');
        } catch (error) {
            const message = error instanceof ApiError ? error.message : 'Unable to load this service.';
            showError(message);
        }
    }

    function renderService(service) {
        setText('code', service.code);
        setText('slug', service.slug);
        setText('name', service.name);

        const categoryLink = page.querySelector('[data-category-link]');
        categoryLink.textContent = service.category.name;
        categoryLink.href = `/admin/service-categories/${service.category.id}`;

        const statusBadge = field('is_active');
        statusBadge.textContent = service.is_active ? 'Active' : 'Inactive';
        statusBadge.className = `rounded-full px-3 py-1.5 text-xs font-semibold ${service.is_active ? 'bg-emerald-50 text-emerald-700' : 'bg-slate-100 text-slate-600'}`;

        toggleButton.textContent = service.is_active ? 'Deactivate' : 'Activate';
        toggleButton.onclick = () => onToggleActive(service.is_active);

        metadataForm.elements.namedItem('name').value = service.name;
        metadataForm.elements.namedItem('short_description').value = service.short_description ?? '';
        metadataForm.elements.namedItem('description').value = service.description ?? '';
        metadataForm.elements.namedItem('display_order').value = String(service.display_order);

        renderCapabilities(service.capabilities);
        renderSpecializations(service.specializations);
        renderOptions(service.options);
        renderMedia(service.media);
        renderPricing(service.pricing);
    }

    function onToggleActive(isCurrentlyActive) {
        const action = isCurrentlyActive ? 'deactivate' : 'activate';

        openToggleActiveModal({
            title: isCurrentlyActive ? 'Deactivate service' : 'Activate service',
            message: isCurrentlyActive
                ? 'This hides the service from the mobile app and stops new Cart additions. Carts it is already in, and existing Bookings/Contracts, are unaffected.'
                : 'This makes the service visible in the mobile app again.',
            confirmLabel: isCurrentlyActive ? 'Deactivate' : 'Activate',
            onConfirm: async () => {
                await request(`/api/v1/admin/services/${encodeURIComponent(serviceUuid)}/${action}`, { method: 'POST' });
                await loadService();
            },
        });
    }

    metadataForm.addEventListener('submit', async (event) => {
        event.preventDefault();
        metadataError.classList.add('hidden');
        metadataSubmit.disabled = true;

        try {
            await request(`/api/v1/admin/services/${encodeURIComponent(serviceUuid)}`, {
                method: 'PATCH',
                body: {
                    name: metadataForm.elements.namedItem('name').value.trim(),
                    short_description: metadataForm.elements.namedItem('short_description').value.trim() || null,
                    description: metadataForm.elements.namedItem('description').value.trim() || null,
                    display_order: Number(metadataForm.elements.namedItem('display_order').value),
                },
            });

            await loadService();
        } catch (error) {
            metadataError.textContent = error instanceof ApiError ? error.message : 'Unable to save these changes.';
            metadataError.classList.remove('hidden');
        } finally {
            metadataSubmit.disabled = false;
        }
    });

    adminAuthReady().then((ready) => {
        if (ready) {
            loadService();
        }
    });
}
