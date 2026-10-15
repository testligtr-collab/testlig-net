#!/usr/bin/env bash
# AlmaLinux 9 ClamAV install and isolated INSTREAM acceptance.
# TESTLIG_CLAMAV_TEST_ROOT is only for the local guard test. The workflow must not set it.
set -euo pipefail

root="${TESTLIG_CLAMAV_TEST_ROOT:-}"
revision_file="${root}/home/testlig.net/shared/CURRENT_REVISION"
scan_conf="${root}/etc/clamd.d/scan.conf"
fresh_conf="${root}/etc/freshclam.conf"
database_dir="${root}/var/lib/clamav"
socket_path="${root}/run/clamd.scan/clamd.sock"
php_bin="${TESTLIG_PHP_BIN:-/usr/local/lsws/lsphp83/bin/php}"
autoload="${root}/home/testlig.net/app/vendor/autoload.php"
work=""
backup_scan=""
backup_fresh=""
bool_was_off=0
mutated=0
keep=0
summary_install=stopped
summary_signature=not_run
summary_socket=not_run
summary_accept=not_run
summary_cleanup=ok

finish() {
  local status=$?
  if [ -n "$work" ]; then
    rm -rf "$work" || summary_cleanup=failed
  fi
  if [ "$status" -ne 0 ] && [ "$mutated" -eq 1 ]; then
    if [ -n "$backup_scan" ] && [ -f "$backup_scan" ]; then
      cp -a "$backup_scan" "$scan_conf" || summary_cleanup=failed
    fi
    if [ -n "$backup_fresh" ] && [ -f "$backup_fresh" ]; then
      cp -a "$backup_fresh" "$fresh_conf" || summary_cleanup=failed
    fi
    if [ "$bool_was_off" -eq 1 ]; then
      setsebool -P antivirus_can_scan_system 0 || summary_cleanup=failed
    fi
    systemctl disable --now clamd@scan.service || summary_cleanup=failed
    systemctl disable --now clamav-freshclam.service || summary_cleanup=failed
  fi
  rm -f "$backup_scan" "$backup_fresh" "${scan_conf}.testlig-new" "${fresh_conf}.testlig-new"
  if [ "$keep" -eq 1 ]; then
    if ! systemctl disable --now clamav-freshclam.service; then
      summary_cleanup=failed
      status=1
    fi
  fi
  echo "summary_install=${summary_install}"
  echo "summary_signature=${summary_signature}"
  echo "summary_socket=${summary_socket}"
  echo "summary_accept=${summary_accept}"
  echo "summary_cleanup=${summary_cleanup}"
  exit "$status"
}
trap finish EXIT

stop() {
  echo "stop_reason=$1"
  exit 1
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
systemctl stop clamav-freshclam.service
systemctl stop clamd@scan.service
systemctl disable clamav-freshclam.service

if [ ! -f "$scan_conf" ] || [ ! -f "$fresh_conf" ]; then
  stop package_config_missing
fi
backup_scan="${scan_conf}.testlig-backup"
backup_fresh="${fresh_conf}.testlig-backup"
cp -a "$scan_conf" "$backup_scan"
cp -a "$fresh_conf" "$backup_fresh"

selinux_mode="$(getenforce)"
case "$selinux_mode" in
  Disabled)
    echo "selinux=disabled"
    ;;
  Enforcing|Permissive)
    bool_state="$(getsebool antivirus_can_scan_system)"
    case "$bool_state" in
      *'--> on')
        echo "selinux_boolean=already_on"
        ;;
      *'--> off')
        bool_was_off=1
        setsebool -P antivirus_can_scan_system 1
        echo "selinux_boolean=enabled"
        ;;
      *)
        stop selinux_boolean_unreadable
        ;;
    esac
    ;;
  *)
    stop selinux_unreadable
    ;;
esac

scan_new="${scan_conf}.testlig-new"
awk '
  /^[[:space:]]*TCPSocket[[:space:]]/ { exit 2 }
  /^[[:space:]]*LocalSocket[[:space:]]/ { next }
  /^[[:space:]]*LocalSocketMode[[:space:]]/ { next }
  /^[[:space:]]*LocalSocketGroup[[:space:]]/ { next }
  { print }
' "$scan_conf" > "$scan_new" || stop tcp_socket_present
printf '%s\n' 'LocalSocket /run/clamd.scan/clamd.sock' 'LocalSocketMode 660' 'LocalSocketGroup virusgroup' >> "$scan_new"
mv "$scan_new" "$scan_conf"

awk '
  /^[[:space:]]*Example[[:space:]]*$/ { print "#Example"; next }
  { print }
' "$fresh_conf" > "${fresh_conf}.testlig-new"
mv "${fresh_conf}.testlig-new" "$fresh_conf"

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
if ! "$php_bin" "${work}/accept.php" dns "$database_dir"; then
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
keep=1
