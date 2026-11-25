# Deployment

Installing Haryanvi AI on ordinary shared hosting — cPanel, aaPanel, Plesk,
DirectAdmin or anything else that gives you Apache, PHP and MySQL.

**Requirements**

| | Minimum |
|---|---|
| PHP | 8.2, with `pdo_mysql`, `openssl`, `curl`, `mbstring`, `json` |
| MySQL | 8.0 (MariaDB 10.6+ also works) |
| Web server | Apache with `mod_rewrite`, **or** nginx with the supplied config |
| SSL | A valid certificate — the app refuses plain HTTP |

No Composer, no Node.js, no shell access strictly required (though the CLI
tools make setup much easier).

> ### Read this if you are on nginx
>
> The backend ships `.htaccess` files. Apache reads them; **nginx ignores them
> completely.** On a default nginx site that means `/api/v1/*` returns 404 and
> `database/*.sql` plus `storage/logs/*.log` are publicly downloadable — the
> SQL includes the seeded administrator's password hash.
>
> aaPanel, BT Panel and most VPS images default to nginx. Use
> [`backend/deploy/nginx-aapanel-rewrite.conf`](../backend/deploy/nginx-aapanel-rewrite.conf)
> (panel) or
> [`nginx-vhost.conf.example`](../backend/deploy/nginx-vhost.conf.example)
> (plain nginx), and verify with the three `curl` checks in
> [step 9](#9-verify-the-protections-actually-work).

---

## Fastest path: aaPanel, cPanel or any panel with a terminal

If you have shell access, [`backend/deploy/install.sh`](../backend/deploy/install.sh)
does steps 1–4 below in one command.

```bash
# 1. In the panel: Website → Add site (domain + PHP 8.2+ + create database)
# 2. Then, in the panel's Terminal:
cd /tmp
curl -fsSLO https://raw.githubusercontent.com/dhdhdh51/Haryanvi-/main/backend/deploy/install.sh
bash install.sh \
  --domain haryanvi.yourdomain.com \
  --db-name haryanvi_ai --db-user haryanvi_user --db-pass 'generated-password'
# 3. Panel → URL Rewrite: paste deploy/nginx-aapanel-rewrite.conf   (nginx only)
# 4. Panel → SSL: Let's Encrypt → Apply → Force HTTPS
```

Details and options: [`backend/deploy/README.md`](../backend/deploy/README.md).
The manual walkthrough continues below.

---

## 1. Create the database

In your hosting panel create a database and a user, and grant that user full
rights on it. Note the four values: host, database name, username, password.

Load the schema and the reference data:

```bash
mysql -u DBUSER -p DBNAME < backend/database/schema.sql
mysql -u DBUSER -p DBNAME < backend/database/seed.sql
```

No shell access? Use phpMyAdmin → **Import**, running `schema.sql` first and
then `seed.sql`.

This creates 16 tables and loads 5 dialect profiles, 6 tone presets, about 130
vocabulary entries and the default application settings.

---

## 2. Upload the backend

Upload the **contents of `backend/`** — not the folder itself — into your web
root, usually `public_html`:

```
public_html/
├── index.php          ← API front controller
├── .htaccess
├── admin/
├── config/
├── database/
├── src/
├── storage/
└── bin/
```

**On Apache**, the bundled `.htaccess` files already deny web access to
`config/`, `src/`, `database/`, `storage/`, `bin/` and `tests/`.

**On nginx**, they do nothing. Install the supplied rules now:

- *Panel-managed site* — paste `deploy/nginx-aapanel-rewrite.conf` into
  Website → *your site* → **URL Rewrite**.
- *Plain nginx* — use `deploy/nginx-vhost.conf.example` as the server block.

Either way, delete the test suite from a live site — it is not needed and its
fixtures are not meant to be web-reachable:

```bash
rm -rf tests
```

Verify before continuing ([step 9](#9-verify-the-protections-actually-work)
has the full check):

```bash
curl -o /dev/null -s -w '%{http_code}\n' https://your-domain.com/database/schema.sql
```

A `200` here means your sensitive files are public. Stop and fix the web-server
configuration first.

Make the log directory writable:

```bash
chmod 750 storage storage/logs
```

### Installing in a subdirectory

Works without changes. Uploading into `public_html/haryanvi/` gives you
`https://your-domain.com/haryanvi/api/v1/...`; the router strips the prefix
automatically.

---

## 3. Configure

```bash
cd public_html
cp config/config.example.php config/config.php
php bin/generate-key.php
```

Paste the generated key into `config/config.php` and fill in the database
credentials:

```php
'db' => [
    'host' => 'localhost',
    'name' => 'yourdb',
    'user' => 'youruser',
    'pass' => 'yourpassword',
],
'security' => [
    'encryption_key' => 'base64:…',   // from generate-key.php
    'force_https'    => true,
],
'app' => [
    'url'   => 'https://your-domain.com',
    'debug' => false,                 // keep false in production
],
```

```bash
chmod 600 config/config.php
```

No shell access? Generate the key in phpMyAdmin's SQL tab:

```sql
SELECT CONCAT('base64:', TO_BASE64(RANDOM_BYTES(32)));
```

> The encryption key protects your AI provider credentials. Changing it later
> makes stored keys unreadable — you would re-enter them in the dashboard.

Verify the installation:

```bash
curl https://your-domain.com/api/v1/health
```

```json
{"success":true,"data":{"status":"ok","database":"connected",
 "ai_provider":"not_configured","encryption_key":"present"}}
```

`ai_provider: not_configured` is expected at this point.

---

## 4. Create your administrator

```bash
php bin/create-admin.php --username=you --email=you@example.com --role=super_admin
```

Then sign in at `https://your-domain.com/admin/` and delete the seeded account:

```sql
DELETE FROM admin_users WHERE username = 'admin';
```

No shell access? Sign in with the seeded account instead — username `admin`,
password `ChangeMe!2026`. The dashboard forces a password change before
allowing anything else.

### Optional: a second lock on the dashboard

Uncomment the basic-auth block in `admin/.htaccess` and create the password
file **outside** the web root:

```bash
htpasswd -c /home/youruser/.htpasswd-admin youradminname
```

---

## 5. Add an AI provider

Translation returns `AI_PROVIDER_NOT_CONFIGURED` until this is done. That is
deliberate: the service never invents a translation.

**In the dashboard:** AI configuration → Add provider.

**From the shell:**

```bash
php bin/provider.php add \
  --name="OpenAI" \
  --driver=openai_compatible \
  --base-url=https://api.openai.com/v1 \
  --model=gpt-4o-mini \
  --key=sk-...

php bin/provider.php test --id=1
php bin/provider.php list
```

| Driver | Base URL | Example model |
|---|---|---|
| `openai_compatible` | `https://api.openai.com/v1` | `gpt-4o-mini` |
| `anthropic` | `https://api.anthropic.com` | `claude-sonnet-4-5` |
| `gemini` | `https://generativelanguage.googleapis.com` | `gemini-2.0-flash` |

`openai_compatible` also covers Groq, OpenRouter, Together, DeepSeek, Mistral,
vLLM and Ollama's OpenAI bridge — change the base URL and model only.

**Suggested settings:** temperature `0.3` (faithful translations), max tokens
`1024`, timeout `30`s, retries `2`.

### Fallback chain

Add a second provider with a higher `priority` number. The default is tried
first; a permanent failure (bad key, unknown model) falls straight through to
the next, while transient failures are retried first.

```bash
php bin/provider.php add --name="Groq backup" --driver=openai_compatible \
  --base-url=https://api.groq.com/openai/v1 --model=llama-3.3-70b-versatile \
  --key=gsk_... --priority=200
```

---

## 6. Tune the limits

Dashboard → **Settings**:

| Setting | Default | Notes |
|---|---|---|
| Free daily translations | 50 | `0` = unlimited |
| Free monthly translations | 800 | |
| Max characters per request | 1200 | Directly affects provider cost |
| Requests / minute | 20 | Abuse protection |
| Allow new registrations | on | Turn off for a private instance |
| Maintenance mode | off | Returns 503 except for `/settings` |

Per-user overrides live under **Users → Limits**.

---

## 7. Build the Android app

### Debug APK

```bash
cd android
./gradlew assembleDebug -PapiBaseUrl=https://your-domain.com/
```

Output: `android/app/build/outputs/apk/debug/app-debug.apk`

The server address is also editable on the app's sign-in screen, so one build
works against any installation.

### Signed release APK

Create a keystore once, and keep it safe — losing it means you can never
update the app on Play:

```bash
keytool -genkey -v -keystore release.jks -keyalg RSA -keysize 2048 \
        -validity 10000 -alias haryanvi
```

**Locally**, create `android/keystore.properties` (already git-ignored):

```properties
storeFile=/absolute/path/to/release.jks
storePassword=…
keyAlias=haryanvi
keyPassword=…
```

```bash
./gradlew assembleRelease -PapiBaseUrl=https://your-domain.com/
```

**In GitHub Actions**, add these repository secrets:

| Secret | Value |
|---|---|
| `KEYSTORE_BASE64` | `base64 -w0 release.jks` |
| `KEYSTORE_PASSWORD` | keystore password |
| `KEY_ALIAS` | key alias |
| `KEY_PASSWORD` | key password |

And optionally the repository **variable** `API_BASE_URL` set to
`https://your-domain.com/`.

Push to `main` and download the APK from the workflow run's artifacts. Without
the secrets the workflow still succeeds and publishes an unsigned APK.

Verify any signed build:

```bash
$ANDROID_HOME/build-tools/35.0.0/apksigner verify --verbose app-release.apk
```

---

## 8. Housekeeping

The project works without cron. If you have a scheduler, these keep the tables
small:

```bash
# Weekly: expired tokens and stale rate-limit windows
php -r 'require "src/bootstrap.php";
  (new Haryanvi\Repositories\TokenRepository())->prune();
  Haryanvi\Core\RateLimiter::prune();'

# Monthly: usage logs older than 180 days, audit log older than a year
php -r 'require "src/bootstrap.php";
  (new Haryanvi\Repositories\UsageRepository())->pruneLogs(180);
  (new Haryanvi\Admin\AdminRepository())->pruneAuditTrail(365);'
```

Also take regular database backups — your dialect vocabulary and approved
corrections are the irreplaceable part.

---

## Troubleshooting

**`CONFIG_MISSING`**
`config/config.php` does not exist. Copy it from `config.example.php`.

**`DATABASE_UNAVAILABLE`**
Credentials are wrong, or the host requires `127.0.0.1` instead of
`localhost`. The real driver message is in `storage/logs/` — it is kept out of
the API response because it can contain credentials.

**`/api/v1/health` returns 404**
`mod_rewrite` is off or `.htaccess` is ignored. Enable `AllowOverride All` for
the directory, or ask your host to enable rewrites.

**Everything returns `UNAUTHORIZED` even with a correct token**
Your server is stripping the `Authorization` header — common on CGI/FastCGI.
The bundled `.htaccess` has two workarounds; if neither applies, ask your host
to pass `HTTP_AUTHORIZATION` through.

**`HTTPS_REQUIRED` behind a proxy or load balancer**
The proxy must forward `X-Forwarded-Proto: https`. For local development only,
set `'force_https' => false`.

**`AI_PROVIDER_NOT_CONFIGURED`**
No active provider with a stored key. Check `php bin/provider.php list`, and
confirm `encryption_key` is set in `config.php`.

**`AI_PROVIDER_FAILED`**
Run `php bin/provider.php test --id=N` for the real cause. Common ones: an
expired key, a model name that does not exist on that account, outbound HTTPS
blocked by the host, or a provider quota that has run out. The dashboard shows
the last error per provider.

**The keyboard does not appear**
Android requires two user actions and no app can do them for you: enable the
keyboard in system input-method settings, then select it while typing. The
app's Settings → Haryanvi Keyboard screen links to both.

**The translate key is greyed out**
You are in a password, PIN, numeric, email or URL field, where translation is
blocked by design.

**The floating bubble does not start**
The overlay permission is missing or was revoked. Settings → Floating
translator explains and requests it.

**Devanagari shows as boxes**
The device lacks a Devanagari font. Rare on modern Android; switch the output
script to Roman as a workaround.

---

## 9. Verify the protections actually work

Run this against the live site. Every line must show **403** or **404** —
never 200.

```bash
D=your-domain.com

for path in \
  database/schema.sql \
  database/seed.sql \
  config/config.php \
  config/config.example.php \
  src/bootstrap.php \
  storage/logs/ \
  bin/provider.php \
  admin/views/layout.php
do
  printf '%s  %s\n' "$(curl -o /dev/null -s -w '%{http_code}' "https://$D/$path")" "$path"
done
```

Then confirm the API answers:

```bash
curl "https://$D/api/v1/health"
```

`schema.sql` returning 200 is the one to care about most — it contains the
seeded administrator's password hash. If you see it, your rewrite rules are not
loaded.

---

## Security checklist

- [ ] The checks in step 9 all return 403 or 404
- [ ] `config/config.php` is `chmod 600` and absent from version control
- [ ] `encryption_key` is set, and unique to this installation
- [ ] `app.debug` is `false`
- [ ] `security.force_https` is `true` and SSL is valid
- [ ] `tests/` has been removed from the live site
- [ ] The seeded `admin` account is deleted or has a rotated password
- [ ] `cors_allowed_origins` is empty unless you ship a web client
- [ ] Database backups are running
- [ ] On nginx: the rewrite rules are installed and saved
