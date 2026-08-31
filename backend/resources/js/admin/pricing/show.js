/**
 * Admin Pricing Scheme Version detail (BLUE V1 Phase B9, extended in Phase
 * B23-ext). Reuses the centralized Admin API client against the existing
 * GET /v1/admin/pricing-schemes/{scheme} endpoint (App\Actions\Admin\
 * Pricing\AdminGetPricingSchemeAction / App\Support\Admin\
 * AdminPricingSchemePresenter) - every field rendered below comes directly
 * from that response.
 *
 * Phase B23-ext adds: condition-group/condition and tier authoring on the
 * "Add a rule" form (previously limited to a single unconditional effect -
 * conditional/multi-tier rules required calling the API directly).
 *
 * Advanced Pricing Administration (this phase) adds three things:
 *  - Pricing Preview now evaluates THIS EXACT scheme version - draft
 *    included - through POST /v1/admin/pricing-schemes/{scheme}/preview
 *    (App\Actions\Admin\Pricing\AdminPreviewPricingSchemeVersionAction),
 *    never a second/duplicated calculation and never the currently-live
 *    price of some other version.
 *  - Editing a rule (PUT .../rules/{rule}) reuses the exact same "Add a
 *    rule" form fields, only for a simple rule with no conditions/tiers -
 *    the same scope limit the "Add a rule" form itself already has for
 *    conditional/tiered rules; editing one of those is done via the API
 *    directly so this page never silently drops a condition/tier it
 *    doesn't know how to re-populate into the form.
 *  - Retiring a PUBLISHED version (POST .../retire) - a plain confirm()
 *    dialog, matching this page's existing delete-rule confirmation.
 *
 * The Rules list below already IS the "readable summary before publish":
 * every condition/tier shown there is rendered directly from the persisted
 * structure (renderConditionGroups/renderTiers), never a separate
 * business-logic description.
 *
 * Mutations (add/edit/delete rule, publish, retire) only ever act on the
 * scheme version status that allows them - the server is the authoritative
 * enforcer of that (see each Action's own docblock), but this page also
 * hides/shows the relevant forms/buttons per status as a UX hint.
 * Publishing/retiring reuse the existing WebAuthn Step-Up flow
 * transparently through lib/api-client.js's request() - no special-case
 * code is needed here. Every mutation reloads the authoritative server
 * response afterward rather than patching local state.
 */

import { request, ApiError } from '../lib/api-client.js';
import { adminAuthReady } from '../auth/restore.js';
import { statusBadgeClasses, statusLabel, formatDateTime } from '../lib/format.js';

const page = document.querySelector('[data-pricing-detail-page]');

if (page) {
    const schemeUuid = page.dataset.schemeUuid;
    const loadingEl = page.querySelector('[data-scheme-loading]');
    const errorEl = page.querySelector('[data-scheme-error]');
    const contentEl = page.querySelector('[data-scheme-content]');
    const publishCard = page.querySelector('[data-publish-card]');
    const publishForm = page.querySelector('[data-publish-form]');
    const publishSubmit = publishForm.querySelector('[data-publish-submit]');
    const publishError = page.querySelector('[data-publish-error]');
    const retireCard = page.querySelector('[data-retire-card]');
    const retireSubmit = page.querySelector('[data-retire-submit]');
    const retireError = page.querySelector('[data-retire-error]');
    const addRuleCard = page.querySelector('[data-add-rule-card]');
    const addRuleHeading = page.querySelector('[data-add-rule-heading]');
    const addRuleForm = page.querySelector('[data-add-rule-form]');
    const addRuleSubmit = addRuleForm.querySelector('[data-add-rule-submit]');
    const addRuleError = addRuleForm.querySelector('[data-add-rule-error]');
    const cancelEditRuleButton = addRuleForm.querySelector('[data-cancel-edit-rule]');
    const effectTypeSelect = addRuleForm.querySelector('[data-effect-type-select]');
    const effectAmountField = addRuleForm.querySelector('[data-effect-amount-field]');
    const perUnitFields = addRuleForm.querySelector('[data-per-unit-fields]');
    const effectOptionSelect = addRuleForm.querySelector('[data-effect-option-select]');
    const tierCalculationSelect = addRuleForm.querySelector('[data-tier-calculation-select]');
    const tiersEditor = addRuleForm.querySelector('[data-tiers-editor]');
    const addTierButton = addRuleForm.querySelector('[data-add-tier-button]');
    const conditionGroupsEditor = addRuleForm.querySelector('[data-condition-groups-editor]');
    const addConditionGroupButton = addRuleForm.querySelector('[data-add-condition-group-button]');
    const rulesEl = page.querySelector('[data-rules]');
    const rulesEmptyEl = page.querySelector('[data-rules-empty]');
    const ruleCardTemplate = document.querySelector('[data-rule-card-template]');
    const tierRowTemplate = document.querySelector('[data-tier-row-template]');
    const conditionGroupTemplate = document.querySelector('[data-condition-group-template]');
    const conditionRowTemplate = document.querySelector('[data-condition-row-template]');
    const previewOptionTemplate = document.querySelector('[data-preview-option-template]');
    const previewForm = page.querySelector('[data-preview-form]');
    const previewOptionsEl = page.querySelector('[data-preview-options]');
    const previewSubmit = previewForm.querySelector('[data-preview-submit]');
    const previewError = previewForm.querySelector('[data-preview-error]');
    const previewResultEl = page.querySelector('[data-preview-result]');

    const AMOUNTLESS_EFFECT_TYPES = ['ADD_PER_UNIT', 'QUOTE_REQUIRED'];

    const NUMERIC_OPERATORS = [
        ['EQ', 'equals'], ['NEQ', 'does not equal'], ['GT', 'is greater than'], ['GTE', 'is at least'],
        ['LT', 'is less than'], ['LTE', 'is at most'], ['BETWEEN', 'is between'],
    ];
    const CHOICE_OPERATORS = [['EQ', 'is'], ['NEQ', 'is not']];
    const BOOLEAN_OPERATORS = [['EQ', 'is'], ['NEQ', 'is not']];

    let serviceUuid = null;
    let serviceOptions = [];
    let editingRuleUuid = null;

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

    function effectTypeLabel(effectType) {
        return effectType
            .toLowerCase()
            .split('_')
            .map((word) => word.charAt(0).toUpperCase() + word.slice(1))
            .join(' ');
    }

    function conditionSummary(condition) {
        const subject = condition.option
            ? condition.option.name
            : (condition.context_attribute ? condition.context_attribute.name : condition.subject_type);

        const valueText = condition.value_choice
            ? condition.value_choice.name
            : (condition.value_number !== null && condition.value_number !== undefined
                ? condition.value_number
                : (condition.value_boolean !== null && condition.value_boolean !== undefined ? String(condition.value_boolean) : ''));

        return `${subject} ${condition.operator} ${valueText}`.trim();
    }

    function renderConditionGroups(container, groups) {
        container.replaceChildren();

        if (groups.length === 0) {
            const note = document.createElement('p');
            note.textContent = 'Always applies (no conditions).';
            container.appendChild(note);
            return;
        }

        groups.forEach((group, index) => {
            if (index > 0) {
                const orLine = document.createElement('p');
                orLine.className = 'font-semibold text-slate-400';
                orLine.textContent = 'OR';
                container.appendChild(orLine);
            }

            const groupLine = document.createElement('p');
            groupLine.textContent = group.conditions.map(conditionSummary).join(' AND ');
            container.appendChild(groupLine);
        });
    }

    function renderTiers(container, tiers) {
        container.replaceChildren();

        if (tiers.length === 0) {
            return;
        }

        const table = document.createElement('table');
        table.className = 'w-full max-w-lg text-left text-xs text-slate-500';

        const thead = document.createElement('thead');
        const headRow = document.createElement('tr');
        ['From', 'To', 'Unit size', 'Rate', 'Mode'].forEach((label) => {
            const th = document.createElement('th');
            th.className = 'py-1 pr-4 font-medium';
            th.textContent = label;
            headRow.appendChild(th);
        });
        thead.appendChild(headRow);
        table.appendChild(thead);

        const tbody = document.createElement('tbody');
        tiers.forEach((tier) => {
            const row = document.createElement('tr');
            [tier.from_unit, tier.to_unit ?? 'Open-ended', tier.charge_unit_size, tier.rate_amount, tier.tier_pricing_mode].forEach((value) => {
                const td = document.createElement('td');
                td.className = 'py-1 pr-4';
                td.textContent = String(value);
                row.appendChild(td);
            });
            tbody.appendChild(row);
        });
        table.appendChild(tbody);

        container.appendChild(table);
    }

    function renderRuleCard(rule, isDraft) {
        const node = ruleCardTemplate.content.cloneNode(true);

        node.querySelector('[data-field="label"]').textContent = rule.label;
        node.querySelector('[data-field="rule_code"]').textContent = rule.rule_code;
        node.querySelector('[data-field="priority"]').textContent = String(rule.priority);

        const effectSummary = rule.effect_amount !== null
            ? `${effectTypeLabel(rule.effect_type)}: ${rule.effect_amount}`
            : (rule.effect_subject_option ? `${effectTypeLabel(rule.effect_type)}: ${rule.effect_subject_option.name}` : effectTypeLabel(rule.effect_type));
        node.querySelector('[data-field="effect_summary"]').textContent = effectSummary;

        if (rule.stop_processing) {
            node.querySelector('[data-field="stop_processing"]').style.display = 'inline-block';
        }

        renderConditionGroups(node.querySelector('[data-condition-groups]'), rule.condition_groups);
        renderTiers(node.querySelector('[data-tiers]'), rule.tiers);

        if (isDraft) {
            const deleteButton = node.querySelector('[data-delete-rule-button]');
            deleteButton.style.display = 'inline-block';
            deleteButton.addEventListener('click', () => onDeleteRule(rule.uuid, rule.label));

            // Editing here is only offered for a simple rule with no
            // conditions/tiers - the same scope this form's "Add a rule"
            // side already has. A conditional/tiered rule's edit button
            // stays hidden so this page can never silently drop structure
            // it doesn't know how to re-populate into the form.
            if (rule.condition_groups.length === 0 && rule.tiers.length === 0) {
                const editButton = node.querySelector('[data-edit-rule-button]');
                editButton.style.display = 'inline-block';
                editButton.addEventListener('click', () => startEditRule(rule));
            }
        }

        return node;
    }

    async function loadScheme() {
        setState('loading');

        try {
            const response = await request(`/api/v1/admin/pricing-schemes/${encodeURIComponent(schemeUuid)}`);
            const scheme = response.data.pricing_scheme;
            serviceUuid = scheme.service.uuid;
            renderScheme(scheme);
            await loadServiceOptions();
            setState('ready');
        } catch (error) {
            const message = error instanceof ApiError ? error.message : 'Unable to load this pricing scheme.';
            showError(message);
        }
    }

    async function loadServiceOptions() {
        try {
            const response = await request(`/api/v1/admin/services/${encodeURIComponent(serviceUuid)}`);
            serviceOptions = response.data.service.options ?? [];
        } catch {
            serviceOptions = [];
        }

        populateEffectOptionSelect();
        renderPreviewOptions();
    }

    function renderScheme(scheme) {
        const serviceLink = page.querySelector('[data-service-link]');
        serviceLink.textContent = scheme.service.name;
        serviceLink.href = `/admin/services/${encodeURIComponent(scheme.service.uuid)}`;

        setText('currency', scheme.currency.code);

        const statusBadge = field('status');
        statusBadge.textContent = statusLabel(scheme.status);
        statusBadge.className = `rounded-full px-3 py-1.5 text-xs font-semibold ${statusBadgeClasses(scheme.status)}`;

        setText('effective_from', scheme.effective_from ? formatDateTime(scheme.effective_from) : '—');
        setText('effective_to', scheme.effective_to ? formatDateTime(scheme.effective_to) : (scheme.effective_from ? 'Open-ended' : '—'));
        setText('published_at', scheme.published_at ? formatDateTime(scheme.published_at) : '—');
        setText('updated_at', formatDateTime(scheme.updated_at));

        const isDraft = scheme.status === 'DRAFT';
        publishCard.style.display = isDraft ? 'block' : 'none';
        addRuleCard.style.display = isDraft ? 'block' : 'none';
        retireCard.style.display = scheme.status === 'PUBLISHED' ? 'block' : 'none';

        if (!isDraft) {
            cancelEditRule();
        }

        setText('rules_count', String(scheme.rules.length));

        if (scheme.rules.length === 0) {
            rulesEmptyEl.classList.remove('hidden');
            rulesEl.replaceChildren();
        } else {
            rulesEmptyEl.classList.add('hidden');
            rulesEl.replaceChildren(...scheme.rules.map((rule) => renderRuleCard(rule, isDraft)));
        }
    }

    async function onDeleteRule(ruleUuid, label) {
        if (!window.confirm(`Delete rule "${label}"? This cannot be undone.`)) {
            return;
        }

        try {
            await request(`/api/v1/admin/pricing-schemes/${encodeURIComponent(schemeUuid)}/rules/${encodeURIComponent(ruleUuid)}`, {
                method: 'DELETE',
            });
            await loadScheme();
        } catch (error) {
            window.alert(error instanceof ApiError ? error.message : 'Unable to delete this rule.');
        }
    }

    function startEditRule(rule) {
        editingRuleUuid = rule.uuid;
        addRuleForm.reset();
        tiersEditor.replaceChildren();
        conditionGroupsEditor.replaceChildren();
        perUnitFields.classList.add('hidden');

        addRuleForm.elements.namedItem('rule_code').value = rule.rule_code;
        addRuleForm.elements.namedItem('label').value = rule.label;
        addRuleForm.elements.namedItem('priority').value = String(rule.priority);
        effectTypeSelect.value = rule.effect_type;
        effectAmountField.style.display = AMOUNTLESS_EFFECT_TYPES.includes(rule.effect_type) ? 'none' : 'block';
        addRuleForm.elements.namedItem('effect_amount').value = rule.effect_amount ?? '';
        addRuleForm.elements.namedItem('stop_processing').checked = rule.stop_processing;

        addRuleHeading.textContent = `Edit rule "${rule.label}"`;
        addRuleSubmit.textContent = 'Save changes';
        cancelEditRuleButton.style.display = 'inline-block';
        addRuleForm.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }

    function cancelEditRule() {
        editingRuleUuid = null;
        addRuleForm.reset();
        tiersEditor.replaceChildren();
        conditionGroupsEditor.replaceChildren();
        perUnitFields.classList.add('hidden');
        addRuleHeading.textContent = 'Add a rule';
        addRuleSubmit.textContent = 'Add rule';
        cancelEditRuleButton.style.display = 'none';
    }

    cancelEditRuleButton.addEventListener('click', cancelEditRule);

    // --- Effect type / per-unit tiers -------------------------------------

    function populateEffectOptionSelect() {
        effectOptionSelect.replaceChildren();

        serviceOptions
            .filter((option) => option.type === 'NUMBER')
            .forEach((option) => {
                const el = document.createElement('option');
                el.value = option.uuid;
                el.textContent = option.name;
                effectOptionSelect.appendChild(el);
            });
    }

    function addTierRow() {
        const node = tierRowTemplate.content.cloneNode(true);
        const row = node.querySelector('[data-tier-row]');

        row.querySelector('[data-remove-tier]').addEventListener('click', () => row.remove());

        tiersEditor.appendChild(node);
    }

    addTierButton.addEventListener('click', addTierRow);

    effectTypeSelect.addEventListener('change', () => {
        const isPerUnit = effectTypeSelect.value === 'ADD_PER_UNIT';

        effectAmountField.style.display = AMOUNTLESS_EFFECT_TYPES.includes(effectTypeSelect.value) ? 'none' : 'block';
        perUnitFields.classList.toggle('hidden', !isPerUnit);

        if (isPerUnit && tiersEditor.children.length === 0) {
            addTierRow();
        }
    });

    function readTiers() {
        return Array.from(tiersEditor.querySelectorAll('[data-tier-row]')).map((row, index) => {
            const toRaw = row.querySelector('[data-tier-to]').value.trim();

            return {
                tier_order: index,
                from_unit: row.querySelector('[data-tier-from]').value,
                to_unit: toRaw === '' ? null : toRaw,
                charge_unit_size: row.querySelector('[data-tier-unit-size]').value || '1',
                rate_amount: row.querySelector('[data-tier-rate]').value,
                tier_pricing_mode: row.querySelector('[data-tier-mode]').value,
            };
        });
    }

    // --- Conditions ---------------------------------------------------------

    function operatorsFor(subjectType) {
        if (subjectType === 'OPTION_CHOICE') return CHOICE_OPERATORS;
        if (subjectType === 'OPTION_BOOLEAN_VALUE') return BOOLEAN_OPERATORS;

        return NUMERIC_OPERATORS;
    }

    function optionsFor(subjectType) {
        if (subjectType === 'OPTION_CHOICE') return serviceOptions.filter((o) => o.type === 'SINGLE_SELECT' || o.type === 'MULTI_SELECT');
        if (subjectType === 'OPTION_NUMERIC_VALUE') return serviceOptions.filter((o) => o.type === 'NUMBER');
        if (subjectType === 'OPTION_BOOLEAN_VALUE') return serviceOptions.filter((o) => o.type === 'BOOLEAN');

        return [];
    }

    function refreshConditionRow(row) {
        const subjectType = row.querySelector('[data-condition-subject]').value;
        const optionField = row.querySelector('[data-condition-option-field]');
        const contextField = row.querySelector('[data-condition-context-field]');
        const operatorSelect = row.querySelector('[data-condition-operator]');
        const optionSelect = row.querySelector('[data-condition-option]');
        const valueChoiceSelect = row.querySelector('[data-condition-value-choice]');
        const valueBooleanSelect = row.querySelector('[data-condition-value-boolean]');
        const valueNumberInput = row.querySelector('[data-condition-value-number]');
        const valueHighField = row.querySelector('[data-condition-value-high-field]');

        const needsOption = ['OPTION_CHOICE', 'OPTION_NUMERIC_VALUE', 'OPTION_BOOLEAN_VALUE'].includes(subjectType);
        optionField.classList.toggle('hidden', !needsOption);
        contextField.classList.toggle('hidden', subjectType !== 'CONTEXT_ATTRIBUTE');

        if (needsOption) {
            optionSelect.replaceChildren(...optionsFor(subjectType).map((option) => {
                const el = document.createElement('option');
                el.value = option.uuid;
                el.textContent = option.name;
                el.dataset.optionType = option.type;

                return el;
            }));
        }

        operatorSelect.replaceChildren(...operatorsFor(subjectType).map(([value, label]) => {
            const el = document.createElement('option');
            el.value = value;
            el.textContent = label;

            return el;
        }));

        valueChoiceSelect.classList.add('hidden');
        valueBooleanSelect.classList.add('hidden');
        valueNumberInput.classList.add('hidden');

        if (subjectType === 'OPTION_CHOICE') {
            const selectedOption = serviceOptions.find((o) => o.uuid === optionSelect.value);
            valueChoiceSelect.replaceChildren(...((selectedOption?.choices ?? []).map((choice) => {
                const el = document.createElement('option');
                el.value = choice.uuid;
                el.textContent = choice.name;

                return el;
            })));
            valueChoiceSelect.classList.remove('hidden');
        } else if (subjectType === 'OPTION_BOOLEAN_VALUE') {
            valueBooleanSelect.classList.remove('hidden');
        } else {
            valueNumberInput.classList.remove('hidden');
        }

        valueHighField.classList.toggle('hidden', operatorSelect.value !== 'BETWEEN');
    }

    function addConditionRow(conditionsContainer) {
        const node = conditionRowTemplate.content.cloneNode(true);
        const row = node.querySelector('[data-condition-row]');

        row.querySelector('[data-condition-subject]').addEventListener('change', () => refreshConditionRow(row));
        row.querySelector('[data-condition-option]').addEventListener('change', () => refreshConditionRow(row));
        row.querySelector('[data-condition-operator]').addEventListener('change', () => refreshConditionRow(row));
        row.querySelector('[data-remove-condition]').addEventListener('click', () => row.remove());

        conditionsContainer.appendChild(node);
        refreshConditionRow(conditionsContainer.lastElementChild);
    }

    function addConditionGroup() {
        const node = conditionGroupTemplate.content.cloneNode(true);
        const group = node.querySelector('[data-condition-group]');
        const conditionsContainer = group.querySelector('[data-conditions]');

        group.querySelector('[data-add-condition]').addEventListener('click', () => addConditionRow(conditionsContainer));
        group.querySelector('[data-remove-condition-group]').addEventListener('click', () => group.remove());

        conditionGroupsEditor.appendChild(node);
        addConditionRow(conditionGroupsEditor.lastElementChild.querySelector('[data-conditions]'));
    }

    addConditionGroupButton.addEventListener('click', addConditionGroup);

    function readConditionGroups() {
        return Array.from(conditionGroupsEditor.querySelectorAll('[data-condition-group]')).map((group) => ({
            conditions: Array.from(group.querySelectorAll('[data-condition-row]')).map((row) => {
                const subjectType = row.querySelector('[data-condition-subject]').value;
                const operator = row.querySelector('[data-condition-operator]').value;
                const condition = { subject_type: subjectType, operator };

                if (['OPTION_CHOICE', 'OPTION_NUMERIC_VALUE', 'OPTION_BOOLEAN_VALUE'].includes(subjectType)) {
                    condition.service_option_id = row.querySelector('[data-condition-option]').value;
                }

                if (subjectType === 'CONTEXT_ATTRIBUTE') {
                    condition.context_attribute_code = row.querySelector('[data-condition-context]').value.trim();
                }

                if (subjectType === 'OPTION_CHOICE') {
                    condition.value_choice_id = row.querySelector('[data-condition-value-choice]').value;
                } else if (subjectType === 'OPTION_BOOLEAN_VALUE') {
                    condition.value_boolean = row.querySelector('[data-condition-value-boolean]').value === 'true';
                } else {
                    condition.value_number = row.querySelector('[data-condition-value-number]').value;

                    if (operator === 'BETWEEN') {
                        condition.value_number_high = row.querySelector('[data-condition-value-number-high]').value;
                    }
                }

                return condition;
            }),
        }));
    }

    // --- Add rule submit -----------------------------------------------------

    addRuleForm.addEventListener('submit', async (event) => {
        event.preventDefault();
        addRuleError.classList.add('hidden');
        addRuleSubmit.disabled = true;

        const effectType = effectTypeSelect.value;
        const amountRaw = addRuleForm.elements.namedItem('effect_amount').value.trim();

        const body = {
            rule_code: addRuleForm.elements.namedItem('rule_code').value.trim(),
            label: addRuleForm.elements.namedItem('label').value.trim(),
            priority: Number(addRuleForm.elements.namedItem('priority').value),
            effect_type: effectType,
            stop_processing: effectType === 'QUOTE_REQUIRED' ? true : addRuleForm.elements.namedItem('stop_processing').checked,
        };

        if (!AMOUNTLESS_EFFECT_TYPES.includes(effectType) && amountRaw) {
            body.effect_amount = amountRaw;
        }

        if (effectType === 'ADD_PER_UNIT') {
            body.effect_subject_service_option_id = effectOptionSelect.value;
            body.tier_calculation_mode = tierCalculationSelect.value;
            body.tiers = readTiers();
        }

        const conditionGroups = readConditionGroups().filter((group) => group.conditions.length > 0);

        if (conditionGroups.length > 0) {
            body.condition_groups = conditionGroups;
        }

        const isEditing = editingRuleUuid !== null;
        const url = isEditing
            ? `/api/v1/admin/pricing-schemes/${encodeURIComponent(schemeUuid)}/rules/${encodeURIComponent(editingRuleUuid)}`
            : `/api/v1/admin/pricing-schemes/${encodeURIComponent(schemeUuid)}/rules`;

        try {
            await request(url, { method: isEditing ? 'PUT' : 'POST', body });

            cancelEditRule();
            await loadScheme();
        } catch (error) {
            addRuleError.textContent = error instanceof ApiError ? error.message : `Unable to ${isEditing ? 'save' : 'add'} this rule.`;
            addRuleError.classList.remove('hidden');
        } finally {
            addRuleSubmit.disabled = false;
        }
    });

    publishForm.addEventListener('submit', async (event) => {
        event.preventDefault();
        publishError.classList.add('hidden');
        publishSubmit.disabled = true;

        const effectiveTo = publishForm.elements.namedItem('effective_to').value;

        try {
            await request(`/api/v1/admin/pricing-schemes/${encodeURIComponent(schemeUuid)}/publish`, {
                method: 'POST',
                body: {
                    effective_from: publishForm.elements.namedItem('effective_from').value,
                    effective_to: effectiveTo || null,
                },
            });

            await loadScheme();
        } catch (error) {
            publishError.textContent = error instanceof ApiError ? error.message : 'Unable to publish this pricing scheme.';
            publishError.classList.remove('hidden');
        } finally {
            publishSubmit.disabled = false;
        }
    });

    retireSubmit.addEventListener('click', async () => {
        if (!window.confirm('Retire this pricing scheme version? It will stop being selected for future pricing. Its rules and history stay fully readable - nothing is deleted.')) {
            return;
        }

        retireError.classList.add('hidden');
        retireSubmit.disabled = true;

        try {
            await request(`/api/v1/admin/pricing-schemes/${encodeURIComponent(schemeUuid)}/retire`, {
                method: 'POST',
            });

            await loadScheme();
        } catch (error) {
            retireError.textContent = error instanceof ApiError ? error.message : 'Unable to retire this pricing scheme version.';
            retireError.classList.remove('hidden');
        } finally {
            retireSubmit.disabled = false;
        }
    });

    // --- Pricing preview ------------------------------------------------------

    function renderPreviewOptions() {
        previewOptionsEl.replaceChildren();

        serviceOptions.forEach((option) => {
            const node = previewOptionTemplate.content.cloneNode(true);
            const row = node.querySelector('[data-preview-option-row]');
            row.dataset.optionUuid = option.uuid;
            row.dataset.optionType = option.type;

            node.querySelector('[data-preview-option-label]').textContent = `${option.name} (${option.type})`;

            if (option.type === 'NUMBER') {
                node.querySelector('[data-preview-numeric]').classList.remove('hidden');
            } else if (option.type === 'BOOLEAN') {
                node.querySelector('[data-preview-boolean]').classList.remove('hidden');
            } else if (option.type === 'TEXT') {
                node.querySelector('[data-preview-text]').classList.remove('hidden');
            } else if (option.type === 'SINGLE_SELECT' || option.type === 'MULTI_SELECT') {
                const select = node.querySelector('[data-preview-choice]');
                select.multiple = option.type === 'MULTI_SELECT';
                select.replaceChildren(...(option.choices ?? []).map((choice) => {
                    const el = document.createElement('option');
                    el.value = choice.uuid;
                    el.textContent = choice.name;

                    return el;
                }));
                select.classList.remove('hidden');
            }

            previewOptionsEl.appendChild(node);
        });
    }

    function readPreviewOptions() {
        return Array.from(previewOptionsEl.querySelectorAll('[data-preview-option-row]'))
            .map((row) => {
                const type = row.dataset.optionType;
                const optionUuid = row.dataset.optionUuid;

                if (type === 'NUMBER') {
                    const value = row.querySelector('[data-preview-numeric]').value.trim();

                    return value === '' ? null : { option_uuid: optionUuid, numeric_value: value };
                }

                if (type === 'BOOLEAN') {
                    const value = row.querySelector('[data-preview-boolean]').value;

                    return value === '' ? null : { option_uuid: optionUuid, boolean_value: value === 'true' };
                }

                if (type === 'TEXT') {
                    const value = row.querySelector('[data-preview-text]').value.trim();

                    return value === '' ? null : { option_uuid: optionUuid, text_value: value };
                }

                const select = row.querySelector('[data-preview-choice]');
                const choiceUuids = Array.from(select.selectedOptions).map((o) => o.value);

                return choiceUuids.length === 0 ? null : { option_uuid: optionUuid, choice_uuids: choiceUuids };
            })
            .filter((entry) => entry !== null);
    }

    function renderPreviewResult(pricing) {
        previewResultEl.classList.remove('hidden');
        previewResultEl.replaceChildren();

        const status = document.createElement('p');
        status.className = 'font-semibold text-slate-900';
        status.textContent = `Status: ${pricing.pricing_status}`;
        previewResultEl.appendChild(status);

        if (pricing.pricing_status !== 'PRICED') {
            if (pricing.required_context.length > 0) {
                const missing = document.createElement('p');
                missing.className = 'mt-1 text-slate-500';
                missing.textContent = `Missing context: ${pricing.required_context.join(', ')}`;
                previewResultEl.appendChild(missing);
            }

            return;
        }

        const list = document.createElement('ul');
        list.className = 'mt-2 space-y-1 text-slate-600';

        pricing.adjustments.forEach((adjustment) => {
            const item = document.createElement('li');
            item.textContent = `${adjustment.label}: ${effectTypeLabel(adjustment.effect_type)}${adjustment.amount_or_factor !== null ? ` (${adjustment.amount_or_factor})` : ''} → running total ${adjustment.running_total_after}`;
            list.appendChild(item);
        });

        previewResultEl.appendChild(list);

        const total = document.createElement('p');
        total.className = 'mt-3 text-base font-semibold text-slate-950';
        total.textContent = `Unit total: ${pricing.unit_total} ${pricing.currency} · Line total (×${pricing.quantity}): ${pricing.line_total} ${pricing.currency}`;
        previewResultEl.appendChild(total);
    }

    previewForm.addEventListener('submit', async (event) => {
        event.preventDefault();
        previewError.classList.add('hidden');
        previewResultEl.classList.add('hidden');
        previewSubmit.disabled = true;

        try {
            const response = await request(`/api/v1/admin/pricing-schemes/${encodeURIComponent(schemeUuid)}/preview`, {
                method: 'POST',
                body: {
                    quantity: Number(previewForm.elements.namedItem('quantity').value || 1),
                    options: readPreviewOptions(),
                },
            });

            renderPreviewResult(response.data.pricing);
        } catch (error) {
            previewError.textContent = error instanceof ApiError ? error.message : 'Unable to compute a pricing preview.';
            previewError.classList.remove('hidden');
        } finally {
            previewSubmit.disabled = false;
        }
    });

    adminAuthReady().then((ready) => {
        if (ready) {
            loadScheme();
        }
    });
}
