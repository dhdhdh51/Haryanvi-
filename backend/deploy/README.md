# Deployment helpers

## Pick your web server first

The backend ships `.htaccess` files. **Apache reads them; nginx ignores them
entirely.** That difference is not cosmetic:

| | Apache | nginx without the config below |
|---|---|---|
| `/api/v1/*` | works | **404 — nothing rewrites to `index.php`** |
| `/database/schema.sql` | 403 | **downloadable** (includes the seeded admin's password hash) |
| `/storage/logs/*.log` | 403 | **downloadable** |
| `/config/config.php` | 403 | executed by PHP-FPM, so not dumped — but protected by luck, not configuration |

aaPanel, BT Panel and most modern VPS images default to **nginx**, so for most
installations the files in this directory are required, not optional.

---

## Files

| File | Use |
|---|---|
| `install.sh` | One-command deploy from git: files, database, config, verification |
| `nginx-aapanel-rewrite.conf` | Paste into aaPanel → Website → *site* → **URL Rewrite** |
| `nginx-vhost.conf.example` | Full `server { }` block for plain nginx (no panel) |

Apache users need none of this — the bundled `.htaccess` files already do the
job. Confirm with the three `curl` checks at the end of this page.

---

## aaPanel / BT Panel

**1. Create the site in the panel.** The script cannot do this, because the
panel has to generate the vhost, the PHP-FPM pool and the SSL certificate.

> Website → **Add site**
> Domain: `haryanvi.yourdomain.com`
> PHP version: **8.2** or newer
> Tick **Create database**, and note the name, user and password

If you are adding a subdomain, create the DNS record first: an **A record**
for `haryanvi` pointing at your server's IP.

**2. Run the installer.** Panel → **Terminal** (or SSH):

```bash
cd /tmp
curl -fsSLO https://raw.githubusercontent.com/dhdhdh51/Haryanvi-/main/backend/deploy/install.sh

bash install.sh \
  --domain haryanvi.yourdomain.com \
  --db-name  haryanvi_ai \
  --db-user  haryanvi_user \
  --db-pass  'the-password-the-panel-generated'
```

This does everything except SSL: checks prerequisites, clones the repository,
deploys `backend/` into the site root, loads `schema.sql` and `seed.sql`,
generates `config.php` with a fresh encryption key at `chmod 600`, strips
`tests/`, moves aside the panel's placeholder `index.html`, **installs the
nginx rewrite rules**, reloads nginx, and boots the app to confirm the database
is reachable.

Re-running is safe: an existing `config.php` is never overwritten, a database
that already has tables is left alone, and the previous deployment is tarred
into `/www/backup/` first.

If the panel did not create the database for you, add `--create-db`:

```bash
bash install.sh --domain haryanvi.yourdomain.com --create-db \
  --db-name haryanvi_ai --db-user haryanvi_user --db-pass 'choose-a-password'
```

That needs MySQL root — via `/root/.my.cnf`, or pass `--db-root-pass`. A
database created this way will not appear in the panel's Databases list, since
the panel only tracks what it created itself.

> **About the nginx step.** aaPanel's generated vhost includes
> `/www/server/panel/vhost/rewrite/<domain>.conf`, so the installer writes the
> rules straight there rather than asking you to paste them. Any existing file
> is saved as `.before-haryanvi`, the result is validated with `nginx -t`, and
> **if the test fails the old file is restored and nginx is never reloaded** —
> a bad config cannot take down your other sites. If validation fails the
> script says so, and you can paste the rules into the panel's URL Rewrite box
> instead.
>
> Pass `--no-nginx` to skip this entirely.

**3. Issue SSL.** Website → *your site* → **SSL** → Let's Encrypt → Apply,
then enable **Force HTTPS**. The API refuses plain HTTP, so this is required.

**4. Finish the setup:**

```bash
cd /www/wwwroot/haryanvi.yourdomain.com

php bin/create-admin.php --username=you --role=super_admin

php bin/provider.php add --name="OpenAI" --driver=openai_compatible \
  --base-url=https://api.openai.com/v1 --model=gpt-4o-mini --key=sk-...
php bin/provider.php test --id=1
```

aaPanel keeps PHP outside `PATH`; if `php` is not found use the full path, for
example `/www/server/php/82/bin/php`.

---

## Verify the protections actually work

Run these against the live site. **All three must return 403** (Apache) or
403/404 (nginx):

```bash
D=haryanvi.yourdomain.com

curl -o /dev/null -s -w '%{http_code}  database/schema.sql\n' "https://$D/database/schema.sql"
curl -o /dev/null -s -w '%{http_code}  src/bootstrap.php\n'   "https://$D/src/bootstrap.php"
curl -o /dev/null -s -w '%{http_code}  config/config.php\n'   "https://$D/config/config.php"
```

If `schema.sql` returns **200**, the rewrite rules are not loaded. Fix that
before going live: the file contains the seeded administrator's password hash.

Then confirm the API itself:

```bash
curl "https://$D/api/v1/health"
# {"success":true,"data":{"status":"ok","database":"connected", ...}}
```

`"ai_provider":"not_configured"` is expected until you add a provider.

---

## Updating later

```bash
bash install.sh --domain haryanvi.yourdomain.com --update
```

Pulls the latest `main`, redeploys the code, keeps `config.php`, the database
and the logs, and tars the previous deployment into `/www/backup/` first.

Apply any pending schema migrations separately:

```bash
php bin/migrate.php --status
php bin/migrate.php
```

---

## Installer options

| Option | Default | Purpose |
|---|---|---|
| `--domain` | *required* | Site domain; also sets `app.url` |
| `--root` | `/www/wwwroot/<domain>` | Override the site root |
| `--repo` | this repository | Deploy from a fork |
| `--branch` | `main` | Branch to deploy |
| `--db-host` / `--db-port` | `127.0.0.1` / `3306` | Database host |
| `--db-name` / `--db-user` / `--db-pass` | *required* | Database credentials |
| `--create-db` | off | Create the database and user (needs MySQL root) |
| `--db-root-pass` | reads `/root/.my.cnf` | MySQL root password for `--create-db` |
| `--skip-db` | off | Deploy files only; import SQL yourself |
| `--update` | off | Redeploy code, keep config and data |
| `--no-nginx` | off | Do not touch the nginx rewrite config |
