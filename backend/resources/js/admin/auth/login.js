const loginForm = document.querySelector('[data-admin-login-form]');

if (loginForm) {
    const submitButton = loginForm.querySelector('[data-submit]');
    const errorBox = document.querySelector('[data-login-error]');

    loginForm.addEventListener('submit', async (event) => {
        event.preventDefault();

        if (errorBox) {
            errorBox.textContent = '';
            errorBox.classList.add('hidden');
        }

        submitButton?.setAttribute('disabled', 'disabled');

        if (submitButton) {
            submitButton.textContent = 'Signing in...';
        }

        try {
            const formData = new FormData(loginForm);

            const response = await fetch('/api/v1/admin/auth/login', {
                method: 'POST',
                headers: {
                    'Accept': 'application/json',
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    phone_number: formData.get('phone_number'),
                    password: formData.get('password'),
                }),
            });

            const payload = await response.json();

            if (!response.ok || payload.success !== true) {
                throw new Error(
                    payload.message || 'Unable to sign in.'
                );
            }

            /*
             * B1.2 will connect these exact backend states to the
             * browser WebAuthn ceremony:
             *
             * MFA_REQUIRED
             * MFA_ENROLLMENT_REQUIRED
             *
             * Do not create a fake session or bypass MFA here.
             */
            window.dispatchEvent(new CustomEvent('blue:admin-login-stage-one', {
                detail: payload,
            }));

            console.info('Admin password stage completed.', payload.data?.state);
        } catch (error) {
            if (errorBox) {
                errorBox.textContent =
                    error instanceof Error
                        ? error.message
                        : 'Unable to sign in.';

                errorBox.classList.remove('hidden');
            }
        } finally {
            submitButton?.removeAttribute('disabled');

            if (submitButton) {
                submitButton.textContent = 'Sign in';
            }
        }
    });
}
