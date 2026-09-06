# Soul Coffeemate — Backend API

Internal field-operations API for the SOUL COFFEEMATE electric-motorbike coffee-cart fleet.
Five roles: Administrator, Finance, Barista, Rider, Staff. No customer ever logs in.

**Laravel 12 · PHP 8.2 · MySQL 8 · Sanctum · Reverb**

The mobile client is a separate Expo/React Native app that ships as an Android APK.

## Specification

The business process is locked in documents kept alongside this repository:

| Doc | Contents |
|---|---|
| `02-context-business-process.md` | Roles, permission matrix, Flow A/B/C, state machine, invariants **R1–R16**, edge cases **E1–E24** |
| `04-api-contract.md` | Every endpoint, request/response shape, and status-code meaning |

If code and those documents disagree, the documents win — or they get amended first, deliberately.

## Local setup

```bash
composer install
cp .env.example .env
php artisan key:generate
# point DB_* at a MySQL 8 database, then:
php artisan migrate:fresh --seed
php artisan storage:link
php artisan serve --host=0.0.0.0 --port=8000
```

Realtime and queued work each need their own long-running process:

```bash
php artisan reverb:start      # WebSocket server (requirement 3)
php artisan queue:work        # outbox publishing, notifications, ledger retries
```

## Production deployment

**Requirements:** PHP 8.2+ (`pdo_mysql`, `mbstring`, `openssl`, `tokenizer`, `xml`, `ctype`,
`json`, `fileinfo`, `curl`, `bcmath`), MySQL 8, Composer, and a process supervisor.

```bash
composer install --no-dev --optimize-autoloader
php artisan key:generate          # once, then keep APP_KEY stable
php artisan migrate --force
php artisan storage:link
php artisan config:cache && php artisan route:cache && php artisan view:cache
```

**Document root must be `public/`, not the project root.** Pointing a domain at the project root
exposes `.env`, `storage/`, and `vendor/` to the internet. This is the single most common way a
Laravel deployment leaks its database credentials.

**Three long-running processes** — supervisor, systemd, or your panel's process manager:

| Process | Command | Purpose |
|---|---|---|
| Web | php-fpm / nginx | HTTP API |
| Reverb | `php artisan reverb:start` | WebSocket. Requirement 3 fails silently without it. |
| Queue | `php artisan queue:work --tries=3` | Outbox events, notifications, ledger retries |

**Reverb behind a reverse proxy.** The phone connects to a public WSS endpoint; Reverb binds
locally. Set `REVERB_HOST` to the public hostname and `REVERB_PORT=443` / `REVERB_SCHEME=https`,
while `REVERB_SERVER_HOST=0.0.0.0` / `REVERB_SERVER_PORT=8080` stay local. Nginx must forward
`Upgrade` and `Connection` headers, or the socket handshake fails and the client falls back to
polling — which still works, but is no longer realtime.

**Shared hosting caveat.** Most cPanel-style shared hosting cannot run persistent processes.
Without Reverb the app degrades to the client's 10-second polling fallback: functional for a
demo, but requirement 3 is not met. A small VPS is the right target if realtime matters.

**Before going live:**

- `APP_DEBUG=false` and `APP_ENV=production` — debug mode renders stack traces containing
  configuration values to anyone who triggers an error
- HTTPS with a valid certificate. The app carries PII (names, phones, GPS) and signature images.
- `REVERB_APP_SECRET` and `APP_KEY` generated fresh — never reuse the values from any example
- Replace the seeded demo users and their published passwords
- **Replace the placeholder product prices.** The seeder marks them `// PLACEHOLDER`: only the
  Rp 18.000–96.000 overall range was verifiable, not per-item prices.
- Set a real backup schedule and restore-test it once. A backup that has never been restored is
  not a backup.

## Design rules that are load-bearing, not stylistic

1. **`RefillRequestStateMachine` is the only writer of `refill_request.status`.** Assign it
   directly anywhere and every guarantee below becomes advisory.

2. **The Barista cannot prepare before Finance approval (R1).** Enforced by the transition guard,
   returning `409`. A disabled button in the app is convenience; the server is the gate.

3. **Monotonic quantities (R4):** `qty_requested ≥ qty_approved ≥ qty_prepared ≥ qty_received`.
   Violations return `422` — never a silent clamp, which would hide a real operational problem.

4. **`stock_ledger` is append-only (R6).** Stock levels are projections over it. Corrections are
   compensating entries, never updates.

5. **Money is `BIGINT` integer rupiah.** No floats, ever.

6. **Cost values are stripped for Barista, Rider and Staff (R15)** at serialisation — omitted from
   the JSON, not zeroed. Asserted by tests.

7. **`active_cart_id` enforces one open request per cart (R2).** MySQL 8 has no partial unique
   index, so the column holds `cart_id` while the request is open and `NULL` when terminal;
   MySQL permits many NULLs in a unique index. **Do not "tidy this up"** — removing it breaks R2
   with no visible symptom until two riders are dispatched to the same cart.

8. **Idempotency-Key is required on every state transition (R12).** A staff member on a bad
   connection who taps twice must not create two requests.

9. **Events are written to `outbox_events` inside the state-change transaction** and published by
   the queue worker, so restarting Reverb loses no notifications.

## Sign-in credentials: password, then PIN

An account starts password-only. The moment its owner creates a 6-digit login PIN in the app, the
PIN **replaces** the password for signing in:

| Account state | `POST /auth/login` (password) | `POST /auth/login-pin` |
|---|---|---|
| No login PIN | 200 + token | `409 PIN_NOT_SET` |
| Login PIN set | `409 PIN_REQUIRED` (only after the password checked out) | 200 + token |

Three consequences that are load-bearing, not incidental:

1. **Creating a PIN revokes every session**, on every device, including the one that created it
   (`LoginPinController::store`). The app then sends the user straight to the PIN pad, so they
   prove they can type it while they still remember it. Without this, a mistyped-but-confirmed PIN
   would only be discovered at the next sign-in, by which point the password no longer works.
2. **A forgotten PIN is not self-service.** Five wrong attempts lock the PIN route for 15 minutes
   and there is no password to fall back to, so the recovery path is
   `POST /auth/pin-reset-requests` → the **Permintaan Reset PIN** queue in the panel. An
   Administrator types a new password, and applying it clears the PIN and revokes every session in
   one transaction (`App\Services\PinResetService`). The password is never generated: the
   characters are the administrator's, and they pass them to the person out of band, using the
   email captured with the request.
3. **The panel is not covered by this rule.** `/admin` still authenticates by password — it has no
   PIN entry, and gating it would lock Administrators out of their only surface. See the
   `AuthController` docblock.

The `409` on the password route is answered **only after the password matched**. Answering it
sooner would turn the login endpoint into a free "does this account have a PIN" oracle.

## Push notifications

Every event that already flowed through `EventPublisher` now takes a second path to the phone:
the Pusher socket as before, plus an FCM push to each of the recipient's registered devices. Both
carry the same `event_id`, which is what the app dedupes on (E15), and the actor is excluded — the
person who tapped the button is looking at the result already.

- **Registration:** `POST /me/devices` (upsert on the FCM token), `DELETE /me/devices` on sign-out.
  The token is keyed by device, not by user, so a shared handset follows whoever signed in last.
- **Transport:** `App\Services\Push\FcmClient` speaks the FCM HTTP v1 API directly — a
  service-account JWT for an OAuth2 bearer, then one `messages:send` per device, pooled. No SDK.
- **Timing:** sent inline after the transaction commits, deliberately **not** queued. With
  `QUEUE_CONNECTION=database` and no resident worker on this host, a queued push would wait for a
  cron tick; "your refill is ready" thirty minutes late is worse than useless.
- **Failure:** never throws. A provider outage delays nothing else — the event is already durable
  in `outbox_events` and `notifications`. A token FCM reports as `UNREGISTERED` is deleted.

Modules that previously emitted no events now do: absen (clock-in, staff window opened), showcase
stock (brew, hand-to-cart, close-out) and the news feed (`NewsPostObserver`, fired once on the
transition into a published state).

**Setup.** Push stays switched off until both halves exist, and everything else works meanwhile:

```bash
# 1. Firebase console -> Project settings -> Service accounts -> Generate new private key
#    Upload the JSON to the server, outside public/:
#      storage/app/private/fcm-service-account.json
# 2. .env:
FCM_PROJECT_ID=your-firebase-project-id
FCM_CREDENTIALS_PATH=/full/path/to/storage/app/private/fcm-service-account.json
# 3. php artisan config:clear && php artisan config:cache
```

The mobile app needs `google-services.json` from the **same** Firebase project, dropped into the
`soul_coffe.mobile` directory before building the APK. Without either half, `PushNotifier` logs
that it is unconfigured and sends nothing; with both, no code change is needed anywhere.

## Security posture

- Sanctum bearer tokens, device-labelled and individually revocable
- Role checks are applied **at the query level**, never by filtering a response
- Every state transition writes an audit row: actor, role, from, to, time, device, GPS (R8)
- Evidence photos must be camera-captured and fresh (R3/E6); signatures are hash-unique (R13)
- PII (name, phone, GPS, signature) never appears in logs

Reviewed against `docs/02` §5.6. Auth, PII, and the money path warrant a security review before
production exposure.
