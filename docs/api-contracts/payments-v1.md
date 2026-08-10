# BLUE V1 — Phase 6A Payment Core API Contract

> Updated for Phase 6B (production-ready Stripe adapter hardening): `publishable_key` added to the
> client initiation contract, "Stripe error classification" added below, and the adapter is now
> covered by `tests/Feature/Payment/StripeAdapterTest.php` (Stripe SDK-transport-mocked, no real
> HTTP). The Payment Core trust boundary and every Phase 6A behavior described below are unchanged.

Base URL: `{{base_url}}` (local default: `http://127.0.0.1:8000/api/v1`)

This document describes the Phase 6A Payment Core endpoints as actually implemented in
`backend/routes/api.php`, their Actions (`App\Actions\Payment\*`) and Controllers
(`App\Http\Controllers\Api\V1\Payment\*`), and verified against `backend/tests/Feature/Payment/*`.
It documents only what exists in code — no aspirational or planned behavior is included.

Payment Core is built entirely on top of the Phase 5 **Checkout** contract
(`docs/api-contracts/checkout-v1.md`): it revalidates `ready_for_payment` server-side on every
attempt and reuses `App\Support\Checkout\CheckoutPresenter` / `PricingEngine` rather than
reimplementing pricing or readiness logic. Checkout itself is **not** redesigned by this phase.

## Scope

Phase 6A is: starting a payment for the caller's ACTIVE cart, freezing an immutable checkout
snapshot, tracking the payment through a small server-authoritative state machine, and processing
the payment provider's webhook confirmation. It is explicitly **not** Booking creation (no
`bookings` row is ever written here — see "Payment → Booking boundary"), **not** refund execution
(the `REFUNDED` status exists in the schema but no code path here ever writes it), and **not**
Apple Pay client setup (see "Apple Pay readiness").

## Stripe as BLUE V1 provider

**Stripe is the approved BLUE V1 payment provider**, targeting Stripe **PaymentIntent** semantics.
No Stripe account/keys are configured yet — `STRIPE_SECRET_KEY`, `STRIPE_PUBLISHABLE_KEY`, and
`STRIPE_WEBHOOK_SECRET` are all empty in `.env`/`.env.example` by design (see
`config/services.php`), and this is a valid, supported state: every `StripePaymentGateway` method
that needs the network checks its required key first and throws
`App\Support\Payment\Gateway\PaymentGatewayNotConfiguredException` instead of attempting the call —
it never fabricates a successful result. `PAYMENT_PROVIDER=stripe` selects this gateway in every
non-testing environment (`App\Providers\PaymentServiceProvider`).

**Checkout, Cart, PricingEngine, and the future Booking domain contain no Stripe-specific logic at
all.** The only boundary between BLUE's Payment domain and Stripe is the provider-neutral
`App\Support\Payment\Gateway\PaymentGateway` interface:

```
providerCode(): string
createPayment(PaymentCreationData): PaymentCreationResult
verifyWebhook(rawBody, signatureHeaders): VerifiedWebhookResult
parseWebhook(verifiedProviderEvent): NormalizedPaymentEvent
fetchPayment(providerReference): ProviderPaymentState
```

No Stripe SDK object, array, or exception type ever leaves `StripePaymentGateway` — every method
returns one of the typed DTOs above. `refund(...)` is deliberately **not** part of this interface
yet — refund execution is out of Phase 6A scope.

## Fake gateway (tests only)

`App\Support\Payment\Gateway\FakePaymentGateway` is the **only** `PaymentGateway` implementation
ever bound while `APP_ENV=testing` (`PaymentServiceProvider`'s one environment guard) — it is never
reachable in any other environment, so a misconfigured `PAYMENT_PROVIDER` can never fall back to a
fake successful payment outside of tests. It makes no network call of any kind: `createPayment()`
returns a deterministic queued/sticky/default result, and webhook verification uses its own fake
HMAC scheme (`FakePaymentGateway::sign()`) instead of Stripe's real signing algorithm, so the full
verify → parse → ledger → state-machine pipeline is still exercised end to end, just against a fake
wire format (a plain JSON payload) instead of a real `Stripe\Event` object. `providerCode()` returns
`"FAKE"`, never `"STRIPE"` — fake test rows can never be mistaken for real Stripe data.

## Endpoint summary

| # | Feature | Method | Route | Auth required |
|---|---|---|---|---|
| 1 | Create Payment | POST | `/v1/payments` | Yes (`auth.customer`) |
| 2 | Get Payment | GET | `/v1/payments/{payment}` | Yes (`auth.customer`) |
| 3 | Stripe Webhook | POST | `/v1/payments/webhooks/stripe` | No — provider signature only |

---

## 1. Create Payment

- **HTTP method / route**: `POST /v1/payments`
- **Required header**: `Idempotency-Key` — a UUID the client generates once per logical
  payment-start operation and reuses verbatim on every retry of that same operation. Missing →
  `422`. Malformed (not a UUID) → `422`.
- **Request body**: carries **no authoritative financial field**. `amount`, `currency`,
  `payment_status`, `provider_status`, `success`, `checkout_reference`, and `cart_uuid` are all
  `prohibited` by `App\Http\Requests\Payment\CreatePaymentRequest` — sending any of them is
  rejected with `422` rather than silently ignored, so a client bug is obvious immediately.
- **Success status**: `201 Created` for a newly created attempt; `200 OK` when the same
  `Idempotency-Key` resolves to an attempt that already existed (no new attempt, no new provider
  call, when the provider object was already confirmed).
- **Error statuses**: `401` (no/invalid token); `404` (no ACTIVE cart and no open payment attempt
  for this customer — genuinely nothing to pay for); `409` (this customer already has an open,
  non-final payment attempt for a `CHECKOUT` cart and the request used a different Idempotency-Key —
  see "Resolution order" below); `422` (malformed Idempotency-Key, checkout not
  `ready_for_payment`, or the Idempotency-Key was already used by a different customer — reported
  identically to avoid leaking whether the key belongs to someone else).

### Resolution order

Every request is resolved in this order, so a customer with an open payment is never misreported as
having nothing to pay for:

1. **Idempotency-Key reuse owned by this customer** — resolves to the existing attempt (`200`/`201`,
   see "Idempotency" below). Checked before touching Transaction A at all.
2. **This customer already owns a non-final (open) payment attempt** — a `CHECKOUT` cart's attempt
   survives even though the cart itself is no longer `ACTIVE`, so it cannot be found by looking for
   an `ACTIVE` cart alone. A *different* Idempotency-Key hitting this state is a known, owned
   financial conflict, not a not-found: `409 Conflict` ("A payment is already in progress for this
   checkout."). No second `payment_attempts` row is written and the `PaymentGateway` is never
   called.
3. **A genuine `ACTIVE` cart exists** — a brand new attempt is created normally (`201`).
4. **Nothing usable** (no `ACTIVE` cart, no open attempt) — `404`.

The DB `UNIQUE(open_cart_marker)` / `UNIQUE(successful_cart_marker)` constraints on
`payment_attempts` (`database/blue_v1_schema.sql`) remain the actual financial race-condition
backstop — see `CreatePaymentAttemptAction::handleInsertRace()`, which also maps an
`open_cart_marker` violation to `409`. The application-level check above is a UX/domain
convenience that reports the conflict without waiting for a race to actually occur; it never
replaces or weakens the DB constraint.
- **Success response**:
```json
{
  "success": true,
  "message": "Payment attempt created.",
  "data": {
    "payment": {
      "uuid": "b7c5965d-16a7-4283-86f5-59787bbc941c",
      "checkout_reference": "3f9a1c...(opaque, 8-64 ascii chars)",
      "status": "PENDING",
      "requested_amount": "100.000000",
      "currency": { "code": "AED", "symbol": "د.إ", "decimal_places": 2 },
      "expires_at": "2026-08-09T19:55:00+00:00",
      "provider": "STRIPE",
      "client_secret": "pi_..._secret_...(only when the provider returned one)",
      "publishable_key": "pk_...(only alongside client_secret)"
    }
  }
}
```
  `client_secret` and `publishable_key` are only ever present together, on the response to the call
  that actually reached the provider (a fresh attempt, or a same-key retry that reached the provider
  because the previous call never confirmed a provider object) — neither is persisted and neither is
  ever present on `GET /payments/{payment}`.
  - `client_secret` is a Stripe **client-side capability token** scoped to this one PaymentIntent —
    it lets the Flutter app complete (confirm) this specific payment via Stripe's PaymentSheet SDK,
    and nothing else. It is returned only to the authenticated owner of this payment attempt, only
    once, and is never logged, never stored in `payment_attempts`, and never reconstructible later.
  - `publishable_key` is **not itself secret** — Stripe's own documentation publishes it in
    client-side JS/mobile code by design (`config('services.stripe.publishable_key')`, the
    `STRIPE_PUBLISHABLE_KEY` env var). It is still only attached alongside an active `client_secret`
    (never on every response) so this endpoint returns only the safe initiation information a
    PaymentSheet integration actually needs for that one call, matching the Phase 6B client
    initiation contract. `STRIPE_SECRET_KEY` and `STRIPE_WEBHOOK_SECRET` are never returned by any
    endpoint under any circumstance.

### Server-authoritative amount and currency

The amount and currency are never read from the request. `CreatePaymentAttemptAction` re-derives
them from `App\Support\Checkout\CheckoutPresenter::present()` — the exact same live-repriced total
Checkout itself exposes — inside the same locked transaction that creates the attempt. Flutter sends
zero monetary values.

### Idempotency

- `Idempotency-Key` (raw UUID from the header) is normalized (lowercased) and SHA-256 hashed before
  storage — the raw key is **never** stored or returned. `payment_attempts.idempotency_key` is the
  32-byte digest, matching the column's existing `binary(32)` type.
- Same key, same customer, attempt already has a provider reference or is terminal → the existing
  attempt is returned as-is (`200`), no new row, no new provider call.
- Same key, same customer, attempt is still `PENDING` with no provider reference yet (the previous
  call's provider outcome was `UNKNOWN` — network loss, timeout, or a failure between the provider
  call and persisting its reference) → the gateway is called **again**, using the exact same
  provider idempotency key derived from the payment attempt's own UUID
  (`'blue_'.$paymentAttemptUuid`) — never a new random provider-side key, so Stripe (or the fake
  gateway) resolves it to the same underlying object instead of creating a second one.
- Same key, different customer → `422`, never the original customer's payment (idempotency_key is
  globally unique at the schema level; the lookup is always additionally scoped by the caller's own
  `carts.customer_user_id`).
- `checkout_reference` is generated by the backend only (`App\Support\Payment\
  CheckoutReferenceGenerator` — 160 bits of randomness, hex-encoded), never accepted from Flutter.

### Checkout revalidation, hold renewal, and cart transition

Every create attempt fully revalidates Checkout from scratch — **never** trusts a previous `GET
/checkout` response. Lock order, extending Checkout's own established order
(`docs/api-contracts/checkout-v1.md` §13) by one level:

```
USER → CART → CART_LOCATION → APPOINTMENT_SLOT → APPOINTMENT_HOLD
```

One `$now` is captured at the start of the transaction and used for every expiry/readiness decision
inside it. After confirming `ready_for_payment === true` (via `CheckoutPresenter`, not a second copy
of the readiness logic):

1. The cart's current open hold's `expires_at` is renewed to `now() +
   config('checkout.appointment_hold_ttl_minutes')` (approved default: 10 minutes) — the same hold
   row, not a new one.
2. `payment_attempts.expires_at` is set to exactly the renewed hold's `expires_at`.
3. The cart transitions `ACTIVE → CHECKOUT`.

Because every Cart mutation Action (`AddCartItemAction`, `UpdateCartItemAction`,
`RemoveCartItemAction`, `ClearCartAction`) only ever resolves/mutates the customer's **ACTIVE**
cart, a `CHECKOUT` cart is naturally frozen — no new code was needed to "block" mutation. A cart
mutation call made while a payment is open transparently opens a **new**, separate ACTIVE cart
instead of touching the frozen one (see `AddCartItemAction`'s "creates the customer's ACTIVE cart
the first time" behavior) — it does not error.

### Transaction / external-call boundary

```
TRANSACTION A
------------
USER lock → CART lock → no-open-attempt check → CART_LOCATION lock
→ APPOINTMENT_SLOT lock → APPOINTMENT_HOLD lock → CheckoutPresenter revalidation
→ hold renewal → cart ACTIVE → CHECKOUT → snapshot + hash → insert PENDING payment_attempts row
COMMIT

OUTSIDE TRANSACTION
-------------------
PaymentGateway::createPayment(...)

TRANSACTION B
-------------
Store provider_session_reference / provider_status_code
```

No MySQL row lock is ever held during the provider HTTP call. `PaymentCreationOutcome` drives what
happens next:

- **`CREATED`** (a provider-side object exists, even if the card was immediately declined) →
  Transaction B persists the provider reference; the attempt stays `PENDING`.
- **`DEFINITIVE_FAILURE`** (proven no provider object was created — e.g. a synchronous 4xx
  configuration/parameter error) → a compensation transaction, only if the attempt is still
  `PENDING` (never regressing an already-resolved attempt): `PENDING → FAILED`, the hold released,
  the cart `CHECKOUT → ACTIVE`.
- **`UNKNOWN`** (timeout, connection failure, 5xx — outcome genuinely ambiguous) → the attempt stays
  `PENDING`, nothing is compensated, and it is recoverable exactly as described above under
  "Idempotency".

### One open / one successful attempt per cart (DB-enforced)

`payment_attempts` carries two generated, indexed marker columns as a hard backstop beneath the
application-level checks above:

```sql
`open_cart_marker` binary(16)
  GENERATED ALWAYS AS (case when (`finalized_at` is null) then `cart_id` else NULL end) STORED,
`successful_cart_marker` binary(16)
  GENERATED ALWAYS AS (case when (`successful_at` is not null) then `cart_id` else NULL end) STORED,

UNIQUE KEY (`open_cart_marker`)
UNIQUE KEY (`successful_cart_marker`)
```

MySQL treats `NULL` as distinct in a unique index, so these constraints mean exactly: **at most one
non-finalized attempt per cart**, and **at most one ever-successful attempt per cart** — enforced
even under concurrent requests racing past the application-level lock (e.g. two different
Idempotency-Keys submitted in parallel for the same cart).

---

## 2. Get Payment

- **HTTP method / route**: `GET /v1/payments/{payment}`
- **Ownership**: `payment_attempts → carts.customer_user_id` — there is no separate `customer_id`
  column on `payment_attempts` by schema design. A payment UUID that does not exist, or belongs to
  another customer, returns `404` identically (never `403`) — matching Checkout's established
  "never distinguish missing from not-yours" convention.
- **Success response**: the same safe `payment` shape as Create Payment's response, **without**
  `client_secret` (never reconstructed on a later read), and never `checkout_snapshot`,
  `checkout_snapshot_hash`, `idempotency_key`, `reconciliation_reason_code`, or any provider secret.

---

## 3. Stripe Webhook

- **HTTP method / route**: `POST /v1/payments/webhooks/stripe`
- **Auth**: **deliberately not** `auth.customer` — the caller is the payment provider's server. The
  only authenticity check is the provider signature.
- **Trust boundary**: `App\Http\Controllers\Api\V1\Payment\PaymentWebhookController` reads
  `$request->getContent()` — the raw, unmodified request body — and passes it straight to
  `PaymentGateway::verifyWebhook()` **before any JSON decoding**. Stripe signs the exact raw bytes;
  re-encoding the body in any way before verification would break every signature.
  - Missing/empty `STRIPE_WEBHOOK_SECRET` → `verifyWebhook()` fails closed (`invalid`) without even
    attempting to construct the event — it never treats an unconfigured secret as "skip
    verification."
  - Invalid signature → `422`, no ledger row is created, no payment attempt is touched, no
    signature/secret is echoed back.
- **Success status**: `200 OK` for every authentic, verified delivery — including duplicates and
  events this system doesn't act on. A `200` here means "the delivery was safely accepted," not
  "the payment succeeded."

### Event ledger

Every verified delivery is recorded in `payment_webhook_events` before any payment-state mutation is
attempted:

| Column | Purpose |
|---|---|
| `provider_code`, `provider_event_id` | `UNIQUE(provider_code, provider_event_id)` — the hard dedup backstop |
| `payment_attempt_id` | Resolved local attempt, nullable until resolved |
| `event_type` | Raw provider event type string (e.g. `payment_intent.succeeded`) |
| `provider_transaction_reference` | For cross-referencing without re-deriving from the attempt |
| `payload_hash` | `SHA-256` of the **raw** request body — the raw payload itself is never stored |
| `status_id` | `RECEIVED → PROCESSED` \| `IGNORED` \| `FAILED` (`payment_webhook_event_statuses`) |
| `processing_attempt_count` | Bumped on every retry of a delivery that never reached `PROCESSED`/`IGNORED` |
| `received_at`, `processed_at` | — |
| `last_error_code`, `last_error_message` | Populated only on `FAILED` (e.g. no matching local attempt) |

A duplicate `provider_event_id` whose ledger row is already `PROCESSED` or `IGNORED` short-circuits
to a safe `200` with **zero** duplicate financial side effects — the payment-state pipeline never
runs a second time. A duplicate still at `RECEIVED`/`FAILED` (an interrupted delivery — e.g. the
process crashed mid-processing) is retried, so a genuine Stripe retry can always make forward
progress. Only a **payload hash** is stored — never the raw payload, never PAN/CVV/card data, never
the Stripe secret or webhook signing secret.

### Local attempt resolution

Resolved by `provider_session_reference` first (scoped to the bound gateway's own `provider_code`);
if that doesn't match (e.g. Transaction B never persisted it because of a DB failure right after a
successful provider create), falls back to `checkout_reference`, which the provider echoes back
through the metadata attached at creation time — a genuine recovery path, not a hypothetical one. No
match at all → the ledger row is marked `FAILED` with `last_error_code =
PAYMENT_ATTEMPT_NOT_FOUND` and the webhook still responds `200` (this needs operational
investigation, not endless provider retries).

### Processing

The resolved attempt is locked (`SELECT ... FOR UPDATE`) before any transition. See
`App\Support\Payment\PaymentAttemptStateMachine` for the two implementation details every mutation
goes through: (1) every transition method requires the attempt currently be `PENDING` and is a safe
**no-op** — never a throw — otherwise, so a stale/duplicate/out-of-order event can never regress a
terminal attempt; (2) `PENDING → SUCCESSFUL` atomically sets `confirmed_amount`,
`provider_transaction_reference` (if known), `provider_status_code`, `payment_method_type` (if
safely known from the provider), `successful_at`, `finalized_at`, and `status_changed_at` together.

---

## Payment statuses

The actual seeded `payment_statuses` codes (`database/blue_v1_seed.sql`) — nothing here is invented:

| code | is_final_for_checkout | allows_booking_creation |
|---|---|---|
| `PENDING` | false | false |
| `SUCCESSFUL` | true | true |
| `FAILED` | true | false |
| `CANCELLED` | true | false |
| `REFUNDED` | true | false |

**Phase 6A allowed transitions**: `PENDING → SUCCESSFUL`, `PENDING → FAILED`, `PENDING →
CANCELLED`. `REFUNDED` exists in the schema (and the `chk_payment_statuses_booking_requires_final`
constraint already governs it) but **no code path writes it** — refund execution is a later phase.
An authentic refund-related provider event is safely ledgered as `IGNORED` in Phase 6A, never
applied as a state transition.

### Stripe → BLUE normalization

`App\Support\Payment\Gateway\NormalizedPaymentOutcome` is the one place a provider's own lifecycle
is translated into BLUE's business status, and it is the direct implementation of the rule that a
provider's own **non-final** states must never read as BLUE `FAILED`:

| Stripe PaymentIntent status | `NormalizedPaymentOutcome` | Effect on the local attempt |
|---|---|---|
| `succeeded` | `SUCCEEDED` | `PENDING → SUCCESSFUL` (see "Reconciliation" below) |
| `canceled` | `CANCELED` | `PENDING → CANCELLED`, hold released, cart back to `ACTIVE` |
| `requires_payment_method` | `NON_TERMINAL` | Stays `PENDING`; `provider_status_code` refreshed |
| `requires_confirmation` | `NON_TERMINAL` | Stays `PENDING` |
| `requires_action` | `NON_TERMINAL` | Stays `PENDING` (e.g. mid-3DS) |
| `processing` | `NON_TERMINAL` | Stays `PENDING` |
| `requires_capture` | `UNEXPECTED_NON_TERMINAL` | Stays `PENDING`; flagged `requires_reconciliation` + `UNEXPECTED_PROVIDER_STATE` — BLUE V1 always creates PaymentIntents with automatic capture, so this indicates a configuration problem, not a customer outcome |
| anything else | `UNRECOGNIZED` | No mutation; ledger `IGNORED` |

A **declined card** (`payment_intent.payment_failed`) does not, by itself, finalize BLUE `FAILED` —
the underlying PaymentIntent typically remains `requires_payment_method` (a fresh payment method can
still be supplied against the *same* PaymentIntent), which normalizes to `NON_TERMINAL`. BLUE
`FAILED` means the local attempt itself was intentionally/definitively closed — either a
`DEFINITIVE_FAILURE` at creation time, never a mid-flight card decline. This mapping is unit-tested
directly (`tests/Unit/Payment/StripeStatusMappingTest.php`), independent of any Stripe API access.

---

## Stripe error classification

`StripePaymentGateway::classifyCreationFailure()` is the **one** centralized place a failed
`paymentIntents->create()` call (any `\Stripe\Exception\ApiErrorException`) is turned into
`PaymentCreationOutcome::DEFINITIVE_FAILURE` or `::UNKNOWN` — `CreatePaymentAttemptAction` branches on
that outcome alone, never on the raw exception type:

| Stripe exception | Outcome | Why |
|---|---|---|
| `RateLimitException`, `ApiConnectionException` | `UNKNOWN` | No proof the PaymentIntent was or wasn't created — recoverable via the same provider idempotency key |
| `InvalidRequestException`, `AuthenticationException` | `DEFINITIVE_FAILURE` (`STRIPE_REQUEST_REJECTED`) | Stripe never creates the object when the request itself (bad params, bad/revoked key) is synchronously rejected |
| Any other `ApiErrorException`, HTTP status ≥ 500 | `UNKNOWN` | Stripe's own server failed — ambiguous, not a proof of non-creation |
| Any other `ApiErrorException`, HTTP status < 500 | `DEFINITIVE_FAILURE` (`STRIPE_API_ERROR`) | A synchronous 4xx this gateway doesn't specifically recognize |

**Ordering hazard this method exists to avoid**: `stripe-php`'s `RateLimitException extends
InvalidRequestException`, so a `catch (InvalidRequestException $e)` block (or an `instanceof` check)
placed before the rate-limit case would silently swallow every 429 as a definitive rejection instead
of a recoverable/ambiguous outcome — exactly the "never classify every exception as FAILED" failure
mode this phase is required to avoid. `classifyCreationFailure()` checks `RateLimitException`/
`ApiConnectionException` first for this reason, and is directly unit-tested against real Stripe SDK
exception instances (built from a mocked HTTP response, never a live call) in
`tests/Feature/Payment/StripeAdapterTest.php`.

Raw Stripe exception messages/objects never reach Flutter — `PaymentCreationResult::$failureMessage`
is consumed only internally (`CreatePaymentAttemptAction::compensateDefinitiveFailure()`, for the
state machine's `failure_message` column) and `PaymentPresenter` never reads or exposes it.

---

## Reconciliation

Financial truth from a verified `SUCCEEDED` event always wins — the attempt is marked `SUCCESSFUL`
**even when** something about the local state looks inconsistent with it, because Stripe confirming
money moved is never treated as a false positive. What changes is whether Phase 7's automatic
Booking creation is safe to trust:

```
status = SUCCESSFUL
requires_reconciliation = 1
reconciliation_reason_code = <one of the codes below>
```

Checked in order inside `ProcessPaymentWebhookAction::determineReconciliationReason()`:

| `reconciliation_reason_code` | Condition |
|---|---|
| `AMOUNT_MISMATCH` | The event's confirmed amount ≠ `requested_amount` |
| `CURRENCY_MISMATCH` | The event's currency ≠ the attempt's currency |
| `HOLD_CART_MISMATCH` | The attempt's hold no longer belongs to the attempt's cart (defense in depth — not reachable through any Phase 6A code path today) |
| `HOLD_RELEASED` | The appointment hold was released before success arrived |
| `HOLD_EXPIRED` | The appointment hold's (renewed) `expires_at` has already passed |
| `SNAPSHOT_INTEGRITY_FAILURE` | Re-canonicalizing the stored `checkout_snapshot` no longer matches `checkout_snapshot_hash` |

No automatic Booking is ever created from a reconciliation-flagged (or any) `SUCCESSFUL` attempt in
Phase 6A — see "Payment → Booking boundary". Refund execution is never triggered automatically
either way.

---

## Immutable checkout snapshot

`payment_attempts.checkout_snapshot` (`json NOT NULL`) is built **once**, server-side, inside
Transaction A, by `App\Support\Payment\CheckoutSnapshotBuilder` — which reuses
`CheckoutPresenter::present()`'s output rather than re-deriving pricing a second way — and is never
recomputed or mutated afterwards. It contains everything Phase 7 needs to create the exact Booking
that was paid for **without** re-running pricing later:

```json
{
  "cart": { "uuid": "..." },
  "currency": { "code": "AED", "symbol": "د.إ", "decimal_places": 2 },
  "requested_total": "100.000000",
  "location": { "...": "same safe shape as GET /checkout's location" },
  "appointment": { "hold_uuid": "...", "slot": { "uuid": "...", "starts_at": "...", "ends_at": "...", "time_window": { "code": "STANDARD", "name": "Standard Hours" } } },
  "resolved_context": { "SERVICE_ZONE": "DUBAI_MARINA_ZONE", "TIME_WINDOW": "STANDARD" },
  "items": [
    {
      "cart_item_uuid": "...",
      "service": { "uuid": "...", "slug": "...", "name": "..." },
      "quantity": 1,
      "options": [ "...same safe options shape as Cart/Checkout..." ],
      "pricing": { "pricing_status": "PRICED", "pricing_scheme_version": "...", "base_amount": "100.000000", "adjustments": [], "unit_total": "100.000000", "quantity": 1, "line_total": "100.000000" }
    }
  ]
}
```

Never included: passwords, JWT/session data, raw `binary(16)` UUIDs (every identifier is already a
UUID string, matching Checkout's own contract), pricing-rule internals (only the same safe
`adjustments[]` shape Checkout already exposes), provider keys, Stripe secrets, or card data.
`resolved_context` codes (`service_zones.code` / `appointment_time_windows.code`) are resolved
server-side via the same `CheckoutContextResolver` PricingEngine itself uses — never a client value.

### Canonical JSON / hashing

`App\Support\Payment\CanonicalJson` is the **one** canonicalization implementation, used for both
storage and hashing — the exact same string is what gets written to `checkout_snapshot` and what
gets `SHA-256`-hashed into `checkout_snapshot_hash`:

- Associative (string-keyed) array keys are sorted recursively (`ksort(..., SORT_STRING)`), so key
  order can never affect the output.
- Sequential (list) arrays keep their original order — order is semantic there (cart items, pricing
  adjustments).
- Floats are rejected outright (`InvalidArgumentException`) — every monetary value in this codebase
  is already a decimal string; floats are the one PHP scalar whose `json_encode` output is not
  guaranteed byte-identical across platforms.
- `JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES`, no pretty-printing, consistently.

**MySQL's `JSON` column type does not guarantee it returns the exact bytes that were inserted** (only
the same semantic content) — so re-verifying integrity later (see `SNAPSHOT_INTEGRITY_FAILURE`
above) always re-canonicalizes the freshly-decoded value (`CanonicalJson::encode(json_decode(...))`)
before re-hashing, rather than hashing the raw column bytes directly. `tests/Unit/Payment/
CanonicalJsonTest.php` proves this decode → re-encode round trip is stable, and equivalent input
(reordered keys) always produces the same hash.

The snapshot and its hash are immutable once inserted — no code path in Phase 6A ever updates either
column. A later catalog price change, or a direct edit to the cart's location/selections, never
mutates an existing attempt's frozen `requested_amount`, `checkout_snapshot`, or
`checkout_snapshot_hash`.

---

## Payment → Booking boundary

Phase 6A ends at a (possibly reconciliation-flagged) `SUCCESSFUL` payment attempt. **No `bookings`
or `booking_items` row is ever created by this phase** — `bookings.payment_attempt_id` is already
`UNIQUE NOT NULL` in the schema and `booking_statuses` already starts at `PAID` with no
unpaid/pending booking status at all, confirming the schema was designed for this exact separation
from the start. Phase 7's hand-off is: one `payment_attempts` row with `status = SUCCESSFUL`,
`confirmed_amount` set, `finalized_at` set, and its frozen `checkout_snapshot` — Phase 7 must refuse
automatic Booking creation while `requires_reconciliation = true`.

## Apple Pay readiness

Apple Pay is **approved as a future BLUE V1 payment method through Stripe** — it is a Stripe payment
method, not a second payment system, and needs no Payment Core schema redesign. The architecture
already accommodates it as-is:

```
PaymentAttempt → Stripe PaymentIntent → payment method (card | Apple Pay | future methods)
```

`StripePaymentGateway::createPayment()` already requests `automatic_payment_methods.enabled = true`
on every PaymentIntent, which is what lets Stripe surface Apple Pay (and other wallet methods) to an
eventual Flutter `PaymentSheet` integration with no backend change. `payment_method_type` stores only
a **safe, provider-returned** classification after confirmation — normalized from the PaymentIntent's
`payment_method` (wallet type when Stripe expands it, otherwise the declared `payment_method_types[0]`,
e.g. `"card"`) — never a value Flutter declares. No `apple_pay_payments` table, no Apple-Pay-specific
amount/booking field, and no Apple Developer Merchant ID/certificate configuration exist or are
needed in Phase 6A — that belongs to the later Flutter/iOS Stripe integration once a Stripe account
and Apple Pay merchant credentials exist.

## No raw card storage

Consistent with `docs/05-system-requirements/03-security-and-privacy-requirements.md` §12
(`SEC-PAY-02`): BLUE never stores complete card numbers, CVV/security codes, or payment account
passwords. `payment_attempts` and `payment_webhook_events` have no column shaped to hold any of
those — `tests/Feature/Payment/SecurityTest.php::test_payment_attempts_schema_has_no_card_data_columns`
asserts this directly against `information_schema.columns`. Only safe metadata is ever stored:
provider reference, normalized payment method type, amount/currency, status, and timestamps.

## Stripe account/keys status

**No Stripe account or API keys are configured for BLUE V1 as of this phase.** `STRIPE_SECRET_KEY`,
`STRIPE_PUBLISHABLE_KEY`, and `STRIPE_WEBHOOK_SECRET` are all empty in the tracked `.env.example`
(and in the local `.env`) — this is intentional and does not block development or testing, since
`StripePaymentGateway` fails safely when unconfigured and the automated test suite never binds it in
the first place (see "Fake gateway" above). Configuring real keys is a future, separate step that
requires no code change — only setting the three environment variables.
