/**
 * Admin Service detail (BLUE V1 Phase B8, extended in Phase B23). Reuses the
 * centralized Admin API client against GET /v1/admin/services/{service}
 * (App\Actions\Admin\Service\AdminGetServiceAction / App\Support\Admin\
 * AdminServicePresenter) - every field rendered below comes directly from
 * that response; nothing is invented or recomputed client-side.
 *
 * Phase B23 adds write UX for: category move, specializations, options +
 * choices (create/edit/activate/deactivate), media (upload/activate/
 * deactivate), and the two-price catalog block (original/current price).
 * Every mutation re-fetches the full service via loadService() afterward
 * rather than patching local state, so the rendered page always reflects
 * exactly what the backend just computed (including the canonical
 * PricingEngine-derived current price).
 */

import { request, ApiError } from '../lib/api-client.js';
import { adminAuthReady } from '../auth/restore.js';
import { formatDateTime } from '../lib/format.js';
import { openToggleActiveModal } from './toggle-active.js';

const OPTION_TYPES = [
    { code: 'TEXT', label: 'Text' },
    { code: 'NUMBER', label: 'Number' },
    { code: 'BOOLEAN', label: 'Yes / No' },
    { code: 'SINGLE_SELECT', label: 'Single selection' },
    { code: 'MULTI_SELECT', label: 'Multiple selection' },
];

const SELECT_TYPES = ['SINGLE_SELECT', 'MULTI_SELECT'];

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
    const choiceRowTemplate = document.querySelector('[data-choice-row-template]');
    const mediaRowTemplate = document.querySelector('[data-media-row-template]');

    const changeCategoryForm = page.querySelector('[data-change-category-form]');
    const changeCategorySelect = changeCategoryForm.querySelector('[data-change-category-select]');
    const changeCategoryError = changeCategoryForm.querySelector('[data-change-category-error]');

    const specializationForm = page.querySelector('[data-specialization-form]');
    const specializationSelect = specializationForm.querySelector('[data-specialization-select]');
    const specializationError = specializationForm.querySelector('[data-specialization-error]');

    const mediaUploadForm = page.querySelector('[data-media-upload-form]');
    const mediaUploadSubmit = mediaUploadForm.querySelector('[data-media-upload-submit]');
    const mediaUploadError = mediaUploadForm.querySelector('[data-media-upload-error]');

    const originalPriceForm = page.querySelector('[data-original-price-form]');
    const originalPriceError = originalPriceForm.querySelector('[data-original-price-error]');

    const currentPriceForm = page.querySelector('[data-current-price-form]');
    const currentPriceError = currentPriceForm.querySelector('[data-current-price-error]');

    const discountSummaryEl = page.querySelector('[data-discount-summary]');

    const catalogPolicyForm = page.querySelector('[data-catalog-policy-form]');
    const catalogPolicyError = catalogPolicyForm.querySelector('[data-catalog-policy-error]');

    const contentSectionsEl = page.querySelector('[data-content-sections]');
    const contentSectionsEmptyEl = page.querySelector('[data-content-sections-empty]');
    const addContentSectionForm = page.querySelector('[data-add-content-section-form]');
    const addContentSectionError = addContentSectionForm.querySelector('[data-add-content-section-error]');
    const contentSectionTypeSelect = addContentSectionForm.querySelector('[data-content-section-type-select]');
    const contentSectionRowTemplate = document.querySelector('[data-content-section-row-template]');
    const editContentSectionModal = document.querySelector('[data-edit-content-section-modal]');
    const editContentSectionForm = editContentSectionModal.querySelector('[data-edit-content-section-form]');
    const editContentSectionError = editContentSectionModal.querySelector('[data-edit-content-section-error]');

    const checkpointGroupsEl = page.querySelector('[data-checkpoint-groups]');
    const checkpointGroupsEmptyEl = page.querySelector('[data-checkpoint-groups-empty]');
    const checkpointGroupCardTemplate = document.querySelector('[data-checkpoint-group-card-template]');
    const checkpointRowTemplate = document.querySelector('[data-checkpoint-row-template]');
    const addCheckpointGroupModal = document.querySelector('[data-add-checkpoint-group-modal]');
    const addCheckpointGroupForm = addCheckpointGroupModal.querySelector('[data-add-checkpoint-group-form]');
    const addCheckpointGroupError = addCheckpointGroupModal.querySelector('[data-add-checkpoint-group-error]');
    const addCheckpointModal = document.querySelector('[data-add-checkpoint-modal]');
    const addCheckpointForm = addCheckpointModal.querySelector('[data-add-checkpoint-form]');
    const addCheckpointError = addCheckpointModal.querySelector('[data-add-checkpoint-error]');
    const checkpointActionTypeSelect = addCheckpointModal.querySelector('[data-checkpoint-action-type-select]');
    const checkpointMoveField = addCheckpointModal.querySelector('[data-checkpoint-move-field]');
    const checkpointGroupSelect = addCheckpointModal.querySelector('[data-checkpoint-group-select]');

    const choiceAttributePillTemplate = document.querySelector('[data-choice-attribute-pill-template]');
    const addChoiceAttributeModal = document.querySelector('[data-add-choice-attribute-modal]');
    const addChoiceAttributeForm = addChoiceAttributeModal.querySelector('[data-add-choice-attribute-form]');
    const addChoiceAttributeError = addChoiceAttributeModal.querySelector('[data-add-choice-attribute-error]');
    const choiceAttributeTypeSelect = addChoiceAttributeModal.querySelector('[data-choice-attribute-type-select]');

    const addOptionModal = document.querySelector('[data-add-option-modal]');
    const addOptionForm = addOptionModal.querySelector('[data-add-option-form]');
    const addOptionTitle = addOptionModal.querySelector('[data-add-option-title]');
    const addOptionSubmit = addOptionModal.querySelector('[data-add-option-submit]');
    const addOptionError = addOptionModal.querySelector('[data-add-option-error]');
    const addOptionCreateOnlyFields = addOptionModal.querySelector('[data-option-create-only-fields]');
    const optionTypeSelect = addOptionModal.querySelector('[data-option-type-select]');
    const numericFieldsEl = addOptionModal.querySelector('[data-numeric-fields]');
    const selectionFieldsEl = addOptionModal.querySelector('[data-selection-fields]');

    const addChoiceModal = document.querySelector('[data-add-choice-modal]');
    const addChoiceForm = addChoiceModal.querySelector('[data-add-choice-form]');
    const addChoiceTitle = addChoiceModal.querySelector('[data-add-choice-title]');
    const addChoiceSubmit = addChoiceModal.querySelector('[data-add-choice-submit]');
    const addChoiceError = addChoiceModal.querySelector('[data-add-choice-error]');
    const addChoiceCreateOnlyFields = addChoiceModal.querySelector('[data-choice-create-only-fields]');

    let latestService = null;

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

    function messageOf(error, fallback) {
        return error instanceof ApiError ? error.message : fallback;
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

    function renderChoiceRow(option, choice) {
        const node = choiceRowTemplate.content.cloneNode(true);
        const row = node.querySelector('[data-choice-row]');
        row.dataset.choiceUuid = choice.uuid;

        node.querySelector('[data-field="name"]').textContent = choice.is_active ? choice.name : `${choice.name} (inactive)`;

        const editButton = node.querySelector('[data-choice-edit]');
        editButton.addEventListener('click', () => openChoiceModal({ mode: 'edit', option, choice }));

        const toggleButtonEl = node.querySelector('[data-choice-toggle-active]');
        toggleButtonEl.textContent = choice.is_active ? 'Deactivate' : 'Activate';
        toggleButtonEl.addEventListener('click', () => onToggleChoiceActive(choice));

        node.querySelector('[data-choice-add-attribute]').addEventListener('click', () => openAddChoiceAttributeModal(choice.uuid));

        const attributesEl = node.querySelector('[data-choice-attributes]');
        (choice.attributes ?? []).forEach((attribute) => {
            const pillNode = choiceAttributePillTemplate.content.cloneNode(true);
            const pill = pillNode.querySelector('[data-choice-attribute-pill]');
            pill.classList.toggle('opacity-50', !attribute.is_active);
            pillNode.querySelector('[data-field="label"]').textContent = `${attribute.attribute_type_name}: ${attribute.value}`;
            pillNode.querySelector('[data-choice-attribute-toggle]').addEventListener('click', () => onToggleChoiceAttributeActive(attribute));
            attributesEl.appendChild(pillNode);
        });

        return node;
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
            const row = node.querySelector('[data-option-row]');
            row.dataset.optionUuid = option.uuid;

            node.querySelector('[data-field="name"]').textContent = option.name;
            node.querySelector('[data-field="type"]').textContent = option.type;

            const requiredBadge = node.querySelector('[data-field="required"]');
            requiredBadge.textContent = option.is_required ? 'Required' : 'Optional';
            requiredBadge.className = `rounded-full px-2.5 py-1 text-xs font-semibold ${option.is_required ? 'bg-amber-50 text-amber-700' : 'bg-slate-100 text-slate-600'}`;

            node.querySelector('[data-field="is_active"]').replaceWith(activeBadgeEl(option.is_active));

            const summary = optionRuleSummary(option);
            node.querySelector('[data-field="rule_summary"]').textContent = summary;

            node.querySelector('[data-option-edit]').addEventListener('click', () => openOptionModal({ mode: 'edit', option }));

            const toggleButtonEl = node.querySelector('[data-option-toggle-active]');
            toggleButtonEl.textContent = option.is_active ? 'Deactivate' : 'Activate';
            toggleButtonEl.addEventListener('click', () => onToggleOptionActive(option));

            const choicesEl = node.querySelector('[data-choices]');
            const addChoiceButton = node.querySelector('[data-add-choice-open]');

            if (SELECT_TYPES.includes(option.type)) {
                addChoiceButton.classList.remove('hidden');
                addChoiceButton.addEventListener('click', () => openChoiceModal({ mode: 'create', option }));

                (option.choices ?? []).forEach((choice) => {
                    choicesEl.appendChild(renderChoiceRow(option, choice));
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
            const row = node.querySelector('[data-media-row]');
            row.dataset.mediaUuid = item.uuid;

            node.querySelector('[data-field="alt_text"]').textContent = item.alt_text;
            node.querySelector('[data-field="mime_type"]').textContent = item.mime_type;

            const primaryBadge = node.querySelector('[data-field="is_primary"]');
            primaryBadge.style.display = item.is_primary ? 'inline-block' : 'none';

            node.querySelector('[data-field="is_active"]').replaceWith(activeBadgeEl(item.is_active));

            const toggleButtonEl = node.querySelector('[data-media-toggle-active]');
            toggleButtonEl.textContent = item.is_active ? 'Deactivate' : 'Activate';
            toggleButtonEl.addEventListener('click', () => onToggleMediaActive(item));

            container.appendChild(node);
        });
    }

    function renderPricing(pricing) {
        setText('pricing_currency', pricing.currency_code);

        originalPriceForm.elements.namedItem('original_price').value = pricing.original_amount ?? '';
        currentPriceForm.elements.namedItem('current_price').value = pricing.current_amount ?? '';

        if (pricing.current_amount === null) {
            discountSummaryEl.textContent = 'No current price published yet.';
        } else if (pricing.has_discount) {
            discountSummaryEl.textContent = `Showing ${pricing.original_amount} ${pricing.currency} crossed out, selling at ${pricing.current_amount} ${pricing.currency}.`;
        } else {
            discountSummaryEl.textContent = `Selling at ${pricing.current_amount} ${pricing.currency}. No list-price discount configured.`;
        }

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

    // --- Content sections -----------------------------------------------------

    function renderContentSections(sections) {
        contentSectionsEl.replaceChildren();

        if (sections.length === 0) {
            contentSectionsEmptyEl.classList.remove('hidden');
            return;
        }

        contentSectionsEmptyEl.classList.add('hidden');

        sections.forEach((section) => {
            const node = contentSectionRowTemplate.content.cloneNode(true);
            const row = node.querySelector('[data-content-section-row]');
            row.dataset.sectionUuid = section.uuid;

            node.querySelector('[data-field="section_type_code"]').textContent = section.section_type_name;
            node.querySelector('[data-field="title"]').textContent = section.title;
            node.querySelector('[data-field="body"]').textContent = section.body;
            node.querySelector('[data-field="is_active"]').replaceWith(activeBadgeEl(section.is_active));

            node.querySelector('[data-edit-content-section]').addEventListener('click', () => openEditContentSectionModal(section));

            const toggleButtonEl = node.querySelector('[data-toggle-content-section-active]');
            toggleButtonEl.textContent = section.is_active ? 'Deactivate' : 'Activate';
            toggleButtonEl.addEventListener('click', () => onToggleContentSectionActive(section));

            contentSectionsEl.appendChild(node);
        });
    }

    function onToggleContentSectionActive(section) {
        const action = section.is_active ? 'deactivate' : 'activate';

        openToggleActiveModal({
            title: section.is_active ? 'Deactivate content section' : 'Activate content section',
            message: section.is_active
                ? 'This hides the section from the customer catalog.'
                : 'This makes the section visible in the customer catalog again.',
            confirmLabel: section.is_active ? 'Deactivate' : 'Activate',
            onConfirm: async () => {
                await request(`/api/v1/admin/service-content-sections/${encodeURIComponent(section.uuid)}/${action}`, { method: 'POST' });
                await loadService();
            },
        });
    }

    function openEditContentSectionModal(section) {
        editContentSectionError.classList.add('hidden');
        editContentSectionForm.dataset.sectionUuid = section.uuid;
        editContentSectionForm.elements.namedItem('title').value = section.title;
        editContentSectionForm.elements.namedItem('body').value = section.body;
        editContentSectionForm.elements.namedItem('display_order').value = String(section.display_order);
        editContentSectionModal.style.display = 'flex';
    }

    editContentSectionModal.querySelector('[data-edit-content-section-cancel]').addEventListener('click', () => {
        editContentSectionModal.style.display = 'none';
    });

    editContentSectionForm.addEventListener('submit', async (event) => {
        event.preventDefault();
        editContentSectionError.classList.add('hidden');

        try {
            await request(`/api/v1/admin/service-content-sections/${encodeURIComponent(editContentSectionForm.dataset.sectionUuid)}`, {
                method: 'PATCH',
                body: {
                    title: editContentSectionForm.elements.namedItem('title').value.trim(),
                    body: editContentSectionForm.elements.namedItem('body').value.trim(),
                    display_order: Number(editContentSectionForm.elements.namedItem('display_order').value || 0),
                },
            });

            editContentSectionModal.style.display = 'none';
            await loadService();
        } catch (error) {
            editContentSectionError.textContent = messageOf(error, 'Unable to save this content section.');
            editContentSectionError.classList.remove('hidden');
        }
    });

    addContentSectionForm.addEventListener('submit', async (event) => {
        event.preventDefault();
        addContentSectionError.classList.add('hidden');

        try {
            await request(`/api/v1/admin/services/${encodeURIComponent(serviceUuid)}/content-sections`, {
                method: 'POST',
                body: {
                    section_type_code: contentSectionTypeSelect.value,
                    title: addContentSectionForm.elements.namedItem('title').value.trim(),
                    body: addContentSectionForm.elements.namedItem('body').value.trim(),
                    display_order: 0,
                },
            });

            addContentSectionForm.reset();
            await loadService();
        } catch (error) {
            addContentSectionError.textContent = messageOf(error, 'Unable to add this content section.');
            addContentSectionError.classList.remove('hidden');
        }
    });

    // --- Checkpoint groups / checkpoints ---------------------------------------

    function renderCheckpointGroups(groups) {
        checkpointGroupsEl.replaceChildren();

        if (groups.length === 0) {
            checkpointGroupsEmptyEl.classList.remove('hidden');
            return;
        }

        checkpointGroupsEmptyEl.classList.add('hidden');

        groups.forEach((group) => {
            const node = checkpointGroupCardTemplate.content.cloneNode(true);
            const card = node.querySelector('[data-checkpoint-group-card]');
            card.dataset.groupUuid = group.uuid;

            node.querySelector('[data-field="name"]').textContent = group.name;
            node.querySelector('[data-field="description"]').textContent = group.description ?? '';
            node.querySelector('[data-field="active_checkpoint_count"]').textContent = String(group.active_checkpoint_count);
            node.querySelector('[data-field="checkpoint_count"]').textContent = String(group.checkpoint_count);
            node.querySelector('[data-field="is_active"]').replaceWith(activeBadgeEl(group.is_active));

            node.querySelector('[data-edit-checkpoint-group]').addEventListener('click', () => openEditCheckpointGroupModal(group));

            const toggleGroupButton = node.querySelector('[data-toggle-checkpoint-group-active]');
            toggleGroupButton.textContent = group.is_active ? 'Deactivate' : 'Activate';
            toggleGroupButton.addEventListener('click', () => onToggleCheckpointGroupActive(group));

            node.querySelector('[data-add-checkpoint-open]').addEventListener('click', () => openAddCheckpointModal(group.uuid));

            const checkpointsEl = node.querySelector('[data-checkpoints]');
            group.checkpoints.forEach((checkpoint) => {
                const checkpointNode = checkpointRowTemplate.content.cloneNode(true);
                const row = checkpointNode.querySelector('[data-checkpoint-row]');
                row.dataset.checkpointUuid = checkpoint.uuid;

                checkpointNode.querySelector('[data-field="name"]').textContent = checkpoint.name;
                checkpointNode.querySelector('[data-field="action_type_name"]').textContent = checkpoint.action_type_name;
                checkpointNode.querySelector('[data-field="is_active"]').replaceWith(activeBadgeEl(checkpoint.is_active));

                checkpointNode.querySelector('[data-edit-checkpoint]').addEventListener('click', () => openEditCheckpointModal(checkpoint, group.uuid));

                const toggleCheckpointButton = checkpointNode.querySelector('[data-toggle-checkpoint-active]');
                toggleCheckpointButton.textContent = checkpoint.is_active ? 'Deactivate' : 'Activate';
                toggleCheckpointButton.addEventListener('click', () => onToggleCheckpointActive(checkpoint));

                checkpointsEl.appendChild(checkpointNode);
            });

            checkpointGroupsEl.appendChild(node);
        });
    }

    function onToggleCheckpointGroupActive(group) {
        const action = group.is_active ? 'deactivate' : 'activate';

        openToggleActiveModal({
            title: group.is_active ? 'Deactivate checkpoint group' : 'Activate checkpoint group',
            message: group.is_active ? 'This hides the group (and its checkpoints) from the customer catalog.' : 'This makes the group visible again.',
            confirmLabel: group.is_active ? 'Deactivate' : 'Activate',
            onConfirm: async () => {
                await request(`/api/v1/admin/service-checkpoint-groups/${encodeURIComponent(group.uuid)}/${action}`, { method: 'POST' });
                await loadService();
            },
        });
    }

    function onToggleCheckpointActive(checkpoint) {
        const action = checkpoint.is_active ? 'deactivate' : 'activate';

        openToggleActiveModal({
            title: checkpoint.is_active ? 'Deactivate checkpoint' : 'Activate checkpoint',
            message: checkpoint.is_active ? 'This hides the checkpoint from the customer catalog.' : 'This makes the checkpoint visible again.',
            confirmLabel: checkpoint.is_active ? 'Deactivate' : 'Activate',
            onConfirm: async () => {
                await request(`/api/v1/admin/service-checkpoints/${encodeURIComponent(checkpoint.uuid)}/${action}`, { method: 'POST' });
                await loadService();
            },
        });
    }

    page.querySelector('[data-add-checkpoint-group-open]').addEventListener('click', () => {
        addCheckpointGroupError.classList.add('hidden');
        addCheckpointGroupForm.reset();
        addCheckpointGroupForm.dataset.mode = 'create';
        addCheckpointGroupModal.querySelector('[data-add-checkpoint-group-title]').textContent = 'Add checkpoint group';
        addCheckpointGroupModal.style.display = 'flex';
    });

    addCheckpointGroupModal.querySelector('[data-add-checkpoint-group-cancel]').addEventListener('click', () => {
        addCheckpointGroupModal.style.display = 'none';
    });

    function openEditCheckpointGroupModal(group) {
        addCheckpointGroupError.classList.add('hidden');
        addCheckpointGroupForm.dataset.mode = 'edit';
        addCheckpointGroupForm.dataset.groupUuid = group.uuid;
        addCheckpointGroupForm.elements.namedItem('name').value = group.name;
        addCheckpointGroupForm.elements.namedItem('description').value = group.description ?? '';
        addCheckpointGroupForm.elements.namedItem('display_order').value = String(group.display_order);
        addCheckpointGroupModal.querySelector('[data-add-checkpoint-group-title]').textContent = 'Edit checkpoint group';
        addCheckpointGroupModal.style.display = 'flex';
    }

    addCheckpointGroupForm.addEventListener('submit', async (event) => {
        event.preventDefault();
        addCheckpointGroupError.classList.add('hidden');

        const payload = {
            name: addCheckpointGroupForm.elements.namedItem('name').value.trim(),
            description: addCheckpointGroupForm.elements.namedItem('description').value.trim() || null,
            display_order: Number(addCheckpointGroupForm.elements.namedItem('display_order').value || 0),
        };

        try {
            if (addCheckpointGroupForm.dataset.mode === 'edit') {
                await request(`/api/v1/admin/service-checkpoint-groups/${encodeURIComponent(addCheckpointGroupForm.dataset.groupUuid)}`, {
                    method: 'PATCH',
                    body: payload,
                });
            } else {
                await request(`/api/v1/admin/services/${encodeURIComponent(serviceUuid)}/checkpoint-groups`, {
                    method: 'POST',
                    body: payload,
                });
            }

            addCheckpointGroupModal.style.display = 'none';
            await loadService();
        } catch (error) {
            addCheckpointGroupError.textContent = messageOf(error, 'Unable to save this checkpoint group.');
            addCheckpointGroupError.classList.remove('hidden');
        }
    });

    function openAddCheckpointModal(groupUuid) {
        addCheckpointError.classList.add('hidden');
        addCheckpointForm.reset();
        addCheckpointForm.dataset.mode = 'create';
        addCheckpointForm.dataset.groupUuid = groupUuid;
        checkpointMoveField.classList.add('hidden');
        addCheckpointModal.querySelector('[data-add-checkpoint-title]').textContent = 'Add checkpoint';
        addCheckpointModal.style.display = 'flex';
    }

    function openEditCheckpointModal(checkpoint, currentGroupUuid) {
        addCheckpointError.classList.add('hidden');
        addCheckpointForm.dataset.mode = 'edit';
        addCheckpointForm.dataset.checkpointUuid = checkpoint.uuid;
        addCheckpointForm.elements.namedItem('name').value = checkpoint.name;
        addCheckpointForm.elements.namedItem('description').value = checkpoint.description ?? '';
        addCheckpointForm.elements.namedItem('action_type_code').value = checkpoint.action_type_code;
        addCheckpointForm.elements.namedItem('display_order').value = String(checkpoint.display_order);
        checkpointGroupSelect.value = currentGroupUuid;
        checkpointMoveField.classList.remove('hidden');
        addCheckpointModal.querySelector('[data-add-checkpoint-title]').textContent = 'Edit checkpoint';
        addCheckpointModal.style.display = 'flex';
    }

    addCheckpointModal.querySelector('[data-add-checkpoint-cancel]').addEventListener('click', () => {
        addCheckpointModal.style.display = 'none';
    });

    addCheckpointForm.addEventListener('submit', async (event) => {
        event.preventDefault();
        addCheckpointError.classList.add('hidden');

        const payload = {
            name: addCheckpointForm.elements.namedItem('name').value.trim(),
            description: addCheckpointForm.elements.namedItem('description').value.trim() || null,
            action_type_code: checkpointActionTypeSelect.value,
            display_order: Number(addCheckpointForm.elements.namedItem('display_order').value || 0),
        };

        try {
            if (addCheckpointForm.dataset.mode === 'edit') {
                payload.group_uuid = checkpointGroupSelect.value;
                await request(`/api/v1/admin/service-checkpoints/${encodeURIComponent(addCheckpointForm.dataset.checkpointUuid)}`, {
                    method: 'PATCH',
                    body: payload,
                });
            } else {
                await request(`/api/v1/admin/service-checkpoint-groups/${encodeURIComponent(addCheckpointForm.dataset.groupUuid)}/checkpoints`, {
                    method: 'POST',
                    body: payload,
                });
            }

            addCheckpointModal.style.display = 'none';
            await loadService();
        } catch (error) {
            addCheckpointError.textContent = messageOf(error, 'Unable to save this checkpoint.');
            addCheckpointError.classList.remove('hidden');
        }
    });

    // --- Catalog policy ---------------------------------------------------------

    catalogPolicyForm.addEventListener('submit', async (event) => {
        event.preventDefault();
        catalogPolicyError.classList.add('hidden');

        try {
            await request(`/api/v1/admin/services/${encodeURIComponent(serviceUuid)}/catalog-policy`, {
                method: 'POST',
                body: {
                    is_featured: catalogPolicyForm.elements.namedItem('is_featured').checked,
                    estimated_duration_minutes: catalogPolicyForm.elements.namedItem('estimated_duration_minutes').value || null,
                    min_quantity: Number(catalogPolicyForm.elements.namedItem('min_quantity').value),
                    max_quantity: Number(catalogPolicyForm.elements.namedItem('max_quantity').value),
                },
            });

            await loadService();
        } catch (error) {
            catalogPolicyError.textContent = messageOf(error, 'Unable to save the catalog policy.');
            catalogPolicyError.classList.remove('hidden');
        }
    });

    // --- Choice attributes -------------------------------------------------------

    function openAddChoiceAttributeModal(choiceUuid) {
        addChoiceAttributeError.classList.add('hidden');
        addChoiceAttributeForm.reset();
        addChoiceAttributeForm.dataset.choiceUuid = choiceUuid;
        addChoiceAttributeModal.style.display = 'flex';
    }

    addChoiceAttributeModal.querySelector('[data-add-choice-attribute-cancel]').addEventListener('click', () => {
        addChoiceAttributeModal.style.display = 'none';
    });

    addChoiceAttributeForm.addEventListener('submit', async (event) => {
        event.preventDefault();
        addChoiceAttributeError.classList.add('hidden');

        try {
            await request(`/api/v1/admin/service-option-choices/${encodeURIComponent(addChoiceAttributeForm.dataset.choiceUuid)}/attributes`, {
                method: 'POST',
                body: {
                    attribute_type_code: choiceAttributeTypeSelect.value,
                    value: addChoiceAttributeForm.elements.namedItem('value').value.trim(),
                },
            });

            addChoiceAttributeModal.style.display = 'none';
            await loadService();
        } catch (error) {
            addChoiceAttributeError.textContent = messageOf(error, 'Unable to save this attribute.');
            addChoiceAttributeError.classList.remove('hidden');
        }
    });

    function onToggleChoiceAttributeActive(attribute) {
        const action = attribute.is_active ? 'deactivate' : 'activate';

        openToggleActiveModal({
            title: attribute.is_active ? 'Deactivate attribute' : 'Activate attribute',
            message: attribute.is_active ? 'This hides the attribute from the customer catalog.' : 'This makes the attribute visible again.',
            confirmLabel: attribute.is_active ? 'Deactivate' : 'Activate',
            onConfirm: async () => {
                await request(`/api/v1/admin/service-option-choice-attributes/${encodeURIComponent(attribute.uuid)}/${action}`, { method: 'POST' });
                await loadService();
            },
        });
    }

    async function loadService() {
        setState('loading');

        try {
            const response = await request(`/api/v1/admin/services/${encodeURIComponent(serviceUuid)}`);
            latestService = response.data.service;
            renderService(latestService);
            setState('ready');
        } catch (error) {
            showError(messageOf(error, 'Unable to load this service.'));
        }
    }

    function renderService(service) {
        setText('code', service.code);
        setText('slug', service.slug);
        setText('name', service.name);

        const categoryLink = page.querySelector('[data-category-link]');
        categoryLink.textContent = service.category.name;
        categoryLink.href = `/admin/service-categories/${service.category.id}`;

        changeCategorySelect.value = String(service.category.id);

        const statusBadge = field('is_active');
        statusBadge.textContent = service.is_active ? 'Active' : 'Inactive';
        statusBadge.className = `rounded-full px-3 py-1.5 text-xs font-semibold ${service.is_active ? 'bg-emerald-50 text-emerald-700' : 'bg-slate-100 text-slate-600'}`;

        toggleButton.textContent = service.is_active ? 'Deactivate' : 'Activate';
        toggleButton.onclick = () => onToggleActive(service.is_active);

        metadataForm.elements.namedItem('name').value = service.name;
        metadataForm.elements.namedItem('short_description').value = service.short_description ?? '';
        metadataForm.elements.namedItem('description').value = service.description ?? '';
        metadataForm.elements.namedItem('display_order').value = String(service.display_order);

        catalogPolicyForm.elements.namedItem('is_featured').checked = service.is_featured;
        catalogPolicyForm.elements.namedItem('estimated_duration_minutes').value = service.estimated_duration_minutes ?? '';
        catalogPolicyForm.elements.namedItem('min_quantity').value = String(service.quantity.min);
        catalogPolicyForm.elements.namedItem('max_quantity').value = String(service.quantity.max);

        renderCapabilities(service.capabilities);
        renderSpecializations(service.specializations);
        renderOptions(service.options);
        renderMedia(service.media);
        renderPricing(service.pricing);
        renderContentSections(service.content_sections);
        renderCheckpointGroups(service.checkpoint_groups);
        populateCheckpointGroupSelect(service.checkpoint_groups);
    }

    function onToggleActive(isCurrentlyActive) {
        const action = isCurrentlyActive ? 'deactivate' : 'activate';

        openToggleActiveModal({
            title: isCurrentlyActive ? 'Deactivate service' : 'Activate service',
            message: isCurrentlyActive
                ? 'This hides the service from the mobile app and stops new Cart additions. Carts it is already in, and existing Bookings/Contracts, are unaffected.'
                : 'This makes the service visible in the mobile app again. It must have an active category, a published current price, an active specialization, and choices for every required selection option.',
            confirmLabel: isCurrentlyActive ? 'Deactivate' : 'Activate',
            onConfirm: async () => {
                await request(`/api/v1/admin/services/${encodeURIComponent(serviceUuid)}/${action}`, { method: 'POST' });
                await loadService();
            },
        });
    }

    function onToggleOptionActive(option) {
        const action = option.is_active ? 'deactivate' : 'activate';

        openToggleActiveModal({
            title: option.is_active ? 'Deactivate option' : 'Activate option',
            message: option.is_active
                ? 'This hides the option from future Cart additions. In-progress Carts and historical Bookings keep whatever they already selected.'
                : 'This makes the option selectable again on future Cart additions.',
            confirmLabel: option.is_active ? 'Deactivate' : 'Activate',
            onConfirm: async () => {
                await request(`/api/v1/admin/service-options/${encodeURIComponent(option.uuid)}/${action}`, { method: 'POST' });
                await loadService();
            },
        });
    }

    function onToggleChoiceActive(choice) {
        const action = choice.is_active ? 'deactivate' : 'activate';

        openToggleActiveModal({
            title: choice.is_active ? 'Deactivate choice' : 'Activate choice',
            message: choice.is_active
                ? 'This hides the choice from future Cart additions. In-progress Carts and historical Bookings keep whatever they already selected.'
                : 'This makes the choice selectable again on future Cart additions.',
            confirmLabel: choice.is_active ? 'Deactivate' : 'Activate',
            onConfirm: async () => {
                await request(`/api/v1/admin/service-option-choices/${encodeURIComponent(choice.uuid)}/${action}`, { method: 'POST' });
                await loadService();
            },
        });
    }

    function onToggleMediaActive(item) {
        const action = item.is_active ? 'deactivate' : 'activate';

        openToggleActiveModal({
            title: item.is_active ? 'Deactivate image' : 'Activate image',
            message: item.is_active
                ? 'This hides the image from the catalog without deleting the file.'
                : 'This makes the image visible in the catalog again.',
            confirmLabel: item.is_active ? 'Deactivate' : 'Activate',
            onConfirm: async () => {
                await request(`/api/v1/admin/service-media/${encodeURIComponent(item.uuid)}/${action}`, { method: 'POST' });
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
            metadataError.textContent = messageOf(error, 'Unable to save these changes.');
            metadataError.classList.remove('hidden');
        } finally {
            metadataSubmit.disabled = false;
        }
    });

    changeCategoryForm.addEventListener('submit', async (event) => {
        event.preventDefault();
        changeCategoryError.classList.add('hidden');

        const categoryId = Number(changeCategorySelect.value);

        if (latestService && categoryId === latestService.category.id) {
            return;
        }

        try {
            await request(`/api/v1/admin/services/${encodeURIComponent(serviceUuid)}/category`, {
                method: 'POST',
                body: { category_id: categoryId },
            });

            await loadService();
        } catch (error) {
            changeCategoryError.textContent = messageOf(error, 'Unable to change category.');
            changeCategoryError.classList.remove('hidden');
        }
    });

    specializationForm.addEventListener('submit', async (event) => {
        event.preventDefault();
        specializationError.classList.add('hidden');

        try {
            await request(`/api/v1/admin/services/${encodeURIComponent(serviceUuid)}/specializations`, {
                method: 'POST',
                body: {
                    specialization_id: Number(specializationForm.elements.namedItem('specialization_id').value),
                    is_primary: specializationForm.elements.namedItem('is_primary').checked,
                    is_active: specializationForm.elements.namedItem('is_active').checked,
                    display_order: 0,
                },
            });

            specializationForm.reset();
            specializationForm.elements.namedItem('is_active').checked = true;
            await loadService();
        } catch (error) {
            specializationError.textContent = messageOf(error, 'Unable to save this specialization.');
            specializationError.classList.remove('hidden');
        }
    });

    originalPriceForm.addEventListener('submit', async (event) => {
        event.preventDefault();
        originalPriceError.classList.add('hidden');

        const raw = originalPriceForm.elements.namedItem('original_price').value.trim();

        try {
            await request(`/api/v1/admin/services/${encodeURIComponent(serviceUuid)}/original-price`, {
                method: 'POST',
                body: { original_price: raw === '' ? null : raw },
            });

            await loadService();
        } catch (error) {
            originalPriceError.textContent = messageOf(error, 'Unable to save the original price.');
            originalPriceError.classList.remove('hidden');
        }
    });

    currentPriceForm.addEventListener('submit', async (event) => {
        event.preventDefault();
        currentPriceError.classList.add('hidden');

        const raw = currentPriceForm.elements.namedItem('current_price').value.trim();

        try {
            await request(`/api/v1/admin/services/${encodeURIComponent(serviceUuid)}/current-price`, {
                method: 'POST',
                body: { current_price: raw },
            });

            await loadService();
        } catch (error) {
            currentPriceError.textContent = messageOf(error, 'Unable to publish the current price.');
            currentPriceError.classList.remove('hidden');
        }
    });

    mediaUploadForm.addEventListener('submit', async (event) => {
        event.preventDefault();
        mediaUploadError.classList.add('hidden');
        mediaUploadSubmit.disabled = true;

        try {
            const formData = new FormData(mediaUploadForm);

            if (!formData.get('is_primary')) {
                formData.set('is_primary', '0');
            }

            await request(`/api/v1/admin/services/${encodeURIComponent(serviceUuid)}/media`, {
                method: 'POST',
                body: formData,
            });

            mediaUploadForm.reset();
            await loadService();
        } catch (error) {
            mediaUploadError.textContent = messageOf(error, 'Unable to upload this image.');
            mediaUploadError.classList.remove('hidden');
        } finally {
            mediaUploadSubmit.disabled = false;
        }
    });

    // --- Add/Edit Option modal -------------------------------------------

    function toggleOptionTypeFields(typeCode) {
        numericFieldsEl.classList.toggle('hidden', typeCode !== 'NUMBER');
        selectionFieldsEl.classList.toggle('hidden', !SELECT_TYPES.includes(typeCode));
    }

    function closeOptionModal() {
        addOptionModal.style.display = 'none';
        addOptionForm.reset();
        addOptionError.classList.add('hidden');
    }

    function openOptionModal({ mode, option = null }) {
        addOptionForm.dataset.mode = mode;
        addOptionForm.dataset.optionUuid = option?.uuid ?? '';
        addOptionForm.dataset.typeCode = option?.type ?? '';
        addOptionError.classList.add('hidden');
        addOptionCreateOnlyFields.classList.toggle('hidden', mode === 'edit');

        if (mode === 'edit' && option) {
            addOptionTitle.textContent = `Edit option: ${option.name}`;
            addOptionSubmit.textContent = 'Save changes';
            addOptionForm.elements.namedItem('name').value = option.name;
            addOptionForm.elements.namedItem('description').value = option.description ?? '';
            addOptionForm.elements.namedItem('is_required').checked = option.is_required;
            addOptionForm.elements.namedItem('display_order').value = String(option.display_order);

            if (option.numeric_rule) {
                addOptionForm.elements.namedItem('min_value').value = option.numeric_rule.min_value ?? '';
                addOptionForm.elements.namedItem('max_value').value = option.numeric_rule.max_value ?? '';
            }

            if (option.selection_rule) {
                addOptionForm.elements.namedItem('minimum_selections').value = String(option.selection_rule.minimum_selections);
                addOptionForm.elements.namedItem('maximum_selections').value = String(option.selection_rule.maximum_selections);
            }

            toggleOptionTypeFields(option.type);
        } else {
            addOptionTitle.textContent = 'Add option';
            addOptionSubmit.textContent = 'Create option';
            toggleOptionTypeFields(optionTypeSelect.value);
        }

        addOptionModal.style.display = 'flex';
    }

    optionTypeSelect.addEventListener('change', () => toggleOptionTypeFields(optionTypeSelect.value));

    page.querySelector('[data-add-option-open]').addEventListener('click', () => openOptionModal({ mode: 'create' }));

    addOptionModal.querySelector('[data-add-option-cancel]').addEventListener('click', closeOptionModal);

    addOptionForm.addEventListener('submit', async (event) => {
        event.preventDefault();
        addOptionError.classList.add('hidden');

        const mode = addOptionForm.dataset.mode;
        const typeCode = mode === 'edit' ? addOptionForm.dataset.typeCode : optionTypeSelect.value;

        const payload = {
            name: addOptionForm.elements.namedItem('name').value.trim(),
            description: addOptionForm.elements.namedItem('description').value.trim() || null,
            is_required: addOptionForm.elements.namedItem('is_required').checked,
            display_order: Number(addOptionForm.elements.namedItem('display_order').value || 0),
        };

        if (mode === 'create') {
            payload.code = addOptionForm.elements.namedItem('code').value.trim();
            payload.option_type_code = typeCode;
        }

        if (typeCode === 'NUMBER') {
            payload.numeric_rule = {
                min_value: addOptionForm.elements.namedItem('min_value').value,
                max_value: addOptionForm.elements.namedItem('max_value').value,
            };
        }

        if (SELECT_TYPES.includes(typeCode)) {
            payload.selection_rule = {
                minimum_selections: Number(addOptionForm.elements.namedItem('minimum_selections').value || 0),
                maximum_selections: Number(addOptionForm.elements.namedItem('maximum_selections').value || 1),
            };
        }

        try {
            if (mode === 'edit') {
                await request(`/api/v1/admin/service-options/${encodeURIComponent(addOptionForm.dataset.optionUuid)}`, {
                    method: 'PATCH',
                    body: payload,
                });
            } else {
                await request(`/api/v1/admin/services/${encodeURIComponent(serviceUuid)}/options`, {
                    method: 'POST',
                    body: payload,
                });
            }

            closeOptionModal();
            await loadService();
        } catch (error) {
            addOptionError.textContent = messageOf(error, 'Unable to save this option.');
            addOptionError.classList.remove('hidden');
        }
    });

    // --- Add/Edit Choice modal --------------------------------------------

    function closeChoiceModal() {
        addChoiceModal.style.display = 'none';
        addChoiceForm.reset();
        addChoiceError.classList.add('hidden');
    }

    function openChoiceModal({ mode, option, choice = null }) {
        addChoiceForm.dataset.mode = mode;
        addChoiceForm.dataset.optionUuid = option.uuid;
        addChoiceForm.dataset.choiceUuid = choice?.uuid ?? '';
        addChoiceError.classList.add('hidden');
        addChoiceCreateOnlyFields.classList.toggle('hidden', mode === 'edit');

        if (mode === 'edit' && choice) {
            addChoiceTitle.textContent = `Edit choice: ${choice.name}`;
            addChoiceSubmit.textContent = 'Save changes';
            addChoiceForm.elements.namedItem('name').value = choice.name;
            addChoiceForm.elements.namedItem('description').value = choice.description ?? '';
            addChoiceForm.elements.namedItem('display_order').value = String(choice.display_order);
        } else {
            addChoiceTitle.textContent = `Add choice to ${option.name}`;
            addChoiceSubmit.textContent = 'Create choice';
        }

        addChoiceModal.style.display = 'flex';
    }

    addChoiceModal.querySelector('[data-add-choice-cancel]').addEventListener('click', closeChoiceModal);

    addChoiceForm.addEventListener('submit', async (event) => {
        event.preventDefault();
        addChoiceError.classList.add('hidden');

        const mode = addChoiceForm.dataset.mode;

        const payload = {
            name: addChoiceForm.elements.namedItem('name').value.trim(),
            description: addChoiceForm.elements.namedItem('description').value.trim() || null,
            display_order: Number(addChoiceForm.elements.namedItem('display_order').value || 0),
        };

        if (mode === 'create') {
            payload.code = addChoiceForm.elements.namedItem('code').value.trim();
        }

        try {
            if (mode === 'edit') {
                await request(`/api/v1/admin/service-option-choices/${encodeURIComponent(addChoiceForm.dataset.choiceUuid)}`, {
                    method: 'PATCH',
                    body: payload,
                });
            } else {
                await request(`/api/v1/admin/service-options/${encodeURIComponent(addChoiceForm.dataset.optionUuid)}/choices`, {
                    method: 'POST',
                    body: payload,
                });
            }

            closeChoiceModal();
            await loadService();
        } catch (error) {
            addChoiceError.textContent = messageOf(error, 'Unable to save this choice.');
            addChoiceError.classList.remove('hidden');
        }
    });

    // --- Lookups ------------------------------------------------------------

    function populateSelect(select, items, { valueKey, labelKey, placeholder = null }) {
        select.replaceChildren();

        if (placeholder) {
            const option = document.createElement('option');
            option.value = '';
            option.textContent = placeholder;
            select.appendChild(option);
        }

        items.forEach((item) => {
            const option = document.createElement('option');
            option.value = String(item[valueKey]);
            option.textContent = item[labelKey];
            select.appendChild(option);
        });
    }

    async function loadLookups() {
        populateSelect(optionTypeSelect, OPTION_TYPES, { valueKey: 'code', labelKey: 'label' });

        try {
            const [categoriesResponse, specializationsResponse, sectionTypesResponse, checkpointActionTypesResponse, attributeTypesResponse] = await Promise.all([
                request('/api/v1/admin/service-categories'),
                request('/api/v1/admin/specializations'),
                request('/api/v1/admin/service-content-section-types'),
                request('/api/v1/admin/service-checkpoint-action-types'),
                request('/api/v1/admin/service-option-choice-attribute-types'),
            ]);

            populateSelect(changeCategorySelect, categoriesResponse.data.service_categories ?? [], { valueKey: 'id', labelKey: 'name' });
            populateSelect(specializationSelect, specializationsResponse.data.specializations ?? [], { valueKey: 'id', labelKey: 'name', placeholder: 'Select a specialization...' });
            populateSelect(contentSectionTypeSelect, sectionTypesResponse.data.section_types ?? [], { valueKey: 'code', labelKey: 'name' });
            populateSelect(checkpointActionTypeSelect, checkpointActionTypesResponse.data.action_types ?? [], { valueKey: 'code', labelKey: 'name' });
            populateSelect(choiceAttributeTypeSelect, attributeTypesResponse.data.attribute_types ?? [], { valueKey: 'code', labelKey: 'name' });
        } catch {
            // Non-fatal: the read-only service detail (and its already-set
            // category/specializations) still render fine; only the "change
            // to..." dropdowns stay empty.
        }
    }

    function populateCheckpointGroupSelect(groups) {
        checkpointGroupSelect.replaceChildren();

        groups.forEach((group) => {
            const option = document.createElement('option');
            option.value = group.uuid;
            option.textContent = group.name;
            checkpointGroupSelect.appendChild(option);
        });
    }

    adminAuthReady().then((ready) => {
        if (ready) {
            loadLookups();
            loadService();
        }
    });
}
