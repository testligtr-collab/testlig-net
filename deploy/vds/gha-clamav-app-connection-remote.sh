#!/usr/bin/env bash
# Open or restore only the question-image ClamAV keys and the connection-check gate.
# File writes are not web acceptance. The expiry gate does not restore env files.
# This script does not change group membership, the group recovery record, ACL,
# packages, providers, or process environ. It does not scan an asset.
# Fixture-only: TESTLIG_CLAMAV_LINK_ROOT, TESTLIG_CLAMAV_LINK_NOW,
# TESTLIG_CLAMAV_LINK_FAIL, TESTLIG_CLAMAV_LINK_MODE_LEDGER.
set -euo pipefail

root="${TESTLIG_CLAMAV_LINK_ROOT:-}"
mode="${CLAMAV_LINK_MODE:-}"
expected="${EXPECTED_SHA:-}"
site="${root}/home/testlig.net"
shared="${site}/shared"
shared_env="${shared}/.env.local"
record="${shared}/clamav-app-connection.record"
app_link="${site}/app"
window_seconds=900
release_dir=""
release_env=""
changed_shared=0
changed_release=0
files_changed=no
record_state=absent
rollback_done=0
socket_path=""
database_dir=""
virus_gid=""
expires=""
record_uid=0
record_gid=0
keys=(
  QUESTION_IMAGE_CLAMAV_SOCKET
  QUESTION_IMAGE_CLAMAV_DATABASE
  QUESTION_IMAGE_CLAMAV_LINK_ENABLED
  QUESTION_IMAGE_CLAMAV_LINK_EXPIRES_AT
  QUESTION_IMAGE_CLAMAV_LINK_GROUP_GID
)
declare -A key_state=()
declare -A key_line=()
declare -A desired=()

finish() {
  local status=$?
  if [ "$rollback_done" -eq 0 ] && [ "$status" -ne 0 ] && { [ "$changed_shared" -eq 1 ] || [ "$changed_release" -eq 1 ]; }; then
    rollback_files || true
    rollback_done=1
    status=1
  fi
  printf 'files_changed=%s\n' "$files_changed"
  printf 'record=%s\n' "$record_state"
  printf 'group_record=untouched\n'
  printf 'worker_process_env=unknown\n'
  printf 'gate_restores_env=no\n'
  printf 'process_env_read=no\n'
  exit "$status"
}
trap finish EXIT

stop() {
  printf 'stop_reason=%s\n' "$1"
  printf 'result=stopped\n'
  printf 'web_acceptance=no\n'
  exit 1
}

fail_is() {
  [ -n "$root" ] || return 1
  case ",${TESTLIG_CLAMAV_LINK_FAIL:-}," in
    *",$1,"*) return 0 ;;
  esac
  return 1
}

read_mode() {
  local target="$1"
  local entry_path="" entry_mode="" matched=""
  if [ -n "$root" ] && [ -n "${TESTLIG_CLAMAV_LINK_MODE_LEDGER:-}" ] && [ -f "${TESTLIG_CLAMAV_LINK_MODE_LEDGER}" ]; then
    while IFS=' ' read -r entry_path entry_mode || [ -n "${entry_path}" ]; do
      if [ "$entry_path" = "$target" ]; then
        matched="$entry_mode"
      fi
    done < "${TESTLIG_CLAMAV_LINK_MODE_LEDGER}" || true
    if [ -n "$matched" ]; then
      printf '%s' "$matched"
      return 0
    fi
  fi
  stat -c %a "$target"
}

apply_mode() {
  local file="$1"
  local mode_value="$2"
  local got=""
  chmod "$mode_value" "$file" || return 1
  if [ -n "$root" ] && [ -n "${TESTLIG_CLAMAV_LINK_MODE_LEDGER:-}" ]; then
    printf '%s %s\n' "$file" "$mode_value" >> "${TESTLIG_CLAMAV_LINK_MODE_LEDGER}" || return 1
  fi
  got="$(read_mode "$file")" || return 1
  [ "$got" = "$mode_value" ]
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
  release_env="${release_dir}/.env.local"
  [ -f "$release_env" ] && [ ! -L "$release_env" ] || stop release_env
  [ -f "$shared_env" ] && [ ! -L "$shared_env" ] || stop shared_env
}

measure_paths() {
  local conf="${root}/etc/clamd.d/scan.conf"
  local socket_count=0 database_count=0 line=""
  [ -f "$conf" ] && [ ! -L "$conf" ] || stop clamav_config
  while IFS= read -r line || [ -n "$line" ]; do
    case "$line" in
      ''|\#*) continue ;;
      LocalSocket[[:space:]]*)
        socket_count=$((socket_count + 1))
        socket_path="${line#LocalSocket}"
        socket_path="${socket_path#"${socket_path%%[![:space:]]*}"}"
        ;;
      DatabaseDirectory[[:space:]]*)
        database_count=$((database_count + 1))
        database_dir="${line#DatabaseDirectory}"
        database_dir="${database_dir#"${database_dir%%[![:space:]]*}"}"
        ;;
    esac
  done < "$conf"
  [ "$socket_count" -eq 1 ] || stop socket_path
  [ "$database_count" -eq 1 ] || stop database_dir
  printf '%s' "$socket_path" | grep -Eq '^/[A-Za-z0-9._/-]+$' || stop socket_path
  printf '%s' "$database_dir" | grep -Eq '^/[A-Za-z0-9._/-]+$' || stop database_dir
  case "$socket_path" in
    *..*) stop socket_path ;;
  esac
  case "$database_dir" in
    *..*) stop database_dir ;;
  esac
  [ -S "$socket_path" ] && [ ! -L "$socket_path" ] || stop socket_path
  [ -d "$database_dir" ] && [ ! -L "$database_dir" ] || stop database_dir
  printf 'socket_measured=yes\ndatabase_measured=yes\n'
}

measure_gid() {
  local line="" name="" gid="" count=0 source=""
  if [ -n "$root" ]; then
    source="${root}/getent-virusgroup"
    [ -f "$source" ] || stop virusgroup_gid
  fi
  if [ -n "$root" ]; then
    while IFS= read -r line || [ -n "$line" ]; do
      count=$((count + 1))
      [ "$count" -eq 1 ] || stop virusgroup_gid
      IFS=: read -r name _ gid _ <<EOF
$line
EOF
    done < "$source"
  else
    while IFS= read -r line || [ -n "$line" ]; do
      count=$((count + 1))
      [ "$count" -eq 1 ] || stop virusgroup_gid
      IFS=: read -r name _ gid _ <<EOF
$line
EOF
    done < <(getent group virusgroup 2>/dev/null || true)
  fi
  [ "$count" -eq 1 ] || stop virusgroup_gid
  [ "$name" = "virusgroup" ] || stop virusgroup_gid
  printf '%s' "$gid" | grep -Eq '^[1-9][0-9]*$' || stop virusgroup_gid
  [ "$gid" != "5002" ] || stop virusgroup_gid
  virus_gid="$gid"
  printf 'virusgroup_gid_measured=yes\n'
}

directory_protects_record() {
  local dir="$1"
  local uid="" dir_mode="" dec=0 sticky=0 writable=0
  if [ -L "$dir" ] || [ ! -d "$dir" ]; then
    return 1
  fi
  uid="$(stat -c %u "$dir")" || return 1
  dir_mode="$(read_mode "$dir")" || return 1
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
  directory_protects_record "$shared" || return 1
  if [ -L "$target" ] || [ ! -f "$target" ]; then
    return 1
  fi
  meta_uid="$(stat -c %u "$target")" || return 1
  meta_gid="$(stat -c %g "$target")" || return 1
  meta_mode="$(read_mode "$target")" || return 1
  [ "$meta_uid" = "$record_uid" ] || return 1
  [ "$meta_gid" = "$record_gid" ] || return 1
  [ "$meta_mode" = "600" ] || return 1
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

inspect_file() {
  local file="$1"
  local prefix="$2"
  local key="" line=""
  for key in "${keys[@]}"; do
    key_state["${prefix}:${key}"]=absent
    key_line["${prefix}:${key}"]=""
  done
  while IFS= read -r line || [ -n "$line" ]; do
    case "$line" in
      \#*) continue ;;
    esac
    for key in "${keys[@]}"; do
      case "$line" in
        "${key}="*)
          if [ "${key_state[${prefix}:${key}]}" != absent ]; then
            key_state["${prefix}:${key}"]=duplicate
          else
            key_state["${prefix}:${key}"]=present
            key_line["${prefix}:${key}"]="$line"
          fi
          ;;
        *"${key}"*)
          key_state["${prefix}:${key}"]=ambiguous
          ;;
      esac
    done
  done < "$file"
}

refuse_unclear() {
  local prefix="$1"
  local key=""
  for key in "${keys[@]}"; do
    case "${key_state[${prefix}:${key}]}" in
      absent|present) ;;
      duplicate) stop duplicate ;;
      *) stop ambiguous ;;
    esac
  done
}

refuse_higher_priority() {
  local path="" prefix="" key=""
  if [ -e "${release_dir}/.env.local.php" ] || [ -e "${shared}/.env.local.php" ]; then
    stop php_dump
  fi
  for path in "${release_dir}/.env.prod" "${release_dir}/.env.prod.local"; do
    [ -f "$path" ] || continue
    prefix="high"
    inspect_file "$path" "$prefix"
    for key in "${keys[@]}"; do
      case "${key_state[${prefix}:${key}]}" in
        absent) ;;
        present|duplicate) stop higher_priority ;;
        *) stop ambiguous ;;
      esac
    done
  done
  printf 'higher_priority=clear\n'
  printf 'php_dump=absent\n'
}

encode_line() {
  local prefix="$1"
  local key="$2"
  if [ "${key_state[${prefix}:${key}]}" = absent ]; then
    printf 'absent'
    return 0
  fi
  printf '%s' "${key_line[${prefix}:${key}]}" | base64 -w0
}

write_record() {
  local tmp="" key=""
  directory_protects_record "$shared" || stop record_directory
  if [ -e "$record" ] || [ -L "$record" ]; then
    stop record_present
  fi
  tmp="$(mktemp "${shared}/.clamav-app-connection.XXXXXX")" || return 1
  if [ -L "$tmp" ] || [ ! -f "$tmp" ]; then
    rm -f "$tmp"
    return 1
  fi
  {
    printf 'format=1\n'
    printf 'release_sha=%s\n' "$expected"
    for key in "${keys[@]}"; do
      printf 'shared_%s=%s\n' "$key" "$(encode_line shared "$key")"
      printf 'release_%s=%s\n' "$key" "$(encode_line release "$key")"
    done
  } > "$tmp" || { rm -f "$tmp"; return 1; }
  apply_mode "$tmp" 600 || { rm -f "$tmp"; return 1; }
  record_metadata_ok "$tmp" || { rm -f "$tmp"; return 1; }
  mv -f "$tmp" "$record" || { rm -f "$tmp"; return 1; }
  apply_mode "$record" 600 || return 1
  record_metadata_ok "$record" || return 1
  record_state=kept
}

prepare_desired() {
  desired[QUESTION_IMAGE_CLAMAV_SOCKET]="QUESTION_IMAGE_CLAMAV_SOCKET=${socket_path}"
  desired[QUESTION_IMAGE_CLAMAV_DATABASE]="QUESTION_IMAGE_CLAMAV_DATABASE=${database_dir}"
  desired[QUESTION_IMAGE_CLAMAV_LINK_ENABLED]="QUESTION_IMAGE_CLAMAV_LINK_ENABLED=1"
  desired[QUESTION_IMAGE_CLAMAV_LINK_EXPIRES_AT]="QUESTION_IMAGE_CLAMAV_LINK_EXPIRES_AT=${expires}"
  desired[QUESTION_IMAGE_CLAMAV_LINK_GROUP_GID]="QUESTION_IMAGE_CLAMAV_LINK_GROUP_GID=${virus_gid}"
}

apply_desired_from_record() {
  local prefix="$1"
  local key="" raw="" decoded=""
  for key in "${keys[@]}"; do
    raw="$(record_field "${prefix}_${key}")" || return 1
    if [ "$raw" = absent ]; then
      desired[$key]=""
      continue
    fi
    decoded="$(printf '%s' "$raw" | base64 -d)" || return 1
    case "$decoded" in
      "${key}="*) desired[$key]="$decoded" ;;
      *) return 1 ;;
    esac
  done
}

write_from_desired() {
  local file="$1"
  local prefix="$2"
  local tmp="" line="" key="" used=""
  tmp="$(mktemp "$(dirname "$file")/.clamav-app-connection.XXXXXX")" || return 1
  while IFS= read -r line || [ -n "$line" ]; do
    used=0
    for key in "${keys[@]}"; do
      case "$line" in
        "${key}="*)
          if [ -n "${desired[$key]}" ]; then
            printf '%s\n' "${desired[$key]}" >> "$tmp"
          fi
          used=1
          ;;
      esac
    done
    if [ "$used" -eq 0 ]; then
      printf '%s\n' "$line" >> "$tmp"
    fi
  done < "$file"
  for key in "${keys[@]}"; do
    if [ -n "${desired[$key]}" ] && ! grep -q "^${key}=" "$tmp"; then
      printf '%s\n' "${desired[$key]}" >> "$tmp"
    fi
  done
  mv -f "$tmp" "$file" || { rm -f "$tmp"; return 1; }
  inspect_file "$file" "$prefix" || return 1
}

verify_desired() {
  local prefix="$1"
  local key=""
  for key in "${keys[@]}"; do
    if [ "${key_line[${prefix}:${key}]}" != "${desired[$key]}" ]; then
      return 1
    fi
  done
}

recycle_ols() {
  if [ -n "$root" ]; then
    printf 'ols_recycle=not_run\n'
    return 0
  fi
  if [ ! -x /usr/local/lsws/bin/lswsctrl ]; then
    printf 'ols_recycle=missing\n'
    return 1
  fi
  /usr/local/lsws/bin/lswsctrl stop >/dev/null 2>&1 || return 1
  sleep 1
  /usr/local/lsws/bin/lswsctrl start >/dev/null 2>&1 || return 1
  printf 'ols_recycle=yes\n'
}

rollback_files() {
  local shared_failed=0 release_failed=0
  if [ ! -f "$record" ]; then
    record_state=kept
    printf 'rollback=failed\n'
    return 1
  fi
  apply_desired_from_record shared || { record_state=kept; printf 'rollback=failed\n'; return 1; }
  if [ "$changed_shared" -eq 1 ]; then
    write_from_desired "$shared_env" shared || shared_failed=1
  fi
  apply_desired_from_record release || { record_state=kept; printf 'rollback=failed\n'; return 1; }
  if [ "$changed_release" -eq 1 ]; then
    write_from_desired "$release_env" release || release_failed=1
  fi
  if [ "$shared_failed" -ne 0 ] || [ "$release_failed" -ne 0 ]; then
    record_state=kept
    printf 'rollback=failed\n'
    return 1
  fi
  if fail_is record_delete; then
    record_state=kept
    printf 'rollback=verified\n'
    return 1
  fi
  rm -f "$record" || { record_state=kept; printf 'rollback=failed\n'; return 1; }
  if [ -e "$record" ]; then
    record_state=kept
    printf 'rollback=failed\n'
    return 1
  fi
  record_state=removed
  changed_shared=0
  changed_release=0
  files_changed=no
  printf 'rollback=verified\n'
  return 0
}

apply_connection() {
  local now="" key=""
  if [ -e "$record" ] || [ -L "$record" ]; then
    stop record_present
  fi
  measure_paths
  measure_gid
  if [ -n "${TESTLIG_CLAMAV_LINK_NOW:-}" ]; then
    now="${TESTLIG_CLAMAV_LINK_NOW}"
  else
    now="$(date -u +%s)" || stop clock
  fi
  printf '%s' "$now" | grep -Eq '^[1-9][0-9]{0,10}$' || stop clock
  expires="$((now + window_seconds))"
  printf '%s' "$expires" | grep -Eq '^[1-9][0-9]{0,10}$' || stop clock
  inspect_file "$shared_env" shared
  inspect_file "$release_env" release
  refuse_unclear shared
  refuse_unclear release
  write_record
  prepare_desired
  write_from_desired "$shared_env" shared || return 1
  changed_shared=1
  files_changed=yes
  if fail_is after_shared; then
    return 1
  fi
  write_from_desired "$release_env" release || return 1
  changed_release=1
  inspect_file "$shared_env" shared
  inspect_file "$release_env" release
  verify_desired shared || return 1
  verify_desired release || return 1
  recycle_ols || return 1
  changed_shared=0
  changed_release=0
  printf 'expires_window_seconds=%s\n' "$window_seconds"
  printf 'historical_release_sha=%s\n' "$expected"
  printf 'result=opened\n'
  printf 'web_acceptance=pending\n'
  record_state=kept
}

restore_connection() {
  local historical=""
  if [ ! -f "$record" ] || [ -L "$record" ]; then
    stop record_absent
  fi
  record_state=kept
  record_metadata_ok "$record" || stop record_metadata
  historical="$(record_field release_sha)" || stop record_shape
  printf '%s' "$historical" | grep -Eq '^[0-9a-f]{40}$' || stop record_shape
  [ "$(record_field format)" = "1" ] || stop record_shape
  printf 'historical_release_sha=%s\n' "$historical"
  inspect_file "$shared_env" shared
  inspect_file "$release_env" release
  refuse_unclear shared
  refuse_unclear release
  apply_desired_from_record shared || stop record_shape
  write_from_desired "$shared_env" shared || return 1
  changed_shared=1
  files_changed=yes
  apply_desired_from_record release || return 1
  write_from_desired "$release_env" release || return 1
  changed_release=1
  inspect_file "$shared_env" shared
  inspect_file "$release_env" release
  verify_desired shared || return 1
  verify_desired release || return 1
  recycle_ols || return 1
  if fail_is record_delete; then
    record_state=kept
    return 1
  fi
  rm -f "$record" || { record_state=kept; return 1; }
  if [ -e "$record" ]; then
    record_state=kept
    return 1
  fi
  changed_shared=0
  changed_release=0
  record_state=removed
  printf 'result=restored\n'
  printf 'web_acceptance=no\n'
}

case "$mode" in
  apply|restore) ;;
  *) stop mode ;;
esac

require_release
refuse_higher_priority
printf 'mode=%s\n' "$mode"
printf 'release_sha=%s\n' "$expected"

set +e
if [ "$mode" = apply ]; then
  apply_connection
else
  restore_connection
fi
status=$?
set -e
if [ "$status" -ne 0 ]; then
  printf 'result=stopped\n'
  printf 'web_acceptance=no\n'
  exit 1
fi
