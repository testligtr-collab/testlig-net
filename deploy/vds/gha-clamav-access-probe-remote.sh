#!/usr/bin/env bash
# Read-only measurement of ClamAV socket and signature visibility.
# The ^lsphp scan is diagnostic and does not select the user.
# The recorded 17 October 2026 web request is euid 5002, egid 5002, groups 5002.
# That identity is fixed in this script. It is not a dispatch input.
# runuser -u testl3865 does not pass group options.
# A completed run is CLI access under that identity. It does not prove web or SELinux acceptance.
# It does not install, scan a file, change services, groups, ACL, or env files.
# test -r/-w/-x is a DAC check and does not prove SELinux.
# A root connection is not application access. The container is not warmed and env values are not embedded.
set -u

if ulimit -c 0; then
  echo "access_probe_core=disabled"
  core_disabled=1
else
  echo "access_probe_core=unknown"
  core_disabled=0
fi

echo "access_probe=read-only"
echo "access_probe_changes_access=no"
echo "dac_check=test_rwx"
echo "dac_check_proves_selinux=no"
echo "root_connection_counts_as_app=no"
echo "loaded_main=unproven"
echo "loaded_bytecode=unproven"
echo "web_identity_source=recorded_request"
echo "access_context=cli_same_identity"
echo "web_selinux_acceptance=no"
echo "runuser_group_options=none"

recorded_euid=5002
recorded_egid=5002
recorded_group=5002
identity_ok=0

root="${TESTLIG_CLAMAV_ACCESS_ROOT:-}"
host_path() {
  if [ -n "$root" ]; then
    printf '%s%s\n' "$root" "$1"
  else
    printf '%s\n' "$1"
  fi
}

safe_path() {
  local path="$1"
  local length=${#path}
  [ "$length" -ge 2 ] && [ "$length" -le 108 ] || return 1
  printf '%s' "$path" | grep -Eq '^/([A-Za-z0-9._-]{1,64}/){0,16}[A-Za-z0-9._-]{1,64}$'
}

limited() {
  local seconds="$1"
  shift
  local output status
  output="$(timeout "$seconds" "$@" 2>/dev/null)"
  status=$?
  if [ "$status" -eq 124 ]; then
    printf '%s\n' "timed_out"
    return 0
  fi
  if [ "$status" -ne 0 ]; then
    printf '%s\n' "unknown"
    return 0
  fi
  printf '%s\n' "$output"
}

conf_value() {
  local file="$1"
  local key="$2"
  timeout 2 awk -v key="$key" '
    /^[[:space:]]*#/ || /^[[:space:]]*$/ { next }
    $1 == key {
      if (seen) { exit 3 }
      seen = 1
      value = substr($0, length($1) + 1)
      gsub(/^[[:space:]]+|[[:space:]]+$/, "", value)
      gsub(/^"|"$/, "", value)
      print value
    }
    END { if (!seen) exit 1 }
  ' "$file" 2>/dev/null
}

emit_field() {
  local label="$1"
  local file="$2"
  local key="$3"
  local value status
  if [ ! -f "$file" ]; then
    echo "${label}=absent"
    return
  fi
  value="$(conf_value "$file" "$key")"
  status=$?
  if [ "$status" -eq 124 ]; then
    echo "${label}=timed_out"
  elif [ "$status" -eq 3 ]; then
    echo "${label}=contradictory"
  elif [ "$status" -eq 1 ] || [ -z "$value" ]; then
    echo "${label}=unset"
  elif [ "${#value}" -gt 200 ]; then
    echo "${label}=rejected"
  else
    case "$value" in
      *[!A-Za-z0-9._/-]*) echo "${label}=rejected" ;;
      *) echo "${label}=${value}" ;;
    esac
  fi
}

probe_id() {
  if [ -n "$root" ] && [ -f "${root}/fixture-id" ]; then
    bash "${root}/fixture-id" "$@"
  else
    id "$@"
  fi
}

probe_now() {
  if [ -n "$root" ] && [ -f "${root}/fixture-date" ]; then
    bash "${root}/fixture-date"
  else
    date +%s
  fi
}

site_account_uid() {
  local account="testl3865"
  local site_path account_uid site_uid
  site_path="$(host_path "/home/testlig.net")"
  if [ -n "$root" ] && [ -f "${root}/fixture-id" ]; then
    account_uid="$(timeout 2 bash "${root}/fixture-id" -u "$account" 2>/dev/null || true)"
  else
    account_uid="$(timeout 2 id -u "$account" 2>/dev/null || true)"
  fi
  site_uid=""
  if [ -d "$site_path" ]; then
    if [ -n "$root" ] && [ -f "${root}/fixture-stat" ]; then
      site_uid="$(timeout 2 bash "${root}/fixture-stat" -c %u "$site_path" 2>/dev/null || true)"
    else
      site_uid="$(timeout 2 stat -c %u "$site_path" 2>/dev/null || true)"
    fi
  fi
  account_uid="${account_uid//[[:space:]]/}"
  site_uid="${site_uid//[[:space:]]/}"
  case "$account_uid" in
    [1-9]|[1-9][0-9]*) ;;
    *) return 1 ;;
  esac
  case "$site_uid" in
    [1-9]|[1-9][0-9]*) ;;
    *) return 1 ;;
  esac
  [ "$account_uid" = "$site_uid" ] || return 1
  printf '%s\n' "$account_uid"
}

numeric_id() {
  local value="$1"
  printf '%s' "$value" | grep -Eq '^[0-9]+$'
}

as_app() {
  if [ -n "$root" ]; then
    if [ ! -f "${root}/fixture-runuser" ]; then
      return 127
    fi
    bash "${root}/fixture-runuser" -u testl3865 -- "$@"
    return
  fi
  runuser -u testl3865 -- "$@"
}

as_app_timed() {
  if [ -n "$root" ]; then
    as_app "$@"
    return
  fi
  timeout 2 runuser -u testl3865 -- "$@"
}

probe_php() {
  if [ -n "$root" ]; then
    if [ ! -f "${root}/fixture-php" ]; then
      return 127
    fi
    as_app bash "${root}/fixture-php" "$@"
    return
  fi
  as_app /usr/local/lsws/lsphp83/bin/php "$@"
}

identity_stage_label() {
  local value="$1"
  if numeric_id "$value"; then
    printf '%s' "$value"
    return 0
  fi
  printf '%s' "unknown"
}

probe_php_code=""
probe_php_code_ready=0

prepare_probe_php_code() {
  local decoded="" marker_count=""
  probe_php_code=""
  probe_php_code_ready=0
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
  if [ "$marker_count" != "1" ]; then
    return 1
  fi
  case "$decoded" in
    *'// CLAMAV_ACCESS_PROBE_ENTRY'$'\n') ;;
    *) return 1 ;;
  esac
  local entry_marker='// CLAMAV_ACCESS_PROBE_ENTRY'
  decoded="${decoded%"$entry_marker"$'\n'}"
  probe_php_code="${decoded}clamav_access_probe_cli_main();"$'\n'
  probe_php_code_ready=1
  return 0
}

invoke_probe_php() {
  set +e
  if [ -n "$root" ]; then
    timeout 12 bash "${root}/fixture-runuser" -u testl3865 -- bash "${root}/fixture-php-bin" -r "$probe_php_code" -- "$@" </dev/null 2>/dev/null
  else
    timeout 12 runuser -u testl3865 -- /usr/local/lsws/lsphp83/bin/php -r "$probe_php_code" -- "$@" </dev/null 2>/dev/null
  fi
  probe_php_status=${PIPESTATUS[0]-}
}

collect_recorded_identity() {
  local raw="" stage_line="" command_status="" helper_started="no" failure="unknown" body=""
  if [ "$probe_php_code_ready" -ne 1 ]; then
    echo "identity_command_status=not_attempted"
    echo "identity_helper_started=no"
    echo "identity_failure=unknown"
    return 1
  fi
  raw="$(
    set +e
    invoke_probe_php --identity
    printf '\nidentity_stage_status=%s\n' "${probe_php_status-}"
  )"
  stage_line="$(printf '%s\n' "$raw" | awk '/^identity_stage_status=/ { line = $0 } END { print line }')"
  body="$(printf '%s\n' "$raw" | awk '!/^identity_stage_status=/')"
  command_status="${stage_line#identity_stage_status=}"
  if printf '%s\n' "$body" | grep -qx 'identity_helper_started=yes'; then
    helper_started="yes"
  fi
  command_status="$(identity_stage_label "$command_status")"
  if [ "$command_status" = "unknown" ]; then
    failure="unknown"
  elif [ "$command_status" -eq 124 ]; then
    failure="timeout"
  elif [ "$command_status" -eq 125 ] || [ "$command_status" -eq 126 ] || [ "$command_status" -eq 127 ]; then
    if [ "$helper_started" = "yes" ]; then
      failure="php"
    else
      failure="command_start"
    fi
  elif [ "$command_status" -ne 0 ]; then
    if [ "$helper_started" = "yes" ]; then
      failure="php"
    else
      failure="unknown"
    fi
  elif [ "$helper_started" != "yes" ]; then
    failure="unknown"
  else
    failure="none"
  fi
  echo "identity_command_status=${command_status}"
  echo "identity_helper_started=${helper_started}"
  echo "identity_failure=${failure}"
  if [ "$failure" != "none" ]; then
    return 1
  fi
  printf '%s\n' "$body"
}

recorded_site_flags() {
  local account_uid="" recorded_name="" site_uid="" site_path=""
  account_uid="$(probe_id -u testl3865 2>/dev/null || true)"
  recorded_name="$(probe_id -nu "$recorded_euid" 2>/dev/null || true)"
  account_uid="${account_uid//[[:space:]]/}"
  recorded_name="${recorded_name//[[:space:]]/}"
  site_path="$(host_path "/home/testlig.net")"
  if [ -d "$site_path" ]; then
    if [ -n "$root" ] && [ -f "${root}/fixture-stat" ]; then
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

process_identity_matches() {
  local output="$1"
  local seen_euid="" seen_egid="" groups="" group_id="" sorted=""
  if printf '%s\n' "$output" | grep -Eq '^process_identity=unavailable$'; then
    echo "process_identity=unavailable"
    return 1
  fi
  seen_euid="$(printf '%s\n' "$output" | awk -F= '$1 == "process_euid" { if (seen) exit 3; seen = 1; print $2 }')"
  if [ "$?" -eq 3 ] || ! numeric_id "$seen_euid"; then
    echo "process_identity_match=no"
    return 1
  fi
  seen_egid="$(printf '%s\n' "$output" | awk -F= '$1 == "process_egid" { if (seen) exit 3; seen = 1; print $2 }')"
  if [ "$?" -eq 3 ] || ! numeric_id "$seen_egid"; then
    echo "process_identity_match=no"
    return 1
  fi
  groups="$(printf '%s\n' "$output" | awk -F= '$1 == "process_group" { print $2 }')"
  if [ -z "$groups" ]; then
    echo "process_identity_match=no"
    return 1
  fi
  for group_id in $groups; do
    numeric_id "$group_id" || {
      echo "process_identity_match=no"
      return 1
    }
  done
  sorted="$(printf '%s\n' $groups | sort -n | tr '\n' ' ' | sed 's/ $//')"
  if [ "$seen_euid" = "$recorded_euid" ] && [ "$seen_egid" = "$recorded_egid" ] && [ "$sorted" = "$recorded_group" ]; then
    echo "process_identity_match=yes"
    return 0
  fi
  echo "process_identity_match=no"
  return 1
}

php_user_count=0
php_proc_dirs=0
php_proc_unreadable=0
php_proc_vanished=0
php_proc_lsphp=0
php_proc_invalid_id=0
candidate_uid=""
candidate_user=""
php_started="$(probe_now)"
php_timed_out=0
seen_uids=" "
for proc in "${root}/proc"/[0-9]*; do
  if [ ! -d "$proc" ]; then
    case "$proc" in
      *'[0-9]*') continue ;;
    esac
    if [ -e "$proc" ] || [ -L "$proc" ]; then
      php_proc_dirs=$((php_proc_dirs + 1))
      php_proc_vanished=$((php_proc_vanished + 1))
    fi
    continue
  fi
  php_proc_dirs=$((php_proc_dirs + 1))
  now="$(probe_now)"
  if [ $((now - php_started)) -ge 2 ]; then
    php_timed_out=1
    break
  fi
  if [ ! -d "$proc" ]; then
    php_proc_vanished=$((php_proc_vanished + 1))
    continue
  fi
  if [ ! -e "$proc/comm" ] || [ ! -e "$proc/status" ]; then
    php_proc_vanished=$((php_proc_vanished + 1))
    continue
  fi
  if [ ! -r "$proc/comm" ] || [ ! -r "$proc/status" ]; then
    if [ ! -d "$proc" ] || [ ! -e "$proc/comm" ] || [ ! -e "$proc/status" ]; then
      php_proc_vanished=$((php_proc_vanished + 1))
    else
      php_proc_unreadable=$((php_proc_unreadable + 1))
    fi
    continue
  fi
  if ! IFS= read -r comm < "$proc/comm"; then
    if [ -d "$proc" ] && [ -e "$proc/comm" ]; then
      php_proc_unreadable=$((php_proc_unreadable + 1))
    else
      php_proc_vanished=$((php_proc_vanished + 1))
    fi
    continue
  fi
  printf '%s' "$comm" | grep -Eq '^lsphp' || continue
  php_proc_lsphp=$((php_proc_lsphp + 1))
  uid="$(awk '/^Uid:/ { print $2; exit }' "$proc/status" 2>/dev/null || true)"
  gid="$(awk '/^Gid:/ { print $2; exit }' "$proc/status" 2>/dev/null || true)"
  if [ ! -d "$proc" ] || [ ! -e "$proc/status" ]; then
    php_proc_vanished=$((php_proc_vanished + 1))
    continue
  fi
  if ! printf '%s' "$uid" | grep -Eq '^[0-9]+$' || ! printf '%s' "$gid" | grep -Eq '^[0-9]+$'; then
    php_proc_invalid_id=$((php_proc_invalid_id + 1))
    continue
  fi
  user="$(probe_id -nu "$uid" 2>/dev/null || true)"
  printf '%s' "$user" | grep -Eq '^[A-Za-z0-9._-]+$' || user="unknown"
  group_names=""
  group_ids="$(awk '/^Groups:/ { for (i = 2; i <= NF && i <= 33; i++) printf "%s ", $i; exit }' "$proc/status" 2>/dev/null || true)"
  for group_id in $group_ids; do
    printf '%s' "$group_id" | grep -Eq '^[0-9]+$' || continue
    group_name="$(getent group "$group_id" 2>/dev/null | awk -F: 'NR == 1 { print $1; exit }' || true)"
    printf '%s' "$group_name" | grep -Eq '^[A-Za-z0-9._-]+$' || group_name="$group_id"
    if [ -n "$group_names" ]; then
      group_names="${group_names},${group_name}"
    else
      group_names="$group_name"
    fi
  done
  if [ -z "$group_names" ]; then
    group_names="none"
  fi
  echo "php_process uid=${uid} gid=${gid} user=${user} groups=${group_names}"
  case "$seen_uids" in
    *" ${uid} "*) ;;
    *)
      seen_uids="${seen_uids}${uid} "
      php_user_count=$((php_user_count + 1))
      candidate_uid="$uid"
      candidate_user="$user"
      ;;
  esac
done
echo "php_proc_dirs=${php_proc_dirs}"
echo "php_proc_unreadable=${php_proc_unreadable}"
echo "php_proc_vanished=${php_proc_vanished}"
echo "php_proc_lsphp=${php_proc_lsphp}"
echo "php_proc_invalid_id=${php_proc_invalid_id}"
if [ "$php_timed_out" -eq 1 ]; then
  echo "php_process=timed_out"
elif [ "$php_proc_vanished" -gt 0 ]; then
  echo "php_process=incomplete"
elif [ "$php_proc_unreadable" -gt 0 ]; then
  echo "php_process=unreadable"
elif [ "$php_proc_lsphp" -eq 0 ]; then
  echo "php_process=none"
elif [ "$php_proc_invalid_id" -gt 0 ]; then
  echo "php_process=invalid_id"
elif [ "$php_user_count" -ne 1 ]; then
  echo "php_process=multiple"
else
  echo "php_process=observed"
fi

scan_conf=""
for candidate in "${root}/etc/clamd.d/scan.conf" "${root}/etc/clamav/clamd.conf" "${root}/usr/local/etc/clamd.conf"; do
  if [ -f "$candidate" ]; then
    scan_conf="$candidate"
    break
  fi
done
fresh_conf=""
for candidate in "${root}/etc/freshclam.conf" "${root}/etc/clamav/freshclam.conf" "${root}/usr/local/etc/freshclam.conf"; do
  if [ -f "$candidate" ]; then
    fresh_conf="$candidate"
    break
  fi
done

if [ -n "$scan_conf" ]; then
  echo "clamd_conf=present"
  emit_field "clamd_local_socket" "$scan_conf" "LocalSocket"
  emit_field "clamd_local_socket_group" "$scan_conf" "LocalSocketGroup"
  emit_field "clamd_local_socket_mode" "$scan_conf" "LocalSocketMode"
  emit_field "clamd_tcp_socket" "$scan_conf" "TCPSocket"
  emit_field "clamd_database_directory" "$scan_conf" "DatabaseDirectory"
else
  echo "clamd_conf=absent"
fi
if [ -n "$fresh_conf" ]; then
  echo "freshclam_conf=present"
  emit_field "freshclam_database_directory" "$fresh_conf" "DatabaseDirectory"
else
  echo "freshclam_conf=absent"
fi

socket_path=""
if [ -n "$scan_conf" ]; then
  socket_value="$(conf_value "$scan_conf" "LocalSocket" || true)"
  if safe_path "$socket_value"; then
    socket_path="$socket_value"
    echo "socket_path=${socket_path}"
  else
    echo "socket_path=rejected"
  fi
else
  echo "socket_path=unknown"
fi

database_dir=""
scan_db="$( [ -n "$scan_conf" ] && conf_value "$scan_conf" "DatabaseDirectory" || true )"
fresh_db="$( [ -n "$fresh_conf" ] && conf_value "$fresh_conf" "DatabaseDirectory" || true )"
scan_db_status=0
fresh_db_status=0
[ -n "$scan_conf" ] && conf_value "$scan_conf" "DatabaseDirectory" >/dev/null 2>&1 || scan_db_status=$?
[ -n "$fresh_conf" ] && conf_value "$fresh_conf" "DatabaseDirectory" >/dev/null 2>&1 || fresh_db_status=$?
if [ "$scan_db_status" -eq 3 ] || [ "$fresh_db_status" -eq 3 ]; then
  echo "database_directory=contradictory"
elif [ "$scan_db_status" -eq 0 ] && [ "$fresh_db_status" -eq 0 ] && [ "$scan_db" != "$fresh_db" ]; then
  echo "database_directory=mismatch"
elif [ "$scan_db_status" -eq 0 ] && { [ "$fresh_db_status" -eq 0 ] || [ "$fresh_db_status" -eq 1 ]; }; then
  if safe_path "$scan_db"; then
    database_dir="$scan_db"
    echo "database_directory=${database_dir}"
    if [ "$fresh_db_status" -eq 0 ]; then
      echo "database_directory_agreement=match"
    else
      echo "database_directory_agreement=freshclam_unset"
    fi
  else
    echo "database_directory=rejected"
  fi
elif [ "$scan_db_status" -eq 1 ] && [ "$fresh_db_status" -eq 0 ]; then
  if safe_path "$fresh_db"; then
    database_dir="$fresh_db"
    echo "database_directory=${database_dir}"
    echo "database_directory_agreement=clamd_unset"
  else
    echo "database_directory=rejected"
  fi
elif [ "$scan_db_status" -eq 1 ] && [ "$fresh_db_status" -eq 1 ]; then
  if command -v clamconf >/dev/null 2>&1; then
    set -o pipefail
    clamconf_db="$(timeout 2 clamconf 2>/dev/null | awk -F'"' '/^DatabaseDirectory = "/ { if (seen && value != $2) exit 2; seen = 1; value = $2 } END { if (!seen) exit 1; print value }')"
    clamconf_status=$?
    set +o pipefail
    if [ "$clamconf_status" -eq 124 ]; then
      echo "database_directory=timed_out"
    elif [ "$clamconf_status" -eq 2 ]; then
      echo "database_directory=contradictory"
    elif safe_path "$clamconf_db"; then
      database_dir="$clamconf_db"
      echo "database_directory=${database_dir}"
      echo "database_directory_agreement=clamconf"
    else
      echo "database_directory=unknown"
    fi
  else
    echo "database_directory=unknown"
  fi
else
  echo "database_directory=unknown"
fi

emit_stat() {
  local label="$1"
  local path="$2"
  if [ ! -e "$path" ] && [ ! -S "$path" ]; then
    echo "${label}_stat=absent"
    return
  fi
  local stat_line
  stat_line="$(limited 2 stat -c 'owner=%U group=%G mode=%a' "$path")"
  echo "${label}_stat=${stat_line}"
  if command -v getfacl >/dev/null 2>&1; then
    local acl
    acl="$(limited 2 getfacl -cp "$path")"
    if [ "$acl" = "timed_out" ] || [ "$acl" = "unknown" ]; then
      echo "${label}_acl=${acl}"
    else
      printf '%s\n' "$acl" | awk -v label="$label" '
        $0 ~ /^(user|group|mask|other|default):/ { print label "_acl=" $0 }
      '
    fi
  else
    echo "${label}_acl=unknown"
  fi
  if command -v ls >/dev/null 2>&1; then
    local context
    context="$(limited 2 ls -Zd "$path")"
    context="$(printf '%s\n' "$context" | awk '{ print $1 }')"
    case "$context" in
      timed_out|unknown) echo "${label}_selinux=${context}" ;;
      *[!A-Za-z0-9._:-]*) echo "${label}_selinux=unknown" ;;
      ?*) echo "${label}_selinux=${context}" ;;
      *) echo "${label}_selinux=unknown" ;;
    esac
  else
    echo "${label}_selinux=unknown"
  fi
}

if [ -n "$socket_path" ]; then
  emit_stat "socket" "$(host_path "$socket_path")"
  ancestor="$(dirname "$socket_path")"
  depth=0
  while [ -n "$ancestor" ] && [ "$ancestor" != "/" ] && [ "$depth" -lt 8 ]; do
    emit_stat "socket_ancestor" "$(host_path "$ancestor")"
    next="$(dirname "$ancestor")"
    [ "$next" = "$ancestor" ] && break
    ancestor="$next"
    depth=$((depth + 1))
  done
else
  echo "socket_stat=unknown"
fi

if command -v getenforce >/dev/null 2>&1; then
  mode="$(limited 2 getenforce)"
  case "$mode" in
    Enforcing|Permissive|Disabled) echo "selinux_mode=${mode}" ;;
    *) echo "selinux_mode=unknown" ;;
  esac
else
  echo "selinux_mode=unknown"
fi

header_version() {
  local file="$1"
  local line=""
  local version=""
  IFS= read -r -n 400 line < "$file" || true
  line="${line%$'\r'}"
  case "$line" in
    ClamAV-VDB:*)
      rest="${line#ClamAV-VDB:}"
      rest="${rest#*:}"
      version="${rest%%:*}"
      ;;
    *) version="" ;;
  esac
  case "$version" in
    0|[1-9]|[1-9][0-9]*) printf '%s\n' "$version" ;;
    *) printf '%s\n' "unreadable" ;;
  esac
}

  if [ -n "$database_dir" ]; then
    emit_stat "signature_dir" "$(host_path "$database_dir")"
  for name in main daily bytecode; do
    found=0
    versions=""
    for extension in cvd cld; do
      file="$(host_path "${database_dir}/${name}.${extension}")"
      if [ -f "$file" ]; then
        found=1
        echo "signature_file=${name}.${extension}"
        emit_stat "signature_${name}_${extension}" "$file"
        version="$(header_version "$file")"
        echo "signature_${name}_${extension}_version=${version}"
        versions="${versions} ${version}"
      fi
    done
    if [ "$found" -eq 0 ]; then
      echo "signature_${name}=absent"
    fi
    distinct="$(printf '%s\n' $versions | awk 'NF && !seen[$0]++ { count++ } END { print count + 0 }')"
    if [ "$distinct" -gt 1 ]; then
      echo "signature_${name}_version=contradictory"
    fi
  done
else
  echo "signature_file=unknown"
fi

dac_check() {
  local label="$1"
  local path="$2"
  shift 2
  local flag result status
  if [ "$identity_ok" -ne 1 ]; then
    echo "${label}_dac=not_attempted"
    return
  fi
  for flag in "$@"; do
    if as_app_timed test "-${flag}" "$path" 2>/dev/null; then
      result="yes"
    else
      status=$?
      if [ "$status" -eq 124 ]; then
        result="timed_out"
      else
        result="no"
      fi
    fi
    echo "${label}_dac_${flag}=${result}"
  done
}

prepare_probe_php_code || true

if recorded_site_flags; then
  if [ -n "$root" ] && [ -f "${root}/fixture-php" ] && [ -z "${ACCESS_PROBE_PHP_B64:-}" ]; then
    identity_output="$(probe_php -- --identity 2>/dev/null || true)"
    if process_identity_matches "$identity_output"; then
      identity_ok=1
    fi
  else
    identity_output="$(collect_recorded_identity)"
    identity_status=$?
    printf '%s\n' "$identity_output"
    if [ "$identity_status" -eq 0 ]; then
      if process_identity_matches "$identity_output"; then
        identity_ok=1
      fi
    else
      echo "process_identity=unavailable"
    fi
  fi
else
  echo "process_identity_match=no"
fi
echo "identity_caller_continues=yes"

if [ "$identity_ok" -eq 1 ]; then
  if [ -n "$socket_path" ]; then
    dac_check "socket" "$(host_path "$socket_path")" r w
    ancestor="$(dirname "$socket_path")"
    depth=0
    while [ -n "$ancestor" ] && [ "$ancestor" != "/" ] && [ "$depth" -lt 8 ]; do
      dac_check "socket_ancestor_${depth}" "$(host_path "$ancestor")" r w x
      next="$(dirname "$ancestor")"
      [ "$next" = "$ancestor" ] && break
      ancestor="$next"
      depth=$((depth + 1))
    done
  fi
  if [ -n "$database_dir" ]; then
    dac_check "signature_dir" "$(host_path "$database_dir")" r w x
    for name in main daily bytecode; do
      for extension in cvd cld; do
        file="$(host_path "${database_dir}/${name}.${extension}")"
        if [ -f "$file" ]; then
          dac_check "signature_${name}_${extension}" "$file" r w
        fi
      done
    done
  fi
else
  echo "app_dac=not_attempted"
fi

unit_state() {
  local unit="$1"
  local field="$2"
  local value
  value="$(limited 2 systemctl "$field" "$unit")"
  case "$value" in
    active|inactive|failed|activating|deactivating|enabled|disabled|static|indirect|generated|transient|enabled-runtime) echo "${unit}_${field}=${value}" ;;
    timed_out|unknown) echo "${unit}_${field}=${value}" ;;
    *) echo "${unit}_${field}=unknown" ;;
  esac
}
unit_state "clamd@scan.service" "is-active"
unit_state "clamd@scan.service" "is-enabled"
unit_state "clamav-freshclam.service" "is-active"
unit_state "clamav-freshclam.service" "is-enabled"

app_ping="not_attempted"
app_version="not_attempted"
if [ "$identity_ok" -eq 1 ] && [ -n "$socket_path" ] && { [ "$probe_php_code_ready" -eq 1 ] || [ -n "$root" ]; }; then
  socket_read="no"
  socket_write="no"
  if as_app_timed test -r "$(host_path "$socket_path")" 2>/dev/null; then
    socket_read="yes"
  fi
  if as_app_timed test -w "$(host_path "$socket_path")" 2>/dev/null; then
    socket_write="yes"
  fi
  if [ "$socket_read" = "yes" ] && [ "$socket_write" = "yes" ]; then
    if [ "$probe_php_code_ready" -eq 1 ]; then
      probe="$(
        set +e
        invoke_probe_php "$(host_path "$socket_path")"
        printf '\nprobe_stage_status=%s\n' "$probe_php_status"
      )"
      probe_status="$(printf '%s\n' "$probe" | awk '/^probe_stage_status=/ { line = $0 } END { print line }')"
      probe_status="${probe_status#probe_stage_status=}"
      probe="$(printf '%s\n' "$probe" | awk '!/^probe_stage_status=/')"
    elif [ -n "$root" ]; then
      probe="$(probe_php -- "$(host_path "$socket_path")" 2>/dev/null || true)"
      probe_status=0
    else
      probe=""
      probe_status=127
    fi
    if [ "$probe_status" -eq 124 ]; then
      app_ping="timed_out"
      app_version="timed_out"
    elif printf '%s\n' "$probe" | grep -Eq '^process_identity_match=no$'; then
      echo "connecting_process_identity_match=no"
      app_ping="not_attempted"
      app_version="not_attempted"
    else
      printf '%s\n' "$probe" | awk '
        $0 ~ /^app_ping=(pong|eof|partial|timed_out|unavailable|unreadable)$/ { ping = substr($0, 10) }
        $0 ~ /^app_version_daily=(0|[1-9][0-9]*|eof|partial|timed_out|unavailable|unreadable)$/ { version = substr($0, 19) }
        END {
          if (ping == "") ping = "unreadable"
          if (version == "") version = "unreadable"
          print "app_ping=" ping
          print "app_version_daily=" version
        }
      '
      app_ping="reported"
      app_version="reported"
    fi
  fi
fi
if [ "$app_ping" != "reported" ]; then
  echo "app_ping=${app_ping}"
  echo "app_version_daily=${app_version}"
fi

env_key_status() {
  local file="$1"
  local key="$2"
  local expected="$3"
  local label="$4"
  local value status
  if [ ! -f "$file" ]; then
    echo "${label}=absent"
    return
  fi
  value="$(timeout 2 awk -v key="$key" '
    {
      line = $0
      sub(/\r/, "", line)
      sub(/^[[:space:]]*export[[:space:]]+/, "", line)
      if (line ~ /^[[:space:]]*#/) { next }
      if (index(line, key "=") != 1) { next }
      if (seen) { exit 3 }
      seen = 1
      value = substr(line, length(key) + 2)
      gsub(/^[[:space:]]+|[[:space:]]+$/, "", value)
      gsub(/^["'\'']|["'\'']$/, "", value)
      print value
    }
    END { if (!seen) exit 2 }
  ' "$file" 2>/dev/null)"
  status=$?
  if [ "$status" -eq 124 ]; then
    echo "${label}=timed_out"
  elif [ "$status" -eq 3 ]; then
    echo "${label}=contradictory"
  elif [ "$status" -eq 2 ]; then
    echo "${label}=absent"
  elif [ "$status" -ne 0 ]; then
    echo "${label}=unknown"
  elif [ -z "$value" ]; then
    echo "${label}=empty"
  elif [ "${#value}" -gt 200 ]; then
    echo "${label}=rejected"
  elif [ -z "$expected" ]; then
    echo "${label}=uncompared"
  elif [ "$value" = "$expected" ]; then
    echo "${label}=match"
  else
    echo "${label}=differ"
  fi
}

env_key_status "${root}/home/testlig.net/shared/.env.local" "QUESTION_IMAGE_CLAMAV_SOCKET" "$socket_path" "shared_socket_key"
env_key_status "${root}/home/testlig.net/shared/.env.local" "QUESTION_IMAGE_CLAMAV_DATABASE" "$database_dir" "shared_database_key"
env_key_status "${root}/home/testlig.net/app/.env.local" "QUESTION_IMAGE_CLAMAV_SOCKET" "$socket_path" "release_socket_key"
env_key_status "${root}/home/testlig.net/app/.env.local" "QUESTION_IMAGE_CLAMAV_DATABASE" "$database_dir" "release_database_key"
echo "env_values_logged=no"
