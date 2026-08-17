# BLUE V1 — Phase 10A Customer Properties Contract

Base URL: `{{base_url}}` (local default: `http://127.0.0.1:8000/api/v1`)

This document describes the Phase 10A Customer Properties endpoints, as actually implemented in
`backend/routes/api.php`, `App\Actions\Property\*`, `App\Http\Controllers\Api\V1\Property\*`, and
verified against `backend/tests/Feature/Property/PropertyTest.php`. It documents only what exists
in code.

## Scope

A customer may save one or more **Properties** — named, reusable addresses built from the same
`property_types` / `property_relationship_types` / `areas` reference data and address-field shape
already used by `cart_locations` / `booking_locations` (see `docs/api-contracts/checkout-v1.md`).
A Property is the anchor a Service Contract is requested against (`docs/api-contracts/contracts-v1.md`)
and is never a payment/checkout concept itself — Standard cart checkout is completely unaffected by
this phase.

No latitude/longitude fields exist — the existing location schema (`cart_locations`,
`booking_locations`) has none, so none was invented here.

## Authentication

Every route below requires `auth.customer` (a valid Customer Bearer access token). Ownership is
always `customer_properties.customer_user_id = <authenticated user>`; a foreign or unknown Property
UUID is reported identically as `404`, never `403`, matching every other ownership-scoped resource
in this codebase (Bookings, Payments, Carts).

## Endpoints

### `GET /v1/properties`

Lists the authenticated customer's Properties, newest first.

Query parameter `status` (optional): `active` (default), `archived`, or `all`.

```json
{
  "success": true,
  "message": "Properties retrieved successfully.",
  "data": { "properties": [ { "uuid": "...", "label": "...", "is_active": true, "...": "..." } ] }
}
```

### `POST /v1/properties`

Creates a new Property owned by the authenticated customer.

```json
{
  "label": "My Apartment",
  "property_relationship_type_id": 1,
  "property_type_id": 2,
  "other_property_type_name": null,
  "area_id": 10,
  "street_name": "Sheikh Zayed Road",
  "address_line": "Building 4, near the mall",
  "building_name_or_number": "Tower 4",
  "floor_number": "12",
  "unit_number": "1201",
  "nearby_landmark": "Near the metro station",
  "additional_location_notes": null,
  "visit_contact_phone": "+971501234567"
}
```

`other_property_type_name` is required only when the resolved `property_type_id`'s code is `OTHER`
— the same catalog-driven rule `App\Actions\Checkout\SaveCheckoutLocationAction` already enforces
for `cart_locations`. Returns `201` with the created Property, or `422` on validation/catalog
failure.

### `GET /v1/properties/{property}`

Returns the Property plus every Service Contract ever requested against it (a lightweight summary:
`uuid`, `contract_number`, `status`, `starts_at`, `ends_at` — full covered-service/entitlement/booking
detail lives at `GET /v1/contracts/{contract}`, never duplicated here). An archived Property remains
fully readable — see "Archiving" below.

### `PATCH /v1/properties/{property}`

Partial update. Every field is optional; once present it is validated exactly like `POST`. Returns
`409` if the Property is currently archived (`is_active = 0`) — an archived Property is immutable,
mirroring how a terminal Booking/Booking Item can no longer be mutated elsewhere in this codebase.

### `DELETE /v1/properties/{property}`

**Archives** the Property (`is_active` set to `0`) — never a destructive row delete, since a
Property referenced by a Service Contract must remain historically queryable. Idempotent: archiving
an already-archived Property is a safe no-op that still returns `200`.

```json
{ "success": true, "message": "Property archived successfully.", "data": { "property": { "uuid": "...", "is_active": false } } }
```

## Schema

```sql
customer_properties (
  id BINARY(16) PK,
  customer_user_id BINARY(16) -> customer_profiles.user_id,
  label VARCHAR(120),
  property_relationship_type_id -> property_relationship_types.id,
  property_type_id -> property_types.id,
  other_property_type_name VARCHAR(120) NULL,
  area_id -> areas.id,
  street_name, address_line, building_name_or_number, floor_number, unit_number,
  nearby_landmark, additional_location_notes, visit_contact_phone,
  is_active TINYINT(1) DEFAULT 1,
  created_at, updated_at
)
```

See `database/blue_v1_schema.sql` for the full DDL (constraints mirror `cart_locations` exactly).

## Regression

Properties are entirely additive — no existing Customer/Cart/Checkout/Payment/Booking table, Action,
or route was changed by this phase.
