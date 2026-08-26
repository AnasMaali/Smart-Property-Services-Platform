/**
 * Admin session restore (BLUE V1 Phase B1.2) - runs once on every load of
 * the protected Admin shell ([data-admin-shell], see
 * resources/views/admin/layouts/app.blade.php). The access token lives
 * only in memory (lib/session.js), so a full page reload always loses it -
 * this is what re-establishes it from the sessionStorage refresh token
 * before any protected content is shown:
 *
 *   sessionStorage refresh token
 *   -> POST /v1/admin/auth/refresh (rotates the refresh token)
 *   -> access token kept in memory only
 *   -> GET /v1/admin/me
 *   -> reveal the Admin shell
 *
 * If there is no stored refresh token, or either call fails, local state is
 * cleared and the browser is sent back to /admin/login - the shell content
 * underneath is never revealed in that case (see revealShell()/the initial
 * [data-admin-loading] overlay in the layout).
 *
 * BLUE V1 Phase B13.1 - adminAuthReady(): every other Admin page module
 * (dashboard/index.js, bookings/index.js, etc., all bundled into the same
 * resources/js/admin/app.js entry) is imported alongside this file and
 * would otherwise fire its own first authenticated request immediately at
 * module-load time - before the async restoration above has installed an
 * access token in memory. That raced the very first `request()` call on
 * every direct load/reload/bookmark of any protected /admin/* page into an
 * immediate 401 (the in-memory access token is empty at that instant),
 * which the centralized 401 handler in lib/api-client.js correctly (by its
 * own contract) treated as "session expired" and bounced back to
 * /admin/login - even though the real restore, running concurrently,
 * would have succeeded moments later. adminAuthReady() is the single
 * source of truth every protected page module must await before its first
 * authenticated request: it resolves to `true` once the sequence above has
 * fully completed (access token installed, refresh token rotated in
 * sessionStorage, identity confirmed via GET /v1/admin/me), or `false` if
 * restoration failed or does not apply on this page (a redirect to
 * /admin/login is already in flight in the failure case - callers must
 * simply not proceed, never retry or show an error of their own). The
 * underlying restoreAdminSession() call itself is unchanged: it still
 * starts immediately and unconditionally below, so restoration begins
 * without waiting for any page module to ask for it.
 */

import { publicRequest, request } from '../lib/api-client.js';
import { getRefreshToken, setAccessToken, setRefreshToken, clearSession } from '../lib/session.js';

const shell = document.querySelector('[data-admin-shell]');

const restorePromise = shell ? restoreAdminSession() : Promise.resolve(false);

export function adminAuthReady() {
    return restorePromise;
}

function redirectToLogin() {
    clearSession();
    window.location.assign('/admin/login');
}

function revealShell(identity) {
    const loading = document.querySelector('[data-admin-loading]');
    const shellEl = document.querySelector('[data-admin-shell]');

    // Inline style.display, not a Tailwind class, toggles visibility here:
    // both elements also carry a `flex` layout utility, and `hidden`/`flex`
    // have equal CSS specificity - whichever is defined LATER in the
    // generated stylesheet would silently win regardless of class order,
    // so a classList-based toggle would be unreliable for these two.
    if (loading) {
        loading.style.display = 'none';
    }

    if (shellEl) {
        shellEl.style.removeProperty('display');
    }

    const nameEl = document.querySelector('[data-admin-name]');
    const roleEl = document.querySelector('[data-admin-role]');
    const primaryRole = (identity.roles || [])[0] || null;

    if (nameEl) {
        nameEl.textContent = identity.full_name || identity.phone_number || 'Administrator';
    }

    if (roleEl) {
        roleEl.textContent = primaryRole ? primaryRole.replace('_', ' ') : 'Secure session';
    }
}

async function restoreAdminSession() {
    const refreshToken = getRefreshToken();

    if (!refreshToken) {
        redirectToLogin();
        return false;
    }

    try {
        const refreshed = await publicRequest('/api/v1/admin/auth/refresh', {
            method: 'POST',
            body: { refresh_token: refreshToken },
        });

        setAccessToken(refreshed.data.access_token, refreshed.data.access_token_expires_at);
        setRefreshToken(refreshed.data.refresh_token);

        const me = await request('/api/v1/admin/me');

        revealShell(me.data);

        return true;
    } catch {
        redirectToLogin();
        return false;
    }
}
