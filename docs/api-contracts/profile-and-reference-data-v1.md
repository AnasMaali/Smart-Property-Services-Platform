# BLUE V1 — Reference Data & Customer Profile API Contract

Base URL: `{{base_url}}` (local default: `http://127.0.0.1:8000/api/v1`)

This document describes the reference-data and customer-profile endpoints as actually
implemented in `backend/routes/api.php`, their Form Requests, Actions, and Controllers, and
verified against `backend/tests/Feature/ReferenceData/*` and `backend/tests/Feature/Profile/*`.
It documents only what exists in code — no aspirational or planned behavior is included.

See `docs/api-contracts/authentication-v1.md` for registration, login, and the dedicated
phone-number/password change flows referenced below.

## Global notes

- All responses are JSON, sharing the envelope `{ "success": bool, "message": string, "data": object|null }`.
- Validation failures return HTTP `422` with the envelope `{ "success": false, "message": "The given data was invalid.", "errors": { "<field>": ["<message>"] } }` (Laravel's standard `FormRequest` validation error shape).
- Protected endpoints require header `Authorization: Bearer {{access_token}}` and are validated by the same `auth.customer` middleware used by the authentication endpoints (see `docs/api-contracts/authentication-v1.md`).
- Only **active** reference rows (`is_active = 1`) are returned by the reference-data endpoint and are accepted as valid selections when updating a profile. A profile that already points at a row which has since become inactive still displays that selection on `GET /v1/profile` — inactivity is enforced on writes, not reads.

---

## Endpoint summary

| # | Feature | Method | Route | Auth required |
|---|---|---|---|---|
| 1 | Registration Reference Data | GET | `/v1/reference-data/registration` | No |
| 2 | Get Profile | GET | `/v1/profile` | Yes (Bearer) |
| 3 | Update Profile | PATCH | `/v1/profile` | Yes (Bearer) |

---

## 1. Registration Reference Data

- **HTTP method / route**: `GET /v1/reference-data/registration`
- **Auth required**: No
- **Purpose**: Populates the registration screen (City → Area cascading dropdown, Customer Type, Preferred Service Interests) and is reused unmodified by the edit-profile screen for the same fields. No `city_id`/country selector is exposed separately in V1 — cities already carry their own active areas nested, and V1 operates in a single country (UAE).
- **Tables read**: `cities`, `areas`, `property_relationship_types`, `service_categories` (all filtered to `is_active = 1`, ordered by `display_order`)
- **Success status**: `200 OK`
- **Success response**:
```json
{
  "success": true,
  "message": "Reference data retrieved successfully.",
  "data": {
    "cities": [
      {
        "id": 2,
        "code": "DUBAI",
        "name": "Dubai",
        "areas": [
          { "id": 8, "code": "DUBAI_MARINA", "name": "Dubai Marina" }
        ]
      }
    ],
    "property_relationship_types": [
      { "id": 1, "code": "PROPERTY_OWNER", "name": "Property Owner", "description": "The customer owns the property." }
    ],
    "service_categories": [
      { "id": 1, "code": "AC", "name": "AC", "description": "Air-conditioning cleaning, repair, installation, and maintenance services." }
    ]
  }
}
```

---

## 2. Get Profile

- **HTTP method / route**: `GET /v1/profile`
- **Auth required**: Yes (Bearer)
- **Purpose**: Populates the profile screen, including the customer's current location, property relationship, and service interests. `phone_number` is included for display only — it is not editable through this endpoint.
- **Tables read**: `users`, `user_profiles`, `user_account_statuses`, `customer_profiles`, `areas`, `cities`, `property_relationship_types`, `customer_service_interests`, `service_categories`
- **Success status**: `200 OK`
- **Success response**:
```json
{
  "success": true,
  "message": "Profile retrieved successfully.",
  "data": {
    "user_uuid": "3f2a1c9e-....-....-....-............",
    "full_name": "Layla Hassan",
    "email": "layla@example.com",
    "phone_number": "+971500001234",
    "phone_verified": true,
    "account_status": "ACTIVE",
    "location": {
      "city": { "id": 2, "code": "DUBAI", "name": "Dubai" },
      "area": { "id": 8, "code": "DUBAI_MARINA", "name": "Dubai Marina" }
    },
    "property_relationship": { "id": 1, "code": "PROPERTY_OWNER", "name": "Property Owner" },
    "service_interests": [
      { "id": 1, "code": "AC", "name": "AC" },
      { "id": 2, "code": "CLEANING", "name": "Cleaning" }
    ]
  }
}
```
- **Error status**: `401 Unauthorized` — missing, invalid, expired, or revoked access token (same generic message as every other `auth.customer` endpoint)
- **Security notes**: never returns `password_hash`, `refresh_token_hash`, or any raw `binary(16)` id — all identifiers are surfaced as UUID strings or plain integers.

---

## 3. Update Profile

- **HTTP method / route**: `PATCH /v1/profile`
- **Auth required**: Yes (Bearer)
- **Purpose**: Partial update of the customer's editable profile fields. Every field is optional; only the fields present in the request body are changed.
- **Request JSON** (all fields optional):
```json
{
  "full_name": "Layla Hassan",
  "email": "layla.new@example.com",
  "area_id": 8,
  "property_relationship_type_id": 2,
  "service_interests": [1, 3, 5]
}
```
- **Fields**:
  | Field | Rules |
  |---|---|
  | `full_name` | string, min:2, max:150, trimmed |
  | `email` | RFC email, max:254, normalized (trimmed + lower-cased), unique in `users.email` excluding the authenticated user's own row |
  | `area_id` | integer, must exist in `areas` with `is_active = 1` (no separate `city_id` field — city is derived from the chosen area) |
  | `property_relationship_type_id` | integer, must exist in `property_relationship_types` with `is_active = 1` |
  | `service_interests` | array, min:1 when supplied, each item distinct, integer, must exist in `service_categories` with `is_active = 1`; **replaces the customer's complete service-interest set** |

  **Not accepted by this endpoint** (silently ignored if present in the body — no validation rule exists for them): `phone_number`, `password`, `account_status_id`, `phone_verified_at`, `role`. Phone number changes go through `POST /v1/auth/change-phone-number` (OTP-verified) and password changes go through `POST /v1/auth/change-password` — both documented in `docs/api-contracts/authentication-v1.md`.

- **Success status**: `200 OK`
- **Success response**: same shape as `GET /v1/profile`, reflecting the fields just updated:
```json
{
  "success": true,
  "message": "Profile updated successfully.",
  "data": { "...": "same shape as GET /v1/profile" }
}
```
- **Error status**: `422 Unprocessable Entity` (validation failure — e.g. duplicate email, inactive/nonexistent `area_id`, empty or duplicate `service_interests`)
- **Business behavior**: Every write (`users.email`, `user_profiles.full_name`, `customer_profiles.area_id` / `property_relationship_type_id`, and the `customer_service_interests` replace-set) happens inside one DB transaction. Any failure rolls back the entire update — no partial profile change is ever persisted. The `users`, `user_profiles`, and `customer_profiles` rows for the authenticated customer are locked (`SELECT ... FOR UPDATE`, in that order) before mutating, preventing two concurrent `PATCH` calls for the same customer from interleaving writes.
- **Historical data**: updating `area_id` never touches `booking_locations` — booking address snapshots are independent, immutable columns captured at booking time and are unaffected by later profile changes.
