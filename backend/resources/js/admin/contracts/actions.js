/**
 * Admin Contract mutation modals (BLUE V1 Phase B4). Every mutation goes
 * through the centralized Admin API client against the existing,
 * already-tested endpoints (App\Actions\Admin\Contract\*) - the
 * ContractStatusMachine, capability authorization, WebAuthn Step-Up
 * enforcement, and AdminAuditLogger writes all remain entirely
 * server-side. This module never re-implements any of that; a rejection
 * (403/409/422/428/429) is simply surfaced as a normal error message, and
 * the caller (contracts/show.js) is expected to reload authoritative
 * Contract state after any successful mutation.
 *
 * Cancel is security-sensitive (admin.capability:contracts.cancel ->
 * admin.stepup) but needs NO special handling here at all: lib/api-client.js's
 * request() already detects a 428/STEP_UP_REQUIRED response, runs the real
 * WebAuthn Step-Up ceremony (lib/step-up.js), and retries the exact same
 * cancel request exactly once - this module just calls request() normally,
 * the same way every other mutation does.
 */

import { request, ApiError } from '../lib/api-client.js';

const approveModal = document.querySelector('[data-approve-modal]');
const confirmModal = document.querySelector('[data-confirm-action-modal]');
const serviceRowTemplate = document.querySelector('[data-approve-service-row-template]');

function showError(errorEl, message) {
    errorEl.textContent = message;
    errorEl.classList.remove('hidden');
}

function hideError(errorEl) {
    errorEl.textContent = '';
    errorEl.classList.add('hidden');
}

function errorMessage(error, fallback) {
    return error instanceof ApiError ? error.message : fallback;
}

// -----------------------------------------------------------------
// Service catalog (for the Approve form's service picker) - uses the
// public GET /v1/services?capability=SUBSCRIPTION filter so only
// contract-eligible services appear (server still re-checks on approve).
// Fetched once and cached for the lifetime of the page.
// -----------------------------------------------------------------

let catalogPromise = null;

function loadServiceCatalog() {
    if (!catalogPromise) {
        catalogPromise = (async () => {
            const response = await request('/api/v1/services?capability=SUBSCRIPTION');
            const services = response.data.services || [];

            return services.map((service) => ({
                uuid: service.uuid,
                name: service.name,
                categoryName: service.category?.name || 'Catalog',
            }));
        })();
    }

    return catalogPromise;
}

// -----------------------------------------------------------------
// Approve modal
// -----------------------------------------------------------------

function approveModalElements() {
    if (!approveModal) {
        return null;
    }

    return {
        modal: approveModal,
        error: approveModal.querySelector('[data-approve-modal-error]'),
        form: approveModal.querySelector('[data-approve-modal-form]'),
        servicesContainer: approveModal.querySelector('[data-approve-services]'),
        addServiceButton: approveModal.querySelector('[data-approve-add-service]'),
        cancelButton: approveModal.querySelector('[data-approve-modal-cancel]'),
        submitButton: approveModal.querySelector('[data-approve-modal-submit]'),
    };
}

function addServiceRow(container, catalog, presetUuid) {
    const fragment = serviceRowTemplate.content.cloneNode(true);
    const row = fragment.querySelector('[data-service-row]');
    const select = row.querySelector('[data-role="service_uuid"]');
    const entitlementSelect = row.querySelector('[data-role="entitlement_mode"]');
    const includedVisitsInput = row.querySelector('[data-role="included_visits"]');
    const removeButton = row.querySelector('[data-role="remove"]');

    select.replaceChildren(
        new Option('Select a service…', ''),
        ...catalog.map((service) => new Option(`${service.categoryName} — ${service.name}`, service.uuid)),
    );

    if (presetUuid) {
        select.value = presetUuid;
    }

    const syncVisitsField = () => {
        const isLimited = entitlementSelect.value === 'LIMITED_VISITS';
        includedVisitsInput.style.display = isLimited ? '' : 'none';
        includedVisitsInput.required = isLimited;

        if (!isLimited) {
            includedVisitsInput.value = '';
        }
    };

    entitlementSelect.addEventListener('change', syncVisitsField);
    syncVisitsField();

    removeButton.addEventListener('click', () => row.remove());

    container.appendChild(fragment);
}

function readServiceRows(container) {
    return Array.from(container.querySelectorAll('[data-service-row]')).map((row) => {
        const entitlementMode = row.querySelector('[data-role="entitlement_mode"]').value;
        const includedVisits = row.querySelector('[data-role="included_visits"]').value;

        return {
            service_uuid: row.querySelector('[data-role="service_uuid"]').value,
            entitlement_mode: entitlementMode,
            included_visits: entitlementMode === 'LIMITED_VISITS' && includedVisits ? Number(includedVisits) : null,
        };
    });
}

export function openApproveModal(contract, onMutated) {
    const els = approveModalElements();

    if (!els) {
        return;
    }

    els.form.reset();
    els.servicesContainer.replaceChildren();
    hideError(els.error);
    els.modal.style.display = 'flex';

    const close = () => {
        els.modal.style.display = 'none';
        els.addServiceButton.removeEventListener('click', onAddService);
        els.cancelButton.removeEventListener('click', onCancel);
        els.form.removeEventListener('submit', onSubmit);
    };

    const onCancel = () => close();

    let catalog = [];

    const onAddService = () => addServiceRow(els.servicesContainer, catalog, null);

    async function onSubmit(event) {
        event.preventDefault();
        hideError(els.error);

        const services = readServiceRows(els.servicesContainer);

        if (services.length === 0) {
            showError(els.error, 'Add at least one covered service.');
            return;
        }

        if (services.some((service) => !service.service_uuid)) {
            showError(els.error, 'Choose a service for every row.');
            return;
        }

        const formData = new FormData(els.form);
        const termMonths = formData.get('term_months');
        const quotedAmount = formData.get('quoted_amount');
        const currencyCode = formData.get('currency_code')?.trim();

        const payload = {
            starts_at: formData.get('starts_at'),
            ends_at: formData.get('ends_at'),
            term_months: termMonths ? Number(termMonths) : null,
            services,
            quoted_amount: quotedAmount || null,
            currency_code: currencyCode || null,
            billing_interval: formData.get('billing_interval'),
            recurring_amount: formData.get('recurring_amount'),
            billing_currency_code: formData.get('billing_currency_code')?.trim().toUpperCase(),
            internal_note: formData.get('internal_note')?.trim() || null,
        };

        els.submitButton.disabled = true;

        try {
            await request(`/api/v1/admin/contracts/${encodeURIComponent(contract.uuid)}/approve`, {
                method: 'POST',
                body: payload,
            });

            close();
            await onMutated();
        } catch (error) {
            showError(els.error, errorMessage(error, 'Unable to approve this contract.'));
        } finally {
            els.submitButton.disabled = false;
        }
    }

    els.addServiceButton.addEventListener('click', onAddService);
    els.cancelButton.addEventListener('click', onCancel);
    els.form.addEventListener('submit', onSubmit);

    loadServiceCatalog()
        .then((loadedCatalog) => {
            catalog = loadedCatalog;

            const presetUuids = contract.requested_service_uuids && contract.requested_service_uuids.length > 0
                ? contract.requested_service_uuids
                : [null];

            presetUuids.forEach((uuid) => addServiceRow(els.servicesContainer, catalog, uuid));
        })
        .catch((error) => {
            showError(els.error, errorMessage(error, 'Unable to load the service catalog.'));
        });
}

// -----------------------------------------------------------------
// Generic confirm-action modal (Send for acceptance / Suspend / Cancel)
// -----------------------------------------------------------------

function confirmActionElements() {
    if (!confirmModal) {
        return null;
    }

    return {
        modal: confirmModal,
        title: confirmModal.querySelector('[data-confirm-action-title]'),
        message: confirmModal.querySelector('[data-confirm-action-message]'),
        error: confirmModal.querySelector('[data-confirm-action-error]'),
        reasonField: confirmModal.querySelector('[data-confirm-action-reason-field]'),
        reason: confirmModal.querySelector('[data-confirm-action-reason]'),
        cancelButton: confirmModal.querySelector('[data-confirm-action-cancel]'),
        confirmButton: confirmModal.querySelector('[data-confirm-action-confirm]'),
    };
}

/**
 * @param {{title: string, message: string, confirmLabel: string, showReason?: boolean, onConfirm: (reason: string|null) => Promise<void>}} options
 */
export function openConfirmAction({ title, message, confirmLabel, showReason = true, onConfirm }) {
    const els = confirmActionElements();

    if (!els) {
        return;
    }

    els.title.textContent = title;
    els.message.textContent = message;
    els.confirmButton.textContent = confirmLabel;
    els.reason.value = '';
    els.reasonField.style.display = showReason ? 'block' : 'none';
    hideError(els.error);
    els.modal.style.display = 'flex';

    const onCancel = () => close();

    const close = () => {
        els.modal.style.display = 'none';
        els.cancelButton.removeEventListener('click', onCancel);
        els.confirmButton.removeEventListener('click', onConfirmClick);
    };

    async function onConfirmClick() {
        hideError(els.error);
        els.confirmButton.disabled = true;

        try {
            await onConfirm(showReason ? (els.reason.value.trim() || null) : null);
            close();
        } catch (error) {
            showError(els.error, errorMessage(error, 'Unable to complete this action.'));
        } finally {
            els.confirmButton.disabled = false;
        }
    }

    els.cancelButton.addEventListener('click', onCancel);
    els.confirmButton.addEventListener('click', onConfirmClick);
}
