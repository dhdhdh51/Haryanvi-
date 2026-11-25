# Testing report

What was actually executed, what it found, and — just as importantly — what is
**not** covered. Every number below was produced by running the suites, not
estimated.

Environment: PHP 8.4.27 · MySQL 8.4.3 · JDK 21 · AGP 8.7.3 · Kotlin 2.0.21 ·
Android SDK 35 · build-tools 35.0.0

---

## Summary

| Suite | Assertions | Result |
|---|---|---|
| `ValidatorTest` | 33 | pass |
| `ProviderParsingTest` | 40 | pass |
| `PromptBuilderTest` | 30 | pass |
| `TextGuardTest` | 23 | pass |
| `CryptoTest` | 18 | pass |
| `LanguageDetectorTest` | 14 | pass |
| `SqlPlaceholderTest` | 3 | pass |
| **Backend unit subtotal** | **161** | **pass** |
| `ApiTest` (integration) | 110 | pass |
| `AdminTest` (integration) | 104 | pass |
| `ProviderFailureTest` (integration) | 27 | pass |
| **Backend integration subtotal** | **241** | **pass** |
| **Backend total** | **402** | **pass** |
| Android unit (JVM), 3 classes | 42 tests | pass |
| Debug APK build | — | pass (19.1 MB) |
| Release APK build (R8 + resource shrinking) | — | pass (2.0 MB) |
| Release signing + signature verification | — | pass (v2 + v3) |
| Android lint (`lintVitalRelease`) | — | pass, 0 errors |
| PHP syntax check | 98 files | pass |

The integration suites ran against a **real MySQL 8.4 server** and a **real
HTTPS AI provider** (the mock fixture, over TLS with certificate verification
enabled), exercising the production code paths rather than stubs. The mock
provider received **23 requests** across the run.

Reproduce:

```bash
php backend/tests/run.php                 # unit only
php backend/tests/run.php --all           # + integration (needs a running instance)
cd android && ./gradlew testDebugUnitTest lintDebug assembleDebug assembleRelease
```

---

## What the tests cover

### Translation engine

- **Language detection** — Devanagari Hindi, English, romanised Hinglish, and
  mixed script. The Hinglish-versus-English distinction is the hard case
  ("kal milte hai" versus "see you tomorrow") and is tested both ways.
- **Content protection** (`TextGuard`) — URLs, emails, `@handles`,
  `#hashtags`, phone numbers and OTPs are masked, round-tripped and restored
  byte-for-byte. Emoji and short numbers are asserted to stay inline and to
  appear as prompt constraints. A dropped placeholder is detected.
- **Prompt assembly** — the dialect's linguistic guidance, the tone
  instruction, the output-script choice, the JSON output contract and all of
  the spec's translation rules are asserted present. The rules forbidding
  Punjabi, Rajasthani and Bhojpuri borrowing are checked individually.
- **Prompt-injection resistance** — the untrusted message is fenced between
  explicit markers and the model is told to treat it as data; a raw URL is
  asserted never to reach the provider.
- **Provider drivers** — request shape, endpoint construction and response
  parsing for all three drivers, including Anthropic's multi-block content and
  Gemini's safety-block response. Error classification (401/403/404 permanent,
  429/5xx retryable) is asserted, since it decides retry versus fallback.
- **Secret hygiene** — a serialised provider never contains its key; reading a
  missing key throws rather than returning empty.

### Security

- **Encryption** — AES-256-GCM round trip, non-determinism, tamper detection
  (a flipped bit fails authentication), rejection of malformed envelopes, and
  failure to decrypt after key rotation. A missing key raises an explicit
  error instead of silently storing plaintext.
- **Validation** — control characters stripped, CRLF normalised, lengths
  counted in characters not bytes, all-digit passwords rejected, plain HTTP
  rejected where HTTPS is required, every field error reported in one response.
- **Authentication** — a wrong password and an unknown email produce byte-identical
  401 responses; a forged token is rejected; a password change revokes every
  other session (verified by watching a second live session go 401); logout
  invalidates the token; a deleted account can no longer sign in.
- **Authorisation** — history rows are addressed by UUID and scoped to the
  caller; probing a sequential id returns 404.
- **CSRF** — a forged dashboard token is rejected and changes nothing.
- **Quotas** — enforced server-side; failed translations do not count.
- **SQL placeholder hygiene** — a test scans every SQL statement in the source
  tree and fails if any reuses a named placeholder (see "Bugs found" below).

### API behaviour

Registration, login, logout, password change, account deletion; translation in
Hindi, English and Hinglish with dialect, tone and script selection; history
list, search, favourites, delete, clear and upload; feedback submission; usage
reporting; 404 and 405 routing; malformed-JSON rejection; over-length input
rejection.

### AI failure handling

Each case was injected and the response asserted:

| Injected condition | Expected | Verified |
|---|---|:--:|
| Invalid credentials (401) | 502, no retry, next provider tried | ✓ |
| Provider 500 | retried, then 502 | ✓ |
| Provider 429 | retried, then 502 | ✓ |
| Non-JSON response body | 502 | ✓ |
| Empty translation in valid JSON | 502 | ✓ |
| Model refusal ("I'm sorry, I cannot…") | 502, never shown as Haryanvi | ✓ |
| Bare text instead of the JSON contract | recovered, 200 | ✓ |
| Timeout | 502 | ✓ |
| Primary fails permanently, secondary healthy | 200 via fallback | ✓ |
| No provider configured at all | 503 `AI_PROVIDER_NOT_CONFIGURED` | ✓ |

In every failure case the response contains **no translation text** — the API
never fabricates output.

### Admin dashboard

Unauthenticated access is refused; a forged CSRF token establishes no session;
a failed login shows a neutral message and is recorded in the audit trail; the
seeded account is forced to rotate its password before anything else works;
all ten pages and all four create/edit forms render without PHP errors; a
settings update persists; an unknown setting key is ignored rather than
inserted; a vocabulary entry can be created, searched and deleted through the
UI; the audit trail records sign-ins, settings changes and vocabulary changes;
view templates cannot be requested directly; security headers
(`X-Frame-Options: DENY`, `nosniff`, CSP, `no-store`) are present; Devanagari
and HTML-escaped ampersands render correctly.

### Database

`schema.sql` and `seed.sql` load cleanly into MySQL 8.4 with no warnings,
producing 16 tables. Seed verified: 5 dialects, 6 tone presets, 125 vocabulary
entries (17 dialect-specific), 21 settings, 1 admin. Devanagari survives the
round trip through `utf8mb4`.

### Android

- **Sensitive-field detection** — 20 tests. Every password variation, numeric,
  phone, datetime, email, URI and postal field is refused; the
  `NO_SUGGESTIONS` and `IME_FLAG_NO_PERSONALIZED_LEARNING` flags are honoured;
  an unrecognised input class fails closed; message fields are allowed.
- **Keyboard layouts** — 12 tests. Every layer has rows, no key has a
  non-positive width, no row is wide enough to push keys off screen, QWERTY
  covers a–z with correct shifted faces, the Devanagari layer contains the
  consonants Haryanvi needs (including `ण` and `स`), every layer offers
  backspace and a route back to letters, the translate key renders inactive
  when translation is disallowed, and undo replaces paste only when a snapshot
  exists.
- **Error classification** — 10 tests over the mapping that decides what the
  user is told and whether a retry button appears.

---

## Bugs found and fixed

Testing and a deployment review found six real defects. Each is listed with how
it would have manifested in production.

**0. The backend did not work on nginx at all** *(found while reviewing a real
target server, after the initial delivery)*
The project shipped `.htaccess` only. Apache reads those files; nginx ignores
them. On a default aaPanel or BT Panel site — both default to nginx — every
`/api/v1/*` request returned 404 because nothing rewrote it to `index.php`, and
`database/schema.sql`, `database/seed.sql` and `storage/logs/*.log` were
publicly downloadable. The SQL contains the seeded administrator's bcrypt hash.
`config/config.php` was *not* dumped, because PHP-FPM executes it and it only
`return`s an array — but that is protection by accident, not by configuration.
Fixed by adding nginx rules for both panel-managed and standalone installs, an
installer that strips `tests/` from a live site, and a documented verification
step that curls every sensitive path and requires 403 or 404. This was the most
serious issue found in the project, and no test caught it — it took looking at
the actual server.

**1. Repeated named SQL placeholders (3 occurrences)**
`WHERE a LIKE :search OR b LIKE :search` fails with `SQLSTATE[HY093]` under
PDO with emulated prepares disabled — which is how this project connects.
Symptom: history search, admin user search and vocabulary search would all
return HTTP 500, and admin login would fail outright. Fixed by giving each
placeholder its own name, and a unit test now scans every SQL statement in the
source tree so it cannot recur.

**2. Empty translation returned the raw JSON envelope**
When a provider replied with valid JSON but an empty `haryanvi` field, the
parser fell through to its plain-text fallback and handed the user
`{"haryanvi": "", "roman": null}` as though it were a translation. Fixed: a
reply that parses as JSON but carries no translation now fails, and the
retry/fallback logic takes over.

**3. Provider timeout silently clamped**
The HTTP layer forced a floor of 5 seconds, ignoring a shorter configured
value. An operator setting 2 seconds got 5 with no indication. Fixed to honour
the configuration, clamping only against zero and absurd values.

**4. Dashboard templates did not receive the session**
`View::render()` passed the session to the layout but not to the page
template, so every page using a role-based control crashed with
`Undefined variable $session` — six of ten pages returned HTTP 500.

**5. Backup rules were invalid and pointed at the wrong file**
`FullBackupContent` lint found ten errors: `<exclude>` entries alongside an
`<include>` are invalid, and the included path named a SharedPreferences file
that does not exist (DataStore writes to `files/datastore/`). Fixed to
include-only semantics with the correct path — which also means the auth token
and history database are excluded by definition rather than by a list that
could fall out of date.

Two further issues were test defects rather than product defects: an assertion
expected `hi` where the server correctly reports `hinglish`, and another
searched for a raw `&` in HTML that is correctly escaped to `&amp;`. The first
prompted a deliberate design change — the server's deterministic language
detector is now authoritative over the model's self-report, because the former
is tested and the latter is not.

---

## Not covered

Stated plainly, because a test report that implies more coverage than exists is
worse than no report.

**Requires a physical device or emulator.** No emulator was available in the
build environment, so the following were verified by construction and code
review only:

- Keyboard insertion into WhatsApp, Instagram, Telegram and Messenger.
- Keyboard stability when switching between apps mid-session. The service
  clears its per-field state in `onStartInputView` and `onFinishInput`, and
  holds no reference to a previous `InputConnection`, which is the usual cause
  of such crashes — but this has not been observed on hardware.
- Floating bubble drag, placement and panel interaction over a real app.
- The overlay permission grant and revoke flow.
- Share-sheet and text-selection entry from third-party apps.
- Rendering across screen sizes, densities and font-scale settings.
- Behaviour on Android 8.0 through 15 specifically. `minSdk` is 26 and the
  code guards every version-sensitive API (`TYPE_APPLICATION_OVERLAY`,
  `VibratorManager`, `startForeground` with a service type, notification
  channels), but only the compile-time contract is verified.

**Requires a paid AI provider account.** Translation *quality* — whether the
Haryanvi is idiomatic, regionally correct and free of Punjabi or Rajasthani
leakage — cannot be asserted by a test. The mock provider verifies the
plumbing: prompt assembly, transport, parsing, retry, fallback, placeholder
restoration, history, quota and usage accounting. Judging the output needs a
real model and native speakers.

Recommended before release: run `php bin/provider.php test --id=N` with a real
key, then review a sample across all five dialects and all six tones with
speakers from each region, and use the dashboard's Translation quality queue to
fold their corrections into the lexicon.

**Not tested at all**

- **The nginx configuration itself.** `deploy/install.sh` was run end to end
  against a real MySQL instance — clean install, schema and seed loaded
  (5 dialects, 6 tones, 125 vocabulary entries), `config.php` generated at
  `chmod 600` with a fresh key, health check reporting `database: connected`,
  and `--update` verified to preserve the encryption key and all 125 rows. But
  `nginx -t` was never run and no request has been served through nginx: there
  was no nginx in the build environment. The rules are written against aaPanel's
  documented include point and the standard `try_files` pattern, and the
  `curl` verification in `deploy/README.md` exists precisely because they need
  confirming on the real server.
- Load and concurrency. The rate limiter uses an atomic upsert and the quota
  counters use `ON DUPLICATE KEY UPDATE`, so both are safe under concurrent
  requests by construction, but no load test was run.
- Instrumented (`androidTest`) tests. The dependencies and source set are
  configured and the suite runs in CI once a device is attached; no tests have
  been written yet.
- Database migrations beyond the baseline. `bin/migrate.php` applies and
  records `.sql` files from `database/migrations/`, and the mechanism works,
  but there is only one schema version so far.
- MariaDB. The schema deliberately avoids MySQL-8-only collations and should
  load on MariaDB 10.6+, but only MySQL 8.4 was exercised.
- Play Store review and policy compliance for a keyboard app.

---

## Test infrastructure

The suites are part of the repository, not throwaway scaffolding.

| Path | Purpose |
|---|---|
| `backend/tests/run.php` | Runner. `--all` adds integration, `--filter=` narrows. Non-zero exit on failure. |
| `backend/tests/Assert.php` | Assertion helpers. No Composer dependency, matching the project's constraint. |
| `backend/tests/unit/` | Pure logic: detection, text protection, crypto, validation, prompts, provider parsing, SQL hygiene. |
| `backend/tests/integration/` | HTTP tests against a running instance: API, provider failures, dashboard. |
| `backend/tests/support/mock-ai-server.py` | HTTPS OpenAI-compatible fixture with failure injection by model name. |
| `backend/tests/support/generate-cert.php` | Self-signed certificate for the fixture, using PHP's openssl extension. |
| `backend/tests/support/dev-router.php` | Reproduces the production Apache routing under `php -S`. |
| `android/app/src/test/` | JVM unit tests. |

The mock provider is a **test fixture only**. The application has no mock
driver and refuses to invent a translation when no provider is configured —
which is itself asserted by the integration suite.
