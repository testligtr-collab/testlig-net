#!/usr/bin/env bash
# Ephemeral Ubuntu runner clamd for X01 homework PDF CI accept.
# Unix socket only. No TCP. No production VDS. No Docker.
set -euo pipefail

cmd="${1:-}"
WORK_ROOT="${HOMEWORK_PDF_CLAMAV_CI_ROOT:-}"

emit() {
  printf '%s\n' "$1"
}

require_work_root() {
  if [ -z "$WORK_ROOT" ]; then
    emit "setup_error=work_root_missing"
    exit 1
  fi
}

stop_clamd() {
  require_work_root
  local pid_file conf_path socket_path
  pid_file="${WORK_ROOT}/clamd.pid"
  conf_path="${WORK_ROOT}/clamd.conf"
  socket_path="${WORK_ROOT}/clamd.sock"
  if [ -f "$pid_file" ]; then
    pid="$(tr -d '\r\n' < "$pid_file" 2>/dev/null || true)"
    if [ -n "${pid:-}" ] && kill -0 "$pid" 2>/dev/null; then
      kill "$pid" 2>/dev/null || true
      for _ in 1 2 3 4 5 6 7 8 9 10; do
        kill -0 "$pid" 2>/dev/null || break
        sleep 1
      done
      kill -9 "$pid" 2>/dev/null || true
    fi
  fi
  if command -v pkill >/dev/null 2>&1 && [ -f "$conf_path" ]; then
    pkill -f "clamd --config-file=${conf_path}" 2>/dev/null || true
  fi
  rm -f "$socket_path" "$pid_file" 2>/dev/null || true
  emit "clamd_stop=ok"
}

start_clamd() {
  require_work_root
  local socket_path conf_path db_dir log_dir pid_file state_file clamd_bin
  socket_path="${WORK_ROOT}/clamd.sock"
  conf_path="${WORK_ROOT}/clamd.conf"
  db_dir="${WORK_ROOT}/db"
  log_dir="${WORK_ROOT}/log"
  pid_file="${WORK_ROOT}/clamd.pid"
  state_file="${WORK_ROOT}/setup.env"

  mkdir -p "$WORK_ROOT" "$db_dir" "$log_dir"
  chmod 700 "$WORK_ROOT"
  emit "setup_work_root=${WORK_ROOT}"

  export DEBIAN_FRONTEND=noninteractive
  sudo apt-get update -qq
  sudo apt-get install -y -qq clamav clamav-daemon clamav-freshclam clamdscan

  sudo systemctl stop clamav-daemon.service clamav-daemon.socket clamav-freshclam.service 2>/dev/null || true
  sudo systemctl disable clamav-daemon.service clamav-daemon.socket clamav-freshclam.service 2>/dev/null || true

  # freshclam's default UpdateLogFile is /var/log/clamav (root-owned). Custom
  # --config-file rewrites failed to parse on this runner image, so update as root
  # into the isolated DB dir, then hand ownership to the unprivileged accept user.
  # Official signature path via freshclam CDN. Fail closed; no blind retry.
  if ! timeout 600 sudo freshclam --datadir="$db_dir" --stdout; then
    emit "signature_update=failed"
    exit 1
  fi
  sudo chown -R "$(id -u):$(id -g)" "$db_dir"
  chmod -R u+rwX "$db_dir"
  emit "signature_update=ok"

  cat >"$conf_path" <<EOF
LocalSocket ${socket_path}
LocalSocketMode 660
FixStaleSocket true
DatabaseDirectory ${db_dir}
PidFile ${pid_file}
LogFile ${log_dir}/clamd.log
LogTime true
Foreground false
ScanPDF yes
StreamMaxLength 100M
MaxFileSize 100M
MaxScanSize 200M
EOF
  chmod 600 "$conf_path"

  clamd_bin="$(command -v clamd)"
  if ! timeout 120 "$clamd_bin" --config-file="$conf_path"; then
    emit "clamd_start=failed"
    exit 1
  fi

  for _ in $(seq 1 60); do
    if [ -S "$socket_path" ]; then
      break
    fi
    sleep 1
  done
  if [ ! -S "$socket_path" ]; then
    emit "clamd_socket=missing"
    stop_clamd || true
    exit 1
  fi
  chmod 660 "$socket_path" || true
  emit "clamd_socket=${socket_path}"
  emit "clamd_start=ok"

  stream_max="$(awk '/^[[:space:]]*StreamMaxLength[[:space:]]+/ {print $2; exit}' "$conf_path")"
  max_file="$(awk '/^[[:space:]]*MaxFileSize[[:space:]]+/ {print $2; exit}' "$conf_path")"
  stream_max_bytes="$(size_to_bytes "${stream_max:-}")"
  max_file_bytes="$(size_to_bytes "${max_file:-}")"
  emit "config_stream_max_length=${stream_max:-unset}"
  emit "config_max_file_size=${max_file:-unset}"
  emit "config_stream_max_bytes=${stream_max_bytes:-unset}"
  emit "config_max_file_bytes=${max_file_bytes:-unset}"

  {
    echo "HOMEWORK_PDF_CLAMAV_SOCKET=${socket_path}"
    echo "HOMEWORK_PDF_CLAMAV_DB=${db_dir}"
    echo "HOMEWORK_PDF_CLAMAV_CONF=${conf_path}"
    echo "HOMEWORK_PDF_CLAMAV_WORK=${WORK_ROOT}"
    echo "HOMEWORK_PDF_CLAMAV_STREAM_MAX_BYTES=${stream_max_bytes}"
    echo "HOMEWORK_PDF_CLAMAV_MAX_FILE_BYTES=${max_file_bytes}"
  } >"$state_file"
  emit "setup_state=${state_file}"
}

size_to_bytes() {
  local raw="$1"
  if [[ "$raw" =~ ^([0-9]+)([KkMmGg])?$ ]]; then
    local n="${BASH_REMATCH[1]}"
    local u="${BASH_REMATCH[2]}"
    case "$u" in
      K|k) echo $((n * 1024)) ;;
      M|m) echo $((n * 1024 * 1024)) ;;
      G|g) echo $((n * 1024 * 1024 * 1024)) ;;
      *) echo "$n" ;;
    esac
  else
    echo ""
  fi
}

case "$cmd" in
  start) start_clamd ;;
  stop) stop_clamd ;;
  *)
    emit "setup_error=usage"
    exit 1
    ;;
esac
