# BLUE V1 — Checkout & Scheduling API Contract

Base URL: `{{base_url}}` (local default: `http://127.0.0.1:8000/api/v1`)

This document describes the Phase 5 Checkout & Scheduling endpoints as actually implemented in
`backend/routes/api.php`, their Actions (`App\Actions\Checkout\*`) and Controllers
(`App\Http\Controllers\Api\V1\Checkout\*`), and verified against `backend/tests/Feature/Checkout/*`.
It documents only what exists in code — no aspirational or planned behavior is included.

`SERVICE_ZONE`, `TIME_WINDOW`, and the appointment hold TTL are fully resolved, data-driven BLUE V1
decisions as of this revision — see "Pricing context trust boundary" and "Appointment hold TTL"
below for how each is derived.

Checkout is built entirely on top of the Phase 3.5 **PricingEngine** (`App\Support\Pricing\
PricingEngine`) and the Phase 4 **Cart** (`docs/api-contracts/cart-v1.md`). It reuses Cart's active
-cart resolution, ownership model, and pricing-status/total semantics rather than reimplementing
them — see `App\Support\Pricing\PricingResultAggregator` and `App\Support\ServiceCatalog\
ServiceSummaryPresenter`, both extracted from the Phase 4 `CartPresenter` in this phase so Cart and
Checkout share the exact same aggregate roll-up and service-summary shape instead of two copies.

## Scope

Phase 5 is checkout summary, actual service location, pricing-context resolution, appointment
availability, temporary appointment holds, live repricing, and final checkout readiness. It is
explicitly **not** payment (no gateway call, no payment transaction, no capture), **not** booking
creation, **not** staff assignment, and **not** invoices/refunds — those are later phases.

## Global notes

- All responses share Cart's envelope `{ "success": bool, "message": string, "data": object|null }`,
  with `"errors": [string, ...]` on validation/business-rule failures.
- **Every endpoint requires authentication** — `Authorization: Bearer {{access_token}}`, enforced by
  `auth.customer` (see `docs/api-contracts/authentication-v1.md`).
- Checkout always operates on the authenticated customer's **ACTIVE** cart, resolved the same way
  Cart resolves it (`carts.customer_user_id` + `cart_statuses.code = 'ACTIVE'`) — never from a
  client-supplied `cart_uuid`. An endpoint that needs a cart to already exist (every endpoint except
  `GET /checkout`) returns `404` when the customer has no ACTIVE cart; `GET /checkout` instead
  returns a safe empty representation, mirroring `GET /cart`.
- **Checkout never trusts a previous Cart or Checkout response.** Every read rebuilds the summary
  live from `carts`, `cart_items`, `cart_locations`, `appointment_holds`, and `PricingEngine::
  evaluate()` — see `App\Support\Checkout\CheckoutPresenter`.
- All `BINARY(16)` identifiers are converted to standard UUID strings before leaving the API, exactly
  as in Cart. `appointment_slots.internal_note` is never returned — it is explicitly an
  admin/staffing field, not part of the customer contract.
- **New reference tables in this phase**: `service_zones` (a zone lookup), `service_zone_areas` (the
  deterministic `areas.id → service_zones.id` mapping — `area_id` is this table's primary key, so an
  area maps to **at most one** zone), and `appointment_time_windows` (a scheduling-window lookup,
  referenced by the new `appointment_slots.time_window_id NOT NULL` column). All three are generic,
  admin-configured lookup data — no service-specific or hardcoded zone/window logic exists anywhere
  in this phase's code. Real zone and time-window rows are business data owned by the admin/scheduling
  layer and remain empty in `blue_db` until populated; BLUE V1 Phase 5 only builds the schema and the
  resolvers that consume it.

---

## Endpoint summary

| # | Feature | Method | Route | Auth required |
|---|---|---|---|---|
| 1 | Get Checkout | GET | `/v1/checkout` | Yes |
| 2 | Save Checkout Location | PUT | `/v1/checkout/location` | Yes |
| 3 | List Appointment Slots | GET | `/v1/checkout/appointment-slots` | Yes |
| 4 | Create Appointment Hold | POST | `/v1/checkout/appointment-hold` | Yes |
| 5 | Release Appointment Hold | DELETE | `/v1/checkout/appointment-hold` | Yes |

---

## 1. Get Checkout

- **HTTP method / route**: `GET /v1/checkout`
- **Tables read**: `carts`, `cart_items` (+ selections), `cart_locations`, `areas`, `cities`,
  `property_types`, `appointment_holds`, `appointment_slots`, `currencies`, plus everything
  `PricingEngine::evaluate()` reads per item.
- **Success status**: `200 OK` always.
- **Success response** (cart with a saved location, an active hold, and one PRICED item):
```json
{
  "success": true,
  "message": "Checkout retrieved successfully.",
  "data": {
    "checkout": {
      "cart_uuid": "b7c5965d-16a7-4283-86f5-59787bbc941c",
      "location": {
        "property_type": { "id": 1, "code": "APARTMENT", "name": "Apartment" },
        "other_property_type_name": null,
        "area": { "id": 8, "code": "DUBAI_MARINA", "name": "Dubai Marina" },
        "city": { "id": 2, "code": "DUBAI", "name": "Dubai" },
        "street_name": "Sheikh Zayed Road",
        "address_line": "Marina Tower, near the metro station",
        "building_name_or_number": "Marina Tower",
        "floor_number": "12",
        "unit_number": "1201",
        "nearby_landmark": "Near QA Metro Station",
        "additional_location_notes": null,
        "visit_contact_phone": "+971500001234"
      },
      "appointment": {
        "hold_uuid": "9c1e4c7a-....-....-....-............",
        "slot": {
          "uuid": "1210862f-....-....-....-............",
          "starts_at": "2026-08-10T09:00:00+00:00",
          "ends_at": "2026-08-10T11:00:00+00:00",
          "time_window": { "code": "STANDARD", "name": "Standard Hours" }
        },
        "expires_at": "2026-08-09T19:45:00+00:00"
      },
      "pricing_status": "PRICED",
      "required_context": [],
      "requires_quote": false,
      "ready_for_payment": true,
      "currency": { "code": "AED", "symbol": "د.إ", "decimal_places": 2 },
      "items": [
        {
          "cart_item_uuid": "6b0e2c8a-....-....-....-............",
          "service": { "uuid": "...", "slug": "ac-deep-clean", "name": "AC Deep Cleaning", "primary_image": null },
          "quantity": 1,
          "options": [],
          "pricing": { "pricing_status": "PRICED", "...": "same safe PricingResult contract as Cart" }
        }
      ],
      "total": "100.000000"
    }
  }
}
```
- **No ACTIVE cart** (safe empty response, never creates a cart): `cart_uuid`, `location`, and
  `appointment` are all `null`, `items: []`, `pricing_status: "PRICED"`, `total: "0.000000"`,
  `ready_for_payment: false`.
- **Business behavior**: Every item is repriced live via `PricingEngine::evaluate()`, called with a
  `context` array built by `App\Support\Checkout\CheckoutContextResolver` (see "Pricing context trust
  boundary" below). An `appointment_holds` row whose `expires_at` has passed is reported as
  `"appointment": null` — never mutated by this read-only endpoint (matching `GET /cart` never
  creating a cart as a side effect).

---

## 2. Save Checkout Location

- **HTTP method / route**: `PUT /v1/checkout/location`
- **Purpose**: Records the **actual** address where the work will happen — never the customer's
  registered profile address (see "Pricing context trust boundary"). `cart_locations.cart_id` is the
  table's primary key (1:1 with `carts`), so this is always an upsert: exactly one row per cart,
  full-replace semantics (PUT, not a partial PATCH — there is no partial-location concept in the
  schema).
- **Request body**:
```json
{
  "property_type_id": 1,
  "other_property_type_name": null,
  "area_id": 8,
  "street_name": "Sheikh Zayed Road",
  "address_line": "Marina Tower, near the metro station",
  "building_name_or_number": "Marina Tower",
  "floor_number": "12",
  "unit_number": "1201",
  "nearby_landmark": "Near QA Metro Station",
  "additional_location_notes": null,
  "visit_contact_phone": "+971500001234"
}
```
  Field constraints mirror `cart_locations`' own `CHECK` constraints exactly (lengths, required vs
  nullable). `property_type_id` and `area_id` must reference **active** rows (`property_types` /
  `areas`); `other_property_type_name` is required only when the resolved `property_type_id`'s code
  is `OTHER` (checked in `SaveCheckoutLocationAction`, not the `FormRequest` — the same
  "shape-only request, catalog-aware Action" split Cart already uses for option validation). There is
  no separate `city_id` field: `areas.city_id` already determines the city, exactly as Profile's
  `PATCH /v1/profile` already established.
- **Success status**: `200 OK` — full updated checkout (§1's shape).
- **Error statuses**: `404 Not Found` (no ACTIVE cart); `422 Unprocessable Entity` (invalid/inactive
  `property_type_id` or `area_id`, or `other_property_type_name` missing for `OTHER`).
- **Business behavior**: One transaction, lock order `USER → CART → CART_LOCATION` (§13). The
  existing `cart_locations` row (if any) is locked before deciding insert vs. update, so two
  concurrent `PUT` calls for the same cart never interleave.
- **Ownership**: the location written is always the caller's own ACTIVE cart's location — there is no
  `cart_uuid` request field to spoof.

---

## 3. List Appointment Slots

- **HTTP method / route**: `GET /v1/checkout/appointment-slots`
- **Purpose**: Read-only availability list for scheduling the cart's single appointment (per
  `docs/05-system-requirements/07-business-rules.md`: "the customer shall select one available
  appointment for the entire cart").
- **Success status**: `200 OK`; **error**: `404 Not Found` when the customer has no ACTIVE cart.
- **Success response**:
```json
{
  "success": true,
  "message": "Appointment slots retrieved successfully.",
  "data": {
    "appointment_slots": [
      {
        "uuid": "1210862f-....",
        "starts_at": "2026-08-10T09:00:00+00:00",
        "ends_at": "2026-08-10T11:00:00+00:00",
        "remaining_capacity": 3,
        "time_window": { "code": "STANDARD", "name": "Standard Hours" }
      }
    ]
  }
}
```
- **Business behavior**: A slot is returned only when `appointment_slots.is_active = 1`, its
  `appointment_time_windows` row is also `is_active = 1`, `starts_at` is still in the future, and it
  has remaining capacity — `booking_capacity` minus every currently-occupying hold (`released_at IS
  NULL` and either `converted_at IS NOT NULL` or `expires_at > now()`). `internal_note` is never
  returned. `time_window.code`/`.name` are the only time-window fields exposed — never the internal
  numeric `time_window_id`.
- **Scope note**: `appointment_slots` carries no `service_id`/zone dimension in the schema, so BLUE
  V1 cannot scope the list to "slots relevant to this cart's services" beyond what the table already
  represents — every currently bookable slot is returned identically regardless of cart contents.
  This is a schema fact, not an oversight; see the source doc-comment on `GetAppointmentSlotsAction`.

---

## 4. Create Appointment Hold

- **HTTP method / route**: `POST /v1/checkout/appointment-hold`
- **Request body**: `{ "appointment_slot_uuid": "<uuid>" }` — slot identity only. No `time_window`,
  `price`, or any other context/monetary field is ever read from this request.
- **Success status**: `201 Created` — full updated checkout (§1's shape) with `data.checkout.
  appointment` populated.
- **Error statuses**: `404 Not Found` (no ACTIVE cart; the slot does not exist; the slot is inactive;
  or the slot's `appointment_time_windows` row is inactive — all reported identically, matching
  Cart's service-lookup precedent of never distinguishing "missing" from "currently ineligible");
  `422 Unprocessable Entity` (the slot's `starts_at` has already passed, or it is at capacity).
- **Business behavior** (`App\Actions\Checkout\CreateAppointmentHoldAction`): a cart may only ever
  have one open (`released_at IS NULL AND converted_at IS NULL`) hold. Selecting a new slot — even a
  second call for the same slot — **replaces** the cart's previous open hold: the new hold's capacity
  is confirmed *before* the old hold is released, so a request that fails on capacity never costs the
  customer their existing hold. Lock order: `USER → CART → SLOT → HOLD(s)` (§13); the
  `appointment_slots` row lock (joined with its `appointment_time_windows` row) serializes every
  concurrent hold attempt on that slot, so the capacity count is always read fresh before the
  accept/reject decision — this is BLUE V1's concurrency-safety mechanism for a limited-capacity slot
  (`SELECT ... FOR UPDATE` inside `DB::transaction()`). `expires_at` is `now() +
  config('checkout.appointment_hold_ttl_minutes')` — see "Appointment hold TTL" below.

---

## 5. Release Appointment Hold

- **HTTP method / route**: `DELETE /v1/checkout/appointment-hold`
- **Request body**: none. There is no `hold_uuid` path or body parameter — the hold released is
  always resolved from the caller's own ACTIVE cart, so there is no ownership check to bypass:
  another customer's hold is simply never reachable through this endpoint.
- **Success status**: `200 OK` always — releasing with no open hold (or no ACTIVE cart at all) is a
  safe no-op, matching `DELETE /v1/cart`'s "clearing an empty cart is not an error" convention.
- **Business behavior**: sets `released_at = now()` on the cart's open hold, if any. Lock order:
  `USER → CART → HOLD`.

---

## Pricing context trust boundary

`App\Support\Checkout\CheckoutContextResolver` builds the `context` array passed to
`PricingEngine::evaluate()`. Both attributes it resolves are looked up through a generic mapping
table, never a hardcoded `if area == X` / `if hour >= 18` branch, and both resolve to the referenced
lookup row's numeric `id` — opaque to this resolver, matched by whatever `pricing_rule_conditions`
an admin has configured against it, exactly like every other `CONTEXT_ATTRIBUTE` value in the system.

- **`SERVICE_ZONE`** — `cart_locations.area_id → service_zone_areas → service_zones.id`, only when
  the matched `service_zones` row is `is_active = 1`. `service_zone_areas.area_id` is that table's
  primary key, so the schema itself guarantees at most one zone per area — resolution is a lookup,
  never a search or a guess. If the cart's area has no mapping row, or its mapped zone has since been
  deactivated, `SERVICE_ZONE` is left **unresolved** (a rule depending on it reports
  `MISSING_CONTEXT`, never a guessed value). **Never** derived from `customer_profiles.area_id` (the
  account's registered address) — a customer's profile address may only ever be a Flutter UI
  convenience (e.g. pre-filling the location form), never an input to this resolver. See
  `CheckoutServiceZoneTest`.

- **`TIME_WINDOW`** — the cart's current active appointment hold (unreleased, unconverted,
  unexpired) → its `appointment_slots` row → that slot's `appointment_time_windows.id`, only when the
  window is still `is_active = 1`. With no usable hold, or a since-deactivated window, `TIME_WINDOW`
  is left **unresolved**. Flutter submits only `appointment_slot_uuid` to `POST
  /checkout/appointment-hold` — never `time_window`, `time_window_id`, or a literal like
  `AFTER_HOURS` — so there is no request field carrying a time-window value to spoof; the backend
  always derives it from the already-validated, already-active slot the hold actually points to. See
  `CheckoutTimeWindowTest`.

Both attributes reprice live: changing the saved location changes `SERVICE_ZONE` on the very next
`GET /checkout` (or any mutating response), and holding a different slot changes `TIME_WINDOW` the
same way — see `CheckoutContextCombinationTest`.

---

## Pricing status & readiness

Reuses Cart's aggregate semantics exactly (`App\Support\Pricing\PricingResultAggregator`, extracted
from `CartPresenter` in this phase): `QUOTE_REQUIRED` beats `MISSING_CONTEXT` beats `UNAVAILABLE`
beats `PRICED`, worst case across all items. `total` is the authoritative sum of every item's
`pricing.line_total` **only** when `pricing_status = "PRICED"`; otherwise `null`.

`ready_for_payment` is strictly stricter than `pricing_status === "PRICED"`. Per
`docs/05-system-requirements/07-business-rules.md` ("all services must use the same property
information and appointment"), it is `true` only when **all** of the following hold:

1. the cart has at least one item,
2. `location` is set,
3. `appointment` is set (an unexpired, unreleased, unconverted hold exists),
4. `pricing_status === "PRICED"`.

`ready_for_payment` is the sole readiness signal Checkout exposes; **payment itself is out of
scope for Phase 5** — no gateway call, no payment attempt row, no booking row is ever created here.

---

## Transactions / lock order

Every Checkout write follows the same global order established by Cart, extended one level:

```
USER → CART → CART_LOCATION
USER → CART → SLOT → HOLD(s)
```

`SaveCheckoutLocationAction` locks `users` → the ACTIVE `carts` row → the existing `cart_locations`
row (if any). `CreateAppointmentHoldAction` locks `users` → the ACTIVE `carts` row → the target
`appointment_slots` row → the occupying `appointment_holds` rows for that slot. This order is never
reversed by another Checkout write path, and is consistent with Cart's `USER → CART → CART ITEM →
SELECTIONS` order (a Checkout write never locks a `cart_items` row, so there is no cycle between the
two paths).

---

## Appointment hold TTL

`appointment_holds` only constrains `expires_at > held_at` at the schema level (`chk_appointment_
holds_expiration`) — the actual duration is an explicit BLUE V1 product decision, not something
derived from the schema. **Approved default: 10 minutes**, configurable through
`CHECKOUT_APPOINTMENT_HOLD_TTL_MINUTES` / `config('checkout.appointment_hold_ttl_minutes')`,
following the same env-configurable-TTL pattern already used for `AUTH_ACCESS_TOKEN_TTL_MINUTES`.
The value is never hardcoded inside an Action — `CreateAppointmentHoldAction` always reads it from
config, so changing the duration is a one-line config/env change with no code change. See
`AppointmentHoldTtlTest`.

## Data model reference

```
areas ──< service_zone_areas >── service_zones
  (area_id is service_zone_areas' primary key: an area maps to at most one zone)

appointment_time_windows ──< appointment_slots >── appointment_holds
  (appointment_slots.time_window_id is NOT NULL: every usable slot has a window)
```

`service_zones`, `service_zone_areas`, and `appointment_time_windows` are generic, code/name/
is_active lookup and mapping tables (see `database/blue_v1_schema.sql`) — the same shape as every
other BLUE V1 reference table (`property_types`, `cart_statuses`, ...). They carry no service-specific
columns and no application code branches on their `code` values; a `code` like `STANDARD` or
`AFTER_HOURS` is only ever a customer-facing label, matched by an admin-configured
`pricing_rule_conditions` row, never by a Laravel `if` statement. Real zone/window rows are business
data the admin/scheduling layer owns and are intentionally **not** seeded — `blue_db` keeps both
tables empty until that layer populates them; only test fixtures create rows, transactionally, in
`blue_test_db`.
