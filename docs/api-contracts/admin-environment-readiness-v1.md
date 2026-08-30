# BLUE V1 — Admin Environment Readiness (Phase B13)

This document records how `blue_db` (the real local development database) was brought in sync with
the Admin Panel schema/permissions already proven against `blue_test_db`, and what is required to
actually open `/admin/login` in a real browser against `blue_db`. It complements
`admin-authorization-v1.md` (permission model), `admin-webauthn-mfa-v1.md` (credential schema), and
`admin-authentication-v1.md` (login/MFA API contract) — this doc is about *environment*, not API
shape.

## Why `blue_db` and `blue_test_db` had diverged

This project does not use Laravel migrations (`backend/database/migrations/` is empty except
`.gitkeep` from initial scaffolding — confirmed no `migrations` table exists in either database).
Schema lives in `database/blue_v1_schema.sql` (the full, always-current, cumulative DDL dump) plus
small incremental `database/phaseNN_*_migration.sql` upgrade scripts, each idempotent
(information_schema-guarded — see any `phaseNN_*.sql` header) and meant to be re-run safely against
an already-deployed database that cannot be dropped/recreated.

`blue_test_db` is rebuilt from the canonical dump before tests via
`php artisan blue:provision-test-db` (`backend/app/Console/Commands/ProvisionTestDatabase.php`),
which streams `blue_v1_schema.sql` + `blue_v1_seed.sql` straight into `blue_test_db` — so it is
always current with HEAD. `blue_db` was provisioned once (schema imported at a point after Phase 11
shipped but before Phase 12), and nothing re-synced it after that. It was missing:

- Phase 12 (`users.deleted_at`) and Phase 13 (`customer_account_deletion_requests`) — required by
  `App\Support\Admin\AdminCustomerPresenter`, which reads both. Without them the Admin Customers
  page (`customers.view`) would fail against `blue_db`.
- Phase 15 (`admin_permissions`, `admin_role_permissions`), Phase 16 (`admin_webauthn_challenge_purposes`,
  `admin_webauthn_challenges`, `admin_webauthn_credentials`), Phase 17
  (`auth_sessions.step_up_verified_at`, `admin_webauthn_challenges.auth_session_id`) — the entire
  Admin authorization/MFA/step-up foundation.
- 13 of the 19 `admin_permissions` rows and their `ADMIN` grants: `phase15_admin_permission_foundation_migration.sql`
  only ever seeded the original 6 (`bookings.view`, `technicians.view`, `technicians.assign`,
  `contracts.view`, `contracts.manage`, `contracts.cancel`); every capability added by later phases
  (B6–B12) was appended directly to `database/blue_v1_seed.sql`'s idempotent
  `INSERT ... ON DUPLICATE KEY UPDATE` blocks rather than getting its own `phaseNN` file, and
  `blue_v1_seed.sql` had never been (re-)run against `blue_db`.

Phase 14 (`otp_verification_purposes` `LOGIN` row) was found missing too, but is unrelated to the
Admin Panel (no Admin code path reads `otp_verification_purposes`) and was deliberately left
untouched — out of scope for this phase.

## Repair performed

A full `mysqldump` backup of `blue_db` was taken first:
`database/backups/blue_db_before_admin_b13_sync_20260826_121653.sql` (gitignored, left in place,
untouched).

1. Applied, in order, using DDL-capable credentials (the application's runtime `blue_app` MySQL user
   intentionally has only `SELECT/INSERT/UPDATE/DELETE`, no `CREATE`/`ALTER` — this is by design,
   documented in each file's own header, not a bug worked around here):
   - `database/phase12_account_deletion_migration.sql`
   - `database/phase13_pending_account_deletion_migration.sql`
   - `database/phase15_admin_permission_foundation_migration.sql`
   - `database/phase16_admin_webauthn_mfa_schema_migration.sql`
   - `database/phase17_admin_step_up_schema_migration.sql`

   Each is idempotent; re-running any of them is always a safe no-op (verified — a second run
   reports every column/index/constraint as already existing and skipped).

2. Synced the remaining 13 `admin_permissions` rows, their `ADMIN` grants, and confirmed the 3
   `admin_webauthn_challenge_purposes` rows, by extracting and re-running exactly the "2A. ADMIN
   CAPABILITIES", "2B. ADMIN ROLE → CAPABILITY GRANTS", and "2C. ADMIN WEBAUTHN CHALLENGE PURPOSES"
   blocks already committed in `database/blue_v1_seed.sql` (byte-identical to the repository file —
   no new SQL was authored). This step is DML-only (`INSERT ... ON DUPLICATE KEY UPDATE` keyed by
   each table's natural unique `code`), so it ran under the normal `blue_app` credentials. The
   general reference-data sections of `blue_v1_seed.sql` (geography, service catalog defaults,
   status/purpose tables outside the Admin scope, etc.) were deliberately **not** re-run wholesale
   against `blue_db`, since those tables may already carry real operator edits made through the
   live Admin UI — only the Admin-authorization-specific blocks, which have no editing UI and are
   pure developer-maintained catalogs, were synced.

No new migration file was created — every gap had an existing, already-reviewed, already-committed
repair. No schema beyond what these five files define was touched.

## Verification result

- `blue_db` and `blue_test_db` now have identical table sets (88 each, zero diff either direction)
  and structurally identical `CREATE TABLE` output for every Admin-critical table (`admin_permissions`,
  `admin_role_permissions`, `admin_webauthn_challenge_purposes`, `admin_webauthn_challenges`,
  `admin_webauthn_credentials`, `admin_audit_logs`, `auth_sessions`, `users`,
  `customer_account_deletion_requests`) — the only differences are `AUTO_INCREMENT` counter values,
  which reflect each database's independent data history and are expected.
- `admin_permissions`: 19 rows in `blue_db`, matching `App\Support\Admin\AdminCapability` and
  `blue_test_db` exactly, zero duplicates.
- `admin_role_permissions`: `ADMIN` holds all 19 grants; `SUPER_ADMIN` correctly holds none (its
  centralized override in `App\Support\Admin\AdminAuthorizationService` is unchanged).
- `roles` (`ADMIN`, `SUPER_ADMIN`, `CUSTOMER`) and `user_account_statuses`
  (`ACTIVE`/`PENDING_VERIFICATION`/`SUSPENDED`/`DEACTIVATED`) already present and correct.

## First real Admin account

`blue_db` already contains one `ACTIVE` user with the `ADMIN` role and zero rows in
`admin_webauthn_credentials` — exactly the correct pre-enrollment state for a genuine first-time
MFA bootstrap (`MFA_ENROLLMENT_REQUIRED` on first login). No new provisioning command was built:
this phase's mandate was to reuse an existing safe mechanism rather than add a competing one, and a
suitable account already exists. Its password is whatever was set when the account was created; it
was never read, reset, or logged by this phase. If it is ever lost, the smallest safe fix is a
password-reset flow using the existing hashing mechanism — none was needed or built here.

## Real WebAuthn local browser requirements

`config/admin_webauthn.php` deliberately has no default `rp_id`/`allowed_origins` in any
environment — an unset value fails closed. `backend/.env` now sets:

```
ADMIN_WEBAUTHN_RP_ID=localhost
ADMIN_WEBAUTHN_ORIGINS=http://localhost:8000
```

This is a standards-valid WebAuthn configuration, not a relaxation: browsers treat `localhost` as a
secure context and a legitimate Relying Party ID; an IP literal (`127.0.0.1`, which is what
`composer run dev` → `php artisan serve`'s default host resolves to) is never a valid RP ID under
the WebAuthn spec. **You must browse to `http://localhost:8000/admin/login` specifically — not
`http://127.0.0.1:8000`** — or every WebAuthn ceremony will fail origin/RP-ID validation exactly as
designed. Tailscale is a production-only network perimeter (see `admin-webauthn-mfa-v1.md`,
"Where device trust lives") and is not required for local testing.

Verified against `http://localhost:8000`: `/admin/login` and `/admin` render (200, correct
`<title>BLUE Admin</title>`), and the Vite-built `admin.css`/`admin/app.js` bundle resolves
correctly (confirmed against `public/build/manifest.json`).

## Verified end-to-end (code-level audit, BLUE V1 Phase B13)

Every stage of the real login/MFA/session lifecycle was traced against its actual implementation
(no live browser/authenticator is available in this environment, so this is a code-correctness
trace, not a live ceremony — a human with a real browser and platform authenticator must still
perform the actual first enrollment):

- Stage 1 password login → `MFA_ENROLLMENT_REQUIRED`/`MFA_REQUIRED` branching, generic anti-oracle
  failure messages (`AdminLoginAction`).
- `/mfa/enroll` → `/mfa/verify` → real `ADMIN_WEB` `auth_sessions` row + access/refresh token pair,
  `AdminSessionPolicy` (12h absolute / 20min idle) applied.
- Frontend (`resources/js/admin/auth/*.js`): correct `navigator.credentials.create()`/`get()`
  sequencing; access token held in memory only, refresh token in `sessionStorage` only — no
  `localStorage` anywhere under `resources/js/admin`.
- Session restore, refresh rotation, logout/logout-all (server-side session invalidation, not just
  client-side clear), and the Step-Up modal (`resources/js/admin/lib/step-up.js`, transparently
  triggered on `428 STEP_UP_REQUIRED` for `contracts.cancel`/`pricing.publish`) all wired correctly.
- All 13 modules (Dashboard, Bookings, Technicians, Contracts, Payments, Contract Billing,
  Customers, Properties, Support, Service Catalog, Pricing, Ratings, Audit Logs): web route ↔ Blade
  view ↔ JS module ↔ API route ↔ `AdminCapability` gate all present and consistent; every mutation
  reloads authoritative server state afterward instead of patching local state.
- Cross-links (Dashboard → detail pages, Customer → Bookings/Contracts/Payments/Billing/Support/Ratings,
  Contract ↔ Billing, Service → Pricing, Booking → technician actions, sidebar active states, detail
  back-links) all verified as real, working links.
- One defect found and fixed: the sidebar "Properties" item was a dead `href="#"` placeholder
  (`resources/views/admin/layouts/app.blade.php`) left over from BLUE V1 Phase B6. There was never
  meant to be a standalone Properties list page — a Property is only ever reached from its owning
  Customer's detail page, and the "Customers" sidebar item's active-state check already covers
  `/admin/properties*`. The dead link has been removed rather than given a fabricated destination.

## Remaining manual step

The final first-login enrollment (`navigator.credentials.create()` against a real platform
authenticator) requires an actual browser and authenticator, and the existing Admin account's
password — neither is available to an automated agent, by design. Everything up to that point is
verified ready.

## Security invariants preserved

No MFA bypass, no Step-Up bypass, no plaintext credential storage, no hardcoded/shared Admin
password, no new organization/company/team hierarchy, no duplicate authorization model, no schema
beyond the five already-reviewed `phaseNN` files, no relaxation of `rp_id`/`allowed_origins`
fail-closed behavior. `SUPER_ADMIN`'s centralized authorization override
(`App\Support\Admin\AdminAuthorizationService`) is unchanged and still requires no `admin_role_permissions`
rows.
