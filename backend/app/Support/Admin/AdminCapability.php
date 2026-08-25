<?php

namespace App\Support\Admin;

/**
 * Stable Admin capability codes (BLUE V1 Phase A1 — Admin Authorization
 * Foundation). Each case's backing value is the exact `admin_permissions.code`
 * row it represents — see `database/blue_v1_seed.sql` ("ADMIN CAPABILITIES")
 * and `docs/api-contracts/admin-authorization-v1.md` for the full catalog and
 * the `family.action` naming convention every future capability must follow.
 *
 * This enum is deliberately small: it only names capabilities that an
 * existing, already-shipped `/v1/admin/*` route enforces today. A future
 * Admin module (Catalog, Pricing, Availability, Payments, Support, ...) adds
 * its own case(s) here in lockstep with its own `admin_permissions` seed
 * row(s) and `admin_role_permissions` grant(s) — never speculatively ahead of
 * the route that needs it.
 */
enum AdminCapability: string
{
    case BOOKINGS_VIEW = 'bookings.view';

    case TECHNICIANS_VIEW = 'technicians.view';
    case TECHNICIANS_ASSIGN = 'technicians.assign';

    case CONTRACTS_VIEW = 'contracts.view';
    case CONTRACTS_MANAGE = 'contracts.manage';
    case CONTRACTS_CANCEL = 'contracts.cancel';

    case PAYMENTS_VIEW = 'payments.view';
    case BILLING_VIEW = 'billing.view';

    /**
     * Covers both Customer and Property Admin reads (BLUE V1 Phase B6) - a
     * Property is always a Customer-owned record, and inspecting one is
     * naturally part of Customer visibility, so a separate
     * `properties.view` capability was deliberately not added.
     */
    case CUSTOMERS_VIEW = 'customers.view';

    /**
     * BLUE V1 Phase B7. Mirrors the `technicians.view`/`technicians.assign`
     * split exactly: `support.view` covers listing/reading Support Requests
     * and their conversation; `support.manage` covers the one Support
     * mutation this phase implements (posting an Admin reply message).
     * Status-transition/assignment mutations were deliberately deferred
     * (no existing lifecycle policy to reuse) - see
     * App\Actions\Admin\Support\AdminSendSupportMessageAction's docblock.
     */
    case SUPPORT_VIEW = 'support.view';

    case SUPPORT_MANAGE = 'support.manage';

    /**
     * BLUE V1 Phase B8. Covers both Service Category and Service reads
     * (list/detail, including their nested capabilities/specializations/
     * options/media/pricing-scheme-version summary) - mirrors the
     * `customers.view` precedent of collapsing a closely related pair of
     * record types into one capability rather than adding
     * `service-categories.view` separately.
     */
    case SERVICES_VIEW = 'services.view';

    /**
     * Covers every Service Catalog mutation this phase implements: Category
     * and Service display-metadata edits (name/description/display_order)
     * and activate/deactivate toggles. Nothing about Service Options,
     * Capabilities, Specializations, or Media is mutable here - see
     * App\Actions\Admin\Service\AdminGetServiceAction's docblock for why
     * each of those remains read-only in this phase.
     */
    case SERVICES_MANAGE = 'services.manage';

    /**
     * The `admin.capability:<code>` route middleware string for this
     * capability — keeps route registration from re-typing the raw string
     * code (see routes/api.php).
     */
    public function middleware(): string
    {
        return 'admin.capability:'.$this->value;
    }
}
