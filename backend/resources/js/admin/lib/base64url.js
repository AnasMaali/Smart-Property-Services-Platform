/**
 * Base64URL <-> ArrayBuffer conversion (BLUE V1 Phase B1.2).
 *
 * The Admin API (see docs/api-contracts/admin-webauthn-mfa-v1.md) always
 * emits/expects WebAuthn binary values (challenge, credential ids,
 * clientDataJSON, attestationObject, authenticatorData, signature,
 * userHandle) as unpadded Base64URL strings - the same wire format
 * navigator.credentials.create()/.get() and their PublicKeyCredential
 * response objects already use natively in the browser. window.atob()/
 * btoa() only understand standard Base64 (with '+', '/', '=' padding), so
 * every conversion must go through these two helpers rather than calling
 * atob()/btoa() directly on a Base64URL string.
 */

export function base64UrlToBuffer(base64Url) {
    const padded = base64Url + '='.repeat((4 - (base64Url.length % 4)) % 4);
    const base64 = padded.replace(/-/g, '+').replace(/_/g, '/');
    const raw = window.atob(base64);
    const bytes = new Uint8Array(raw.length);

    for (let i = 0; i < raw.length; i++) {
        bytes[i] = raw.charCodeAt(i);
    }

    return bytes.buffer;
}

export function bufferToBase64Url(buffer) {
    const bytes = new Uint8Array(buffer);
    let binary = '';

    for (let i = 0; i < bytes.byteLength; i++) {
        binary += String.fromCharCode(bytes[i]);
    }

    return window
        .btoa(binary)
        .replace(/\+/g, '-')
        .replace(/\//g, '_')
        .replace(/=+$/, '');
}
