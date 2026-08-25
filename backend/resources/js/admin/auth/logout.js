/**
 * Admin logout / logout-all (BLUE V1 Phase B1.2). Reuses the existing
 * shared POST /v1/auth/logout and /v1/auth/logout-all endpoints (see
 * docs/api-contracts/admin-authentication-v1.md "Why logout / logout-all
 * are not new endpoints") - no Admin-specific logout route exists.
 *
 * Local state is always cleared and the browser always returns to
 * /admin/login, even if the API call itself fails (an already-expired
 * session, a network error, ...) - a failed logout call must never leave
 * the Admin UI stuck on a page it can no longer use.
 */

import { request } from '../lib/api-client.js';
import { clearSession } from '../lib/session.js';

const logoutButton = document.querySelector('[data-logout]');
const logoutAllButton = document.querySelector('[data-logout-all]');

async function performLogout(path) {
    try {
        await request(path, { method: 'POST' });
    } catch {
        // See class docblock - local state is cleared regardless.
    } finally {
        clearSession();
        window.location.assign('/admin/login');
    }
}

logoutButton?.addEventListener('click', () => performLogout('/api/v1/auth/logout'));
logoutAllButton?.addEventListener('click', () => performLogout('/api/v1/auth/logout-all'));
