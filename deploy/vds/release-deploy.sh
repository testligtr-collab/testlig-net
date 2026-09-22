#!/usr/bin/env bash
# Testlig VDS release deploy — activate a prepared release tarball.
# Usage: testlig-release-deploy.sh <git-sha> <tarball-path>
# Preserves: shared/.env.local, DB, certs, uploads. Never drops schema/data.
set -euo pipefail

SHA="${1:?git sha required}"
TARBALL="${2:?tarball path required}"
SITE_ROOT="/home/testlig.net"
RELEASES_DIR="${SITE_ROOT}/releases"
SHARED_DIR="${SITE_ROOT}/shared"
APP_LINK="${SITE_ROOT}/app"
KEEP_RELEASES="${KEEP_RELEASES:-5}"
PHP_BIN="${PHP_BIN:-/usr/local/lsws/lsphp83/bin/php}"
COMPOSER_BIN="${COMPOSER_BIN:-/usr/bin/composer}"
APP_USER="${APP_USER:-testl3865}"
APP_GROUP="${APP_GROUP:-testl3865}"
HEALTH_URL="${HEALTH_URL:-https://127.0.0.1/health}"
HEALTH_HOST="${HEALTH_HOST:-testlig.net}"

log() { printf '[deploy] %s\n' "$*"; }
die() { printf '[deploy] ERROR: %s\n' "$*" >&2; exit 1; }

[[ -f "$TARBALL" ]] || die "tarball missing: $TARBALL"
[[ -x "$PHP_BIN" ]] || die "PHP binary missing: $PHP_BIN"
[[ -d "$SHARED_DIR" ]] || die "shared dir missing: $SHARED_DIR"
[[ -f "$SHARED_DIR/.env.local" ]] || die "shared/.env.local missing — refuse to deploy without env"
command -v "$COMPOSER_BIN" >/dev/null || die "composer missing"

umask 022
TS="$(date -u +%Y%m%dT%H%M%SZ)"
SHORT_SHA="$(printf '%s' "$SHA" | cut -c1-12)"
RELEASE_NAME="${TS}-${SHORT_SHA}"
RELEASE_DIR="${RELEASES_DIR}/${RELEASE_NAME}"
PREV_TARGET=""

if [[ -L "$APP_LINK" ]]; then
  PREV_TARGET="$(readlink -f "$APP_LINK" || true)"
elif [[ -d "$APP_LINK" ]]; then
  die "app is a real directory, not a symlink — run bootstrap first"
fi

mkdir -p "$RELEASE_DIR"
log "extracting to ${RELEASE_DIR}"
tar -xzf "$TARBALL" -C "$RELEASE_DIR"

# Never ship secrets from the archive; always use shared env.
rm -f "$RELEASE_DIR/.env.local" "$RELEASE_DIR/.env.*.local" 2>/dev/null || true
install -m 640 -o "$APP_USER" -g "$APP_GROUP" "$SHARED_DIR/.env.local" "$RELEASE_DIR/.env.local"

# Ensure prod marker exists without overwriting operator local secrets.
if [[ ! -f "$RELEASE_DIR/.env" ]]; then
  printf 'APP_ENV=prod\n' >"$RELEASE_DIR/.env"
  chown "$APP_USER:$APP_GROUP" "$RELEASE_DIR/.env"
  chmod 640 "$RELEASE_DIR/.env"
fi

# Shared writable paths (create if absent; link into release).
mkdir -p "$SHARED_DIR/var/log" "$SHARED_DIR/public/uploads"
if [[ -d "$RELEASE_DIR/var" ]]; then
  rm -rf "$RELEASE_DIR/var/log"
else
  mkdir -p "$RELEASE_DIR/var"
fi
ln -sfn ../../shared/var/log "$RELEASE_DIR/var/log"
mkdir -p "$RELEASE_DIR/public"
if [[ -e "$RELEASE_DIR/public/uploads" || -L "$RELEASE_DIR/public/uploads" ]]; then
  rm -rf "$RELEASE_DIR/public/uploads"
fi
ln -sfn ../../shared/public/uploads "$RELEASE_DIR/public/uploads"

# OLS front-controller rewrite (must exist; CyberPanel uses public/.htaccess)
if [[ ! -f "$RELEASE_DIR/public/.htaccess" ]]; then
  if [[ -f "$SHARED_DIR/public/.htaccess" ]]; then
    install -m 644 -o "$APP_USER" -g "$APP_GROUP" "$SHARED_DIR/public/.htaccess" "$RELEASE_DIR/public/.htaccess"
  else
    cat >"$RELEASE_DIR/public/.htaccess" <<'HTA'
DirectoryIndex index.php
<IfModule mod_rewrite.c>
    RewriteEngine On
    RewriteCond %{HTTP:Authorization} .
    RewriteRule ^ - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]
    RewriteCond %{REQUEST_FILENAME} !-f
    RewriteRule ^ index.php [L]
</IfModule>
HTA
    chown "$APP_USER:$APP_GROUP" "$RELEASE_DIR/public/.htaccess"
  fi
fi
# Keep a golden copy for future releases
mkdir -p "$SHARED_DIR/public"
cp -f "$RELEASE_DIR/public/.htaccess" "$SHARED_DIR/public/.htaccess"
chown "$APP_USER:$APP_GROUP" "$SHARED_DIR/public/.htaccess" || true

printf '%s\n' "$SHA" >"$RELEASE_DIR/REVISION"
chown -R "$APP_USER:$APP_GROUP" "$RELEASE_DIR"

run_app() {
  # Run as app user when possible; fall back to root with COMPOSER_ALLOW_SUPERUSER.
  if command -v runuser >/dev/null 2>&1; then
    runuser -u "$APP_USER" -- env HOME="$SITE_ROOT" COMPOSER_HOME="$SITE_ROOT/.composer" "$@"
  else
    env HOME="$SITE_ROOT" COMPOSER_HOME="$SITE_ROOT/.composer" COMPOSER_ALLOW_SUPERUSER=1 "$@"
  fi
}

cd "$RELEASE_DIR"
log "composer install (no-dev)"
export COMPOSER_ALLOW_SUPERUSER=1
run_app "$PHP_BIN" "$COMPOSER_BIN" install \
  --no-dev \
  --prefer-dist \
  --no-interaction \
  --no-progress \
  --optimize-autoloader \
  --classmap-authoritative \
  --no-scripts

log "composer dump-autoload + auto-scripts (prod)"
run_app "$PHP_BIN" "$COMPOSER_BIN" dump-autoload --optimize --classmap-authoritative --no-interaction
# Run Symfony install scripts explicitly in prod.
run_app "$PHP_BIN" bin/console cache:clear --env=prod --no-warmup --no-interaction || true
run_app "$PHP_BIN" bin/console assets:install public --env=prod --no-interaction || true
run_app "$PHP_BIN" bin/console importmap:install --env=prod --no-interaction || true

log "doctrine migrations (no data drop)"
run_app "$PHP_BIN" bin/console doctrine:migrations:migrate --no-interaction --all-or-nothing --env=prod

log "asset-map compile + cache warmup"
run_app "$PHP_BIN" bin/console asset-map:compile --env=prod --no-interaction || true
run_app "$PHP_BIN" bin/console cache:warmup --env=prod --no-interaction

chown -R "$APP_USER:$APP_GROUP" "$RELEASE_DIR"
# OLS needs write to var/cache
chmod -R ug+rwX "$RELEASE_DIR/var" || true

rollback() {
  if [[ -n "$PREV_TARGET" && -d "$PREV_TARGET" ]]; then
    log "rollback -> $PREV_TARGET"
    if [[ "$PREV_TARGET" == "$RELEASES_DIR"/* ]]; then
      ln -sfn "releases/$(basename "$PREV_TARGET")" "$APP_LINK"
    else
      ln -sfn "$PREV_TARGET" "$APP_LINK"
    fi
  fi
}

log "atomic symlink switch"
ln -sfn "releases/${RELEASE_NAME}" "$APP_LINK"
# Ensure OLS re-reads .htaccess after docroot symlink change
touch "$APP_LINK/public/.htaccess" 2>/dev/null || true
if [[ -x /usr/local/lsws/bin/lswsctrl ]]; then
  /usr/local/lsws/bin/lswsctrl restart >/dev/null 2>&1 || true
  sleep 1
fi

# Health check from localhost (health-restrict allows 127.0.0.1)
log "health check"
HEALTH_CODE="$(curl -sk -o /tmp/testlig-health.json -w '%{http_code}' \
  --resolve "${HEALTH_HOST}:443:127.0.0.1" \
  -H "Host: ${HEALTH_HOST}" \
  "$HEALTH_URL" || true)"
if [[ "$HEALTH_CODE" != "200" ]]; then
  log "health failed code=${HEALTH_CODE}"
  rollback
  die "health check failed after switch; rolled back"
fi
if ! grep -q '"status":"ok"' /tmp/testlig-health.json 2>/dev/null; then
  log "health body not ok"
  rollback
  die "health payload not ok; rolled back"
fi

# Smoke: homepage reachable
HOME_CODE="$(curl -sk -o /dev/null -w '%{http_code}' \
  --resolve "${HEALTH_HOST}:443:127.0.0.1" \
  "https://${HEALTH_HOST}/" || true)"
if [[ "$HOME_CODE" != "200" ]]; then
  log "homepage failed code=${HOME_CODE}"
  rollback
  die "homepage check failed; rolled back"
fi

printf '%s\n' "$SHA" >"${SITE_ROOT}/shared/CURRENT_REVISION"
printf '%s\n' "$RELEASE_NAME" >"${SITE_ROOT}/shared/CURRENT_RELEASE"
chown "$APP_USER:$APP_GROUP" "${SITE_ROOT}/shared/CURRENT_REVISION" "${SITE_ROOT}/shared/CURRENT_RELEASE" || true

log "prune old releases (keep ${KEEP_RELEASES})"
# shellcheck disable=SC2012
ls -1dt "$RELEASES_DIR"/*/ 2>/dev/null | tail -n +"$((KEEP_RELEASES + 1))" | while read -r old; do
  # never delete the live target
  live="$(readlink -f "$APP_LINK" || true)"
  old_abs="$(readlink -f "$old" || true)"
  if [[ -n "$live" && "$old_abs" == "$live" ]]; then
    continue
  fi
  if [[ -n "$PREV_TARGET" && "$old_abs" == "$PREV_TARGET" ]]; then
    # keep immediate previous for one-click rollback
    continue
  fi
  log "removing old release $(basename "$old")"
  rm -rf "$old"
done

log "OK release=${RELEASE_NAME} sha=${SHA}"
cat /tmp/testlig-health.json
printf '\n'
