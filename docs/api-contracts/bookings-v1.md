# BLUE V1 — Phase 7A/7B/8A Booking Conversion, Lifecycle & Technician Assignment Contract

Base URL: `{{base_url}}` (local default: `http://127.0.0.1:8000/api/v1`)

This document describes the Phase 7A Booking endpoints, the Phase 7B Booking lifecycle, and the
Phase 8A technician assignment foundation, as actually implemented in `backend/routes/api.php`,
their Actions (`App\Actions\Booking\*`, `App\Actions\Technician\*`) and Controllers
(`App\Http\Controllers\Api\V1\Booking\*`), and verified against `backend/tests/Feature/Booking/*`
and `backend/tests/Feature/Technician/*`. It documents only what exists in code — no aspirational
or planned behavior is included.

Phase 7A is built entirely on top of the Phase 6A/6B **Payment Core** contract
(`docs/api-contracts/payments-v1.md`): it converts an already-trusted `SUCCESSFUL` payment attempt
into a Booking, reusing the frozen `checkout_snapshot` Phase 6A already produced rather than
re-deriving pricing or re-validating checkout a second way. Payment Core itself is **not**
redesigned by this phase.

## Scope

Phase 7A is: converting a trusted `SUCCESSFUL` `payment_attempts` row into exactly one `bookings`
row (plus its `booking_items`, `booking_locations`, and initial `booking_status_history` row), and
a minimal, read-only, ownership-scoped customer API to see the result.

Phase 7B is: the code-driven Booking/Booking Item lifecycle state machine that moves a Booking
forward from `PAID` through fulfillment (see "Booking lifecycle after PAID" below) — a foundation
layer with no new HTTP surface, since no admin-authenticated caller exists yet.

Phase 8A is: the code-driven Technician-to-Booking-Item assignment foundation (see "Technician
assignment (Phase 8A)" below) — also internal/server-only, for the same reason Phase 7B stopped at
the Action layer.

None of these three phases is refund execution, notifications, or Admin Booking management — all
later phases.

## Payment → Booking boundary

**A Booking is never created because Flutter says a payment succeeded.** It is created only from a
`payment_attempts` row that has already reached `SUCCESSFUL` through the verified provider
webhook flow (`App\Actions\Payment\ProcessPaymentWebhookAction`). No endpoint in this phase accepts
a "create booking" request from the client at all — there is no `POST /v1/bookings`.

The trigger is entirely server-side: once `ProcessPaymentWebhookAction::handle()`'s own transaction
commits a payment attempt's outcome (regardless of which outcome), it makes one best-effort,
idempotent call to `App\Actions\Booking\CreateBookingFromSuccessfulPaymentAction` — deliberately
**outside** and **after** that transaction, in its own separate transaction, so a Booking-conversion
problem can never roll back or otherwise affect payment state that already safely committed. Any
exception from that call is reported and swallowed; the webhook still responds `200` (the delivery
itself was validly processed).

## Exactly-once guarantee

`CreateBookingFromSuccessfulPaymentAction::handle(string $paymentAttemptUuid)` is the **one**
canonical, idempotent entry point for Booking conversion — every caller (the webhook pipeline, and
the recovery Artisan command below) goes through it, never a second implementation.

Calling it once, twice, ten times, after a webhook retry, or after a client retry always converges
on **exactly one** Booking, enforced by two independent layers:

1. **Application-level existence check** — the very first thing `handle()` does after locking the
   `payment_attempts` row is check for an existing `bookings` row with that `payment_attempt_id`;
   if found, it returns that Booking's uuid immediately (`ALREADY_EXISTS`), writing nothing.
2. **DB backstop** — `bookings.payment_attempt_id` and `bookings.cart_id` are both `UNIQUE NOT NULL`
   in the schema. A genuine insert race (two callers reaching the insert at the same instant) is
   caught (`Illuminate\Database\UniqueConstraintViolationException`) and resolved by re-reading and
   returning the winner's Booking — never by letting a SQL exception escape to the caller.

## Eligibility for automatic Booking

Conversion proceeds only when, at the moment `handle()` runs and after locking the row:

- `payment_attempts.status_id` is `SUCCESSFUL`, **and**
- `payment_attempts.requires_reconciliation` is `false`, **and**
- no `bookings` row already exists for this `payment_attempt_id`, **and**
- the frozen `checkout_snapshot`'s re-canonicalized `SHA-256` hash still matches
  `checkout_snapshot_hash`, **and**
- the payment's own `cart` is still `CHECKOUT` (not something else), **and**
- the payment's own `appointment_hold` is still bound to that same cart, not released, not
  expired, and not already converted.

`PENDING`, `FAILED`, `CANCELLED`, and `REFUNDED` payment attempts never produce a Booking.
`requires_reconciliation = true` never produces a Booking automatically, regardless of reason.

No financial state is ever changed backward merely because Booking conversion is refused — a
`SUCCESSFUL` payment attempt that fails an eligibility check stays exactly `SUCCESSFUL`.

## Snapshot / financial integrity re-verified at conversion time

Even though `ProcessPaymentWebhookAction` already checks snapshot integrity once (at the moment a
payment becomes `SUCCESSFUL`), `CreateBookingFromSuccessfulPaymentAction` independently re-verifies
it again, from scratch, every time it runs — this is what makes a *deferred* conversion (a recovery
run minutes or hours after success) just as safe as an immediate one:

1. Recompute the canonical hash of `checkout_snapshot` (`App\Support\Payment\CanonicalJson`, the
   exact same implementation Phase 6A uses) and compare against `checkout_snapshot_hash`.
2. For every snapshot item: confirm `pricing_status == PRICED`, then verify
   `line_total_amount == unit_total_amount × quantity` using `BCMath` decimal-string arithmetic —
   never floats.
3. Verify `sum(booking_items.line_total_amount) == payment_attempts.confirmed_amount ==
   checkout_snapshot.requested_total`, again using `BCMath` only.

Any mismatch anywhere in the above: **no Booking is created**, the payment stays `SUCCESSFUL`, and
`payment_attempts` is updated to `requires_reconciliation = true` with an **existing**
`reconciliation_reason_code` (`SNAPSHOT_INTEGRITY_FAILURE` or `AMOUNT_MISMATCH` — see
`docs/api-contracts/payments-v1.md` "Reconciliation") — no new reason code was needed. The snapshot
itself is never mutated; only read.

## Appointment hold safety re-checked at conversion time

`CreateBookingFromSuccessfulPaymentAction` re-verifies the payment's own bound `appointment_hold`
row (never an arbitrary current customer hold) immediately before writing anything:

| Condition found | Result |
|---|---|
| Hold's `cart_id` no longer matches the payment's `cart_id` | `requires_reconciliation = true`, `reconciliation_reason_code = HOLD_CART_MISMATCH` |
| Hold already `released_at` | `requires_reconciliation = true`, `reconciliation_reason_code = HOLD_RELEASED` |
| Hold's `expires_at` has already passed | `requires_reconciliation = true`, `reconciliation_reason_code = HOLD_EXPIRED` |
| Hold already `converted_at` but no Booking exists for this attempt | Withheld (`BLOCKED`) — an unreachable state under the current architecture; nothing is guessed at or mutated |

This is exactly how BLUE V1 handles "the payment succeeded, but by the time conversion actually ran,
the slot's hold had expired" without ever silently overbooking a slot: the Booking is withheld,
financial truth (`SUCCESSFUL`) is preserved, and the condition is reconciliation-visible using the
same existing reason codes Phase 6A already defined.

## Transaction / lock order

Booking conversion runs inside **one** DB transaction covering every fulfillment-domain write, with
no external/network call inside it (no Stripe call, no `PricingEngine` re-run):

```
PAYMENT_ATTEMPT lock → existing-Booking check → eligibility checks → CART lock
→ APPOINTMENT_HOLD lock → build + validate Booking Items (no writes yet)
→ insert bookings → insert booking_status_history → insert booking_locations
→ insert booking_items (all) → cart CHECKOUT → CONVERTED → hold.converted_at set
COMMIT
```

This lock order (`PAYMENT_ATTEMPT → CART → APPOINTMENT_HOLD`) is a new root distinct from every
Phase 1–6B customer-request flow (`USER → CART → ...`), proven deadlock-free rather than merely
unlikely to collide:

- Every Phase 1–6B write path that locks a `carts` row first resolves it as the customer's
  **ACTIVE** cart. By the time Booking conversion ever runs, the payment's cart is `CHECKOUT` (or
  already `CONVERTED`) — so those paths never select, and therefore never lock, the same cart row.
- `ProcessPaymentWebhookAction` (the only other path that locks a `payment_attempts` row directly)
  always locks that row first too, and never locks `CART`/`APPOINTMENT_HOLD` itself — so two
  overlapping conversion attempts for the *same* payment attempt simply queue on the
  `PAYMENT_ATTEMPT` lock, never deadlock.

`appointment_slots` is deliberately never locked during conversion: it makes no capacity/
availability decision (that already happened when the hold was created), and every display fact it
needs about the slot already lives in the frozen `checkout_snapshot` / is read directly by uuid via
`bookings.appointment_slot_id` for the read API below.

Payment is already `SUCCESSFUL` **before** this transaction ever starts. If any step inside it fails
for any reason (including a genuine DB exception), the whole transaction rolls back — no partial
`bookings`/`booking_items`/`booking_locations`/`booking_status_history` row is ever left behind —
and the already-committed payment state is **never** rolled back to `PENDING`/`FAILED`.

## Cart transition

Only the payment's own frozen `CHECKOUT` cart is ever touched — never "the customer's current
ACTIVE cart" (a customer may already have started a brand new one). If the payment's cart is not
currently `CHECKOUT` (e.g. already `CONVERTED` with no matching Booking — an inconsistent state
under normal operation), conversion is safely withheld (`BLOCKED`) rather than guessed at.

```
ACTIVE → CHECKOUT (Phase 6A payment creation) → CONVERTED (Phase 7A Booking creation)
```

## Booking Items and pricing

Every `booking_items` row is built **only** from the frozen `checkout_snapshot` — never by calling
`PricingEngine` again, never by reading the current catalog price. `pricing_breakdown` stores the
snapshot's own `adjustments[]` array verbatim; `base_amount_snapshot`, `unit_total_amount`, and
`line_total_amount` are copied through as decimal strings and re-validated with `BCMath` (see
"Snapshot / financial integrity" above) before anything is written.

**Known snapshot limitation**: the Phase 6A `checkout_snapshot` does not carry
`services.code` or per-option/per-choice descriptive detail (name, type, measurement unit) — only
`service.uuid`/`slug`/`name` and a minimal `option_uuid` + value shape (see
`docs/api-contracts/payments-v1.md` "Immutable checkout snapshot"). `booking_items.service_code_snapshot`
is therefore resolved from the current `services.code` column via the frozen, immutable `service_id`
foreign key (the same "resolve a stable value from a frozen reference id" pattern Phase 6A itself
uses for `resolved_context`), not from the snapshot. `booking_item_option_selections` /
`booking_item_option_choice_selections` are intentionally left unpopulated in Phase 7A — the
snapshot does not carry enough descriptive detail to populate them safely, and `pricing_breakdown`
already preserves the full historical pricing-adjustment detail. See the Phase 7A implementation
report's "Schema Gaps" section for the full reasoning.

## Location snapshot

`booking_locations` is populated once, at conversion time, from the frozen `checkout_snapshot`'s
`location` object plus a resolution of the current `property_types`/`areas`/`cities`/`countries`
reference tables keyed by the snapshot's own frozen `property_type.id` / `area.id` — never from the
customer's current `cart_locations` row (which may have since changed or moved to a different cart).
Free-text fields (`street_name`, `address_line`, `building_name_or_number`, `floor_number`,
`unit_number`, `nearby_landmark`, `additional_location_notes`, `visit_contact_phone`) are copied
through from the snapshot verbatim.

## Appointment snapshot

`bookings.appointment_slot_id` references the slot directly. Unlike location, `appointment_slots`
rows are immutable operational data once created (no code path ever updates an existing slot's
`starts_at`/`ends_at`) — reading the slot (and its `appointment_time_windows` row) live for display
is exactly as historically accurate as a text snapshot would be, and is what the read API below
does.

## Booking status

The initial status is always resolved by **code** (`booking_statuses.code = 'PAID'`), never a
hardcoded numeric id (`App\Support\Booking\BookingStatuses::id('PAID')`). The initial
`booking_status_history` row (`from_status_id = null`, `to_status_id = PAID`) is written in the
same transaction as the Booking itself, exactly once — a retried/duplicate conversion call never
inserts a second history row, since it short-circuits at the existing-Booking check before reaching
any insert.

## Booking lifecycle after PAID (Phase 7B)

**Payment status and Booking status are two separate things.** `payment_attempts.status_id` is
financial truth and is frozen the moment a Booking exists (Phase 7A/7B never reopens it). A
Booking's own `status_id` is fulfillment state — it starts at `PAID` and moves forward through
operational work that happens after the money has already been collected. Concretely:

- Payment `SUCCESSFUL` does **not** mean Booking `COMPLETED` — it only means the Booking was
  allowed to exist in the first place, at status `PAID`.
- Booking `CANCELLED` does **not** automatically mean payment `REFUNDED` — see "Cancellation" below.

The seeded lifecycle (`database/blue_v1_seed.sql` "22. BOOKING STATUSES" /
docs/03-features-and-requirements/08-request-status-tracking.md) is:

```
PAID -> ASSIGNED -> IN_PROGRESS -> COMPLETED
{PAID, ASSIGNED, IN_PROGRESS} -> CANCELLED
```

`COMPLETED` and `CANCELLED` are both terminal (`booking_statuses.is_terminal = 1`) — no transition
is possible out of either. The identical shape is seeded for `booking_item_statuses`
(`PENDING_ASSIGNMENT -> ASSIGNED -> IN_PROGRESS -> COMPLETED`, `CANCELLED` from any non-terminal
item status).

### Transition Actions are server/internal-only in Phase 7B

`App\Actions\Booking\TransitionBookingStatusAction` (`bookings`) and
`App\Actions\Booking\TransitionBookingItemStatusAction` (`booking_items`) are the **one** canonical,
code-driven implementation of the graph above — never an arbitrary `status_id`/`status_code` write
from anywhere else. Each exposes one explicit method per business action (`assign()`, `start()`,
`complete()`, `cancel()`), never a generic "transition to any code" call.

**Neither is reachable from any HTTP route.**
docs/03-features-and-requirements/08-request-status-tracking.md is explicit that "the admin manages
and updates request statuses in Version 1" and "the customer can only view request statuses," and no
admin-authenticated area of the API exists yet in this codebase. Phase 7B therefore stops at the
Action layer — a future phase that adds admin authentication (and, separately, technician
assignment) is expected to call these Actions from an admin-only controller. No customer-facing
mutation endpoint (`POST /cancel`, `POST /complete`, `PATCH /status`, reschedule, etc.) exists, and
none should be added on top of this state machine without that explicit admin-facing requirement.

Every transition: locks the target row (`bookings` or `booking_items`) → resolves its current
status by **code** → attempts the specific transition → on success, writes exactly one
`booking_status_history` / `booking_item_status_history` row using the same captured server
timestamp as the status write — all inside one DB transaction. A row already in the requested target
status is a safe, idempotent no-op (nothing written, so a retried call after a lost response never
duplicates history). A row whose current status does not allow the requested transition (including
any attempt out of a terminal status) is safely rejected — never an exception for this expected case.

### Booking and Booking Item lifecycles are independent

A Booking Item can be driven through its own lifecycle independently of its parent Booking's status,
and vice versa — Phase 7B does not derive or enforce one from the other. Transitioning a Booking
Item never reads or locks the parent `bookings` row, and transitioning a Booking never reads or
locks any `booking_items` row. This is a deliberate boundary, not an oversight: the requirements
docs describe multi-service bookings where "each service may have its own status" without specifying
that the system itself must auto-derive or gate one level from the other, so Phase 7B does not invent
that coupling. A later phase — once technician assignment and an admin panel exist to drive both
levels together — can decide whether/how to reconcile them.

### Cancellation

`cancel()` moves a Booking from any non-terminal status (`PAID`, `ASSIGNED`, `IN_PROGRESS`) to
`CANCELLED`, sets `cancelled_at` from server time, and writes one history row. **It never touches
`payment_attempts` and never triggers a refund.** A cancelled paid Booking may still have a
`SUCCESSFUL` payment until a separate refund process exists in a later phase — Booking cancellation
and Payment refund are deliberately two different, independently-triggered concerns. Refund
execution is explicitly out of scope for both Phase 7A and Phase 7B.

### Completion

`complete()` moves a Booking from `IN_PROGRESS` to `COMPLETED` and sets `completed_at` from server
time. It never re-runs `PricingEngine`, never rewrites `booking_items` pricing-snapshot columns, and
never touches the appointment/hold history Phase 7A already wrote.

## Technician assignment (Phase 8A)

`App\Actions\Technician\AssignTechnicianToBookingItemAction` is the **one** canonical entry point
for assigning a Technician to a Booking Item, built directly on the `technicians` /
`technician_statuses` / `technician_specializations` / `technician_assignments` schema that already
existed. It has two explicit methods, `assign()` and `reassign()` — never a generic mutation call.

**Server/internal-only, exactly like Phase 7B's transition Actions.**
`docs/03-features-and-requirements/07-technician-assignment.md` is explicit that "only the admin
can assign technicians in Version 1," and no admin-authenticated area of the API exists yet in this
codebase. There is therefore no `POST /v1/bookings/{booking}/assign-technician` or any other
customer-reachable route — `backend/tests/Feature/Technician/TechnicianAssignmentTest.php` asserts
this directly (a plausible assignment URL 404s for an authenticated customer). A future phase that
adds admin authentication is expected to call `assign()`/`reassign()` from an admin-only controller.

### Assignment is Booking-Item-level

`technician_assignments.booking_item_id` is the table's only foreign key into the Booking graph —
there is no Booking-level assignment concept in the schema. Assigning "one technician for the whole
booking" (a case `docs/02-user-roles-and-stakeholders/04-technician-service-employee.md` describes)
is simply calling `assign()` once per Booking Item with the same Technician uuid — never a separate
code path. A Booking Item may currently have at most one **primary** active Technician
(`technician_assignments.is_primary = 1`); the schema's own unique constraints
(`uq_technician_assignments_active_technician`, `uq_technician_assignments_active_primary`) show it
was designed to also allow secondary/non-primary technicians on the same item, but nothing in the
Version 1 requirements describes that, so it is not built in Phase 8A.

### Eligibility

- **Booking Item**: must currently be `PENDING_ASSIGNMENT` for `assign()`, or `ASSIGNED`/
  `IN_PROGRESS` for `reassign()`. `IN_PROGRESS`/`COMPLETED`/`CANCELLED` are never valid starting
  points for `assign()`; `PENDING_ASSIGNMENT`/`COMPLETED`/`CANCELLED` are never valid starting
  points for `reassign()`. Resolved by locking the `booking_items` row and reading its status by
  **code**, never a hardcoded id.
- **Technician**: must currently be in a `technician_statuses` row with `is_assignable = 1` — a
  data-driven flag, never a hardcoded status code. The seeded statuses
  (`database/blue_v1_seed.sql` "24. TECHNICIAN STATUSES") are `AVAILABLE` (`is_assignable = 1`),
  `BUSY`, `ON_LEAVE`, `INACTIVE` (all `is_assignable = 0`) — Phase 8A does not treat `AVAILABLE` as
  a magic string anywhere in code, only as the seed data's current answer to "which statuses are
  assignable."
- **Actor**: `assigned_by_user_id` / `released_by_user_id` are `NOT NULL` foreign keys to `users`,
  so every call must supply a real actor uuid — Phase 8A never fabricates one. The actor must also
  hold the `ADMIN` or `SUPER_ADMIN` role (`roles`/`user_roles`, matching "only the admin can assign
  technicians"). An unknown actor uuid is `ACTOR_NOT_FOUND`; a real user without that role is
  `ACTOR_NOT_AUTHORIZED`.

### Specialization matching is data-driven

Compatibility is resolved entirely from `service_specializations` (keyed by the Booking Item's own,
authoritative `booking_items.service_id` — never a client-supplied service id, since the Action
signature has no such parameter at all) intersected with the Technician's active
`technician_specializations`. No service id, slug, or technician "type" is ever hardcoded or
branched on.

- The service's active specializations are read in the schema's own preference order (its
  `is_primary` specialization first, then `display_order`); the first one the Technician actually
  holds (an active `technician_specializations` row) is the one recorded on the assignment.
- If the service has **no** active `service_specializations` row at all, assignment is rejected as
  `SERVICE_SPECIALIZATION_NOT_CONFIGURED` — `technician_assignments.specialization_id` is `NOT
  NULL`, so there is no id available to satisfy it. This is a catalog data-completeness
  precondition (an admin must configure a service's specialization before any technician can be
  assigned to it), not a technician eligibility problem or a schema gap.
- If the service has requirements but the Technician holds none of them: `SPECIALIZATION_MISMATCH`.

### Scheduling / double-booking

`bookings.appointment_slot_id` is singular — every Booking Item inside one Booking shares the exact
same `starts_at`/`ends_at` window. There is no per-Booking-Item time column and no unique constraint
that can enforce "a Technician may not hold two overlapping active assignments" at the database
level, so Phase 8A implements exactly the safe subset the schema supports:

- Before writing a new active assignment, the Technician's other active assignments (on
  **different** Bookings only — two items of the *same* Booking never conflict with each other,
  since they share one identical slot) are compared against the new Booking's slot period. Any
  overlap (`existing.starts_at < new.ends_at AND existing.ends_at > new.starts_at`) is rejected as
  `TECHNICIAN_DOUBLE_BOOKED`. Assignments on cancelled Booking Items, or on a cancelled Booking, are
  excluded from the comparison — a cancelled job no longer occupies the calendar.
- This check is made race-safe by locking the `technicians` row `FOR UPDATE` before running it: any
  concurrent assignment attempt referencing the same Technician must first take an implicit
  FK-parent lock on that same row (to satisfy `technician_assignments.technician_id`'s foreign key),
  so it blocks until the first transaction commits or rolls back, then correctly re-reads the
  just-committed state.
- **Limitation, reported as required**: technician assignment can be safely recorded with this
  overlap protection, but a full technician *scheduling/availability* engine (working hours, shifts,
  per-item time granularity, travel time between jobs) is out of scope — the schema has no such
  data model yet. That is a later scheduling extension, not a Phase 8A gap.

### Idempotency

Retrying the exact same `assign(item, technician, actor)` call is a safe no-op: `assign()` first
checks for an existing active assignment on that Booking Item, and if it is already the requested
Technician, returns the existing assignment uuid immediately — no duplicate
`technician_assignments` row, no duplicate `booking_item_status_history` row, no second lifecycle
transition attempt. The schema's own `uq_technician_assignments_active_technician` unique
constraint is the DB-level backstop for the same guarantee under a genuine insert race (caught as
`UniqueConstraintViolationException` and resolved by re-reading the winner, mirroring
`CreateBookingFromSuccessfulPaymentAction`'s own race-resolution pattern). A **different** Technician
can never silently overwrite an already-active assignment — `assign()` rejects that as
`ASSIGNED_TO_ANOTHER_TECHNICIAN`; only `reassign()` may replace an active Technician.

### Reassignment

`reassign(item, newTechnician, actor, releaseReason)` replaces the active primary Technician on a
Booking Item that is already `ASSIGNED` or `IN_PROGRESS`. The schema explicitly supports this
(`technician_assignments.released_at` / `released_by_user_id` / `release_reason`), so Phase 8A
implements the one safe path it describes:

- The previous assignment row is **never deleted** — it is released (all three release columns set
  together, exactly as `chk_technician_assignments_release_data` requires), remaining a permanent,
  queryable audit row.
- The new Technician goes through the exact same eligibility/specialization/double-booking checks as
  `assign()`.
- The Booking Item's own status is **never changed** by reassignment — an `IN_PROGRESS` item stays
  `IN_PROGRESS` under its new Technician; `BookingItemStatusMachine` has no reverse transition and
  none is required by current requirements, so Phase 8A does not invent a regression path.
- Reassigning to the Technician already active is an idempotent no-op (`ALREADY_ASSIGNED`).
- There is no standalone "unassign" (release with no replacement) in Phase 8A: since
  `BookingItemStatusMachine` cannot move a Booking Item backward from `ASSIGNED` to
  `PENDING_ASSIGNMENT`, a bare release would leave the item stuck `ASSIGNED` with no active
  Technician — an unsupported state this phase does not invent.

### Booking Item lifecycle integration

A **first-time** successful `assign()` (from `PENDING_ASSIGNMENT`) calls the existing Phase 7B
`App\Actions\Booking\TransitionBookingItemStatusAction::assign()` in the same DB transaction —
Phase 8A never writes `booking_items.status_id` directly, and never duplicates the Phase 7B state
machine's rules. `reassign()` never calls it at all (the item's status does not change).

### Booking lifecycle boundary — unchanged from Phase 7B

Technician assignment **never** reads, locks, or writes the parent `bookings` row, and never calls
`App\Actions\Booking\TransitionBookingStatusAction`. Phase 7B's Booking/Booking-Item independence
(see "Booking and Booking Item lifecycles are independent" above) is preserved exactly as-is — no
aggregate rule ("Booking → `ASSIGNED` once all items are assigned," etc.) is defined by current
requirements, so Phase 8A does not invent one. A future phase may decide whether/how to reconcile
the two levels once an admin panel exists to drive both together.

### Payment / pricing boundary

Technician assignment never touches `payment_attempts`, `carts`, `checkout_snapshot`, or any
`booking_items` pricing-snapshot column, and never calls `PricingEngine` or the Stripe gateway —
`backend/tests/Feature/Technician/TechnicianAssignmentTest.php` asserts both the source-level
absence of any such reference and that a Booking's payment/pricing snapshot survive assignment
byte-for-byte unchanged.

### Transaction / lock order

`booking_items` (the row being assigned) → `technicians` (the Technician being assigned) — both
`assign()` and `reassign()` acquire locks in this exact order, so two concurrent calls for the same
Booking Item simply queue on the `booking_items` lock, and two concurrent calls for the same
Technician (different items) simply queue on the `technicians` lock, never a reversed pair and
therefore never a deadlock cycle. This lock order never touches `bookings`, `payment_attempts`,
`carts`, or `appointment_holds` — disjoint from every Phase 7A/7B lock path. No external/network
call is ever made inside the transaction.

### Customer-facing technician information

Not implemented in Phase 8A. `docs/03-features-and-requirements/13-technician-contact-information.md`
describes customer-visible technician name/specialization/contact-number-if-allowed, but the Booking
read API (`GET /v1/bookings/{booking}`, "Booking APIs" below) is deliberately **unchanged** by this
phase — `BookingPresenter` still returns no technician data of any kind. Adding it is a later,
explicit scope decision, not an automatic consequence of assignment existing.

## Recovery

A `SUCCESSFUL` payment with no Booking never becomes invisible. The reusable, idempotent
`CreateBookingFromSuccessfulPaymentAction` can always be safely re-invoked for it — the smallest
safe recovery mechanism is an Artisan command:

```
php artisan bookings:convert-successful-payments [--limit=200]
```

`App\Console\Commands\ConvertSuccessfulPaymentsToBookings` finds every `payment_attempts` row that
is `SUCCESSFUL`, `requires_reconciliation = false`, and has no matching `bookings` row (a plain
`LEFT JOIN ... WHERE bookings.id IS NULL` — exactly how an operator finds "successful payments with
no bookings" today), and retries conversion for each. On a healthy system it finds nothing and does
nothing. No queue/outbox infrastructure was introduced — the conversion Action's own idempotency is
what makes repeated invocation always safe.

## Booking APIs

| # | Feature | Method | Route | Auth required |
|---|---|---|---|---|
| 1 | List Bookings | GET | `/v1/bookings` | Yes (`auth.customer`) |
| 2 | Get Booking | GET | `/v1/bookings/{booking}` | Yes (`auth.customer`) |

Both are read-only, never reprice, and are entirely historical-snapshot based — nothing here re-runs
`PricingEngine` or re-reads the live catalog/profile.

### 1. List Bookings

- **HTTP method / route**: `GET /v1/bookings`
- Every Booking belonging to the authenticated customer (`bookings → carts.customer_user_id`),
  newest first. Never reveals another customer's Bookings.

### 2. Get Booking

- **HTTP method / route**: `GET /v1/bookings/{booking}`
- **Ownership**: `bookings → carts.customer_user_id` — there is no separate `customer_id` column on
  `bookings` by schema design, mirroring Payment Core's own `payment_attempts → carts.customer_user_id`
  convention. A Booking UUID that does not exist, or belongs to another customer, returns `404`
  identically (never `403`) — a foreign or malformed UUID can never be distinguished from "does not
  exist."
- **Success response** (shown at `PAID`, the status immediately after Phase 7A conversion —
  `status`/`items[].status` reflect whatever the Booking/Booking Item have actually reached via the
  Phase 7B lifecycle in "Booking lifecycle after PAID" above: `PAID`, `ASSIGNED`, `IN_PROGRESS`,
  `COMPLETED`, or `CANCELLED` for the Booking; `PENDING_ASSIGNMENT`, `ASSIGNED`, `IN_PROGRESS`,
  `COMPLETED`, or `CANCELLED` per item):
```json
{
  "success": true,
  "message": "Booking retrieved successfully.",
  "data": {
    "booking": {
      "uuid": "b7c5965d-16a7-4283-86f5-59787bbc941c",
      "booking_number": "BLU-4F9A1C2B0D",
      "status": "PAID",
      "currency": { "code": "AED", "symbol": "د.إ", "decimal_places": 2 },
      "total": "100.000000",
      "location": {
        "property_type_name": "Apartment",
        "other_property_type_name": null,
        "country_name": "United Arab Emirates",
        "city_name": "Dubai",
        "area_name": "Dubai Marina",
        "street_name": "Sheikh Zayed Road",
        "address_line": "...",
        "building_name_or_number": "Tower 4",
        "floor_number": "12",
        "unit_number": "1201",
        "nearby_landmark": "Near QA Metro Station",
        "additional_location_notes": null,
        "visit_contact_phone": "+971500000000"
      },
      "appointment": {
        "slot": {
          "uuid": "...",
          "starts_at": "2026-08-12T10:00:00+00:00",
          "ends_at": "2026-08-12T12:00:00+00:00",
          "time_window": { "code": "STANDARD", "name": "Standard Hours" }
        }
      },
      "items": [
        {
          "uuid": "...",
          "service": { "uuid": "...", "code": "AC_REPAIR", "name": "AC Repair" },
          "quantity": 1,
          "status": "PENDING_ASSIGNMENT",
          "completed_at": null,
          "cancelled_at": null,
          "pricing": {
            "pricing_scheme_version_uuid": "...",
            "base_amount": "100.000000",
            "adjustments": [],
            "unit_total": "100.000000",
            "line_total": "100.000000"
          }
        }
      ],
      "created_at": "2026-08-11T11:00:00+00:00",
      "status_changed_at": "2026-08-11T11:00:00+00:00",
      "completed_at": null,
      "cancelled_at": null
    }
  }
}
```

Never returned by either endpoint: `payment_attempt_id`, `checkout_snapshot`,
`checkout_snapshot_hash`, `idempotency_key`, any `reconciliation_*` field, `client_secret`, or any
other provider/webhook internal. Every id is a UUID string (`App\Support\Uuid\UuidBinary::toString()`)
— no raw `binary(16)` value is ever serialized. Every monetary value is a decimal string with 6
fraction digits (`decimal(19,6)` column shape) — never a JSON number/float.

## Not implemented in Phase 7A / 7B / 8A

Phase 7B added the internal Booking/Booking Item lifecycle state machine and transition Actions (see
"Booking lifecycle after PAID" above); Phase 8A added the internal Technician assignment Actions
(see "Technician assignment (Phase 8A)" above). Neither added a new HTTP route. Still not
implemented, and still later phases: any admin-authenticated API at all (Admin Booking management,
Admin technician assignment/management), any customer-facing mutation endpoint (cancel, reschedule),
customer-visible technician information on the Booking read API, secondary/team technician
assignment, a full technician scheduling/availability engine, reviews, and refund execution — no
route, Action, or Controller for any of them exists yet.
