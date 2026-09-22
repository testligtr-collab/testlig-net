#!/usr/bin/env bash
# One-time bootstrap: backup live app, create shared+releases layout, convert app → symlink.
# Safe: does not touch DB, certs, or .env.local contents beyond copying into shared.
set -euo pipefail

SITE_ROOT="/home/testlig.net"
APP_DIR="${SITE_ROOT}/app"
RELEASES_DIR="${SITE_ROOT}/releases"
SHARED_DIR="${SITE_ROOT}/shared"
APP_USER="${APP_USER:-testl3865}"
APP_GROUP="${APP_GROUP:-testl3865}"
TS="$(date -u +%Y%m%dT%H%M%SZ)"
BACKUP_ROOT="${SITE_ROOT}/backups"
BACKUP_TGZ="${BACKUP_ROOT}/app-pre-deploy-${TS}.tgz"

log() { printf '[bootstrap] %s\n' "$*"; }
die() { printf '[bootstrap] ERROR: %s\n' "$*" >&2; exit 1; }

[[ "$(id -u)" -eq 0 ]] || die "run as root"
[[ -d "$APP_DIR" ]] || die "app missing"

mkdir -p "$RELEASES_DIR" "$SHARED_DIR" "$BACKUP_ROOT" \
  "$SHARED_DIR/var/log" "$SHARED_DIR/public/uploads"

if [[ -L "$APP_DIR" ]]; then
  log "app already symlink -> $(readlink "$APP_DIR"); ensuring shared env"
  if [[ ! -f "$SHARED_DIR/.env.local" ]]; then
    if [[ -f "$APP_DIR/.env.local" ]]; then
      install -m 640 -o "$APP_USER" -g "$APP_GROUP" "$APP_DIR/.env.local" "$SHARED_DIR/.env.local"
    else
      die "no .env.local to seed shared/"
    fi
  fi
  log "bootstrap already done"
  exit 0
fi

log "backing up live app to ${BACKUP_TGZ} (excludes vendor/var caches for size; keeps .env.local)"
tar -C "$SITE_ROOT" -czf "$BACKUP_TGZ" \
  --exclude='app/vendor' \
  --exclude='app/var/cache' \
  --exclude='app/.git' \
  app
ls -lh "$BACKUP_TGZ"

if [[ ! -f "$SHARED_DIR/.env.local" ]]; then
  [[ -f "$APP_DIR/.env.local" ]] || die ".env.local missing on live app"
  install -m 640 -o "$APP_USER" -g "$APP_GROUP" "$APP_DIR/.env.local" "$SHARED_DIR/.env.local"
  log "seeded shared/.env.local"
fi

# Preserve uploads if any
if [[ -d "$APP_DIR/public/uploads" && ! -L "$APP_DIR/public/uploads" ]]; then
  cp -a "$APP_DIR/public/uploads/." "$SHARED_DIR/public/uploads/" 2>/dev/null || true
fi
if [[ -d "$APP_DIR/var/log" ]]; then
  cp -a "$APP_DIR/var/log/." "$SHARED_DIR/var/log/" 2>/dev/null || true
fi

BASE_NAME="baseline-${TS}"
BASE_DIR="${RELEASES_DIR}/${BASE_NAME}"
log "moving live app -> ${BASE_DIR}"
mv "$APP_DIR" "$BASE_DIR"

# Wire shared links inside baseline
rm -f "$BASE_DIR/.env.local"
install -m 640 -o "$APP_USER" -g "$APP_GROUP" "$SHARED_DIR/.env.local" "$BASE_DIR/.env.local"
rm -rf "$BASE_DIR/var/log"
mkdir -p "$BASE_DIR/var" "$BASE_DIR/public"
ln -sfn ../../shared/var/log "$BASE_DIR/var/log"
if [[ -e "$BASE_DIR/public/uploads" || -L "$BASE_DIR/public/uploads" ]]; then
  rm -rf "$BASE_DIR/public/uploads"
fi
ln -sfn ../../shared/public/uploads "$BASE_DIR/public/uploads"

if [[ ! -f "$BASE_DIR/REVISION" ]]; then
  printf 'baseline-unknown\n' >"$BASE_DIR/REVISION"
fi
chown -R "$APP_USER:$APP_GROUP" "$BASE_DIR"

log "pointing app symlink -> releases/${BASE_NAME}"
ln -sfn "releases/${BASE_NAME}" "$APP_DIR"

# public_html should already be app/public; verify
if [[ -L "${SITE_ROOT}/public_html" ]]; then
  log "public_html -> $(readlink "${SITE_ROOT}/public_html")"
fi

printf '%s\n' "$(cat "$BASE_DIR/REVISION")" >"$SHARED_DIR/CURRENT_REVISION"
printf '%s\n' "$BASE_NAME" >"$SHARED_DIR/CURRENT_RELEASE"
chown "$APP_USER:$APP_GROUP" "$SHARED_DIR/CURRENT_REVISION" "$SHARED_DIR/CURRENT_RELEASE"

# Quick health
CODE="$(curl -sk -o /tmp/boot-health.json -w '%{http_code}' \
  --resolve 'testlig.net:443:127.0.0.1' https://testlig.net/health || true)"
log "post-bootstrap health=${CODE}"
[[ "$CODE" == "200" ]] || die "health failed after bootstrap — investigate before deploy"
grep -q '"status":"ok"' /tmp/boot-health.json || die "health body not ok"

log "OK bootstrap complete backup=${BACKUP_TGZ}"
