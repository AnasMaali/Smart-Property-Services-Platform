/**
 * Shared Activate/Deactivate confirm modal (BLUE V1 Phase B8), used
 * identically by both categories-show.js and services-show.js - each
 * page's Blade view carries its own `[data-toggle-active-modal]` markup
 * (see resources/views/admin/services/categories-show.blade.php and
 * services-show.blade.php), so this only ever queries the one instance
 * that exists on whichever page is currently loaded. Mirrors
 * resources/js/admin/contracts/actions.js's openConfirmAction() pattern,
 * simplified since activate/deactivate never takes a reason.
 */

import { ApiError } from '../lib/api-client.js';

export function openToggleActiveModal({ title, message, confirmLabel, onConfirm }) {
    const modal = document.querySelector('[data-toggle-active-modal]');

    if (!modal) {
        return;
    }

    const titleEl = modal.querySelector('[data-toggle-active-title]');
    const messageEl = modal.querySelector('[data-toggle-active-message]');
    const errorEl = modal.querySelector('[data-toggle-active-error]');
    const cancelButton = modal.querySelector('[data-toggle-active-cancel]');
    const confirmButton = modal.querySelector('[data-toggle-active-confirm]');

    titleEl.textContent = title;
    messageEl.textContent = message;
    confirmButton.textContent = confirmLabel;
    errorEl.textContent = '';
    errorEl.classList.add('hidden');
    modal.style.display = 'flex';

    const close = () => {
        modal.style.display = 'none';
        cancelButton.removeEventListener('click', onCancel);
        confirmButton.removeEventListener('click', onConfirmClick);
    };

    const onCancel = () => close();

    async function onConfirmClick() {
        errorEl.textContent = '';
        errorEl.classList.add('hidden');
        confirmButton.disabled = true;

        try {
            await onConfirm();
            close();
        } catch (error) {
            errorEl.textContent = error instanceof ApiError ? error.message : 'Unable to complete this action.';
            errorEl.classList.remove('hidden');
        } finally {
            confirmButton.disabled = false;
        }
    }

    cancelButton.addEventListener('click', onCancel);
    confirmButton.addEventListener('click', onConfirmClick);
}
