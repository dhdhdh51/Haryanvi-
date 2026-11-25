#!/usr/bin/env bash
# =====================================================================
#  Haryanvi AI — server installer
# ---------------------------------------------------------------------
#  Deploys the backend from git into an existing site root, creates the
#  database, writes the configuration and verifies the result.
#
#  Run it from the server shell (aaPanel → Terminal, or SSH):
#
#    bash install.sh \
#      --domain haryanvi.example.com \
#      --db-name haryanvi_ai \
#      --db-user haryanvi_user \
#      --db-pass 'your-db-password'
#
#  Useful extras:
#    --root /www/wwwroot/haryanvi.example.com   override the site root
#    --branch main                              branch to deploy
#    --repo https://github.com/you/Haryanvi-.git
#    --db-host 127.0.0.1  --db-port 3306
#    --create-db          create the database and user too (needs MySQL root)
#    --db-root-pass PASS  MySQL root password, if not in /root/.my.cnf
#    --skip-db            site files only, leave the database alone
#    --update             redeploy code, keep config.php and the database
#    --no-nginx           do not touch the nginx rewrite config
#
#  WHAT THIS SCRIPT DOES NOT DO
#  It does not create the site or the subdomain. aaPanel has to generate
#  the vhost, the SSL certificate and the PHP-FPM pool, so create the
#  site in the panel first (Website → Add site). This script then fills
#  it in. See DEPLOYMENT.md for the five panel clicks involved.
#
#  Safe to re-run. Existing config.php is never overwritten.
# =====================================================================

set -uo pipefail

REPO="https://github.com/dhdhdh51/Haryanvi-.git"
BRANCH="main"
DOMAIN=""
SITE_ROOT=""
DB_HOST="127.0.0.1"
DB_PORT="3306"
DB_NAME=""
DB_USER=""
DB_PASS=""
SKIP_DB=0
UPDATE_ONLY=0
CREATE_DB=0
DB_ROOT_PASS=""
INSTALL_NGINX=1

# ---------------------------------------------------------------------
#  Output helpers
# ---------------------------------------------------------------------
if [ -t 1 ]; then
    BOLD=$'\033[1m'; GREEN=$'\033[32m'; RED=$'\033[31m'
    YELLOW=$'\033[33m'; DIM=$'\033[2m'; RESET=$'\033[0m'
else
    BOLD=""; GREEN=""; RED=""; YELLOW=""; DIM=""; RESET=""
fi

step()  { printf "\n%s==> %s%s\n" "$BOLD" "$*" "$RESET"; }
ok()    { printf "    %s✓%s %s\n" "$GREEN" "$RESET" "$*"; }
warn()  { printf "    %s!%s %s\n" "$YELLOW" "$RESET" "$*"; }
fail()  { printf "\n%serror:%s %s\n\n" "$RED" "$RESET" "$*" >&2; exit 1; }
note()  { printf "    %s%s%s\n" "$DIM" "$*" "$RESET"; }

# ---------------------------------------------------------------------
#  Arguments
# ---------------------------------------------------------------------
while [ $# -gt 0 ]; do
    case "$1" in
        --repo)      REPO="${2:-}"; shift 2 ;;
        --branch)    BRANCH="${2:-}"; shift 2 ;;
        --domain)    DOMAIN="${2:-}"; shift 2 ;;
        --root)      SITE_ROOT="${2:-}"; shift 2 ;;
        --db-host)   DB_HOST="${2:-}"; shift 2 ;;
        --db-port)   DB_PORT="${2:-}"; shift 2 ;;
        --db-name)   DB_NAME="${2:-}"; shift 2 ;;
        --db-user)   DB_USER="${2:-}"; shift 2 ;;
        --db-pass)   DB_PASS="${2:-}"; shift 2 ;;
        --skip-db)   SKIP_DB=1; shift ;;
        --update)    UPDATE_ONLY=1; shift ;;
        --create-db) CREATE_DB=1; shift ;;
        --db-root-pass) DB_ROOT_PASS="${2:-}"; shift 2 ;;
        --no-nginx)  INSTALL_NGINX=0; shift ;;
        -h|--help)   sed -n '2,40p' "$0"; exit 0 ;;
        *)           fail "Unknown option: $1  (try --help)" ;;
    esac
done

[ -n "$DOMAIN" ] || fail "--domain is required, e.g. --domain haryanvi.example.com"

[ -n "$SITE_ROOT" ] || SITE_ROOT="/www/wwwroot/$DOMAIN"

if [ "$SKIP_DB" -eq 0 ] && [ "$UPDATE_ONLY" -eq 0 ]; then
    [ -n "$DB_NAME" ] || fail "--db-name is required (or pass --skip-db)"
    [ -n "$DB_USER" ] || fail "--db-user is required (or pass --skip-db)"
    [ -n "$DB_PASS" ] || fail "--db-pass is required (or pass --skip-db)"
fi

printf "\n%sHaryanvi AI installer%s\n" "$BOLD" "$RESET"
note "domain : $DOMAIN"
note "root   : $SITE_ROOT"
note "branch : $BRANCH"

# ---------------------------------------------------------------------
#  1. Prerequisites
# ---------------------------------------------------------------------
step "Checking prerequisites"

command -v git >/dev/null 2>&1 || fail "git is not installed. aaPanel: App Store → search 'git', or: yum -y install git / apt -y install git"

# aaPanel keeps PHP outside PATH; find the newest 8.2+ binary.
PHP_BIN=""
for candidate in \
    "$(command -v php 2>/dev/null)" \
    /www/server/php/84/bin/php \
    /www/server/php/83/bin/php \
    /www/server/php/82/bin/php
do
    [ -n "$candidate" ] && [ -x "$candidate" ] || continue
    version=$("$candidate" -r 'echo PHP_VERSION_ID;' 2>/dev/null || echo 0)
    if [ "$version" -ge 80200 ] 2>/dev/null; then
        PHP_BIN="$candidate"
        break
    fi
done

[ -n "$PHP_BIN" ] || fail "No PHP 8.2+ found. aaPanel: App Store → PHP 8.2 (or newer) → Install."
ok "PHP $("$PHP_BIN" -r 'echo PHP_VERSION;') at $PHP_BIN"

# Required extensions.
missing=""
for ext in pdo_mysql openssl curl mbstring json; do
    "$PHP_BIN" -m 2>/dev/null | grep -qix "$ext" || missing="$missing $ext"
done
[ -z "$missing" ] || fail "PHP is missing:$missing — enable them in aaPanel → PHP → Install extensions"
ok "Required PHP extensions present"

MYSQL_BIN="$(command -v mysql 2>/dev/null || true)"
[ -n "$MYSQL_BIN" ] || MYSQL_BIN="/www/server/mysql/bin/mysql"
if [ "$SKIP_DB" -eq 0 ] && [ "$UPDATE_ONLY" -eq 0 ]; then
    [ -x "$MYSQL_BIN" ] || fail "mysql client not found. Pass --skip-db and import the SQL through phpMyAdmin instead."
    ok "mysql client at $MYSQL_BIN"
fi

[ -d "$SITE_ROOT" ] || fail "Site root does not exist: $SITE_ROOT
    Create the site first: aaPanel → Website → Add site → domain $DOMAIN"
ok "Site root exists"

# ---------------------------------------------------------------------
#  2. Fetch the source
# ---------------------------------------------------------------------
step "Fetching source"

WORK="/tmp/haryanvi-deploy-$$"
rm -rf "$WORK"

git clone --depth 1 --branch "$BRANCH" "$REPO" "$WORK" >/dev/null 2>&1 \
    || fail "git clone failed for $REPO (branch $BRANCH). Check the URL and that the server has outbound HTTPS."

[ -f "$WORK/backend/index.php" ] || fail "Clone succeeded but backend/index.php is missing — wrong repository?"
ok "Cloned $(cd "$WORK" && git rev-parse --short HEAD) from $BRANCH"

# ---------------------------------------------------------------------
#  3. Back up anything already deployed
# ---------------------------------------------------------------------
if [ -f "$SITE_ROOT/index.php" ] || [ -d "$SITE_ROOT/src" ]; then
    step "Backing up the current deployment"

    BACKUP="/www/backup/haryanvi-$(date +%Y%m%d-%H%M%S).tar.gz"
    mkdir -p "$(dirname "$BACKUP")" 2>/dev/null || BACKUP="/tmp/$(basename "$BACKUP")"

    tar czf "$BACKUP" -C "$SITE_ROOT" . 2>/dev/null \
        && ok "Saved $BACKUP" \
        || warn "Backup failed; continuing (nothing was deleted yet)"
fi

# ---------------------------------------------------------------------
#  4. Deploy the files
# ---------------------------------------------------------------------
step "Deploying backend files"

# Preserve the live configuration and logs across a redeploy.
PRESERVED="/tmp/haryanvi-preserve-$$"
mkdir -p "$PRESERVED"
[ -f "$SITE_ROOT/config/config.php" ] && cp "$SITE_ROOT/config/config.php" "$PRESERVED/config.php"
[ -d "$SITE_ROOT/storage/logs" ] && cp -r "$SITE_ROOT/storage/logs" "$PRESERVED/logs" 2>/dev/null

# aaPanel drops a placeholder index.html on new sites; it would win over
# index.php in the directory index.
for placeholder in index.html 404.html .user.ini; do
    [ -f "$SITE_ROOT/$placeholder" ] && mv "$SITE_ROOT/$placeholder" "$SITE_ROOT/$placeholder.bak" 2>/dev/null
done

cp -r "$WORK/backend/." "$SITE_ROOT/" || fail "Copy into $SITE_ROOT failed — check permissions"

# The test suite and the deploy templates do not belong on a live site.
rm -rf "$SITE_ROOT/tests"

if [ -f "$PRESERVED/config.php" ]; then
    cp "$PRESERVED/config.php" "$SITE_ROOT/config/config.php"
    ok "Existing config.php preserved"
fi
[ -d "$PRESERVED/logs" ] && cp -r "$PRESERVED/logs/." "$SITE_ROOT/storage/logs/" 2>/dev/null
rm -rf "$PRESERVED"

ok "Files deployed to $SITE_ROOT"

# ---------------------------------------------------------------------
#  5. Ownership and permissions
# ---------------------------------------------------------------------
step "Setting ownership and permissions"

WEB_USER="www"
id www >/dev/null 2>&1 || WEB_USER="www-data"
id "$WEB_USER" >/dev/null 2>&1 || WEB_USER=""

if [ -n "$WEB_USER" ]; then
    chown -R "$WEB_USER:$WEB_USER" "$SITE_ROOT" 2>/dev/null && ok "Owner set to $WEB_USER"
else
    warn "Could not determine the web user; leaving ownership unchanged"
fi

find "$SITE_ROOT" -type d -exec chmod 755 {} \; 2>/dev/null
find "$SITE_ROOT" -type f -exec chmod 644 {} \; 2>/dev/null
mkdir -p "$SITE_ROOT/storage/logs" "$SITE_ROOT/storage/cache"
chmod -R 750 "$SITE_ROOT/storage"
ok "Permissions applied, storage/ writable"

# ---------------------------------------------------------------------
#  6. Database
# ---------------------------------------------------------------------
if [ "$SKIP_DB" -eq 1 ]; then
    step "Skipping database (--skip-db)"
    note "Import manually: database/schema.sql then database/seed.sql"
elif [ "$UPDATE_ONLY" -eq 1 ]; then
    step "Skipping database (--update)"
    note "Apply any pending migrations: cd $SITE_ROOT && $PHP_BIN bin/migrate.php"
else
    step "Loading the database"

    mysql_run() {
        "$MYSQL_BIN" -h "$DB_HOST" -P "$DB_PORT" -u "$DB_USER" -p"$DB_PASS" "$@" 2>&1
    }

    # --- Optionally create the database and user ---------------------
    if [ "$CREATE_DB" -eq 1 ]; then
        mysql_root() {
            if [ -n "$DB_ROOT_PASS" ]; then
                "$MYSQL_BIN" -h "$DB_HOST" -P "$DB_PORT" -u root -p"$DB_ROOT_PASS" "$@" 2>&1
            else
                # Relies on /root/.my.cnf, which aaPanel usually provides.
                "$MYSQL_BIN" -h "$DB_HOST" -P "$DB_PORT" -u root "$@" 2>&1
            fi
        }

        mysql_root -e "SELECT 1;" >/dev/null 2>&1 \
            || fail "Cannot connect to MySQL as root, so --create-db cannot run.
    Either pass --db-root-pass 'the-root-password', or create the database in
    the panel (Databases → Add database) and re-run without --create-db."

        # CREATE ... IF NOT EXISTS, so re-running is harmless.
        mysql_root <<SQL >/dev/null 2>&1
CREATE DATABASE IF NOT EXISTS \`$DB_NAME\`
    CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '$DB_USER'@'localhost' IDENTIFIED BY '$DB_PASS';
CREATE USER IF NOT EXISTS '$DB_USER'@'127.0.0.1' IDENTIFIED BY '$DB_PASS';
GRANT ALL PRIVILEGES ON \`$DB_NAME\`.* TO '$DB_USER'@'localhost';
GRANT ALL PRIVILEGES ON \`$DB_NAME\`.* TO '$DB_USER'@'127.0.0.1';
FLUSH PRIVILEGES;
SQL
        if [ $? -eq 0 ]; then
            ok "Database '$DB_NAME' and user '$DB_USER' ready"
            note "The panel will not list a database it did not create. To see it"
            note "in aaPanel, add it there instead and skip --create-db."
        else
            fail "Creating the database as root failed. Create it in the panel instead."
        fi
    fi

    mysql_run -e "SELECT 1;" "$DB_NAME" >/dev/null 2>&1 \
        || fail "Cannot connect to database '$DB_NAME' as '$DB_USER'.
    Create it first (aaPanel → Databases → Add database), or re-run with
    --create-db to have this script create it."
    ok "Connected to $DB_NAME"

    existing=$(mysql_run -N -B -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='$DB_NAME';" | tail -1)

    if [ "${existing:-0}" -gt 0 ] 2>/dev/null; then
        warn "$DB_NAME already contains $existing tables — leaving it untouched"
        note "To reload from scratch, drop the database in the panel and re-run."
    else
        mysql_run "$DB_NAME" < "$SITE_ROOT/database/schema.sql" >/dev/null \
            || fail "schema.sql failed to load"
        ok "Schema loaded"

        mysql_run "$DB_NAME" < "$SITE_ROOT/database/seed.sql" >/dev/null \
            || fail "seed.sql failed to load"

        counts=$(mysql_run -N -B "$DB_NAME" -e "
            SELECT CONCAT((SELECT COUNT(*) FROM dialects), ' dialects, ',
                          (SELECT COUNT(*) FROM tone_presets), ' tones, ',
                          (SELECT COUNT(*) FROM dialect_vocabulary), ' vocabulary entries');" | tail -1)
        ok "Reference data loaded: $counts"
    fi
fi

# ---------------------------------------------------------------------
#  7. Configuration
# ---------------------------------------------------------------------
step "Writing configuration"

CONFIG="$SITE_ROOT/config/config.php"

if [ -f "$CONFIG" ]; then
    ok "config.php already present — left as it is"
elif [ "$SKIP_DB" -eq 1 ]; then
    cp "$SITE_ROOT/config/config.example.php" "$CONFIG"
    warn "Template copied to config/config.php — fill in the database credentials"
else
    ENC_KEY=$("$PHP_BIN" -r 'echo "base64:" . base64_encode(random_bytes(32));')

    cat > "$CONFIG" <<PHPCONF
<?php
/**
 * Generated by deploy/install.sh on $(date -u '+%Y-%m-%d %H:%M:%S') UTC.
 *
 * Keep this file out of version control and off the public web. The
 * encryption key below protects your AI provider credentials: changing
 * it makes stored provider keys unreadable.
 */

return [
    'app' => [
        'env'      => 'production',
        'debug'    => false,
        'url'      => 'https://$DOMAIN',
        'timezone' => 'Asia/Kolkata',
    ],

    'db' => [
        'host'    => '$DB_HOST',
        'port'    => $DB_PORT,
        'name'    => '$DB_NAME',
        'user'    => '$DB_USER',
        'pass'    => '$DB_PASS',
        'charset' => 'utf8mb4',
        'socket'  => null,
    ],

    'security' => [
        'encryption_key' => '$ENC_KEY',
        'force_https'    => true,
        'session_name'   => 'hy_admin_sess',
        'token_ttl_days' => 90,
        'cors_origins'   => [],
        'max_body_bytes' => 65536,
    ],

    'logging' => [
        'path'  => __DIR__ . '/../storage/logs',
        'level' => 'warning',
    ],
];
PHPCONF

    chmod 600 "$CONFIG"
    [ -n "$WEB_USER" ] && chown "$WEB_USER:$WEB_USER" "$CONFIG" 2>/dev/null

    "$PHP_BIN" -l "$CONFIG" >/dev/null 2>&1 || fail "Generated config.php has a syntax error"
    ok "config.php written (chmod 600) with a fresh encryption key"
fi

# ---------------------------------------------------------------------
#  7b. nginx rewrite rules
# ---------------------------------------------------------------------
#  aaPanel's generated vhost includes
#      /www/server/panel/vhost/rewrite/<domain>.conf
#  inside its server block, so the rules can be installed from here
#  instead of being pasted into the panel UI.
#
#  Every change is backed up and validated with `nginx -t`; if the test
#  fails the previous file is restored and nginx is left untouched.
# ---------------------------------------------------------------------
NGINX_DONE=0

if [ "$INSTALL_NGINX" -eq 0 ]; then
    step "Skipping nginx configuration (--no-nginx)"
elif [ ! -d /www/server/panel/vhost/rewrite ]; then
    step "nginx rewrite rules"
    note "aaPanel rewrite directory not found — this does not look like aaPanel."
    note "Apache needs nothing (the .htaccess files handle it)."
    note "Plain nginx: use deploy/nginx-vhost.conf.example as the server block."
else
    step "Installing nginx rewrite rules"

    REWRITE="/www/server/panel/vhost/rewrite/$DOMAIN.conf"
    SOURCE="$SITE_ROOT/deploy/nginx-aapanel-rewrite.conf"

    if [ ! -f "$SOURCE" ]; then
        warn "$SOURCE is missing — skipping"
    elif grep -q "Haryanvi AI" "$REWRITE" 2>/dev/null; then
        ok "Rules already installed at $REWRITE"
        NGINX_DONE=1
    else
        # Preserve whatever the panel or a previous framework put there.
        if [ -s "$REWRITE" ]; then
            cp "$REWRITE" "$REWRITE.before-haryanvi" 2>/dev/null \
                && note "Previous rewrite file saved as $REWRITE.before-haryanvi"
        fi

        cp "$SOURCE" "$REWRITE" || fail "Could not write $REWRITE (are you root?)"

        NGINX_BIN="$(command -v nginx 2>/dev/null || true)"
        [ -n "$NGINX_BIN" ] || NGINX_BIN="/www/server/nginx/sbin/nginx"

        if [ -x "$NGINX_BIN" ]; then
            if "$NGINX_BIN" -t >/dev/null 2>&1; then
                if "$NGINX_BIN" -s reload >/dev/null 2>&1; then
                    ok "Rules installed and nginx reloaded"
                else
                    warn "Rules installed, but the reload failed."
                    note "Reload from the panel: Website → nginx → Reload"
                fi
                NGINX_DONE=1
            else
                # Roll back rather than leave nginx unable to start.
                if [ -f "$REWRITE.before-haryanvi" ]; then
                    mv "$REWRITE.before-haryanvi" "$REWRITE"
                else
                    rm -f "$REWRITE"
                fi
                warn "nginx -t rejected the new config; it has been rolled back."
                note "nginx was not reloaded, so your other sites are unaffected."
                note "Paste deploy/nginx-aapanel-rewrite.conf into the panel's"
                note "URL Rewrite box instead, and check the error with: nginx -t"
            fi
        else
            warn "nginx binary not found; rules written but not validated"
            note "Reload from the panel: Website → nginx → Reload"
            NGINX_DONE=1
        fi
    fi
fi

# ---------------------------------------------------------------------
#  8. Local verification
# ---------------------------------------------------------------------
step "Verifying the installation"

cd "$SITE_ROOT" || fail "Cannot enter $SITE_ROOT"

health=$("$PHP_BIN" -r '
$_SERVER["REQUEST_METHOD"] = "GET";
$_SERVER["REQUEST_URI"]    = "/api/v1/health";
$_SERVER["SCRIPT_NAME"]    = "/index.php";
$_SERVER["HTTPS"]          = "on";
ob_start();
require "index.php";
' 2>&1 | tail -1)

case "$health" in
    *'"status":"ok"'*)
        ok "Application boots and reaches the database"
        case "$health" in
            *'"ai_provider":"configured"'*) ok "AI provider configured" ;;
            *) warn "No AI provider yet — translation will return a configuration error" ;;
        esac
        ;;
    *CONFIG_MISSING*)       fail "config.php not found where the app expects it" ;;
    *DATABASE_UNAVAILABLE*) fail "The app cannot reach the database. Re-check the credentials in config/config.php." ;;
    *)
        warn "Health check was inconclusive. Raw output:"
        printf "    %s\n" "$health"
        ;;
esac

rm -rf "$WORK"

# ---------------------------------------------------------------------
#  9. What is left to do
# ---------------------------------------------------------------------
cat <<SUMMARY

${BOLD}Files and database are in place.${RESET}

SUMMARY

if [ "$NGINX_DONE" -eq 1 ]; then
    cat <<SUMMARY
${BOLD}nginx rewrite rules: installed.${RESET}

${BOLD}One thing remains, and it needs the panel:${RESET}

  ${BOLD}SSL certificate${RESET}
     aaPanel → Website → $DOMAIN → SSL → Let's Encrypt → Apply,
     then switch on "Force HTTPS".
     The API refuses plain HTTP, so this is not optional.

SUMMARY
else
    cat <<SUMMARY
${BOLD}Two things remain, and both need the panel:${RESET}

  ${BOLD}1. nginx rewrite rules${RESET} ${RED}(required — without these the API returns 404
     and database/*.sql is downloadable)${RESET}

     aaPanel → Website → $DOMAIN → URL Rewrite
     Paste the contents of:
         $SITE_ROOT/deploy/nginx-aapanel-rewrite.conf
     Save.

  ${BOLD}2. SSL certificate${RESET}
     aaPanel → Website → $DOMAIN → SSL → Let's Encrypt → Apply,
     then switch on "Force HTTPS".

SUMMARY
fi

cat <<SUMMARY
${BOLD}Then finish the setup:${RESET}

  curl https://$DOMAIN/api/v1/health

  cd $SITE_ROOT
  $PHP_BIN bin/create-admin.php --username=you --role=super_admin
  $PHP_BIN bin/provider.php add --name="OpenAI" --driver=openai_compatible \\
      --base-url=https://api.openai.com/v1 --model=gpt-4o-mini --key=sk-...
  $PHP_BIN bin/provider.php test --id=1

  Dashboard: https://$DOMAIN/admin/

${BOLD}Confirm the protections actually work${RESET} (all three must say 403):

  curl -o /dev/null -s -w '%{http_code}  database/schema.sql\\n' https://$DOMAIN/database/schema.sql
  curl -o /dev/null -s -w '%{http_code}  src/bootstrap.php\\n'   https://$DOMAIN/src/bootstrap.php
  curl -o /dev/null -s -w '%{http_code}  config/config.php\\n'   https://$DOMAIN/config/config.php

${BOLD}Point the app at this server:${RESET} enter https://$DOMAIN/ on the app's
sign-in screen, or build with -PapiBaseUrl=https://$DOMAIN/

To redeploy later without touching config or data:
  bash install.sh --domain $DOMAIN --update

SUMMARY
