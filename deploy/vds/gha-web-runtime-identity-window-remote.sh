#!/usr/bin/env bash
# Open or restore only WEB_RUNTIME_IDENTITY_ENABLED and WEB_RUNTIME_IDENTITY_EXPIRES_AT.
# The expiry gate closes diagnostic access by itself. It does not restore env files.
# This script does not send HTTP, read process variables or the command line, or change OLS,
# groups, ACL, or any other setting. Fixture-only variables are
# TESTLIG_IDENTITY_WINDOW_ROOT, TESTLIG_IDENTITY_WINDOW_NOW, and
# TESTLIG_IDENTITY_WINDOW_FAIL. The workflow must not set them.
set -euo pipefail

root="${TESTLIG_IDENTITY_WINDOW_ROOT:-}"
mode="${IDENTITY_WINDOW_MODE:-}"
expected="${EXPECTED_SHA:-}"
site="${root}/home/testlig.net"
shared_env="${site}/shared/.env.local"
record="${site}/shared/web-runtime-identity-window.record"
app_link="${site}/app"
window_seconds=900
release_dir=""
release_env=""
changed_shared=0
changed_release=0
files_changed=no
record_state=absent
rollback_done=0

declare -A key_state=()
declare -A key_line=()

finish() {
  local status=$?
  set +e
  if [ "$rollback_done" -eq 0 ] && [ "$status" -ne 0 ] && { [ "$changed_shared" -eq 1 ] || [ "$changed_release" -eq 1 ]; }; then
    rollback_changes
    rollback_done=1
    status=1
  fi
  printf 'files_changed=%s\n' "$files_changed"
  printf 'record=%s\n' "$record_state"
  printf 'http_sent=no\n'
  printf 'process_env_read=no\n'
  exit "$status"
}
trap finish EXIT

stop() {
  printf 'stop_reason=%s\n' "$1"
  printf 'result=stopped\n'
  exit 1
}

require_sha() {
  [[ "$expected" =~ ^[0-9a-f]{40}$ ]] || stop release_sha
  [ -f "${site}/shared/CURRENT_REVISION" ] || stop release_sha
  local current
  current="$(tr -d '[:space:]' < "${site}/shared/CURRENT_REVISION")"
  [ "$current" = "$expected" ] || stop release_mismatch
  if [ -L "$app_link" ]; then
    release_dir="$(readlink -f "$app_link")"
  elif [ -n "$root" ] && [ -d "$app_link" ] && [ -f "${app_link}/REVISION" ]; then
    release_dir="$(readlink -f "$app_link")"
  else
    stop app_link
  fi
  case "$release_dir" in
    "${site}/releases/"*) ;;
    *)
      if [ -z "$root" ] || [ "$release_dir" != "$(readlink -f "$app_link")" ]; then
        stop release_dir
      fi
      ;;
  esac
  [ -f "${release_dir}/REVISION" ] || stop release_sha
  current="$(tr -d '[:space:]' < "${release_dir}/REVISION")"
  [ "$current" = "$expected" ] || stop release_mismatch
  release_env="${release_dir}/.env.local"
  [ -f "$shared_env" ] || stop shared_env
  [ -f "$release_env" ] || stop release_env
  if [ "$shared_env" -ef "$release_env" ]; then
    stop same_file
  fi
}

inspect_file() {
  local file="$1"
  local prefix="$2"
  local key line
  for key in WEB_RUNTIME_IDENTITY_ENABLED WEB_RUNTIME_IDENTITY_EXPIRES_AT; do
    key_state["${prefix}:${key}"]=absent
    key_line["${prefix}:${key}"]=""
  done
  while IFS= read -r line || [ -n "$line" ]; do
    case "$line" in
      \#*) continue ;;
    esac
    for key in WEB_RUNTIME_IDENTITY_ENABLED WEB_RUNTIME_IDENTITY_EXPIRES_AT; do
      case "$line" in
        "$key"=*)
          if [ "${key_state[${prefix}:${key}]}" != absent ]; then
            key_state["${prefix}:${key}"]=duplicate
          else
            key_state["${prefix}:${key}"]=present
            key_line["${prefix}:${key}"]="$line"
          fi
          ;;
        *"$key"*)
          key_state["${prefix}:${key}"]=ambiguous
          ;;
      esac
    done
  done < "$file"
}

refuse_unclear() {
  local prefix="$1"
  local key
  for key in WEB_RUNTIME_IDENTITY_ENABLED WEB_RUNTIME_IDENTITY_EXPIRES_AT; do
    case "${key_state[${prefix}:${key}]}" in
      absent|present) ;;
      duplicate) stop duplicate ;;
      *) stop ambiguous ;;
    esac
  done
}

refuse_higher_priority() {
  local path prefix key
  for path in "${release_dir}/.env.prod" "${release_dir}/.env.prod.local"; do
    [ -f "$path" ] || continue
    prefix="high:${path}"
    inspect_file "$path" "$prefix"
    for key in WEB_RUNTIME_IDENTITY_ENABLED WEB_RUNTIME_IDENTITY_EXPIRES_AT; do
      case "${key_state[${prefix}:${key}]}" in
        absent) ;;
        present) stop higher_priority ;;
        duplicate) stop duplicate ;;
        *) stop ambiguous ;;
      esac
    done
  done
}

refuse_php_dump() {
  if [ -e "${release_dir}/.env.local.php" ] || [ -e "${site}/shared/.env.local.php" ]; then
    stop php_dump
  fi
  printf 'php_dump=absent\n'
}

encode_line() {
  local prefix="$1"
  local key="$2"
  if [ "${key_state[${prefix}:${key}]}" = absent ]; then
    printf 'absent'
    return
  fi
  printf '%s' "${key_line[${prefix}:${key}]}" | base64 -w0
}

write_record() {
  local tmp
  tmp="$(mktemp "${record}.tmp.XXXXXX")"
  umask 077
  {
    printf 'format=1\n'
    printf 'release_sha=%s\n' "$expected"
    printf 'release_dir=%s\n' "$release_dir"
    printf 'shared_enabled=%s\n' "$(encode_line shared WEB_RUNTIME_IDENTITY_ENABLED)"
    printf 'shared_expires=%s\n' "$(encode_line shared WEB_RUNTIME_IDENTITY_EXPIRES_AT)"
    printf 'release_enabled=%s\n' "$(encode_line release WEB_RUNTIME_IDENTITY_ENABLED)"
    printf 'release_expires=%s\n' "$(encode_line release WEB_RUNTIME_IDENTITY_EXPIRES_AT)"
  } > "$tmp"
  mv -f "$tmp" "$record"
  record_state=kept
}

record_field() {
  local name="$1"
  local line value
  value=""
  while IFS= read -r line || [ -n "$line" ]; do
    case "$line" in
      "$name"=*) value="${line#"${name}"=}" ;;
    esac
  done < "$record"
  printf '%s' "$value"
}

decode_field() {
  local raw="$1"
  if [ "$raw" = absent ]; then
    printf ''
    return
  fi
  printf '%s' "$raw" | base64 -d
}

rewrite_file() {
  local file="$1"
  local enabled_raw="$2"
  local expires_raw="$3"
  local tmp line saw_enabled=0 saw_expires=0
  tmp="$(mktemp)"
  while IFS= read -r line || [ -n "$line" ]; do
    case "$line" in
      WEB_RUNTIME_IDENTITY_ENABLED=*)
        saw_enabled=1
        if [ "$enabled_raw" != absent ]; then
          printf '%s\n' "$(decode_field "$enabled_raw")" >> "$tmp"
        fi
        ;;
      WEB_RUNTIME_IDENTITY_EXPIRES_AT=*)
        saw_expires=1
        if [ "$expires_raw" != absent ]; then
          printf '%s\n' "$(decode_field "$expires_raw")" >> "$tmp"
        fi
        ;;
      *)
        printf '%s\n' "$line" >> "$tmp"
        ;;
    esac
  done < "$file"
  if [ "$saw_enabled" -eq 0 ] && [ "$enabled_raw" != absent ]; then
    printf '%s\n' "$(decode_field "$enabled_raw")" >> "$tmp"
  fi
  if [ "$saw_expires" -eq 0 ] && [ "$expires_raw" != absent ]; then
    printf '%s\n' "$(decode_field "$expires_raw")" >> "$tmp"
  fi
  cat "$tmp" > "$file" || return 1
  rm -f "$tmp"
  files_changed=yes
}

line_matches() {
  local prefix="$1"
  local key="$2"
  local raw="$3"
  if [ "$raw" = absent ]; then
    [ "${key_state[${prefix}:${key}]}" = absent ]
    return
  fi
  [ "${key_state[${prefix}:${key}]}" = present ] || return 1
  [ "${key_line[${prefix}:${key}]}" = "$(decode_field "$raw")" ]
}

verify_record() {
  local shared_enabled shared_expires release_enabled release_expires
  shared_enabled="$(record_field shared_enabled)"
  shared_expires="$(record_field shared_expires)"
  release_enabled="$(record_field release_enabled)"
  release_expires="$(record_field release_expires)"
  inspect_file "$shared_env" shared
  inspect_file "$release_env" release
  line_matches shared WEB_RUNTIME_IDENTITY_ENABLED "$shared_enabled" || return 1
  line_matches shared WEB_RUNTIME_IDENTITY_EXPIRES_AT "$shared_expires" || return 1
  line_matches release WEB_RUNTIME_IDENTITY_ENABLED "$release_enabled" || return 1
  line_matches release WEB_RUNTIME_IDENTITY_EXPIRES_AT "$release_expires" || return 1
}

rollback_changes() {
  local shared_enabled shared_expires release_enabled release_expires
  shared_enabled="$(record_field shared_enabled)"
  shared_expires="$(record_field shared_expires)"
  release_enabled="$(record_field release_enabled)"
  release_expires="$(record_field release_expires)"
  if [ "$changed_shared" -eq 1 ]; then
    rewrite_file "$shared_env" "$shared_enabled" "$shared_expires" || return 1
  fi
  if [ "$changed_release" -eq 1 ]; then
    rewrite_file "$release_env" "$release_enabled" "$release_expires" || return 1
  fi
  if verify_record; then
    rm -f "$record"
    if [ -e "$record" ]; then
      record_state=kept
      printf 'rollback=failed\n'
      return 1
    fi
    changed_shared=0
    changed_release=0
    files_changed=no
    record_state=removed
    printf 'rollback=verified\n'
    return 1
  fi
  record_state=kept
  printf 'rollback=failed\n'
  printf 'rollback_shared_enabled=%s\n' "${key_state[shared:WEB_RUNTIME_IDENTITY_ENABLED]:-unset}"
  printf 'rollback_shared_expires=%s\n' "${key_state[shared:WEB_RUNTIME_IDENTITY_EXPIRES_AT]:-unset}"
  printf 'rollback_release_enabled=%s\n' "${key_state[release:WEB_RUNTIME_IDENTITY_ENABLED]:-unset}"
  printf 'rollback_release_expires=%s\n' "${key_state[release:WEB_RUNTIME_IDENTITY_EXPIRES_AT]:-unset}"
  if [ "$shared_enabled" = absent ]; then
    printf 'rollback_record_shared_enabled=absent\n'
  else
    printf 'rollback_record_shared_enabled=encoded\n'
  fi
  if [ "$release_expires" = absent ]; then
    printf 'rollback_record_release_expires=absent\n'
  else
    printf 'rollback_record_release_expires=encoded\n'
  fi
  return 1
}

open_window() {
  [ ! -e "$record" ] || stop record_present
  local now expires enabled_b64 expires_b64
  if [ -n "${TESTLIG_IDENTITY_WINDOW_NOW:-}" ]; then
    now="${TESTLIG_IDENTITY_WINDOW_NOW}"
  else
    now="$(date -u +%s)"
  fi
  [[ "$now" =~ ^[1-9][0-9]{0,10}$ ]] || stop clock
  expires="$((now + window_seconds))"
  [[ "$expires" =~ ^[1-9][0-9]{0,10}$ ]] || stop clock
  inspect_file "$shared_env" shared
  inspect_file "$release_env" release
  refuse_unclear shared
  refuse_unclear release
  printf 'shared_enabled_before=%s\n' "${key_state[shared:WEB_RUNTIME_IDENTITY_ENABLED]}"
  printf 'shared_expires_before=%s\n' "${key_state[shared:WEB_RUNTIME_IDENTITY_EXPIRES_AT]}"
  printf 'release_enabled_before=%s\n' "${key_state[release:WEB_RUNTIME_IDENTITY_ENABLED]}"
  printf 'release_expires_before=%s\n' "${key_state[release:WEB_RUNTIME_IDENTITY_EXPIRES_AT]}"
  write_record
  changed_shared=1
  enabled_b64="$(printf '%s' 'WEB_RUNTIME_IDENTITY_ENABLED=1' | base64 -w0)"
  expires_b64="$(printf '%s' "WEB_RUNTIME_IDENTITY_EXPIRES_AT=${expires}" | base64 -w0)"
  rewrite_file "$shared_env" "$enabled_b64" "$expires_b64"
  if [ "${TESTLIG_IDENTITY_WINDOW_FAIL:-}" = after_shared ]; then
    return 1
  fi
  changed_release=1
  rewrite_file "$release_env" "$enabled_b64" "$expires_b64"
  inspect_file "$shared_env" shared
  inspect_file "$release_env" release
  [ "${key_line[shared:WEB_RUNTIME_IDENTITY_ENABLED]}" = "WEB_RUNTIME_IDENTITY_ENABLED=1" ] || return 1
  [ "${key_line[shared:WEB_RUNTIME_IDENTITY_EXPIRES_AT]}" = "WEB_RUNTIME_IDENTITY_EXPIRES_AT=${expires}" ] || return 1
  [ "${key_line[release:WEB_RUNTIME_IDENTITY_ENABLED]}" = "WEB_RUNTIME_IDENTITY_ENABLED=1" ] || return 1
  [ "${key_line[release:WEB_RUNTIME_IDENTITY_EXPIRES_AT]}" = "WEB_RUNTIME_IDENTITY_EXPIRES_AT=${expires}" ] || return 1
  changed_shared=0
  changed_release=0
  printf 'expires_window_seconds=%s\n' "$window_seconds"
  printf 'result=opened\n'
  record_state=kept
}

restore_window() {
  [ -f "$record" ] || stop record_absent
  record_state=kept
  local recorded_sha recorded_dir
  recorded_sha="$(record_field release_sha)"
  recorded_dir="$(record_field release_dir)"
  [ "$recorded_sha" = "$expected" ] || stop record_release
  [ "$recorded_dir" = "$release_dir" ] || stop record_release
  inspect_file "$shared_env" shared
  inspect_file "$release_env" release
  refuse_unclear shared
  refuse_unclear release
  if verify_record; then
    rm -f "$record"
    [ ! -e "$record" ] || stop record_kept
    record_state=removed
    files_changed=no
    printf 'result=restored\n'
    return 0
  fi
  changed_shared=1
  rewrite_file "$shared_env" "$(record_field shared_enabled)" "$(record_field shared_expires)"
  changed_release=1
  rewrite_file "$release_env" "$(record_field release_enabled)" "$(record_field release_expires)"
  verify_record || return 1
  rm -f "$record"
  [ ! -e "$record" ] || stop record_kept
  changed_shared=0
  changed_release=0
  record_state=removed
  printf 'result=restored\n'
}

case "$mode" in
  open|restore) ;;
  *) stop mode ;;
esac

require_sha
refuse_php_dump
refuse_higher_priority
printf 'higher_priority=clear\n'
printf 'mode=%s\n' "$mode"
printf 'release_sha=%s\n' "$expected"

if [ "$mode" = open ]; then
  if ! open_window; then
    rollback_changes
    rollback_done=1
    exit 1
  fi
else
  if ! restore_window; then
    rollback_changes
    rollback_done=1
    exit 1
  fi
fi
