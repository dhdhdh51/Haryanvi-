# Haryanvi AI — Translator

Turn Hindi, English or Hinglish into natural, regionally accurate **Haryanvi**
before you send it — from the app, from a custom keyboard inside any chat app,
from a floating bubble, or from the Android share sheet.

```
  Hindi / English / Hinglish  ──►  Haryanvi AI  ──►  WhatsApp · Instagram
                                                     Telegram · Messenger
```

---

## What this is

| Part | Technology | Location |
|---|---|---|
| Android app | Kotlin, Jetpack Compose, Material 3, minSdk 26 | [`android/`](android/) |
| Custom keyboard | `InputMethodService` | [`android/app/src/main/java/com/haryanvi/translator/keyboard/`](android/app/src/main/java/com/haryanvi/translator/keyboard/) |
| Floating translator | Overlay + foreground service | [`android/app/src/main/java/com/haryanvi/translator/floating/`](android/app/src/main/java/com/haryanvi/translator/floating/) |
| REST API | PHP 8.2+, PDO, no Composer | [`backend/`](backend/) |
| Database | MySQL 8+ (also runs on MariaDB 10.6+) | [`backend/database/`](backend/database/) |
| Admin dashboard | Server-rendered PHP, no build step | [`backend/admin/`](backend/admin/) |
| CI | GitHub Actions | [`.github/workflows/`](.github/workflows/) |

Everything runs on ordinary shared hosting: Apache, PHP and MySQL. No VPS, no
Node.js, no Composer, no container runtime.

---

## The translation engine

Translation is **not** word substitution. Each request builds a structured
prompt containing the source text, the detected language, the chosen regional
dialect, the requested tone, the output script, a curated reference lexicon for
that region, and an explicit set of rules.

**Five regional profiles** ship with the project, each with linguistic guidance
written for the model rather than for a reader:

| Dialect | Region | Character |
|---|---|---|
| `general` | All Haryana | Neutral, widely understood. The default. |
| `rohtak_jhajjar` | Rohtak, Jhajjar, Bahadurgarh | Core Deswali — `सै`/`सूं`, `कोन्या`, `बेरा`, `घणा` |
| `hisar_bhiwani` | Hisar, Bhiwani, Fatehabad | Western, Bagri transition — `म्हैं`, `थारा`, `कठै` |
| `sonipat_panipat` | Sonipat, Panipat, Samalkha | Softer, Khari Boli-adjacent |
| `jind_kaithal` | Jind, Kaithal, Narwana | Northern cadence, Haryanvi lexicon throughout |

**Six tones**: natural, modern youth, traditional village, respectful,
friendly, funny.

**Rules the prompt enforces** — translate meaning rather than words; never
sound like a parody; never borrow Punjabi, Rajasthani or Bhojpuri vocabulary;
preserve names, numbers, dates, links, handles and emoji exactly; keep modern
loanwords people genuinely use; match person, gender, number and formality.

Links, emails, `@handles`, `#hashtags`, phone numbers and OTPs are **masked
before the model sees them** and restored afterwards, so they cannot be
"translated" at all. See
[`TextGuard`](backend/src/Services/TextGuard.php).

If no AI provider is configured the API returns a clear
`AI_PROVIDER_NOT_CONFIGURED` error. It never returns an invented translation.

### Provider independence

Credentials stay on your server, encrypted with AES-256-GCM. Three drivers
ship, selected per provider in the dashboard:

| Driver | Works with |
|---|---|
| `openai_compatible` | OpenAI, Groq, OpenRouter, Together, DeepSeek, Mistral, vLLM, Ollama's OpenAI bridge |
| `anthropic` | Claude |
| `gemini` | Google Gemini |

Providers form a **fallback chain**: transient errors are retried with
exponential backoff, permanent ones (bad key, unknown model) fall straight
through to the next provider.

---

## Privacy, in concrete terms

These are enforced in code, not just stated:

- **The keyboard cannot transmit from a private field.** Password, PIN,
  numeric, phone, email, URL and postal-address fields are refused, along with
  any field flagged `NO_SUGGESTIONS` or `IME_FLAG_NO_PERSONALIZED_LEARNING`.
  An unrecognised input type fails closed.
  See [`SensitiveFieldDetector`](android/app/src/main/java/com/haryanvi/translator/keyboard/SensitiveFieldDetector.kt)
  and its [20 tests](android/app/src/test/java/com/haryanvi/translator/keyboard/SensitiveFieldDetectorTest.kt).
- **No keystroke logging.** The keyboard holds no buffer and no history. The
  only text it ever keeps is one snapshot taken when you press the translate
  key, plus one undo copy — both dropped when you leave the field.
- **Nothing is sent until you press हरियाणवी.** Typing alone never touches the
  network.
- **The clipboard is read only on an explicit tap.** Never on a timer, never at
  launch.
- **No chat reading, no automatic sending.** The floating bubble is an overlay;
  overlays cannot read the apps beneath them. Nothing is ever sent on your
  behalf.
- **History is opt-out and destructive.** Turning it off deletes the stored
  translations on the device *and* on the server, immediately.
- **Account deletion is real.** Translations, tokens and settings are removed.
- **Backups exclude your data.** The auth token and the history database are
  outside the backup rules; only UI preferences travel.
- **Logs never contain user text** — only sizes, durations and error codes.

Permissions requested: `INTERNET`, `ACCESS_NETWORK_STATE`,
`SYSTEM_ALERT_WINDOW` (optional, for the bubble), `FOREGROUND_SERVICE`,
`POST_NOTIFICATIONS`, `VIBRATE`. Nothing else — no contacts, storage,
location, microphone, accessibility service or `QUERY_ALL_PACKAGES`.

---

## Quick start

### 1. Database

```bash
mysql -u youruser -p yourdb < backend/database/schema.sql
mysql -u youruser -p yourdb < backend/database/seed.sql
```

### 2. Backend

Upload the **contents** of `backend/` into your web root (`public_html`), then:

```bash
cp config/config.example.php config/config.php
php bin/generate-key.php      # paste the key into config.php
chmod 600 config/config.php
```

Fill in your database credentials in `config/config.php`. Verify:

```bash
curl https://your-domain.com/api/v1/health
```

### 3. Administrator

```bash
php bin/create-admin.php --username=you --role=super_admin
```

Sign in at `https://your-domain.com/admin/`.

> The seeded `admin` account (password `ChangeMe!2026`) must change its
> password at first login and should be deleted once you have your own.

### 4. AI provider

In the dashboard: **AI configuration → Add provider**, or from the shell:

```bash
php bin/provider.php add \
  --name="OpenAI" --driver=openai_compatible \
  --base-url=https://api.openai.com/v1 --model=gpt-4o-mini \
  --key=sk-...

php bin/provider.php test --id=1
```

### 5. Android app

```bash
cd android
./gradlew assembleDebug -PapiBaseUrl=https://your-domain.com/
```

The APK lands in `android/app/build/outputs/apk/debug/`. The server address is
also editable in the app's sign-in screen, so one build works against any
installation.

Full instructions: **[docs/DEPLOYMENT.md](docs/DEPLOYMENT.md)**

---

## Using the app

1. **Sign in** — enter your server address, then register or sign in.
2. **Pick your region and tone** once; they are remembered.
3. **Translate** — type Hindi or English, press Translate, then copy or share.
4. **Keyboard** — Settings → Haryanvi Keyboard walks through the two Android
   steps. Then, in any chat app, type normally and press **हरियाणवी** to
   replace your text with the Haryanvi version in place.
5. **Floating bubble** — Settings → Floating translator explains the overlay
   permission before asking for it.
6. **Share sheet** — select text anywhere, Share → *Translate to Haryanvi*.

---

## Documentation

| Document | Contents |
|---|---|
| [docs/API.md](docs/API.md) | Every endpoint, request and response shape, error codes |
| [docs/DEPLOYMENT.md](docs/DEPLOYMENT.md) | Shared-hosting install, SSL, signing, troubleshooting |
| [docs/TESTING.md](docs/TESTING.md) | Test report: what was run, what was found, what is untested |
| [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md) | How the pieces fit together and why |

---

## Development

```bash
# Backend: unit tests (no database needed)
php backend/tests/run.php

# Backend: full suite against a running instance
php -S 127.0.0.1:8080 -t backend backend/tests/support/dev-router.php
HARYANVI_TEST_URL=http://127.0.0.1:8080 php backend/tests/run.php --all

# Android
cd android
./gradlew testDebugUnitTest lintDebug assembleDebug
```

CI runs all of the above on every push:
[Android build](.github/workflows/android-build.yml) ·
[Backend checks](.github/workflows/backend-checks.yml)

---

## Project layout

```
android/                      Android Studio project
  app/src/main/java/com/haryanvi/translator/
    data/        remote (API client, DTOs), local (Room, DataStore, Keystore)
    domain/      models
    ui/          Compose screens, view models, theme
    keyboard/    InputMethodService, layouts, sensitive-field detection
    floating/    overlay bubble + foreground service
    share/       share-to-translate entry point
  app/src/test/  JVM unit tests

backend/
  index.php                   API front controller
  src/Core/                   config, PDO, router, validation, crypto, logging
  src/Providers/              AI drivers + fallback chain
  src/Services/               translation engine, prompts, TextGuard, auth
  src/Repositories/           data access
  src/Http/                   controllers + middleware
  src/Admin/                  dashboard session, views, dashboard data
  admin/                      dashboard entry point, templates, stylesheet
  database/                   schema.sql, seed.sql, migrations/
  bin/                        migrate, generate-key, create-admin, provider
  tests/                      unit + integration suites, mock AI provider

docs/                         API, deployment, testing, architecture
```

---

## Contributing to the dialect data

The most valuable contribution is **vocabulary**. Each entry is a source term,
its Haryanvi equivalent, a usage note and an example sentence; the engine
prefers entries whose source term appears in the user's text.

Add them in the dashboard under **Regional vocabulary**, or approve a user's
report under **Translation quality** — approving with a suggested wording
promotes it into the lexicon in one step.

---

## Licence

See [LICENSE](LICENSE).
