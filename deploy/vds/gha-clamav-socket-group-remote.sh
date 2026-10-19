#!/usr/bin/env bash
# Reversible testl3865 membership in the existing virusgroup.
# This is not the historical access probe. That probe still requires groups [5002].
# A completed apply is CLI socket acceptance for the new process only.
# It is not web worker or SELinux acceptance, and it does not prove loaded main or bytecode.
# It does not change signature permissions, ACL, socket mode, TCP, services, packages, env, or OLS.
set -u

mode="${CLAMAV_GROUP_MODE:-}"
expected="${EXPECTED_SHA:-}"
root="${TESTLIG_CLAMAV_GROUP_ROOT:-}"
account="testl3865"
recorded_uid="5002"
socket_rel="/run/clamd.scan/clamd.sock"
parent_rel="/run/clamd.scan"
signature_rel="/var/lib/clamav"
php_bin="/usr/local/lsws/lsphp83/bin/php"

case "$mode" in
  apply|restore) ;;
  *)
    printf '%s\n' "stop_reason=mode" "result=stopped"
    exit 1
    ;;
esac

if ulimit -c 0; then
  printf '%s\n' "access_probe_core=disabled"
  core_disabled=1
else
  printf '%s\n' "access_probe_core=unknown" "stop_reason=core" "result=stopped"
  exit 1
fi

printf '%s\n' \
  "web_selinux_acceptance=no" \
  "loaded_main=unproven" \
  "loaded_bytecode=unproven" \
  "historical_web_groups=5002" \
  "web_worker_groups_updated=no" \
  "running_processes_groups_unchanged=yes"

site="/home/testlig.net"
if [ -n "$root" ]; then
  site="${root}/home/testlig.net"
fi
shared="${site}/shared"
record="${shared}/clamav-socket-group.record"
app_link="${site}/app"
release_dir=""
virus_gid=""
membership_added="no"
record_state="absent"
probe_php_code=""
probe_php_status=""

stop() {
  printf '%s\n' "stop_reason=$1" "result=stopped" "membership_added=${membership_added}" "record=${record_state}"
  exit 1
}

host_path() {
  if [ -n "$root" ]; then
    printf '%s%s\n' "$root" "$1"
  else
    printf '%s\n' "$1"
  fi
}

run_id() {
  if [ -n "$root" ]; then
    bash "${root}/fixture-id" "$@"
    return
  fi
  id "$@"
}

run_stat() {
  if [ -n "$root" ]; then
    bash "${root}/fixture-stat" "$@"
    return
  fi
  stat "$@"
}

run_getent() {
  if [ -n "$root" ]; then
    bash "${root}/fixture-getent" "$@"
    return
  fi
  getent "$@"
}

run_gpasswd() {
  if [ -n "$root" ]; then
    bash "${root}/fixture-gpasswd" "$@"
    return
  fi
  gpasswd "$@"
}

run_chmod() {
  if [ -n "$root" ]; then
    bash "${root}/fixture-chmod" "$@"
    return
  fi
  chmod "$@"
}

as_app() {
  if [ -n "$root" ]; then
    bash "${root}/fixture-runuser" -u "$account" -- "$@"
    return
  fi
  runuser -u "$account" -- "$@"
}

numeric_token() {
  printf '%s' "$1" | grep -Eq '^[0-9]+$'
}

require_release() {
  local current=""
  printf '%s' "$expected" | grep -Eq '^[0-9a-f]{40}$' || stop release_sha
  if [ -L "${shared}/CURRENT_REVISION" ] || [ ! -f "${shared}/CURRENT_REVISION" ]; then
    stop release_sha
  fi
  current="$(tr -d '[:space:]' < "${shared}/CURRENT_REVISION")"
  [ "$current" = "$expected" ] || stop release_mismatch
  if [ ! -L "$app_link" ] || [ ! -d "$app_link" ]; then
    stop app_link
  fi
  release_dir="$(readlink -f "$app_link" 2>/dev/null || true)"
  [ -n "$release_dir" ] || stop app_link
  case "$release_dir" in
    "${site}/releases/"*) ;;
    *) stop release_dir ;;
  esac
  if [ -L "${release_dir}/REVISION" ] || [ ! -f "${release_dir}/REVISION" ]; then
    stop release_sha
  fi
  current="$(tr -d '[:space:]' < "${release_dir}/REVISION")"
  [ "$current" = "$expected" ] || stop release_mismatch
}

require_site_account() {
  local account_uid="" account_name="" owner_uid=""
  account_uid="$(run_id -u "$account" 2>/dev/null || true)"
  account_name="$(run_id -nu "$recorded_uid" 2>/dev/null || true)"
  owner_uid="$(run_stat -c %u "$(host_path "/home/testlig.net")" 2>/dev/null || true)"
  account_uid="${account_uid//[[:space:]]/}"
  account_name="${account_name//[[:space:]]/}"
  owner_uid="${owner_uid//[[:space:]]/}"
  [ "$account_uid" = "$recorded_uid" ] || stop site_account_uid
  [ "$account_name" = "$account" ] || stop site_account_name
  [ "$owner_uid" = "$recorded_uid" ] || stop site_owner
}

measure_virus_gid() {
  local line="" name="" gid="" rest="" count=0
  while IFS= read -r line || [ -n "$line" ]; do
    count=$((count + 1))
    [ "$count" -eq 1 ] || stop virusgroup_gid
    IFS=: read -r name _ gid rest <<EOF
$line
EOF
  done < <(run_getent group virusgroup 2>/dev/null || true)
  [ "$count" -eq 1 ] || stop virusgroup_gid
  [ "$name" = "virusgroup" ] || stop virusgroup_gid
  numeric_token "$gid" || stop virusgroup_gid
  [ "$gid" != "0" ] || stop virusgroup_gid
  virus_gid="$gid"
  printf '%s\n' "virusgroup_gid=${virus_gid}"
}

require_node() {
  local label="$1"
  local path="$2"
  local owner="" group="" mode=""
  if [ -L "$path" ]; then
    stop "$label"
  fi
  if [ "$label" = "socket" ]; then
    if [ -n "$root" ]; then
      [ -f "$path" ] || stop socket
    elif [ ! -S "$path" ]; then
      stop socket
    fi
  elif [ ! -d "$path" ]; then
    stop "$label"
  fi
  owner="$(run_stat -c %U "$path" 2>/dev/null || true)"
  group="$(run_stat -c %G "$path" 2>/dev/null || true)"
  mode="$(run_stat -c %a "$path" 2>/dev/null || true)"
  owner="${owner//[[:space:]]/}"
  group="${group//[[:space:]]/}"
  mode="${mode//[[:space:]]/}"
  [ "$owner" = "clamscan" ] || stop "$label"
  [ "$group" = "virusgroup" ] || stop "$label"
  if [ "$label" = "socket" ]; then
    [ "$mode" = "660" ] || stop socket
  else
    [ "$mode" = "710" ] || stop socket_parent
  fi
}

require_signature_files() {
  local name="" path=""
  for name in main.cvd daily.cld bytecode.cvd; do
    path="$(host_path "${signature_rel}/${name}")"
    if [ -L "$path" ] || [ ! -f "$path" ]; then
      stop signature_file
    fi
  done
}

app_test() {
  local flag="$1"
  local path="$2"
  as_app test "-${flag}" "$path" >/dev/null 2>&1
}

require_signature_dac() {
  local name="" path=""
  for name in main.cvd daily.cld bytecode.cvd; do
    path="$(host_path "${signature_rel}/${name}")"
    if app_test r "$path"; then
      printf '%s\n' "signature_${name//./_}_dac_r=yes"
    else
      printf '%s\n' "signature_${name//./_}_dac_r=no"
      return 1
    fi
    if app_test w "$path"; then
      printf '%s\n' "signature_${name//./_}_dac_w=yes"
      return 1
    fi
    printf '%s\n' "signature_${name//./_}_dac_w=no"
  done
  return 0
}

prepare_php_code() {
  local suffix="$1"
  local decoded="" marker_count=""
  probe_php_code=""
  if [ "$core_disabled" -ne 1 ] || [ -z "${ACCESS_PROBE_PHP_B64:-}" ]; then
    return 1
  fi
  decoded="$(printf '%s' "$ACCESS_PROBE_PHP_B64" | base64 -d 2>/dev/null; status=$?; printf 'X'; exit "$status")" || return 1
  decoded="${decoded%X}"
  case "$decoded" in
    '<?php'$'\n'*) ;;
    *) return 1 ;;
  esac
  decoded="${decoded#<?php$'\n'}"
  marker_count="$(printf '%s' "$decoded" | awk 'BEGIN { count = 0 } $0 == "// CLAMAV_ACCESS_PROBE_ENTRY" { count++ } END { print count + 0 }')"
  [ "$marker_count" = "1" ] || return 1
  case "$decoded" in
    *'// CLAMAV_ACCESS_PROBE_ENTRY'$'\n') ;;
    *) return 1 ;;
  esac
  local entry_marker='// CLAMAV_ACCESS_PROBE_ENTRY'
  decoded="${decoded%"$entry_marker"$'\n'}"
  probe_php_code="${decoded}${suffix}"$'\n'
  return 0
}

invoke_php() {
  set +e
  if [ -n "$root" ]; then
    timeout 12 bash "${root}/fixture-runuser" -u "$account" -- bash "${root}/fixture-php" -r "$probe_php_code" -- "$@" </dev/null 2>/dev/null
  else
    timeout 12 runuser -u "$account" -- "$php_bin" -r "$probe_php_code" -- "$@" </dev/null 2>/dev/null
  fi
  probe_php_status=${PIPESTATUS[0]-}
}

identity_groups_match() {
  local body="$1"
  local expected_groups="$2"
  local euid="" egid="" groups="" sorted=""
  printf '%s\n' "$body" | grep -qx 'identity_helper_started=yes' || return 1
  euid="$(printf '%s\n' "$body" | awk -F= '$1 == "process_euid" { if (seen) exit 3; seen = 1; print $2 }')"
  [ "$?" -eq 3 ] && return 1
  egid="$(printf '%s\n' "$body" | awk -F= '$1 == "process_egid" { if (seen) exit 3; seen = 1; print $2 }')"
  [ "$?" -eq 3 ] && return 1
  numeric_token "$euid" || return 1
  numeric_token "$egid" || return 1
  [ "$euid" = "$recorded_uid" ] && [ "$egid" = "$recorded_uid" ] || return 1
  groups="$(printf '%s\n' "$body" | awk -F= '$1 == "process_group" { print $2 }')"
  [ -n "$groups" ] || return 1
  local group_id=""
  for group_id in $groups; do
    numeric_token "$group_id" || return 1
  done
  sorted="$(printf '%s\n' $groups | sort -n | tr '\n' ' ' | sed 's/ $//')"
  [ "$sorted" = "$expected_groups" ]
}

read_identity() {
  local raw="" body="" status=""
  prepare_php_code 'clamav_access_probe_cli_main();' || return 1
  raw="$(
    set +e
    invoke_php --identity
    printf '\nidentity_stage_status=%s\n' "${probe_php_status-}"
  )"
  status="$(printf '%s\n' "$raw" | awk '/^identity_stage_status=/ { line = $0 } END { print line }')"
  status="${status#identity_stage_status=}"
  body="$(printf '%s\n' "$raw" | awk '!/^identity_stage_status=/')"
  numeric_token "$status" || return 1
  [ "$status" = "0" ] || return 1
  printf '%s\n' "$body"
  return 0
}

group_members_include_account() {
  local line="" name="" gid="" members="" member=""
  line="$(run_getent group virusgroup 2>/dev/null || true)"
  IFS=: read -r name _ gid members <<EOF
$line
EOF
  [ "$gid" = "$virus_gid" ] || return 1
  IFS=',' read -r -a member_list <<EOF
$members
EOF
  for member in "${member_list[@]-}"; do
    [ "$member" = "$account" ] && return 0
  done
  return 1
}

expected_added_groups() {
  printf '%s\n' "$recorded_uid" "$virus_gid" | sort -n | tr '\n' ' ' | sed 's/ $//'
}

directory_protects_record() {
  local dir="$1"
  local uid="" dir_mode="" dec=0 sticky=0 writable=0
  if [ -L "$dir" ] || [ ! -d "$dir" ]; then
    return 1
  fi
  uid="$(stat -c %u "$dir")" || return 1
  dir_mode="$(run_stat -c %a "$dir")" || return 1
  if [ -n "$root" ]; then
    record_uid="$uid"
    record_gid="$(stat -c %g "$dir")" || return 1
  else
    record_uid=0
    record_gid=0
    [ "$uid" = "0" ] || return 1
  fi
  dec=$((8#$dir_mode))
  sticky=$((dec & 01000))
  writable=$((dec & 022))
  if [ "$writable" -ne 0 ] && [ "$sticky" -eq 0 ]; then
    return 1
  fi
  return 0
}

record_metadata_ok() {
  local target="$1"
  local meta_uid="" meta_gid="" meta_mode=""
  if [ -L "$target" ] || [ ! -f "$target" ]; then
    return 1
  fi
  meta_uid="$(run_stat -c %u "$target")" || return 1
  meta_gid="$(run_stat -c %g "$target")" || return 1
  meta_mode="$(run_stat -c %a "$target")" || return 1
  [ "$meta_uid" = "$record_uid" ] || return 1
  [ "$meta_gid" = "$record_gid" ] || return 1
  [ "$meta_mode" = "600" ] || return 1
  directory_protects_record "$shared"
}

record_field() {
  local key="$1"
  local value="" seen=0 line=""
  [ -f "$record" ] && [ ! -L "$record" ] || return 1
  while IFS= read -r line || [ -n "$line" ]; do
    case "$line" in
      "${key}="*)
        [ "$seen" -eq 0 ] || return 3
        seen=1
        value="${line#"${key}"=}"
        ;;
    esac
  done < "$record"
  [ "$seen" -eq 1 ] || return 1
  printf '%s' "$value"
}

record_shape_ok() {
  local format="" sha="" gid="" groups="" added=""
  format="$(record_field format)" || return 1
  sha="$(record_field release_sha)" || return 1
  gid="$(record_field virusgroup_gid)" || return 1
  groups="$(record_field previous_groups)" || return 1
  added="$(record_field membership_added)" || return 1
  [ "$format" = "1" ] || return 1
  printf '%s' "$sha" | grep -Eq '^[0-9a-f]{40}$' || return 1
  [ "$gid" = "$virus_gid" ] || return 1
  [ "$groups" = "$recorded_uid" ] || return 1
  [ "$added" = "yes" ] || return 1
  return 0
}

write_record() {
  local tmp=""
  directory_protects_record "$shared" || return 1
  if [ -e "$record" ] || [ -L "$record" ]; then
    return 1
  fi
  tmp="$(mktemp "${shared}/.clamav-socket-group.XXXXXX")" || return 1
  if [ -L "$tmp" ] || [ ! -f "$tmp" ]; then
    rm -f "$tmp"
    return 1
  fi
  {
    printf '%s\n' "format=1"
    printf '%s\n' "release_sha=${expected}"
    printf '%s\n' "virusgroup_gid=${virus_gid}"
    printf '%s\n' "previous_groups=${recorded_uid}"
    printf '%s\n' "membership_added=yes"
  } > "$tmp" || { rm -f "$tmp"; return 1; }
  local owner_now="" group_now=""
  owner_now="$(stat -c %u "$tmp")" || { rm -f "$tmp"; return 1; }
  group_now="$(stat -c %g "$tmp")" || { rm -f "$tmp"; return 1; }
  if [ "$owner_now" != "$record_uid" ] || [ "$group_now" != "$record_gid" ]; then
    chown "${record_uid}:${record_gid}" "$tmp" || { rm -f "$tmp"; return 1; }
  fi
  run_chmod 600 "$tmp" || { rm -f "$tmp"; return 1; }
  record_metadata_ok "$tmp" || { rm -f "$tmp"; return 1; }
  mv -f "$tmp" "$record" || { rm -f "$tmp"; return 1; }
  run_chmod 600 "$record" || return 1
  record_metadata_ok "$record" || return 1
  record_state="kept"
  membership_added="yes"
  return 0
}

remove_record() {
  if [ -n "$root" ] && [ -f "${root}/state/fail-record-delete" ]; then
    record_state="kept"
    return 1
  fi
  rm -f "$record" || {
    record_state="kept"
    return 1
  }
  if [ -e "$record" ] || [ -L "$record" ]; then
    record_state="kept"
    return 1
  fi
  record_state="removed"
  return 0
}

rollback_membership() {
  local body="" groups=""
  printf '%s\n' "running_processes_groups_unchanged=yes"
  if [ "$membership_added" = "yes" ]; then
    run_gpasswd -d "$account" virusgroup >/dev/null 2>&1 || true
  fi
  body="$(read_identity || true)"
  if [ -z "$body" ] || ! identity_groups_match "$body" "$recorded_uid"; then
    record_state="kept"
    printf '%s\n' "result=rollback_failed" "record=kept" "membership_added=${membership_added}"
    exit 1
  fi
  if group_members_include_account; then
    record_state="kept"
    printf '%s\n' "result=rollback_failed" "record=kept" "membership_added=${membership_added}"
    exit 1
  fi
  if ! remove_record; then
    printf '%s\n' "result=rollback_failed" "record=kept" "membership_added=${membership_added}"
    exit 1
  fi
  printf '%s\n' "result=rolled_back" "record=removed" "membership_added=no" "cli_socket_acceptance=no"
  exit 1
}

accept_socket() {
  local raw="" body="" status="" ping="" version=""
  local socket_path="" parent_path=""
  socket_path="$(host_path "$socket_rel")"
  parent_path="$(host_path "$parent_rel")"
  prepare_php_code 'clamav_socket_group_accept_main();' || return 1
  raw="$(
    set +e
    invoke_php "$socket_path" "$virus_gid"
    printf '\nsocket_command_status=%s\n' "${probe_php_status-}"
  )"
  status="$(printf '%s\n' "$raw" | awk '/^socket_command_status=/ { line = $0 } END { print line }')"
  status="${status#socket_command_status=}"
  body="$(printf '%s\n' "$raw" | awk '!/^socket_command_status=/')"
  if ! numeric_token "$status"; then
    printf '%s\n' "socket_command_status=unknown" "app_ping=failed" "app_version_daily=failed"
    return 1
  fi
  if [ "$status" = "124" ]; then
    printf '%s\n' "app_ping=timed_out" "app_version_daily=timed_out"
    return 1
  fi
  if [ "$status" != "0" ]; then
    printf '%s\n' "socket_command_status=${status}" "app_ping=failed" "app_version_daily=failed"
    return 1
  fi
  if printf '%s\n' "$body" | grep -qx 'process_identity_match=no'; then
    printf '%s\n' "process_identity_match=no" "app_ping=not_attempted" "app_version_daily=not_attempted"
    return 1
  fi
  if ! printf '%s\n' "$body" | grep -qx 'process_identity_match=yes'; then
    printf '%s\n' "process_identity_match=no" "app_ping=failed" "app_version_daily=failed"
    return 1
  fi
  ping="$(printf '%s\n' "$body" | awk -F= '$1 == "app_ping" { if (seen) exit 3; seen = 1; print $2 }')"
  version="$(printf '%s\n' "$body" | awk -F= '$1 == "app_version_daily" { if (seen) exit 3; seen = 1; print $2 }')"
  if [ "$ping" != "pong" ] || ! printf '%s' "$version" | grep -Eq '^(0|[1-9][0-9]*)$'; then
    printf '%s\n' "process_identity_match=yes" "app_ping=failed" "app_version_daily=failed"
    return 1
  fi
  printf '%s\n' "process_identity_match=yes" "app_ping=pong" "app_version_daily=${version}"
  if app_test r "$socket_path"; then
    printf '%s\n' "socket_dac_r=yes"
  else
    printf '%s\n' "socket_dac_r=no"
    return 1
  fi
  if app_test w "$socket_path"; then
    printf '%s\n' "socket_dac_w=yes"
  else
    printf '%s\n' "socket_dac_w=no"
    return 1
  fi
  if app_test r "$parent_path"; then
    printf '%s\n' "socket_parent_dac_r=yes"
    return 1
  fi
  printf '%s\n' "socket_parent_dac_r=no"
  if app_test w "$parent_path"; then
    printf '%s\n' "socket_parent_dac_w=yes"
    return 1
  fi
  printf '%s\n' "socket_parent_dac_w=no"
  if app_test x "$parent_path"; then
    printf '%s\n' "socket_parent_dac_x=yes"
  else
    printf '%s\n' "socket_parent_dac_x=no"
    return 1
  fi
  require_signature_dac || return 1
  return 0
}

preconditions() {
  require_release
  require_site_account
  measure_virus_gid
  require_node socket "$(host_path "$socket_rel")"
  require_node socket_parent "$(host_path "$parent_rel")"
  require_signature_files
  require_signature_dac || stop signature_dac
  local body=""
  body="$(read_identity || true)"
  if [ -z "$body" ] || ! identity_groups_match "$body" "$recorded_uid"; then
    stop posix_identity
  fi
  if [ "$virus_gid" = "$recorded_uid" ] || group_members_include_account; then
    if [ "$virus_gid" = "$recorded_uid" ] && ! group_members_include_account; then
      printf '%s\n' "result=already_member" "membership_added=no" "record=${record_state}" "cli_socket_acceptance=no"
      exit 0
    fi
    if [ "$virus_gid" = "$recorded_uid" ] && group_members_include_account; then
      printf '%s\n' "result=already_member" "membership_added=no" "record=${record_state}" "cli_socket_acceptance=no"
      exit 0
    fi
    stop membership_contradiction
  fi
}

apply_group() {
  local added_groups="" body=""
  if [ -L "$record" ] || [ -d "$record" ]; then
    stop record_type
  fi
  if [ -e "$record" ]; then
    record_state="kept"
    stop record_present
  fi
  preconditions
  prepare_php_code 'clamav_socket_group_accept_main();' || stop helper_code
  write_record || stop record_write
  if ! run_gpasswd -a "$account" virusgroup >/dev/null 2>&1; then
    rollback_membership
  fi
  added_groups="$(expected_added_groups)"
  body="$(read_identity || true)"
  if [ -z "$body" ] || ! identity_groups_match "$body" "$added_groups"; then
    rollback_membership
  fi
  if ! group_members_include_account; then
    rollback_membership
  fi
  if ! accept_socket; then
    rollback_membership
  fi
  printf '%s\n' "result=applied" "membership_added=yes" "record=kept" "cli_socket_acceptance=yes" "web_selinux_acceptance=no"
  exit 0
}

restore_group() {
  local body=""
  printf '%s\n' "running_processes_groups_unchanged=yes"
  if [ ! -e "$record" ] && [ ! -L "$record" ]; then
    stop record_absent
  fi
  record_state="kept"
  if [ -L "$record" ] || [ ! -f "$record" ]; then
    stop record_type
  fi
  require_release
  measure_virus_gid
  directory_protects_record "$shared" || stop record_directory
  record_metadata_ok "$record" || stop record_metadata
  record_shape_ok || stop record_fields
  require_node socket "$(host_path "$socket_rel")"
  require_node socket_parent "$(host_path "$parent_rel")"
  body="$(read_identity || true)"
  if [ -n "$body" ] && identity_groups_match "$body" "$recorded_uid"; then
    if group_members_include_account; then
      stop unexpected_groups
    fi
    remove_record || stop record_delete
    printf '%s\n' "result=restored" "membership_added=no" "record=${record_state}" "cli_socket_acceptance=no"
    exit 0
  fi
  if [ -z "$body" ] || ! identity_groups_match "$body" "$(expected_added_groups)"; then
    stop unexpected_groups
  fi
  if ! group_members_include_account; then
    stop unexpected_groups
  fi
  delete_failed=0
  if ! run_gpasswd -d "$account" virusgroup >/dev/null 2>&1; then
    delete_failed=1
  fi
  body="$(read_identity || true)"
  if [ "$delete_failed" -ne 0 ] || [ -z "$body" ] || ! identity_groups_match "$body" "$recorded_uid" || group_members_include_account; then
    printf '%s\n' "result=rollback_failed" "record=kept" "membership_added=yes"
    exit 1
  fi
  if ! remove_record; then
    printf '%s\n' "result=rollback_failed" "record=kept" "membership_added=no"
    exit 1
  fi
  printf '%s\n' "result=restored" "membership_added=no" "record=removed" "cli_socket_acceptance=no" "running_processes_groups_unchanged=yes"
  exit 0
}

if [ "$mode" = "apply" ]; then
  apply_group
fi
restore_group
