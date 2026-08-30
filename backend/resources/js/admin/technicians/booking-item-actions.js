/**
 * Booking Item technician operations (BLUE V1 Phase B3), integrated into
 * the Admin Booking detail page (resources/js/admin/bookings/show.js).
 * Every mutation goes through the centralized Admin API client against the
 * existing, already-tested endpoints - eligibility, specialization
 * matching, double-booking detection, and the Booking Item status machine
 * are never re-implemented here; the backend outcome (success or a normal
 * ApiError) is the only source of truth.
 *
 * attachTechnicianActions() decides ONLY which buttons make sense to show
 * for the CURRENT known status/assignment, purely for UX - it never assumes
 * an action will succeed. Every actual call still goes through the real
 * Action, and a rejection (409/422/...) is simply shown as a normal error;
 * the caller is expected to reload authoritative Booking state
 * (onMutated()) after any successful mutation, never patch local state.
 */

import { request, ApiError } from '../lib/api-client.js';

const technicianModal = document.querySelector('[data-technician-modal]');
const confirmModal = document.querySelector('[data-confirm-action-modal]');

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
// Assign / Reassign technician modal
// -----------------------------------------------------------------

function technicianModalElements() {
    if (!technicianModal) {
        return null;
    }

    return {
        modal: technicianModal,
        service: technicianModal.querySelector('[data-technician-modal-service]'),
        title: technicianModal.querySelector('[data-technician-modal-title]'),
        error: technicianModal.querySelector('[data-technician-modal-error]'),
        loading: technicianModal.querySelector('[data-technician-modal-loading]'),
        empty: technicianModal.querySelector('[data-technician-modal-empty]'),
        form: technicianModal.querySelector('[data-technician-modal-form]'),
        candidates: technicianModal.querySelector('[data-technician-modal-candidates]'),
        releaseReasonField: technicianModal.querySelector('[data-technician-modal-release-reason-field]'),
        cancelButton: technicianModal.querySelector('[data-technician-modal-cancel]'),
        submitButton: technicianModal.querySelector('[data-technician-modal-submit]'),
    };
}

function renderCandidates(container, candidates) {
    container.replaceChildren(...candidates.map((candidate) => {
        const label = document.createElement('label');
        label.className = 'flex cursor-pointer items-center justify-between gap-3 rounded-lg border '
            + 'border-slate-200 px-3 py-2 text-sm hover:bg-slate-50 has-[:checked]:border-blue-400 '
            + 'has-[:checked]:bg-blue-50';

        const left = document.createElement('div');
        left.className = 'flex items-center gap-2.5';

        const radio = document.createElement('input');
        radio.type = 'radio';
        radio.name = 'technician_uuid';
        radio.value = candidate.uuid;
        radio.className = 'h-4 w-4';

        const textWrap = document.createElement('div');

        const name = document.createElement('div');
        name.className = 'font-medium text-slate-900';
        name.textContent = candidate.full_name;

        const meta = document.createElement('div');
        meta.className = 'text-xs text-slate-500';
        meta.textContent = [candidate.phone_number, candidate.specializations.map((s) => s.name).join(', ')]
            .filter(Boolean)
            .join(' · ');

        textWrap.append(name, meta);
        left.append(radio, textWrap);
        label.appendChild(left);

        if (candidate.is_double_booked) {
            const warning = document.createElement('span');
            warning.className = 'shrink-0 rounded-full bg-amber-50 px-2 py-0.5 text-xs font-medium text-amber-700';
            warning.textContent = 'Overlaps another job';
            label.appendChild(warning);
        }

        return label;
    }));
}

function openTechnicianModal(item, onMutated) {
    const els = technicianModalElements();

    if (!els) {
        return;
    }

    const isReassign = Boolean(item.active_assignment);

    els.service.textContent = item.service.name;
    els.title.textContent = isReassign ? 'Reassign technician' : 'Assign technician';
    els.submitButton.textContent = isReassign ? 'Reassign' : 'Assign';
    els.releaseReasonField.style.display = isReassign ? 'block' : 'none';
    els.form.reset();
    els.form.style.display = 'none';
    els.empty.style.display = 'none';
    els.loading.style.display = 'block';
    hideError(els.error);
    els.modal.style.display = 'flex';

    const onCancel = () => close();

    const close = () => {
        els.modal.style.display = 'none';
        els.cancelButton.removeEventListener('click', onCancel);
        els.form.removeEventListener('submit', onSubmit);
    };

    async function onSubmit(event) {
        event.preventDefault();
        hideError(els.error);

        const selected = els.candidates.querySelector('input[name="technician_uuid"]:checked');

        if (!selected) {
            showError(els.error, 'Choose a technician to continue.');
            return;
        }

        const formData = new FormData(els.form);
        const payload = {
            technician_uuid: selected.value,
            internal_note: formData.get('internal_note')?.trim() || null,
        };

        if (isReassign) {
            const releaseReason = formData.get('release_reason')?.trim();

            if (!releaseReason) {
                showError(els.error, 'A reason is required to reassign this Booking Item.');
                return;
            }

            payload.release_reason = releaseReason;
        }

        els.submitButton.disabled = true;

        try {
            const path = isReassign
                ? `/api/v1/admin/booking-items/${encodeURIComponent(item.uuid)}/reassign-technician`
                : `/api/v1/admin/booking-items/${encodeURIComponent(item.uuid)}/assign-technician`;

            await request(path, { method: 'POST', body: payload });

            close();
            await onMutated();
        } catch (error) {
            showError(els.error, errorMessage(error, 'Unable to complete this action.'));
        } finally {
            els.submitButton.disabled = false;
        }
    }

    els.cancelButton.addEventListener('click', onCancel);
    els.form.addEventListener('submit', onSubmit);

    request(`/api/v1/admin/booking-items/${encodeURIComponent(item.uuid)}/technician-candidates`)
        .then((response) => {
            els.loading.style.display = 'none';
            const candidates = response.data.candidates || [];

            if (candidates.length === 0) {
                els.empty.textContent = response.data.requirement_configured
                    ? 'No eligible technicians are currently available for this service.'
                    : 'No specialization is configured for this service yet, so no technician can be assigned.';
                els.empty.style.display = 'block';
                return;
            }

            renderCandidates(els.candidates, candidates);
            els.form.style.display = 'block';
        })
        .catch((error) => {
            els.loading.style.display = 'none';
            showError(els.error, errorMessage(error, 'Unable to load technician candidates.'));
        });
}

// -----------------------------------------------------------------
// Generic confirm-action modal (Start work / Complete work). Exported so
// bookings/show.js can reuse it for the Booking-level "Cancel booking"
// operation (BLUE V1 Phase B16) instead of building a second modal.
// -----------------------------------------------------------------

function confirmActionElements() {
    if (!confirmModal) {
        return null;
    }

    return {
        modal: confirmModal,
        title: confirmModal.querySelector('[data-confirm-action-title]'),
        message: confirmModal.querySelector('[data-confirm-action-message]'),
        details: confirmModal.querySelector('[data-confirm-action-details]'),
        error: confirmModal.querySelector('[data-confirm-action-error]'),
        reason: confirmModal.querySelector('[data-confirm-action-reason]'),
        reasonLabel: confirmModal.querySelector('[data-confirm-action-reason-label]'),
        cancelButton: confirmModal.querySelector('[data-confirm-action-cancel]'),
        confirmButton: confirmModal.querySelector('[data-confirm-action-confirm]'),
    };
}

/**
 * $detailsNode (optional) is an already-built DOM node - e.g. the
 * server-authoritative cancellation/refund preview bookings/show.js
 * renders from GET /v1/admin/bookings/{booking}/cancellation-preview -
 * shown above the reason field. This modal never builds that content
 * itself; it only mounts whatever node the caller hands it, or hides the
 * slot entirely when none is given (Start work / Complete work above).
 */
export function openConfirmAction({ title, message, confirmLabel, detailsNode, reasonLabel, onConfirm }) {
    const els = confirmActionElements();

    if (!els) {
        return;
    }

    els.title.textContent = title;
    els.message.textContent = message;
    els.confirmButton.textContent = confirmLabel;
    els.reason.value = '';

    if (els.reasonLabel) {
        els.reasonLabel.textContent = reasonLabel || 'Reason (optional)';
    }

    hideError(els.error);

    if (els.details) {
        els.details.replaceChildren();

        if (detailsNode) {
            els.details.appendChild(detailsNode);
            els.details.style.display = 'block';
        } else {
            els.details.style.display = 'none';
        }
    }

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
            await onConfirm(els.reason.value.trim() || null);
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

// -----------------------------------------------------------------
// Public entry point
// -----------------------------------------------------------------

/**
 * Renders the appropriate technician action buttons for one Booking Item
 * into $container (an empty element from the item template) and wires them
 * up. $onMutated is called (and awaited) after any successful mutation - it
 * is expected to reload the Booking from GET /v1/admin/bookings/{booking}
 * (see bookings/show.js), never to patch local state.
 */
export function attachTechnicianActions(container, item, onMutated) {
    container.replaceChildren();

    const isTerminal = item.status === 'COMPLETED' || item.status === 'CANCELLED';

    if (isTerminal) {
        return;
    }

    const manageButton = document.createElement('button');
    manageButton.type = 'button';
    manageButton.className = 'rounded-lg border border-slate-300 bg-white px-3 py-1.5 '
        + 'text-xs font-semibold text-slate-700 hover:bg-slate-50';
    manageButton.textContent = item.active_assignment ? 'Reassign technician' : 'Assign technician';
    manageButton.addEventListener('click', () => openTechnicianModal(item, onMutated));
    container.appendChild(manageButton);

    if (item.active_assignment && item.status === 'ASSIGNED') {
        const startButton = document.createElement('button');
        startButton.type = 'button';
        startButton.className = 'rounded-lg bg-slate-950 px-3 py-1.5 text-xs font-semibold '
            + 'text-white hover:bg-slate-800';
        startButton.textContent = 'Start work';
        startButton.addEventListener('click', () => {
            openConfirmAction({
                title: 'Start work',
                message: `Mark work as started for "${item.service.name}"?`,
                confirmLabel: 'Start work',
                onConfirm: async (reason) => {
                    await request(`/api/v1/admin/booking-items/${encodeURIComponent(item.uuid)}/start-work`, {
                        method: 'POST',
                        body: { technician_uuid: item.active_assignment.technician.uuid, reason },
                    });
                    await onMutated();
                },
            });
        });
        container.appendChild(startButton);
    }

    if (item.active_assignment && item.status === 'IN_PROGRESS') {
        const completeButton = document.createElement('button');
        completeButton.type = 'button';
        completeButton.className = 'rounded-lg bg-emerald-600 px-3 py-1.5 text-xs font-semibold '
            + 'text-white hover:bg-emerald-700';
        completeButton.textContent = 'Complete work';
        completeButton.addEventListener('click', () => {
            openConfirmAction({
                title: 'Complete work',
                message: `Mark work as completed for "${item.service.name}"?`,
                confirmLabel: 'Complete work',
                onConfirm: async (reason) => {
                    await request(`/api/v1/admin/booking-items/${encodeURIComponent(item.uuid)}/complete-work`, {
                        method: 'POST',
                        body: { technician_uuid: item.active_assignment.technician.uuid, reason },
                    });
                    await onMutated();
                },
            });
        });
        container.appendChild(completeButton);
    }
}
