#!/bin/bash
# Read-only PHP security inventory. Prints allowlisted keys only.
# Does not change settings, packages, or services.
# Does not create a public PHP file. CLI and ini reads are not web acceptance.
set -u
set -o pipefail

root="${TESTLIG_PHP_INVENTORY_ROOT:-}"

host_path() {
  if [ -n "$root" ]; then
    printf '%s%s' "$root" "$1"
  else
    printf '%s' "$1"
  fi
}

run_limited() {
  local seconds="$1"
  shift
  if [ -n "$root" ]; then
    bash "$@"
    return
  fi
  timeout "$seconds" "$@"
}

allow_value() {
  case "$1" in
    0|1|On|Off|on|off|true|false) return 0 ;;
    *) return 1 ;;
  esac
}

matches() {
  printf '%s' "$1" | grep -Eq -- "$2"
}

read_ini_value() {
  local file="$1"
  local host line value
  host="$(host_path "$file")"
  if [ ! -f "$host" ]; then
    printf '%s' 'absent_file'
    return 0
  fi
  value=""
  while IFS= read -r line || [ -n "$line" ]; do
    case "$line" in
      *[Ss][Ee][Cc][Rr][Ee][Tt]*|*[Pp][Aa][Ss][Ss][Ww][Oo][Rr][Dd]*|*[Tt][Oo][Kk][Ee][Nn]*) continue ;;
    esac
    case "$line" in
      *session.upload_progress.enabled*) ;;
      *) continue ;;
    esac
    value="${line#*=}"
    value="${value#"${value%%[![:space:]]*}"}"
    value="${value%%;*}"
    value="${value%"${value##*[![:space:]]}"}"
    value="${value#\"}"
    value="${value%\"}"
    value="${value#\'}"
    value="${value%\'}"
  done < "$host"
  if allow_value "$value"; then
    printf '%s' "$value"
  elif [ -z "$value" ]; then
    printf '%s' 'absent'
  else
    printf '%s' 'unreadable'
  fi
}

echo "php_inventory=read-only"
echo "php_inventory_changes=no"
echo "web_runtime_measured=no"
echo "web_session_upload_progress_enabled=unknown"
echo "php_cli_is_web_runtime=no"
echo "php_cli_loaded_ini_is_web_acceptance=no"
echo "configured_handler_is_internet_exposure=no"

report_packages() {
  local out="" status=127 name version release arch
  if [ -n "$root" ]; then
    if [ -f "$root/bin/rpm" ]; then
      status=0
      out="$(bash "$root/bin/rpm" -qa --qf '%{NAME}|%{VERSION}|%{RELEASE}|%{ARCH}\n' 'lsphp*' 2>/dev/null)" || status=$?
    fi
  elif command -v rpm >/dev/null 2>&1; then
    status=0
    out="$(timeout 20 rpm -qa --qf '%{NAME}|%{VERSION}|%{RELEASE}|%{ARCH}\n' 'lsphp*' 2>/dev/null)" || status=$?
  elif command -v dpkg-query >/dev/null 2>&1; then
    status=0
    out="$(timeout 20 dpkg-query -W -f '${Package}|${Version}|none|${Architecture}\n' 'lsphp*' 2>/dev/null)" || status=$?
  fi
  if [ "$status" -ne 0 ]; then
    echo "lsphp_package_query=unknown"
    return 0
  fi
  if [ -z "$out" ]; then
    echo "lsphp_package=none"
    return 0
  fi
  local found=0
  while IFS= read -r line || [ -n "$line" ]; do
    [ -n "$line" ] || continue
    name="${line%%|*}"
    local rest="${line#*|}"
    version="${rest%%|*}"
    rest="${rest#*|}"
    release="${rest%%|*}"
    arch="${rest#*|}"
    if ! printf '%s' "$name" | grep -Eq '^lsphp[0-9]+(-[a-z0-9]+)?$'; then
      continue
    fi
    if ! printf '%s' "$version" | grep -Eq '^[A-Za-z0-9._+-]+$'; then
      continue
    fi
    if ! printf '%s' "$release" | grep -Eq '^[A-Za-z0-9._+-]+$'; then
      continue
    fi
    if ! printf '%s' "$arch" | grep -Eq '^[A-Za-z0-9_]+$'; then
      continue
    fi
    echo "lsphp_package name=${name} version=${version} release=${release} arch=${arch}"
    found=1
    report_package_configs "$name"
  done <<EOF
$out
EOF
  if [ "$found" -eq 0 ]; then
    echo "lsphp_package=unparsed"
  fi
}

report_package_configs() {
  local name="$1" out="" status=0 path found=0
  if [ -n "$root" ]; then
    [ -f "$root/bin/rpm" ] || return 0
    out="$(bash "$root/bin/rpm" -qc "$name" 2>/dev/null)" || status=$?
  elif command -v rpm >/dev/null 2>&1; then
    out="$(timeout 20 rpm -qc "$name" 2>/dev/null)" || status=$?
  else
    return 0
  fi
  if [ "$status" -ne 0 ]; then
    echo "lsphp_config package=${name} path=unknown"
    return 0
  fi
  while IFS= read -r path || [ -n "$path" ]; do
    [ -n "$path" ] || continue
    if ! matches "$path" '^/usr/local/lsws/lsphp[0-9]+/[A-Za-z0-9._/-]+\.ini$' || matches "$path" '\.\.'; then
      continue
    fi
    echo "lsphp_config package=${name} path=${path} source=package"
    found=1
  done <<EOF
$out
EOF
  if [ "$found" -eq 0 ]; then
    echo "lsphp_config package=${name} path=none"
  fi
}

report_filesystem_ini() {
  local base ini path value
  base="$(host_path "/usr/local/lsws")"
  [ -d "$base" ] || return 0
  shopt -s nullglob
  for ini in "$base"/lsphp[0-9]*/etc/php.ini "$base"/lsphp[0-9]*/etc/php.d/*.ini; do
    [ -f "$ini" ] || continue
    path="$ini"
    if [ -n "$root" ]; then
      path="${ini#"$root"}"
    fi
    if ! matches "$path" '^/usr/local/lsws/lsphp[0-9]+/[A-Za-z0-9._/-]+\.ini$' || matches "$path" '\.\.'; then
      continue
    fi
    echo "lsphp_config path=${path} source=filesystem"
    value="$(read_ini_value "$path")"
    echo "php_ini_directive path=${path} key=session.upload_progress.enabled value=${value} web_acceptance=no"
  done
}

remembered="|"
remember() {
  case "$remembered" in
    *"|$1|"*) return 1 ;;
  esac
  remembered="${remembered}$1|"
  return 0
}

scan_configs() {
  local conf file line field expect="" value present
  local saw_testlig=0 saw_other=0 saw_override=0 saw_docroot=0
  conf="$(host_path "/usr/local/lsws/conf")"
  if [ ! -d "$conf" ]; then
    echo "ols_config=absent"
    echo "testlig_php_handler=absent"
    echo "configured_php_handler=none"
    echo "testlig_configured_override=none"
    echo "testlig_docroot_user_ini=absent"
    return 0
  fi
  echo "ols_config=present"
  shopt -s nullglob
  for file in "$conf/httpd_config.conf" "$conf"/vhosts/*/vhconf.conf "$conf"/vhosts/*/vhost.conf; do
    [ -f "$file" ] || continue
    local testlig=0
    case "$file" in
      */vhosts/testlig.net/*) testlig=1 ;;
    esac
    set -f
    while IFS= read -r line || [ -n "$line" ]; do
      expect=""
      for field in $line; do
        if [ "$expect" = "override" ]; then
          expect=""
          if [ "$testlig" -eq 1 ] && allow_value "$field"; then
            echo "testlig_configured_override key=session.upload_progress.enabled value=${field} source=vhconf web_acceptance=no"
            saw_override=1
          fi
          continue
        fi
        if [ "$expect" = "docroot" ]; then
          expect=""
          if [ "$testlig" -eq 1 ] && [ "$saw_docroot" -eq 0 ]; then
            saw_docroot=1
            if matches "$field" '^/home/testlig\.net/[A-Za-z0-9._/-]+$' && ! matches "$field" '\.\.'; then
              value="$(read_ini_value "${field}/.user.ini")"
              echo "testlig_user_ini path=${field}/.user.ini key=session.upload_progress.enabled value=${value} web_acceptance=no"
              echo "testlig_docroot_user_ini=read"
            else
              echo "testlig_docroot_user_ini=unresolved"
            fi
          fi
          continue
        fi
        if [ "$expect" = "override_path" ]; then
          expect=""
          if [ "$testlig" -eq 1 ]; then
            case "$field" in
              '{') ;;
              *)
                if matches "$field" '^(/usr/local/lsws|/home/testlig\.net)/[A-Za-z0-9._/-]+\.ini$' && ! matches "$field" '\.\.'; then
                  echo "testlig_php_ini_override_path=${field} web_acceptance=no"
                  value="$(read_ini_value "$field")"
                  echo "php_ini_directive path=${field} key=session.upload_progress.enabled value=${value} source=phpIniOverride web_acceptance=no"
                else
                  echo "testlig_php_ini_override_path=rejected"
                fi
                ;;
            esac
          fi
          continue
        fi
        case "$field" in
          session.upload_progress.enabled) expect="override" ;;
          docRoot) expect="docroot" ;;
          phpIniOverride) expect="override_path" ;;
          /usr/local/lsws/lsphp*)
            matches "$field" '^/usr/local/lsws/lsphp[0-9]+/bin/(lsphp[0-9]*|php)$' || continue
            remember "$field" || continue
            if [ -f "$(host_path "$field")" ]; then
              present="yes"
            else
              present="no"
            fi
            if [ "$testlig" -eq 1 ]; then
              echo "testlig_php_handler binary=${field} present=${present} source=vhost_config exposure=not_measured"
              saw_testlig=1
            else
              echo "configured_php_handler binary=${field} present=${present} exposure=not_measured"
              saw_other=1
            fi
            ;;
        esac
      done
    done < "$file"
    set +f
  done
  if [ "$saw_testlig" -eq 0 ]; then
    echo "testlig_php_handler=absent"
  fi
  if [ "$saw_other" -eq 0 ]; then
    echo "configured_php_handler=none"
  fi
  if [ "$saw_override" -eq 0 ]; then
    echo "testlig_configured_override=none"
  fi
  if [ "$saw_docroot" -eq 0 ]; then
    echo "testlig_docroot_user_ini=absent"
  fi
}

report_cli() {
  local base bin path version_raw version ini_raw loaded progress found=0
  base="$(host_path "/usr/local/lsws")"
  if [ ! -d "$base" ]; then
    echo "php_cli=absent"
    return 0
  fi
  shopt -s nullglob
  for bin in "$base"/lsphp[0-9]*/bin/php; do
    [ -f "$bin" ] || continue
    path="$bin"
    if [ -n "$root" ]; then
      path="${bin#"$root"}"
    fi
    matches "$path" '^/usr/local/lsws/lsphp[0-9]+/bin/php$' || continue
    found=1
    version_raw="$(run_limited 8 "$bin" -v 2>/dev/null || true)"
    ini_raw="$(run_limited 8 "$bin" --ini 2>/dev/null || true)"
    progress="$(run_limited 8 "$bin" -r 'echo ini_get("session.upload_progress.enabled");' 2>/dev/null || true)"
    version="$(printf '%s\n' "$version_raw" | awk 'NR==1 && $1=="PHP" && $2 ~ /^[0-9]+\.[0-9]+\.[0-9]+$/ { print $2; exit }')"
    [ -n "$version" ] || version="unreadable"
    loaded="$(printf '%s\n' "$ini_raw" | awk 'NR==1 && /^Loaded Configuration File: / { sub(/^Loaded Configuration File: /, ""); print; exit }')"
    loaded="${loaded%%$'\r'*}"
    if [ "$loaded" = "(none)" ]; then
      loaded="none"
    elif ! matches "$loaded" '^/usr/local/lsws/lsphp[0-9]+/[A-Za-z0-9._/-]+\.ini$' || matches "$loaded" '\.\.'; then
      loaded="unreadable"
    fi
    progress="${progress//$'\r'/}"
    progress="${progress//$'\n'/}"
    if ! allow_value "$progress"; then
      progress="unreadable"
    fi
    echo "php_cli binary=${path} version=${version} upload_progress_enabled=${progress} loaded_ini=${loaded} label=cli web_acceptance=no"
  done
  if [ "$found" -eq 0 ]; then
    echo "php_cli=absent"
  fi
}

report_repos() {
  local dir file line id="" enabled="" found=0
  dir="$(host_path "/etc/yum.repos.d")"
  if [ ! -d "$dir" ]; then
    echo "package_repo=absent"
    return 0
  fi
  shopt -s nullglob
  for file in "$dir"/*.repo; do
    id=""
    enabled=""
    while IFS= read -r line || [ -n "$line" ]; do
      case "$line" in
        \[*\])
          if [ -n "$id" ] && [ -n "$enabled" ]; then
            case "$id" in
              *[!A-Za-z0-9._-]*) ;;
              *)
                echo "package_repo id=${id} enabled=${enabled}"
                found=1
                ;;
            esac
          fi
          id="${line#[}"
          id="${id%]}"
          enabled=""
          ;;
        enabled=0|enabled=1)
          enabled="${line#enabled=}"
          ;;
      esac
    done < "$file"
    if [ -n "$id" ] && [ -n "$enabled" ]; then
      case "$id" in
        *[!A-Za-z0-9._-]*) ;;
        *)
          echo "package_repo id=${id} enabled=${enabled}"
          found=1
          ;;
      esac
    fi
  done
  if [ "$found" -eq 0 ]; then
    echo "package_repo=none"
  fi
}

report_security_update() {
  local out="" status=127 line advisory package parsed=0
  if [ -n "$root" ]; then
    if [ -f "$root/bin/dnf" ]; then
      status=0
      out="$(bash "$root/bin/dnf" -q updateinfo list security --cacheonly lsphp83 2>/dev/null)" || status=$?
    fi
  elif command -v dnf >/dev/null 2>&1; then
    status=0
    out="$(timeout 20 dnf -q updateinfo list security --cacheonly lsphp83 2>/dev/null)" || status=$?
  fi
  if [ "$status" -ne 0 ]; then
    echo "lsphp83_security_update=unknown"
    echo "lsphp83_security_update_reason=repo_query_failed"
    return 0
  fi
  while IFS= read -r line || [ -n "$line" ]; do
    [ -n "$line" ] || continue
    case "$line" in
      "Last metadata"*|"Updating "*|"This system "*) continue ;;
    esac
    advisory="${line%% *}"
    package="${line##* }"
    if ! matches "$advisory" '^[A-Z][A-Z0-9]*-[0-9]{4}:[0-9]+$' || ! matches "$package" '^lsphp83-[A-Za-z0-9._+-]+$'; then
      echo "lsphp83_security_update=unknown"
      echo "lsphp83_security_update_reason=unparsed_repo_output"
      return 0
    fi
    echo "lsphp83_security_update=available"
    echo "lsphp83_security_update_advisory=${advisory}"
    echo "lsphp83_security_update_package=${package}"
    parsed=1
  done <<EOF
$out
EOF
  if [ "$parsed" -eq 0 ]; then
    echo "lsphp83_security_update=none_in_cache"
  fi
}

report_release() {
  local file revision app release_name
  file="$(host_path "/home/testlig.net/shared/CURRENT_REVISION")"
  if [ -r "$file" ]; then
    revision="$(tr -d '\r\n' < "$file")"
    if matches "$revision" '^[0-9a-f]{40}$'; then
      echo "release_sha=${revision}"
    else
      echo "release_sha=unreadable"
    fi
  else
    echo "release_sha=absent"
  fi
  app="$(host_path "/home/testlig.net/app")"
  if [ -L "$app" ]; then
    release_name="$(basename "$(readlink -f "$app" 2>/dev/null || true)")"
    if matches "$release_name" '^[A-Za-z0-9._-]{1,80}$'; then
      echo "release_name=${release_name}"
    else
      echo "release_name=unreadable"
    fi
  else
    echo "release_name=absent"
  fi
}

report_packages
report_filesystem_ini
scan_configs
report_cli
report_repos
report_security_update
report_release
echo "php_inventory_complete=yes"
