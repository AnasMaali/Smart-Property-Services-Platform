/**
 * Admin frontend session storage policy (BLUE V1 Phase B1.2):
 *
 * - access_token lives ONLY in memory (a module-level variable) - never
 *   written to localStorage, sessionStorage, a cookie, or the DOM. A full
 *   page reload always loses it, which is intentional (see restore.js).
 * - refresh_token lives in sessionStorage only - cleared automatically
 *   when the tab/browser closes, never shared across tabs, never as
 *   durable as localStorage.
 * - Every refresh-token ROTATION must call setRefreshToken() again with
 *   the new value - the old one is never reused after a successful
 *   refresh (App\Actions\Auth\AdminRefreshTokenAction rotates it
 *   server-side on every call).
 *
 * No token value is ever logged, and nothing here writes to the DOM.
 */

const REFRESH_TOKEN_STORAGE_KEY = 'blue_admin_refresh_token';

let accessToken = null;
let accessTokenExpiresAt = null;

export function getAccessToken() {
    return accessToken;
}

export function setAccessToken(token, expiresAt = null) {
    accessToken = token || null;
    accessTokenExpiresAt = expiresAt || null;
}

export function getAccessTokenExpiresAt() {
    return accessTokenExpiresAt;
}

export function getRefreshToken() {
    try {
        return window.sessionStorage.getItem(REFRESH_TOKEN_STORAGE_KEY);
    } catch {
        // sessionStorage unavailable (private browsing, disabled storage,
        // sandboxed iframe) - treat as "no stored session" rather than
        // throwing out of every caller.
        return null;
    }
}

export function setRefreshToken(token) {
    try {
        if (token) {
            window.sessionStorage.setItem(REFRESH_TOKEN_STORAGE_KEY, token);
        } else {
            window.sessionStorage.removeItem(REFRESH_TOKEN_STORAGE_KEY);
        }
    } catch {
        // See getRefreshToken() - never throw for storage unavailability.
    }
}

export function hasStoredRefreshToken() {
    return Boolean(getRefreshToken());
}

export function clearSession() {
    setAccessToken(null);
    setRefreshToken(null);
}
