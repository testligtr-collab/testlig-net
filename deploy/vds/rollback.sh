#!/usr/bin/env bash
# Manual rollback to previous release directory name (under releases/).
# Usage: testlig-rollback.sh [release-name]
# If omitted, rolls back to the previous release (not current).
set -euo pipefail

SITE_ROOT="/home/testlig.net"
RELEASES_DIR="${SITE_ROOT}/releases"
APP_LINK="${SITE_ROOT}/app"
HEALTH_HOST="testlig.net"

log() { printf '[rollback] %s\n' "$*"; }
die() { printf '[rollback] ERROR: %s\n' "$*" >&2; exit 1; }

[[ "$(id -u)" -eq 0 ]] || die "run as root"
[[ -L "$APP_LINK" ]] || die "app is not a symlink"

TARGET="${1:-}"
if [[ -z "$TARGET" ]]; then
  current="$(readlink -f "$APP_LINK")"
  # second newest by mtime
  TARGET="$(ls -1dt "$RELEASES_DIR"/*/ 2>/dev/null | while read -r d; do
    abs="$(readlink -f "$d")"
    [[ "$abs" == "$current" ]] && continue
    basename "$d"
    break
  done)"
fi
[[ -n "$TARGET" ]] || die "no previous release found"
[[ -d "${RELEASES_DIR}/${TARGET}" ]] || die "release not found: $TARGET"

log "switching app -> releases/${TARGET}"
ln -sfn "releases/${TARGET}" "$APP_LINK"

CODE="$(curl -sk -o /tmp/rollback-health.json -w '%{http_code}' \
  --resolve "${HEALTH_HOST}:443:127.0.0.1" "https://${HEALTH_HOST}/health" || true)"
[[ "$CODE" == "200" ]] || die "health failed after rollback code=${CODE}"
grep -q '"status":"ok"' /tmp/rollback-health.json || die "health body not ok"

printf '%s\n' "$(cat "${RELEASES_DIR}/${TARGET}/REVISION" 2>/dev/null || echo unknown)" \
  >"${SITE_ROOT}/shared/CURRENT_REVISION"
printf '%s\n' "$TARGET" >"${SITE_ROOT}/shared/CURRENT_RELEASE"
log "OK rolled back to ${TARGET}"
