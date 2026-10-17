#!/usr/bin/env bash
# Open or restore only WEB_RUNTIME_IDENTITY_ENABLED and WEB_RUNTIME_IDENTITY_EXPIRES_AT.
# The expiry gate closes diagnostic access by itself. It does not restore env files.
# This script does not send HTTP, read process variables or the command line, or change OLS,
# groups, ACL, or any other setting. Env files are replaced atomically.
# The recovery record is mode 0600 and owned by root, and its directory must
# not let another user replace that record. Fixture-only variables are
# TESTLIG_IDENTITY_WINDOW_ROOT, TESTLIG_IDENTITY_WINDOW_NOW,
# TESTLIG_IDENTITY_WINDOW_FAIL, TESTLIG_IDENTITY_WINDOW_RECORD_UID,
# TESTLIG_IDENTITY_WINDOW_RECORD_GID, TESTLIG_IDENTITY_WINDOW_CONTEXT, and
# TESTLIG_IDENTITY_WINDOW_MODE_LEDGER.
# The workflow must not set them.
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
rollback_active=0
last_replace_changed=0
record_uid=0
record_gid=0
if [ -n "$root" ]; then
  if [ -n "${TESTLIG_IDENTITY_WINDOW_RECORD_UID:-}" ]; then
    record_uid="${TESTLIG_IDENTITY_WINDOW_RECORD_UID}"
  fi
  if [ -n "${TESTLIG_IDENTITY_WINDOW_RECORD_GID:-}" ]; then
    record_gid="${TESTLIG_IDENTITY_WINDOW_RECORD_GID}"
  fi
fi

declare -A key_state=()
declare -A key_line=()

cleanup_identity_temps() {
  local dir="" path=""
  for dir in "${site}/shared" ${release_dir:+"$release_dir"}; do
    [ -d "$dir" ] || continue
    for path in "$dir"/.identity-window.*; do
      if [ -e "$path" ] || [ -L "$path" ]; then
        rm -f "$path"
      fi
    done
  done
}

finish() {
  local status=$?
  set +e
  cleanup_identity_temps
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
  if [ -L "$shared_env" ] || [ ! -f "$shared_env" ]; then
    stop file_type
  fi
  if [ -L "$release_env" ] || [ ! -f "$release_env" ]; then
    stop file_type
  fi
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
  return 0
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
    return 0
  fi
  printf '%s' "${key_line[${prefix}:${key}]}" | base64 -w0
  return $?
}

fail_is() {
  [ "${TESTLIG_IDENTITY_WINDOW_FAIL:-}" = "$1" ]
  return $?
}

cleanup_temp() {
  local path="$1"
  if [ -z "$path" ]; then
    return 0
  fi
  if [ -e "$path" ] || [ -L "$path" ]; then
    rm -f "$path"
    if [ $? -ne 0 ] || [ -e "$path" ]; then
      return 1
    fi
  fi
  return 0
}

read_context() {
  local file="$1"
  local ctx=""
  if [ -n "$root" ] && [ -n "${TESTLIG_IDENTITY_WINDOW_CONTEXT:-}" ]; then
    printf '%s' "${TESTLIG_IDENTITY_WINDOW_CONTEXT}"
    return 0
  fi
  ctx="$(stat -c %C "$file" 2>/dev/null)" || true
  case "$ctx" in
    ""|"?"|"-"|"unlabeled") printf '' ;;
    *) printf '%s' "$ctx" ;;
  esac
  return 0
}

read_mode() {
  local target="$1"
  local entry_path="" entry_mode="" matched=""
  if [ -n "$root" ] && [ -n "${TESTLIG_IDENTITY_WINDOW_MODE_LEDGER:-}" ] && [ -f "${TESTLIG_IDENTITY_WINDOW_MODE_LEDGER}" ]; then
    while IFS=' ' read -r entry_path entry_mode || [ -n "$entry_path" ]; do
      if [ "$entry_path" = "$target" ]; then
        matched="$entry_mode"
      fi
    done < "${TESTLIG_IDENTITY_WINDOW_MODE_LEDGER}" || true
    if [ -n "$matched" ]; then
      printf '%s' "$matched"
      return 0
    fi
  fi
  stat -c %a "$target"
}

apply_mode() {
  local file="$1"
  local mode="$2"
  local got=""
  chmod "$mode" "$file" || return 1
  if [ -n "$root" ] && [ -n "${TESTLIG_IDENTITY_WINDOW_MODE_LEDGER:-}" ]; then
    printf '%s %s\n' "$file" "$mode" >> "${TESTLIG_IDENTITY_WINDOW_MODE_LEDGER}" || return 1
  fi
  got="$(read_mode "$file")" || return 1
  [ "$got" = "$mode" ]
}

directory_protects_record() {
  local dir="$1"
  local uid="" dir_mode="" dec=0 sticky=0 writable=0
  if [ -L "$dir" ] || [ ! -d "$dir" ]; then
    return 1
  fi
  uid="$(stat -c %u "$dir")" || return 1
  dir_mode="$(read_mode "$dir")" || return 1
  if [ "$uid" != "$record_uid" ]; then
    return 1
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
  meta_uid="$(stat -c %u "$target")" || return 1
  meta_gid="$(stat -c %g "$target")" || return 1
  meta_mode="$(read_mode "$target")" || return 1
  if [ "$meta_uid" != "$record_uid" ] || [ "$meta_gid" != "$record_gid" ] || [ "$meta_mode" != "600" ]; then
    return 1
  fi
  directory_protects_record "${site}/shared"
  return $?
}

write_record() {
  set +e
  local tmp="" dir="" uid="" gid=""
  dir="${site}/shared"
  if fail_is record_write; then
    return 1
  fi
  if [ -L "$dir" ] || [ ! -d "$dir" ]; then
    return 1
  fi
  if ! directory_protects_record "$dir"; then
    printf 'stop_reason=record_directory\n'
    return 1
  fi
  tmp="$(mktemp "${dir}/.identity-window.XXXXXX")"
  if [ $? -ne 0 ] || [ -z "$tmp" ] || [ ! -f "$tmp" ] || [ -L "$tmp" ]; then
    cleanup_temp "$tmp"
    return 1
  fi
  {
    printf 'format=1\n'
    printf 'release_sha=%s\n' "$expected"
    printf 'release_dir=%s\n' "$release_dir"
    printf 'shared_enabled=%s\n' "$(encode_line shared WEB_RUNTIME_IDENTITY_ENABLED)"
    printf 'shared_expires=%s\n' "$(encode_line shared WEB_RUNTIME_IDENTITY_EXPIRES_AT)"
    printf 'release_enabled=%s\n' "$(encode_line release WEB_RUNTIME_IDENTITY_ENABLED)"
    printf 'release_expires=%s\n' "$(encode_line release WEB_RUNTIME_IDENTITY_EXPIRES_AT)"
  } > "$tmp"
  if [ $? -ne 0 ]; then
    cleanup_temp "$tmp"
    return 1
  fi
  uid="$(stat -c %u "$tmp")" || { cleanup_temp "$tmp"; return 1; }
  gid="$(stat -c %g "$tmp")" || { cleanup_temp "$tmp"; return 1; }
  if [ "$uid" != "$record_uid" ] || [ "$gid" != "$record_gid" ]; then
    chown "${record_uid}:${record_gid}" "$tmp"
    if [ $? -ne 0 ]; then
      cleanup_temp "$tmp"
      return 1
    fi
  fi
  if ! apply_mode "$tmp" 600; then
    cleanup_temp "$tmp"
    return 1
  fi
  if ! record_metadata_ok "$tmp"; then
    cleanup_temp "$tmp"
    return 1
  fi
  if fail_is record_move; then
    cleanup_temp "$tmp"
    return 1
  fi
  mv -f "$tmp" "$record"
  if [ $? -ne 0 ]; then
    cleanup_temp "$tmp"
    return 1
  fi
  if ! apply_mode "$record" 600; then
    rm -f "$record"
    return 1
  fi
  if ! record_metadata_ok "$record"; then
    rm -f "$record"
    return 1
  fi
  record_state=kept
  printf 'record_mode=600\n'
  printf 'record_directory=safe\n'
  return 0
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

copy_metadata() {
  local src="$1"
  local dest="$2"
  local su="" sg="" sm="" du="" dg="" dm="" ctx=""
  su="$(stat -c %u "$src")" || return 1
  sg="$(stat -c %g "$src")" || return 1
  sm="$(read_mode "$src")" || return 1
  du="$(stat -c %u "$dest")" || return 1
  dg="$(stat -c %g "$dest")" || return 1
  if [ "$du" != "$su" ] || [ "$dg" != "$sg" ]; then
    chown "${su}:${sg}" "$dest"
    if [ $? -ne 0 ]; then
      return 1
    fi
  fi
  if ! apply_mode "$dest" "$sm"; then
    return 1
  fi
  du="$(stat -c %u "$dest")" || return 1
  dg="$(stat -c %g "$dest")" || return 1
  dm="$(read_mode "$dest")" || return 1
  if [ "$du" != "$su" ] || [ "$dg" != "$sg" ] || [ "$dm" != "$sm" ]; then
    return 1
  fi
  ctx="$(read_context "$src")" || return 1
  if [ -n "$ctx" ]; then
    chcon --reference="$src" "$dest"
    if [ $? -ne 0 ]; then
      return 1
    fi
    if [ -z "${TESTLIG_IDENTITY_WINDOW_CONTEXT:-}" ]; then
      if [ "$(read_context "$dest")" != "$ctx" ]; then
        return 1
      fi
    fi
  fi
  return 0
}

rewrite_file() {
  set +e
  local file="$1"
  local enabled_raw="$2"
  local expires_raw="$3"
  local tmp="" dir="" line="" saw_enabled=0 saw_expires=0 before="" after="" want="" kept_mode=""
  last_replace_changed=0
  if [ -L "$file" ] || [ ! -f "$file" ]; then
    return 1
  fi
  dir="$(dirname "$file")"
  if [ "$rollback_active" -eq 0 ] && fail_is temp_prepare; then
    return 1
  fi
  tmp="$(mktemp "${dir}/.identity-window.XXXXXX")"
  if [ $? -ne 0 ] || [ -z "$tmp" ] || [ ! -f "$tmp" ] || [ -L "$tmp" ]; then
    cleanup_temp "$tmp"
    return 1
  fi
  if [ "$rollback_active" -eq 0 ] && fail_is temp_prepare; then
    cleanup_temp "$tmp"
    return 1
  fi
  while IFS= read -r line || [ -n "$line" ]; do
    case "$line" in
      WEB_RUNTIME_IDENTITY_ENABLED=*)
        saw_enabled=1
        if [ "$enabled_raw" != absent ]; then
          printf '%s\n' "$(decode_field "$enabled_raw")" >> "$tmp"
          if [ $? -ne 0 ]; then
            cleanup_temp "$tmp"
            return 1
          fi
        fi
        ;;
      WEB_RUNTIME_IDENTITY_EXPIRES_AT=*)
        saw_expires=1
        if [ "$expires_raw" != absent ]; then
          printf '%s\n' "$(decode_field "$expires_raw")" >> "$tmp"
          if [ $? -ne 0 ]; then
            cleanup_temp "$tmp"
            return 1
          fi
        fi
        ;;
      *)
        printf '%s\n' "$line" >> "$tmp"
        if [ $? -ne 0 ]; then
          cleanup_temp "$tmp"
          return 1
        fi
        ;;
    esac
  done < "$file"
  if [ "$saw_enabled" -eq 0 ] && [ "$enabled_raw" != absent ]; then
    printf '%s\n' "$(decode_field "$enabled_raw")" >> "$tmp"
    if [ $? -ne 0 ]; then
      cleanup_temp "$tmp"
      return 1
    fi
  fi
  if [ "$saw_expires" -eq 0 ] && [ "$expires_raw" != absent ]; then
    printf '%s\n' "$(decode_field "$expires_raw")" >> "$tmp"
    if [ $? -ne 0 ]; then
      cleanup_temp "$tmp"
      return 1
    fi
  fi
  if ! copy_metadata "$file" "$tmp"; then
    cleanup_temp "$tmp"
    return 1
  fi
  kept_mode="$(read_mode "$file")" || { cleanup_temp "$tmp"; return 1; }
  before="$(cksum < "$file")" || { cleanup_temp "$tmp"; return 1; }
  want="$(cksum < "$tmp")" || { cleanup_temp "$tmp"; return 1; }
  if [ "$rollback_active" -eq 0 ] && fail_is atomic_replace; then
    cleanup_temp "$tmp"
    after="$(cksum < "$file")" || return 1
    if [ "$after" != "$before" ]; then
      return 1
    fi
    return 1
  fi
  if [ "$rollback_active" -eq 1 ] && fail_is rollback_shared_after_both && [ "$file" = "$shared_env" ]; then
    cleanup_temp "$tmp"
    after="$(cksum < "$file")" || return 1
    if [ "$after" != "$before" ]; then
      return 1
    fi
    return 1
  fi
  mv -f "$tmp" "$file"
  if [ $? -ne 0 ]; then
    cleanup_temp "$tmp"
    after="$(cksum < "$file")" || return 1
    if [ "$after" != "$before" ]; then
      last_replace_changed=1
    fi
    return 1
  fi
  last_replace_changed=1
  files_changed=yes
  if ! apply_mode "$file" "$kept_mode"; then
    return 1
  fi
  after="$(cksum < "$file")" || return 1
  if [ "$after" != "$want" ]; then
    return 1
  fi
  return 0
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
  set +e
  local shared_enabled="" shared_expires="" release_enabled="" release_expires=""
  local shared_failed=0 release_failed=0
  if [ ! -f "$record" ]; then
    record_state=absent
    printf 'rollback=not_needed\n'
    return 1
  fi
  shared_enabled="$(record_field shared_enabled)"
  shared_expires="$(record_field shared_expires)"
  release_enabled="$(record_field release_enabled)"
  release_expires="$(record_field release_expires)"
  rollback_active=1
  if [ "$changed_shared" -eq 1 ]; then
    rewrite_file "$shared_env" "$shared_enabled" "$shared_expires"
    if [ $? -ne 0 ]; then
      shared_failed=1
    fi
  fi
  if [ "$changed_release" -eq 1 ]; then
    rewrite_file "$release_env" "$release_enabled" "$release_expires"
    if [ $? -ne 0 ]; then
      release_failed=1
    fi
  fi
  rollback_active=0
  if [ "$shared_failed" -eq 0 ] && [ "$release_failed" -eq 0 ] && verify_record; then
    rm -f "$record"
    if [ $? -ne 0 ] || [ -e "$record" ]; then
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
  return 1
}

open_window() {
  set +e
  local now="" expires="" enabled_b64="" expires_b64="" rc=0
  if [ -e "$record" ]; then
    stop record_present
  fi
  if [ -n "${TESTLIG_IDENTITY_WINDOW_NOW:-}" ]; then
    now="${TESTLIG_IDENTITY_WINDOW_NOW}"
  else
    now="$(date -u +%s)"
    rc=$?
    if [ "$rc" -ne 0 ] || [ -z "$now" ]; then
      stop clock
    fi
  fi
  if ! [[ "$now" =~ ^[1-9][0-9]{0,10}$ ]]; then
    stop clock
  fi
  expires="$((now + window_seconds))"
  if ! [[ "$expires" =~ ^[1-9][0-9]{0,10}$ ]]; then
    stop clock
  fi
  inspect_file "$shared_env" shared
  rc=$?
  if [ "$rc" -ne 0 ]; then
    return 1
  fi
  inspect_file "$release_env" release
  rc=$?
  if [ "$rc" -ne 0 ]; then
    return 1
  fi
  refuse_unclear shared
  refuse_unclear release
  printf 'shared_enabled_before=%s\n' "${key_state[shared:WEB_RUNTIME_IDENTITY_ENABLED]}"
  printf 'shared_expires_before=%s\n' "${key_state[shared:WEB_RUNTIME_IDENTITY_EXPIRES_AT]}"
  printf 'release_enabled_before=%s\n' "${key_state[release:WEB_RUNTIME_IDENTITY_ENABLED]}"
  printf 'release_expires_before=%s\n' "${key_state[release:WEB_RUNTIME_IDENTITY_EXPIRES_AT]}"
  write_record
  rc=$?
  if [ "$rc" -ne 0 ]; then
    return 1
  fi
  enabled_b64="$(printf '%s' 'WEB_RUNTIME_IDENTITY_ENABLED=1' | base64 -w0)"
  rc=$?
  if [ "$rc" -ne 0 ] || [ -z "$enabled_b64" ]; then
    return 1
  fi
  expires_b64="$(printf '%s' "WEB_RUNTIME_IDENTITY_EXPIRES_AT=${expires}" | base64 -w0)"
  rc=$?
  if [ "$rc" -ne 0 ] || [ -z "$expires_b64" ]; then
    return 1
  fi
  rewrite_file "$shared_env" "$enabled_b64" "$expires_b64"
  rc=$?
  if [ "$last_replace_changed" -eq 1 ]; then
    changed_shared=1
  fi
  if [ "$rc" -ne 0 ]; then
    return 1
  fi
  if fail_is after_shared; then
    return 1
  fi
  rewrite_file "$release_env" "$enabled_b64" "$expires_b64"
  rc=$?
  if [ "$last_replace_changed" -eq 1 ]; then
    changed_release=1
  fi
  if [ "$rc" -ne 0 ]; then
    return 1
  fi
  if fail_is rollback_shared_after_both; then
    return 1
  fi
  inspect_file "$shared_env" shared
  rc=$?
  if [ "$rc" -ne 0 ]; then
    return 1
  fi
  inspect_file "$release_env" release
  rc=$?
  if [ "$rc" -ne 0 ]; then
    return 1
  fi
  if [ "${key_line[shared:WEB_RUNTIME_IDENTITY_ENABLED]}" != "WEB_RUNTIME_IDENTITY_ENABLED=1" ]; then
    return 1
  fi
  if [ "${key_line[shared:WEB_RUNTIME_IDENTITY_EXPIRES_AT]}" != "WEB_RUNTIME_IDENTITY_EXPIRES_AT=${expires}" ]; then
    return 1
  fi
  if [ "${key_line[release:WEB_RUNTIME_IDENTITY_ENABLED]}" != "WEB_RUNTIME_IDENTITY_ENABLED=1" ]; then
    return 1
  fi
  if [ "${key_line[release:WEB_RUNTIME_IDENTITY_EXPIRES_AT]}" != "WEB_RUNTIME_IDENTITY_EXPIRES_AT=${expires}" ]; then
    return 1
  fi
  changed_shared=0
  changed_release=0
  printf 'expires_window_seconds=%s\n' "$window_seconds"
  printf 'result=opened\n'
  record_state=kept
  return 0
}

restore_window() {
  set +e
  local recorded_sha="" recorded_dir="" rc=0 shared_failed=0 release_failed=0
  if [ ! -f "$record" ]; then
    stop record_absent
  fi
  record_state=kept
  if ! record_metadata_ok "$record"; then
    stop record_metadata
  fi
  recorded_sha="$(record_field release_sha)"
  recorded_dir="$(record_field release_dir)"
  if [ "$recorded_sha" != "$expected" ]; then
    stop record_release
  fi
  if [ "$recorded_dir" != "$release_dir" ]; then
    stop record_release
  fi
  inspect_file "$shared_env" shared
  rc=$?
  if [ "$rc" -ne 0 ]; then
    return 1
  fi
  inspect_file "$release_env" release
  rc=$?
  if [ "$rc" -ne 0 ]; then
    return 1
  fi
  refuse_unclear shared
  refuse_unclear release
  if verify_record; then
    rm -f "$record"
    if [ $? -ne 0 ] || [ -e "$record" ]; then
      record_state=kept
      return 1
    fi
    record_state=removed
    files_changed=no
    printf 'result=restored\n'
    return 0
  fi
  rewrite_file "$shared_env" "$(record_field shared_enabled)" "$(record_field shared_expires)"
  rc=$?
  if [ "$last_replace_changed" -eq 1 ]; then
    changed_shared=1
  fi
  if [ "$rc" -ne 0 ]; then
    shared_failed=1
  fi
  rewrite_file "$release_env" "$(record_field release_enabled)" "$(record_field release_expires)"
  rc=$?
  if [ "$last_replace_changed" -eq 1 ]; then
    changed_release=1
  fi
  if [ "$rc" -ne 0 ]; then
    release_failed=1
  fi
  if [ "$shared_failed" -ne 0 ] || [ "$release_failed" -ne 0 ] || ! verify_record; then
    return 1
  fi
  rm -f "$record"
  if [ $? -ne 0 ] || [ -e "$record" ]; then
    record_state=kept
    return 1
  fi
  changed_shared=0
  changed_release=0
  record_state=removed
  printf 'result=restored\n'
  return 0
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

set +e
if [ "$mode" = open ]; then
  open_window
  open_rc=$?
  if [ "$open_rc" -ne 0 ]; then
    rollback_changes
    rollback_done=1
    printf 'result=stopped\n'
    exit 1
  fi
else
  restore_window
  restore_rc=$?
  if [ "$restore_rc" -ne 0 ]; then
    rollback_changes
    rollback_done=1
    printf 'result=stopped\n'
    exit 1
  fi
fi
set -e
