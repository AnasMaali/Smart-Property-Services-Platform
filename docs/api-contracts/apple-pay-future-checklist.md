# BLUE V1 — Apple Pay Future Checklist (deferred, does not block Phase 6B)

Apple Pay is **approved as a future BLUE V1 payment method through Stripe** — it is a Stripe payment
method surfaced through the same PaymentIntent BLUE already creates
(`automatic_payment_methods.enabled = true`), not a second payment system. See "Apple Pay readiness"
in `docs/api-contracts/payments-v1.md` for the backend architecture, which needs **no further change**
for Apple Pay specifically. Everything on this list is Flutter/iOS/Apple Developer configuration,
deferred until that client work begins — it does not block Phase 6B backend completion.

## Prerequisites

- [ ] **Apple Developer account** (individual or organization) enrolled in the Apple Developer
      Program — required to register a Merchant ID and enable the Apple Pay capability.
- [ ] **Apple Merchant ID** — registered in the Apple Developer portal
      (Certificates, Identifiers & Profiles → Identifiers → Merchant IDs), e.g.
      `merchant.com.<company>.blue`. This ID is what Stripe's Apple Pay integration and the Flutter
      app both reference — it is not a BLUE database value and is never stored in
      `payment_attempts` (see "Apple Pay readiness": no `apple_pay_payments` table, no
      Apple-specific column, by design).
- [ ] **Apple Pay capability** enabled on the app's iOS App ID in the Apple Developer portal, and the
      corresponding entitlement (`com.apple.developer.in-app-payments`) added to the Flutter/iOS
      project's entitlements file, listing the Merchant ID above.
- [ ] **Stripe Apple Pay configuration** — in the Stripe Dashboard (Settings → Payment methods →
      Apple Pay), register the same Apple Merchant ID and complete Stripe's domain association file
      upload (for web) or the native iOS merchant verification flow, per Stripe's own Apple Pay
      setup docs at the time this is implemented.
- [ ] **Merchant country configuration** — the Merchant ID's country must match the Stripe account's
      country/settlement configuration; confirm this before generating any Apple Pay certificate.

## Flutter/iOS integration (deferred — not part of Phase 6B)

- [ ] Add the Stripe Flutter SDK's Apple Pay support to the PaymentSheet configuration, referencing
      the publishable key BLUE's `POST /v1/payments` response already returns
      (`data.payment.publishable_key`, see "Client Initiation Contract" in `payments-v1.md`) and the
      Merchant ID above.
- [ ] No new BLUE backend field is required to pass the Merchant ID through `POST /v1/payments` — it
      is static, client-side/build configuration (an iOS entitlement + PaymentSheet parameter), not
      a per-payment value the server needs to supply.
- [ ] Confirm `payment_method_type` normalization already handles the Apple Pay wallet case
      end-to-end once real transactions exist — `StripePaymentGateway::normalizePaymentMethodType()`
      already reads a wallet type off an expanded `payment_method.card.wallet.type` when Stripe
      provides one (see `payments-v1.md` "Apple Pay readiness") — this only needs a real Apple Pay
      transaction to observe, not a code change.

## Testing (requires a physical device)

- [ ] **Physical supported Apple device** — the iOS Simulator cannot present the real Apple Pay
      sheet; testing requires a physical iPhone/iPad signed into a sandbox Apple ID with at least one
      test card provisioned in Wallet (per Apple's Apple Pay sandbox testing docs).
- [ ] End-to-end test: Flutter PaymentSheet → Apple Pay sheet → Stripe confirms the same PaymentIntent
      → BLUE's existing webhook path (`ProcessPaymentWebhookAction`) transitions the attempt exactly
      as a card payment would — no Apple-Pay-specific backend code path should be needed to observe
      this, which is itself the thing to verify (if it isn't true, the backend accidentally grew
      wallet-specific logic and that is a regression against "Apple Pay is not a second payment
      architecture").

## What this explicitly does not include

- No `apple_pay_payments` table or any other new schema.
- No Apple-Pay-specific amount, currency, or pricing logic — the exact same
  `PaymentCreationData`/`PaymentIntent` flow BLUE already uses for cards.
- No Apple-Pay-specific webhook event type — Apple Pay transactions still emit
  `payment_intent.succeeded`/`.payment_failed`/`.canceled`/`.processing`, already mapped.
- No automatic Booking creation change — Apple Pay success reaches the same
  `SUCCESSFUL`/`requires_reconciliation` outcome as any other payment method (see "Payment → Booking
  boundary" in `payments-v1.md`).
