# Technician WhatsApp Job Notifications — V1 Handoff

BLUE V1 has no Technician mobile app yet. Whenever an Admin assigns (or reassigns) a
Technician to a Booking Item, the system automatically notifies that Technician through
WhatsApp with everything needed to execute the job. WhatsApp is a notification **channel**,
never the source of truth — the assignment itself remains canonical in
`technician_assignments`.

## A. Feature behavior

- Admin does only: **Assign technician → choose Technician → confirm.**
- On a genuine new assignment, the system writes one durable `outbound_notifications`
  obligation **inside the same DB transaction** as the assignment, then makes one
  best-effort synchronous WhatsApp send attempt immediately after that transaction commits.
- If WhatsApp is temporarily unavailable, the assignment still succeeds — the obligation
  stays `PENDING` and is retried automatically by a scheduled recovery command.
- On a reassignment (Technician A → Technician B), **two** independent obligations are
  created: a `TECHNICIAN_NEW_ASSIGNMENT` notification to B, and a
  `TECHNICIAN_ASSIGNMENT_REMOVED` notification to A ("Booking {{number}} is no longer
  assigned to you. No action is required.") — A is never told who replaced them.
- If a queued `NEW_ASSIGNMENT` notification is retried after its assignment was released
  (reassigned away) before it ever sent, it is marked `SKIPPED` — never sent stale, never
  marked `FAILED` (nothing went wrong; the business state legitimately changed).
- **Lost-response safety**: the Meta WhatsApp Cloud API has no request-level idempotency
  key (unlike Stripe). If the send request's connection fails or times out before BLUE ever
  receives a response, BLUE cannot know whether Meta already created a real message. This is
  NEVER auto-retried - it becomes the terminal `RECONCILIATION_REQUIRED` ("Needs review")
  state on the first such outcome, is excluded from both the recovery command and the
  ordinary Admin retry endpoint, and requires an explicit, out-of-band human decision - see
  section J.
- **HTTP 5xx is treated the same as a lost response, not as an ordinary transient failure.**
  A server-side error response does not, by itself, prove Meta never created/accepted the
  message before returning that error - relying on generic HTTP semantics here would risk a
  duplicate real WhatsApp message. The only failure status treated as "provider definitively
  rejected the request, safe to retry" is an explicit HTTP `429`. Every `5xx` response is
  classified exactly like a connection timeout: terminal `RECONCILIATION_REQUIRED`, never
  auto-resent. See the classification matrix in section J.

## B. Required env variable names (names only — never commit real values)

```
TECHNICIAN_NOTIFICATION_DRIVER=log        # or meta_whatsapp
TECHNICIAN_NOTIFICATION_MAX_ATTEMPTS=5

WHATSAPP_PHONE_NUMBER_ID=
WHATSAPP_ACCESS_TOKEN=
WHATSAPP_GRAPH_VERSION=                   # never hardcoded - check the current Meta Graph API version
WHATSAPP_ASSIGNMENT_TEMPLATE=
WHATSAPP_UNASSIGNMENT_TEMPLATE=
WHATSAPP_TEMPLATE_LANGUAGE=en
WHATSAPP_TIMEOUT_SECONDS=10
```

All five `WHATSAPP_*` values above (phone number id, access token, Graph version, both
template names) are required and validated eagerly at boot by
`App\Providers\TechnicianNotificationServiceProvider` when
`TECHNICIAN_NOTIFICATION_DRIVER=meta_whatsapp` — a missing one throws a clear
`RuntimeException` rather than silently sending nothing.

## C. Meta WhatsApp Cloud API setup checklist

1. Create/use a Meta Business account and a WhatsApp Business Platform app in
   [Meta for Developers](https://developers.facebook.com/).
2. Add a phone number to the WhatsApp Business Platform app (a Meta **test number** is
   sufficient for initial verification — see section E).
3. Note the **Phone Number ID** → `WHATSAPP_PHONE_NUMBER_ID`.
4. Generate a **permanent access token** (System User token, not the 24-hour temporary
   token) with `whatsapp_business_messaging` permission → `WHATSAPP_ACCESS_TOKEN`.
5. Note the current Graph API version shown in the Meta dashboard (e.g. `v21.0`) →
   `WHATSAPP_GRAPH_VERSION`. Check this at configuration time — Meta deprecates versions on
   its own schedule; never assume a version from memory or from this document.
6. Submit both templates below (section D/E) for **Utility category** approval in
   WhatsApp Manager → Message Templates. Approval is required before either can be sent to a
   real recipient outside the 24-hour customer-service window (which never applies here,
   since a Technician never messages BLUE first).
7. Once approved, put the exact template **names** (not bodies) in
   `WHATSAPP_ASSIGNMENT_TEMPLATE` / `WHATSAPP_UNASSIGNMENT_TEMPLATE`.
8. Set `WHATSAPP_TEMPLATE_LANGUAGE` to the language code the templates were approved in
   (e.g. `en`).

## D. Assignment template — `blue_technician_assignment_v1`

- **Category**: Utility
- **Language**: as configured (`WHATSAPP_TEMPLATE_LANGUAGE`)
- **Body** (6 variables, in this exact order — must match
  `TechnicianJobNotificationContent::assignmentTemplateParameters()`):

```
BLUE | New Service Assignment

Hello {{1}},

A new service has been assigned to you.

Booking: {{2}}
Service: {{3}}
When: {{4}}
Customer: {{5}}
Location: {{6}}

Please arrive during the scheduled appointment window.
```

| # | Variable | Example |
|---|---|---|
| 1 | Technician name | `Omar Khalil` |
| 2 | Booking number | `BLU-9759A460A2` |
| 3 | Service + selected options/choices | `1x Deep Clean - Extra windows` |
| 4 | Appointment date/time (Asia/Dubai) | `Sat, 30 Aug 2026 - 10:00 AM to 12:00 PM` |
| 5 | Customer name + visit contact phone | `Layla Hassan - +971501234567` |
| 6 | Full visit address, one line | `Apartment, E2E Tower, Floor 5 - Unit 501, Corniche Road, Al Khalidiyah, Abu Dhabi (near Corniche Metro)` |

Meta rejects an empty template parameter — every value above is composed to always be
non-empty (a missing floor/unit/landmark is simply omitted from its composed field, never
sent as a blank parameter).

The full human-readable rendering used by the **log** driver (and available to an Admin for
troubleshooting) is richer — see `TechnicianJobNotificationContent::renderAssignmentText()` —
but the six values above are what Meta itself actually requires as approved template
variables.

## E. Removal/reassignment template — `blue_technician_assignment_removed_v1`

- **Category**: Utility
- **Language**: as configured
- **Body** (1 variable):

```
BLUE

Booking {{1}} is no longer assigned to you.

No action is required.
```

| # | Variable | Example |
|---|---|---|
| 1 | Booking number | `BLU-9759A460A2` |

Deliberately never names the replacement Technician.

## F. Template category

Both templates must be submitted and approved as **Utility** templates. BLUE never sends
free-form outbound WhatsApp text as its production path — Meta requires a pre-approved
template for any business-initiated message to a recipient who has not messaged the business
first, which is always true for a Technician receiving a job assignment.

## G. Template language configuration

`WHATSAPP_TEMPLATE_LANGUAGE` (default `en`) is passed as the `language.code` on every Graph
API template message request. If BLUE ever needs to notify Technicians in more than one
language, that requires a config/lookup change (e.g. per-Technician preferred language) —
out of scope for this V1 phase, which uses one fixed language for all Technicians.

## H. Technician phone requirements

- Must be in **E.164 format** (e.g. `+9715XXXXXXXX`) — validated at send time via
  `/^\+[1-9]\d{7,14}$/` in `App\Actions\Notifications\SendTechnicianNotificationAction`.
- BLUE has no canonical phone normalizer (no country-code inference for malformed data) —
  if a Technician's stored `phone_number` is not already valid E.164, the notification
  obligation is marked `FAILED` with `last_error_code = INVALID_PHONE_FORMAT`; the
  assignment itself still succeeds. Fix the Technician's phone number and use the Admin
  "Retry WhatsApp" action (section J) once corrected.

## I. How to switch: log → meta_whatsapp

1. Confirm every checklist item in section C is complete and both templates are
   **approved** (not just submitted) in WhatsApp Manager.
2. Set the six `WHATSAPP_*` env vars (section B) to real values.
3. Set `TECHNICIAN_NOTIFICATION_DRIVER=meta_whatsapp`.
4. Restart the app server / run `php artisan config:clear` if config is cached.
5. Perform one real assignment against a real WhatsApp-capable test number you control (see
   Meta's test-number flow in WhatsApp Manager) and confirm the message arrives, before
   relying on this for real Technicians.

Switching back to `log` at any time requires no other change — `outbound_notifications`
rows already created keep their history; only new sends use the newly-selected driver.

## J. Queue/recovery command and Admin UI status meanings

- Recovery command: `php artisan notifications:send-pending` (scheduled every minute in
  `routes/console.php`) — idempotent, safe to run anytime; a healthy system finds nothing
  to do. Only retries `PENDING` obligations whose backoff window
  (`next_attempt_at`) has elapsed. **Never** selects `RECONCILIATION_REQUIRED` rows (its
  query is `WHERE status_id = PENDING`, structurally excluding them).
- Manual Admin retry: `POST /api/v1/admin/outbound-notifications/{notification}/retry`
  (`technicians.assign` capability) — resets a `FAILED` obligation back to `PENDING` and
  immediately re-attempts it, reusing the exact same obligation/idempotency key (never
  creates a second one). Rejects with `409` if the obligation is already `SUBMITTED`, was
  correctly `SKIPPED`, or is `RECONCILIATION_REQUIRED` (see below).
- Bounded retries: the only provider outcome treated as an ordinary retryable transient
  failure is an explicit Meta-returned HTTP `429` - a real response proving no message was
  created. It is retried with linear backoff (attempt number × 5 minutes) up to
  `TECHNICIAN_NOTIFICATION_MAX_ATTEMPTS` (default 5), after which it becomes a terminal
  `FAILED` (`MAX_ATTEMPTS_EXCEEDED`) — never retried forever.
- **Provider outcome classification matrix** (`MetaWhatsAppTechnicianNotificationGateway::classifyFailure()`) — deliberately conservative, because a blind resend risks a real duplicate WhatsApp message and Meta has no idempotency key to make that safe:

| Provider result | Outcome | DB status | Auto-retried? |
|---|---|---|---|
| HTTP 2xx with a message id | `SUBMITTED` | `SUBMITTED` | n/a — succeeded |
| HTTP 429 (rate limit) | `UNKNOWN` | stays `PENDING` | Yes — bounded backoff |
| HTTP 5xx (any) | `AMBIGUOUS` | `RECONCILIATION_REQUIRED` | **No** — a 5xx does not prove Meta never created the message |
| Connection/timeout (no response received) | `AMBIGUOUS` | `RECONCILIATION_REQUIRED` | **No** — same reasoning as 5xx |
| Any other 4xx (invalid template/recipient/credentials/malformed request) | `DEFINITIVE_FAILURE` | `FAILED` | No — proof no message was queued |

  This intentionally does **not** rely on generic HTTP semantics ("5xx is usually safe to
  retry") — that assumption is rejected here specifically because BLUE's priority is
  preventing a duplicate real WhatsApp job message, not minimizing manual review work. A
  future narrowing of this table (e.g. special-casing one specific 5xx code) would require an
  explicit, documented guarantee from Meta's official Cloud API reference that that code
  cannot have created a message — no such guarantee is currently relied upon.
- **`RECONCILIATION_REQUIRED` recovery is intentionally NOT a one-click retry** in V1 - the
  Admin retry endpoint explicitly rejects it (409), and the recovery command never selects
  it. To recover: an operator must first determine out-of-band whether the Technician
  actually received the WhatsApp message (e.g. by calling them, or checking the Meta Business
  dashboard's message log for the relevant phone number/time window around
  `outbound_notifications.created_at`). If confirmed NOT sent, the safe path to get a fresh
  send attempt is to reassign the Booking Item away and back to the same Technician (release
  reason: e.g. "Confirmed original WhatsApp attempt was never delivered") - this creates a
  brand-new `technician_assignments` row and therefore a fresh, distinct
  `idempotency_key`/obligation, never reusing the ambiguous one. If confirmed it WAS sent, no
  further action is needed; the obligation stays `RECONCILIATION_REQUIRED` as a permanent,
  accurate record that a human resolved it manually.
- Admin Booking Workspace status wording (never anything stronger than proven):

| Status code | Admin UI label | Meaning |
|---|---|---|
| `PENDING` | **Queued** | Not yet sent; will be retried automatically. |
| `SUBMITTED` | **Sent to WhatsApp** | The provider accepted the message. **Not** proof of delivery or that the Technician read it — no delivery/read webhook exists in this phase. |
| `FAILED` | **Failed** | Definitive rejection, or ordinary retries exhausted. Shows a "Retry WhatsApp" action. |
| `SKIPPED` | **Skipped** | Never sent because the assignment it described was already released — not an error, no action needed. |
| `RECONCILIATION_REQUIRED` | **Needs review** | The send request's outcome could not be confirmed - either a connection/timeout failure (no response received) or an HTTP 5xx response (which does not itself prove no message was created) - so whether Meta already sent the message is unknown. No retry button; see the recovery process above. Never rendered as "Failed" (a real provider rejection did not necessarily occur) or "Delivered"/"Read" (no evidence either way exists). |

## K. Database

- `outbound_notification_statuses` — `PENDING` / `SUBMITTED` / `FAILED` / `SKIPPED` /
  `RECONCILIATION_REQUIRED` only. No `DELIVERED`/`READ` status exists, matching the fact
  that no delivery/read webhook is implemented in this phase.
- `outbound_notifications` — one durable obligation per (Technician assignment,
  notification type) pair. Deliberately generic (`channel`, `recipient_type` columns) rather
  than a WhatsApp-specific table name, so a future channel can reuse it (see section L).
  `idempotency_key` is `blue_notify_{assignment_uuid}_{notification_type}` — deterministic,
  UNIQUE-constrained, never regenerated on retry.
- Migration file: `database/phase20_technician_notifications_migration.sql` (additive only,
  `CREATE TABLE IF NOT EXISTS`, safe to run repeatedly).

## L. How V2's Technician App can reuse this architecture

`App\Support\Notifications\Gateway\TechnicianNotificationGateway` is the only boundary
`App\Actions\Notifications\SendTechnicianNotificationAction` depends on — neither the
assignment domain logic (`AssignTechnicianToBookingItemAction`, untouched by this phase) nor
the recovery command know or care which channel is configured. To add a V2 channel (a native
Technician-app push notification, or an in-app inbox):

1. Add a new `TechnicianNotificationGateway` implementation (e.g.
   `PushTechnicianNotificationGateway`), following the same
   `NotificationDispatchData`/`NotificationDispatchResult`/`NotificationDispatchOutcome`
   contract WhatsApp already uses (SUBMITTED / DEFINITIVE_FAILURE / UNKNOWN / AMBIGUOUS) —
   applying the same conservative rule: only a response that definitively proves no message
   was created may be classified UNKNOWN (retryable); anything else uncertain must be
   AMBIGUOUS (`RECONCILIATION_REQUIRED`, never auto-resent).
2. Extend `outbound_notifications.channel`'s CHECK constraint to allow the new channel value
   (e.g. `'PUSH'`) — the table, statuses, idempotency model, and recovery command all work
   unchanged.
3. Either swap the gateway binding for the new channel entirely, or (if both WhatsApp and a
   V2 push notification should fire for the same assignment) have
   `CreateTechnicianAssignmentNotificationAction` write one obligation row per channel — the
   schema already supports multiple `outbound_notifications` rows per
   `technician_assignment_id` as long as `(assignment, type, channel)` stays part of a
   distinct idempotency key.
4. `App\Providers\TechnicianNotificationServiceProvider` is the only place a driver is
   selected — extend its `match` there, mirroring the existing `log`/`meta_whatsapp` arms.

No change to `technician_assignments`, the Admin assign/reassign endpoints, or the
notification recovery command is required for a V2 channel addition.
