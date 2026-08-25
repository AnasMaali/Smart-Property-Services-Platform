<?php

namespace App\Support\Admin;

/**
 * Stable Admin security-audit action codes (BLUE V1 Phase A2.6).
 *
 * These values are persisted verbatim in admin_audit_logs.action_code.
 * Keep them centralized so authentication/security Actions never scatter
 * free-form audit strings throughout the codebase.
 */
enum AdminSecurityAuditAction: string
{
    case ADMIN_LOGIN_SUCCESS = 'ADMIN_LOGIN_SUCCESS';
    case ADMIN_LOGIN_MFA_FAILED = 'ADMIN_LOGIN_MFA_FAILED';

    case ADMIN_LOGOUT = 'ADMIN_LOGOUT';
    case ADMIN_LOGOUT_ALL = 'ADMIN_LOGOUT_ALL';

    case WEBAUTHN_CREDENTIAL_REGISTERED = 'WEBAUTHN_CREDENTIAL_REGISTERED';

    case STEP_UP_VERIFIED = 'STEP_UP_VERIFIED';
    case STEP_UP_FAILED = 'STEP_UP_FAILED';
}
