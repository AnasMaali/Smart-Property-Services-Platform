# Flutter Booking/Refund API Handoff

This document is for the Flutter developer implementing the customer-facing Booking and
cancellation/refund screens. It describes the **already-implemented, already-tested,
real-Stripe-verified** backend contract only. No Flutter code is included or implied here.

## Branch / checkpoint

- Branch: `feat/booking-refund-automation`
- Base this work on the current tip of that branch once the final backend commit for this
  phase (business-timezone fix) lands on it. As of this handoff, the branch's backend code,
  tests, and this document are complete and verified; only that one commit is still pending.

## Business timezone

**Asia/Dubai** (UTC+4, no DST) is the one business timezone used to interpret an
appointment/cancellation's *calendar day* for the refund policy below
(`config('cancellation.timezone')`, default `Asia/Dubai`, overridable via
`BOOKING_CANCELLATION_TIMEZONE`). Every timestamp the API returns is still plain ISO-8601 UTC
(e.g. `"2026-08-30T10:00:00+00:00"`) — the backend does the Asia/Dubai calendar-day math
server-side and simply tells you the result (`percentage`, `cancellable`, `reason_code`). The
Flutter app never needs to do timezone conversion itself to implement this policy correctly —
it only needs to **display** the ISO-8601 instants it receives in the user's local time.

## Currency

**AED only.** BLUE V1 supports exactly one business/payment currency. Every money-bearing
response still carries a `currency` object (`{code, symbol, decimal_places}` or `{code, name,
minor_unit}` depending on the endpoint — see field lists below) for correct display formatting,
but the app should not build multi-currency UI/logic around it.

## Required DB migrations (informational — already applied to `blue_db` for this environment)

- `database/phase18_appointment_hold_reschedule_schema_migration.sql` — appointment hold/reschedule schema (`appointment_holds.superseded_at`, etc.)
- `database/phase19_booking_refund_automation_migration.sql` — `booking_refund_statuses` (PENDING / SUCCEEDED / FAILED / RECONCILIATION_REQUIRED) and `booking_refunds`.

Neither migration changes any request/response shape below; they only back the `refund_due`
fields already documented here.

---

## THE ONE RULE THAT MATTERS MOST

> **The Flutter app must NEVER calculate a refund percentage or a refund amount, and must
> NEVER decide locally whether a Booking is cancellable.** Every percentage, every amount, and
> every cancellable/not-cancellable decision comes from the backend response, verbatim. The
> backend is authoritative — it already runs a real, tested, Stripe-integrated policy engine
> (`App\Support\Booking\RefundEligibilityCalculator`) that accounts for the exact appointment
> calendar day, the exact captured payment amount, and standard monetary rounding. The app's
> only job is to render the numbers it is given.

The customer app must also never expose, anywhere in its UI, logs, or crash reports:

- A Stripe PaymentIntent id, Charge id, or refund id (`re_...`)
- Any "provider" name (Stripe is never named in a customer-facing string)
- Webhook internals (event ids, event types, ledger status)
- An idempotency key
- `failure_code` / `failure_message` (these do not even exist on the customer-facing response — see below)

---

## Refund policy (for context only — do not re-implement)

Evaluated server-side, Asia/Dubai calendar day:

| Cancellation timing (Asia/Dubai) | Result |
|---|---|
| Before the appointment's calendar day | **100%** refund |
| On the appointment's calendar day, before `starts_at` | **75%** refund |
| At or after `starts_at` | Cancellation **unavailable** |

Refund destination is always **"Original payment method"** — there is no wallet, store credit,
or manual payout path. Refunds execute automatically via Stripe; the app never asks the
customer for card details to receive a refund.

## Safe customer-facing refund status labels

`booking_refund_statuses.code` → what the backend returns in `refund_due.status` /
`refund_due.status` is one of exactly these four codes. Map them to UI copy exactly like this
— never show the raw code, never show anything else:

| Backend code | Safe customer label |
|---|---|
| `PENDING` | **Refund processing** |
| `SUCCEEDED` | **Refunded** |
| `FAILED` | **Refund needs attention** |
| `RECONCILIATION_REQUIRED` | **Refund needs attention** |

`FAILED` and `RECONCILIATION_REQUIRED` deliberately map to the **same** customer copy — the
distinction between "Stripe rejected the refund" and "Stripe reported something unexpected" is
operator-only and is never surfaced to the customer. In both cases, the correct customer
instruction is to contact support; no retry action should be offered in the app.

A `status` of `null` (no refund obligation row yet resolved, e.g. immediately after a
synchronous cancel/refund attempt that hasn't returned) should also render as **"Refund
processing"**.

---

## Customer endpoints

All four require `Authorization: Bearer <access_token>` (the `auth.customer` middleware
group in `routes/api.php`). All responses share the envelope
`{ "success": bool, "message": string, "data": {...} }` (or `{"success": false, "message":
"...", "data": null}` / `{"errors": {...}}` for 422s).

### 1. Booking list

- **Method / URL**: `GET /api/v1/bookings`
- **Auth**: required (`auth.customer`)
- **Request body**: none
- **Response**: `data.bookings` — array of the same Booking shape as endpoint 2 below (one
  presenter, `App\Support\Booking\BookingPresenter`, backs both the list and the detail read).

### 2. Booking detail

- **Method / URL**: `GET /api/v1/bookings/{booking}` — `{booking}` is the Booking's UUID.
- **Auth**: required. A UUID that doesn't exist, or belongs to another customer, both return a
  plain `404` — ownership is never leaked through a different status code.
- **Request body**: none
- **Important response fields** (`data.booking`):
  - `uuid`, `booking_number`, `status` (`PAID` / `ASSIGNED` / `IN_PROGRESS` / `COMPLETED` /
    `CANCELLED` — map to friendly copy client-side)
  - `contract` — `null` for a STANDARD (Stripe-payment-backed) Booking, an object for a
    Contract-covered Booking. **When non-null, never show any Stripe/refund-percentage UI** —
    see "Contract Bookings" below.
  - `currency` — `{code, symbol, decimal_places}`
  - `total` — decimal string, the amount paid
  - `location` — `{property_type_name, other_property_type_name, country_name, city_name,
    area_name, street_name, address_line, building_name_or_number, floor_number, unit_number,
    nearby_landmark, additional_location_notes, visit_contact_phone}`
  - `appointment.slot` — `{uuid, starts_at, ends_at, time_window: {code, name}}` (ISO-8601 UTC)
  - `items[]` — `{uuid, service: {uuid, code, name}, quantity, status, completed_at,
    cancelled_at, pricing: {..., line_total}}`
  - `cancelled_at` — null unless `status == "CANCELLED"`
  - `refund_due` — **null unless `status == "CANCELLED"` AND this was a STANDARD (non-Contract)
    Booking.** Shape: `{percentage, amount, execution, status, method, requested_at,
    succeeded_at, failed_at}`. `method` is always the literal string `"ORIGINAL_PAYMENT_METHOD"`.
    `execution` is always `"AUTOMATIC"`. **No `failure_code`, `failure_message`, provider name,
    or provider reference is present on this customer-facing shape** — see the Admin-only
    equivalent's extra fields, which the customer API deliberately omits.

**Real example, captured against a live Stripe TEST-mode payment during this phase's E2E
verification** (a cancelled, 100%-refunded Booking, `SUCCEEDED`):

```json
{
  "success": true,
  "message": "Bookings retrieved successfully.",
  "data": {
    "bookings": [
      {
        "uuid": "fa3c572f-51a8-4bcc-a65a-d77b25e6f173",
        "booking_number": "BLU-9759A460A2",
        "status": "CANCELLED",
        "source": "STANDARD",
        "contract": null,
        "currency": { "code": "AED", "symbol": "د.إ", "decimal_places": 2 },
        "total": "100.000000",
        "location": {
          "property_type_name": "Apartment",
          "country_name": "United Arab Emirates",
          "city_name": "Abu Dhabi",
          "area_name": "Al Khalidiyah",
          "street_name": "Corniche Road",
          "address_line": "E2E test address line",
          "building_name_or_number": "E2E Tower",
          "floor_number": "5",
          "unit_number": "501",
          "nearby_landmark": "Near Corniche",
          "additional_location_notes": null,
          "visit_contact_phone": "+971508827029"
        },
        "appointment": {
          "slot": {
            "uuid": "ddac0707-cad1-42d9-9c3b-b981174e98f9",
            "starts_at": "2026-08-30T10:00:00+00:00",
            "ends_at": "2026-08-30T12:00:00+00:00",
            "time_window": { "code": "BLUE_STRIPE_E2E_TEST_WINDOW", "name": "BLUE_STRIPE_E2E_TEST Window" }
          }
        },
        "items": [
          {
            "uuid": "c8338ff1-333b-4b26-9fe3-34bde2a380bc",
            "service": { "uuid": "7cec4b3d-971d-11f1-be4e-00155d919f4a", "code": "BLUE_STRIPE_E2E_TEST_SERVICE", "name": "BLUE_STRIPE_E2E_TEST Service" },
            "quantity": 1,
            "status": "CANCELLED",
            "completed_at": null,
            "cancelled_at": "2026-08-28T23:32:15.000000Z",
            "pricing": { "unit_total": "100.000000", "line_total": "100.000000" }
          }
        ],
        "created_at": "2026-08-28T23:31:44+00:00",
        "cancelled_at": "2026-08-28T23:32:15.000000Z",
        "refund_due": {
          "percentage": 100,
          "amount": "100.000000",
          "execution": "AUTOMATIC",
          "status": "SUCCEEDED",
          "method": "ORIGINAL_PAYMENT_METHOD",
          "requested_at": "2026-08-28T23:32:15.504639Z",
          "succeeded_at": "2026-08-28T23:32:16.636704Z",
          "failed_at": null
        }
      }
    ]
  }
}
```

### 3. Cancellation preview (call this BEFORE the customer confirms cancellation)

- **Method / URL**: `GET /api/v1/bookings/{booking}/cancellation-preview`
- **Auth**: required, same ownership rule as above (404 for foreign/unknown)
- **Request body**: none
- **Response** (`data.preview`):
  - `cancellable` — boolean. **This is the only signal the app should use to decide whether to
    show the refund confirmation UI or the "cancellation unavailable" message.**
  - `reason_code` — `BEFORE_APPOINTMENT_DAY` / `APPOINTMENT_DAY_BEFORE_START` /
    `APPOINTMENT_ALREADY_STARTED` / `CONTRACT_ENTITLEMENT`. Informational only; the app should
    branch on `cancellable` + `refund` (below), not string-match this beyond the Contract check.
  - `appointment.starts_at` — ISO-8601 UTC
  - `paid_amount`, `currency` — null for a Contract Booking (nothing was paid via Stripe)
  - `refund` — **null when not cancellable, or when this is a Contract Booking.** Otherwise
    `{percentage, amount, execution: "AUTOMATIC", method: "ORIGINAL_PAYMENT_METHOD"}`.

**Real example — 100% case** (captured live, before the appointment's calendar day):

```json
{
  "success": true,
  "message": "Cancellation preview retrieved successfully.",
  "data": {
    "preview": {
      "cancellable": true,
      "reason_code": "BEFORE_APPOINTMENT_DAY",
      "appointment": { "starts_at": "2026-08-30T10:00:00+00:00" },
      "paid_amount": "100.000000",
      "currency": { "code": "AED", "symbol": "د.إ", "decimal_places": 2 },
      "refund": {
        "percentage": 100,
        "amount": "100.000000",
        "execution": "AUTOMATIC",
        "method": "ORIGINAL_PAYMENT_METHOD"
      }
    }
  }
}
```

**Real example — 75% case** (same Asia/Dubai calendar day, before `starts_at`):

```json
{
  "success": true,
  "message": "Cancellation preview retrieved successfully.",
  "data": {
    "preview": {
      "cancellable": true,
      "reason_code": "APPOINTMENT_DAY_BEFORE_START",
      "appointment": { "starts_at": "2026-08-29T16:00:00+00:00" },
      "paid_amount": "100.000000",
      "currency": { "code": "AED", "symbol": "د.إ", "decimal_places": 2 },
      "refund": {
        "percentage": 75,
        "amount": "75.000000",
        "execution": "AUTOMATIC",
        "method": "ORIGINAL_PAYMENT_METHOD"
      }
    }
  }
}
```

**Real example — cancellation unavailable** (appointment already started):

```json
{
  "success": true,
  "message": "Cancellation preview retrieved successfully.",
  "data": {
    "preview": {
      "cancellable": false,
      "reason_code": "APPOINTMENT_ALREADY_STARTED",
      "appointment": { "starts_at": "2026-08-28T23:36:00+00:00" },
      "paid_amount": "100.000000",
      "currency": { "code": "AED", "symbol": "د.إ", "decimal_places": 2 },
      "refund": null
    }
  }
}
```

Suggested UI copy for the `cancellable: false` case (do not invent your own wording):

> **Cancellation unavailable**
> The appointment has already started. Please contact support if you need assistance.

### 4. Cancel booking

- **Method / URL**: `POST /api/v1/bookings/{booking}/cancel`
- **Auth**: required, same ownership rule
- **Request body**: `{}` (empty — no `reason`, no `percentage`, no `amount` field exists on
  this request; anything the customer sends beyond an empty body is ignored)
- **Success response** (`200`): `data.booking` (`{uuid, status, cancelled_at}`) and
  `data.refund_due` (`{percentage, amount, execution}` — the *immediate* policy result only,
  not the full execution-status shape). **After a successful cancel, re-fetch the full Booking
  via endpoint 2 (`GET /api/v1/bookings/{booking}`) rather than trying to render this smaller
  response shape** — that re-fetch is what carries `refund_due.status` for the post-cancellation
  UI (§ Post-cancellation refund UI below).
- **Idempotent**: calling this twice on an already-cancelled Booking returns `200` again with
  the same original percentage/amount (the ORIGINAL cancellation time is what was evaluated,
  never re-evaluated against "now" on a retry) — safe to retry on a timeout without asking the
  customer.
- **Rejection** (`409`): `{"success": false, "message": "This Booking cannot be cancelled because its appointment has already started.", "data": null}` — this should be unreachable in normal use if the app always checks the preview first, but must still be handled gracefully (show the same "Cancellation unavailable" copy) since it is possible for time to pass between showing the preview and the customer confirming.

**Real example — successful 100% cancellation** (captured live):

```json
{
  "success": true,
  "message": "Booking cancelled successfully.",
  "data": {
    "booking": {
      "uuid": "fa3c572f-51a8-4bcc-a65a-d77b25e6f173",
      "status": "CANCELLED",
      "cancelled_at": "2026-08-28T23:32:15.000000Z"
    },
    "refund_due": {
      "percentage": 100,
      "amount": "100.000000",
      "execution": "AUTOMATIC"
    }
  }
}
```

---

## Suggested confirmation-screen copy (values from the preview response only)

```
Amount paid
AED 100.00

Refund
75%

You will receive
AED 75.00

Returned to
Original payment method

[ Cancel booking & refund AED 75.00 ]
```

Format `amount`/`paid_amount` (a decimal string, e.g. `"75.000000"`) using
`currency.decimal_places` (always `2` for AED) — truncate/pad to that many decimals for
display; never re-parse and re-round the number, and never perform any percentage math on it
yourself.

## Post-cancellation refund UI (Booking Details, `status == "CANCELLED"`)

- If `booking.contract != null` → this was a Contract Booking; `refund_due` will be `null`.
  Show contract-appropriate wording only (e.g. "This visit was covered by your service
  contract — no separate refund applies"). **Never show a Stripe/refund-percentage section for
  a Contract Booking.**
- Otherwise, `refund_due` is present. Render `refund_due.percentage`/`refund_due.amount` as the
  refund breakdown, and map `refund_due.status` through the four-row table above for the status
  label. Never show "Refunded" unless `status == "SUCCEEDED"` — a `null` or `"PENDING"` status
  must read as "Refund processing", never as already complete.

## Contract Bookings — full checklist

- `booking.contract != null` on both the list/detail read and (for the preview)
  `preview.reason_code == "CONTRACT_ENTITLEMENT"`.
- Never show: refund percentage, refund amount, "Automatic via Stripe" execution wording,
  "Original payment method" destination wording, or any `refund_due`/`refund` object (both are
  always `null` for these Bookings).
- The Cancel Booking confirmation for a Contract Booking should say something like "This will
  cancel your contract-covered visit. No payment refund applies." — never a monetary figure.

---

## Remaining backend gaps relevant to the Flutter implementation

- **No "technician assigned" field exists yet on the customer-facing Booking read.** If the
  product requirement to show an assigned technician on Booking Details still stands, that is a
  backend addition needed before the Flutter screen can show it — do not fabricate this data
  client-side.
- No customer-facing push/webhook exists to notify the app when a `PENDING` refund later
  resolves to `SUCCEEDED`/`FAILED` — the app must re-fetch Booking Details (e.g. on
  pull-to-refresh or a timed poll) to see a status change, there is no live-update channel yet.
