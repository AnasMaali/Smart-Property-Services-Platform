# BLUE V1 — Service Catalog API Contract

Base URL: `{{base_url}}` (local default: `http://127.0.0.1:8000/api/v1`)

This document describes the public service-catalog endpoints as actually implemented in
`backend/routes/api.php`, their Actions and Controllers, and verified against
`backend/tests/Feature/ServiceCatalog/*`. It documents only what exists in code — no
aspirational or planned behavior is included.

Phase 3 covers catalog browsing only: Home → Service Categories → Services in Category →
Service Details → generic option metadata + pricing preview. Cart, Checkout, Booking, Payment,
Property, Appointment, Technician Assignment, and Search are out of scope and not implemented here.

Pricing itself (Phase 3.5) is implemented by one generic, data-driven **PricingEngine**
(`App\Support\Pricing\PricingEngine`) that these endpoints call for a preview and that a future
Cart/Checkout will call again for authoritative pricing — there is exactly one pricing calculation
in the system, never a second algorithm per endpoint.

## Global notes

- All responses are JSON, sharing the envelope `{ "success": bool, "message": string, "data": object|null }`.
- All three endpoints are **public** — no `Authorization` header is required or checked.
- Only **active** rows are ever returned: `service_categories.is_active = 1`, `services.is_active = 1`,
  `service_media.is_active = 1`, `service_options.is_active = 1`, `service_option_choices.is_active = 1`.
- Pricing is resolved through `pricing_scheme_versions` + `pricing_rules` (+ their condition/tier
  child tables), not through a flat price column. A scheme version is "currently effective" when
  `status = PUBLISHED` and `effective_from <= now()` and (`effective_to IS NULL` or
  `effective_to > now()`) — at most one such version exists per (service, currency) at any instant.
  Only the currently effective version's rules are ever evaluated; historical/future scheme
  versions are never exposed or used for a preview.
- Ordering is always by `display_order ASC` — for categories, services, media, options, and choices.
- All `BINARY(16)` identifiers (`services.id`, `service_media.id`, `service_options.id`,
  `service_option_choices.id`) are converted to standard UUID strings before leaving the API.
  Raw binary is never returned. Integer lookup ids (`service_categories.id`, `currencies.id`,
  `measurement_units.id`) are returned as plain integers.
- Money amounts are returned as strings, preserving the full `decimal(19,6)` precision stored in
  the database (computed via bcmath, never float).
- Not found (unknown or inactive category/service) always returns `404` with
  `{ "success": false, "message": "..." }` — never a silent empty result for a bad identifier.

---

## Endpoint summary

| # | Feature | Method | Route | Auth required |
|---|---|---|---|---|
| 1 | List Service Categories | GET | `/v1/service-categories` | No |
| 2 | List Services in Category | GET | `/v1/service-categories/{category}/services` | No |
| 3 | Get Service Details | GET | `/v1/services/{service}` | No |

---

## 1. List Service Categories

- **HTTP method / route**: `GET /v1/service-categories`
- **Auth required**: No
- **Purpose**: Populates the customer Home screen's category list.
- **Tables read**: `service_categories` (`is_active = 1`, ordered by `display_order`)
- **Success status**: `200 OK`
- **Success response**:
```json
{
  "success": true,
  "message": "Service categories retrieved successfully.",
  "data": {
    "service_categories": [
      { "id": 1, "code": "AC", "name": "AC", "description": "Air-conditioning cleaning, repair, installation, and maintenance services." }
    ]
  }
}
```

---

## 2. List Services in Category

- **HTTP method / route**: `GET /v1/service-categories/{category}/services`
- **Auth required**: No
- **Path parameter**: `category` — integer `service_categories.id`. A non-numeric value is treated
  the same as an unknown category.
- **Purpose**: Populates the services-inside-category screen with card data: identity, primary
  image, and a pricing preview per service.
- **Tables read**: `service_categories`, `services`, `service_media`, `currencies`,
  `pricing_scheme_versions`, `pricing_rules` (+ condition/tier child tables)
- **Success status**: `200 OK`
- **Error status**: `404 Not Found` — the category id does not exist or is inactive
- **Success response**:
```json
{
  "success": true,
  "message": "Category services retrieved successfully.",
  "data": {
    "category": { "id": 1, "code": "AC", "name": "AC", "description": "..." },
    "services": [
      {
        "uuid": "3f2a1c9e-....-....-....-............",
        "code": "AC_DEEP_CLEAN",
        "slug": "ac-deep-clean",
        "name": "AC Deep Cleaning",
        "short_description": "Deep cleaning for split AC units.",
        "primary_image": {
          "storage_key": "services/ac-deep-clean/hero.jpg",
          "mime_type": "image/jpeg",
          "alt_text": "Technician cleaning an AC unit",
          "caption": null,
          "width_pixels": 1600,
          "height_pixels": 900
        },
        "pricing_preview": {
          "pricing_status": "PRICED",
          "unit_total": "120.000000",
          "currency": { "code": "AED", "symbol": "د.إ", "minor_unit": 2 }
        }
      }
    ]
  }
}
```
- **Business behavior**: `primary_image` is `null` when no active, active-primary `service_media`
  row exists for the service. `pricing_preview` is evaluated by `PricingEngine::previewMany()`
  with no selections and quantity 1 (one batched load for the whole page, not one query per
  service). `pricing_status` is `UNAVAILABLE` when no currently effective PUBLISHED scheme version
  exists for the service — a missing price is never invented or defaulted. `unit_total` and
  `currency` are `null` whenever `pricing_status` is not `PRICED`. No pricing history is returned.

---

## 3. Get Service Details

- **HTTP method / route**: `GET /v1/services/{service}`
- **Auth required**: No
- **Path parameter**: `service` — `services.slug`.
- **Purpose**: Populates the service-details screen: full identity, category summary, media
  gallery, a pricing preview, and every active configurable option's generic input metadata so
  Flutter can render TEXT/NUMBER/BOOLEAN/SINGLE_SELECT/MULTI_SELECT controls without any
  service-specific Flutter code.
- **Tables read**: `services`, `service_categories`, `service_media`, `currencies`,
  `service_options`, `service_option_types`, `service_option_numeric_rules`, `measurement_units`,
  `service_option_selection_rules`, `service_option_choices`, `pricing_scheme_versions`,
  `pricing_rules` (+ condition/tier child tables)
- **Success status**: `200 OK`
- **Error status**: `404 Not Found` — the slug does not exist or the service is inactive
- **Success response**:
```json
{
  "success": true,
  "message": "Service details retrieved successfully.",
  "data": {
    "uuid": "3f2a1c9e-....-....-....-............",
    "code": "AC_DEEP_CLEAN",
    "slug": "ac-deep-clean",
    "name": "AC Deep Cleaning",
    "short_description": "Deep cleaning for split AC units.",
    "description": "Full description of the service...",
    "category": { "id": 1, "code": "AC", "name": "AC", "description": "..." },
    "media": [
      {
        "uuid": "5b6c....",
        "storage_key": "services/ac-deep-clean/hero.jpg",
        "mime_type": "image/jpeg",
        "alt_text": "Technician cleaning an AC unit",
        "caption": null,
        "width_pixels": 1600,
        "height_pixels": 900,
        "is_primary": true
      }
    ],
    "pricing_preview": {
      "pricing_status": "PRICED",
      "currency": "AED",
      "pricing_scheme_version": "9d1e....-....-....-....-............",
      "base_amount": "120.000000",
      "adjustments": [
        { "rule_code": "BASE_PRICE", "label": "Base price", "effect_type": "SET_PRICE", "amount_or_factor": "120.000000", "running_total_after": "120.000000" }
      ],
      "unit_total": "120.000000",
      "quantity": 1,
      "line_total": "120.000000",
      "required_context": []
    },
    "options": [
      {
        "uuid": "7a1d....",
        "code": "NUM_AC_UNITS",
        "name": "Number of AC Units",
        "description": "How many AC units need cleaning.",
        "type": "NUMBER",
        "is_required": true,
        "numeric_rule": {
          "min_value": "1.000000",
          "max_value": "10.000000",
          "step_value": "1.000000",
          "default_value": "1.000000",
          "decimal_places": 0,
          "measurement_unit": { "id": 2, "code": "UNIT", "name": "Unit", "symbol": "unit" }
        }
      },
      {
        "uuid": "9c2e....",
        "code": "CLEANING_TYPE",
        "name": "Cleaning Type",
        "description": "Choose the level of cleaning.",
        "type": "SINGLE_SELECT",
        "is_required": true,
        "selection_rule": { "minimum_selections": 1, "maximum_selections": 1 },
        "choices": [
          { "uuid": "ab3f....", "code": "STANDARD", "name": "Standard", "description": null },
          { "uuid": "cd4a....", "code": "PREMIUM", "name": "Premium", "description": null }
        ]
      }
    ]
  }
}
```
- **Business behavior**:
  - `media` includes only active rows, ordered by `display_order`; `is_primary` marks the
    active row where `is_primary = 1`.
  - `pricing_preview` is the exact `PricingResult` returned by `PricingEngine::evaluate()` with no
    selections yet, quantity 1, and no system context resolved — see **Pricing statuses** below.
    It is evaluated fresh on every request; nothing about it is cached or invented.
  - `numeric_rule` is only present on `NUMBER` options; `selection_rule` / `choices` are only
    present on `SINGLE_SELECT` / `MULTI_SELECT` options. `TEXT` and `BOOLEAN` options carry no
    extra rule block. Options no longer carry any pricing sub-fields (no `numeric_pricing_rule`,
    no per-choice `current_additional_price`) — pricing is entirely the responsibility of
    `pricing_preview` / the PricingEngine, since a rule's price contribution can depend on
    conditions spanning multiple options and can no longer be reduced to "one number per option
    or choice" in general.
  - `category` is shown regardless of whether the category itself is currently active — the same
    "display what the resource currently points at" rule already used for reference-data lookups
    in `docs/api-contracts/profile-and-reference-data-v1.md`.

---

## Pricing statuses

Every `pricing_preview` (and, later, every Cart/Checkout pricing response) carries one of:

| Status | Meaning |
|---|---|
| `PRICED` | A price was computed; `unit_total` / `line_total` are populated. |
| `QUOTE_REQUIRED` | A rule in the currently effective scheme explicitly requires a custom quote (e.g. renovation work); no numeric total is returned. |
| `MISSING_CONTEXT` | A rule depends on system context (e.g. service zone, appointment time window) that is not yet known at this point in the flow; `required_context` lists which attribute codes are missing. Never guessed. |
| `UNAVAILABLE` | No currently effective PUBLISHED pricing scheme exists for this service+currency, or no rule in it produced a price for the given (here: empty) selections. |

At the Service Details preview stage — before the customer has entered a cart, chosen a property,
or picked an appointment slot — `MISSING_CONTEXT` is expected and normal for any service whose
pricing depends on system context; Flutter must render this as "price depends on your selections /
location" rather than treating it as an error.

---

## Pricing trust boundary

This phase only exposes catalog data and a **preview** computed by the same PricingEngine future
endpoints use — it never returns a client-editable or client-trusted amount, and no Cart/Checkout
endpoint is implemented in Phase 3 / Phase 3.5.

- The client (Flutter) **may** display the returned `pricing_preview` as a UX estimate while the
  customer is selecting options, and may recompute a local estimate as selections change.
- That client-side or previously-fetched calculation is **never authoritative**. Future
  Cart/Checkout endpoints **must** call `PricingEngine::evaluate()` again server-side with the
  customer's actual selections at the time of the request — the exact same engine, never a second
  algorithm.
- Future clients submit only: `service_id`, selected `service_option_id`(s), selected
  `service_option_choice_id`(s), numeric option values, boolean option values, text option values,
  and `quantity` (meaning repeated identical service items only — never a substitute for a
  service-specific measurement like hours, rooms, AC units, or square meters, which are always
  `NUMBER` service options).
- Future clients must **never** submit, and the backend must **never** trust, a client-provided
  price, subtotal, total, currency amount, or pricing snapshot for any of these identifiers.
