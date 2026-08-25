/**
 * Centralized Admin API client (BLUE V1 Phase B1.2). Every authenticated
 * Admin API call in this frontend goes through request() below - no page
 * script duplicates fetch/header/error-handling logic of its own.
 *
 * publicRequest() is the unauthenticated sibling for the pre-session login
 * flow (Stage 1 password, first-credential enroll, MFA verify) and for the
 * session-restore refresh call - none of those carry a Bearer token, and
 * none of them should trigger request()'s 401 "session expired" redirect,
 * since there is no Admin session to expire yet at that point.
 */

import { getAccessToken, clearSession } from './session.js';
import { performStepUp } from './step-up.js';

export class ApiError extends Error {
    constructor(message, { status = null, code = null, payload = null } = {}) {
        super(message);
        this.name = 'ApiError';
        this.status = status;
        this.code = code;
        this.payload = payload;
    }
}

function redirectToLogin() {
    clearSession();

    if (!window.location.pathname.startsWith('/admin/login')) {
        window.location.assign('/admin/login');
    }
}

async function send(path, { method, body, headers }) {
    let response;

    try {
        response = await fetch(path, {
            method,
            headers,
            body: body !== undefined && body !== null ? JSON.stringify(body) : undefined,
        });
    } catch {
        throw new ApiError('Unable to reach the server. Check your connection and try again.', { status: 0 });
    }

    let payload = null;

    try {
        payload = await response.json();
    } catch {
        payload = null;
    }

    return { response, payload };
}

/**
 * Unauthenticated request - no Authorization header, never redirects on
 * failure. Used only by the pre-session login flow and session restore.
 */
export async function publicRequest(path, { method = 'GET', body = null } = {}) {
    const { response, payload } = await send(path, {
        method,
        body,
        headers: {
            Accept: 'application/json',
            ...(body ? { 'Content-Type': 'application/json' } : {}),
        },
    });

    if (response.status === 429) {
        throw new ApiError('Too many attempts. Please wait a moment and try again.', { status: 429, payload });
    }

    if (!response.ok || !payload || payload.success !== true) {
        throw new ApiError(payload?.message || 'The request could not be completed.', {
            status: response.status,
            code: payload?.code ?? null,
            payload,
        });
    }

    return payload;
}

/**
 * Authenticated Admin API request. Attaches the in-memory access token,
 * and handles every cross-cutting response shape a protected `/v1/admin/*`
 * route can produce:
 *
 * - 401: the session itself is invalid/expired - clear local state and
 *   return to /admin/login. Never retried.
 * - 403: a capability/authorization denial - the caller stays logged in;
 *   surfaced to the caller as a normal ApiError.
 * - 428 + { code: "STEP_UP_REQUIRED" }: run the Step-Up ceremony once
 *   (see lib/step-up.js) and, on success, retry this EXACT same request
 *   exactly once (allowStepUpRetry guards against ever looping a second
 *   time). A Step-Up failure/cancellation never logs the Admin out - see
 *   docs/api-contracts/admin-authentication-v1.md "admin.stepup
 *   middleware".
 * - 429: rate limited - surfaced as a normal ApiError for the caller to
 *   show as transient.
 * - anything else non-2xx: surfaced as a normal ApiError.
 */
export async function request(path, { method = 'GET', body = null, allowStepUpRetry = true } = {}) {
    const accessToken = getAccessToken();

    const { response, payload } = await send(path, {
        method,
        body,
        headers: {
            Accept: 'application/json',
            ...(accessToken ? { Authorization: `Bearer ${accessToken}` } : {}),
            ...(body ? { 'Content-Type': 'application/json' } : {}),
        },
    });

    if (response.status === 401) {
        redirectToLogin();
        throw new ApiError('Your session has expired. Please sign in again.', { status: 401, payload });
    }

    if (response.status === 428 && payload?.code === 'STEP_UP_REQUIRED' && allowStepUpRetry) {
        const verified = await performStepUp();

        if (!verified) {
            throw new ApiError('Additional verification was not completed.', {
                status: 428,
                code: 'STEP_UP_REQUIRED',
                payload,
            });
        }

        return request(path, { method, body, allowStepUpRetry: false });
    }

    if (response.status === 429) {
        throw new ApiError('Too many attempts. Please wait a moment and try again.', { status: 429, payload });
    }

    if (!response.ok || !payload || payload.success !== true) {
        throw new ApiError(payload?.message || 'The request could not be completed.', {
            status: response.status,
            code: payload?.code ?? null,
            payload,
        });
    }

    return payload;
}
