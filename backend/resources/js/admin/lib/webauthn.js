/**
 * Browser WebAuthn ceremony helpers (BLUE V1 Phase B1.2).
 *
 * Converts the exact snake_case, Base64URL-encoded option shapes the Admin
 * API emits (App\Support\Admin\WebAuthn\AdminWebAuthnOptionsPresenter) into
 * the camelCase, ArrayBuffer-based objects navigator.credentials.create()/
 * .get() require, and serializes the resulting PublicKeyCredential back
 * into the exact JSON shape the Admin API expects. No cryptographic
 * verification happens here or anywhere in the browser - the browser only
 * performs the WebAuthn ceremony; web-auth/webauthn-lib on the server
 * remains the sole security authority.
 */

import { base64UrlToBuffer, bufferToBase64Url } from './base64url.js';

function assertSupported() {
    if (!window.PublicKeyCredential || !navigator.credentials) {
        throw new Error('This browser does not support WebAuthn security keys.');
    }
}

function toCreationOptions(options) {
    return {
        rp: {
            id: options.rp.id,
            name: options.rp.name,
        },
        user: {
            id: base64UrlToBuffer(options.user.id),
            name: options.user.name,
            displayName: options.user.display_name,
        },
        challenge: base64UrlToBuffer(options.challenge),
        pubKeyCredParams: options.pub_key_cred_params.map((param) => ({
            type: param.type,
            alg: param.alg,
        })),
        authenticatorSelection: options.authenticator_selection
            ? { userVerification: options.authenticator_selection.user_verification }
            : undefined,
        attestation: options.attestation,
        excludeCredentials: (options.exclude_credentials || []).map(toDescriptor),
        timeout: options.timeout || undefined,
    };
}

function toRequestOptions(options) {
    return {
        rpId: options.rp_id,
        challenge: base64UrlToBuffer(options.challenge),
        allowCredentials: (options.allow_credentials || []).map(toDescriptor),
        userVerification: options.user_verification,
        timeout: options.timeout || undefined,
    };
}

function toDescriptor(descriptor) {
    return {
        id: base64UrlToBuffer(descriptor.id),
        type: descriptor.type,
        transports: descriptor.transports || undefined,
    };
}

function serializeAttestation(credential) {
    const response = credential.response;

    return {
        id: credential.id,
        rawId: bufferToBase64Url(credential.rawId),
        type: credential.type,
        response: {
            clientDataJSON: bufferToBase64Url(response.clientDataJSON),
            attestationObject: bufferToBase64Url(response.attestationObject),
        },
    };
}

function serializeAssertion(credential) {
    const response = credential.response;
    const payload = {
        id: credential.id,
        rawId: bufferToBase64Url(credential.rawId),
        type: credential.type,
        response: {
            clientDataJSON: bufferToBase64Url(response.clientDataJSON),
            authenticatorData: bufferToBase64Url(response.authenticatorData),
            signature: bufferToBase64Url(response.signature),
        },
    };

    if (response.userHandle) {
        payload.response.userHandle = bufferToBase64Url(response.userHandle);
    }

    return payload;
}

/**
 * Runs navigator.credentials.create() against Admin registration options
 * (MFA_ENROLLMENT_REQUIRED / first-credential bootstrap) and returns the
 * JSON body ready to send to POST /v1/admin/auth/mfa/enroll.
 */
export async function createCredential(creationOptions) {
    assertSupported();

    let credential;

    try {
        credential = await navigator.credentials.create({ publicKey: toCreationOptions(creationOptions) });
    } catch (error) {
        throw normalizeCeremonyError(error);
    }

    if (!credential) {
        throw new Error('Security key setup was cancelled.');
    }

    return serializeAttestation(credential);
}

/**
 * Runs navigator.credentials.get() against Admin assertion options
 * (MFA_REQUIRED login, or a Step-Up challenge) and returns the JSON body
 * ready to send to POST /v1/admin/auth/mfa/verify or
 * POST /v1/admin/auth/step-up/verify.
 */
export async function getCredential(requestOptions) {
    assertSupported();

    let credential;

    try {
        credential = await navigator.credentials.get({ publicKey: toRequestOptions(requestOptions) });
    } catch (error) {
        throw normalizeCeremonyError(error);
    }

    if (!credential) {
        throw new Error('Security key verification was cancelled.');
    }

    return serializeAssertion(credential);
}

function normalizeCeremonyError(error) {
    if (error && error.name === 'NotAllowedError') {
        return new Error('The security key action was cancelled or timed out.');
    }

    if (error && error.name === 'InvalidStateError') {
        return new Error('This security key is already registered.');
    }

    return error instanceof Error ? error : new Error('The security key action failed.');
}
