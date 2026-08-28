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

    /**
     * BLUE V1 Phase B15. Allows an authorized Admin to update operational
     * Booking information that remains legitimately editable after
     * checkout, such as visit contact/address details. Financial
     * snapshots, service identity, Customer ownership, appointment
     * capacity, and Booking lifecycle status are intentionally outside
     * this capability - see App\Actions\Admin\Booking\
     * AdminUpdateBookingAction's docblock.
     */
    case BOOKINGS_MANAGE = 'bookings.manage';

    /**
     * BLUE V1 Phase B16. Mirrors the `contracts.manage`/`contracts.cancel`
     * split: cancellation is a one-way terminal transition with a real
     * (manual) refund-eligibility consequence, so it gets its own
     * capability rather than folding into `bookings.manage` - whose own
     * docblock already explicitly excludes Booking lifecycle status.
     * Unlike `contracts.cancel`, this does NOT require `admin.stepup`: a
     * customer may already trigger the exact same cancellation/refund-
     * eligibility outcome on their own Booking with no extra verification
     * (POST /v1/bookings/{booking}/cancel), and cancelling one Booking
     * never revokes a whole Contract's future authorization the way
     * Contract cancellation does.
     */
    case BOOKINGS_CANCEL = 'bookings.cancel';

    /**
     * BLUE V1 Phase B17. Break-glass operational recovery: force-completes
     * a Booking through its Booking Items when the normal technician
     * lifecycle cannot finish it. Strictly more dangerous than
     * `bookings.cancel` (it fabricates completion state that feeds
     * ratings eligibility and Contract entitlement usage) so it gets its
     * own capability rather than folding into `bookings.manage`/
     * `bookings.cancel`, and - like `contracts.cancel`/`pricing.publish` -
     * also requires a fresh `admin.stepup` re-proof on top of this
     * capability. Still granted to ADMIN by default in the canonical
     * seed, exactly like those two precedents: step-up is the safety net
     * here, not capability scarcity.
     */
    case BOOKINGS_FORCE_COMPLETE = 'bookings.force_complete';

    /**
     * BLUE V1 Phase B19. Moving a Booking to a different appointment_slot
     * is a distinct operational mutation (capacity/hold/Technician-overlap
     * concerns bookings.manage's own docblock never covers) so it gets its
     * own capability rather than overloading bookings.manage. No
     * admin.stepup - unlike bookings.force_complete, a reschedule is
     * reversible (can be rescheduled again) and never touches payment,
     * pricing, or Contract entitlement.
     */
    case BOOKINGS_RESCHEDULE = 'bookings.reschedule';

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
     * BLUE V1 Phase B9. `pricing.view` covers list/detail reads of
     * pricing_scheme_versions and their nested rules/conditions/tiers.
     */
    case PRICING_VIEW = 'pricing.view';

    /**
     * Covers DRAFT-only authoring: creating a new DRAFT scheme version and
     * creating/deleting its rules (with their condition groups/conditions
     * and tiers). PUBLISHED/RETIRED versions and rules are never mutable
     * through this or any capability - see App\Actions\Admin\Pricing\
     * AdminCreatePricingRuleAction's docblock.
     */
    case PRICING_MANAGE = 'pricing.manage';

    /**
     * Mirrors the `contracts.manage`/`contracts.cancel` split: publishing a
     * DRAFT makes it live for real customer price calculations, which is
     * uniquely dangerous and hard to reverse (like a Contract cancellation)
     * - it therefore gets its own capability AND `admin.stepup`, never
     * folded into `pricing.manage`.
     */
    case PRICING_PUBLISH = 'pricing.publish';

    /**
     * BLUE V1 Phase B10. The Admin Dashboard aggregates read-only counts
     * and small bounded lists across every existing domain (Bookings,
     * Contracts, Payments, Contract Billing, Support, Technicians,
     * Customers) - unlike every other `.view` capability, it is not scoped
     * to one domain. A single `dashboard.view` capability (rather than
     * requiring every individual domain `.view` capability at once, which
     * this codebase's `admin.capability:<code>` middleware has no AND
     * -combination support for) keeps this consistent with the existing
     * "exactly one capability per route" convention while still gating
     * this cross-domain read behind a real capability, not just
     * `auth.admin` alone (unlike the identity-only `/v1/admin/me`).
     */
    case DASHBOARD_VIEW = 'dashboard.view';

    /**
     * BLUE V1 Phase B11. Read-only visibility into `ratings` (one row per
     * completed Booking, at most). No customer-facing rating-creation
     * endpoint exists anywhere in this codebase yet, and
     * docs/03-features-and-requirements/10-rating-and-feedback.md
     * explicitly defers edit/delete to "a future version" - so there is no
     * `ratings.manage` counterpart, mirroring the `payments.view`/
     * `billing.view` precedent of a single view-only capability.
     */
    case RATINGS_VIEW = 'ratings.view';

    /**
     * BLUE V1 Phase B12. Read-only, searchable visibility into
     * `admin_audit_logs` - B10's Dashboard only ever exposes its 10
     * most-recent rows. An audit ledger is append-only by nature: nothing
     * ever mutates a row, so there is no `audit.manage` counterpart.
     */
    case AUDIT_VIEW = 'audit.view';

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
