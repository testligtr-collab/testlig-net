#!/bin/bash
# Apply the Testlig lsphp83 security package, when the refreshed repo has it,
# and set session.upload_progress.enabled=0 in that branch's main php.ini.
# Does not update other PHP branches, compile PHP, or restart every site.
set -u
set -o pipefail

root="${TESTLIG_PHP_APPLY_ROOT:-}"
ini_path="/usr/local/lsws/lsphp83/etc/php.ini"
php_bin="/usr/local/lsws/lsphp83/bin/php"
handler_bin="/usr/local/lsws/lsphp83/bin/lsphp"
backup_rel="/home/testlig.net/shared/php-ini-backups"
key="session.upload_progress.enabled"
fixed_version="8.3.36"

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

active_directive() {
  local file="$1" line trimmed raw found=0 value="absent"
  [ -f "$file" ] || { printf '%s' 'absent_file'; return 0; }
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

echo "php_apply=lsphp83"
echo "php_apply_other_branches=unchanged"
echo "web_runtime_measured=no"
echo "web_session_upload_progress_enabled=unknown"
echo "wide_restart=no"
echo "package_downgrade=no"

ini_host="$(host_path "$ini_path")"
if [ ! -f "$ini_host" ] || [ ! -r "$ini_host" ]; then
  echo "ini_change=unreadable"
  echo "php_apply_complete=no"
  exit 1
fi

conf="$(host_path "/usr/local/lsws/conf")"
override="none"
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
        docRoot[[:space:]]*)
          path="${trimmed#docRoot}"
          path="${path#"${path%%[![:space:]]*}"}"
          path="${path%%[[:space:]]*}"
          if matches "$path" '^/home/testlig.net/[A-Za-z0-9._/-]+$' && ! matches "$path" '\.\.'; then
            value="$(active_directive "$(host_path "$path/.user.ini")")"
            case "$value" in
              absent|absent_file) ;;
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
              absent|absent_file) ;;
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
fi
echo "site_override=${override}"
if [ "$override" = "conflict" ]; then
  echo "ini_change=not_applied"
  echo "package_update=not_attempted"
  echo "php_apply_complete=no"
  exit 1
fi

previous="unknown"
pkg_line="$(tool rpm -q --qf '%{VERSION}|%{RELEASE}' lsphp83 2>/dev/null || true)"
if matches "$pkg_line" '^[0-9]+\.[0-9]+\.[0-9]+\|[A-Za-z0-9._+-]+$'; then
  previous="${pkg_line%%|*}-${pkg_line#*|}"
fi
echo "lsphp83_previous=${previous}"

repo_status=0
tool dnf -q makecache --disablerepo='*' --enablerepo='litespeed*' >/dev/null 2>&1 || repo_status=$?
if [ "$repo_status" -ne 0 ]; then
  echo "repo_refresh=failed"
  candidate="unknown"
else
  echo "repo_refresh=refreshed"
  query="$(tool dnf -q repoquery --latest-limit=1 --qf '%{name}|%{version}|%{release}' lsphp83 2>/dev/null || true)"
  candidate="unknown"
  if matches "$query" '^lsphp83\|[0-9]+\.[0-9]+\.[0-9]+\|[A-Za-z0-9._+-]+$'; then
    candidate="${query#lsphp83|}"
    candidate="${candidate%%|*}-${candidate#*|}"
  fi
fi
echo "lsphp83_candidate=${candidate}"

candidate_version="${candidate%%-*}"
patched="no"
if matches "$candidate_version" '^[0-9]+\.[0-9]+\.[0-9]+$' && { [ "$candidate_version" = "$fixed_version" ] || version_newer "$fixed_version" "$candidate_version"; }; then
  patched="yes"
  echo "official_fix_version=candidate"
else
  echo "official_fix_version=not_candidate"
fi
if [ "$repo_status" -eq 0 ]; then
  changelog="$(tool dnf -q changelog lsphp83 2>/dev/null || true)"
  if printf '%s' "$changelog" | grep -q 'CVE-2026-109618' && printf '%s' "$changelog" | grep -q 'CVE-2026-109619'; then
    patched="yes"
    echo "vendor_backport=changelog"
  else
    echo "vendor_backport=not_seen"
  fi
else
  echo "vendor_backport=not_seen"
fi

package_changed="no"
if [ "$patched" = "yes" ] && [ "$candidate" != "unknown" ] && [ "$candidate" != "$previous" ]; then
  preview="$(tool dnf -q update --assumeno 'lsphp83*' 2>/dev/null || true)"
  if printf '%s\n' "$preview" | grep -Eq '^(Removing|Downgrading):'; then
    echo "package_update=stopped"
    echo "package_stop_reason=unexpected_removal"
    echo "ini_change=not_applied"
    echo "php_apply_complete=no"
    exit 1
  fi
  bad="$(printf '%s\n' "$preview" | grep -Eo 'lsphp(74|80|81|82|84|85)(-[a-z0-9]+)?' || true)"
  if [ -n "$bad" ]; then
    echo "package_update=stopped"
    echo "package_stop_reason=other_php_branch"
    echo "ini_change=not_applied"
    echo "php_apply_complete=no"
    exit 1
  fi
  names="$(printf '%s\n' "$preview" | grep -Eo 'lsphp83(-[a-z0-9]+)?' || true)"
  if [ -z "$names" ]; then
    echo "package_update=stopped"
    echo "package_stop_reason=unparsed_preview"
    echo "ini_change=not_applied"
    echo "php_apply_complete=no"
    exit 1
  fi
  if ! tool dnf -y update 'lsphp83*' >/dev/null 2>&1; then
    echo "package_update=failed"
    echo "ini_change=not_applied"
    echo "php_apply_complete=no"
    exit 1
  fi
  package_changed="yes"
  echo "package_update=updated"
else
  echo "package_update=waiting"
fi

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

if tool rpm -q lsphp80 >/dev/null 2>&1 || [ -x "$(host_path "/usr/local/lsws/lsphp80/bin/lsphp")" ]; then
  echo "other_php_handler=lsphp80"
  echo "other_php_action=not_updated"
fi

backup_dir="$(host_path "$backup_rel")"
mkdir -p "$backup_dir"
chmod 700 "$backup_dir" || true
stamp="$(date -u +%Y%m%dT%H%M%SZ)"
backup_file="${backup_dir}/lsphp83-php.ini.${stamp}"
cp -a "$ini_host" "$backup_file"
chmod 600 "$backup_file" || true
echo "ini_backup=saved"
echo "ini_backup_web_exposed=no"

before="$(active_directive "$ini_host")"
echo "ini_before=${before}"
if [ "$before" = "unknown" ] || [ "$before" = "read_error" ] || [ "$before" = "absent_file" ]; then
  echo "ini_change=not_applied"
  echo "php_apply_complete=no"
  exit 1
fi

tmp="${ini_host}.testlig-apply"
if [ "$before" = "0" ]; then
  echo "ini_change=already_0"
else
  awk -v key="$key" '
    BEGIN { found = 0 }
    {
      line = $0
      sub(/\r$/, "", line)
      trimmed = line
      sub(/^[ \t]+/, "", trimmed)
      if (trimmed ~ /^[;#]/ || trimmed == "") { print line; next }
      if (index(trimmed, key) == 1) {
        rest = substr(trimmed, length(key) + 1)
        sub(/^[ \t]+/, "", rest)
        if (index(rest, "=") == 1) {
          if (!found) { found = NR }
        }
      }
      print line
    }
    END {
      if (!found) print key " = 0"
    }
    ' "$ini_host" > "$tmp"
  if [ ! -s "$tmp" ]; then
    rm -f "$tmp"
    echo "ini_change=not_applied"
    echo "php_apply_complete=no"
    exit 1
  fi
  if [ "$before" != "absent" ]; then
    awk -v key="$key" '
      BEGIN { seen = 0 }
      {
        line = $0
        raw = line
        sub(/\r$/, "", raw)
        trimmed = raw
        sub(/^[ \t]+/, "", trimmed)
        active = 0
        if (trimmed !~ /^[;#]/ && trimmed != "" && index(trimmed, key) == 1) {
          rest = substr(trimmed, length(key) + 1)
          sub(/^[ \t]+/, "", rest)
          if (index(rest, "=") == 1) active = 1
        }
        if (active) last = NR
        rows[NR] = line
      }
      END {
        for (i = 1; i <= NR; i++) {
          if (i == last) print key " = 0"
          else print rows[i]
        }
      }
    ' "$ini_host" > "$tmp"
  fi
  if [ ! -s "$tmp" ]; then
    rm -f "$tmp"
    cp -a "$backup_file" "$ini_host"
    echo "ini_rollback=restored"
    echo "ini_change=not_applied"
    echo "php_apply_complete=no"
    exit 1
  fi
  cat "$tmp" > "$ini_host"
  rm -f "$tmp"
  echo "ini_change=set_0"
fi

after="$(active_directive "$ini_host")"
echo "ini_after=${after}"
if [ "$after" != "0" ]; then
  cp -a "$backup_file" "$ini_host"
  echo "ini_rollback=restored"
  echo "php_apply_complete=no"
  exit 1
fi

shared=""
if [ -d "$conf" ]; then
  shopt -s nullglob
  for file in "$conf"/vhosts/*/vhconf.conf "$conf"/vhosts/*/vhost.conf; do
    case "$file" in
      */vhosts/testlig.net/*) continue ;;
    esac
    if grep -q '/usr/local/lsws/lsphp83/bin/lsphp' "$file"; then
      vhost="$(basename "$(dirname "$file")")"
      if matches "$vhost" '^[A-Za-z0-9._-]+$'; then
        echo "shared_handler_vhost=${vhost}"
        shared="yes"
      fi
    fi
  done
fi
if [ -z "$shared" ]; then
  echo "shared_handler_vhost=none"
fi

recycled=0
proc_root="$(host_path "/proc")"
if [ -n "$root" ] && [ -f "$root/state/workers" ]; then
  while IFS=' ' read -r pid target || [ -n "${pid:-}" ]; do
    if [ "$target" = "$handler_bin" ] && matches "$pid" '^[0-9]+$'; then
      tool kill -TERM "$pid" >/dev/null 2>&1 || true
      recycled=$((recycled + 1))
    fi
  done < "$root/state/workers"
elif [ -d "$proc_root" ]; then
  for exe in "$proc_root"/[0-9]*/exe; do
    target="$(readlink "$exe" 2>/dev/null || true)"
    if [ "$target" = "$handler_bin" ]; then
      pid="$(basename "$(dirname "$exe")")"
      if matches "$pid" '^[0-9]+$'; then
        tool kill -TERM "$pid" >/dev/null 2>&1 || true
        recycled=$((recycled + 1))
      fi
    fi
  done
fi
if [ "$recycled" -eq 0 ]; then
  echo "worker_recycle=none_running"
else
  echo "worker_recycle=lsphp83_term"
  echo "worker_recycle_count=${recycled}"
fi

cli_version="unreadable"
cli_status=0
cli_raw="$(tool "$php_bin" -v </dev/null 2>/dev/null)" || cli_status=$?
if [ "$cli_status" -eq 0 ]; then
  cli_version="$(printf '%s\n' "$cli_raw" | awk 'NR==1 && $1=="PHP" && $2 ~ /^[0-9]+\.[0-9]+\.[0-9]+$/ { print $2; exit }')"
  [ -n "$cli_version" ] || cli_version="unreadable"
fi
loaded="unreadable"
loaded_status=0
loaded_raw="$(tool "$php_bin" --ini </dev/null 2>/dev/null)" || loaded_status=$?
if [ "$loaded_status" -eq 0 ]; then
  loaded="$(printf '%s\n' "$loaded_raw" | awk '/^Loaded Configuration File:/ { sub(/^Loaded Configuration File:[[:space:]]*/, ""); sub(/[[:space:]]+$/, ""); print; exit }')"
  [ -n "$loaded" ] || loaded="unreadable"
fi
progress="unreadable"
progress_status=0
progress_raw="$(tool "$php_bin" -c "$ini_host" -r 'echo ini_get("session.upload_progress.enabled");' </dev/null 2>/dev/null)" || progress_status=$?
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
if [ "$progress" != "0" ] || [ "$loaded" != "$ini_path" ]; then
  cp -a "$backup_file" "$ini_host"
  echo "ini_rollback=restored"
  echo "php_apply_complete=no"
  exit 1
fi

health_code="$(tool curl -sk -o "$backup_dir/health.json" -w '%{http_code}' --resolve 'testlig.net:443:127.0.0.1' -H 'Host: testlig.net' 'https://127.0.0.1/health' || true)"
if [ "$health_code" = "200" ] && grep -q '"status":"ok"' "$backup_dir/health.json" 2>/dev/null; then
  echo "health_code=200"
  echo "health_status=ok"
else
  echo "health_code=${health_code:-unreadable}"
  echo "health_status=failed"
  rm -f "$backup_dir/health.json"
  cp -a "$backup_file" "$ini_host"
  echo "ini_rollback=restored"
  echo "php_apply_complete=no"
  exit 1
fi
rm -f "$backup_dir/health.json"
home_code="$(tool curl -sk -o /dev/null -w '%{http_code}' --resolve 'testlig.net:443:127.0.0.1' 'https://testlig.net/' || true)"
login_code="$(tool curl -sk -o /dev/null -w '%{http_code}' --resolve 'testlig.net:443:127.0.0.1' 'https://testlig.net/giris' || true)"
echo "homepage_code=${home_code:-unreadable}"
echo "login_code=${login_code:-unreadable}"
if [ "$home_code" != "200" ] || [ "$login_code" != "200" ]; then
  cp -a "$backup_file" "$ini_host"
  echo "ini_rollback=restored"
  echo "php_apply_complete=no"
  exit 1
fi
echo "ini_rollback=not_needed"
echo "php_apply_complete=yes"
