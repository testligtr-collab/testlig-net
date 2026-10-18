#!/usr/bin/env bash
# Read-only start check for the same PHP binary used by the access probe.
# It does not open ClamAV, scan a file, or change services, groups, ACL, or env.
# A numeric exit is not a root-cause claim. Unknown stays unknown.
# A completed run is not ClamAV acceptance or a web or SELinux result.
set -u

echo "php_diag=read-only"
echo "php_diag_changes_access=no"
echo "php_diag_root_cause=unknown"

recorded_euid=5002
root="${TESTLIG_PHP_DIAG_ROOT:-}"
php_bin=/usr/local/lsws/lsphp83/bin/php

if ulimit -c 0; then
  echo "php_diag_core=disabled"
  core_disabled=1
else
  echo "php_diag_core=unknown"
  core_disabled=0
fi

host_path() {
  if [ -n "$root" ]; then
    printf '%s%s' "$root" "$1"
  else
    printf '%s' "$1"
  fi
}

site_account_matches() {
  local account_uid="" recorded_name="" site_uid="" site_path=""
  if [ -n "$root" ]; then
    account_uid="$(timeout 2 bash "${root}/fixture-id" -u testl3865 2>/dev/null || true)"
    recorded_name="$(timeout 2 bash "${root}/fixture-id" -nu "$recorded_euid" 2>/dev/null || true)"
  else
    account_uid="$(timeout 2 id -u testl3865 2>/dev/null || true)"
    recorded_name="$(timeout 2 id -nu "$recorded_euid" 2>/dev/null || true)"
  fi
  account_uid="${account_uid//[[:space:]]/}"
  recorded_name="${recorded_name//[[:space:]]/}"
  site_path="$(host_path "/home/testlig.net")"
  if [ -d "$site_path" ]; then
    if [ -n "$root" ]; then
      site_uid="$(timeout 2 bash "${root}/fixture-stat" -c %u "$site_path" 2>/dev/null || true)"
    else
      site_uid="$(timeout 2 stat -c %u "$site_path" 2>/dev/null || true)"
    fi
  fi
  site_uid="${site_uid//[[:space:]]/}"
  if [ "$account_uid" = "$recorded_euid" ]; then
    echo "site_account_uid_match=yes"
  else
    echo "site_account_uid_match=no"
  fi
  if [ "$recorded_name" = "testl3865" ]; then
    echo "site_account_name_match=yes"
  else
    echo "site_account_name_match=no"
  fi
  if [ "$site_uid" = "$recorded_euid" ]; then
    echo "site_owner_match=yes"
  else
    echo "site_owner_match=no"
  fi
  [ "$account_uid" = "$recorded_euid" ] && [ "$recorded_name" = "testl3865" ] && [ "$site_uid" = "$recorded_euid" ]
}

report_php_diag() {
  local name="$1"
  local raw="$2"
  local status_line="" status="" marker="no" timed="unknown"
  status_line="$(printf '%s\n' "$raw" | awk '/^php_diag_stage_status=/ { line = $0 } END { print line }')"
  status="${status_line#php_diag_stage_status=}"
  if printf '%s\n' "$raw" | grep -qx 'php_diag_started=yes'; then
    marker="yes"
  fi
  case "$status" in
    ''|*[!0-9]*)
      status="unknown"
      timed="unknown"
      ;;
    124)
      timed="yes"
      ;;
    *)
      timed="no"
      ;;
  esac
  printf '%s\n' "php_diag_test=${name}" "php_diag_exit=${status}" "php_diag_marker=${marker}" "php_diag_timeout=${timed}"
}

report_not_attempted() {
  local name="$1"
  printf '%s\n' "php_diag_test=${name}" "php_diag_exit=not_attempted" "php_diag_marker=no" "php_diag_timeout=not_attempted"
}

diag_version() {
  set +e
  local php_status=""
  if [ -n "$root" ]; then
    timeout 5 bash "${root}/fixture-runuser" -u testl3865 -- bash "${root}/fixture-php" -v
  else
    timeout 5 runuser -u testl3865 -- "$php_bin" -v
  fi
  php_status=${PIPESTATUS[0]-}
  printf '\nphp_diag_stage_status=%s\n' "$php_status"
}

diag_inline() {
  set +e
  local php_status=""
  if [ -n "$root" ]; then
    timeout 5 bash "${root}/fixture-runuser" -u testl3865 -- bash "${root}/fixture-php" -r 'echo "php_diag_started=yes\n";'
  else
    timeout 5 runuser -u testl3865 -- "$php_bin" -r 'echo "php_diag_started=yes\n";'
  fi
  php_status=${PIPESTATUS[0]-}
  printf '\nphp_diag_stage_status=%s\n' "$php_status"
}

diag_stdin() {
  set +e
  set -o pipefail
  local php_status=""
  if [ -n "$root" ]; then
    printf '%s\n' '<?php echo "php_diag_started=yes\n";' | timeout 5 bash "${root}/fixture-runuser" -u testl3865 -- bash "${root}/fixture-php"
  else
    printf '%s\n' '<?php echo "php_diag_started=yes\n";' | timeout 5 runuser -u testl3865 -- "$php_bin"
  fi
  php_status=${PIPESTATUS[1]-}
  printf '\nphp_diag_stage_status=%s\n' "$php_status"
}

run_named_diag() {
  local name="$1"
  local raw=""
  raw="$(
    set +e
    "diag_${name}" 2>/dev/null
  )"
  report_php_diag "$name" "$raw"
}

if site_account_matches && [ "$core_disabled" -eq 1 ]; then
  run_named_diag version
  run_named_diag inline
  run_named_diag stdin
else
  report_not_attempted version
  report_not_attempted inline
  report_not_attempted stdin
fi
echo "php_diag_caller_continues=yes"
