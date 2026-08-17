# BLUE V1 — Phase 10B/10C/10D/10E/10F Service Contracts Contract

Base URL: `{{base_url}}` (local default: `http://127.0.0.1:8000/api/v1`)

This document describes the Phase 10 Service Contract endpoints (customer + admin), as actually
implemented in `backend/routes/api.php`, `App\Actions\Contract\*`, `App\Actions\Admin\Contract\*`,
`App\Http\Controllers\Api\V1\Contract\*`, `App\Http\Controllers\Api\V1\Admin\Contract\*`, and
verified against `backend/tests/Feature/Contract/*` and `backend/tests/Feature/Admin/AdminContractTest.php`.

## Scope

A customer may request a long-term **Service Contract** against exactly one saved Property
(`docs/api-contracts/properties-v1.md`), covering one service, several services, or "all currently
eligible services". A contract-covered service may later be consumed into a real, fulfillable
**CONTRACT Booking** with no new customer Payment — see `docs/api-contracts/bookings-v1.md` §"Booking
source: STANDARD vs CONTRACT" for how that Booking reuses the existing fulfillment system unchanged.

**Out of scope in V1**: Stripe subscriptions, automatic recurring billing, an e-signature provider.
The Contract's own commercial value (`quoted_amount`/`currency`) is a manual, Admin-entered figure;
collecting payment for it is an operational process outside this API.

## CONTRACT eligibility

A service is contract-eligible when it carries the **`SUBSCRIPTION`** capability
(`service_capability_types.code = 'SUBSCRIPTION'`, already seeded before Phase 10 — "The service is
only available under an annual subscription or maintenance contract"). No new schema was needed for
this: `App\Support\Pricing\ServiceCapabilities::has($serviceUuid, 'SUBSCRIPTION')` is the one place
this is checked, at both request time (`RequestContractAction`) and approval time
(`AdminApproveContractAction`).

## Lifecycle (`service_contract_statuses`)

```
REQUESTED -> APPROVED -> PENDING_CUSTOMER_ACCEPTANCE -> ACTIVE -> SUSPENDED
                                                            |
                                                            v (lazy, term-ended)
                                                          EXPIRED
{REQUESTED, APPROVED, PENDING_CUSTOMER_ACCEPTANCE, ACTIVE, SUSPENDED} -> CANCELLED
```

`App\Support\Contract\ContractStatusMachine` is the one place `service_contracts.status_id` is ever
written, mirroring `App\Support\Booking\BookingStatusMachine` exactly: every method requires the
caller to have already locked the row, is a safe no-op (never a throw) from the wrong starting
status, and never writes `service_contract_status_history` itself — the calling Action does, exactly
once per real transition. `EXPIRED` and `CANCELLED` are terminal.

### Expiry — deterministic, no scheduler required

An `ACTIVE` Contract whose `ends_at` has passed is **lazily** transitioned to `EXPIRED` by
`App\Actions\Contract\Concerns\AppliesContractExpiry`, called by every write path that needs an
authoritative answer (booking creation, suspend, cancel) on the row it has already locked `FOR
UPDATE` — this makes the transition race-safe for free and needs no background job for correctness.
Plain reads (list/detail) never write; they compute the same "effective status" purely via
`ContractPresenter::effectiveStatus()` / `AdminContractPresenter`, so `GET` requests always show the
correct status even for a Contract nobody has written to since its term ended. An optional
`php artisan contracts:expire` command sweeps ACTIVE-but-due contracts in bulk as a maintenance
convenience only — correctness never depends on it running.

## Entitlements (`service_contract_items`)

Each Contract Item snapshots one covered service (`service_code_snapshot`/`service_name_snapshot`,
frozen at approval time — never re-read from the live catalog later) and its entitlement:

- **`LIMITED_VISITS`** — `included_visits` is a positive integer cap.
- **`UNLIMITED`** — no cap; `included_visits` is `NULL`.

**"Used visits" is never a separate mutable counter.** It is always derived from real `bookings` rows
(`App\Support\Contract\ContractEntitlementCalculator`): a Booking referencing this Contract Item
whose current status is not `CANCELLED` counts as one consumed visit. Cancelling a CONTRACT Booking
therefore automatically and permanently frees its visit with zero extra bookkeeping — see
`App\Actions\Booking\CancelBookingAction`'s CONTRACT branch.

**Race safety for the last visit**: `App\Actions\Contract\CreateContractBookingAction` locks the
`service_contract_items` row `FOR UPDATE` before taking a `FOR UPDATE` count of non-cancelled
Bookings against it (mirroring `App\Actions\Checkout\CreateAppointmentHoldAction`'s own
capacity-count pattern). Two concurrent booking attempts for the same last remaining visit serialize
on that row lock — the loser always re-counts the winner's already-committed Booking and correctly
rejects. This was judged sufficient and simpler than adding a separate reservation-ledger table.

## "All services" resolution

If the customer requests `all_services: true`, the currently-eligible (`SUBSCRIPTION`-capable,
active) services are recorded only as an **informational** snapshot on the `service_contracts` row
(`requested_service_ids` / `requested_all_services`) at request time. The **authoritative**,
historical `service_contract_items` rows are created exactly once, by an Admin, at approval time
(`AdminApproveContractAction`) — a service added to the catalog after that moment is never
automatically added to an already-approved Contract.

## Customer endpoints

All require `auth.customer`. Ownership is always `service_contracts.customer_user_id = <authenticated
user>`; a foreign or unknown Contract UUID is `404`, never `403`.

### `GET /v1/contracts`

Lists the customer's own Contracts, newest first (summary shape only).

### `GET /v1/contracts/{contract}`

Full detail: contract number, property, term, covered services (with `entitlement_mode`,
`included_visits`, `used_visits`, `remaining_visits`), acceptance info, and every CONTRACT Booking
created against it.

### `POST /v1/contracts/requests`

```json
{
  "property_uuid": "...",
  "all_services": false,
  "service_uuids": ["..."],
  "desired_start_date": "2026-09-01",
  "customer_note": "optional"
}
```

Creates a `REQUESTED` Contract. The customer never chooses status, entitlement quantities, price,
approval state, or contract number — those are all server/Admin-owned. `422` if any requested
service is not `SUBSCRIPTION`-eligible, or if `all_services: true` resolves to zero currently
eligible services. `409` if the property is archived. `404` if the property is not owned by the
caller.

### `POST /v1/contracts/{contract}/accept`

No e-signature provider in V1 — "acceptance" is the authenticated customer's own action against
their own `PENDING_CUSTOMER_ACCEPTANCE` Contract. Persists an immutable agreement snapshot + SHA-256
hash (`service_contract_acceptances`, `App\Support\Payment\CanonicalJson`, the same pattern
`payment_attempts.checkout_snapshot`/`checkout_snapshot_hash` already uses) and transitions to
`ACTIVE`. Idempotent two ways: an already-`ACTIVE` Contract with an existing acceptance row returns
the same `200` result; `UNIQUE(service_contract_id)` on `service_contract_acceptances` is the DB
backstop for a concurrent retry race. `409` from any other status.

### `POST /v1/contracts/{contract}/services/{contractItem}/book`

```json
{ "appointment_slot_uuid": "..." }
```

Consumes one entitlement unit into a real Booking. See
`docs/api-contracts/bookings-v1.md` for the full CONTRACT Booking flow and entitlement-safety rules.
Only ever succeeds when: the Contract is `ACTIVE` (after a lazy expiry check), the current date is
`>= starts_at`, the `{contractItem}` belongs to `{contract}`, the entitlement is not exhausted, and
the appointment slot has remaining capacity.

## Admin endpoints

All require `auth.admin`. Never expose customer-private/provider/payment internals — see
`App\Support\Admin\AdminContractPresenter`.

| Method | Path | Purpose |
|---|---|---|
| GET | `/v1/admin/contracts` | Paginated list, filterable by `status`, `contract_number`, `customer_uuid` |
| GET | `/v1/admin/contracts/{contract}` | Full operational detail (customer identity, internal note, requested-service snapshot, status history) |
| POST | `/v1/admin/contracts/{contract}/approve` | `REQUESTED -> APPROVED`; the one place `service_contract_items` rows are ever created |
| POST | `/v1/admin/contracts/{contract}/send-for-acceptance` | `APPROVED -> PENDING_CUSTOMER_ACCEPTANCE` |
| POST | `/v1/admin/contracts/{contract}/suspend` | `ACTIVE -> SUSPENDED` (optional `reason`) |
| POST | `/v1/admin/contracts/{contract}/cancel` | Any non-terminal status `-> CANCELLED` (optional `reason`) |

`approve` and `send-for-acceptance` are kept as two separate routes (not folded into one) because
Phase 10D's required route surface lists both explicitly, and an Admin may legitimately want to
review a freshly-approved Contract before actually notifying the customer.

### `POST /v1/admin/contracts/{contract}/approve`

```json
{
  "starts_at": "2026-09-01T00:00:00Z",
  "ends_at": "2027-09-01T00:00:00Z",
  "term_months": 12,
  "services": [
    { "service_uuid": "...", "entitlement_mode": "LIMITED_VISITS", "included_visits": 4 },
    { "service_uuid": "...", "entitlement_mode": "UNLIMITED" }
  ],
  "quoted_amount": "1200.000000",
  "currency_code": "AED",
  "internal_note": "optional"
}
```

The Admin always explicitly lists every covered service and its entitlement terms here, even when
the customer originally requested "all services" — there is no server-side "just copy every eligible
service" shortcut, by design (see "All services" resolution above). `422` if any listed service is
not `SUBSCRIPTION`-eligible or the `included_visits`/`entitlement_mode` pairing is invalid.
Already-`APPROVED` is a safe idempotent no-op (V1 has no "amend an approved contract" endpoint —
items are write-once).

### Idempotency & audit

Every mutating Admin action is idempotent from its target status and writes at most one
`service_contract_status_history` row per real transition. `App\Support\Admin\AdminAuditLogger`
records exactly one `admin_audit_logs` row per real transition (`CONTRACT_APPROVED`,
`CONTRACT_SENT_FOR_ACCEPTANCE`, `CONTRACT_SUSPENDED`, `CONTRACT_CANCELLED`) — never for a retried,
already-there no-op, mirroring `App\Actions\Admin\Technician\AdminAssignTechnicianAction`'s
convention exactly.

## Schema

See `database/blue_v1_schema.sql` for full DDL: `service_contract_statuses`, `service_contracts`,
`service_contract_items`, `service_contract_status_history`, `service_contract_acceptances`. Contract
→ Booking linkage lives on `bookings` itself — see `docs/api-contracts/bookings-v1.md`.

## Regression

Every existing Customer/Auth/Profile/Catalog/Pricing/Cart/Checkout/Payment/Booking/Technician/Admin
behavior is unchanged. The only touched pre-existing files are `bookings.payment_attempt_id`
(made nullable), `App\Actions\Booking\CreateBookingFromSuccessfulPaymentAction` (now writes
`booking_source_id = STANDARD` explicitly), `App\Actions\Booking\CancelBookingAction` (a CONTRACT
branch that skips refund calculation entirely — STANDARD behavior is byte-for-byte unchanged), and
`App\Support\Booking\BookingPresenter` / `App\Support\Admin\AdminBookingPresenter` (additive
`source`/`contract` fields only).
