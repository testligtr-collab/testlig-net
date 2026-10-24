#!/bin/bash
# Apply the Testlig lsphp83 security package when the refreshed repo has that
# exact candidate, and set session.upload_progress.enabled=0 in its main php.ini.
# Does not update other PHP branches, compile PHP, or kill unrelated processes.
set -u
set -o pipefail

root="${TESTLIG_PHP_APPLY_ROOT:-}"
ini_path="/usr/local/lsws/lsphp83/etc/php.ini"
php_bin="/usr/local/lsws/lsphp83/bin/php"
handler_bin="/usr/local/lsws/lsphp83/bin/lsphp"
lswsctrl_bin="/usr/local/lsws/bin/lswsctrl"
backup_rel="/home/testlig.net/shared/php-ini-backups"
key="session.upload_progress.enabled"
fixed_version="8.3.36"
snapshot_lines=""
worker_lines=""
worker_identity="unresolved"
socket_inode=""
shared_handler="no"
backup_file=""
original_meta=""
apply_result="not_applied"

host_path() {
  if [ -n "$root" ]; then
    printf '%s%s' "$root" "$1"
  else
    printf '%s' "$1"
  fi
}

tool() {
  local name="$1" base
  shift
  base="$(basename "$name")"
  if [ -n "$root" ] && [ -f "$root/bin/$base" ]; then
    bash "$root/bin/$base" "$@"
    return
  fi
  command "$name" "$@"
}

run_limited() {
  local seconds="$1"
  shift
  if [ -n "$root" ]; then
    tool "$@"
    return
  fi
  timeout "$seconds" "$@"
}

matches() {
  printf '%s' "$1" | grep -Eq -- "$2"
}

allow_value() {
  case "$1" in
    0|1|On|Off|on|off|true|false) return 0 ;;
    *) return 1 ;;
  esac
}

normalize_value() {
  case "$1" in
    0|Off|off|false) printf '%s' '0' ;;
    1|On|on|true) printf '%s' '1' ;;
    *) printf '%s' 'unknown' ;;
  esac
}

directive_token() {
  local raw="$1" token
  raw="${raw#"${raw%%[![:space:]]*}"}"
  [ -n "$raw" ] || { printf '%s' 'unknown'; return 0; }
  case "$raw" in
    \"*)
      token="${raw#\"}"
      case "$token" in
        *\"*) token="${token%%\"*}" ;;
        *) printf '%s' 'unknown'; return 0 ;;
      esac
      ;;
    \'*)
      token="${raw#\'}"
      case "$token" in
        *\'*) token="${token%%\'*}" ;;
        *) printf '%s' 'unknown'; return 0 ;;
      esac
      ;;
    *)
      token="${raw%%;*}"
      token="${token%"${token##*[![:space:]]}"}"
      ;;
  esac
  if allow_value "$token"; then
    normalize_value "$token"
  else
    printf '%s' 'unknown'
  fi
}

regular_file() {
  [ -e "$1" ] || return 1
  [ -L "$1" ] && return 1
  [ -f "$1" ] || return 1
  return 0
}

file_meta() {
  tool stat -c '%u %g %a' "$1" 2>/dev/null
}

active_directive() {
  local file="$1" line trimmed raw found=0 value="absent"
  regular_file "$file" || { printf '%s' 'rejected'; return 0; }
  [ -r "$file" ] || { printf '%s' 'read_error'; return 0; }
  while IFS= read -r line || [ -n "$line" ]; do
    line="${line%$'\r'}"
    trimmed="${line#"${line%%[![:space:]]*}"}"
    case "$trimmed" in
      ''|';'*|'#'*) continue ;;
    esac
    case "$trimmed" in
      "$key"=*|"$key"[[:space:]]*) ;;
      *) continue ;;
    esac
    raw="${trimmed#"$key"}"
    raw="${raw#"${raw%%[![:space:]]*}"}"
    case "$raw" in
      '='*) ;;
      *) continue ;;
    esac
    raw="${raw#=}"
    found=1
    value="$(directive_token "$raw")"
  done < "$file"
  if [ "$found" -eq 0 ]; then
    printf '%s' 'absent'
  else
    printf '%s' "$value"
  fi
}

version_newer() {
  local installed="$1" candidate="$2" first
  [ "$installed" = "$candidate" ] && return 1
  first="$(printf '%s\n%s\n' "$installed" "$candidate" | sort -V | head -n 1)"
  [ "$first" = "$installed" ]
}

handler_exe_match() {
  [ "$1" = "$handler_bin" ] && return 0
  [ "$1" = "$handler_bin (deleted)" ] && return 0
  return 1
}

copy_preserved() {
  local src="$1" dest="$2"
  if [ -n "$root" ] && [ -f "$root/state/fail-copy" ]; then
    return 1
  fi
  cp -a "$src" "$dest"
}

move_atomic() {
  local src="$1" dest="$2" kind="$3"
  if [ -n "$root" ] && [ "$kind" = "write" ] && [ -f "$root/state/fail-rename" ]; then
    return 1
  fi
  if [ -n "$root" ] && [ "$kind" = "rollback" ] && [ -f "$root/state/fail-rollback" ]; then
    return 1
  fi
  mv -f "$src" "$dest"
}

finish_no() {
  echo "apply_result=${apply_result}"
  echo "php_apply_complete=no"
  exit 1
}

rollback_ini() {
  local temp meta_backup meta_temp
  if [ -z "$backup_file" ] || ! regular_file "$backup_file"; then
    echo "ini_rollback=failed"
    return 1
  fi
  temp="${ini_host}.testlig-rollback"
  rm -f "$temp"
  if ! copy_preserved "$backup_file" "$temp"; then
    rm -f "$temp"
    echo "ini_rollback=failed"
    return 1
  fi
  if ! regular_file "$temp" || ! cmp -s "$backup_file" "$temp"; then
    rm -f "$temp"
    echo "ini_rollback=failed"
    return 1
  fi
  meta_backup="$(file_meta "$backup_file" || true)"
  meta_temp="$(file_meta "$temp" || true)"
  if [ -z "$meta_backup" ] || [ "$meta_backup" != "$meta_temp" ]; then
    rm -f "$temp"
    echo "ini_rollback=failed"
    return 1
  fi
  if ! move_atomic "$temp" "$ini_host" rollback; then
    rm -f "$temp"
    echo "ini_rollback=failed"
    return 1
  fi
  if ! regular_file "$ini_host" || ! cmp -s "$backup_file" "$ini_host"; then
    echo "ini_rollback=failed"
    return 1
  fi
  echo "ini_rollback=restored"
  return 0
}

starttime_of() {
  local stat_file="$1/stat" rest start
  [ -r "$stat_file" ] || return 1
  rest="$(sed -n 's/.*) //p' "$stat_file" 2>/dev/null || true)"
  start="$(printf '%s\n' "$rest" | awk '{ print $20; exit }')"
  matches "$start" '^[0-9]+$' || return 1
  printf '%s' "$start"
}

fd_matches_inode() {
  local pid_dir="$1" inode="$2" fd target got
  [ -d "$pid_dir/fd" ] || return 2
  [ -r "$pid_dir/fd" ] || return 2
  shopt -s nullglob
  for fd in "$pid_dir/fd"/*; do
    [ -L "$fd" ] || [ -e "$fd" ] || continue
    target="$(readlink "$fd" 2>/dev/null || true)"
    if [ -z "$target" ]; then
      return 2
    fi
    case "$target" in
      socket:\[[0-9]*\])
        got="${target#socket:[}"
        got="${got%]}"
        if matches "$got" '^[0-9]+$' && [ "$got" = "$inode" ]; then
          return 0
        fi
        ;;
    esac
  done
  return 1
}

discover_workers() {
  local socket_file proc pid_dir pid exe matched start lines="" count=0
  worker_lines=""
  worker_identity="unresolved"
  socket_inode=""
  if [ -z "${testlig_socket:-}" ] || ! matches "$testlig_socket" '^/(usr/local/lsws|tmp|run)/[A-Za-z0-9._/-]+$' || matches "$testlig_socket" '\.\.'; then
    return 1
  fi
  socket_file="$(host_path "$testlig_socket")"
  if [ ! -e "$socket_file" ] || [ -L "$socket_file" ] || [ -d "$socket_file" ]; then
    return 1
  fi
  socket_inode="$(tool stat -c '%i' "$socket_file" 2>/dev/null || true)"
  if ! matches "$socket_inode" '^[0-9]+$'; then
    return 1
  fi
  proc="$(host_path "/proc")"
  if [ ! -d "$proc" ] || [ ! -r "$proc" ]; then
    return 1
  fi
  shopt -s nullglob
  for pid_dir in "$proc"/[0-9]*; do
    pid="$(basename "$pid_dir")"
    if [ ! -e "$pid_dir/exe" ] && [ ! -L "$pid_dir/exe" ]; then
      continue
    fi
    exe="$(readlink "$pid_dir/exe" 2>/dev/null || true)"
    if [ -z "$exe" ]; then
      continue
    fi
    handler_exe_match "$exe" || continue
    fd_matches_inode "$pid_dir" "$socket_inode"
    matched=$?
    if [ "$matched" -eq 2 ]; then
      worker_lines=""
      worker_identity="unresolved"
      return 1
    fi
    if [ "$matched" -eq 0 ]; then
      start="$(starttime_of "$pid_dir" || true)"
      if ! matches "${start:-}" '^[0-9]+$'; then
        worker_lines=""
        worker_identity="unresolved"
        return 1
      fi
      lines="${lines}${pid}|${start}"$'\n'
      count=$((count + 1))
    fi
  done
  worker_lines="$lines"
  if [ "$count" -eq 0 ]; then
    worker_identity="none"
  else
    worker_identity="ok"
  fi
  return 0
}

snapshot_workers() {
  local pid start count=0
  snapshot_lines=""
  if ! discover_workers; then
    echo "worker_identity=unresolved"
    echo "worker_snapshot_count=0"
    return 1
  fi
  snapshot_lines="$worker_lines"
  while IFS='|' read -r pid start || [ -n "${pid:-}" ]; do
    [ -n "${pid:-}" ] || continue
    count=$((count + 1))
  done <<< "$snapshot_lines"
  echo "worker_identity=${worker_identity}"
  echo "worker_snapshot_count=${count}"
  return 0
}

current_testlig_workers() {
  discover_workers || return 1
  printf '%s' "$worker_lines"
}

worker_still_same() {
  local pid="$1" start="$2" proc current
  proc="$(host_path "/proc")/${pid}"
  if [ ! -d "$proc" ]; then
    return 1
  fi
  current="$(starttime_of "$proc" || true)"
  if [ -z "$current" ]; then
    return 0
  fi
  [ "$current" = "$start" ]
}

verify_workers_exited() {
  local lines="$1" pid start tries=1 n=0 alive
  [ -z "$lines" ] && return 0
  if [ -z "$root" ]; then
    tries=8
  fi
  while [ "$n" -lt "$tries" ]; do
    alive=0
    while IFS='|' read -r pid start || [ -n "${pid:-}" ]; do
      [ -n "${pid:-}" ] || continue
      if worker_still_same "$pid" "$start"; then
        alive=1
      fi
    done <<< "$lines"
    [ "$alive" -eq 0 ] && return 0
    n=$((n + 1))
    if [ -z "$root" ]; then
      sleep 1
    fi
  done
  return 1
}

signal_workers() {
  local lines="$1" pid start proc exe matched
  while IFS='|' read -r pid start || [ -n "${pid:-}" ]; do
    [ -n "${pid:-}" ] || continue
    proc="$(host_path "/proc")/${pid}"
    if [ ! -d "$proc" ]; then
      continue
    fi
    exe="$(readlink "$proc/exe" 2>/dev/null || true)"
    handler_exe_match "$exe" || continue
    fd_matches_inode "$proc" "$socket_inode"
    matched=$?
    if [ "$matched" -eq 2 ]; then
      return 1
    fi
    [ "$matched" -eq 0 ] || continue
    case "$exe" in
      *" (deleted)") echo "worker_deleted_exe=${pid}" ;;
    esac
    if ! tool kill -TERM "$pid"; then
      return 1
    fi
  done <<< "$lines"
  return 0
}

refresh_workers() {
  local phase="$1" lines="$2" method="ols_lsapi_term"
  if [ "$phase" = "rollback_" ]; then
    if ! current_testlig_workers >/dev/null; then
      echo "${phase}worker_identity=unresolved"
      echo "${phase}worker_recycle=failed"
      echo "${phase}worker_recycle_verified=unresolved"
      return 1
    fi
    lines="$worker_lines"
    echo "${phase}worker_identity=${worker_identity}"
  fi
  if [ "$shared_handler" = "no" ]; then
    method="ols_restart"
  fi
  if [ -z "$lines" ]; then
    if [ "$worker_identity" != "none" ]; then
      echo "${phase}worker_recycle=failed"
      echo "${phase}worker_recycle_verified=unresolved"
      return 1
    fi
    if [ "$method" = "ols_lsapi_term" ]; then
      echo "${phase}wide_restart=no"
      echo "${phase}worker_recycle=none_running"
      echo "${phase}worker_recycle_verified=none"
      return 0
    fi
  fi
  if [ "$method" = "ols_restart" ]; then
    echo "${phase}wide_restart=yes"
    if ! tool "$lswsctrl_bin" stop; then
      echo "${phase}worker_recycle=failed"
      echo "${phase}worker_recycle_verified=not_signaled"
      return 1
    fi
    if ! tool "$lswsctrl_bin" start; then
      echo "${phase}worker_recycle=failed"
      echo "${phase}worker_recycle_verified=not_signaled"
      return 1
    fi
  else
    echo "${phase}wide_restart=no"
    if ! signal_workers "$lines"; then
      echo "${phase}worker_recycle=failed"
      echo "${phase}worker_recycle_verified=signal_failed"
      return 1
    fi
  fi
  if ! verify_workers_exited "$lines"; then
    echo "${phase}worker_recycle=failed"
    echo "${phase}worker_recycle_verified=still_running"
    return 1
  fi
  echo "${phase}worker_recycle=${method}"
  if [ -z "$lines" ]; then
    echo "${phase}worker_recycle_verified=none"
  else
    echo "${phase}worker_recycle_verified=exited"
  fi
  return 0
}

rollback_and_refresh() {
  local file_ok=0
  apply_result="failed"
  if rollback_ini; then
    file_ok=1
  fi
  if ! refresh_workers "rollback_" ""; then
    finish_no
  fi
  [ "$file_ok" -eq 1 ] || finish_no
}

prepare_backup() {
  local backup_dir stamp meta_live meta_backup
  backup_dir="$(host_path "$backup_rel")"
  case "$backup_rel" in
    *public_html*) return 1 ;;
  esac
  mkdir -p "$backup_dir" || return 1
  chmod 700 "$backup_dir" || return 1
  [ -d "$backup_dir" ] && [ ! -L "$backup_dir" ] || return 1
  stamp="$(date -u +%Y%m%dT%H%M%SZ)"
  backup_file="${backup_dir}/lsphp83-php.ini.${stamp}"
  copy_preserved "$ini_host" "$backup_file" || return 1
  regular_file "$backup_file" || return 1
  cmp -s "$ini_host" "$backup_file" || return 1
  meta_live="$(file_meta "$ini_host" || true)"
  meta_backup="$(file_meta "$backup_file" || true)"
  original_meta="$meta_live"
  [ -n "$meta_live" ] && [ "$meta_live" = "$meta_backup" ] || return 1
  echo "ini_backup=saved"
  echo "ini_backup_web_exposed=no"
  return 0
}

render_ini() {
  local src="$1" dest="$2"
  if [ -n "$root" ] && [ -f "$root/state/fail-write" ]; then
    return 1
  fi
  awk -v key="$key" '
    {
      line = $0
      sub(/\r$/, "", line)
      rows[NR] = line
      trimmed = line
      sub(/^[ \t]+/, "", trimmed)
      active = 0
      if (trimmed !~ /^[;#]/ && trimmed != "" && index(trimmed, key) == 1) {
        rest = substr(trimmed, length(key) + 1)
        sub(/^[ \t]+/, "", rest)
        if (index(rest, "=") == 1) active = 1
      }
      if (active) last = NR
    }
    END {
      if (NR == 0) { print key " = 0"; exit }
      for (i = 1; i <= NR; i++) {
        if (i == last) print key " = 0"
        else print rows[i]
      }
      if (last == "") print key " = 0"
    }
  ' "$src" > "$dest"
}

write_ini() {
  local temp body meta_temp
  temp="${ini_host}.testlig-apply"
  body="${temp}.body"
  rm -f "$temp" "$body"
  copy_preserved "$ini_host" "$temp" || return 1
  regular_file "$temp" || return 1
  cmp -s "$ini_host" "$temp" || return 1
  meta_temp="$(file_meta "$temp" || true)"
  [ -n "$original_meta" ] && [ "$meta_temp" = "$original_meta" ] || return 1
  render_ini "$ini_host" "$body" || return 1
  [ -s "$body" ] || return 1
  [ "$(active_directive "$body")" = "0" ] || return 1
  cat "$body" > "$temp" || return 1
  rm -f "$body"
  meta_temp="$(file_meta "$temp" || true)"
  [ "$meta_temp" = "$original_meta" ] || return 1
  [ "$(active_directive "$temp")" = "0" ] || return 1
  move_atomic "$temp" "$ini_host" write || return 1
  regular_file "$ini_host" || return 1
  [ "$(active_directive "$ini_host")" = "0" ] || return 1
  meta_temp="$(file_meta "$ini_host" || true)"
  [ "$meta_temp" = "$original_meta" ] || return 1
  rm -f "$temp" "$body"
  return 0
}

validate_cli() {
  local cli_version="unreadable" cli_status=0 cli_raw loaded="unreadable" loaded_status=0 loaded_raw
  local progress="unreadable" progress_status=0 progress_raw
  cli_raw="$(run_limited 8 "$php_bin" -v </dev/null 2>/dev/null)" || cli_status=$?
  if [ "$cli_status" -eq 0 ]; then
    cli_version="$(printf '%s\n' "$cli_raw" | awk 'NR==1 && $1=="PHP" && $2 ~ /^[0-9]+\.[0-9]+\.[0-9]+$/ { print $2; exit }')"
    [ -n "$cli_version" ] || cli_version="unreadable"
  fi
  loaded_raw="$(run_limited 8 "$php_bin" --ini </dev/null 2>/dev/null)" || loaded_status=$?
  if [ "$loaded_status" -eq 0 ]; then
    loaded="$(printf '%s\n' "$loaded_raw" | awk '/^Loaded Configuration File:/ { sub(/^Loaded Configuration File:[[:space:]]*/, ""); sub(/[[:space:]]+$/, ""); print; exit }')"
    [ -n "$loaded" ] || loaded="unreadable"
  fi
  progress_raw="$(run_limited 8 "$php_bin" -c "$ini_host" -r 'echo ini_get("session.upload_progress.enabled");' </dev/null 2>/dev/null)" || progress_status=$?
  if [ "$progress_status" -eq 0 ]; then
    progress_raw="${progress_raw//$'\r'/}"
    progress_raw="${progress_raw//$'\n'/}"
    if [ -z "$progress_raw" ] || [ "$progress_raw" = "0" ]; then
      progress="0"
    elif allow_value "$progress_raw"; then
      progress="$(normalize_value "$progress_raw")"
    fi
  fi
  echo "php_cli binary=${php_bin} version=${cli_version} upload_progress_enabled=${progress} loaded_ini=${loaded} label=cli web_acceptance=no"
  [ "$progress" = "0" ] && [ "$loaded" = "$ini_path" ] && [ "$cli_version" != "unreadable" ]
}

parse_preview() {
  local preview="$1" candidate_evr="$2"
  printf '%s\n' "$preview" | awk -v evr="$candidate_evr" '
    BEGIN { section = "" }
    /^Error:/ || /^Problem:/ || /Could not/ || /No match for argument/ { bad = "preview_failed" }
    /^Downgrading:/ { section = "down"; next }
    /^Removing:/ { section = "remove"; next }
    /^Upgrading:/ || /^Installing/ { section = "up"; next }
    section == "down" && $1 ~ /^[A-Za-z0-9._+-]+$/ { bad = "downgrade"; next }
    section == "remove" && $1 ~ /^[A-Za-z0-9._+-]+$/ { bad = "unexpected_removal"; next }
    section == "up" {
      name = $1
      ver = ""
      for (i = 1; i <= NF; i++) if ($i ~ /^[0-9]+\.[0-9]+\.[0-9]+-[A-Za-z0-9._+-]+$/) ver = $i
      if (name ~ /^lsphp(74|80|81|82|84|85)(-[a-z0-9]+)?$/) { bad = "other_php_branch"; next }
      if (ver == "") next
      if (name !~ /^lsphp83(-[a-z0-9]+)?$/) { bad = "unexpected_package"; next }
      if (ver != evr) { bad = "candidate_mismatch"; next }
      print name "-" ver
      if (name == "lsphp83") main = 1
    }
    END {
      if (bad != "") { print "BAD " bad; exit }
      if (main != 1) print "BAD unparsed_preview"
    }
  '
}

echo "php_apply=lsphp83"
echo "php_apply_other_branches=unchanged"
echo "web_runtime_measured=no"
echo "web_session_upload_progress_enabled=unknown"
echo "package_downgrade=no"

ini_host="$(host_path "$ini_path")"
workflow_sha="${1:-}"
if ! matches "$workflow_sha" '^[0-9a-f]{40}$'; then
  echo "release_identity=unreadable"
  echo "package_update=not_attempted"
  echo "ini_change=not_applied"
  finish_no
fi
app_link="$(host_path "/home/testlig.net/app")"
if [ ! -L "$app_link" ]; then
  echo "release_identity=unreadable"
  echo "package_update=not_attempted"
  echo "ini_change=not_applied"
  finish_no
fi
app_target="$(readlink "$app_link" 2>/dev/null || true)"
release_name="$(basename "$app_target")"
if ! matches "$release_name" '^[0-9]{8}T[0-9]{6}Z-[0-9a-f]{12}$'; then
  echo "release_identity=unreadable"
  echo "package_update=not_attempted"
  echo "ini_change=not_applied"
  finish_no
fi
case "$app_target" in
  "releases/${release_name}"|"/home/testlig.net/releases/${release_name}") ;;
  *)
    echo "release_identity=unreadable"
    echo "package_update=not_attempted"
    echo "ini_change=not_applied"
    finish_no
    ;;
esac
release_revision="$(host_path "/home/testlig.net/releases/${release_name}/REVISION")"
current_revision="$(host_path "/home/testlig.net/shared/CURRENT_REVISION")"
if ! regular_file "$release_revision" || ! regular_file "$current_revision"; then
  echo "release_identity=unreadable"
  echo "package_update=not_attempted"
  echo "ini_change=not_applied"
  finish_no
fi
release_file_sha="$(tr -d '[:space:]' < "$release_revision")"
current_sha="$(tr -d '[:space:]' < "$current_revision")"
if ! matches "$release_file_sha" '^[0-9a-f]{40}$' || ! matches "$current_sha" '^[0-9a-f]{40}$'; then
  echo "release_identity=unreadable"
  echo "package_update=not_attempted"
  echo "ini_change=not_applied"
  finish_no
fi
if [ "$release_file_sha" != "$workflow_sha" ] || [ "$current_sha" != "$workflow_sha" ]; then
  echo "release_identity=mismatch"
  echo "package_update=not_attempted"
  echo "ini_change=not_applied"
  finish_no
fi
echo "release_identity=match"
echo "release_sha=${workflow_sha}"
echo "release_name=${release_name}"

if [ -L "$ini_host" ]; then
  echo "ini_change=rejected_symlink"
  finish_no
fi
if ! regular_file "$ini_host" || [ ! -r "$ini_host" ]; then
  echo "ini_change=rejected_type"
  finish_no
fi

conf="$(host_path "/usr/local/lsws/conf")"
override="none"
testlig_socket=""
if [ -d "$conf" ]; then
  shopt -s nullglob
  for file in "$conf"/vhosts/testlig.net/vhconf.conf "$conf"/vhosts/testlig.net/vhost.conf; do
    [ -f "$file" ] || continue
    while IFS= read -r line || [ -n "$line" ]; do
      line="${line%$'\r'}"
      trimmed="${line#"${line%%[![:space:]]*}"}"
      case "$trimmed" in
        ''|';'*|'#'*) continue ;;
      esac
      case "$trimmed" in
        address[[:space:]]uds://*)
          testlig_socket="${trimmed#*uds://}"
          testlig_socket="${testlig_socket%%[[:space:]]*}"
          ;;
        docRoot[[:space:]]*)
          path="${trimmed#docRoot}"
          path="${path#"${path%%[![:space:]]*}"}"
          path="${path%%[[:space:]]*}"
          if matches "$path" '^/home/testlig.net/[A-Za-z0-9._/-]+$' && ! matches "$path" '\.\.'; then
            value="$(active_directive "$(host_path "$path/.user.ini")")"
            case "$value" in
              absent|rejected) ;;
              0) [ "$override" = "none" ] && override="aligned" ;;
              *) override="conflict" ;;
            esac
          fi
          ;;
        phpIniOverride[[:space:]]*)
          path="${trimmed#phpIniOverride}"
          path="${path#"${path%%[![:space:]]*}"}"
          path="${path%%[[:space:]]*}"
          if [ "$path" != "$ini_path" ] && matches "$path" '^/(usr/local/lsws|home/testlig.net)/[A-Za-z0-9._/-]+\.ini$' && ! matches "$path" '\.\.'; then
            value="$(active_directive "$(host_path "$path")")"
            case "$value" in
              absent|rejected) ;;
              0) [ "$override" = "none" ] && override="aligned" ;;
              *) override="conflict" ;;
            esac
          fi
          ;;
      esac
      case "$trimmed" in
        *" $key"|*" $key"=*|*" $key"[[:space:]]*) ;;
        *) continue ;;
      esac
      raw="${trimmed##*"$key"}"
      raw="${raw#"${raw%%[![:space:]]*}"}"
      raw="${raw#=}"
      token="$(directive_token "$raw")"
      if [ "$token" != "0" ] && [ "$token" != "absent" ]; then
        override="conflict"
      elif [ "$override" = "none" ] && [ "$token" = "0" ]; then
        override="aligned"
      fi
    done < "$file"
  done
  for file in "$conf"/vhosts/*/vhconf.conf "$conf"/vhosts/*/vhost.conf; do
    case "$file" in
      */vhosts/testlig.net/*) continue ;;
    esac
    if grep -q '/usr/local/lsws/lsphp83/bin/lsphp' "$file"; then
      vhost="$(basename "$(dirname "$file")")"
      if matches "$vhost" '^[A-Za-z0-9._-]+$'; then
        echo "shared_handler_vhost=${vhost}"
        shared_handler="yes"
      fi
    fi
  done
fi
if [ "$shared_handler" = "no" ]; then
  echo "shared_handler_vhost=none"
fi
echo "site_override=${override}"
if [ "$override" = "conflict" ]; then
  echo "ini_change=not_applied"
  echo "package_update=not_attempted"
  finish_no
fi

if ! snapshot_workers; then
  echo "package_update=not_attempted"
  echo "ini_change=not_applied"
  finish_no
fi

previous="unknown"
pkg_line="$(tool rpm -q --qf '%{VERSION}|%{RELEASE}' lsphp83 2>/dev/null || true)"
if matches "$pkg_line" '^[0-9]+\.[0-9]+\.[0-9]+\|[A-Za-z0-9._+-]+$'; then
  previous="${pkg_line%%|*}-${pkg_line#*|}"
fi
echo "lsphp83_previous=${previous}"

repo_status=0
tool dnf -q makecache --disablerepo='*' --enablerepo='litespeed*' >/dev/null 2>&1 || repo_status=$?
candidate="unknown"
if [ "$repo_status" -ne 0 ]; then
  echo "repo_refresh=failed"
else
  echo "repo_refresh=refreshed"
  query="$(tool dnf -q repoquery --latest-limit=1 --qf '%{name}|%{version}|%{release}' lsphp83 2>/dev/null || true)"
  if matches "$query" '^lsphp83\|[0-9]+\.[0-9]+\.[0-9]+\|[A-Za-z0-9._+-]+$'; then
    candidate="${query#lsphp83|}"
    candidate="${candidate%%|*}-${candidate#*|}"
  fi
fi
echo "lsphp83_candidate=${candidate}"

candidate_version="${candidate%%-*}"
candidate_evr="$candidate"
if matches "$candidate_version" '^[0-9]+\.[0-9]+\.[0-9]+$' && { [ "$candidate_version" = "$fixed_version" ] || version_newer "$fixed_version" "$candidate_version"; }; then
  echo "official_fix_version=candidate"
else
  echo "official_fix_version=not_candidate"
fi

fix_evidence="not_confirmed"
if [ "$repo_status" -eq 0 ] && [ "$candidate" != "unknown" ]; then
  changelog="$(tool dnf -q changelog "lsphp83-${candidate_evr}" 2>/dev/null || true)"
  if printf '%s' "$changelog" | grep -q "$candidate_evr" \
    && printf '%s' "$changelog" | grep -q 'CVE-2026-109618' \
    && printf '%s' "$changelog" | grep -q 'CVE-2026-109619'; then
    fix_evidence="candidate_changelog"
  fi
fi
echo "fix_evidence=${fix_evidence}"

package_goal="waiting"
pinned=""
if [ "$fix_evidence" = "candidate_changelog" ] && [ "$candidate" != "$previous" ]; then
  preview_status=0
  preview="$(tool dnf -q update --assumeno "lsphp83-${candidate_evr}" 2>/dev/null)" || preview_status=$?
  if [ "$preview_status" -ne 0 ] && [ "$preview_status" -ne 1 ]; then
    echo "package_update=stopped"
    echo "package_stop_reason=preview_failed"
    echo "ini_change=not_applied"
    finish_no
  fi
  parsed="$(parse_preview "$preview" "$candidate_evr")"
  if printf '%s\n' "$parsed" | grep -q '^BAD '; then
    reason="$(printf '%s\n' "$parsed" | awk '/^BAD / { print $2; exit }')"
    [ -n "$reason" ] || reason="unparsed_preview"
    echo "package_update=stopped"
    echo "package_stop_reason=${reason}"
    echo "ini_change=not_applied"
    finish_no
  fi
  pinned="$(printf '%s\n' "$parsed" | awk 'NF { print }' | tr '\n' ' ')"
  if [ -z "$pinned" ] || ! printf '%s\n' "$parsed" | grep -qx "lsphp83-${candidate_evr}"; then
    echo "package_update=stopped"
    echo "package_stop_reason=unparsed_preview"
    echo "ini_change=not_applied"
    finish_no
  fi
  package_goal="update"
else
  echo "package_update=waiting"
fi

if ! prepare_backup; then
  echo "ini_backup=failed"
  echo "ini_change=not_applied"
  finish_no
fi

if [ "$package_goal" = "update" ]; then
  # shellcheck disable=SC2086
  if ! tool dnf -y update $pinned >/dev/null 2>&1; then
    echo "package_update=failed"
    echo "ini_change=not_applied"
    finish_no
  fi
  current_line="$(tool rpm -q --qf '%{VERSION}|%{RELEASE}' lsphp83 2>/dev/null || true)"
  current="unknown"
  if matches "$current_line" '^[0-9]+\.[0-9]+\.[0-9]+\|[A-Za-z0-9._+-]+$'; then
    current="${current_line%%|*}-${current_line#*|}"
  fi
  echo "lsphp83_installed=${current}"
  installed_changelog="$(tool rpm -q --changelog lsphp83 2>/dev/null || true)"
  if [ "$current" != "$candidate_evr" ] \
    || ! printf '%s' "$installed_changelog" | grep -q "$candidate_evr" \
    || ! printf '%s' "$installed_changelog" | grep -q 'CVE-2026-109618' \
    || ! printf '%s' "$installed_changelog" | grep -q 'CVE-2026-109619'; then
    echo "cve_2026_109618=absent"
    echo "cve_2026_109619=absent"
    echo "package_update=verify_failed"
    echo "ini_change=not_applied"
    finish_no
  fi
  echo "cve_2026_109618=present"
  echo "cve_2026_109619=present"
  echo "package_update=updated"
  echo "package_verified=yes"
  apply_result="patched"
else
  current_line="$(tool rpm -q --qf '%{VERSION}|%{RELEASE}' lsphp83 2>/dev/null || true)"
  current="$previous"
  if matches "$current_line" '^[0-9]+\.[0-9]+\.[0-9]+\|[A-Za-z0-9._+-]+$'; then
    current="${current_line%%|*}-${current_line#*|}"
  fi
  echo "lsphp83_installed=${current}"
  installed_changelog="$(tool rpm -q --changelog lsphp83 2>/dev/null || true)"
  if printf '%s' "$installed_changelog" | grep -q 'CVE-2026-109618'; then
    echo "cve_2026_109618=present"
  else
    echo "cve_2026_109618=absent"
  fi
  if printf '%s' "$installed_changelog" | grep -q 'CVE-2026-109619'; then
    echo "cve_2026_109619=present"
  else
    echo "cve_2026_109619=absent"
  fi
  apply_result="temporary_mitigation"
fi

if tool rpm -q lsphp80 >/dev/null 2>&1 || [ -x "$(host_path "/usr/local/lsws/lsphp80/bin/lsphp")" ]; then
  echo "other_php_handler=lsphp80"
  echo "other_php_action=not_updated"
fi

before="$(active_directive "$ini_host")"
echo "ini_before=${before}"
if [ "$before" = "unknown" ] || [ "$before" = "read_error" ] || [ "$before" = "rejected" ]; then
  echo "ini_change=not_applied"
  finish_no
fi
if [ "$before" = "0" ]; then
  echo "ini_change=already_0"
else
  if ! write_ini; then
    if regular_file "$ini_host" && cmp -s "$ini_host" "$backup_file"; then
      echo "ini_change=not_applied"
      echo "ini_rollback=not_needed"
    else
      rollback_ini || apply_result="failed"
    fi
    apply_result="failed"
    finish_no
  fi
  echo "ini_change=set_0"
fi
echo "ini_after=$(active_directive "$ini_host")"

if ! validate_cli; then
  if [ "$before" != "0" ]; then
    rollback_and_refresh
  fi
  finish_no
fi

if ! refresh_workers "" "$snapshot_lines"; then
  if [ "$before" != "0" ]; then
    rollback_and_refresh
  fi
  finish_no
fi

health_code="$(tool curl -sk --max-time 15 --connect-timeout 5 -o "$(host_path "$backup_rel")/health.json" -w '%{http_code}' --resolve 'testlig.net:443:127.0.0.1' -H 'Host: testlig.net' 'https://127.0.0.1/health' || printf '%s' 'curl_failed')"
health_body="$(host_path "$backup_rel")/health.json"
if [ "$health_code" = "200" ] && grep -q '"status":"ok"' "$health_body" 2>/dev/null; then
  echo "health_code=200"
  echo "health_status=ok"
else
  echo "health_code=${health_code:-unreadable}"
  echo "health_status=failed"
  rm -f "$health_body"
  rollback_and_refresh
  finish_no
fi
rm -f "$health_body"
home_code="$(tool curl -sk --max-time 15 --connect-timeout 5 -o /dev/null -w '%{http_code}' --resolve 'testlig.net:443:127.0.0.1' 'https://testlig.net/' || printf '%s' 'curl_failed')"
login_code="$(tool curl -sk --max-time 15 --connect-timeout 5 -o /dev/null -w '%{http_code}' --resolve 'testlig.net:443:127.0.0.1' 'https://testlig.net/giris' || printf '%s' 'curl_failed')"
echo "homepage_code=${home_code:-unreadable}"
echo "login_code=${login_code:-unreadable}"
if [ "$home_code" != "200" ] || [ "$login_code" != "200" ]; then
  rollback_and_refresh
  finish_no
fi
echo "ini_rollback=not_needed"
echo "apply_result=${apply_result}"
echo "php_apply_complete=yes"
