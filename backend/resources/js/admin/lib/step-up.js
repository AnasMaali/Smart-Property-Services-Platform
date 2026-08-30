/**
 * WebAuthn Step-Up ceremony (BLUE V1 Phase B1.2/A2.5).
 *
 * Deliberately modal/click-driven rather than automatic: WebAuthn's
 * navigator.credentials.get() requires a live user gesture ("transient
 * activation") in every major browser, and that activation does not
 * reliably survive the network round trip of the fetch() call that
 * discovered the 428 in the first place. So instead of calling
 * getCredential() immediately, this shows a small modal (see
 * [data-step-up-modal] in resources/views/admin/layouts/app.blade.php) and
 * only starts the real ceremony from the modal's own button click - a
 * fresh, genuine user gesture.
 *
 * performStepUp() is called from lib/api-client.js's request() when a
 * protected route responds 428/STEP_UP_REQUIRED. It resolves `true` once a
 * fresh WebAuthn Step-Up has been verified for the CURRENT session (safe
 * to retry the original operation once), or `false` if the Admin cancels
 * or the ceremony fails (the original operation must not be retried, but
 * the Admin session itself remains fully valid - see
 * App\Http\Middleware\EnsureAdminStepUpIsFresh's docblock: a Step-Up
 * failure is never a logout).
 *
 * Only one Step-Up ceremony runs at a time - a second concurrent caller
 * awaits the same in-flight modal instead of opening a second one.
 */

import { request } from './api-client.js';
import { getCredential } from './webauthn.js';

let activeStepUp = null;

export function performStepUp() {
    if (!activeStepUp) {
        activeStepUp = runStepUpModal().finally(() => {
            activeStepUp = null;
        });
    }

    return activeStepUp;
}

function modalElements() {
    const modal = document.querySelector('[data-step-up-modal]');

    if (!modal) {
        return null;
    }

    return {
        modal,
        verifyButton: modal.querySelector('[data-step-up-verify]'),
        cancelButton: modal.querySelector('[data-step-up-cancel]'),
        errorBox: modal.querySelector('[data-step-up-error]'),
    };
}

function runStepUpModal() {
    return new Promise((resolve) => {
        const elements = modalElements();

        if (!elements) {
            // No Step-Up modal on this page (e.g. the login page can never
            // reach a Step-Up-protected route) - fail closed, never retry.
            resolve(false);
            return;
        }

        const { modal, verifyButton, cancelButton, errorBox } = elements;

        const showError = (message) => {
            if (errorBox) {
                errorBox.textContent = message;
                errorBox.classList.remove('hidden');
            }
        };

        const clearError = () => {
            if (errorBox) {
                errorBox.textContent = '';
                errorBox.classList.add('hidden');
            }
        };

        const finish = (result) => {
            modal.style.display = 'none';
            verifyButton?.removeEventListener('click', onVerify);
            cancelButton?.removeEventListener('click', onCancel);
            resolve(result);
        };

        const onCancel = () => finish(false);

        const onVerify = async () => {
            verifyButton?.setAttribute('disabled', 'disabled');
            clearError();

            try {
                const optionsResponse = await request('/api/v1/admin/auth/step-up/request', { method: 'POST' });
                const credential = await getCredential(optionsResponse.data.webauthn);

                await request('/api/v1/admin/auth/step-up/verify', {
                    method: 'POST',
                    body: {
                        step_up_ticket: optionsResponse.data.step_up_ticket,
                        credential,
                    },
                });

                finish(true);
            } catch (error) {
                showError(error?.message || 'Verification failed. Please try again.');
                verifyButton?.removeAttribute('disabled');
            }
        };

        verifyButton?.addEventListener('click', onVerify);
        cancelButton?.addEventListener('click', onCancel);

        clearError();
        modal.style.display = 'flex';
    });
}
