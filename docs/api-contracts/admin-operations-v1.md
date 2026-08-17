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
  already documented ("no permission framework beyond role codes exists"). No endpoint below
  branches on which of the two roles the caller holds.
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
