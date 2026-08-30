/**
 * Admin Service Category detail (BLUE V1 Phase B8). Reuses the centralized
 * Admin API client against the existing GET /v1/admin/service-categories/
 * {category} endpoint (App\Actions\Admin\Service\AdminGetServiceCategoryAction
 * / App\Support\Admin\AdminServiceCategoryPresenter) - every field rendered
 * below comes directly from that response.
 *
 * Two mutations exist: editing display metadata (PATCH) and toggling
 * is_active (POST activate/deactivate). Both reload authoritative server
 * state afterward (loadCategory()) rather than patching local state.
 */

import { request, ApiError } from '../lib/api-client.js';
import { adminAuthReady } from '../auth/restore.js';
import { openToggleActiveModal } from './toggle-active.js';

const page = document.querySelector('[data-category-detail-page]');

if (page) {
    const categoryId = page.dataset.categoryId;
    const loadingEl = page.querySelector('[data-category-loading]');
    const errorEl = page.querySelector('[data-category-error]');
    const contentEl = page.querySelector('[data-category-content]');
    const metadataForm = page.querySelector('[data-metadata-form]');
    const metadataSubmit = metadataForm.querySelector('[data-metadata-submit]');
    const metadataError = metadataForm.querySelector('[data-metadata-error]');
    const toggleButton = page.querySelector('[data-toggle-active-button]');
    const servicesEl = page.querySelector('[data-services]');
    const servicesEmptyEl = page.querySelector('[data-services-empty]');
    const serviceRowTemplate = document.querySelector('[data-service-row-template]');

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

    function renderActiveBadge(isActive) {
        const badge = field('is_active');
        badge.textContent = isActive ? 'Active' : 'Inactive';
        badge.className = `rounded-full px-3 py-1.5 text-xs font-semibold ${isActive ? 'bg-emerald-50 text-emerald-700' : 'bg-slate-100 text-slate-600'}`;
    }

    function renderServiceRow(service) {
        const node = serviceRowTemplate.content.cloneNode(true);
        const link = node.querySelector('[data-service-link]');

        link.href = `/admin/services/${encodeURIComponent(service.uuid)}`;
        node.querySelector('[data-field="name"]').textContent = service.name;
        node.querySelector('[data-field="code"]').textContent = service.code;
        node.querySelector('[data-field="display_order"]').textContent = `#${service.display_order}`;

        const badge = node.querySelector('[data-field="is_active"]');
        badge.textContent = service.is_active ? 'Active' : 'Inactive';
        badge.className = `rounded-full px-2.5 py-1 text-xs font-semibold ${service.is_active ? 'bg-emerald-50 text-emerald-700' : 'bg-slate-100 text-slate-600'}`;

        return node;
    }

    async function loadCategory() {
        setState('loading');

        try {
            const response = await request(`/api/v1/admin/service-categories/${encodeURIComponent(categoryId)}`);
            renderCategory(response.data.service_category);
            setState('ready');
        } catch (error) {
            const message = error instanceof ApiError ? error.message : 'Unable to load this service category.';
            showError(message);
        }
    }

    function renderCategory(category) {
        setText('code', category.code);
        setText('name', category.name);
        renderActiveBadge(category.is_active);

        toggleButton.textContent = category.is_active ? 'Deactivate' : 'Activate';
        toggleButton.onclick = () => onToggleActive(category.is_active);

        metadataForm.elements.namedItem('name').value = category.name;
        metadataForm.elements.namedItem('description').value = category.description ?? '';
        metadataForm.elements.namedItem('display_order').value = String(category.display_order);

        setText('services_count', String(category.services.length));

        if (category.services.length === 0) {
            servicesEmptyEl.classList.remove('hidden');
            servicesEl.replaceChildren();
        } else {
            servicesEmptyEl.classList.add('hidden');
            servicesEl.replaceChildren(...category.services.map(renderServiceRow));
        }
    }

    function onToggleActive(isCurrentlyActive) {
        const action = isCurrentlyActive ? 'deactivate' : 'activate';

        openToggleActiveModal({
            title: isCurrentlyActive ? 'Deactivate category' : 'Activate category',
            message: isCurrentlyActive
                ? 'This hides the category (and its services, via the category listing) from the mobile app. Existing bookings and contracts are unaffected.'
                : 'This makes the category visible in the mobile app again.',
            confirmLabel: isCurrentlyActive ? 'Deactivate' : 'Activate',
            onConfirm: async () => {
                await request(`/api/v1/admin/service-categories/${encodeURIComponent(categoryId)}/${action}`, { method: 'POST' });
                await loadCategory();
            },
        });
    }

    metadataForm.addEventListener('submit', async (event) => {
        event.preventDefault();
        metadataError.classList.add('hidden');
        metadataSubmit.disabled = true;

        try {
            await request(`/api/v1/admin/service-categories/${encodeURIComponent(categoryId)}`, {
                method: 'PATCH',
                body: {
                    name: metadataForm.elements.namedItem('name').value.trim(),
                    description: metadataForm.elements.namedItem('description').value.trim() || null,
                    display_order: Number(metadataForm.elements.namedItem('display_order').value),
                },
            });

            await loadCategory();
        } catch (error) {
            metadataError.textContent = error instanceof ApiError ? error.message : 'Unable to save these changes.';
            metadataError.classList.remove('hidden');
        } finally {
            metadataSubmit.disabled = false;
        }
    });

    adminAuthReady().then((ready) => {
        if (ready) {
            loadCategory();
        }
    });
}
