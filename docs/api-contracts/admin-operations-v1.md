# BLUE V1 — Admin Operations API Contract (Phase 9B)

Base URL: `{{base_url}}` (local default: `http://127.0.0.1:8000/api/v1`)

This document describes the Admin operational endpoints actually implemented in
`backend/routes/api.php`, their Form Requests, `App\Actions\Admin\*` wrapper Actions, and
Controllers, verified against `backend/tests/Feature/Admin/*`. It documents only what exists in
code — no aspirational or planned behavior is included.

Cross-reference: `admin-authentication-v1.md` (the `auth.admin` security boundary every route below
sits behind) and `bookings-v1.md` (the Phase 7B/8A/8B domain Actions every endpoint below wraps
without re-implementing).

## Scope of this phase

Phase 9B is a **thin, secure transport layer** over the already-tested Phase 7B (Booking/Booking
Item lifecycle) and Phase 8A/8B (technician assignment, Start/Complete Work) domain Actions. Every
mutating endpoint below calls one existing, unmodified domain Action — `App\Actions\Technician\
AssignTechnicianToBookingItemAction`, `StartTechnicianJobAction`, `CompleteTechnicianJobAction` —
and only maps the request to that Action's signature and its outcome to HTTP. No route
re-implements eligibility, specialization matching, double-booking detection, item-status
validation, idempotency, or DB race handling; those all still live exactly where Phase 8A/8B put
them.

Not touched by this phase: Payment Core, Stripe, `PricingEngine`, any Booking/Booking Item financial
snapshot column, or the schema.

## Global notes

- All responses use the same envelope as every other Phase: `{ "success": bool, "message": string, "data": object|null }`.
- Every route requires `Authorization: Bearer {{admin_access_token}}`, enforced by the `auth.admin`
  middleware (`admin-authentication-v1.md`). A valid Customer access token is rejected identically to
  a missing/expired one (`401`, `"This session is invalid or has expired."`) — it is never
  distinguishable from any other invalid-session case.
- `ADMIN` and `SUPER_ADMIN` are treated identically throughout this phase — exactly as Phase 9A
  originally documented. **BLUE V1 Phase A1** (`docs/api-contracts/admin-authorization-v1.md`)
  later added a capability-based permission layer behind every route below, but seeded `ADMIN` with
  every capability those routes require, so this remains true in practice: `ADMIN` and
  `SUPER_ADMIN` still pass every endpoint below identically today. No endpoint below branches on
  which of the two roles the caller holds — the capability grant (or `SUPER_ADMIN`'s centralized
  override) does that instead, entirely outside this phase's Controllers/Actions.
- Every id in a response is a UUID string. Every amount is a decimal string. Every date is
  ISO-8601. Every status is a string code. No raw `binary(16)` value, numeric status/role id, or
  provider secret (`client_secret`, webhook signing secret, password/refresh-token hash) is ever
  returned.
- The actor for every mutating endpoint is always `$request->attributes->get('auth_user')` — the
  identity `auth.admin` already resolved and verified against the database on this exact request.
  No request body field is ever accepted for actor identity, and any such field present in a
  request body is silently ignored (Form Requests only declare the fields they actually use).

## Endpoint summary

| # | Feature | Method | Route | Notes |
|---|---|---|---|---|
| 1 | List Bookings | GET | `/v1/admin/bookings` | Paginated, filtered, not customer-scoped |
| 2 | Get Booking | GET | `/v1/admin/bookings/{booking}` | Operational detail |
| 3 | List Technicians | GET | `/v1/admin/technicians` | Paginated, filtered |
| 4 | List Technician Candidates | GET | `/v1/admin/booking-items/{bookingItem}/technician-candidates` | Advisory only |
| 5 | Assign Technician | POST | `/v1/admin/booking-items/{bookingItem}/assign-technician` | Wraps `assign()` |
| 6 | Reassign Technician | POST | `/v1/admin/booking-items/{bookingItem}/reassign-technician` | Wraps `reassign()` |
| 7 | Start Work | POST | `/v1/admin/booking-items/{bookingItem}/start-work` | Wraps `start()` |
| 8 | Complete Work | POST | `/v1/admin/booking-items/{bookingItem}/complete-work` | Wraps `complete()` |

All eight sit inside the `auth.admin` route group in `routes/api.php`, alongside `GET /v1/admin/me`
from Phase 9A.

---

## 1. List Bookings

- **HTTP method / route**: `GET /v1/admin/bookings`
- Unlike `GET /v1/bookings` (customer, ownership-scoped), this is **not** scoped to one customer —
  the Admin sees paid Bookings across every customer (ACR-BKG-06).
- **Query parameters** (all optional):

  | Param | Rules | Effect |
  |---|---|---|
  | `page` | integer, min 1 (default 1) | Page number |
  | `per_page` | integer, 1–100 (default 20) | Page size, hard-capped at 100 |
  | `status` | must be a real `booking_statuses.code` | Exact status match |
  | `booking_number` | string, max 40 | Exact `booking_number` match |
  | `customer_uuid` | uuid | Scopes to one customer's Bookings (`carts.customer_user_id`) |
  | `from` | date | `bookings.created_at >= from` |
  | `to` | date, `after_or_equal:from` | `bookings.created_at <= to` |
  | `appointment_date` | `Y-m-d` | Bookings whose appointment slot falls on this calendar day |

  An unknown `status` value is `422` (validated against `booking_statuses.code` via `Rule::exists`),
  never silently ignored. `per_page` above 100 is `422`, never silently clamped past the cap.
- **Ordering**: `bookings.created_at DESC, bookings.id DESC` — deterministic; a page re-requested
  with identical filters always returns the identical row order.
- **Success status**: `200 OK`
- **Success response** (abridged):
```json
{
  "success": true,
  "message": "Bookings retrieved successfully.",
  "data": {
    "bookings": [
      {
        "uuid": "...",
        "booking_number": "BLU-4F9A1C2B0D",
        "status": "PAID",
        "customer": { "uuid": "...", "full_name": "Jane Customer", "phone_number": "+971500000000" },
        "currency": { "code": "AED", "symbol": "د.إ", "decimal_places": 2 },
        "items_count": 2,
        "total": "250.000000",
        "created_at": "2026-08-11T11:00:00+00:00",
        "status_changed_at": "2026-08-11T11:00:00+00:00"
      }
    ],
    "pagination": { "page": 1, "per_page": 20, "total": 57, "last_page": 3 }
  }
}
```
- **Query performance**: batch-loaded, never N+1 — one query for the Booking page itself, one
  grouped aggregate query for `items_count`/`total` across every Booking on the page, one query for
  customer summaries, one query for currencies (`App\Support\Admin\AdminBookingPresenter::presentList()`).
  Query count does not grow with `per_page`.

## 2. Get Booking

- **HTTP method / route**: `GET /v1/admin/bookings/{booking}`
- Not ownership-scoped — any Admin may view any customer's Booking. A malformed or unknown
  `{booking}` uuid is `404` either way (never `403`), matching the customer-facing convention.
- **Success response** (abridged): same fields as List Bookings' summary shape, plus:
  - `customer.email`
  - `payment`: `{ status, amount, provider, reference, successful_at }` — trusted, already-settled
    fields only (`payment_statuses.code`, `confirmed_amount ?? requested_amount`, `provider_code`,
    `provider_transaction_reference`). **Never** `client_secret`, `checkout_snapshot`,
    `checkout_snapshot_hash`, `idempotency_key`, or any webhook/reconciliation field.
  - `location`, `appointment` — identical shape to `bookings-v1.md`'s customer contract.
  - `items[]`, each with `active_assignment` (the current active primary `technician_assignments`
    row, or `null`) and `assignment_history` (every assignment ever made on this item, oldest last —
    released rows included, never deleted, matching Phase 8A's own retention guarantee) shaped as
    `{ uuid, technician: { uuid, full_name, phone_number }, specialization: { code, name },
    assigned_at, released_at, release_reason, internal_note }`.
  - `total` — sum of every item's `line_total_amount`, identical computation to the customer
    presenter.
  - `refund_due` — `null` unless the Booking is currently `CANCELLED`; otherwise `{ percentage,
    amount, execution: "MANUAL" }`, read verbatim from the historical snapshot
    (`bookings.cancellation_refund_percentage` / `cancellation_refund_amount`) `CancelBookingAction`
    persisted at the moment of cancellation — never recomputed here. Identical to the customer read
    API's `refund_due` — see `bookings-v1.md`'s `refund_due` section for the full contract.
- **Query performance**: one query per section (status/currency/customer/location/slot/payment),
  one query for all Booking Items, one query for all their assignments (grouped in PHP) — never one
  query per item.

## 3. List Technicians

- **HTTP method / route**: `GET /v1/admin/technicians`
- **Query parameters** (all optional): `page`, `per_page` (same rules as Booking list),
  `status` (must be a real `technician_statuses.code`), `specialization` (must be a real
  `specializations.code`).
- **Ordering**: `technicians.full_name ASC, technicians.id ASC` — deterministic.
- **Success response** (abridged):
```json
{
  "success": true,
  "message": "Technicians retrieved successfully.",
  "data": {
    "technicians": [
      {
        "uuid": "...",
        "employee_code": "TECH_0042",
        "full_name": "Ahmed Technician",
        "phone_number": "+971550000000",
        "email": "ahmed@example.com",
        "status": "AVAILABLE",
        "is_phone_visible_to_customer": false,
        "internal_note": null,
        "active_assignments_count": 1,
        "specializations": [ { "code": "AC_REPAIR", "name": "AC Repair", "is_primary": true } ],
        "created_at": "2026-01-01T08:00:00+00:00"
      }
    ],
    "pagination": { "page": 1, "per_page": 20, "total": 12, "last_page": 1 }
  }
}
```
  The Admin sees the full record (`is_phone_visible_to_customer` gates a future customer-facing
  surface only — it never hides the phone number from an authenticated Admin here).
- **Query performance**: one query for the Technician page, one grouped query for specializations,
  one grouped query for active-assignment counts — never N+1.

## 4. List Technician Candidates

- **HTTP method / route**: `GET /v1/admin/booking-items/{bookingItem}/technician-candidates`
- Server-authoritative candidate list for one Booking Item, resolved from
  `booking_item → service → service_specializations` intersected with active
  `technician_specializations`, and `technician_statuses.is_assignable = 1` — the exact same data the
  real `assign()`/`reassign()` Action itself will check. Flutter never re-implements this matching.
- **Response**:
```json
{
  "success": true,
  "message": "Technician candidates retrieved successfully.",
  "data": {
    "item": { "uuid": "...", "status": "PENDING_ASSIGNMENT", "service": { "uuid": "...", "code": "AC_REPAIR", "name": "AC Repair" } },
    "requirement_configured": true,
    "candidates": [
      { "uuid": "...", "full_name": "...", "status": "AVAILABLE", "specializations": [...], "is_double_booked": false }
    ]
  }
}
```
  `requirement_configured: false` (empty `candidates`) means the service has no active
  `service_specializations` row at all yet — a catalog data-completeness gap, distinct from "no
  eligible technician currently holds it."
- **`is_double_booked` is advisory only.** It reflects a point-in-time overlap check against this
  Booking's appointment slot and can go stale between this read and the actual assign/reassign call.
  It is never a reservation or a guarantee — `AssignTechnicianToBookingItemAction` re-validates every
  eligibility rule itself, under a row lock, at write time, exactly as it always has
  (`backend/tests/Feature/Admin/AdminTechnicianReadTest.php::test_candidate_list_never_bypasses_final_assignment_validation`
  proves a listed candidate can still be correctly rejected by the real assign call).
- A malformed or unknown `{bookingItem}` uuid is `404`.

## 5. Assign Technician

- **HTTP method / route**: `POST /v1/admin/booking-items/{bookingItem}/assign-technician`
- **Request JSON**: `{ "technician_uuid": "...", "internal_note": "optional, 2-1000 chars" }` — only
  what the Admin actually chooses. `service_uuid`, `specialization_id`, `status`, `assigned_at`,
  `assigned_by_user_uuid`, `is_primary`, or any payment field submitted in the body is silently
  ignored — the Form Request has no such rule, and the wrapper Action never reads the raw request
  array (`backend/tests/Feature/Admin/AdminAssignmentTest.php::test_assignment_actor_is_derived_from_auth_admin_not_the_request_body`).
- Calls `AssignTechnicianToBookingItemAction::assign($bookingItemUuid, $technicianUuid,
  $actor->id, $internalNote)` — the actor is always the `auth.admin`-resolved user.
- **Domain outcome → HTTP mapping**:

  | Outcome | HTTP | Notes |
  |---|---|---|
  | `ASSIGNED` | `201` | New active assignment created |
  | `ALREADY_ASSIGNED` | `200` | Idempotent retry — same technician already active |
  | `ITEM_NOT_FOUND` | `404` | |
  | `TECHNICIAN_NOT_FOUND` | `404` | |
  | `ITEM_NOT_ELIGIBLE` | `409` | Wrong Booking Item status for `assign()` |
  | `ASSIGNED_TO_ANOTHER_TECHNICIAN` | `409` | Use reassign instead |
  | `TECHNICIAN_NOT_ELIGIBLE` | `409` | `technician_statuses.is_assignable = 0` |
  | `TECHNICIAN_DOUBLE_BOOKED` | `409` | Overlapping active assignment elsewhere |
  | `SERVICE_SPECIALIZATION_NOT_CONFIGURED` | `422` | Catalog data-completeness gap |
  | `SPECIALIZATION_MISMATCH` | `422` | Technician holds none of the service's required specializations |
  | `ACTOR_NOT_FOUND` / `ACTOR_NOT_AUTHORIZED` | `403` | Practically unreachable — `auth.admin` already gates the request |

- **Success response**: `{ "assignment": { "uuid", "booking_item_uuid", "technician": {...},
  "specialization": { "code", "name" }, "assigned_at", "internal_note" } }`.
- **Audit**: exactly one `admin_audit_logs` row (`action_code = TECHNICIAN_ASSIGNED`) on `ASSIGNED`
  only — never on `ALREADY_ASSIGNED` or any rejection (see "Audit Logging" below).

## 6. Reassign Technician

- **HTTP method / route**: `POST /v1/admin/booking-items/{bookingItem}/reassign-technician`
- **Request JSON**: `{ "technician_uuid": "...", "release_reason": "required, 2-500 chars",
  "internal_note": "optional" }`. `release_reason` is required because
  `technician_assignments.release_reason` is `NOT NULL` whenever a row is released
  (`chk_technician_assignments_release_data`) and the domain Action has no default of its own.
- Calls `AssignTechnicianToBookingItemAction::reassign(...)`. The previous assignment row is
  **released, never deleted** — it remains a permanent, queryable history row
  (`backend/tests/Feature/Admin/AdminAssignmentTest.php::test_reassignment_releases_the_old_assignment_without_deleting_it`).
- **Domain outcome → HTTP mapping**: identical to Assign above, plus `NO_ACTIVE_ASSIGNMENT → 409`
  (nothing active to replace).
- **Audit**: one `TECHNICIAN_REASSIGNED` row on `REASSIGNED` only.

## 7. Start Work

- **HTTP method / route**: `POST /v1/admin/booking-items/{bookingItem}/start-work`
- Technicians have no system accounts in BLUE V1 — the Admin acts **on the Technician's behalf**.
  `technician_uuid` in the request body is therefore a **claim**, syntactically validated here but
  never trusted as proof of identity: `StartTechnicianJobAction::start()` itself is what verifies it
  against the Booking Item's actual active `technician_assignments` row. This wrapper does not, and
  must not, change that contract.
- **Request JSON**: `{ "technician_uuid": "...", "reason": "optional, 2-500 chars" }`.
- **Domain outcome → HTTP mapping**:

  | Outcome | HTTP |
  |---|---|
  | `STARTED` | `200` |
  | `ALREADY_STARTED` | `200` (idempotent) |
  | `ITEM_NOT_FOUND` | `404` |
  | `ITEM_NOT_ELIGIBLE` | `409` |
  | `NO_ACTIVE_ASSIGNMENT` | `409` |
  | `ASSIGNMENT_MISMATCH` | `409` — the claimed technician is not the currently active one |
  | `ACTOR_NOT_FOUND` / `ACTOR_NOT_AUTHORIZED` | `403` |

- **Success response**: `{ "assignment": {...}, "status": "IN_PROGRESS" }`.
- **Audit**: one `BOOKING_ITEM_WORK_STARTED` row on `STARTED` only.

## 8. Complete Work

- **HTTP method / route**: `POST /v1/admin/booking-items/{bookingItem}/complete-work`
- Same contract as Start Work, wrapping `CompleteTechnicianJobAction::complete()`. `COMPLETED →
  200`, `ALREADY_COMPLETED → 200` (idempotent — `completed_at` is never rewritten on retry), all
  other outcomes map identically to Start Work.
- Accepts no completion evidence (photos/signatures/notes) — no schema support exists
  (`bookings-v1.md` "Completion Evidence... Gap classification: CAN WAIT").
- **Audit**: one `BOOKING_ITEM_WORK_COMPLETED` row on `COMPLETED` only.

---

## Booking-Level Lifecycle — not implemented, and why

Phase 9B does **not** expose any Booking-level (`bookings.status_id`) mutation endpoint — no
`assign`, `start`, or `complete` on a Booking itself, and no generic `PATCH /v1/admin/bookings/{id}`
status setter (verified by `backend/tests/Feature/Admin/AdminFinancialIsolationTest.php` and
`NoOperationalEndpointsExposedTest.php`).

This was attempted and deliberately reverted during this phase. BR-16 ("A Booking becomes Completed
only after all required Booking Items are completed") looks like a well-defined, exposable
operation, and an `AdminCompleteBookingAction` verifying it was built and tested. It was removed
after discovering a hard structural conflict:

- `App\Support\Booking\BookingStatusMachine::transitionToCompleted()` only accepts a Booking
  currently `IN_PROGRESS` — by design, not an oversight (see that class's own docblock).
- A Booking can only reach `IN_PROGRESS` via `TransitionBookingStatusAction::assign()` then
  `::start()` — `PAID → ASSIGNED → IN_PROGRESS`.
- `bookings-v1.md` ("Parent Booking lifecycle boundary") already documents that **exactly when** a
  Booking should move through `ASSIGNED`/`IN_PROGRESS` is an open, unresolved product decision, and
  this phase's own instructions are explicit: *"if requirements remain ambiguous about when the
  parent Booking should move, DO NOT invent aggregation. Leave those operations internal."*

Exposing Booking completion alone is therefore not actually possible without silently inventing an
answer to that exact open question (e.g. auto-walking the Booking through `ASSIGNED`/`IN_PROGRESS`
the moment every item is done) — which would be inventing aggregation under a different name. Rather
than ship an endpoint that either always fails (`409 INVALID_TRANSITION` on every real Booking,
since none will ever have been moved to `IN_PROGRESS`) or quietly invents the very rule this phase is
told not to invent, Booking-level lifecycle mutation is left exactly where Phase 7B/8B already left
it: internal, Action-only, no HTTP route.

**Classification: REQUIRED NOW — a product decision, not a schema change**, before any Booking-level
lifecycle endpoint (including completion) can be safely built:

> Should Booking-level status be automatically derived from Booking Item statuses (and if so, under
> exactly what rule — e.g. `IN_PROGRESS` the moment *any* item starts, or only once *all* items have
> started?), or does the Admin continue to trigger `assign`/`start`/`complete` on the Booking
> manually, as three separate, explicit operations, once that manual contract is itself approved?

Booking Item-level lifecycle (Assign/Reassign/Start/Complete Work, all above) is fully independent
of this open question and is unaffected by it.

## Audit Logging

Every **successful, state-changing** mutation writes exactly one `admin_audit_logs` row:

| Action | `action_code` | `entity_type` | `entity_identifier` |
|---|---|---|---|
| Assign (first time) | `TECHNICIAN_ASSIGNED` | `BOOKING_ITEM` | Booking Item uuid |
| Reassign | `TECHNICIAN_REASSIGNED` | `BOOKING_ITEM` | Booking Item uuid |
| Start Work | `BOOKING_ITEM_WORK_STARTED` | `BOOKING_ITEM` | Booking Item uuid |
| Complete Work | `BOOKING_ITEM_WORK_COMPLETED` | `BOOKING_ITEM` | Booking Item uuid |

`admin_user_id` is always the server-resolved `auth_user`, never a request field.
`new_values`/`old_values` hold only small, already-safe identifiers (technician/assignment uuids) —
never a full request body, never a secret. `ip_address`/`user_agent` are captured the same way
`AdminLoginAction` already does.

**Idempotent retries and rejections never write a second/any row** — audit is written only for the
one outcome that actually changed state (`ASSIGNED`, `REASSIGNED`, `STARTED`, `COMPLETED`), verified
directly (`AdminAssignmentTest::test_idempotent_retry_does_not_write_a_second_audit_row`,
`test_rejected_assignment_does_not_write_an_audit_row`).

**Atomicity**: the audit write is a second, immediate write made *after* the domain Action's own
transaction has already committed — not inside a shared transaction with it. This mirrors the
existing `CreateBookingFromSuccessfulPaymentAction`/`ProcessPaymentWebhookAction` precedent in this
codebase (a best-effort follow-up write deliberately kept outside the domain transaction, so a
problem in it can never roll back or block already-committed domain state). `admin_audit_logs` has
no foreign key or trigger tying it to the mutation it describes, so the schema does not require —
and this phase does not attempt to fake — single-transaction atomicity between the two. **Residual
gap, reported rather than hidden**: a process crash in the narrow window between the domain commit
and the audit insert would leave the mutation applied but unaudited. Classification: **CAN WAIT** —
no current requirement document calls for stronger guarantees than this, and closing it fully would
require either an outbox pattern or reworking the domain Actions' own transaction boundaries, neither
of which this phase's scope authorizes.

## Authorization

- Every route above is registered inside the `auth.admin` middleware group — verified directly
  (`NoOperationalEndpointsExposedTest::test_every_admin_operations_route_requires_auth_admin_middleware`).
- A Customer access token is rejected with the same generic `401` as any other invalid session.
- `ADMIN` and `SUPER_ADMIN` both pass identically (`AdminBookingReadTest`,
  `AdminAssignmentTest`, etc. exercise both).
- A role revoked, or an account deactivated, after login blocks the very next Admin Operations
  request — `auth.admin` re-checks both from the database on every call, exactly as Phase 9A already
  guarantees (`AdminAssignmentTest::test_admin_role_removed_after_login_blocks_assignment`,
  `test_inactive_admin_account_blocks_assignment`).

## Transaction / Locking

No Admin wrapper Action opens an outer transaction around a domain Action, holds a lock while
serializing the JSON response, or makes an external/network call inside a transaction. Every lock
still originates inside the unmodified Phase 7B/8A/8B Actions, in their already-documented order
(`booking_items → technicians` for assign/reassign; `booking_items` alone for start/complete).

## Financial Isolation

No Admin Operations endpoint ever writes `payment_attempts`, `checkout_snapshot`, or any Booking Item
pricing-snapshot column, and none makes a Stripe call — verified both by a full assign → start →
complete flow asserting byte-for-byte payment/pricing survival, and a static source-scan asserting no
`app/Actions/Admin/*` or `app/Http/Controllers/Api/V1/Admin/*` file references Stripe or
`PricingEngine` (`AdminFinancialIsolationTest`).

## Pagination

Both list endpoints (Bookings, Technicians) share one shape: `data.pagination = { page, per_page,
total, last_page }`. Default `per_page` is 20; the hard maximum is 100 (`422` above that, never
silently clamped). Neither endpoint can return an unbounded result set in one response.

## Not implemented in Phase 9B

- **Booking-level lifecycle mutation** (see "Booking-Level Lifecycle" above) — **REQUIRED NOW**, a
  product decision.
- **Technician CRUD** (create/update technician records). `docs/05-system-requirements/
  04-role-and-access-control-requirements.md` does list "Add or update technician information" as
  an Admin permission, and the schema (`technicians`) is clean enough to support it — but building it
  correctly also touches `technician_specializations` (which specializations does a *new* technician
  hold on day one? no endpoint shape for that is defined anywhere in this phase's instructions), and
  this phase's own guidance is explicit that a read/list endpoint plus the operational assignment
  APIs "may be sufficient for this phase" when CRUD rules are not fully defined. **Classification:
  CAN WAIT** — Technicians are provisioned directly (matching how Admin accounts are already
  provisioned per Phase 9A), and every endpoint that actually *uses* a Technician record (list,
  candidates, assign, reassign, start, complete) is fully built.
- **Booking cancellation / refunds** — out of scope by explicit instruction; belongs to a dedicated
  later phase once a Cancellation/Refund policy is defined.
- **Technician-facing API** — still blocked on the Technician Authentication/API phase named in
  `bookings-v1.md`; unaffected by this phase.
- **Completion evidence** (photos/signatures/reports) — no schema support (`bookings-v1.md`
  "Completion Evidence"). **CAN WAIT**.

## Admin Service Contract management (BLUE V1 Phase 10E)

`GET /v1/admin/contracts`, `GET /v1/admin/contracts/{contract}`,
`POST /v1/admin/contracts/{contract}/approve`, `POST /v1/admin/contracts/{contract}/send-for-acceptance`,
`POST /v1/admin/contracts/{contract}/suspend`, `POST /v1/admin/contracts/{contract}/cancel` are
documented in full in `docs/api-contracts/contracts-v1.md` (request/response shapes, the Contract
state machine, idempotency, and audit logging) rather than duplicated here. They follow every
convention this document already established: `auth.admin`-gated, thin Controller → Action →
`AdminContractPresenter` transport with no business logic re-implemented at the route layer, and
`AdminAuditLogger::record()` called exactly once per real state transition (never for an idempotent
no-op), mirroring `AdminAssignTechnicianAction`'s pattern precisely.

`App\Http\Controllers\Api\V1\Admin\Contract\*` and `App\Actions\Admin\Contract\*` never touch a
`bookings`, `payment_attempts`, or `technician_assignments` row directly — Contract lifecycle
management is fully independent of Booking/Payment/Technician management, the same separation this
document already keeps between Booking reads and Technician operations.

## Admin Payments / Contract Billing visibility (BLUE V1 Phase B5)

Read-only, global (cross-customer) operational visibility into what already happens financially in
BLUE — never a second payment/billing implementation. `App\Actions\Payment\*` (one-off Payment
Attempts) and `App\Actions\Contract\Billing\*` (recurring Contract subscription billing) remain the
only place any of this state is ever written; nothing under `App\Actions\Admin\Payment\*` or
`App\Actions\Admin\ContractBilling\*` calls a payment/billing gateway, transitions
`PaymentAttemptStateMachine`, or writes a `service_contract_billings` column. **No refund, retry,
status-override, or any other financial mutation exists in this module** — inspection confirmed no
secure, already-shipped Admin-domain Action for any such mutation exists, so this phase is
deliberately monitoring-only, per BLUE's small-trusted-operator-team product shape.

### Endpoints

| Feature | Method | Route | Capability |
|---|---|---|---|
| List Payments | GET | `/v1/admin/payments` | `payments.view` |
| Get Payment | GET | `/v1/admin/payments/{payment}` | `payments.view` |
| List Contract Billings | GET | `/v1/admin/contract-billings` | `billing.view` |
| Get Contract Billing | GET | `/v1/admin/contract-billings/{billing}` | `billing.view` |

All four sit behind `auth.admin` + `admin.capability:<code>`, exactly like every other route in this
document. `payments.view`/`billing.view` are new BLUE V1 Phase B5 `admin_permissions` rows, granted to
`ADMIN` the same way every other capability in this document already is (`SUPER_ADMIN` needs no row —
the centralized `AdminAuthorizationService` override already covers it). No new middleware, no new
authorization mechanism, no organizational/department layer was introduced.

### List Payments — `GET /v1/admin/payments`

`App\Actions\Admin\Payment\AdminListPaymentsAction` / `App\Support\Admin\AdminPaymentPresenter`.
Unlike `App\Actions\Payment\GetPaymentAction` (ownership-scoped to the authenticated customer), this
is never scoped to one customer. Query filters — all optional, all matched against an existing
column/index on `payment_attempts`: `status` (must be a real `payment_statuses.code`), `checkout_reference`
(exact match), `customer_uuid` (exact match via `carts.customer_user_id`), `provider_transaction_reference`
(exact match). `page`/`per_page` follow the exact same convention as every other Admin list endpoint
(default 20, hard max 100, `data.pagination = { page, per_page, total, last_page }`).

Each row: `uuid`, `checkout_reference`, `status`, `customer { uuid, full_name, phone_number }`,
`requested_amount`, `confirmed_amount`, `currency { code, symbol, decimal_places }`, `provider`,
`booking_uuid` (the linked Booking's uuid if one was created from this payment, else `null`),
`created_at`, `status_changed_at`.

### Get Payment — `GET /v1/admin/payments/{payment}`

Same detail fields as the list, plus: `customer.email`, `booking { uuid, booking_number, status }`,
`provider_session_reference`, `provider_transaction_reference`, `provider_status_code`,
`payment_method_type`, `failure_code`, `failure_message`, `requires_reconciliation`,
`reconciliation_reason_code`, `reconciled_at`, `expires_at`, `successful_at`, `finalized_at`, and
`recent_webhook_events` (up to the 20 most recent `payment_webhook_events` rows for this payment,
each `{ provider_event_id, event_type, status, received_at, processed_at, last_error_code,
last_error_message }`).

**Never returned**: `checkout_snapshot`/`checkout_snapshot_hash` (the frozen cart-price snapshot and
its integrity hash), `idempotency_key`, `client_secret`/`publishable_key` (Stripe PaymentSheet
initiation tokens — only ever meaningful once, to the customer, at creation time), or any raw
`payment_webhook_events.payload_hash` bytes. `provider_session_reference`/
`provider_transaction_reference` are Stripe object identifiers, not secrets — the same posture this
document already established for `stripe_subscription_id` etc. below.

### List Contract Billings — `GET /v1/admin/contract-billings`

`App\Actions\Admin\ContractBilling\AdminListContractBillingsAction` / `App\Support\Admin\
AdminContractBillingPresenter`. Recurring subscription billing state, distinct from one-off Payments
above. Filters: `status` (a real `service_contract_billing_statuses.code`), `contract_number` (exact
match via `service_contracts.contract_number`), `customer_uuid` (exact match via
`service_contracts.customer_user_id`). Same pagination convention as every list endpoint in this
document.

Each row: `uuid`, `contract { uuid, contract_number }`, `customer { uuid, full_name, phone_number }`,
`status`, `billing_interval`, `recurring_amount`, `currency`, `current_period_end`, `past_due_since`,
`cancel_at`, `created_at`.

### Get Contract Billing — `GET /v1/admin/contract-billings/{billing}`

Looked up by the billing row's own uuid (not the Contract's) — reachable independently, and also
linked from the existing Phase 10E Contract detail response's `billing` key once that Contract has
been approved. Reuses `App\Support\Contract\Billing\ContractBillingPresenter::presentRow()` — the
exact same admin-safe field mapping `AdminContractPresenter::detail()`'s embedded `billing` key
already used before this phase, now additionally including `billing_suspended_at`,
`provider_cancellation_requested_at`, `provider_cancellation_last_attempt_at`, and
`provider_cancellation_attempt_count` (all pre-existing `service_contract_billings` columns that
were simply not yet surfaced) — plus `contract { uuid, contract_number, status }`, `customer`, and
`recent_webhook_events` (same shape as Payments above, sourced from
`service_contract_billing_webhook_events`).

**Never returned**: any Stripe secret/webhook-signing key, or a raw webhook payload — this table
never stores one in the first place (only `payload_hash`, itself never returned).

### Why a new Admin read layer, not the existing customer-facing routes

`GET /v1/payments/{payment}` is deliberately ownership-scoped to the authenticated customer
(`App\Actions\Payment\GetPaymentAction`) and its presenter is deliberately minimal for a customer
audience — neither is "Admin-safe" merely because the route already exists. No customer-facing
Contract Billing list/detail route exists at all. Both gaps are closed here with the smallest
additive layer: new thin Actions/Controllers/Presenters over the exact same tables, reusing every
existing status enum, currency-formatting convention, and UUID-safety rule already established by
`AdminBookingPresenter`/`AdminContractPresenter`. No schema change was required or made — every field
above already existed on `payment_attempts` / `service_contract_billings` / their webhook-event
tables.

## Admin Customers / Properties visibility (BLUE V1 Phase B6)

Read-only, global (cross-customer) operational visibility into BLUE's existing Customer/Property
domain — never a second implementation. `App\Actions\Auth\RegisterCustomerAction`, `App\Actions\
Profile\*`, `App\Actions\Property\*`, and the account-deletion pipeline (`App\Support\Auth\
AccountDeletionRequestStore` and friends) remain the only place any of this state is ever written.
Nothing under `App\Actions\Admin\Customer\*` or `App\Actions\Admin\Property\*` mutates a `users`,
`customer_profiles`, or `customer_properties` row — **no account/property mutation exists in this
module** (no edit, no deactivate, no archive, no force-delete/approve-deletion), since no secure,
already-shipped Admin-domain Action for any such mutation exists. This module is deliberately
monitoring-only, per BLUE's small-trusted-operator-team product shape — no organizational/company/
department layer was introduced anywhere in it.

### Endpoints

| Feature | Method | Route | Capability |
|---|---|---|---|
| List Customers | GET | `/v1/admin/customers` | `customers.view` |
| Get Customer | GET | `/v1/admin/customers/{customer}` | `customers.view` |
| Get Property | GET | `/v1/admin/properties/{property}` | `customers.view` |

All three sit behind `auth.admin` + `admin.capability:customers.view` — a single capability covers
both Customer and Property reads, since a Property is always Customer-owned and inspecting one is
naturally part of Customer visibility (no separate `properties.view` was added). `customers.view` is
a new BLUE V1 Phase B6 `admin_permissions` row, granted to `ADMIN` the same way every other
capability in this document already is (`SUPER_ADMIN` needs no row — the centralized
`AdminAuthorizationService` override already covers it).

There is no global "list every Property across every customer" route — a Property is always reached
from its owning Customer's detail response (below), matching how many Properties a single customer
realistically has (the same unpaginated-per-customer convention `App\Actions\Property\
ListPropertiesAction` already uses for the customer's own "my properties" screen).

### List Customers — `GET /v1/admin/customers`

`App\Actions\Admin\Customer\AdminListCustomersAction` / `App\Support\Admin\AdminCustomerPresenter`.
Only a `users` row that also has a `customer_profiles` row is ever listed — a pure-Admin account
that never registered as a customer is excluded by the inner join, never returned as a "Customer".
Query filters — all optional: `account_status` (a real `user_account_statuses.code`), `phone_number`
(exact match), `email` (exact match), `customer_uuid` (exact match), `search` (partial match against
`user_profiles.full_name`). `page`/`per_page` follow the exact same pagination convention as every
other Admin list endpoint (default 20, hard max 100).

Each row: `uuid`, `full_name`, `phone_number`, `email`, `account_status`, `phone_verified`,
`area { name, city_name }`, `active_properties_count` (batched, never a query per row),
`deletion_pending` (batched), `last_login_at`, `created_at`.

### Get Customer — `GET /v1/admin/customers/{customer}`

Reuses the exact canonical field sources `App\Actions\Profile\GetProfileAction` already established
(account status, location, property relationship) rather than re-deriving them. Returns: identity
(`uuid`, `full_name`, `phone_number`, `email`), account state (`account_status`, `phone_verified`/
`phone_verified_at`, `last_login_at`, `created_at`, `updated_at`, `deleted_at`), `account_deletion
{ status: "NONE"|"PENDING", requested_at }` (reusing `App\Support\Auth\AccountDeletionRequestStore::
findPending()` — the exact same source `GET /v1/auth/account-deletion` uses for the customer's own
status), `location { area_name, city_name, country_name }`, `property_relationship { code, name }`,
the customer's full `properties` array (each presented via `PropertyPresenter::present()` — see
below), and a small `activity { bookings_count, payments_count, contracts_count,
properties_count }` summary (four bounded `COUNT` queries against already-indexed foreign keys, not
a dashboard aggregation — B10 owns that).

**Never returned**: `password_hash`, `refresh_token_hash`, any OTP/session/refresh-token/WebAuthn
material, or `customer_profiles.stripe_customer_id` (an internal billing-provider linkage with no
Admin operational use here — the safe, already-reviewed Stripe identifiers a Contract's *billing*
carries are exposed separately, on the existing Contract Billing detail page, not duplicated here).

### Get Property — `GET /v1/admin/properties/{property}`

`App\Actions\Admin\Property\AdminGetPropertyAction` — unlike `App\Actions\Property\GetPropertyAction`,
never ownership-scoped to an authenticated caller (there is no "authenticated owner" concept for an
Admin request). Reuses `App\Support\Property\PropertyPresenter::present()` **verbatim** — that
presenter was already pure, ownership-independent presentation (it never reads or requires
`customer_user_id`), so no separate `AdminPropertyPresenter` was created. Returns `property` (the
exact `PropertyPresenter::present()` shape: label, relationship/property type, area, address fields,
`is_active`, timestamps), `customer { uuid, full_name, phone_number, email }`, and `contracts` (the
same lightweight Contract summary `GetPropertyAction` already returns for the customer's own
equivalent screen — `uuid`, `contract_number`, `status`, `starts_at`, `ends_at` — never a duplicate
of the full Contract detail page).

There is deliberately no Property → Bookings link: `bookings`/`booking_locations` store a
point-in-time location *snapshot*, not a live foreign key to `customer_properties`, so no such
relationship exists in the schema to query.

### Operational links

The Customer detail page links into the existing B2/B4/B5 list pages using `?customer_uuid=...` —
every one of `AdminListBookingsAction`, `AdminListContractsAction`, `AdminListPaymentsAction`, and
`AdminListContractBillingsAction` already accepts that exact filter (see their own sections above),
so no new query parameter was invented anywhere to make this work.

## Admin Support Requests / Support Messages (BLUE V1 Phase B7)

There is no customer-facing Support implementation anywhere in this codebase — only the
`support_requests` / `support_messages` / `support_request_statuses` schema is provisioned. This
phase is therefore the *first* application code over that schema, not an Admin layer added on top of
an existing customer feature, and there is no existing customer-facing behavior to regress.

`App\Actions\Admin\Support\*` and `App\Support\Admin\AdminSupportRequestPresenter` are the sole
Support write/read path. No new schema, table, or column was added — `NO SCHEMA CHANGE` per BLUE V1
standing policy.

### Endpoints

| Feature | Method | Route | Capability |
|---|---|---|---|
| List Support Requests | GET | `/v1/admin/support-requests` | `support.view` |
| Get Support Request | GET | `/v1/admin/support-requests/{supportRequest}` | `support.view` |
| Send Support Message | POST | `/v1/admin/support-requests/{supportRequest}/messages` | `support.manage` |

`support.view`/`support.manage` are new BLUE V1 Phase B7 `admin_permissions` rows, granted to `ADMIN`
the same way every other capability in this document already is (`SUPER_ADMIN` needs no row). The
split mirrors the existing `technicians.view`/`technicians.assign` convention exactly: `support.view`
covers both reads; `support.manage` covers the one Support mutation this phase implements.

### List Support Requests — `GET /v1/admin/support-requests`

`App\Actions\Admin\Support\AdminListSupportRequestsAction` / `AdminSupportRequestPresenter::
presentList()`. Deterministic ordering (`created_at DESC, id DESC`), bounded page size (default 20,
hard max 100) — the same pagination convention as every other Admin list endpoint. Query filters —
all optional: `status` (a real `support_request_statuses.code`), `customer_uuid`, `booking_uuid`,
`assigned_admin_uuid` (each an exact-match UUID; malformed input yields an empty result rather than a
500), `unassigned` (boolean — `assigned_admin_user_id IS NULL`), `search` (partial match against
`subject`).

Each row: `uuid`, `request_number`, `subject`, `status`, `customer { uuid, full_name, phone_number }`,
`booking { uuid, booking_number }` (nullable), `assigned_admin { uuid, full_name }` (nullable),
`created_at`, `status_changed_at`. All related data is batch-loaded (`whereIn` + `keyBy`) — never a
query per row.

### Get Support Request — `GET /v1/admin/support-requests/{supportRequest}`

`App\Actions\Admin\Support\AdminGetSupportRequestAction` / `AdminSupportRequestPresenter::detail()`.
Malformed or unknown UUIDs return a generic 404, never leaking which case applied. Returns the full
Support Request (`uuid`, `request_number`, `subject`, `status`, `status_changed_at`, `resolved_at`,
`closed_at`, `created_at`, `updated_at`), `customer { uuid, full_name, phone_number, email }`,
`booking { uuid, booking_number, status }` (nullable — no booking was ever linked), `assigned_admin
{ uuid, full_name }` (nullable, read-only — see "Assignment" below), and the full `messages` array
(oldest first — a Support conversation is realistically a handful of messages, so this is
unpaginated, matching the existing unpaginated-child-collection convention used for a Contract's
`status_history`/`covered_services`).

Each message: `uuid`, `message_body`, `created_at`, `sender { uuid, full_name, type }`, where `type`
is one of `CUSTOMER` / `ADMIN` / `UNKNOWN`, derived **server-side** from `roles`/`user_roles` — never
guessed client-side and never falsely labeled: `CUSTOMER` only if the sender is literally the request's
own `customer_user_id`; `ADMIN` only if the sender currently holds an active `ADMIN`/`SUPER_ADMIN`
role; otherwise `UNKNOWN` (e.g. a sender whose role was since revoked).

**CRITICAL — stored XSS**: `message_body` is untrusted, user-authored text (from both Customers and
Admins). The frontend (`resources/js/admin/support/show.js`) renders every message exclusively via
`textContent`, never `innerHTML`.

### Send Support Message — `POST /v1/admin/support-requests/{supportRequest}/messages`

`App\Actions\Admin\Support\AdminSendSupportMessageAction`. Body: `{ message_body: string }`
(`min:1`, `max:5000`, trimmed). The authenticated Admin is **always** the sender — taken from the
authorization context, never from client-supplied input, so a request cannot spoof a different
sender. On success (`201`), inserts one `support_messages` row, writes one `SUPPORT_MESSAGE_SENT`
`admin_audit_logs` row (identifiers only — `{ message_uuid }` — the message text itself is never
logged), and returns the full updated Support Request detail (same shape as the GET above) so the
frontend always re-renders authoritative server state rather than patching local state. This
endpoint **never** writes `status_id`, `status_changed_at`, `resolved_at`, or `closed_at` — a reply
does not change the request's lifecycle status.

### Status transitions and assignment — not implemented, and why

BLUE's Support requirements (`docs/03-features-and-requirements/09-human-support.md`) confirm the
`OPEN` → `IN_PROGRESS` → `RESOLVED` → `CLOSED` status vocabulary and that Admins are expected to
eventually update status and close requests, but neither that document nor the schema defines the
exact transition graph (which statuses may move to which others, what triggers each move, whether a
`CLOSED` request can reopen) or any assignment rule (who may assign, whether reassignment is allowed,
whether assignment requires a particular role). Per BLUE V1 standing policy, ambiguous lifecycle
policy is never invented — it is reported and deferred instead of shipped as a guess.

Consequently, Phase B7 deliberately implements **read-only** status and assignment display only:
- `status` is shown as a badge; there is no status-change endpoint, and neither the frontend nor any
  Action ever writes `support_requests.status_id`/`status_changed_at`/`resolved_at`/`closed_at`.
- `assigned_admin` is shown as read-only text ("Unassigned" or the assigned Admin's name); there is no
  assignment/reassignment endpoint.

No generic `PATCH /v1/admin/support-requests/{supportRequest}` was added specifically to avoid
papering over this gap with an implicit, unreviewed lifecycle policy. A future phase can introduce an
explicit `AdminUpdateSupportRequestStatusAction`/`AdminAssignSupportRequestAction` (mirroring the
explicit-Action-per-transition convention `App\Support\Contract\ContractStatusMachine` already
establishes for Contracts) once the transition/assignment rules are confirmed.

## Admin Service Catalog (Categories/Services) management (BLUE V1 Phase B8)

Admin management of the exact Service Catalog the mobile app already reads through
`App\Actions\ServiceCatalog\ListServiceCategoriesAction` / `ListCategoryServicesAction` /
`GetServiceDetailsAction` (`GET /v1/service-categories`, `GET /v1/service-categories/{category}/
services`, `GET /v1/services/{slug}`) — never a parallel Service Catalog. `App\Actions\Admin\
Service\*` and `App\Support\Admin\AdminServiceCategoryPresenter`/`AdminServicePresenter` read and
mutate the same canonical `service_categories`/`services` rows those public endpoints already read;
no new schema, table, or column was added — `NO SCHEMA CHANGE` per BLUE V1 standing policy.

### Endpoints

| Feature | Method | Route | Capability |
|---|---|---|---|
| List Service Categories | GET | `/v1/admin/service-categories` | `services.view` |
| Get Service Category | GET | `/v1/admin/service-categories/{category}` | `services.view` |
| Update Service Category metadata | PATCH | `/v1/admin/service-categories/{category}` | `services.manage` |
| Activate Service Category | POST | `/v1/admin/service-categories/{category}/activate` | `services.manage` |
| Deactivate Service Category | POST | `/v1/admin/service-categories/{category}/deactivate` | `services.manage` |
| List Services (global) | GET | `/v1/admin/services` | `services.view` |
| Get Service | GET | `/v1/admin/services/{service}` | `services.view` |
| Update Service metadata | PATCH | `/v1/admin/services/{service}` | `services.manage` |
| Activate Service | POST | `/v1/admin/services/{service}/activate` | `services.manage` |
| Deactivate Service | POST | `/v1/admin/services/{service}/deactivate` | `services.manage` |

`services.view`/`services.manage` are new BLUE V1 Phase B8 `admin_permissions` rows, granted to
`ADMIN` the same way every other capability in this document already is (`SUPER_ADMIN` needs no row).
One capability pair covers both Categories and Services (mirroring the `customers.view` precedent of
collapsing a closely related pair of record types) rather than adding four separate capabilities.

`service_categories.id` is a plain unsigned int, not a binary(16) UUID (see
`database/blue_v1_schema.sql`) — the same identifier the existing public `GET /v1/service-categories`
already returns, so it is presented as-is here too, never re-encoded. `services.id` is a binary(16)
UUID and is always presented as the standard UUID string, never raw bytes.

### List Service Categories — `GET /v1/admin/service-categories`

`App\Actions\Admin\Service\AdminListServiceCategoriesAction`. Unlike the customer-facing
`ListServiceCategoriesAction` (active-only, for the mobile Home screen), Admin sees every Category
regardless of `is_active` by default — an operator needs to see what is currently hidden from the
app. Optional `is_active` filter narrows to one state. Only 18 categories exist in BLUE V1's seed
data, so this is deliberately a small, unpaginated list. Each row: `id`, `code`, `name`,
`description`, `display_order`, `is_active`, `services_count` (batched, never a query per row),
`created_at`, `updated_at`.

### Get Service Category — `GET /v1/admin/service-categories/{category}`

Same fields as the list row, plus the category's full `services` array (unpaginated — a Category
realistically has a handful of Services, matching the existing per-category "all services" shape
`ListCategoryServicesAction` already returns for the customer's own equivalent screen). Each nested
Service: `uuid`, `code`, `name`, `is_active`, `display_order`.

### List Services (global) — `GET /v1/admin/services`

`App\Actions\Admin\Service\AdminListServicesAction`. Unlike the customer-facing
`ListCategoryServicesAction` (one active Category's active Services only), this sees every Service
across every Category regardless of its own or its Category's `is_active` state. Deterministic
ordering (`updated_at DESC, id DESC`) and a bounded page size (default 20, hard max 100 — the same
pagination convention as every other Admin list endpoint) make this safe against an unbounded table.
Query filters — all optional: `category_id` (exact match), `is_active` (boolean), `search` (partial
match against `name`).

Each row: `uuid`, `code`, `name`, `is_active`, `display_order`, `category { id, name }`,
`capabilities` (active capability rows, see below), `updated_at`. All related data is batch-loaded
(`whereIn`) — never a query per row.

### Get Service — `GET /v1/admin/services/{service}`

`App\Actions\Admin\Service\AdminGetServiceAction` / `AdminServicePresenter::detail()`. Unlike the
customer-facing `GetServiceDetailsAction` (which only ever shows active options/choices to a
shopper), this shows every row regardless of its own `is_active` flag — an operator needs to see what
is currently hidden from the app. Returns the full Service (`uuid`, `code`, `slug`, `name`,
`short_description`, `description`, `is_active`, `display_order`, `created_at`, `updated_at`),
`category { id, code, name, is_active }`, and four read-only sections:

- **`capabilities`** — every linked `service_capability_types` row (`code`, `name`, `description`).
- **`specializations`** — every linked `specializations` row (`code`, `name`, `is_primary`,
  `is_active`).
- **`options`** — every `service_options` row (including inactive ones), each with its numeric or
  selection rule and choices, mirroring `GetServiceDetailsAction`'s exact option/rule/choice shape.
- **`media`** — every `service_media` row (including inactive ones): `storage_key`, `mime_type`,
  `alt_text`, `caption`, dimensions, `is_primary`, `is_active`.
- **`pricing`** — `{ currency_code, scheme_versions: [{ id, status, effective_from, effective_to }] }`
  for BLUE's default currency, via `App\Support\Pricing\PricingSchemeRepository::schemeVersionsFor()`
  — which `pricing_scheme_versions` exist and their publish status, never a rule evaluation and never
  editable here. See "Pricing boundary with B9" below.

### What is mutable, and why the rest is deliberately read-only

Only **display metadata** and **is_active** are mutable, on both Categories and Services:

- **Update metadata** (`PATCH`) — Category: `name`, `description`, `display_order`. Service: `name`,
  `short_description`, `description`, `display_order`. Both are a full replace, not a partial patch,
  so a caller cannot accidentally leave a stale value in place by omitting a field. `code`/`slug`
  are never editable: nothing in this codebase reads a Category's `code` programmatically today, but
  it is the one stable identifier the public catalog contract exposes; `slug` is the customer-facing
  `GET /v1/services/{slug}` lookup key (renaming it would break any existing deep link).
  Re-categorizing a Service (`category_id`) is likewise out of scope — a structural change with no
  established safety story, not a "safe metadata" edit.
- **Activate/Deactivate** (`POST .../activate`, `.../deactivate`) — toggles `is_active`. Idempotent:
  activating an already-active row (or deactivating an already-inactive one) is a safe no-op and
  writes no audit row.

Options, Capabilities, Specializations, Media, and Zones remain **read-only** (or, for Zones, entirely
absent from the Service detail page) after inspecting how each is actually consumed elsewhere:

- **`service_capabilities`** gates real Cart/Contract eligibility (`App\Support\Pricing\
  ServiceCapabilities::has()`, checked by `AddCartItemAction` for `CART_ELIGIBLE` and
  `RequestContractAction` for `SUBSCRIPTION`) — toggling one is a structural product-behavior change,
  not display metadata.
- **`service_specializations`** directly determines technician-candidate eligibility for a booking
  item (`App\Actions\Admin\Technician\AdminListTechnicianCandidatesAction` intersects it with
  `technician_specializations`) — an uninformed edit could silently make a Service unassignable or
  eligible for the wrong technicians.
- **`service_options`/`service_option_choices`** (and their numeric/selection rules) are validated by
  `App\Support\Cart\CartSelectionValidator` and priced by the flexible pricing engine. Live
  `cart_item_option_selections` rows carry a hard FK to `service_options`/`service_option_choices`
  (`ON DELETE RESTRICT`), while completed `booking_item_option_selections` instead snapshot every
  field at booking time — so a Cart in progress depends on the option continuing to mean what it
  meant when it was added, and no safe "what happens to an in-progress Cart if this option changes"
  policy exists yet.
- **`service_media`** has a real storage schema (`storage_key`/`file_size_bytes`) but nothing in this
  codebase writes to it yet — there is no existing secure upload pipeline to reuse, and BLUE V1
  standing policy is to never invent one solely for this Admin page.
- **`service_zones`/`service_zone_areas`** has **no relationship to `services` at all** in the schema
  — it maps `areas` to a `service_zones` row, consumed only as a pricing-rule context dimension
  (`SERVICE_ZONE`) by `App\Support\Checkout\CheckoutContextResolver`. There is no "which zones is this
  Service available in" data to show or mutate, so no Zones section exists on the Service detail page
  at all — adding one would misrepresent the data model.

Each of these remains a candidate for an explicit, reviewed mutation Action in a future phase once its
safety story is confirmed — never a generic PATCH covering all of them at once.

### Active/Inactive semantics

- **Category deactivation** removes it from `GET /v1/service-categories` and makes `GET
  /v1/service-categories/{category}/services` 404 (both filter on `is_active = 1`). It does **not**
  cascade to deactivate its individual Services — no cascading deactivation was invented. It also does
  **not** retroactively affect an individual Service's own by-slug detail page: `GET /v1/services/
  {slug}` only checks the Service's own `is_active`, never its Category's (`GetServiceDetailsAction`
  never joins on the Category's `is_active`) — a still-active Service under a newly-deactivated
  Category therefore remains individually reachable by slug. This is pre-existing behavior, not
  introduced by B8, and is covered by a regression test.
- **Service deactivation** removes it from `GET /v1/service-categories/{category}/services` and makes
  `GET /v1/services/{slug}` 404. It only stops **new** Cart additions (`AddCartItemAction`/
  `UpdateCartItemAction` both require `is_active = 1` at the moment a selection is made). It does
  **not** remove the Service from a Cart it is already in (no live re-validation exists at checkout),
  and does **not** affect any existing Booking or Contract — `booking_item_option_selections`
  snapshots every field at booking time, and neither Booking nor Contract rows carry a live dependency
  on `services.is_active`.
- Both toggles are idempotent no-ops when the row is already in the target state (no audit row is
  written when nothing actually changes).

### Audit logging

`SERVICE_CATEGORY_UPDATED`, `SERVICE_CATEGORY_ACTIVATED`, `SERVICE_CATEGORY_DEACTIVATED`,
`SERVICE_UPDATED`, `SERVICE_ACTIVATED`, `SERVICE_DEACTIVATED` — one row per successful, state-changing
mutation. A metadata update logs only `name` and `display_order` in `new_values`/`old_values`, never
`description` (never logging large description blobs). Activate/deactivate log no `new_values` at all
beyond the action itself; an idempotent no-op writes no audit row.

### Pricing boundary with B9

This phase deliberately shows only which `pricing_scheme_versions` exist for a Service and their
publish status (`DRAFT`/`PUBLISHED`/`RETIRED`, effective dates) — read-only, via the existing
`PricingSchemeRepository`. It never creates, edits, or publishes a pricing rule, and never evaluates
one (no `PricingEngine` call exists anywhere in `App\Actions\Admin\Service\*` or `App\Http\
Controllers\Api\V1\Admin\Service\*` — enforced by `AdminFinancialIsolationTest`). Full pricing-rule
authoring/publishing (`pricing_rules`, `pricing_rule_conditions`, `pricing_rule_tiers`) is BLUE V1
Phase B9's exclusive domain.

### Frontend

Sidebar "Services" points at the Category list (`/admin/service-categories`) — the natural top-level
browsing structure for what shows up in the mobile app — rather than getting a separate "Categories"
sidebar entry; the global cross-category Services list (`/admin/services`) is reached from there via a
"View all Services" link, per the "keep navigation simple" guidance. A Category detail page
(`/admin/service-categories/{category}`) lists its Services; a Service detail page
(`/admin/services/{service}`) shows Overview/Options/Capabilities/Specializations/Media/Pricing as
read-only cards alongside the metadata-edit form and the Activate/Deactivate control. Every mutation
reloads the authoritative server response afterward rather than patching local state; Service/Category
names/descriptions are rendered exclusively via `textContent`/`createElement`, never `innerHTML`.

## Admin Pricing Management (BLUE V1 Phase B9)

Admin authoring of the exact canonical `pricing_scheme_versions`/`pricing_rules`/
`pricing_rule_condition_groups`/`pricing_rule_conditions`/`pricing_rule_condition_values`/
`pricing_rule_tiers` rows `App\Support\Pricing\PricingEngine` already reads for every real customer
price calculation (service-details preview, Cart, Checkout) — **there is only one pricing engine in
BLUE**. `App\Actions\Admin\Pricing\*` write that canonical configuration; nothing in this phase
re-implements rule evaluation, condition matching, tier math, or scheme selection. No new schema,
table, or column was added — `NO SCHEMA CHANGE`.

### Existing pricing architecture (as discovered, not assumed)

- **`pricing_scheme_versions`** — one row per (service, currency) pricing configuration revision.
  `status` is a plain string (`DRAFT`/`PUBLISHED`/`RETIRED`, enforced by a CHECK constraint — there is
  no PHP enum for it, unlike the calculation-result `PricingStatus` enum). `effective_from`/
  `effective_to` may only be null while `status = 'DRAFT'` (`chk_pricing_scheme_versions_requires_from`).
  A generated `open_ended_marker` column plus a unique key
  (`service_id, currency_id, open_ended_marker`) means **at most one open-ended PUBLISHED version can
  exist per service+currency at a time** — enforced by the schema itself, not application code. A
  service may have any number of DRAFT versions simultaneously; nothing in the schema limits this.
- **`App\Support\Pricing\PricingSchemeSelector`** — pure selection of the one currently-effective
  PUBLISHED version for (service, currency, evaluation time): `status = PUBLISHED` and
  `effective_from <= at < effective_to` (or open-ended). Both bounded and future-dated PUBLISHED
  versions are supported.
- **`App\Support\Pricing\SchemePublishValidator`** — the single, authoritative publish-readiness gate.
  `validate()` checks: at least one rule exists; no duplicate rule priorities; every referenced
  `service_option` belongs to this scheme's own service; condition shapes are structurally complete
  (e.g. `IN`/`NOT_IN` has values); `ADD_PER_UNIT` rules have tiers with no gap/overlap and a coherent
  tier-mode combination; `QUOTE_REQUIRED` rules have `stop_processing` enabled. `publish()` then
  atomically (inside its own `DB::transaction`, `lockForUpdate()` on the target version and every
  existing PUBLISHED version for the same service+currency) rejects any effective-period overlap with
  an existing PUBLISHED version, then flips the version to `PUBLISHED` with the given effective dates.
  **This validator is never duplicated anywhere in Admin Pricing — it is called, not reimplemented.**
- **`App\Support\Pricing\PricingRuleEvaluator`** — the pure calculation core. Rules are sorted by
  `priority` ascending; a rule fires if **any** of its condition groups matches (OR between groups),
  and a group matches only if **all** of its conditions match (AND within a group) — a rule with no
  condition groups always fires. `stop_processing` halts evaluation after that rule. Effect types:
  `SET_PRICE`, `ADD_FIXED`, `ADD_PER_UNIT` (tiered, keyed to one `OPTION_NUMERIC_VALUE` service
  option), `MULTIPLY`, `MIN_TOTAL`, `MAX_TOTAL`, `QUOTE_REQUIRED`. Tiers support `VOLUME` (one matching
  band, `FLAT` or `PER_UNIT`) and `GRADUATED` (sum across bands, `PER_UNIT` only) calculation modes.
  Condition subject types: `OPTION_CHOICE`, `OPTION_NUMERIC_VALUE`, `OPTION_BOOLEAN_VALUE`,
  `ITEM_QUANTITY`, `CONTEXT_ATTRIBUTE` (resolved from `pricing_context_attributes`, e.g.
  `SERVICE_ZONE`, supplied by `App\Support\Checkout\CheckoutContextResolver`). Operators: `EQ`, `NEQ`,
  `GT`, `GTE`, `LT`, `LTE`, `IN`, `NOT_IN`, `BETWEEN` (validity per subject type is enforced by CHECK
  constraints, e.g. boolean conditions only support `EQ`/`NEQ`).
- **Historical safety** — `booking_item_option_selections`/`booking_item_option_choice_selections`
  snapshot every relevant field at booking time; a completed Booking never depends on a live
  `service_options`/`service_option_choices` row. Live `cart_item_option_selections` rows do carry a
  hard FK to `service_options` (`ON DELETE RESTRICT`), but nothing in Admin Pricing ever
  deletes/mutates a `service_option` — only `pricing_rules` and their own children are written here.

### Capabilities

| Capability | Covers |
|---|---|
| `pricing.view` | List/detail reads of every pricing scheme version and its nested rules/conditions/tiers. |
| `pricing.manage` | DRAFT-only authoring: create a DRAFT scheme version, create/delete a DRAFT rule. |
| `pricing.publish` | Publish a DRAFT scheme version. Requires `admin.stepup` (see below). |

Mirrors the `contracts.manage`/`contracts.cancel` split exactly: publishing changes live customer
prices and is uniquely dangerous and hard to reverse (like cancelling a Contract), so it gets its own
capability and Step-Up rather than folding into `pricing.manage`.

### Endpoints

| Feature | Method | Route | Capability |
|---|---|---|---|
| List Pricing Schemes | GET | `/v1/admin/pricing-schemes` | `pricing.view` |
| Get Pricing Scheme | GET | `/v1/admin/pricing-schemes/{pricingScheme}` | `pricing.view` |
| Create Pricing Scheme Draft | POST | `/v1/admin/pricing-schemes` | `pricing.manage` |
| Create Pricing Rule | POST | `/v1/admin/pricing-schemes/{pricingScheme}/rules` | `pricing.manage` |
| Delete Pricing Rule | DELETE | `/v1/admin/pricing-schemes/{pricingScheme}/rules/{rule}` | `pricing.manage` |
| Publish Pricing Scheme | POST | `/v1/admin/pricing-schemes/{pricingScheme}/publish` | `pricing.publish` + `admin.stepup` |

**List** filters (all optional): `service_uuid`, `status` (`DRAFT`/`PUBLISHED`/`RETIRED`), `currency`
(ISO code). Pagination: default 20, hard max 100 — the same convention as every other Admin list.
Each row: `uuid`, `service { uuid, name }`, `currency { code, symbol }`, `status`, `effective_from`,
`effective_to`, `rules_count` (batched), `created_at`, `updated_at`.

**Detail** additionally returns the full `rules` array (each: `uuid`, `rule_code`, `label`,
`priority`, `effect_type`, `effect_amount`, `effect_subject_option { uuid, name }`,
`tier_calculation_mode`, `stop_processing`, `condition_groups[{ conditions[...] }]`, `tiers[...]`) —
human-readable option/choice/context-attribute names are joined in purely for display; the underlying
codes/operators the real evaluator acts on are always included too. No raw binary UUID bytes are ever
exposed; `service_categories`-style plain-int identifiers don't apply here since every pricing
identifier is a binary(16) UUID.

### Mutations implemented, and why the rest is deferred

**Implemented** (DRAFT-only; a `PUBLISHED`/`RETIRED` version's rules and metadata are immutable,
enforced centrally by each Action locking and checking `status` before writing):

- **Create Pricing Scheme Draft** — `App\Actions\Admin\Pricing\AdminCreatePricingSchemeDraftAction`.
  Validates the service and currency exist (currency must be active); creates a `DRAFT` row with no
  effective dates (the schema only requires them once a version leaves `DRAFT`, and
  `SchemePublishValidator::publish()` is what sets them). No limit on concurrent DRAFTs per service.
- **Create Pricing Rule** — `App\Actions\Admin\Pricing\AdminCreatePricingRuleAction`. Accepts one full
  rule (effect + optional condition groups/conditions + optional tiers) and writes it atomically.
  Validates only field-level shape (mirroring the literal `pricing_rules`/`pricing_rule_conditions`
  CHECK constraints, e.g. `ADD_PER_UNIT` requires `effect_subject_service_option_id` +
  `tier_calculation_mode` + at least one tier; `QUOTE_REQUIRED` requires `stop_processing`) plus
  lightweight FK-existence checks. **Deliberately does not duplicate `SchemePublishValidator`'s
  cross-row checks** (duplicate priorities within the *whole* version, cross-service option
  references, tier sequence/coverage) — a DRAFT rule may be saved before it is fully publish-ready;
  the validator remains the single, authoritative "is this scheme safe to go live" gate, re-run in
  full at publish time. Duplicate `rule_code`/`priority` *within this one create call* is still
  rejected early (409) since that's a simple existence check, not a cross-row publish-readiness rule.
- **Delete Pricing Rule** — `App\Actions\Admin\Pricing\AdminDeletePricingRuleAction`. Its condition
  groups/conditions/condition values/tiers cascade-delete via the existing `ON DELETE CASCADE`
  foreign keys — nothing extra is deleted manually.
- **Publish Pricing Scheme** — `App\Actions\Admin\Pricing\AdminPublishPricingSchemeAction`. Locks the
  version, rejects anything other than `DRAFT` (a check `SchemePublishValidator::publish()` itself
  does not make — it would otherwise silently rewrite an already-PUBLISHED version's effective dates),
  calls `validate()` for a friendly aggregated error list, then calls the real `publish()` — the exact
  same transactional, row-locking, overlap-rejecting operation, never copied or reimplemented.

**Deferred, and why**:

- **Update an existing DRAFT rule (PATCH)** — no update endpoint exists; editing a DRAFT rule is
  delete + recreate. This avoids inventing partial-update semantics for a rule's nested condition/tier
  structure that no existing code establishes.
- **Retire a PUBLISHED scheme** — `RETIRED` is a valid schema status (used only in
  `PricingSchemeSelectorTest`'s fixtures to prove a retired version is correctly excluded from
  selection), but **no existing Action or business rule anywhere in this codebase transitions a
  version to `RETIRED`**. Inventing how/when that happens would mean guessing financial lifecycle
  policy, which BLUE V1 standing policy forbids. A `RETIRED` version therefore stays reachable only via
  direct read.
- **Service Zones on the Service/Pricing pages** — confirmed in B8: `service_zones`/
  `service_zone_areas` has no relationship to `services` at all in the schema; it is purely an
  Area→pricing-context mapping. Nothing to author here.
- **Pricing Preview / test calculation** — investigated `App\Support\Checkout\
  CheckoutContextResolver`: it resolves `SERVICE_ZONE` (and any future context attribute) from a real
  `cart_locations.area_id`, i.e. a genuine Cart/Checkout/Property context. Synthesizing a fake context
  from the Admin panel to "preview" a rule would mean guessing what context values are realistic,
  which is exactly the kind of invented business behavior this phase avoids. No
  `POST .../preview` endpoint was built; an Admin instead uses the real, unmodified
  `GET /v1/services/{slug}` pricing preview (which already calls the same `PricingEngine` with no
  selections) to confirm a published change, exactly as the end-to-end test below does.
- **Condition-group/tier authoring in the frontend UI** — the backend `Create Pricing Rule` endpoint
  fully supports nested condition groups and multi-tier `ADD_PER_UNIT` rules (see the dedicated tests
  in `AdminPricingTest`), but the Admin web UI's "Add a rule" form only covers the common unconditional
  case (`SET_PRICE`/`ADD_FIXED`/`MULTIPLY`/`MIN_TOTAL`/`MAX_TOTAL`/`QUOTE_REQUIRED`, no conditions, no
  tiers) to keep the form legible for a small trusted Admin team; a conditional or tiered rule is
  authored by calling the Admin API directly. The page says so explicitly.

### Step-Up

`POST /v1/admin/pricing-schemes/{pricingScheme}/publish` is gated by
`[AdminCapability::PRICING_PUBLISH->middleware(), 'admin.stepup']` — the exact existing WebAuthn A2.5
Step-Up infrastructure (no new MFA code). The frontend needs no special-case handling:
`resources/js/admin/lib/api-client.js`'s `request()` already detects a `428`/`STEP_UP_REQUIRED`
response, runs the real WebAuthn ceremony, and retries the publish call exactly once.

### Audit events

`PRICING_SCHEME_DRAFT_CREATED`, `PRICING_RULE_CREATED`, `PRICING_RULE_DELETED`,
`PRICING_SCHEME_PUBLISHED` — one row per successful, state-changing mutation. Logged metadata is
always small and safe (service/currency codes, rule UUID/code, effective dates) — never the full rule/
condition/tier structure, request body, or any customer payment data.

### Payment/Billing isolation

Nothing in `App\Actions\Admin\Pricing\*` or `App\Http\Controllers\Api\V1\Admin\Pricing\*` references
Stripe or creates/mutates a Payment Attempt, Contract Billing subscription, or webhook event —
verified by the existing `AdminFinancialIsolationTest::test_no_stripe_client_is_referenced_anywhere_in_
the_admin_operations_source` source scan (extended to cover this phase's files automatically, since it
scans the whole `app/Actions/Admin/**` and `app/Http/Controllers/Api/V1/Admin/**` trees). A published
pricing change only ever affects a *future* price calculation through the existing
`PricingSchemeSelector`/`PricingRuleEvaluator` path — never a direct side effect on any in-flight
Payment or Billing state.

### End-to-end proof: Admin Pricing drives the real customer price

`AdminPricingTest::test_admin_authored_published_pricing_is_used_by_the_real_pricing_engine` creates a
DRAFT scheme version and an unconditional `SET_PRICE` rule entirely through the Admin API, publishes it
entirely through the Admin API (with Step-Up), then calls the real, unmodified customer-facing
`GET /v1/services/{slug}` endpoint (`App\Actions\ServiceCatalog\GetServiceDetailsAction` →
`PricingEngine`) and asserts the returned `pricing_preview.unit_total` matches the configured amount
exactly. This proves Admin Pricing writes canonical configuration that the one real pricing engine
reads — never a second, parallel pricing implementation.

### Frontend

Sidebar "Pricing" (under Financial, alongside Payments/Contract Billing) points at
`/admin/pricing` (list, with filters + an inline "Create a Pricing Draft" form) and
`/admin/pricing/{scheme}` (detail: Overview, a Publish form and an Add-rule form shown only while
`status = DRAFT`, and a Rules list rendering each rule's effect, condition groups — with the real OR
-between-groups/AND-within-group relationship spelled out, never a generic dump — and tiers as
readable cards, never raw JSON). A B8 Service detail page's pricing-scheme-version links now navigate
to the corresponding B9 detail page (`/admin/pricing/{scheme}`), replacing the earlier read-only-only
summary — Pricing editing stays entirely in the Pricing domain; Service Catalog never gained pricing
-mutation UI of its own. Every mutation reloads the authoritative server response afterward; all
dynamic text is rendered via `textContent`/`createElement`, never `innerHTML`.

## Admin Operational Dashboard (BLUE V1 Phase B10)

The real Admin landing page at `GET /admin` — replacing the earlier frontend-foundation placeholder.
Connects every existing Admin domain (Bookings, Contracts, Payments, Contract Billing, Support,
Technicians, Customers) into one read-only operational overview: summary counts, actionable
"needs attention" lists, and a recent-activity feed. It reads canonical data only — no business logic
(status machines, pricing, eligibility) is reimplemented, and no schema change was made.

### Endpoint and authorization

`GET /v1/admin/dashboard` — `auth.admin` + a single new `dashboard.view` capability. One capability
was introduced, deliberately, rather than requiring every individual domain `.view` capability at
once: this codebase's `admin.capability:<code>` route middleware has no AND-combination support (every
existing route already checks exactly one capability), and the Dashboard is inherently cross-domain by
design. `dashboard.view` is a new BLUE V1 Phase B10 `admin_permissions` row, granted to `ADMIN` the
same way every other capability in this document already is (`SUPER_ADMIN` needs no row). This is a
real capability, not the no-gate precedent `GET /v1/admin/me` uses — the Dashboard exposes real
cross-domain operational data, unlike `/me`'s pure self-identity.

The endpoint is read-only: no writes, no side effects, fully deterministic, always bounded.

### Summary metrics (exact meaning of each)

| Group | Metric | Meaning |
|---|---|---|
| `bookings` | `active` | `bookings` whose `booking_statuses.is_terminal = 0` (PAID/ASSIGNED/IN_PROGRESS). |
| | `created_last_24h` | `bookings.created_at >= now()->subDay()`. |
| | `pending_assignment` | `booking_items` whose status is `PENDING_ASSIGNMENT`. |
| | `in_progress` | `booking_items` whose status is `IN_PROGRESS`. |
| `contracts` | `active` / `awaiting_approval` / `pending_customer_acceptance` / `pending_payment` / `suspended` | `service_contracts` grouped by `service_contract_statuses.code` (`ACTIVE`/`REQUESTED`/`PENDING_CUSTOMER_ACCEPTANCE`/`PENDING_PAYMENT`/`SUSPENDED`). `awaiting_approval` is the `REQUESTED` count — the status `App\Actions\Admin\Contract\AdminApproveContractAction` acts on. |
| `financial` | `payments_successful_last_24h` | `payment_attempts.successful_at >= now()->subDay()`. |
| | `payments_pending` | `payment_attempts` whose status is `PENDING`. |
| | `payments_requiring_reconciliation` | `payment_attempts.requires_reconciliation = 1 AND reconciled_at IS NULL` — the exact canonical flag `App\Actions\Payment\ProcessPaymentWebhookAction` already sets/clears; never a new concept. |
| | `billings_past_due` | `service_contract_billings` whose status is `PAST_DUE`. |
| `customers` | `active` | `users` (joined to `customer_profiles`, i.e. an actual Customer) whose account status is `ACTIVE`. |
| | `registered_last_24h` | same join, `users.created_at >= now()->subDay()`. |
| `support` | `open_or_in_progress` | `support_requests` whose status is non-terminal (`OPEN`/`IN_PROGRESS`). |
| | `unassigned_open` | same, plus `assigned_admin_user_id IS NULL`. |
| `technicians` | `assignable` | `technicians` whose `technician_statuses.is_assignable = 1` — the exact flag `App\Actions\Admin\Technician\AdminListTechnicianCandidatesAction` already uses for real assignment eligibility. |
| | `busy` | `technicians` whose status is `BUSY`. |

A metric is always an integer, including `0` — a zero-state database returns real zeros, never `null`
or an omitted key.

### "Needs attention" — exact conditions and links

Each list is bounded to the 10 oldest-first matching rows (a small, deterministic, actionable backlog
— not a paginated browse). Every item carries the exact identifier its own domain's existing Admin
detail page already accepts, and the Dashboard itself performs no mutation — clicking through is always
the only next step:

| List | Condition | Links to |
|---|---|---|
| `booking_items_pending_assignment` | `booking_items.status = PENDING_ASSIGNMENT` | `/admin/bookings/{booking_uuid}` |
| `contracts_awaiting_approval` | `service_contracts.status = REQUESTED` | `/admin/contracts/{contract_uuid}` |
| `payments_requiring_reconciliation` | `requires_reconciliation = 1 AND reconciled_at IS NULL` | `/admin/payments/{payment_uuid}` |
| `billings_past_due` | `service_contract_billings.status = PAST_DUE` | `/admin/billing/{billing_uuid}` |
| `support_unassigned_open` | non-terminal status AND `assigned_admin_user_id IS NULL` | `/admin/support/{support_request_uuid}` |

`PENDING_CUSTOMER_ACCEPTANCE`/`PENDING_PAYMENT` contracts are deliberately **not** an attention item:
those states wait on the customer or a webhook, not an Admin click — only `REQUESTED` (awaiting
`AdminApproveContractAction`) is a genuine Admin-actionable state today.

### Recent activity

Source: `admin_audit_logs` — the existing centralized Admin mutation ledger every other phase already
writes through `AdminAuditLogger`, ordered `created_at DESC, id DESC` (deterministic tie-break) and
bounded to the 10 most recent rows. Each entry exposes only `action_code`, `entity_type`,
`entity_identifier` (already a safe string per every existing `AdminAuditLogger::record()` call site —
never a raw binary id), `was_successful`, `failure_reason`, the actor's `full_name` (joined from
`users`/`user_profiles`, `null` if the actor account no longer resolves), and `created_at`.
**`old_values`/`new_values` are never returned** — the simplest, safest choice available (no
per-action-code whitelist to maintain), and every existing audit-writing Action already keeps those
columns small and safe (identifiers/short metadata only, per its own established convention), so
nothing operationally useful is lost by omitting them here.

### Timezone semantics ("last 24 hours", not "today")

BLUE V1's application timezone (`config('app.timezone')`) is UTC. During this phase's own testing, a
direct comparison of PHP's `now()` against the test database's `SELECT NOW()` showed the MySQL server's
own clock is not UTC-aligned with the application's configured timezone in this environment. A
calendar-day ("today", `startOfDay()`) boundary is exactly the kind of comparison a clock/timezone
mismatch like this can silently corrupt near either midnight. Every "recent" metric therefore uses a
rolling `now()->subDay()` ("last 24 hours") window instead — a fixed multi-hour offset only shifts a
24-hour rolling window slightly, whereas it can flip a calendar-day boundary entirely. This is exactly
the "prefer last 24 hours over ambiguous today" fallback called for when exact timezone alignment
cannot be assumed; it is not itself a fix for the underlying app/DB timezone configuration, which is
out of scope for a dashboard phase.

### Financial safety

The Dashboard's `financial` group and `payments_requiring_reconciliation`/`billings_past_due`
attention lists are pure `SELECT`s over `payment_attempts`/`service_contract_billings`. Nothing in
`App\Actions\Admin\Dashboard\*` or `App\Http\Controllers\Api\V1\Admin\Dashboard\*` references Stripe or
writes to a Payment Attempt, Contract Billing subscription, or webhook event — covered by the same
existing `AdminFinancialIsolationTest` source scan every other Admin module is. Never returned:
`checkout_snapshot`, `client_secret`, Stripe identifiers, webhook payloads, or any other
provider/security material.

### Fields intentionally not returned

Raw binary UUIDs (every identifier is the standard UUID string, matching every other Admin API);
`admin_audit_logs.old_values`/`new_values`; any `payment_attempts`/`service_contract_billings` column
with no Admin operational purpose (snapshots, provider secrets); Customer fields beyond what a count
needs (no customer list is exposed here — Customer detail remains `/admin/customers`).

### No schema changes; `blue_db` note

No table, column, migration, or index was added — every metric is a plain `COUNT`/grouped-aggregate
query over already-indexed columns (`booking_statuses`/`booking_item_statuses` joins,
`idx_bookings_status_created`, `idx_service_contracts_status`, `idx_service_contract_billings_past_due`,
`idx_support_requests_assigned_admin_status`, etc. — see `database/blue_v1_schema.sql`). The one new
`dashboard.view` `admin_permissions` row was added via the existing `INSERT ... ON DUPLICATE KEY
UPDATE` seed convention in `database/blue_v1_seed.sql`, with equivalent DML applied only to
`blue_test_db` (the pre-existing `blue_db` Admin-schema gap, reported in every phase since B5, remains
untouched and unrelated to B10).

### Why no charts/analytics infrastructure

No time-series data, chart library, or analytics pipeline was added. The existing schema does not
already expose meaningful time-series aggregates, and BLUE V1 standing policy is to never invent
dashboard infrastructure speculatively. Numbers and actionable, clickable lists answer "what needs
attention right now" far more directly than a decorative graph would for a small trusted Admin team.

### Frontend

Replaces the placeholder at `resources/views/admin/dashboard/index.blade.php` (still the same
`GET /admin` route). Sections: top summary card grid (one card per domain group above), a "Needs
attention" panel (one sub-list per condition above, each item linking straight into the existing
domain detail page — the Dashboard never adds a duplicate action button of its own), a bounded
"Recent activity" feed, and a static "Quick access" link row to every existing `/admin/*` module. Every
value — including `0` — is rendered via `textContent`; no `innerHTML` is used anywhere in
`resources/js/admin/dashboard/index.js`.

## Admin Ratings visibility (BLUE V1 Phase B11)

Read-only Admin visibility into `ratings` — the exact table
`docs/03-features-and-requirements/10-rating-and-feedback.md` describes ("The Admin / Service
Management Team should be able to: View customer ratings. View customer comments. View the booking
related to the rating. Review low ratings."). This phase implements exactly that read surface — never
a second feedback store, and never a rating-creation or moderation feature that does not already exist.

### Why this phase, and why it is entirely read-only

Exhaustive search of `backend/app` confirms **zero application code references `ratings` anywhere** —
there is no customer-facing rating-creation endpoint at all yet, in addition to no Admin surface. The
requirements document itself defers the only two mutations anyone might expect ("Editing or deleting a
submitted rating can be considered in a future version") — an explicitly undefined policy. Per BLUE V1
standing policy against inventing business rules, this phase adds **no mutation of any kind**: no
create (that is a Customer-app gap, out of scope for an Admin phase), no edit, no delete, no
moderation/hide flag. The `ratings` schema itself has no column that could represent such a flag
(`booking_id`, `rating_value`, `comment`, `created_at` only) — inventing one would be an unauthorized
schema change.

### Reused logic

Customer resolution reuses the exact `bookings JOIN carts → carts.customer_user_id` join
`App\Support\Admin\AdminBookingPresenter`/`AdminListBookingsAction` (B2) already established for "the
customer who owns this Booking" — no new path to that answer was invented. The Rating detail's
"services in this booking" list reuses `booking_items.service_name_snapshot` — the same historical
-safety snapshot column Booking detail already relies on — rather than joining live `services` rows.

### Endpoints

| Feature | Method | Route | Capability |
|---|---|---|---|
| List Ratings | GET | `/v1/admin/ratings` | `ratings.view` |
| Get Rating | GET | `/v1/admin/ratings/{booking}` | `ratings.view` |

`ratings.booking_id` is the table's own primary key — a Booking has at most one Rating, and there is no
separate "rating id" anywhere in the schema — so the Admin identifier for a Rating is simply the
Booking's own UUID. `ratings.view` is a new BLUE V1 Phase B11 `admin_permissions` row, granted to
`ADMIN` the same way every other capability in this document already is (`SUPER_ADMIN` needs no row).
There is no `ratings.manage` — mirroring the `payments.view`/`billing.view` precedent of a single
view-only capability with no mutation counterpart.

### List Ratings — `GET /v1/admin/ratings`

`App\Actions\Admin\Rating\AdminListRatingsAction`. Deterministic ordering (`created_at DESC, booking_id
DESC`) and the same pagination convention as every other Admin list endpoint (default 20, hard max
100). Query filters — all optional: `rating_value` (exact match, 1–5), `max_rating` (`<=`, e.g. `2` to
directly answer "review low ratings" from the requirements doc), `booking_uuid`, `customer_uuid` (both
exact-match UUIDs; malformed values are rejected with a `422` by the FormRequest, consistent with every
other Admin list endpoint's UUID filters — never silently ignored).

Each row: `booking_uuid`, `booking_number`, `rating_value`, `comment`, `customer { uuid, full_name }`
(nullable if the customer account no longer resolves), `created_at`.

### Get Rating — `GET /v1/admin/ratings/{booking}`

`App\Actions\Admin\Rating\AdminGetRatingAction`. A malformed UUID, an unknown Booking, or a Booking
with no Rating all return the same generic `404` — never distinguishing which case applied. Returns
`booking_uuid`, `booking_number`, `booking_status` (e.g. `COMPLETED`), `rating_value`, `comment`,
`customer { uuid, full_name, phone_number }`, `services` (array of service names from the Booking,
via `service_name_snapshot`), `created_at`.

**Never returned**: raw binary ids (every identifier is the standard UUID string), `password_hash`,
`refresh_token_hash`, any payment/checkout material — a Rating has no relationship to payment data at
all, so none is ever queried for this endpoint in the first place.

### No Step-Up, no audit events

Nothing here mutates state, so there is nothing to protect with WebAuthn Step-Up and nothing for
`AdminAuditLogger` to record — consistent with every other read-only Admin module (Payments, Contract
Billing, Customers).

### Frontend

Sidebar "Ratings" (under Application, alongside Customers/Services/Support) points at `/admin/ratings`
(list, with rating-value/max-rating/customer-uuid filters and a labeled "review low ratings" option)
and `/admin/ratings/{booking}` (detail: rating, customer, the Booking's services, and the full
comment). Both link back into their owning Booking (`/admin/bookings/{uuid}`) and Customer
(`/admin/customers/{uuid}`) detail pages rather than duplicating any of that information. The Customer
detail page's existing operational-links row (B6) now also links to
`/admin/ratings?customer_uuid=...`, exactly like every other cross-module link already there. The
customer-authored comment is rendered exclusively via `textContent`, never `innerHTML`.

### No schema changes; `blue_db` note

No table, column, migration, or index was added. The one new `ratings.view` `admin_permissions` row
was added via the existing `INSERT ... ON DUPLICATE KEY UPDATE` seed convention in
`database/blue_v1_seed.sql`, with equivalent DML applied only to `blue_test_db` — the pre-existing
`blue_db` Admin-schema gap, reported in every phase since B5, remains untouched and unrelated to B11.
