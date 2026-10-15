#!/usr/bin/env bash
# Read-only ClamAV preflight. Does not install, start, stop, or scan.
set -euo pipefail

echo "preflight=clamav-read-only"
echo "preflight_decides_install=no"

if [ -r /etc/os-release ]; then
  awk -F= '
    $1 == "ID" || $1 == "VERSION_ID" || $1 == "PRETTY_NAME" {
      gsub(/\r/, "", $2)
      gsub(/^"/, "", $2)
      gsub(/"$/, "", $2)
      print "os_" tolower($1) "=" $2
    }
  ' /etc/os-release
else
  echo "os_release=absent"
fi
uname -s | awk '{ print "os_kernel_name=" $1 }'
uname -r | awk '{ print "os_kernel_release=" $1 }'
uname -m | awk '{ print "os_machine=" $1 }'

for tool in apt-get dnf yum rpm dpkg systemctl; do
  if command -v "$tool" >/dev/null 2>&1; then
    echo "tool_${tool}=present"
  else
    echo "tool_${tool}=absent"
  fi
done
if [ -d /run/systemd/system ]; then
  echo "systemd_runtime=present"
else
  echo "systemd_runtime=absent"
fi

if [ -r /proc/meminfo ]; then
  awk '
    $1 == "MemTotal:" { print "mem_total_kib=" $2 }
    $1 == "MemAvailable:" { print "mem_available_kib=" $2 }
    $1 == "SwapTotal:" { print "swap_total_kib=" $2 }
    $1 == "SwapFree:" { print "swap_free_kib=" $2 }
  ' /proc/meminfo
else
  echo "meminfo=absent"
fi

for mount in / /var /usr /home /tmp; do
  if [ -d "$mount" ]; then
    df -B1 -P "$mount" | awk -v mount="$mount" '
      NR == 2 { print "disk mount=" mount " size_bytes=" $2 " avail_bytes=" $4 }
    '
  else
    echo "disk mount=${mount} missing=yes"
  fi
done

if id testl3865 >/dev/null 2>&1; then
  echo "app_user=present"
  echo "app_uid=$(id -u testl3865)"
  echo "app_gid=$(id -g testl3865)"
  echo "app_groups=$(id -nG testl3865)"
else
  echo "app_user=absent"
fi

if command -v ps >/dev/null 2>&1; then
  owners="$(ps -eo user=,comm= | awk '$2 ~ /^lsphp/ { print $1 }' | sort -u | awk 'NR != 1 { printf "," } { printf "%s", $0 }')"
  if [ -n "$owners" ]; then
    echo "php_process_owner=${owners}"
  else
    echo "php_process_owner=none"
  fi
else
  echo "php_process_owner=ps_absent"
fi

php_bin=/usr/local/lsws/lsphp83/bin/php
if [ -x "$php_bin" ]; then
  "$php_bin" -v | awk 'NR == 1 && /^PHP [0-9]/ { print "php_cli=" $0; found = 1 } END { if (!found) print "php_cli=unreadable" }'
else
  echo "php_cli=absent"
fi

revision_file=/home/testlig.net/shared/CURRENT_REVISION
if [ -r "$revision_file" ]; then
  revision="$(tr -d '\r\n' < "$revision_file")"
  if printf '%s' "$revision" | grep -Eq '^[0-9a-f]{40}$'; then
    echo "release_sha=${revision}"
  else
    echo "release_sha=unreadable"
  fi
else
  echo "release_sha=absent"
fi
if [ -L /home/testlig.net/app ]; then
  release_name="$(basename "$(readlink -f /home/testlig.net/app)")"
  if printf '%s' "$release_name" | grep -Eq '^[A-Za-z0-9._-]{1,80}$'; then
    echo "release_name=${release_name}"
  else
    echo "release_name=unreadable"
  fi
else
  echo "release_name=absent"
fi

for tool in clamd freshclam sigtool; do
  if command -v "$tool" >/dev/null 2>&1; then
    echo "tool_${tool}=present"
  else
    echo "tool_${tool}=absent"
  fi
done

if command -v dpkg-query >/dev/null 2>&1; then
  for package in clamav clamav-daemon clamav-freshclam clamav-base clamdscan; do
    status="$(dpkg-query -W -f '${Status}' "$package" 2>/dev/null || printf '%s' 'not-installed')"
    echo "package_${package}=${status}"
  done
fi
if command -v rpm >/dev/null 2>&1; then
  for package in clamav clamd clamav-update clamav-server clamav-filesystem; do
    if rpm -q --qf '%{NAME} %{VERSION}-%{RELEASE}' "$package" >/dev/null 2>&1; then
      rpm -q --qf "package_${package}=installed %{VERSION}-%{RELEASE}\n" "$package"
    else
      echo "package_${package}=not-installed"
    fi
  done
fi

if command -v systemctl >/dev/null 2>&1; then
  for unit in clamav-daemon.service clamav-freshclam.service clamd.service clamd@scan.service clamav-daemon.socket; do
    load="$(systemctl show -p LoadState --value "$unit" 2>/dev/null || printf '%s' 'unknown')"
    active="$(systemctl show -p ActiveState --value "$unit" 2>/dev/null || printf '%s' 'unknown')"
    enabled="$(systemctl show -p UnitFileState --value "$unit" 2>/dev/null || printf '%s' 'unknown')"
    echo "unit=${unit} load=${load} active=${active} enabled=${enabled}"
  done
else
  echo "units=systemd_absent"
fi

safe_path() {
  printf '%s' "$1" | grep -Eq '^/([A-Za-z0-9._-]{1,64}/){0,16}[A-Za-z0-9._-]{1,64}$'
}

conf_value() {
  local file="$1"
  local key="$2"
  awk -v key="$key" '
    /^[[:space:]]*#/ || /^[[:space:]]*$/ { next }
    $1 == key {
      value = substr($0, length($1) + 1)
      gsub(/^[[:space:]]+|[[:space:]]+$/, "", value)
      gsub(/^"|"$/, "", value)
      print value
      exit
    }
  ' "$file"
}

emit_conf() {
  local file="$1"
  local label="$2"
  shift 2
  if [ ! -f "$file" ]; then
    echo "${label}=absent"
    return
  fi
  echo "${label}=present"
  local key value
  for key in "$@"; do
    value="$(conf_value "$file" "$key")"
    if [ -z "$value" ]; then
      echo "${label}_${key}=unset"
    elif [ "${#value}" -gt 200 ] || printf '%s' "$value" | grep -Eq '[[:space:]]'; then
      echo "${label}_${key}=rejected"
    else
      echo "${label}_${key}=${value}"
    fi
  done
}

socket_path=""
signature_dir=""
clamd_conf_found=0
for file in /etc/clamav/clamd.conf /etc/clamd.d/scan.conf /usr/local/etc/clamd.conf; do
  if [ -f "$file" ]; then
    emit_conf "$file" "clamd_conf" LocalSocket LocalSocketGroup LocalSocketMode StreamMaxLength TCPSocket DatabaseDirectory
    socket_path="$(conf_value "$file" "LocalSocket")"
    signature_dir="$(conf_value "$file" "DatabaseDirectory")"
    clamd_conf_found=1
    break
  fi
done
if [ "$clamd_conf_found" -eq 0 ]; then
  echo "clamd_conf=absent"
fi
freshclam_conf_found=0
for file in /etc/clamav/freshclam.conf /usr/local/etc/freshclam.conf; do
  if [ -f "$file" ]; then
    emit_conf "$file" "freshclam_conf" DatabaseDirectory
    if [ -z "$signature_dir" ]; then
      signature_dir="$(conf_value "$file" "DatabaseDirectory")"
    fi
    freshclam_conf_found=1
    break
  fi
done
if [ "$freshclam_conf_found" -eq 0 ]; then
  echo "freshclam_conf=absent"
fi

if [ -n "$socket_path" ] && safe_path "$socket_path"; then
  if [ -S "$socket_path" ]; then
    stat -c 'socket_type=%F owner=%U group=%G mode=%a' "$socket_path"
  else
    echo "socket_stat=absent"
  fi
elif [ -n "$socket_path" ]; then
  echo "socket_stat=rejected"
else
  echo "socket_stat=unset"
fi

if [ -n "${signature_dir:-}" ] && safe_path "$signature_dir" && [ -d "$signature_dir" ]; then
  stat -c 'signature_dir_type=%F owner=%U group=%G mode=%a' "$signature_dir"
  found=0
  for name in main.cvd main.cld daily.cvd daily.cld bytecode.cvd bytecode.cld; do
    if [ -f "${signature_dir}/${name}" ]; then
      echo "signature_file=${name}"
      found=1
    fi
  done
  if [ "$found" -eq 0 ]; then
    echo "signature_file=none"
  fi
elif [ -n "${signature_dir:-}" ]; then
  echo "signature_dir=unreadable"
else
  echo "signature_dir=unset"
fi

# 3310 decimal is 0CEE in /proc/net/tcp local port field.
tcp_tables=()
if [ -r /proc/net/tcp ]; then
  tcp_tables+=("/proc/net/tcp")
fi
if [ -r /proc/net/tcp6 ]; then
  tcp_tables+=("/proc/net/tcp6")
fi
if [ "${#tcp_tables[@]}" -eq 0 ]; then
  echo "tcp_3310_listener=unknown"
elif awk '
  FNR != 1 && $4 == "0A" && $2 ~ /:0CEE$/ { found = 1 }
  END { exit found ? 0 : 1 }
' "${tcp_tables[@]}"; then
  echo "tcp_3310_listener=yes"
else
  echo "tcp_3310_listener=no"
fi
