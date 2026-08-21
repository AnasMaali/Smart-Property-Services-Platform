# BLUE — Customer Mobile App

Flutter app for the BLUE property-services platform, targeting **Android and iOS from one
codebase**. The authoritative frontend plan is
[`docs/flutter/flutter-integration-blueprint-v1.md`](../docs/flutter/flutter-integration-blueprint-v1.md)
at the repository root — read that before building any feature.

## ⚠️ Application identity: PENDING PRODUCT DECISION

```
FINAL_ANDROID_APPLICATION_ID = PENDING PRODUCT DECISION
FINAL_IOS_BUNDLE_ID = PENDING PRODUCT DECISION
```

No official Android `applicationId`, iOS bundle identifier, or reverse-DNS organization
exists anywhere in this repository as of this phase (F1). This project was generated with
the deliberately temporary org **`dev.blue.placeholder`**
(`applicationId`/bundle id `dev.blue.placeholder.blue`), which is **scaffolding only**.

**Do not** use `dev.blue.placeholder` for, or begin, any of the following until a real
identifier is confirmed and the generated Android/iOS project files are updated:

- App Store Connect or Google Play Console listing/provisioning
- Apple Developer Program provisioning, certificates, or entitlements
- Sign in with Apple
- Apple Pay merchant/app association
- Firebase project setup
- Push notification certificates/credentials
- Universal links / associated domains, or any deep link tied to production identity

None of the above is configured in this phase — F1 only builds the app skeleton.

## What's implemented so far (F1 — foundation only)

- Project skeleton (`android/`, `ios/` platform projects; no Linux/macOS/Windows/Web)
- `core/config` — typed `AppConfig`, environment (`dev`/`staging`/`production`) and API
  base URL resolved from compile-time `--dart-define`, with production builds refusing to
  start against a local/insecure URL
- `core/network` — `ApiClient` (Dio-based), `AuthInterceptor` (single-flight
  401 → refresh → retry, per the blueprint's session-management design), global
  `sessionStatusProvider`
- `core/storage` — `TokenStore` abstraction + OS Keychain/Keystore-backed
  `SecureTokenStore` (`flutter_secure_storage`)
- `core/errors` — centralized `ApiFailure` taxonomy mapped from real backend HTTP
  responses
- `core/routing` — `go_router` foundation with three placeholder routes
- `core/theme` — a modest, replaceable Material 3 light theme (no official BLUE brand
  guide exists yet)
- Placeholder Splash / Login / Home screens proving the app boots and navigates
- `features/*` folders exist as named placeholders only (no code yet) — see each
  folder's `README.md` for which phase builds it

**Not implemented yet**: real Login/Register/Home UI, Stripe, any backend repository
beyond the network/token foundation. See
`docs/flutter/flutter-integration-blueprint-v1.md` §21 for the full phase order.

## Prerequisites

- A Flutter SDK on the **stable** channel. Verify your setup with:
  ```
  flutter doctor -v
  ```
  On Linux, `flutter doctor` will correctly report the Android toolchain as unconfigured
  until you install Android Studio / the Android SDK (only needed once you actually run
  the app, not for `flutter analyze`/`flutter test`), and will show no iOS line — Xcode
  and iOS builds are only ever possible from a macOS machine. **This project targets both
  Android and iOS from the same codebase**; iOS builds, signing, and App Store submission
  happen later on macOS/Xcode, and are out of scope for local Linux/Windows development.

## Setup

```
cd mobile
flutter pub get
```

## Running the app

The backend API base URL and environment are **never** hardcoded — they're passed at
build/run time via `--dart-define`, matching the "no committed secret `.env` files"
security rule in the blueprint (§7/§18).

The backend's API is mounted under `/api`, with every route under `/v1/...` (e.g.
`POST /v1/auth/login` is reachable at `{API_BASE_URL}/v1/auth/login`) — `API_BASE_URL`
below should therefore point at `.../api`, **without** a trailing `/v1`.

### Android emulator (development)

The Android emulator cannot reach your host machine's Laravel dev server through
`127.0.0.1` — the emulator's own loopback interface is not the host's. Use the emulator's
special host alias `10.0.2.2` instead (this is also the default baked into `AppConfig` if
`API_BASE_URL` is omitted):

```
flutter run \
  --dart-define=APP_ENV=development \
  --dart-define=API_BASE_URL=http://10.0.2.2:8000/api
```

### Physical Android device (development)

A physical device is on a different network path than the emulator alias above — point it
at your development machine's actual LAN IP instead (find it with `ip addr` / `ifconfig`
on the machine running `php artisan serve`), with both devices on the same network:

```
flutter run \
  --dart-define=APP_ENV=development \
  --dart-define=API_BASE_URL=http://192.168.1.23:8000/api
```

### Staging

```
flutter run \
  --dart-define=APP_ENV=staging \
  --dart-define=API_BASE_URL=https://staging-api.blue.example/api
```

### Production

```
flutter run \
  --dart-define=APP_ENV=production \
  --dart-define=API_BASE_URL=https://api.blue.example/api
```

`AppConfig` deliberately **refuses to start** (throws `AppConfigError`) if `APP_ENV=production`
is combined with a non-`https://` URL, or a URL pointing at `localhost`, `127.0.0.1`, or
`10.0.2.2` — this is intentional: a production build must never silently point at a local
development backend.

### iOS

Not runnable from this Linux development environment. iOS builds, code signing, Xcode
capabilities/entitlements, TestFlight, and App Store submission all require a macOS
machine with Xcode installed, and are deferred to that later step — nothing about the
Dart/Flutter code in this project is Android-specific, so no architectural change is
expected to be needed when that step begins.

## Quality checks

```
dart format .
flutter analyze
flutter test
```

All three must pass before committing. `flutter test` runs entirely headless (no
emulator/device, no real network call, no real OS Keychain/Keystore access) — see the test
files under `test/core/` for how the network and storage layers are tested against fakes.

## Security rules — never violate these

- **Never commit secrets.** No `STRIPE_SECRET_KEY`, Stripe webhook secret, Twilio auth
  token, `AUTH_JWT_SECRET`, database credentials, or any other backend-only secret ever
  belongs in this Flutter project, in any form (code, `--dart-define`, committed config
  file, or otherwise). None of these are ever returned by the backend API either — see
  `docs/api-contracts/*.md`.
- Access and refresh tokens are only ever read/written through `TokenStore`
  (`core/storage/`), which is backed by the OS Keychain/Keystore. Never store a token in
  `SharedPreferences`, a log line, or plain application state.
- A Stripe `publishable_key` (once the Payment feature is built) is client-safe and always
  sourced from the backend's per-payment API response — never hardcoded, and never
  confused with the secret key above.
- Never log an access token, a refresh token, a Stripe `client_secret`, an OTP code, or a
  password/`current_password` value, anywhere, in any build configuration.
