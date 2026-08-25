/**
 * Admin Pricing Scheme Version detail (BLUE V1 Phase B9). Reuses the
 * centralized Admin API client against the existing GET /v1/admin/
 * pricing-schemes/{scheme} endpoint (App\Actions\Admin\Pricing\
 * AdminGetPricingSchemeAction / App\Support\Admin\
 * AdminPricingSchemePresenter) - every field rendered below comes directly
 * from that response.
 *
 * Mutations (add rule / delete rule / publish) only ever act on a DRAFT
 * scheme version - the server is the authoritative enforcer of that (see
 * AdminCreatePricingRuleAction/AdminDeletePricingRuleAction/
 * AdminPublishPricingSchemeAction), but this page also hides the relevant
 * forms/buttons for a non-DRAFT version as a UX hint. Publishing reuses
 * the existing WebAuthn Step-Up flow transparently through
 * lib/api-client.js's request() - no special-case code is needed here.
 * Every mutation reloads the authoritative server response afterward
 * rather than patching local state.
 */

import { request, ApiError } from '../lib/api-client.js';
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
    const addRuleCard = page.querySelector('[data-add-rule-card]');
    const addRuleForm = page.querySelector('[data-add-rule-form]');
    const addRuleSubmit = addRuleForm.querySelector('[data-add-rule-submit]');
    const addRuleError = addRuleForm.querySelector('[data-add-rule-error]');
    const effectTypeSelect = addRuleForm.querySelector('[data-effect-type-select]');
    const effectAmountField = addRuleForm.querySelector('[data-effect-amount-field]');
    const rulesEl = page.querySelector('[data-rules]');
    const rulesEmptyEl = page.querySelector('[data-rules-empty]');
    const ruleCardTemplate = document.querySelector('[data-rule-card-template]');

    const AMOUNTLESS_EFFECT_TYPES = ['ADD_PER_UNIT', 'QUOTE_REQUIRED'];

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
            : effectTypeLabel(rule.effect_type);
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
        }

        return node;
    }

    async function loadScheme() {
        setState('loading');

        try {
            const response = await request(`/api/v1/admin/pricing-schemes/${encodeURIComponent(schemeUuid)}`);
            renderScheme(response.data.pricing_scheme);
            setState('ready');
        } catch (error) {
            const message = error instanceof ApiError ? error.message : 'Unable to load this pricing scheme.';
            showError(message);
        }
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

    effectTypeSelect.addEventListener('change', () => {
        effectAmountField.style.display = AMOUNTLESS_EFFECT_TYPES.includes(effectTypeSelect.value) ? 'none' : 'block';
    });

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

        try {
            await request(`/api/v1/admin/pricing-schemes/${encodeURIComponent(schemeUuid)}/rules`, {
                method: 'POST',
                body,
            });

            addRuleForm.reset();
            await loadScheme();
        } catch (error) {
            addRuleError.textContent = error instanceof ApiError ? error.message : 'Unable to add this rule.';
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

    loadScheme();
}
