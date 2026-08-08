# BLUE V1 — Service Catalog API Contract

Base URL: `{{base_url}}` (local default: `http://127.0.0.1:8000/api/v1`)

This document describes the public service-catalog endpoints as actually implemented in
`backend/routes/api.php`, their Actions and Controllers, and verified against
`backend/tests/Feature/ServiceCatalog/*`. It documents only what exists in code — no
aspirational or planned behavior is included.

Phase 3 covers catalog browsing only: Home → Service Categories → Services in Category →
Service Details → Options/Pricing configuration. Cart, Checkout, Booking, Payment, Property,
Appointment, Technician Assignment, and Search are out of scope and not implemented here.

## Global notes

- All responses are JSON, sharing the envelope `{ "success": bool, "message": string, "data": object|null }`.
- All three endpoints are **public** — no `Authorization` header is required or checked.
- Only **active** rows are ever returned: `service_categories.is_active = 1`, `services.is_active = 1`,
  `service_media.is_active = 1`, `service_options.is_active = 1`, `service_option_choices.is_active = 1`.
- Pricing tables (`service_prices`, `service_option_choice_prices`,
  `service_option_numeric_pricing_rules`) have no `is_active` column. Historical/future rows are
  excluded purely by effective-dating: a row is "currently effective" when
  `effective_from <= now()` and (`effective_to IS NULL` or `effective_to > now()`). Only the
  currently effective row is ever returned; pricing history is never exposed.
- Ordering is always by `display_order ASC` — for categories, services, media, options, and choices.
- All `BINARY(16)` identifiers (`services.id`, `service_media.id`, `service_options.id`,
  `service_option_choices.id`) are converted to standard UUID strings before leaving the API.
  Raw binary is never returned. Integer lookup ids (`service_categories.id`, `currencies.id`,
  `measurement_units.id`) are returned as plain integers.
- Money amounts (`base_amount`, `additional_amount`, `amount_per_unit`, etc.) are returned as
  strings, preserving the full `decimal(19,6)` precision stored in the database.
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
  image, and current starting price per service.
- **Tables read**: `service_categories`, `services`, `service_media`, `service_prices`, `currencies`
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
        "current_price": {
          "amount": "120.000000",
          "currency": { "code": "AED", "symbol": "د.إ", "minor_unit": 2 }
        }
      }
    ]
  }
}
```
- **Business behavior**: `primary_image` is `null` when no active, active-primary `service_media`
  row exists for the service. `current_price` is `null` when no currently effective `service_prices`
  row exists — a missing price is never invented or defaulted. No pricing history is returned.

---

## 3. Get Service Details

- **HTTP method / route**: `GET /v1/services/{service}`
- **Auth required**: No
- **Path parameter**: `service` — `services.slug`.
- **Purpose**: Populates the service-details screen: full identity, category summary, media
  gallery, currently effective base price, and every active configurable option with its
  currently effective pricing rules and active choices.
- **Tables read**: `services`, `service_categories`, `service_media`, `service_prices`,
  `currencies`, `service_options`, `service_option_types`, `service_option_numeric_rules`,
  `measurement_units`, `service_option_numeric_pricing_rules`, `service_option_selection_rules`,
  `service_option_choices`, `service_option_choice_prices`
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
    "base_price": {
      "amount": "120.000000",
      "currency": { "code": "AED", "symbol": "د.إ", "minor_unit": 2 }
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
        },
        "numeric_pricing_rule": {
          "currency": { "code": "AED", "symbol": "د.إ", "minor_unit": 2 },
          "included_value": "1.000000",
          "charge_unit_size": "1.000000",
          "amount_per_unit": "40.000000",
          "minimum_additional_amount": "0.000000",
          "maximum_additional_amount": null
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
          {
            "uuid": "ab3f....",
            "code": "STANDARD",
            "name": "Standard",
            "description": null,
            "current_additional_price": null
          },
          {
            "uuid": "cd4a....",
            "code": "PREMIUM",
            "name": "Premium",
            "description": null,
            "current_additional_price": {
              "amount": "50.000000",
              "currency": { "code": "AED", "symbol": "د.إ", "minor_unit": 2 }
            }
          }
        ]
      }
    ]
  }
}
```
- **Business behavior**:
  - `media` includes only active rows, ordered by `display_order`; `is_primary` marks the
    active row where `is_primary = 1`.
  - `base_price` is `null` when no currently effective `service_prices` row exists.
  - `numeric_rule` / `numeric_pricing_rule` are only present on `NUMBER` options.
    `selection_rule` / `choices` are only present on `SINGLE_SELECT` / `MULTI_SELECT` options.
    `TEXT` and `BOOLEAN` options carry no extra rule block, because no such rule table exists in
    the schema — no rules are invented beyond what is actually configured.
  - Each choice's `current_additional_price` is `null` when no currently effective
    `service_option_choice_prices` row exists for it.
  - `category` is shown regardless of whether the category itself is currently active — the same
    "display what the resource currently points at" rule already used for reference-data lookups
    in `docs/api-contracts/profile-and-reference-data-v1.md`.

---

## Pricing trust boundary

This phase only exposes catalog and pricing **configuration** — it never calculates or returns a
computed cart/checkout total, and no price-calculation or cart endpoint is implemented in Phase 3.

- The client (Flutter) **may** use the returned base price, choice prices, and numeric pricing
  rules to render a **UX preview** of an estimated price while the customer is selecting options.
- That client-side calculation is **never authoritative**. Future Cart/Checkout endpoints **must**
  recalculate the price server-side from the currently effective pricing rows at the time the
  request is made.
- Future clients submit only: `service_id`, selected `service_option_id`(s), selected
  `service_option_choice_id`(s), and numeric option values/quantities.
- Future clients must **never** submit, and the backend must **never** trust, a client-provided
  amount, subtotal, or total for any of these option identifiers.
