# Haryanvi AI — REST API

Base URL: `https://your-domain.com/api/v1`

- HTTPS only. Plain HTTP is rejected with `HTTPS_REQUIRED` unless
  `security.force_https` is disabled for local development.
- Request and response bodies are JSON (`application/json; charset=utf-8`).
- Request bodies are capped at `security.max_body_bytes` (64 KB by default).
- All timestamps are UTC, formatted `YYYY-MM-DD HH:MM:SS` or ISO-8601 where noted.

---

## Response envelope

Every response uses the same two shapes, so a client needs exactly one parser.

**Success**

```json
{
  "success": true,
  "data": { },
  "meta": { }
}
```

`meta` is present only where it carries something useful (pagination, usage,
latency).

**Failure**

```json
{
  "success": false,
  "error": {
    "code": "VALIDATION_FAILED",
    "message": "Please correct the highlighted fields.",
    "details": { "fields": { "email": "Enter a valid email address." } }
  }
}
```

`code` is stable and machine-readable; branch on it rather than on `message`.

---

## Authentication

Obtain a token from `/auth/register` or `/auth/login`, then send it on every
protected request:

```
Authorization: Bearer <token>
```

Tokens are opaque, expire after `token_ttl_days` (90 by default), and are
stored on the server only as SHA-256 hashes. A token is invalidated by logout,
by a password change, by blocking the account, or by account deletion.

---

## Endpoints

| Method | Path | Auth | Purpose |
|---|---|:--:|---|
| GET | `/health` | – | Readiness and configuration probe |
| GET | `/dialects` | – | Dialects, tones, language and script options |
| GET | `/settings` | optional | App configuration; adds the caller's preferences when a token is sent |
| PATCH | `/settings` | ✓ | Update the caller's preferences |
| POST | `/auth/register` | – | Create an account |
| POST | `/auth/login` | – | Sign in |
| POST | `/auth/logout` | ✓ | Revoke this token, or all of them |
| POST | `/auth/password` | ✓ | Change password (revokes every token) |
| POST | `/auth/delete-account` | ✓ | Permanently delete the account |
| POST | `/translate` | ✓ | Translate text into Haryanvi |
| GET | `/history` | ✓ | List, search and filter saved translations |
| POST | `/history` | ✓ | Upload an entry (offline queue / cloud sync) |
| PATCH | `/history/{id}` | ✓ | Toggle favourite |
| DELETE | `/history/{id}` | ✓ | Delete one entry |
| DELETE | `/history` | ✓ | Delete every entry |
| POST | `/feedback` | ✓ | Report a bad translation |
| GET | `/usage` | ✓ | Quota and usage counters |

---

### GET /health

Unauthenticated. Reports whether the installation is ready, without revealing
versions, paths or credentials.

```json
{
  "success": true,
  "data": {
    "status": "ok",
    "database": "connected",
    "ai_provider": "configured",
    "encryption_key": "present",
    "password_algorithm": "argon2id",
    "api_version": "v1",
    "server_time": "2026-11-25T10:21:00Z"
  }
}
```

`ai_provider` is `not_configured` until a provider is added and activated —
that is the first thing to check when `/translate` returns 503.

---

### GET /dialects

Unauthenticated, so the app can populate its pickers before sign-in.

```json
{
  "success": true,
  "data": {
    "dialects": [
      {
        "code": "rohtak_jhajjar",
        "name_en": "Rohtak & Jhajjar",
        "name_hi": "रोहतक व झज्जर",
        "region_label": "Rohtak, Jhajjar, Bahadurgarh belt",
        "description": "Core Deswali/Bangru Haryanvi.",
        "is_default": false
      }
    ],
    "tones": [
      { "code": "natural", "name_en": "Natural Conversational", "name_hi": "सहज बातचीत", "is_default": true }
    ],
    "target_language": { "code": "bgc", "name_en": "Haryanvi", "name_hi": "हरियाणवी" },
    "source_languages": [ { "code": "auto", "name_en": "Auto detect" } ],
    "output_scripts": [ { "code": "devanagari" }, { "code": "roman" } ]
  }
}
```

The internal `prompt_notes` field is deliberately not exposed.

---

### POST /auth/register

```json
{
  "email": "ravi@example.com",
  "password": "a decent passphrase",
  "display_name": "Ravi",
  "device": "Pixel 7"
}
```

`201 Created`:

```json
{
  "success": true,
  "data": {
    "token": "y3Xv...",
    "expires_at": "2027-02-23 10:21:00",
    "user": { "id": "4f1c...", "email": "ravi@example.com", "display_name": "Ravi", "status": "active" }
  }
}
```

Passwords must be at least 8 characters and not all digits. `user.id` is a
UUID; the database's integer primary key is never exposed.

Errors: `VALIDATION_FAILED` (422), `EMAIL_ALREADY_REGISTERED` (409),
`FORBIDDEN` (403, registration closed), `RATE_LIMITED` (429).

---

### POST /auth/login

```json
{ "email": "ravi@example.com", "password": "a decent passphrase", "device": "Pixel 7" }
```

Returns the same payload as register. A wrong password and an unknown email
produce an identical 401 response, and both take the same time to answer.

Errors: `UNAUTHORIZED` (401), `FORBIDDEN` (403, blocked account),
`RATE_LIMITED` (429, default 10 attempts per hour per IP).

---

### POST /auth/logout

```json
{ "all_devices": false }
```

---

### POST /auth/password

```json
{ "current_password": "old passphrase", "new_password": "new passphrase" }
```

On success **every** token for the account is revoked, including the one used
to make the call. The client must sign in again.

---

### POST /auth/delete-account

```json
{ "password": "your passphrase", "confirm": "DELETE" }
```

`confirm` must be exactly `DELETE`. Translations, tokens and settings are
removed; the account row is anonymised so platform-level usage statistics stay
consistent. Irreversible.

---

### POST /translate

The only endpoint that calls an AI provider.

```json
{
  "text": "मुझे नहीं पता कि वो कहाँ है",
  "source_lang": "auto",
  "dialect": "rohtak_jhajjar",
  "tone": "friendly",
  "output_script": "devanagari",
  "channel": "keyboard",
  "save_history": true
}
```

| Field | Required | Values |
|---|:--:|---|
| `text` | ✓ | 1 … `max_input_chars` (1200 by default) |
| `source_lang` | | `auto` (default), `hi`, `en` |
| `dialect` | | any active dialect code; defaults to the user's preference |
| `tone` | | any active tone code; defaults to the user's preference |
| `output_script` | | `devanagari` (default), `roman` |
| `channel` | | `app` (default), `keyboard`, `floating`, `share`, `api` |
| `save_history` | | `false` opts this request out of history |

`save_history: true` cannot override an account that has history switched off —
the account-level setting always wins.

**200 OK**

```json
{
  "success": true,
  "data": {
    "translated_text": "मन्ने बेरा ना के वो कित सै",
    "roman_text": "manne bera na ke wo kit sai",
    "primary_text": "मन्ने बेरा ना के वो कित सै",
    "source_text": "मुझे नहीं पता कि वो कहाँ है",
    "detected_lang": "hi",
    "output_script": "devanagari",
    "dialect": { "code": "rohtak_jhajjar", "name_en": "Rohtak & Jhajjar" },
    "tone": { "code": "friendly", "name_en": "Friendly" },
    "history_id": "9c2f1e04-7a3b-4c5d-8e9f-0a1b2c3d4e5f",
    "saved_to_history": true
  },
  "meta": {
    "latency_ms": 820,
    "usage": {
      "daily":   { "used": 7, "limit": 50, "remaining": 43, "unlimited": false },
      "monthly": { "used": 7, "limit": 800, "remaining": 793, "unlimited": false }
    }
  }
}
```

`primary_text` already honours `output_script`; display it directly.
`roman_text` is null unless Roman output was requested or enabled.

**Errors**

| Code | Status | Meaning |
|---|:--:|---|
| `VALIDATION_FAILED` | 422 | Empty text, or longer than `max_input_chars` |
| `UNAUTHORIZED` | 401 | Missing, expired or revoked token |
| `RATE_LIMITED` | 429 | Per-minute or per-hour cap; `details.retry_after` in seconds |
| `QUOTA_EXCEEDED` | 429 | Daily or monthly allowance spent; `details.scope`, `limit`, `used` |
| `AI_PROVIDER_NOT_CONFIGURED` | 503 | No active provider with credentials |
| `AI_PROVIDER_FAILED` | 502 | Every provider in the chain failed |
| `MAINTENANCE_MODE` | 503 | Operator has enabled maintenance |

A failed translation never counts against the quota, and never returns
invented text.

**Preserved content.** Links, emails, `@handles`, `#hashtags`, phone numbers
and runs of five or more digits are masked before the provider sees them and
restored afterwards, so they survive byte-for-byte. Plain numbers and emoji are
listed as explicit constraints in the prompt.

---

### GET /settings

Optional auth. Without a token you get `data.app` only; with one you also get
`data.user`, `data.preferences` and `data.stats`.

```json
{
  "success": true,
  "data": {
    "app": {
      "app_name": "Haryanvi AI",
      "maintenance_mode": false,
      "free_daily_limit": 50,
      "max_input_chars": 1200,
      "allow_registration": true,
      "privacy_policy": "…",
      "terms_conditions": "…",
      "min_app_version": 1
    },
    "preferences": {
      "dialect": "rohtak_jhajjar",
      "tone": "friendly",
      "output_script": "devanagari",
      "include_roman": false,
      "default_source_lang": "auto",
      "save_history": true,
      "cloud_sync": false,
      "theme": "dark"
    },
    "stats": { "history_count": 42 }
  }
}
```

Only settings flagged public in the database appear under `app`; rate limits
and CORS configuration are never exposed.

---

### PATCH /settings

Send only what changes.

```json
{ "dialect": "hisar_bhiwani", "tone": "respectful", "save_history": false }
```

Returns the complete updated preferences plus `history_cleared`, the number of
server-side entries deleted — turning history off deletes stored translations
immediately.

Errors: `VALIDATION_FAILED` (422) for an unknown dialect or tone code, or when
no recognised field was supplied.

---

### GET /history

Query parameters: `page` (default 1), `per_page` (1–100, default 25),
`q` (search both sides of the translation), `favourites=1`.

```json
{
  "success": true,
  "data": {
    "items": [
      {
        "id": "9c2f1e04-…",
        "source_text": "मुझे नहीं पता",
        "translated_text": "मन्ने बेरा ना",
        "roman_text": null,
        "dialect": "rohtak_jhajjar",
        "dialect_name": "Rohtak & Jhajjar",
        "tone": "friendly",
        "is_favourite": true,
        "channel": "keyboard",
        "created_at": "2026-11-25 10:21:00"
      }
    ]
  },
  "meta": { "page": 1, "per_page": 25, "total": 42, "total_pages": 2 }
}
```

Entries are addressed by UUID and every query is scoped to the caller, so
guessing an identifier returns 404 rather than another account's row.

---

### POST /history

Uploads an entry the client already has — used for offline queues and when
cloud sync is switched on. Does not translate, so it consumes no quota.

```json
{
  "source_text": "कल मिलते हैं",
  "translated_text": "काल मिलांगे",
  "roman_text": "kaal milaange",
  "channel": "keyboard",
  "dialect": "general",
  "tone": "natural"
}
```

Returns `403 FORBIDDEN` when the account has history disabled.

---

### PATCH /history/{id}

```json
{ "is_favourite": true }
```

### DELETE /history/{id}

### DELETE /history

```json
{ "success": true, "data": { "deleted": 42, "message": "Deleted 42 saved translations." } }
```

---

### POST /feedback

```json
{
  "reason": "wrong_dialect",
  "history_id": "9c2f1e04-…",
  "suggested_text": "मन्ने बेरा ना",
  "comment": "Rohtak speakers would say बेरा, not पता."
}
```

`reason` is one of `wrong_meaning`, `wrong_dialect`, `unnatural`,
`script_issue`, `offensive`, `other`.

Supply either `history_id`, or both `source_text` and `translated_text`.
Throttled to 20 reports per hour per account.

Approving a report in the dashboard can promote `suggested_text` directly into
the regional lexicon, where it shapes later translations for that dialect.

---

### GET /usage

```json
{
  "success": true,
  "data": {
    "account": { "id": "4f1c…", "email": "ravi@example.com", "display_name": "Ravi" },
    "usage": {
      "daily":   { "used": 7, "limit": 50, "remaining": 43, "unlimited": false },
      "monthly": { "used": 7, "limit": 800, "remaining": 793, "unlimited": false },
      "all_time": { "translations": 318 },
      "characters_this_month": 11420,
      "resets": { "daily_at": "2026-11-26T00:00:00Z", "monthly_at": "2026-12-01T00:00:00Z" }
    }
  }
}
```

Informational only. The authoritative check runs server-side on every
translate call, so a modified client cannot grant itself more translations.

---

## Error codes

| Code | Status | Notes |
|---|:--:|---|
| `VALIDATION_FAILED` | 422 | `details.fields` maps field → message |
| `MALFORMED_JSON` | 400 | Body was not a JSON object |
| `UNAUTHORIZED` | 401 | Sign in again |
| `FORBIDDEN` | 403 | Blocked account, closed registration, or history disabled |
| `NOT_FOUND` | 404 | Unknown endpoint or row |
| `METHOD_NOT_ALLOWED` | 405 | Path exists, method does not |
| `EMAIL_ALREADY_REGISTERED` | 409 | Offer sign-in instead |
| `PAYLOAD_TOO_LARGE` | 413 | Body over `max_body_bytes` |
| `RATE_LIMITED` | 429 | Honour `Retry-After` / `details.retry_after` |
| `QUOTA_EXCEEDED` | 429 | Allowance spent; see `details.scope` |
| `SERVER_ERROR` | 500 | Logged server-side; details withheld |
| `DATABASE_ERROR` / `DATABASE_UNAVAILABLE` | 500 / 503 | Database problem |
| `AI_PROVIDER_NOT_CONFIGURED` | 503 | Add a provider in the dashboard |
| `AI_PROVIDER_FAILED` | 502 | All providers failed; safe to retry |
| `MAINTENANCE_MODE` | 503 | `/settings` stays reachable to read the message |
| `HTTPS_REQUIRED` | 403 | Use HTTPS |
| `CONFIG_MISSING` | 500 | `config/config.php` is absent |

---

## Rate limits

| Scope | Default | Setting |
|---|---|---|
| Translate, per minute | 20 | `rate_limit_per_minute` |
| Translate, per hour | 300 | `rate_limit_per_hour` |
| Auth attempts, per hour per IP | 10 | `auth_attempts_per_hour` |
| Feedback, per hour per account | 20 | fixed |
| Daily translations | 50 | `free_daily_limit`, per-user override |
| Monthly translations | 800 | `free_monthly_limit`, per-user override |

A limit of `0` means unlimited. Throttle buckets are keyed by the account for
translate (so a shared mobile network is not penalised) and by IP for auth.

---

## CORS

No CORS headers are sent by default, which blocks every browser cross-origin
call. The Android client is unaffected — CORS is a browser mechanism. To ship a
web client, add its exact origins to `cors_allowed_origins` in the dashboard; a
wildcard is never emitted.

---

## Example session

```bash
BASE=https://your-domain.com/api/v1

curl -s "$BASE/health"

TOKEN=$(curl -s -X POST "$BASE/auth/login" \
  -H 'Content-Type: application/json' \
  -d '{"email":"ravi@example.com","password":"a decent passphrase"}' \
  | python3 -c 'import sys,json; print(json.load(sys.stdin)["data"]["token"])')

curl -s -X POST "$BASE/translate" \
  -H "Authorization: Bearer $TOKEN" \
  -H 'Content-Type: application/json' \
  -d '{"text":"मैं ठीक हूँ, तुम कैसे हो?","dialect":"rohtak_jhajjar","tone":"friendly"}'

curl -s "$BASE/usage" -H "Authorization: Bearer $TOKEN"
```
