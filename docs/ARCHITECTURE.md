# Architecture

Why the system is shaped the way it is.

---

## Overview

```
┌─────────────────────────── Android ───────────────────────────┐
│                                                               │
│  Compose UI ──┐                                               │
│  Keyboard ────┼──► Repositories ──► ApiClient ──► HTTPS       │
│  Bubble ──────┤         │                                     │
│  Share ───────┘         ├──► Room (history, device-local)     │
│                         ├──► DataStore (preferences)          │
│                         └──► Keystore + prefs (bearer token)  │
└───────────────────────────────┬───────────────────────────────┘
                                │
┌───────────────────────────────▼───────────────────────────────┐
│                     Apache + PHP (shared hosting)             │
│                                                               │
│  index.php ──► Router ──► Middleware ──► Controller           │
│                            │                 │               │
│            CORS, HTTPS, maintenance,         ▼               │
│            auth, throttle            Services ──► Repositories│
│                                         │              │      │
│                            TranslationService          ▼      │
│                                         │           MySQL     │
│  admin/index.php ──► Dashboard          ▼                     │
│                              ProviderFactory                  │
│                                         │                     │
└─────────────────────────────────────────┼─────────────────────┘
                                          ▼
                              AI provider (OpenAI-compatible,
                              Anthropic or Gemini) over HTTPS
```

---

## Key decisions

### No Composer, no Node.js

The target is shared hosting, where neither is reliably available and where
"upload these files" is the whole deployment process. The backend uses a
60-line PSR-4 autoloader; the dashboard is server-rendered PHP with one
stylesheet. This rules out some conveniences — the test suite brings its own
assertion library rather than using PHPUnit — and buys a project that installs
by FTP.

### Hand-rolled HTTP client instead of Retrofit

The API has eleven endpoints and one response envelope. Retrofit would add two
dependencies and a code-generation step to save a few lines, while making the
envelope unwrapping and error mapping harder to see. `ApiClient` is ~250 lines
on OkHttp and turns every failure — HTTP, transport, TLS, application — into a
typed `ApiError` before it crosses the repository boundary. No exception
escapes into a ViewModel.

### Manual dependency injection

`AppContainer` wires about ten objects. Hilt would add annotation processing to
the build for that. The explicit container also lets the keyboard service and
the floating service reach the same singletons as the Compose UI without any
framework involvement — both call `HaryanviApp.container()`.

### Compose for the app, Views for the keyboard and bubble

Compose is the right tool for the app's fourteen screens. It is the wrong tool
inside an `InputMethodService` and a window-manager overlay, where the
lifecycle is not an Activity's and the surface is attached directly to the
window. The keyboard builds its rows programmatically from the data in
`KeyboardLayouts`, so layouts can be swapped without inflating anything, and
the bubble's panel is a single XML file. Both read their colours from
`res/values/colors.xml`, which mirrors the Compose palette, so the whole
product looks like one thing.

### Server-side quotas, always

The app shows a remaining-translations counter, and that number is purely
cosmetic. `QuotaService` re-checks the daily and monthly allowance before every
provider call. A patched client gains nothing. Counters live in a
pre-aggregated `usage_daily` table so the check is one indexed row lookup
rather than a scan.

### Provider abstraction at the smallest possible surface

`AiProvider` has one method: given a system prompt and a user prompt, return
text and token counts. Everything vendor-specific — endpoint shape, auth
header, response structure, error semantics — lives in a driver. Adding a
vendor means one class and one line in `ProviderFactory`; nothing in the
translation service, the controllers or the schema changes.

Retry versus fallback is decided by `ProviderException.retryable`: a timeout or
a 5xx is retried against the same provider with exponential backoff, while a
bad key or an unknown model falls straight through to the next provider in the
chain. Retrying a wrong API key is just a slower failure.

### The prompt is the product

Translation quality lives almost entirely in `PromptBuilder`, and the material
it assembles is **data in the database**, not strings in code:

- `dialects.prompt_notes` — linguistic guidance per region, written as
  instructions to a translator.
- `tone_presets.prompt_instruction` — the conversational register.
- `dialect_vocabulary` — a curated lexicon, with the entries whose source term
  appears in the user's text promoted to the front.

An operator improves translations by editing rows in the dashboard, with no
deployment. A user's correction can be promoted into the lexicon in one click
from the Translation quality queue, which closes the loop from complaint to
improvement.

The lexicon is framed to the model as *evidence of how the region speaks*, not
as a substitution table — the prompt says so explicitly, because a
find-and-replace table is exactly the failure mode the project is trying to
avoid.

### Masking beats instructing, for opaque tokens

Asking a model to "preserve the URL exactly" works most of the time. Most of
the time is not good enough for a payment link or an OTP. `TextGuard` replaces
URLs, emails, handles, hashtags, phone numbers and long digit runs with `{{n}}`
placeholders before the provider sees them, then restores the originals. A
dropped placeholder is detected and logged.

Emoji and short numbers are handled the other way — listed as explicit prompt
constraints and left inline — because masking them would damage the sentence
rhythm the model needs in order to place them naturally.

### Language detection on the server, not from the model

`LanguageDetector` is deterministic, unit-tested and free. The model's own
`detected_language` is read, compared and logged when it disagrees, but the
server's answer is what the API returns. The distinction that matters is
Hinglish versus English — "kal milte hai" needs Hindi handling and "see you
tomorrow" does not, and both are plain Latin text.

### Token storage

The bearer token is wrapped with AES-256-GCM using a key generated inside the
Android Keystore; only the ciphertext reaches SharedPreferences. Implemented
against the platform directly rather than with `androidx.security-crypto`,
which is ~80 lines and avoids an alpha dependency in the critical path. The key
does not require user authentication, deliberately: the keyboard must be able
to translate without a biometric prompt interrupting typing.

If the key is ever invalidated — the user removes their device lock, for
instance — decryption fails, the stored token is discarded and the user is
asked to sign in again. Failing closed is the correct behaviour.

### History is local-first

Reads come from Room, so the list opens instantly and works offline. Writes go
to both Room and the server. Cloud sync is opt-in; with it off, a translation
never leaves the device beyond the translation request itself. Favourite
toggles are optimistic and roll back if the server rejects them, so the star
never lies about the stored state.

---

## Request path: one translation

1. **Keyboard** — the user presses हरियाणवी. The service checks
   `SensitiveFieldDetector`; in a password field the key is already disabled.
2. It reads the field once via `getTextBeforeCursor` + `getTextAfterCursor`.
   This is the only moment any text is captured.
3. `TranslationRepository` adds the stored dialect, tone, script and the
   `save_history` flag, and posts to `/api/v1/translate`.
4. **Middleware** — CORS, HTTPS enforcement, maintenance check, bearer-token
   auth (blocked accounts rejected here), then the per-minute and per-hour
   throttle keyed to the account.
5. **Controller** — validates length against `max_input_chars`, then
   `QuotaService` checks the daily and monthly allowance.
6. **TranslationService** — `TextGuard` masks opaque spans;
   `DialectRepository` selects relevant lexicon rows; `PromptBuilder` assembles
   the system and user prompts.
7. It walks the provider chain. Transient failures retry with backoff;
   permanent ones move to the next provider.
8. The reply is parsed defensively — fenced JSON, bare JSON, or plain text,
   with a refusal detector so an apology is never passed off as Haryanvi.
9. Placeholders are restored and verified.
10. Usage is recorded (metadata only, never the text) and `usage_daily` is
    advanced. History is written **only** if the account allows it.
11. The keyboard replaces the field contents in a single batch edit and keeps
    one in-memory undo copy.
12. Leaving the field drops the snapshot and the undo copy.

---

## Database notes

16 InnoDB tables, `utf8mb4_unicode_ci` throughout — chosen over MySQL 8's
`utf8mb4_0900_ai_ci` so the same file loads on MariaDB-based hosting.

- **No plaintext secrets.** Passwords are Argon2id (bcrypt where unavailable);
  bearer tokens are SHA-256 digests; provider API keys are AES-256-GCM
  envelopes whose master key lives only in `config/config.php`.
- **`ai_usage_logs` stores no user text** — sizes, token counts, latency and
  error codes only. It is both the audit trail and the quota source of truth,
  which means quota accounting works even for users who have history disabled.
- **`usage_daily`** is the pre-aggregated counter, so quota checks are O(1).
- **`rate_limits`** uses a fixed window with an atomic upsert, and hashes the
  identity so raw IP addresses are not stored.
- **Public identifiers are UUIDs.** Integer primary keys are never exposed, so
  row counts cannot be inferred and identifiers cannot be enumerated.
- **Foreign keys everywhere**, with `ON DELETE CASCADE` for owned data and
  `SET NULL` where history should outlive a deleted parent.

---

## Threat model

What the design defends against, and how.

| Threat | Defence |
|---|---|
| Database dump | No usable credentials: hashed passwords, hashed tokens, encrypted provider keys |
| Stolen bearer token | Expiry, revocation on logout/password change/block, HTTPS only |
| Patched client raising its own limits | Every quota and rate limit re-checked server-side |
| Keyboard leaking a password | Sensitive fields refused before any transmission, failing closed on unknown input types |
| Prompt injection inside the user's message | Message fenced between markers, model instructed to treat it as data, instruction never to act on content |
| Link or OTP corrupted by translation | Masked before the provider sees it, restored and verified afterwards |
| Dashboard CSRF | Per-session token on every mutation, `SameSite=Strict` cookie |
| Dashboard brute force | Lockout after five failures, neutral error message, audit trail |
| Admin XSS via user-submitted text | Every value escaped on output, strict CSP, no inline scripts |
| SQL injection | PDO prepared statements with native (non-emulated) prepares; no interpolation of input into SQL |
| Secret committed to git | `config/config.php` git-ignored; CI fails if it appears or if a key-shaped string is found |
| Backup exfiltration of history | Backup rules are include-only; token and history database excluded |

Out of scope: a compromised server (the AI provider key is decryptable there by
design), a malicious AI provider (it sees the single message being translated),
and a rooted device with a live screen recorder.
