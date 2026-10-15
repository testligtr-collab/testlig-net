#!/usr/bin/env bash
# AlmaLinux 9 ClamAV install and isolated INSTREAM acceptance.
# TESTLIG_CLAMAV_TEST_ROOT is only for the local guard test. The workflow must not set it.
# Private acceptance files are read here and sent with INSTREAM. clamd does not open that
# directory. The official antivirus_can_scan_system boolean allows system-file scans and
# database-load RWX mapping; this run does not change it.
set -euo pipefail

root="${TESTLIG_CLAMAV_TEST_ROOT:-}"
revision_file="${root}/home/testlig.net/shared/CURRENT_REVISION"
scan_conf="${root}/etc/clamd.d/scan.conf"
fresh_conf="${root}/etc/freshclam.conf"
socket_path="${root}/run/clamd.scan/clamd.sock"
unit_dir="${root}/usr/lib/systemd/system"
php_bin="${TESTLIG_PHP_BIN:-/usr/local/lsws/lsphp83/bin/php}"
autoload="${root}/home/testlig.net/app/vendor/autoload.php"
database_dir=""
work=""
backup_scan=""
backup_fresh=""
mutated=0
summary_install=stopped
summary_signature=not_run
summary_socket=not_run
summary_accept=not_run
summary_updater=not_run
summary_cleanup=ok
cleanup_work=skipped
cleanup_config=skipped
cleanup_units=skipped
cleanup_backups=skipped

finish() {
  local status=$?
  set +e
  if [ -n "$work" ]; then
    rm -rf "$work"
    if [ -e "$work" ]; then
      cleanup_work=failed
      status=1
    else
      cleanup_work=ok
    fi
  fi
  if [ "$status" -ne 0 ] && [ "$mutated" -eq 1 ]; then
    cleanup_config=ok
    if [ -n "$backup_scan" ]; then
      if [ -f "$backup_scan" ]; then
        cp -a "$backup_scan" "$scan_conf" || cleanup_config=failed
      else
        cleanup_config=failed
      fi
    fi
    if [ -n "$backup_fresh" ]; then
      if [ -f "$backup_fresh" ]; then
        cp -a "$backup_fresh" "$fresh_conf" || cleanup_config=failed
      else
        cleanup_config=failed
      fi
    fi
    cleanup_units=ok
    systemctl disable --now clamd@scan.service || cleanup_units=failed
    systemctl disable --now clamav-freshclam.service || cleanup_units=failed
    systemctl disable --now clamav-freshclam-once.timer || cleanup_units=failed
    systemctl stop clamav-freshclam-once.service || cleanup_units=failed
  fi
  if [ "$cleanup_config" = failed ]; then
    cleanup_backups=retained
    if [ -n "$backup_scan" ] && [ ! -f "$backup_scan" ]; then
      cleanup_backups=failed
    fi
    if [ -n "$backup_fresh" ] && [ ! -f "$backup_fresh" ]; then
      cleanup_backups=failed
    fi
  else
    cleanup_backups=ok
    if [ -n "$backup_scan" ]; then
      rm -f "$backup_scan" || cleanup_backups=failed
      if [ -e "$backup_scan" ]; then
        cleanup_backups=failed
      fi
    fi
    if [ -n "$backup_fresh" ]; then
      rm -f "$backup_fresh" || cleanup_backups=failed
      if [ -e "$backup_fresh" ]; then
        cleanup_backups=failed
      fi
    fi
  fi
  scratch_status=0
  if [ -n "$scan_conf" ]; then
    rm -f "${scan_conf}.testlig-new" || scratch_status=1
    if [ -e "${scan_conf}.testlig-new" ]; then
      scratch_status=1
    fi
  fi
  if [ -n "$fresh_conf" ]; then
    rm -f "${fresh_conf}.testlig-new" || scratch_status=1
    if [ -e "${fresh_conf}.testlig-new" ]; then
      scratch_status=1
    fi
  fi
  if [ "$scratch_status" -ne 0 ] && [ "$cleanup_backups" != retained ]; then
    cleanup_backups=failed
  fi
  for item in "$cleanup_work" "$cleanup_config" "$cleanup_units" "$cleanup_backups"; do
    if [ "$item" = failed ] || [ "$item" = retained ]; then
      summary_cleanup=failed
      status=1
    fi
  done
  echo "cleanup_work=${cleanup_work}"
  echo "cleanup_config=${cleanup_config}"
  echo "cleanup_units=${cleanup_units}"
  echo "cleanup_backups=${cleanup_backups}"
  echo "summary_install=${summary_install}"
  echo "summary_signature=${summary_signature}"
  echo "summary_socket=${summary_socket}"
  echo "summary_accept=${summary_accept}"
  echo "summary_updater=${summary_updater}"
  echo "summary_cleanup=${summary_cleanup}"
  exit "$status"
}
trap finish EXIT

stop() {
  echo "stop_reason=$1"
  exit 1
}

uncommented_database_directory() {
  awk '
    /^[[:space:]]*#/ { next }
    /^[[:space:]]*DatabaseDirectory[[:space:]]+/ {
      if (seen) { exit 2 }
      seen = 1
      print $2
    }
    END { if (!seen) exit 1 }
  ' "$1"
}

[ -n "${EXPECTED_SHA:-}" ] || stop expected_sha_missing
if [ ! -r "$revision_file" ]; then
  stop release_unreadable
fi
revision="$(tr -d '\r\n' < "$revision_file")"
[ "$revision" = "$EXPECTED_SHA" ] || stop release_mismatch

os_file=/etc/os-release
mem_file=/proc/meminfo
if [ -n "$root" ]; then
  os_file="${root}/etc/os-release"
  mem_file="${root}/proc/meminfo"
fi
[ -r "$os_file" ] || stop os_unreadable
os_id="$(awk -F= '$1=="ID" { gsub(/"/, "", $2); print $2 }' "$os_file")"
os_version="$(awk -F= '$1=="VERSION_ID" { gsub(/"/, "", $2); print $2 }' "$os_file")"
[ "$os_id" = "almalinux" ] || stop os_mismatch
printf '%s' "$os_version" | grep -Eq '^9([.][0-9]+)?$' || stop os_mismatch

available_kib="$(awk '$1=="MemAvailable:" { print $2 }' "$mem_file")"
printf '%s' "$available_kib" | grep -Eq '^[0-9]+$' || stop memory_unreadable
[ "$available_kib" -ge 3145728 ] || stop memory_below_documented_minimum
if [ -n "$root" ]; then
  disk_avail="$(tr -d '\r\n' < "${root}/disk-avail")"
else
  disk_avail="$(df -B1 -P / | awk 'NR==2 { print $4 }')"
fi
printf '%s' "$disk_avail" | grep -Eq '^[0-9]+$' || stop disk_unreadable
[ "$disk_avail" -ge 5368709120 ] || stop disk_below_documented_minimum

if [ -n "$root" ]; then
  if [ -e "${root}/usr/bin/clamd" ] || [ -e "${root}/usr/bin/freshclam" ] || [ -e "${root}/usr/bin/sigtool" ]; then
    stop existing_install
  fi
else
  if command -v clamd >/dev/null 2>&1 || command -v freshclam >/dev/null 2>&1 || command -v sigtool >/dev/null 2>&1; then
    stop existing_install
  fi
fi
if [ -e "$scan_conf" ] || [ -e "$fresh_conf" ] || [ -S "$socket_path" ]; then
  stop existing_config
fi

mutated=1
dnf install -y epel-release
dnf install -y clamav clamd clamav-update
summary_install=installed
for unit in clamd@.service clamav-freshclam.service clamav-freshclam-once.service clamav-freshclam-once.timer; do
  [ -f "${unit_dir}/${unit}" ] || stop updater_unit_missing
done
systemctl stop clamav-freshclam.service || stop updater_stop_failed
systemctl stop clamav-freshclam-once.timer || stop updater_stop_failed
systemctl stop clamav-freshclam-once.service || stop updater_stop_failed
systemctl disable clamav-freshclam.service || stop updater_stop_failed
systemctl disable clamav-freshclam-once.timer || stop updater_stop_failed
systemctl stop clamd@scan.service || stop updater_stop_failed

if [ ! -f "$scan_conf" ] || [ ! -f "$fresh_conf" ]; then
  stop package_config_missing
fi
backup_scan="${scan_conf}.testlig-backup"
backup_fresh="${fresh_conf}.testlig-backup"
cp -a "$scan_conf" "$backup_scan"
cp -a "$fresh_conf" "$backup_fresh"

scan_db=""
fresh_db=""
scan_db_status=0
fresh_db_status=0
scan_db="$(uncommented_database_directory "$scan_conf")" || scan_db_status=$?
fresh_db="$(uncommented_database_directory "$fresh_conf")" || fresh_db_status=$?
if [ "$scan_db_status" -eq 2 ] || [ "$fresh_db_status" -eq 2 ]; then
  stop database_directory_unreadable
fi
if [ "$scan_db_status" -eq 0 ] && [ "$fresh_db_status" -eq 0 ]; then
  [ "$scan_db" = "$fresh_db" ] || stop database_directory_mismatch
  database_dir="$scan_db"
elif [ "$scan_db_status" -eq 1 ] && [ "$fresh_db_status" -eq 1 ]; then
  clamconf_db="$(clamconf | awk -F'"' '/^DatabaseDirectory = "/ { if (seen && value != $2) exit 2; seen = 1; value = $2 } END { if (!seen) exit 1; print value }')" || stop database_directory_unreadable
  database_dir="$clamconf_db"
else
  stop database_directory_mismatch
fi
printf '%s' "$database_dir" | grep -Eq '^/([A-Za-z0-9._-]{1,64}/){0,16}[A-Za-z0-9._-]{1,64}$' || stop database_directory_unreadable
if [ -n "$root" ]; then
  database_dir="${root}${database_dir}"
fi

scan_new="${scan_conf}.testlig-new"
awk '
  /^[[:space:]]*TCPSocket[[:space:]]/ { exit 2 }
  /^[[:space:]]*Example[[:space:]]*$/ { print "#Example"; next }
  /^[[:space:]]*LocalSocket[[:space:]]/ { next }
  /^[[:space:]]*LocalSocketMode[[:space:]]/ { next }
  /^[[:space:]]*LocalSocketGroup[[:space:]]/ { next }
  { print }
' "$scan_conf" > "$scan_new" || stop tcp_socket_present
printf '%s\n' 'LocalSocket /run/clamd.scan/clamd.sock' 'LocalSocketMode 660' 'LocalSocketGroup virusgroup' >> "$scan_new"
mv "$scan_new" "$scan_conf"
if grep -Eq '^[[:space:]]*Example[[:space:]]*$' "$scan_conf"; then
  stop example_active
fi

awk '
  /^[[:space:]]*Example[[:space:]]*$/ { print "#Example"; next }
  { print }
' "$fresh_conf" > "${fresh_conf}.testlig-new"
mv "${fresh_conf}.testlig-new" "$fresh_conf"
if grep -Eq '^[[:space:]]*Example[[:space:]]*$' "$fresh_conf"; then
  stop example_active
fi

mkdir -p "${root}/tmp"
work="$(mktemp -d "${root}/tmp/testlig-clamav-accept.XXXXXX")"
fresh_log="${work}/freshclam.log"
if ! freshclam > "$fresh_log" 2>&1; then
  summary_signature=failed
  stop freshclam_failed
fi
if grep -q 'OUTDATED' "$fresh_log"; then
  summary_signature=outdated
  stop engine_outdated
fi
rm -f "$fresh_log"

[ -n "${ACCEPT_PHP_B64:-}" ] || stop accept_helper_missing
printf '%s' "$ACCEPT_PHP_B64" | base64 -d > "${work}/accept.php"
if ! "$php_bin" "${work}/accept.php" dns "$database_dir" "$autoload"; then
  summary_signature=failed
  stop signature_not_current
fi
summary_signature=current

systemctl enable --now clamd@scan.service
ready=0
for _ in 1 2 3 4 5 6 7 8 9 10 11 12 13 14 15; do
  if [ -S "$socket_path" ]; then
    ready=1
    break
  fi
  sleep 1
done
[ "$ready" -eq 1 ] || stop socket_missing
socket_mode="$(stat -c '%a' "$socket_path")"
[ "$socket_mode" = "660" ] || stop socket_mode_mismatch
echo "socket_mode=${socket_mode}"
summary_socket=ok

if [ -r /proc/net/tcp ] || [ -r /proc/net/tcp6 ]; then
  tcp_tables=()
  [ -r /proc/net/tcp ] && tcp_tables+=("/proc/net/tcp")
  [ -r /proc/net/tcp6 ] && tcp_tables+=("/proc/net/tcp6")
  if awk 'FNR != 1 && $4 == "0A" && $2 ~ /:0CEE$/ { found = 1 } END { exit found ? 0 : 1 }' "${tcp_tables[@]}"; then
    stop tcp_3310_present
  fi
fi
echo "tcp_3310_listener=no"

if ! "$php_bin" "${work}/accept.php" scan "$autoload" "$socket_path" "$work"; then
  summary_accept=failed
  stop accept_failed
fi
summary_accept=ok

systemctl enable --now clamav-freshclam.service || stop updater_enable_failed
updater_enabled="$(systemctl is-enabled clamav-freshclam.service)" || stop updater_not_enabled
updater_active="$(systemctl is-active clamav-freshclam.service)" || stop updater_not_active
[ "$updater_enabled" = "enabled" ] || stop updater_not_enabled
[ "$updater_active" = "active" ] || stop updater_not_active
if systemctl is-enabled clamav-freshclam-once.timer >/dev/null 2>&1; then
  stop updater_timer_enabled
fi
if systemctl is-active clamav-freshclam-once.timer >/dev/null 2>&1; then
  stop updater_timer_active
fi
summary_updater=enabled
