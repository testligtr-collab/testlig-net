#!/usr/bin/env bash
# Refresh OpenLiteSpeed workers for the release that is already current.
# Does not create a release, extract an archive, or change the app symlink.
# Does not run Composer, migrations, cache clear, cache warmup, or package commands.
# Does not read or write env files, groups, ACL, ClamAV, or application data.
# Fixture-only variables are TESTLIG_OLS_REFRESH_ROOT, TESTLIG_OLS_REFRESH_LSWSCTRL,
# TESTLIG_OLS_REFRESH_HEALTH_BODY, and TESTLIG_OLS_REFRESH_SLEEP.
# The workflow must not set them.
set -euo pipefail

root="${TESTLIG_OLS_REFRESH_ROOT:-}"
expected="${EXPECTED_SHA:-}"
if [ -n "$root" ]; then
  site="${root}/home/testlig.net"
  lswsctrl="${TESTLIG_OLS_REFRESH_LSWSCTRL:-${root}/usr/local/lsws/bin/lswsctrl}"
  health_body="${TESTLIG_OLS_REFRESH_HEALTH_BODY:-${root}/health.json}"
  pause_seconds="${TESTLIG_OLS_REFRESH_SLEEP:-1}"
else
  site="/home/testlig.net"
  lswsctrl="/usr/local/lsws/bin/lswsctrl"
  health_body="/tmp/testlig-health.json"
  pause_seconds=1
fi
app_link="${site}/app"
releases_dir="${site}/releases"
shared_revision="${site}/shared/CURRENT_REVISION"
health_url="${HEALTH_URL:-https://127.0.0.1/health}"
health_host="${HEALTH_HOST:-testlig.net}"

fail() {
  printf 'stop_reason=%s\n' "$1"
  printf 'lswsctrl_called=no\n'
  exit 1
}

read_revision() {
  tr -d '\r\n' <"$1"
}

safe_output() {
  printf '%s' "$1" | tr '\r\n' '  ' | head -c 400
  printf '\n'
}

if ! printf '%s' "$expected" | grep -Eq '^[0-9a-f]{40}$'; then
  fail sha_invalid
fi
if [ ! -L "$app_link" ]; then
  fail not_symlink
fi
target="$(readlink -f "$app_link" 2>/dev/null || true)"
case "$target" in
  "$releases_dir"/*) ;;
  *) fail target_outside_releases ;;
esac
if [ ! -d "$target" ]; then
  fail target_outside_releases
fi
release_revision_file="${target}/REVISION"
if [ ! -f "$release_revision_file" ] || [ ! -r "$release_revision_file" ]; then
  fail revision_unreadable
fi
if [ ! -f "$shared_revision" ] || [ ! -r "$shared_revision" ]; then
  fail revision_unreadable
fi
release_revision="$(read_revision "$release_revision_file")"
shared_value="$(read_revision "$shared_revision")"
if [ "$release_revision" != "$expected" ] || [ "$shared_value" != "$expected" ]; then
  fail revision_mismatch
fi
if [ ! -x "$lswsctrl" ] || [ ! -f "$lswsctrl" ]; then
  fail lswsctrl_missing
fi
if ! command -v curl >/dev/null 2>&1 || ! command -v readlink >/dev/null 2>&1; then
  fail command_missing
fi

release_before="$(basename "$target")"
printf 'release_before=%s\n' "$release_before"
printf 'revision_before=%s\n' "$release_revision"

set +e
stop_output="$("$lswsctrl" stop 2>&1)"
stop_status=$?
set -e
printf 'lswsctrl_stop_status=%s\n' "$stop_status"
printf 'lswsctrl_stop_output='
safe_output "$stop_output"
sleep "$pause_seconds"

set +e
start_output="$("$lswsctrl" start 2>&1)"
start_status=$?
set -e
printf 'lswsctrl_start_status=%s\n' "$start_status"
printf 'lswsctrl_start_output='
safe_output "$start_output"
sleep "$pause_seconds"

target_after="$(readlink -f "$app_link" 2>/dev/null || true)"
release_after="$(basename "$target_after")"
release_revision_after=""
shared_after=""
if [ -f "$release_revision_file" ] && [ -r "$release_revision_file" ]; then
  release_revision_after="$(read_revision "$release_revision_file")"
fi
if [ -f "$shared_revision" ] && [ -r "$shared_revision" ]; then
  shared_after="$(read_revision "$shared_revision")"
fi
printf 'release_after=%s\n' "$release_after"
printf 'revision_after=%s\n' "$release_revision_after"
if [ "$target_after" != "$target" ] || [ "$release_after" != "$release_before" ] || [ "$release_revision_after" != "$expected" ] || [ "$shared_after" != "$expected" ]; then
  printf 'release_identity_unchanged=no\n'
  exit 1
fi
printf 'release_identity_unchanged=yes\n'

if [ "$start_status" -ne 0 ]; then
  printf 'stop_reason=start_failed\n'
  exit 1
fi

health_code="$(curl -sk --max-time 15 -o "$health_body" -w '%{http_code}' \
  --resolve "${health_host}:443:127.0.0.1" \
  -H "Host: ${health_host}" \
  "$health_url" || true)"
printf 'local_health_status=%s\n' "$health_code"
if [ "$health_code" != "200" ]; then
  printf 'stop_reason=health_status\n'
  exit 1
fi
if ! grep -q '"status":"ok"' "$health_body" 2>/dev/null; then
  printf 'stop_reason=health_body\n'
  exit 1
fi

home_code="$(curl -sk --max-time 15 -o /dev/null -w '%{http_code}' \
  --resolve "${health_host}:443:127.0.0.1" \
  "https://${health_host}/" || true)"
printf 'local_home_status=%s\n' "$home_code"
if [ "$home_code" != "200" ]; then
  printf 'stop_reason=home_status\n'
  exit 1
fi

target_final="$(readlink -f "$app_link" 2>/dev/null || true)"
if [ "$target_final" != "$target" ]; then
  printf 'release_identity_unchanged=no\n'
  exit 1
fi
printf 'result=refreshed\n'
