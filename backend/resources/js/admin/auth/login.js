/**
 * Admin login (BLUE V1 Phase B1.2) - drives the full two-stage flow end to
 * end over real WebAuthn:
 *
 *   password -> MFA_ENROLLMENT_REQUIRED -> navigator.credentials.create()
 *            -> POST /mfa/enroll -> MFA_REQUIRED
 *            -> navigator.credentials.get() -> POST /mfa/verify -> session
 *
 *   password -> MFA_REQUIRED -> navigator.credentials.get()
 *            -> POST /mfa/verify -> session
 *
 * No cryptographic verification happens here - the browser only performs
 * the WebAuthn ceremony (lib/webauthn.js); the Laravel backend remains the
 * sole security authority. A session is only ever considered to exist once
 * POST /v1/admin/auth/mfa/verify succeeds - nothing here fabricates one.
 */

import { publicRequest } from '../lib/api-client.js';
import { createCredential, getCredential } from '../lib/webauthn.js';
import { setAccessToken, setRefreshToken } from '../lib/session.js';

const loginForm = document.querySelector('[data-admin-login-form]');

if (loginForm) {
    const submitButton = loginForm.querySelector('[data-submit]');
    const errorBox = document.querySelector('[data-login-error]');
    const statusBox = document.querySelector('[data-login-status]');

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

    const setStatus = (message) => {
        if (statusBox) {
            statusBox.textContent = message || '';
            statusBox.classList.toggle('hidden', !message);
        }
    };

    const setBusy = (busy, label) => {
        if (submitButton) {
            submitButton.disabled = busy;
            submitButton.textContent = label;
        }
    };

    async function completeLogin(stageData) {
        if (stageData.state === 'MFA_ENROLLMENT_REQUIRED') {
            setStatus('Set up your security key - follow your browser/device prompt.');

            const credential = await createCredential(stageData.webauthn);

            const enrolled = await publicRequest('/api/v1/admin/auth/mfa/enroll', {
                method: 'POST',
                body: {
                    login_ticket: stageData.login_ticket,
                    credential,
                },
            });

            // A successful enrollment deliberately never creates a session
            // by itself - it hands back a fresh MFA_REQUIRED challenge for
            // the credential just registered (see AdminMfaEnrollAction).
            return completeLogin(enrolled.data);
        }

        if (stageData.state === 'MFA_REQUIRED') {
            setStatus('Verify with your security key - follow your browser/device prompt.');

            const credential = await getCredential(stageData.webauthn);

            const session = await publicRequest('/api/v1/admin/auth/mfa/verify', {
                method: 'POST',
                body: {
                    login_ticket: stageData.login_ticket,
                    credential,
                },
            });

            setAccessToken(session.data.access_token, session.data.access_token_expires_at);
            setRefreshToken(session.data.refresh_token);

            window.location.assign('/admin');
            return;
        }

        throw new Error('Unexpected login response.');
    }

    loginForm.addEventListener('submit', async (event) => {
        event.preventDefault();
        clearError();
        setBusy(true, 'Signing in...');

        try {
            const formData = new FormData(loginForm);

            const stage1 = await publicRequest('/api/v1/admin/auth/login', {
                method: 'POST',
                body: {
                    phone_number: formData.get('phone_number'),
                    password: formData.get('password'),
                },
            });

            await completeLogin(stage1.data);
        } catch (error) {
            showError(error instanceof Error ? error.message : 'Unable to sign in.');
        } finally {
            setStatus(null);
            setBusy(false, 'Sign in');
        }
    });
}
