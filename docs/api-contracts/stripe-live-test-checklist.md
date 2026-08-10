# BLUE V1 — Stripe Live-Test Checklist (Phase 6B → real credentials)

This checklist is for **later**, once a real Stripe account exists. Nothing in BLUE V1's backend
code needs to change to complete it — `App\Support\Payment\Gateway\StripePaymentGateway` and
`App\Providers\PaymentServiceProvider` already read `STRIPE_SECRET_KEY`, `STRIPE_PUBLISHABLE_KEY`,
and `STRIPE_WEBHOOK_SECRET` purely from the environment (`config/services.php`). Completing this
checklist is an operational/configuration exercise, not a code change.

**Do not put real Stripe keys or webhook secrets in this file, in any doc, in Postman, or in `.env`
committed to git.** Only `.env` (untracked, already `.gitignore`d) ever holds real values.

## Setup

- [ ] **1. Create a Stripe account** (or use the team's existing one) at https://dashboard.stripe.com.
- [ ] **2. Enable test mode** — the toggle in the Stripe Dashboard. All items below happen in test
      mode first; do not point BLUE at live keys until every item here has passed against test mode.
- [ ] **3. Obtain a test secret key** (`sk_test_...`) from Dashboard → Developers → API keys.
- [ ] **4. Obtain the matching test publishable key** (`pk_test_...`) from the same page.
- [ ] **5. Configure a webhook endpoint** in Dashboard → Developers → Webhooks, pointing at
      `https://<your-tunnel-or-server>/api/v1/payments/webhooks/stripe`. Locally, use the Stripe CLI
      (`stripe listen --forward-to localhost:8000/api/v1/payments/webhooks/stripe`) or a tunnel
      (ngrok/Cloudflare Tunnel) — Stripe cannot reach `localhost` directly. Subscribe at minimum to
      `payment_intent.succeeded`, `payment_intent.payment_failed`, `payment_intent.canceled`,
      `payment_intent.processing` (see docs/api-contracts/payments-v1.md "Stripe → BLUE
      normalization" — any other event type is safely ignored, not an error, but subscribing only to
      what BLUE understands keeps the Dashboard's delivery log readable).
- [ ] **6. Obtain the webhook signing secret** (`whsec_...`) Stripe shows once the endpoint is
      created (Dashboard → the endpoint's detail page → "Signing secret").
- [ ] **7. Configure `.env` locally/server-side** — set `PAYMENT_PROVIDER=stripe`,
      `STRIPE_SECRET_KEY=sk_test_...`, `STRIPE_PUBLISHABLE_KEY=pk_test_...`,
      `STRIPE_WEBHOOK_SECRET=whsec_...`. Restart `php artisan serve` / the app server so the new
      values are read (Laravel config is cached in some deploy setups —
      `php artisan config:clear` first if so).
- [ ] **8. Never commit real keys** — confirm `backend/.env` is still untracked
      (`git check-ignore backend/.env` should print the path) and that `backend/.env.example` still
      has all three `STRIPE_*` values empty before and after this checklist.
- [ ] **9. Configure supported payment methods** in Dashboard → Settings → Payment methods (card is
      on by default; enable Apple Pay/Google Pay here too, though their client-side setup is
      separate — see `apple-pay-future-checklist.md`).

## Functional verification (test mode)

Use `POST /api/v1/payments` (real `PAYMENT_PROVIDER=stripe`, not the test suite's `FakePaymentGateway`)
against a real test-mode Stripe account for all of the below. Stripe's published test card numbers
(https://stripe.com/docs/testing) drive most of these from the client side (Stripe CLI, Postman
against a manually-confirmed PaymentIntent, or once available, the Flutter PaymentSheet).

- [ ] **10. Successful card** (`4242 4242 4242 4242`) — confirm the PaymentIntent client-side, verify
      the webhook arrives, `payment_attempts.status` reaches `SUCCESSFUL`,
      `requires_reconciliation = 0`.
- [ ] **11. Declined card** (`4000 0000 0000 0002`) — confirm the PaymentIntent stays
      `requires_payment_method` (not BLUE `FAILED`); `payment_attempts.status` stays `PENDING`.
- [ ] **12. 3DS authentication** (`4000 0027 6000 3184` or another SCA-required test card) — confirm
      the PaymentIntent passes through `requires_action` and BLUE stays `PENDING` throughout, then
      reaches `SUCCESSFUL` once 3DS completes.
- [ ] **13. Cancellation** — cancel the PaymentIntent (Dashboard or API) before confirmation; verify
      the webhook transitions `payment_attempts.status` to `CANCELLED` and the appointment hold is
      released / cart returns to `ACTIVE` (see `ProcessPaymentWebhookAction::handleCanceled()`).
- [ ] **14. Duplicate/replayed webhook** — use Dashboard → the endpoint → "Resend" on an already-
      processed event (or `stripe events resend`), verify `payment_webhook_events` shows no new row
      for the same `provider_event_id` and no duplicate financial mutation (see "Event ledger" in
      `payments-v1.md`).
- [ ] **15. Webhook delay** — confirm a payment before the webhook arrives, verify
      `payment_attempts` stays `PENDING`/recoverable with no premature failure, and that once the
      (delayed) webhook does arrive, it still resolves correctly.
- [ ] **16. App/network interruption during create** — simulate a client timeout on `POST
      /api/v1/payments` (e.g. kill the connection right after sending, before the response returns)
      while the PaymentIntent may have already been created Stripe-side; verify a retry with the same
      `Idempotency-Key` recovers the same attempt/PaymentIntent rather than creating a second one
      (see "Idempotency" in `payments-v1.md`).
- [ ] **17. Verify amount/currency** — confirm a real payment for a known cart total and check
      `payment_attempts.confirmed_amount`/currency match `requested_amount`/the cart's currency
      exactly, with `requires_reconciliation = 0`. Optionally force a mismatch (a manually-created
      PaymentIntent for a different amount matched to an existing `checkout_reference`) to confirm
      `AMOUNT_MISMATCH`/`CURRENCY_MISMATCH` still land as `SUCCESSFUL` + `requires_reconciliation = 1`
      rather than `FAILED` (see "Reconciliation" in `payments-v1.md`) — do this only in test mode.
- [ ] **18. Verify no duplicate PaymentIntent** — for every scenario above, check the Stripe
      Dashboard's PaymentIntent list for the test cart/customer and confirm exactly one PaymentIntent
      per `payment_attempts` row (matching `provider_session_reference`), even across retries.
- [ ] **19. Confirm no Booking creation in Phase 6B** — after every successful payment above, verify
      `bookings`/`booking_items` remain empty (`SELECT COUNT(*) FROM bookings` = 0). Booking creation
      is out of scope for Phase 6A/6B by design (see "Payment → Booking boundary" in
      `payments-v1.md`).
- [ ] **20. Repeat the full BLUE automated test suite** (`php artisan test`) after configuring real
      keys, to confirm nothing about a real, configured Stripe account changes any existing
      behavior — the suite must still pass entirely against `FakePaymentGateway`
      (`APP_ENV=testing` never binds `StripePaymentGateway` regardless of `.env`, so this is a
      sanity check on the test harness itself, not the live integration).

## After this checklist passes

Only once every item above is checked against Stripe **test mode** should live-mode keys
(`sk_live_...`/`pk_live_...`/a live-mode webhook endpoint and its own `whsec_...`) be considered —
that is a separate, explicit decision requiring the same care (a second pass through items 7-9 with
live values, plus a small-value real-card smoke test before any customer-facing rollout).
