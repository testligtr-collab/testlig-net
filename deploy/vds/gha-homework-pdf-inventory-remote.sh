#!/usr/bin/env bash
# Read-only homework PDF inventory and scan-readiness preview. One SSH session, no writes.
# The release is verified first: the app link must resolve to a release directory whose REVISION file
# carries the exact expected 40-character SHA. Only then is the inventory command run once, as the
# application account, from that directory. Its output is reduced to allowlisted counters and states.
# Readiness lines are measurements with their source. A configured value is never recorded as a running
# or web-verified value, and a CLI or root view is never recorded as web access.
# It does not install, create a directory, change a permission, flag, service or setting, delete or move
# a file, upload a PDF, scan a sample, or write to the database.
set -u
set -o pipefail
exec 2>/dev/null

root="${TESTLIG_HW_INVENTORY_ROOT:-}"
site_root="/home/testlig.net"
app_user="testl3865"
php_bin="/usr/local/lsws/lsphp83/bin/php"
expected_storage="/home/testlig.net/shared/var/homework-pdf"
app_limit_bytes=26214400
inventory_timeout_seconds=300

host_path() {
  if [ -n "$root" ]; then
    printf '%s%s' "$root" "$1"
  else
    printf '%s' "$1"
  fi
}

matches() {
  printf '%s' "$1" | grep -Eq -- "$2"
}

limited() {
  local seconds="$1"
  shift
  timeout "$seconds" "$@" </dev/null 2>/dev/null
}

now_epoch() {
  local fixture
  fixture="$(host_path "/fixture-now")"
  if [ -n "$root" ] && [ -f "$fixture" ]; then
    tr -d '[:space:]' <"$fixture"
    return 0
  fi
  date +%s
}

# Sets READ_ENV_VALUE for the caller only. The value itself is never printed.
# Returns 0 found, 1 unreadable, 2 absent, 3 contradictory.
READ_ENV_VALUE=""
read_env_value() {
  local file="$1" key="$2" value status
  READ_ENV_VALUE=""
  if [ ! -e "$file" ]; then
    return 2
  fi
  if [ ! -f "$file" ] || [ ! -r "$file" ]; then
    return 1
  fi
  value="$(timeout 3 awk -v key="$key" '
    {
      line = $0
      sub(/\r$/, "", line)
      sub(/^[ \t]*export[ \t]+/, "", line)
      if (line ~ /^[ \t]*#/) { next }
      if (index(line, key "=") != 1) { next }
      if (seen) { exit 3 }
      seen = 1
      value = substr(line, length(key) + 2)
      gsub(/^[ \t]+|[ \t]+$/, "", value)
      gsub(/^["'\'']|["'\'']$/, "", value)
      print value
    }
    END { if (!seen) exit 2 }
  ' "$file" </dev/null 2>/dev/null)"
  status=$?
  case "$status" in
    0) ;;
    2) return 2 ;;
    3) return 3 ;;
    *) return 1 ;;
  esac
  READ_ENV_VALUE="$value"
  return 0
}

shared_env_file() {
  host_path "${site_root}/shared/.env.local"
}

storage_dir_config() {
  read_env_value "$(shared_env_file)" "HOMEWORK_PDF_STORAGE_DIR"
  case $? in
    0)
      if [ -z "$READ_ENV_VALUE" ]; then
        echo "empty"
      elif [ "$READ_ENV_VALUE" = "$expected_storage" ]; then
        echo "expected_shared"
      else
        echo "differs"
      fi
      ;;
    2) echo "absent" ;;
    3) echo "contradictory" ;;
    *) echo "unreadable" ;;
  esac
}

flag_state() {
  read_env_value "$(shared_env_file)" "$1"
  case $? in
    0)
      case "$READ_ENV_VALUE" in
        0) echo "0" ;;
        1) echo "1" ;;
        '') echo "empty" ;;
        *) echo "other" ;;
      esac
      ;;
    2) echo "absent" ;;
    3) echo "contradictory" ;;
    *) echo "unreadable" ;;
  esac
}

to_bytes() {
  local value="$1" digits unit multiplier
  value="${value//[[:space:]]/}"
  case "$value" in
    ''|*[!0-9KkMmGg]*) echo "unparsed"; return 0 ;;
  esac
  digits="${value%%[!0-9]*}"
  unit="${value#"$digits"}"
  if [ -z "$digits" ] || [ "${#digits}" -gt 12 ]; then
    echo "unparsed"
    return 0
  fi
  case "$unit" in
    '') multiplier=1 ;;
    K|k) multiplier=1024 ;;
    M|m) multiplier=1048576 ;;
    G|g) multiplier=1073741824 ;;
    *) echo "unparsed"; return 0 ;;
  esac
  echo $((10#$digits * multiplier))
}

limit_class() {
  local bytes="$1"
  case "$bytes" in
    unparsed) echo "unparsed"; return 0 ;;
    '') echo "unset"; return 0 ;;
  esac
  if [ "$bytes" -eq 0 ]; then
    echo "unlimited_or_zero"
  elif [ "$bytes" -ge "$app_limit_bytes" ]; then
    echo "ge_25mib"
  else
    echo "lt_25mib"
  fi
}

conf_value() {
  local file="$1" key="$2"
  timeout 3 awk -v key="$key" '
    /^[ \t]*#/ || /^[ \t]*$/ { next }
    $1 == key {
      value = substr($0, length($1) + 1)
      gsub(/\r/, "", value)
      gsub(/^[ \t]+|[ \t]+$/, "", value)
      gsub(/^"|"$/, "", value)
      found = value
    }
    END { if (found == "") exit 1; print found }
  ' "$file" </dev/null 2>/dev/null
}

safe_path() {
  printf '%s' "$1" | grep -Eq '^/([A-Za-z0-9._-]{1,64}/){0,16}[A-Za-z0-9._-]{1,64}$'
}

echo "hw_pdf_ops=read-only"
echo "hw_pdf_ops_changes=no"
echo "hw_pdf_ops_ssh_sessions=1"
echo "upload_gate_changed=no"
echo "scan_gate_changed=no"
echo "inventory_threshold_is_retention_policy=no"
echo "cli_or_root_is_web_acceptance=no"
echo "measured_utc=$(date -u +%Y-%m-%dT%H:%M:%SZ)"

# --- Release verification (before any inventory) -------------------------------------------------
expected="${EXPECTED_SHA:-}"
release_dir=""
release_check="not_checked"

if matches "$expected" '^[0-9a-f]{40}$'; then
  echo "expected_sha=${expected}"
else
  echo "expected_sha=invalid"
  release_check="expected_sha_invalid"
fi

if [ "$release_check" = "not_checked" ]; then
  app_link="$(host_path "${site_root}/app")"
  releases_dir="$(host_path "${site_root}/releases")"
  if [ ! -L "$app_link" ]; then
    echo "app_link=absent"
    release_check="app_link_absent"
  else
    target="$(readlink -f "$app_link" 2>/dev/null || true)"
    rest="${target#"${releases_dir}"/}"
    if [ -z "$target" ] || [ "$rest" = "$target" ]; then
      echo "app_link=outside_releases"
      release_check="app_link_outside_releases"
    elif [ -z "$rest" ] || [[ "$rest" == */* ]] || ! matches "$rest" '^[A-Za-z0-9._-]{1,80}$'; then
      echo "app_link=unexpected_target"
      release_check="app_link_unexpected_target"
    elif [ ! -d "$target" ]; then
      echo "app_link=target_not_directory"
      release_check="app_link_target_missing"
    else
      echo "app_link=release_directory"
      echo "release_name=${rest}"
      revision_file="${target}/REVISION"
      if [ ! -f "$revision_file" ] || [ ! -r "$revision_file" ]; then
        echo "release_revision=absent"
        release_check="revision_absent"
      else
        revision="$(head -c 128 "$revision_file" 2>/dev/null | tr -d '[:space:]')"
        if matches "$revision" '^[0-9a-f]{40}$'; then
          echo "release_revision=${revision}"
          if [ "$revision" = "$expected" ]; then
            echo "release_revision_match=yes"
            release_check="ok"
            release_dir="$target"
          else
            echo "release_revision_match=no"
            release_check="revision_mismatch"
          fi
        else
          echo "release_revision=invalid"
          release_check="revision_invalid"
        fi
      fi
    fi
  fi
fi

current_revision_file="$(host_path "${site_root}/shared/CURRENT_REVISION")"
if [ -r "$current_revision_file" ]; then
  current_revision="$(head -c 128 "$current_revision_file" 2>/dev/null | tr -d '[:space:]')"
  if ! matches "$current_revision" '^[0-9a-f]{40}$'; then
    echo "current_revision_match=invalid"
  elif [ "$current_revision" = "$expected" ]; then
    echo "current_revision_match=yes"
  else
    echo "current_revision_match=no"
  fi
else
  echo "current_revision_match=absent"
fi

command_in_release="no"
prod_cache="no"
if [ -n "$release_dir" ]; then
  if [ -f "${release_dir}/src/Command/HomeworkPdfInventoryCommand.php" ]; then
    command_in_release="yes"
  fi
  if compgen -G "${release_dir}/var/cache/prod/*KernelProdContainer*" >/dev/null; then
    prod_cache="yes"
  fi
  echo "inventory_command_in_release=${command_in_release}"
  echo "prod_cache_present=${prod_cache}"
fi

storage_config="$(storage_dir_config)"
echo "storage_dir_config=${storage_config}"
durable_dir="$(host_path "$expected_storage")"
durable_state="absent"
if [ -L "$durable_dir" ]; then
  durable_state="symlink"
elif [ -d "$durable_dir" ]; then
  durable_state="directory"
elif [ -e "$durable_dir" ]; then
  durable_state="not_directory"
fi
echo "durable_dir_state=${durable_state}"

# --- One inventory call ----------------------------------------------------------------------------
inventory_result="not_run"
inventory_exit="not_run"
inventory_status="not_run"
inventory_filtered=""
inventory_dropped=0

if [ "$release_check" != "ok" ]; then
  inventory_result="not_run_${release_check}"
elif [ "$command_in_release" != "yes" ]; then
  inventory_result="not_run_command_missing"
elif [ "$prod_cache" != "yes" ]; then
  inventory_result="not_run_prod_cache_missing"
elif [ "$storage_config" != "expected_shared" ]; then
  inventory_result="not_run_storage_root_unverified"
elif [ "$durable_state" = "symlink" ] || [ "$durable_state" = "not_directory" ]; then
  inventory_result="not_run_storage_root_unsafe"
elif [ -z "$root" ] && ! command -v runuser >/dev/null 2>&1; then
  inventory_result="not_run_runuser_missing"
else
  if [ -n "$root" ]; then
    raw="$(cd "$release_dir" && timeout "$inventory_timeout_seconds" bash "$(host_path "/fixture-console")" app:homework-pdf:inventory </dev/null 2>/dev/null)"
    inventory_exit=$?
  else
    raw="$(cd "$release_dir" && timeout "$inventory_timeout_seconds" runuser -u "$app_user" -- env HOME="$site_root" APP_ENV=prod APP_DEBUG=0 "$php_bin" bin/console app:homework-pdf:inventory --no-interaction </dev/null 2>/dev/null)"
    inventory_exit=$?
  fi
  keys=" homework_pdf_inventory_status homework_pdf_inventory_code preview_only writes point_in_time orphan_min_age_seconds store_empty"
  keys="${keys} disk_files disk_bytes matched_files matched_bytes unreferenced_young_files unreferenced_young_bytes"
  keys="${keys} timestamp_unreliable_files orphan_candidate_files orphan_candidate_bytes orphan_candidate_temp_files"
  keys="${keys} unsafe_entries unsafe_symlink unsafe_directory unsafe_special unsafe_invalid_name unsafe_stat_failed"
  keys="${keys} db_references db_declared_bytes db_physical_bytes db_missing_file db_unreadable_file db_size_mismatch db_invalid_key"
  keys="${keys} duplicate_reference ambiguous_owner_references"
  keys="${keys} draft_references draft_declared_bytes draft_physical_bytes"
  keys="${keys} submitted_references submitted_declared_bytes submitted_physical_bytes"
  keys="${keys} evaluated_references evaluated_declared_bytes evaluated_physical_bytes"
  keys="${keys} unknown_lifecycle_references draft_older_than_threshold db_declared_minus_physical_bytes "
  text_keys=" homework_pdf_inventory_status homework_pdf_inventory_code preview_only writes point_in_time store_empty "
  inventory_filtered="$(printf '%s\n' "$raw" | awk -F= -v keys="$keys" -v text_keys="$text_keys" '
    NR == 201 { exit }
    NF == 2 && index(keys, " " $1 " ") != 0 {
      if (index(text_keys, " " $1 " ") != 0) {
        if ($2 ~ /^[a-z_]+$/ && length($2) <= 32) { print; next }
      } else if ($2 ~ /^-?[0-9]+$/ && length($2) <= 19) { print; next }
    }
    $0 != "" { dropped++ }
    END { print "inventory_output_dropped_lines=" dropped + 0 }
  ' 2>/dev/null)"
  inventory_dropped="$(printf '%s\n' "$inventory_filtered" | awk -F= '$1 == "inventory_output_dropped_lines" { print $2 }')"
  inventory_filtered="$(printf '%s\n' "$inventory_filtered" | awk -F= '$1 != "inventory_output_dropped_lines"')"
  inventory_status="$(printf '%s\n' "$inventory_filtered" | awk -F= '$1 == "homework_pdf_inventory_status" { print $2; exit }')"
  store_empty="$(printf '%s\n' "$inventory_filtered" | awk -F= '$1 == "store_empty" { print $2; exit }')"
  if [ "$inventory_exit" -eq 124 ]; then
    inventory_result="timed_out"
  else
    case "${inventory_exit}:${inventory_status}" in
      0:ok)
        if [ "$store_empty" = "yes" ]; then
          inventory_result="ok_store_empty"
        else
          inventory_result="ok"
        fi
        ;;
      0:root_missing) inventory_result="root_missing" ;;
      *:root_unsafe) inventory_result="root_unsafe" ;;
      *:root_unreadable) inventory_result="root_unreadable" ;;
      *:error) inventory_result="inventory_error" ;;
      *) inventory_result="failed" ;;
    esac
  fi
  [ -n "$inventory_status" ] || inventory_status="unknown"
fi

echo "inventory_result=${inventory_result}"
echo "inventory_exit_code=${inventory_exit}"
echo "inventory_status=${inventory_status}"
echo "inventory_options=none"
echo "inventory_output_dropped_lines=${inventory_dropped}"
if [ -n "$inventory_filtered" ]; then
  printf '%s\n' "$inventory_filtered"
fi

# --- Scan readiness (independent of the inventory result) ------------------------------------------
echo "readiness_section=preview_only_measurements"

ols_conf="$(host_path "/usr/local/lsws/conf")"
testlig_vhost=""
for candidate in "${ols_conf}/vhosts/testlig.net/vhconf.conf" "${ols_conf}/vhosts/testlig.net/vhost.conf"; do
  if [ -f "$candidate" ]; then
    testlig_vhost="$candidate"
    break
  fi
done

handler=""
if [ -n "$testlig_vhost" ]; then
  handler="$(awk '
    {
      for (i = 1; i <= NF; i++) {
        if ($i ~ /^\/usr\/local\/lsws\/lsphp[0-9]+\/bin\/(lsphp[0-9]*|php)$/) { print $i; exit }
      }
    }
  ' "$testlig_vhost" </dev/null 2>/dev/null)"
fi
handler_branch=""
if [ -n "$handler" ] && matches "$handler" '^/usr/local/lsws/lsphp[0-9]+/bin/(lsphp[0-9]*|php)$'; then
  handler_present="no"
  if [ -f "$(host_path "$handler")" ]; then
    handler_present="yes"
  fi
  echo "testlig_php_handler binary=${handler} present=${handler_present} source=vhost_config exposure=not_measured"
  handler_branch="${handler%/bin/*}"
else
  echo "testlig_php_handler=unknown"
fi

site_uid="$(stat -c %u "$(host_path "$site_root")" 2>/dev/null </dev/null || true)"
case "$site_uid" in
  ''|*[!0-9]*) site_uid="" ;;
esac

# Web workers: processes named lsphp whose own identity is read from /proc. Not the CLI and not root.
sock_uid=""
sock_gid=""
sock_mode=""
worker_total=0
worker_site=0
worker_printed=0
site_w_uid=()
site_w_gid=()
site_w_groups=()
dac_rw=0
dac_norw=0
proc_dir="$(host_path "/proc")"
shopt -s nullglob
for comm_file in "$proc_dir"/[0-9]*/comm; do
  [ -r "$comm_file" ] || continue
  name="$(tr -d '\r\n' <"$comm_file" 2>/dev/null)"
  case "$name" in
    lsphp|lsphp[0-9]*) ;;
    *) continue ;;
  esac
  worker_total=$((worker_total + 1))
  status_file="${comm_file%/comm}/status"
  [ -r "$status_file" ] || continue
  w_uid="$(awk '/^Uid:/ { print $2; exit }' "$status_file" </dev/null 2>/dev/null)"
  w_gid="$(awk '/^Gid:/ { print $2; exit }' "$status_file" </dev/null 2>/dev/null)"
  w_groups="$(awk '/^Groups:/ { for (i = 2; i <= NF && i <= 33; i++) printf "%s ", $i; exit }' "$status_file" </dev/null 2>/dev/null)"
  matches "$w_uid" '^[0-9]+$' || continue
  matches "$w_gid" '^[0-9]+$' || continue
  w_site="no"
  if [ -n "$site_uid" ] && [ "$w_uid" = "$site_uid" ]; then
    w_site="yes"
    worker_site=$((worker_site + 1))
  fi
  w_exe="$(readlink "${comm_file%/comm}/exe" 2>/dev/null || true)"
  w_exe="${w_exe% (deleted)}"
  matches "$w_exe" '^/usr/local/lsws/lsphp[0-9]+/bin/(lsphp|php)$' || w_exe="unparsed"
  w_user="$(limited 2 id -nu "$w_uid" || true)"
  matches "$w_user" '^[A-Za-z0-9._-]{1,32}$' || w_user="unknown"
  if [ "$worker_printed" -lt 20 ]; then
    echo "web_worker uid=${w_uid} user=${w_user} gid=${w_gid} supplementary_groups=$(printf '%s' "$w_groups" | wc -w | tr -d '[:space:]') serves_site_uid=${w_site} exe=${w_exe} identity_source=proc web_acceptance=no"
    worker_printed=$((worker_printed + 1))
  fi
  if [ "$w_site" = "yes" ]; then
    site_w_uid+=("$w_uid")
    site_w_gid+=("$w_gid")
    site_w_groups+=("$w_groups")
  fi
done
echo "web_worker_count=${worker_total}"
echo "web_worker_site_uid_count=${worker_site}"
if [ "$worker_total" -eq 0 ]; then
  echo "web_worker_identity=none_running"
elif [ "$worker_site" -eq 0 ]; then
  echo "web_worker_identity=no_site_uid_worker"
else
  echo "web_worker_identity=observed"
fi

# clamd configuration (file values) and service state.
clamd_conf=""
for candidate in /etc/clamd.d/scan.conf /etc/clamav/clamd.conf /usr/local/etc/clamd.conf; do
  if [ -f "$(host_path "$candidate")" ]; then
    clamd_conf="$(host_path "$candidate")"
    break
  fi
done
socket_path=""
db_dir=""
if [ -z "$clamd_conf" ]; then
  echo "clamd_conf=absent"
else
  echo "clamd_conf=present"
  socket_value="$(conf_value "$clamd_conf" "LocalSocket" || true)"
  if [ -z "$socket_value" ]; then
    echo "clamd_local_socket=unset"
  elif safe_path "$socket_value"; then
    socket_path="$socket_value"
    echo "clamd_local_socket=${socket_path}"
  else
    echo "clamd_local_socket=rejected"
  fi
  for key in StreamMaxLength MaxFileSize; do
    value="$(conf_value "$clamd_conf" "$key" || true)"
    bytes=""
    [ -n "$value" ] && bytes="$(to_bytes "$value")"
    cls="$(limit_class "$bytes")"
    case "$bytes" in
      ''|unparsed) shown="$bytes"; [ -n "$shown" ] || shown="unset" ;;
      *) shown="$bytes" ;;
    esac
    echo "clamd_config key=${key} bytes=${shown} vs_app_limit_25mib=${cls} source=config_file runtime_verified=no default_if_unset=not_assumed"
  done
  db_value="$(conf_value "$clamd_conf" "DatabaseDirectory" || true)"
  if [ -n "$db_value" ] && safe_path "$db_value"; then
    db_dir="$db_value"
  fi
fi

service_state="unknown"
if [ -n "$root" ]; then
  if [ -f "$(host_path "/fixture-systemctl")" ]; then
    service_state="$(limited 3 bash "$(host_path "/fixture-systemctl")" is-active "clamd@scan.service" || true)"
  fi
else
  service_state="$(limited 3 systemctl is-active "clamd@scan.service" || true)"
fi
case "$service_state" in
  active|inactive|failed|activating|deactivating) ;;
  *) service_state="unknown" ;;
esac
echo "clamd_service_active=${service_state}"

conf_newer="unknown"
if [ -n "$clamd_conf" ] && [ "$service_state" = "active" ]; then
  started_text=""
  if [ -n "$root" ]; then
    if [ -f "$(host_path "/fixture-systemctl")" ]; then
      started_text="$(limited 3 bash "$(host_path "/fixture-systemctl")" show -p ActiveEnterTimestamp --value "clamd@scan.service" || true)"
    fi
  else
    started_text="$(limited 3 systemctl show -p ActiveEnterTimestamp --value "clamd@scan.service" || true)"
  fi
  started_epoch=""
  if matches "$started_text" '^[A-Za-z0-9 :+-]{8,60}$'; then
    started_epoch="$(date -d "$started_text" +%s 2>/dev/null </dev/null || true)"
  fi
  conf_epoch="$(stat -c %Y "$clamd_conf" 2>/dev/null </dev/null || true)"
  if matches "$started_epoch" '^[0-9]+$' && matches "$conf_epoch" '^[0-9]+$'; then
    if [ "$conf_epoch" -gt "$started_epoch" ]; then
      conf_newer="yes"
    else
      conf_newer="no"
    fi
  fi
fi
echo "clamd_conf_newer_than_service_start=${conf_newer}"
echo "clamd_running_stream_max_length=not_measured"
echo "clamd_running_max_file_size=not_measured"

if [ -n "$socket_path" ]; then
  sock_host="$(host_path "$socket_path")"
  if [ -S "$sock_host" ] || { [ -n "$root" ] && [ -e "$sock_host" ]; }; then
    sock_stat="$(stat -c '%u %g %a %U %G' "$sock_host" 2>/dev/null </dev/null || true)"
    set -- $sock_stat
    sock_uid="${1:-}"
    sock_gid="${2:-}"
    sock_mode="${3:-}"
    if matches "${sock_uid}" '^[0-9]+$' && matches "${sock_gid}" '^[0-9]+$' && matches "${sock_mode}" '^[0-7]{3,4}$' && matches "${4:-}" '^[A-Za-z0-9._-]{1,32}$' && matches "${5:-}" '^[A-Za-z0-9._-]{1,32}$'; then
      echo "clamd_socket exists=yes owner=${4} group=${5} mode=${sock_mode} source=stat"
    else
      sock_uid=""
      echo "clamd_socket=unreadable"
    fi
  else
    echo "clamd_socket=absent"
  fi
else
  echo "clamd_socket=unknown"
fi

dac_result="not_attempted"
dac_reason="none"
if [ -z "$sock_uid" ]; then
  dac_reason="socket_not_stat"
elif [ "$worker_site" -eq 0 ]; then
  dac_reason="no_site_uid_worker"
else
  mode3="${sock_mode: -3}"
  idx=0
  while [ "$idx" -lt "$worker_site" ]; do
    u="${site_w_uid[$idx]}"
    g="${site_w_gid[$idx]}"
    grp="${site_w_groups[$idx]}"
    if [ "$u" = "$sock_uid" ]; then
      bits="${mode3:0:1}"
    else
      member="no"
      [ "$g" = "$sock_gid" ] && member="yes"
      for one in $grp; do
        [ "$one" = "$sock_gid" ] && member="yes"
      done
      if [ "$member" = "yes" ]; then
        bits="${mode3:1:1}"
      else
        bits="${mode3:2:1}"
      fi
    fi
    if [ $((bits & 6)) -eq 6 ]; then
      dac_rw=$((dac_rw + 1))
    else
      dac_norw=$((dac_norw + 1))
    fi
    idx=$((idx + 1))
  done
  if [ "$dac_rw" -gt 0 ] && [ "$dac_norw" -eq 0 ]; then
    dac_result="rw"
  elif [ "$dac_rw" -eq 0 ] && [ "$dac_norw" -gt 0 ]; then
    dac_result="no_rw"
  else
    dac_result="mixed"
  fi
fi
echo "web_worker_socket_dac_inference=${dac_result} reason=${dac_reason} basis=proc_identity_and_socket_mode connect_measured=no selinux_measured=no ancestors_checked=no web_acceptance=no"
echo "web_worker_socket_connect=not_measured"

# Signature age from the on-disk header (not the loaded signature).
sig_found=0
if [ -n "$db_dir" ]; then
  now="$(now_epoch)"
  for ext in cvd cld; do
    sig_file="$(host_path "${db_dir}/daily.${ext}")"
    if [ -f "$sig_file" ]; then
      sig_found=1
      built=""
      line="$(head -c 512 "$sig_file" 2>/dev/null | head -n 1 | tr -d '\r')"
      case "$line" in
        ClamAV-VDB:*)
          rest="${line#ClamAV-VDB:}"
          stamp="${rest%%:*}"
          if matches "$stamp" '^[0-9]{1,2} [A-Za-z]{3} [0-9]{4} [0-9]{2}-[0-9]{2} [+-][0-9]{4}$'; then
            converted="$(printf '%s' "$stamp" | sed -E 's/ ([0-9]{2})-([0-9]{2}) / \1:\2 /')"
            built="$(date -d "$converted" +%s 2>/dev/null </dev/null || true)"
          fi
          ;;
      esac
      if matches "$built" '^[0-9]+$' && matches "$now" '^[0-9]+$'; then
        diff=$((now - built))
        if [ "$diff" -lt -3600 ]; then
          verdict="future"
        elif [ "$diff" -gt 259200 ]; then
          verdict="exceeds_72h"
        else
          verdict="within_72h"
        fi
        hours=$((diff / 3600))
        echo "signature_daily file=daily.${ext} age_hours=${hours} vs_72h=${verdict} source=disk_header loaded_signature_verified=no"
      else
        echo "signature_daily file=daily.${ext} age_hours=unparsed vs_72h=not_measured source=disk_header loaded_signature_verified=no"
      fi
    fi
  done
fi
if [ "$sig_found" -eq 0 ]; then
  echo "signature_daily=not_measured"
fi

# Web upload limits: configured values only. The effective web value is not measured.
web_limit() {
  local key="$1" source="$2" value="$3" bytes
  [ -n "$value" ] || return 0
  bytes="$(to_bytes "$value")"
  echo "web_limit key=${key} source=${source} bytes=${bytes} vs_app_limit_25mib=$(limit_class "$bytes") runtime_verified=no web_acceptance=no"
}

ini_last_value() {
  local file="$1" key="$2"
  awk -v key="$key" '
    {
      line = $0
      sub(/\r$/, "", line)
      if (line ~ /^[ \t]*[;#]/) { next }
      n = index(line, "=")
      if (n == 0) { next }
      name = substr(line, 1, n - 1)
      gsub(/^[ \t]+|[ \t]+$/, "", name)
      if (name != key) { next }
      value = substr(line, n + 1)
      sub(/[ \t]*;.*$/, "", value)
      gsub(/^[ \t]+|[ \t]+$/, "", value)
      gsub(/^"|"$/, "", value)
      found = value
    }
    END { if (found == "") exit 1; print found }
  ' "$file" </dev/null 2>/dev/null
}

if [ -n "$handler_branch" ]; then
  branch_host="$(host_path "$handler_branch")"
  for key in upload_max_filesize post_max_size; do
    last=""
    for ini in "${branch_host}/etc/php.ini" "${branch_host}"/etc/php.d/*.ini; do
      [ -f "$ini" ] || continue
      found="$(ini_last_value "$ini" "$key" || true)"
      [ -n "$found" ] && last="$found"
    done
    if [ -n "$last" ]; then
      web_limit "$key" "lsphp_ini_files" "$last"
    else
      echo "web_limit key=${key} source=lsphp_ini_files bytes=unset runtime_verified=no web_acceptance=no"
    fi
    if [ -n "$testlig_vhost" ]; then
      override="$(awk -v key="$key" '
        $1 ~ /^php_(admin_)?value$/ && $2 == key { found = $3 }
        END { if (found == "") exit 1; print found }
      ' "$testlig_vhost" </dev/null 2>/dev/null || true)"
      web_limit "$key" "vhost_override" "$override"
    fi
  done
else
  echo "web_limit=not_measured reason=handler_unknown"
fi

for source_name in httpd_config vhost; do
  file=""
  if [ "$source_name" = "httpd_config" ]; then
    file="${ols_conf}/httpd_config.conf"
  else
    file="$testlig_vhost"
  fi
  if [ -n "$file" ] && [ -f "$file" ]; then
    value="$(awk '$1 == "maxReqBodySize" { found = $2 } END { if (found == "") exit 1; print found }' "$file" </dev/null 2>/dev/null || true)"
    if [ -n "$value" ]; then
      bytes="$(to_bytes "$value")"
      echo "ols_limit key=maxReqBodySize source=${source_name} bytes=${bytes} vs_app_limit_25mib=$(limit_class "$bytes") runtime_verified=no"
    fi
  fi
done
echo "web_limits_note=user_ini_not_read multipart_overhead_not_measured effective_web_limit=not_measured cli_ini_not_used=yes"

# Declared application flags (file source only). The code default is not a live value.
upload_flag="$(flag_state HOMEWORK_PDF_UPLOAD_ENABLED)"
scan_flag="$(flag_state HOMEWORK_PDF_SCAN_READY)"
echo "homework_pdf_upload_enabled declared=${upload_flag} source=shared_env_local effective_web=not_measured code_default_is_live_evidence=no"
echo "homework_pdf_scan_ready declared=${scan_flag} source=shared_env_local effective_web=not_measured code_default_is_live_evidence=no"
read_env_value "$(shared_env_file)" "HOMEWORK_PDF_CLAMD_STREAM_MAX_BYTES"
case $? in
  0)
    declared_bytes="$(to_bytes "$READ_ENV_VALUE")"
    echo "homework_pdf_clamd_stream_max_declared vs_app_limit_25mib=$(limit_class "$declared_bytes") source=shared_env_local runtime_verified=no"
    ;;
  2) echo "homework_pdf_clamd_stream_max_declared=absent" ;;
  3) echo "homework_pdf_clamd_stream_max_declared=contradictory" ;;
  *) echo "homework_pdf_clamd_stream_max_declared=unreadable" ;;
esac
echo "env_values_logged=no"

echo "scan_readiness_summary=preview_only gates_unchanged=yes proven=no"
echo "hw_pdf_ops_complete=yes"

case "$inventory_result" in
  ok|ok_store_empty|root_missing) exit 0 ;;
  *) exit 1 ;;
esac
