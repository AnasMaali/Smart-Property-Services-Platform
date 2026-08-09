# BLUE V1 — Cart API Contract

Base URL: `{{base_url}}` (local default: `http://127.0.0.1:8000/api/v1`)

This document describes the Phase 4 Cart endpoints as actually implemented in
`backend/routes/api.php`, their Actions (`App\Actions\Cart\*`) and Controllers
(`App\Http\Controllers\Api\V1\Cart\*`), and verified against `backend/tests/Feature/Cart/*`.
It documents only what exists in code — no aspirational or planned behavior is included.

Cart is the first authenticated, mutating surface built on top of the Phase 3.5 **PricingEngine**
(`App\Support\Pricing\PricingEngine`). Every endpoint below that returns a cart — GET, add, update,
remove, clear — calls `PricingEngine::evaluate()` for every item, live, on every response. There is
exactly one pricing calculation in the system; Cart never duplicates, caches, or reimplements it.

## Global notes

- All responses are JSON, sharing the envelope `{ "success": bool, "message": string, "data": object|null }`.
  Validation and business-rule failures also include `"errors": [string, ...]`.
- **Every endpoint requires authentication** — `Authorization: Bearer {{access_token}}`, enforced by
  the `auth.customer` middleware (see `docs/api-contracts/authentication-v1.md`). A missing or
  invalid token returns `401` with the generic session-invalid message, before any cart logic runs.
- BLUE V1 has exactly one active currency (AED), resolved by `currencies.code = 'AED'`
  (`App\Support\Pricing\DefaultCurrency`) — never a hardcoded numeric currency id.
- All `BINARY(16)` identifiers (cart, cart item, service, service option, choice, pricing scheme
  version) are converted to standard UUID strings before leaving the API. Raw binary, internal
  pricing rule/condition data, and internal numeric lookup ids are never returned.
- **The client never controls price.** Request bodies are validated through a Laravel `FormRequest`
  whose `rules()` enumerate exactly the accepted keys (`service_uuid`, `quantity`, `options`); a
  client-supplied `price`, `base_amount`, `subtotal`, `total`, `currency`, `pricing_rule_id`, or
  `pricing_scheme_id` field is silently dropped by `$request->validated()` and never read by any
  Action. Cart pricing is always the live output of `PricingEngine::evaluate()`.
- **Carts and cart items store no monetary snapshot.** `carts` and `cart_items` hold only service,
  quantity, and selections — no price/amount/currency-total column exists on either table. Every
  amount in every response below is computed at request time. See §7 for what this means for the
  `carts/cart_items` "total amount" wording still found in `docs/05-system-requirements/`.

---

## Endpoint summary

| # | Feature | Method | Route | Auth required |
|---|---|---|---|---|
| 1 | Get Cart | GET | `/v1/cart` | Yes |
| 2 | Add Cart Item | POST | `/v1/cart/items` | Yes |
| 3 | Update Cart Item | PATCH | `/v1/cart/items/{item}` | Yes |
| 4 | Remove Cart Item | DELETE | `/v1/cart/items/{item}` | Yes |
| 5 | Clear Cart | DELETE | `/v1/cart` | Yes |

`{item}` is `cart_items.id` as a standard UUID string.

---

## Cart lifecycle

A customer has at most one **ACTIVE** cart at a time (`cart_statuses.code = 'ACTIVE'`, resolved by
code, never a hardcoded id). `GET /v1/cart` **never creates a cart as a side effect** — with no
ACTIVE cart it returns a safe, empty-cart representation (`data.cart.uuid: null`, `items: []`) and
writes nothing.

`POST /v1/cart/items` creates the ACTIVE cart the first time a customer adds an item, and every
later add reuses it. To make concurrent first-add requests from the same customer safe, the Action
locks the `users` row for that customer **first**, then finds-or-creates the ACTIVE `carts` row
under lock, and only then locks/creates the cart item and its selections — lock order
`USER → CART → CART ITEM → SELECTIONS` is used by every mutating endpoint. Removing the last item
from a cart does **not** delete the cart row; `DELETE /v1/cart` explicitly clears every item but
always keeps the cart itself.

Every item mutation proves ownership through the full chain
`cart_items.cart_id → carts.id → carts.customer_user_id = <authenticated customer>`. A `{item}`
UUID that exists but belongs to another customer's cart, or does not exist at all, or is not
syntactically a UUID, is rejected identically as `404 Not Found` — ownership is never leaked through
a different status code.

---

## Request option format (Add / Update)

`options` is an array of `{ "option_uuid": "<uuid>", ...one value field... }`. Exactly one value
field is used, matching the option's `service_option_types.code`:

| Option type | Value field | Extra schema enforced |
|---|---|---|
| `TEXT` | `text_value` (string) | trimmed length 1–1000 chars |
| `NUMBER` | `numeric_value` (number) | `service_option_numeric_rules`: min/max/step/decimal places |
| `BOOLEAN` | `boolean_value` (true/false) | — |
| `SINGLE_SELECT` / `MULTI_SELECT` | `choice_uuids` (array of uuid) | `service_option_selection_rules`: min/max selections; every choice must be active and belong to the option; no duplicate choices |

All of this is enforced by one generic, schema-driven validator (`App\Support\Cart\
CartSelectionValidator`) that loads the submitted service's actual `service_options` (+ numeric/
selection rules + choices) — there is no per-service or per-option-type-name branch anywhere in
Cart. A submitted `option_uuid` that does not resolve to an **active** option belonging to the
**submitted service** is rejected the same way whether it is unknown, inactive, or actually belongs
to a different service — Cart never confirms or denies another service's catalog shape. Every
`is_required = 1` option must be present; duplicate `option_uuid` entries within one request are
rejected.

---

## 1. Get Cart

- **HTTP method / route**: `GET /v1/cart`
- **Purpose**: Populates the Cart screen with the customer's current ACTIVE cart, fully repriced.
- **Tables read**: `carts`, `cart_items`, `cart_item_option_selections`,
  `cart_item_option_choice_selections`, `services`, `service_media`, `currencies`, plus everything
  `PricingEngine::evaluate()` reads per item.
- **Success status**: `200 OK` always (an empty cart is not an error).
- **Success response** (ACTIVE cart with one PRICED item):
```json
{
  "success": true,
  "message": "Cart retrieved successfully.",
  "data": {
    "cart": {
      "uuid": "b7c5965d-16a7-4283-86f5-59787bbc941c",
      "currency": { "code": "AED", "symbol": "د.إ", "decimal_places": 2 },
      "pricing_status": "PRICED",
      "required_context": [],
      "requires_quote": false,
      "items": [
        {
          "uuid": "6b0e2c8a-8a14-44d5-9801-09166be0c4b7",
          "service": {
            "uuid": "1210862f-7d48-4834-9285-b4752bff7676",
            "slug": "ac-deep-clean",
            "name": "AC Deep Cleaning",
            "primary_image": null
          },
          "quantity": 1,
          "options": [
            { "option_uuid": "7a1d....", "numeric_value": "4.000000" }
          ],
          "pricing": {
            "pricing_status": "PRICED",
            "currency": "AED",
            "pricing_scheme_version": "9d1e....",
            "base_amount": "100.000000",
            "adjustments": [ { "rule_code": "BASE", "label": "Base price", "effect_type": "SET_PRICE", "amount_or_factor": "100.000000", "running_total_after": "100.000000" } ],
            "unit_total": "100.000000",
            "quantity": 1,
            "line_total": "100.000000",
            "required_context": []
          }
        }
      ],
      "total": "100.000000"
    }
  }
}
```
- **Empty-cart response** (no ACTIVE cart — no row is created):
```json
{
  "success": true,
  "message": "Cart retrieved successfully.",
  "data": {
    "cart": {
      "uuid": null,
      "currency": { "code": "AED", "symbol": "د.إ", "decimal_places": 2 },
      "pricing_status": "PRICED",
      "required_context": [],
      "requires_quote": false,
      "items": [],
      "total": "0.000000"
    }
  }
}
```
- **Business behavior**: Every item is repriced by `PricingEngine::evaluate()` using selections
  reloaded straight from `cart_item_option_selections` / `cart_item_option_choice_selections` — a
  selected option that has since been deactivated does not break repricing, it simply no longer
  matches any condition. See §2 for the cart-aggregate `pricing_status`/`total` rules, which apply
  identically here and after every mutation below.

---

## 2. Cart-aggregate pricing status

Every endpoint that returns a cart computes one aggregate status across all its items, worst case
first:

1. `QUOTE_REQUIRED` — if any item's pricing is `QUOTE_REQUIRED`.
2. else `MISSING_CONTEXT` — if any item's pricing is `MISSING_CONTEXT`.
3. else `UNAVAILABLE` — if any *already-persisted* item now evaluates `UNAVAILABLE` (its scheme was
   retired/expired after it was added — Add/Update never let an item reach this state, but GET must
   still be able to report it).
4. else `PRICED` — including a cart with zero items, which is trivially `PRICED` with `total = "0.000000"`.

`data.cart.total` is the authoritative cart total **only** when `pricing_status = "PRICED"` — the
sum of every item's `pricing.line_total`. In every other case `total` is `null`; a total is never
fabricated for a cart that cannot be fully priced yet. `required_context` is the sorted, deduplicated
union of every item's `pricing.required_context`. `requires_quote` is `true` when any item is
`QUOTE_REQUIRED`.

`Add` and `Update` (below) additionally **reject** a submission whose own pricing evaluates
`UNAVAILABLE` — there is no currently-effective pricing configuration to charge, so nothing is
written. `QUOTE_REQUIRED` and `MISSING_CONTEXT` are accepted; BLUE V1 has no context-resolution
mechanism yet, so any pricing rule with a `CONTEXT_ATTRIBUTE` condition always yields
`MISSING_CONTEXT` today.

---

## 3. Add Cart Item

- **HTTP method / route**: `POST /v1/cart/items`
- **Request body**:
```json
{
  "service_uuid": "<uuid>",
  "quantity": 1,
  "options": [
    { "option_uuid": "<uuid>", "numeric_value": 4 }
  ]
}
```
  `quantity` defaults to `1` when omitted; range `1–1000` (matches `cart_items.quantity`'s CHECK
  constraint). `options` defaults to `[]`.
- **Success status**: `201 Created` — response body is the full updated cart (§1's shape).
- **Error statuses**:
  - `404 Not Found` — `service_uuid` does not exist or the service is inactive.
  - `422 Unprocessable Entity` — the service lacks the `CART_ELIGIBLE` capability (checked generically
    through `service_capabilities` / `service_capability_types`, never a hardcoded
    emergency/subscription/quote branch); one or more submitted options fail validation (§ Request
    option format); or the resulting pricing is `UNAVAILABLE`. `errors` carries the specific reasons.
- **Business behavior**: One DB transaction: lock `users` row → find-or-lock-or-create the ACTIVE
  `carts` row → validate service + capability + options → `PricingEngine::evaluate()` → reject if
  `UNAVAILABLE` → insert `cart_items` (at the next `display_order` within the cart; existing items'
  order is never rewritten) → insert selections. Every write happens only after every check passes,
  so a rejected request leaves nothing partially written. Adding the same service twice never merges
  — two separate `cart_items` rows are created.

---

## 4. Update Cart Item

- **HTTP method / route**: `PATCH /v1/cart/items/{item}`
- **Request body** (both keys optional, at least meaningfully one expected):
```json
{ "quantity": 3, "options": [ { "option_uuid": "<uuid>", "boolean_value": true } ] }
```
  When `options` is supplied it is the **full replacement** selection set — previously-persisted
  selections not present in the new array are deleted, never merged.
- **Success status**: `200 OK` — full updated cart (§1's shape).
- **Error statuses**: `404 Not Found` (unknown `{item}`, or owned by another customer — see
  Ownership below); `422 Unprocessable Entity` (service no longer active/eligible, invalid options,
  or `UNAVAILABLE` pricing).
- **Business behavior**: Every update **fully revalidates and reprices**, even when only `quantity`
  changes and `options` is omitted — the currently-persisted selections are reloaded and re-checked
  against the live catalog schema. One transaction: lock `users` → lock the ACTIVE `carts` row → lock
  the owned `cart_items` row → validate → reprice → write `quantity` and (if `options` was supplied)
  delete-and-reinsert every selection row. All validation/pricing checks run **before** any write, so
  a rejected PATCH changes nothing — proven by `UpdateCartItemTest::test_update_rollback_on_
  validation_failure`.

---

## 5. Remove Cart Item

- **HTTP method / route**: `DELETE /v1/cart/items/{item}`
- **Success status**: `200 OK` — full updated cart (§1's shape), with the item removed.
- **Error status**: `404 Not Found` — unknown `{item}` or owned by another customer.
- **Business behavior**: Lock `users` → lock the ACTIVE `carts` row → lock the owned item → delete
  it. `cart_item_option_selections` and `cart_item_option_choice_selections` rows cascade at the
  database layer (`ON DELETE CASCADE`); the cart row itself is untouched.

---

## 6. Clear Cart

- **HTTP method / route**: `DELETE /v1/cart`
- **Success status**: `200 OK` always — clearing an already-empty cart, or a customer with no cart
  at all, is a safe no-op, never an error.
- **Business behavior**: Lock `users` → lock the ACTIVE `carts` row (if one exists) → delete every
  `cart_items` row for it. The cart row is always kept — Cart is never deleted by this endpoint.

---

## 7. Documentation note: cart "total amount" is computed, not stored

`docs/05-system-requirements/05-data-requirements.md` (DR-CART-02, DR-CI-02, and DR-CON-01) lists
"Total amount" / "Item price" as Cart / Cart Item data. As actually implemented, `carts` and
`cart_items` carry **no** monetary column at all — no `total_amount`, no `item_price`. The cart total
is authoritative but always **computed live** by `PricingEngine::evaluate()`, per §2 above; a
`Cart Item`'s "price" is its `pricing.line_total` in the response, never a stored value. A booking
created later from a paid cart is expected to store an **immutable pricing snapshot** at booking
time (out of scope for this Phase 4 document) — that snapshot, not a cart/cart-item column, is what
DR-CART-02/DR-CI-02 describe. See the clarifying note added directly to that document.

---

## Trust boundary summary

| Client can send | Client cannot influence |
|---|---|
| `service_uuid`, `quantity`, `options[].option_uuid` + one value field | `price`, `base_amount`, `subtotal`, `total`, `currency`, any pricing rule/scheme id |

Every accepted field is validated against the live catalog schema before it ever reaches
`PricingEngine::evaluate()`; every price in every response is that call's direct output.
