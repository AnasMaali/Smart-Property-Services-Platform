# BLUE V1 — Admin Authorization / Permission Foundation (Phase A1)

This document describes the Admin **authorization** (capability) layer added on top of the
existing Admin **authentication** boundary (`admin-authentication-v1.md`) and the Admin
Operations routes it now gates (`admin-operations-v1.md`). It documents only what exists in
code — verified against `backend/routes/api.php`, `App\Support\Admin\*`,
`App\Http\Middleware\EnsureAdminHasCapability`, `database/blue_v1_schema.sql`, and
`backend/tests/Feature/Admin/AdminCapabilityAuthorizationTest.php`.

## Scope of this phase

Phase A1 builds the **authorization foundation only**: a capability-code catalog, a Role →
Capability grant table, a centralized authorization service, and one route middleware that
consults it. It does **not** add any new Admin operational module (Catalog, Pricing,
Availability, Payments, Support, Customers, Technician CRUD, etc.) and does not change what any
existing Admin route does for a caller who already holds `ADMIN` or `SUPER_ADMIN` — it makes that
existing access explicit and revocable per-capability instead of implicit and all-or-nothing.

## Authentication vs. Authorization — the core distinction

> **Authentication** answers: *"Is this an authenticated Admin-session user?"* — handled entirely
> by `auth.admin` (`App\Http\Middleware\AuthenticateAdmin`), unchanged by this phase. It verifies
> the JWT, the backing `auth_sessions` row, the user's `ACTIVE` account status, that the user holds
> at least one currently active `ADMIN`/`SUPER_ADMIN` role, and that the session's client type is
> `ADMIN_WEB`. On success it attaches `auth_user`, `auth_session`, and `auth_admin_roles` (the
> caller's active Admin role codes) to the request.
>
> **Authorization** answers: *"Is this Admin allowed to perform THIS specific capability?"* —
> handled by `admin.capability:<code>` (`App\Http\Middleware\EnsureAdminHasCapability`), which
> **must run after `auth.admin`** and only consults `auth_admin_roles` already resolved by it. It
> never re-authenticates the request itself.
>
> These are two separate middleware for a reason: authentication alone must never grant
> operational capability. A route with only `auth.admin` and no `admin.capability` gate (there is
> exactly one: `GET /v1/admin/me`) is a deliberate exception, not an oversight — identity bootstrap
> is available to every authenticated Admin regardless of which capabilities they hold.

## Roles (unchanged)

`ADMIN` and `SUPER_ADMIN`, in the existing `roles`/`user_roles` tables — no new role, no second
role system. This phase adds a permission **layer** on top of the existing roles, never replaces
or duplicates them.

## Permission schema

Two new, purely additive tables (`database/phase15_admin_permission_foundation_migration.sql`;
folded into `database/blue_v1_schema.sql`/`blue_v1_seed.sql` for fresh provisioning):

### `admin_permissions` — the capability-code catalog

| Column | Notes |
|---|---|
| `id` | `smallint unsigned AUTO_INCREMENT` |
| `code` | unique, ASCII, `family.action` (see naming convention below) |
| `name` | human-readable label |
| `description` | human-readable explanation |
| `is_active` | deactivating a row here fails every check for it closed, for every role except SUPER_ADMIN (which never consults this table) |

### `admin_role_permissions` — the Role → Capability grant matrix

| Column | Notes |
|---|---|
| `role_id` | FK → `roles.id` |
| `permission_id` | FK → `admin_permissions.id` |
| `granted_by_user_id` | FK → `users.id`, nullable (`NULL` for system/seed-provisioned grants) |
| `granted_at` | timestamp |
| Primary key | `(role_id, permission_id)` — a role holds a capability at most once |

**No row in this table is ever seeded for `SUPER_ADMIN`.** See "SUPER_ADMIN override" below — this
is intentional, not missing data.

## Capability naming convention

`family.action`, all lowercase, `snake_case` within each segment, at least one dot
(`^[a-z][a-z0-9_]*([.][a-z][a-z0-9_]*)+$`, enforced by a database `CHECK` constraint). `family`
groups a module (`bookings`, `technicians`, `contracts`, and — in future phases —
`services`, `pricing`, `availability`, `payments`, `support`, `customers`, `admins`, ...);
`action` names the operation within it (`view`, `manage`, `assign`, `cancel`, `refund`, ...).

Every capability code has a matching case in `App\Support\Admin\AdminCapability` (a PHP backed
enum) — application code never hand-types a raw capability string in a route definition.

## Current capability matrix

Derived from, and enforced on, the exact routes already shipped in Phase 9B/10E. No capability
below exists without a real route enforcing it.

| Capability | Grants | Route(s) | `ADMIN` | `SUPER_ADMIN` |
|---|---|---|---|---|
| `bookings.view` | Read paid Bookings/Booking Items across every customer | `GET /v1/admin/bookings`, `GET /v1/admin/bookings/{booking}` | ✅ | ✅ (override) |
| `technicians.view` | Read Technician records and candidate lists | `GET /v1/admin/technicians`, `GET /v1/admin/booking-items/{id}/technician-candidates` | ✅ | ✅ (override) |
| `technicians.assign` | Assign/reassign/start/complete Technician work | `POST .../assign-technician`, `.../reassign-technician`, `.../start-work`, `.../complete-work` | ✅ | ✅ (override) |
| `contracts.view` | Read Service Contracts | `GET /v1/admin/contracts`, `GET /v1/admin/contracts/{contract}` | ✅ | ✅ (override) |
| `contracts.manage` | Approve / send for acceptance / suspend a Contract | `POST .../approve`, `.../send-for-acceptance`, `.../suspend` | ✅ | ✅ (override) |
| `contracts.cancel` | Cancel a Service Contract | `POST /v1/admin/contracts/{contract}/cancel` | ✅ | ✅ (override) |

`GET /v1/admin/me` requires no capability — see "Authentication vs. Authorization" above.

`ADMIN` holds every capability above (seeded in `database/blue_v1_seed.sql` §2B), which
**preserves Phase 9B's existing behavior exactly** — `ADMIN` and `SUPER_ADMIN` had identical
operational access before this phase, and still do today. What changes is that `ADMIN`'s access is
now an explicit, auditable, individually-revocable set of grant rows instead of an implicit "any
`ADMIN`/`SUPER_ADMIN` passes" rule. A future phase may choose to grant `ADMIN` a narrower set for a
new module (e.g. `payments.view` but not `payments.refund`) — that is now a data change
(`admin_role_permissions`), never a code change.

## SUPER_ADMIN override

A caller holding an active `SUPER_ADMIN` role is authorized for **every** capability — present and
future — through one centralized, explicit branch in `App\Support\Admin\AdminAuthorizationService`,
never a per-controller/per-Action `if ($role === 'SUPER_ADMIN')` special case. This means a brand
new capability introduced by a future module is automatically available to `SUPER_ADMIN` the
moment it exists, with no grant row and no code change required for `SUPER_ADMIN` specifically.

The override bypasses **permission assignment only**. It never bypasses:

- authentication (`auth.admin` — a deactivated account or revoked session still gets `401`, not `403`, exactly as before this phase, before `EnsureAdminHasCapability` is ever reached);
- the transaction-time re-check `App\Support\Admin\AdminMutationAuthorizer` already performs inside privileged mutations (unchanged by this phase — see that class's own docblock);
- request validation, domain transition rules, or any database constraint.

It also does not validate that the capability code itself is real — the override is unconditional
on the role, by design (see `AdminAuthorizationService`'s own docblock and
`AdminCapabilityAuthorizationTest::test_super_admin_override_does_not_validate_the_capability_code_itself`).

## Fail-closed guarantees

`AdminAuthorizationService::authorize()` returns `false` (and `EnsureAdminHasCapability` responds
`403 { "success": false, "message": "You are not authorized to perform this action." }`) for every
one of:

- an empty/missing active-role list (e.g. `auth.admin` was somehow skipped);
- a capability code with no matching `admin_permissions` row;
- a capability row that exists but is `is_active = 0`;
- an `ADMIN` role holding no matching `admin_role_permissions` grant.

There is no "unknown capability defaults to allow" path anywhere in this design.

## What this phase deliberately does NOT change

- `App\Support\Admin\AdminMutationAuthorizer` (the transaction-time binary re-check used inside
  privileged domain Actions) is untouched. It answers a different, narrower question — "is this
  actor still a genuinely active ADMIN/SUPER_ADMIN right now, under a DB lock, at the moment of
  commit?" — and remains the race-condition safety net it already was. Capability authorization
  happens once, earlier, at the HTTP boundary; it does not run inside a domain transaction and does
  not hold any row lock.
- No audit row is written for an authorization check or denial. `admin_audit_logs` keeps its
  existing Phase 9B semantics exactly: one row per successful, state-changing privileged mutation.
  A rejected request (401 or 403) is a normal, expected outcome of everyday API usage, not a
  security event logged there — see `AdminAuthorizationService`'s own docblock for the reasoning.
- `GET /v1/admin/me`'s response shape is unchanged (still `roles`, not `permissions`) — exposing a
  caller's resolved capability list is left to whichever future phase actually needs the Admin
  frontend to consume it.

## Adding a new capability (process, for future modules)

1. Add exactly one new `App\Support\Admin\AdminCapability` case, matching the `family.action`
   convention.
2. Add exactly one matching `admin_permissions` seed row (`blue_v1_seed.sql` §2A **and** a new
   additive `phaseNN_..._migration.sql`, following this phase's file as a template).
3. Grant it to whichever role(s) the product decision calls for, in `blue_v1_seed.sql` §2B / the
   same migration file. Never grant it to `SUPER_ADMIN` explicitly — the override already covers
   that role.
4. Attach `AdminCapability::YOUR_CASE->middleware()` to the new route(s), after `auth.admin` in the
   middleware chain.

No other file needs to change — `AdminAuthorizationService` and `EnsureAdminHasCapability` are
already generic over any capability code.
