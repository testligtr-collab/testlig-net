#!/usr/bin/env bash
# Read-only measurement of ClamAV socket and signature visibility for the live lsphp uid.
# It does not install, scan a file, change services, groups, ACL, or env files.
# A completed run is not application access. test -r/-w/-x is a DAC check and does not prove SELinux.
# A root connection is not application access. The container is not warmed and env values are not embedded.
set -u

echo "access_probe=read-only"
echo "access_probe_changes_access=no"
echo "dac_check=test_rwx"
echo "dac_check_proves_selinux=no"
echo "root_connection_counts_as_app=no"
echo "loaded_main=unproven"
echo "loaded_bytecode=unproven"

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

php_user_count=0
app_user=""
php_started="$(date +%s)"
php_timed_out=0
seen_uids=" "
for proc in "${root}/proc"/[0-9]*; do
  [ -d "$proc" ] || continue
  now="$(date +%s)"
  if [ $((now - php_started)) -ge 2 ]; then
    php_timed_out=1
    break
  fi
  [ -r "$proc/comm" ] && [ -r "$proc/status" ] || continue
  IFS= read -r comm < "$proc/comm" || continue
  printf '%s' "$comm" | grep -Eq '^lsphp' || continue
  uid="$(awk '/^Uid:/ { print $2; exit }' "$proc/status" 2>/dev/null || true)"
  gid="$(awk '/^Gid:/ { print $2; exit }' "$proc/status" 2>/dev/null || true)"
  printf '%s' "$uid" | grep -Eq '^[0-9]+$' || continue
  printf '%s' "$gid" | grep -Eq '^[0-9]+$' || continue
  user="$(id -nu "$uid" 2>/dev/null || true)"
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
      app_user="$user"
      ;;
  esac
done
if [ "$php_timed_out" -eq 1 ]; then
  echo "php_process=timed_out"
  app_user=""
elif [ "$php_user_count" -eq 0 ]; then
  echo "php_process=unknown"
  app_user=""
elif [ "$php_user_count" -ne 1 ]; then
  echo "app_user=unresolved"
  app_user=""
elif [ "$app_user" = "root" ] || [ "$app_user" = "unknown" ]; then
  echo "app_user=not_app_user"
  app_user=""
else
  echo "app_user_basis=single_live_lsphp_uid"
  echo "app_user=${app_user}"
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
  local flag result
  if [ -z "$app_user" ] || ! command -v runuser >/dev/null 2>&1; then
    echo "${label}_dac=unknown"
    return
  fi
  for flag in r w x; do
    if timeout 2 runuser -u "$app_user" -- test "-${flag}" "$path" 2>/dev/null; then
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

if [ -n "$app_user" ] && [ "$app_user" != "root" ]; then
  if [ -n "$socket_path" ]; then
    dac_check "socket" "$(host_path "$socket_path")"
  fi
  if [ -n "$database_dir" ]; then
    dac_check "signature_dir" "$(host_path "$database_dir")"
    for name in main daily bytecode; do
      for extension in cvd cld; do
        file="$(host_path "${database_dir}/${name}.${extension}")"
        if [ -f "$file" ]; then
          dac_check "signature_${name}_${extension}" "$file"
        fi
      done
    done
  fi
else
  echo "app_dac=unknown"
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
php_bin="/usr/local/lsws/lsphp83/bin/php"
if [ -n "$app_user" ] && [ "$app_user" != "root" ] && [ -n "$socket_path" ] && [ -x "$php_bin" ]; then
  socket_read="no"
  socket_write="no"
  if timeout 2 runuser -u "$app_user" -- test -r "$(host_path "$socket_path")" 2>/dev/null; then
    socket_read="yes"
  fi
  if timeout 2 runuser -u "$app_user" -- test -w "$(host_path "$socket_path")" 2>/dev/null; then
    socket_write="yes"
  fi
  if [ "$socket_read" = "yes" ] && [ "$socket_write" = "yes" ]; then
    probe="$(timeout 5 runuser -u "$app_user" -- "$php_bin" -r '
$path = $argv[1] ?? "";
$socket = @stream_socket_client("unix://".$path, $errno, $errstr, 5);
if (!is_resource($socket)) { echo "ping_failed\nversion_failed\n"; exit(0); }
stream_set_timeout($socket, 5);
fwrite($socket, "nPING\n");
$ping = fread($socket, 32);
$meta = stream_get_meta_data($socket);
if (!empty($meta["timed_out"]) || "PONG\n" !== $ping) { echo "ping_failed\n"; } else { echo "pong\n"; }
fwrite($socket, "nVERSION\n");
$version = fread($socket, 256);
$meta = stream_get_meta_data($socket);
if (!empty($meta["timed_out"])) { echo "version_timed_out\n"; exit(0); }
if (1 === preg_match("/^ClamAV [0-9]+(?:\\.[0-9]+){1,3}\\/(0|[1-9][0-9]*)\\/[A-Za-z]{3} [A-Za-z]{3} [ 0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2} [0-9]{4}\\n$/", $version, $matches)) {
    echo "daily=".$matches[1]."\n";
} else { echo "version_unreadable\n"; }
' "$(host_path "$socket_path")" 2>/dev/null)"
    probe_status=$?
    if [ "$probe_status" -eq 124 ]; then
      app_ping="timed_out"
      app_version="timed_out"
    else
      printf '%s\n' "$probe" | awk '
        $0 == "pong" { ping = "pong" }
        $0 == "ping_failed" { ping = "unavailable" }
        $0 == "version_failed" || $0 == "version_unreadable" { version = "unreadable" }
        $0 == "version_timed_out" { version = "timed_out" }
        $0 ~ /^daily=(0|[1-9][0-9]*)$/ { version = substr($0, 7) }
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
