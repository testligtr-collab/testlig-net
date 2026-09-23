#!/usr/bin/env bash
# One-shot interactive SUPER_ADMIN bootstrap for Testlig VDS.
# Password: read -s on the SSH TTY (never argv / shell history / logs).
# Passed to the official console via a mode-600 temp file + TESTLIG_BOOTSTRAP_PASSWORD_FILE.
# ALLOW_SUPER_ADMIN_BOOTSTRAP is process-scoped; .env.local is not modified.
# Usage:
#   ssh -t root@HOST '/usr/local/sbin/testlig-bootstrap-super-admin-once.sh --email=admin@example.com'
set -euo pipefail

EMAIL=""
FIRST_NAME="Super"
LAST_NAME="Admin"
APP_ROOT="${APP_ROOT:-/home/testlig.net/app}"
PHP_BIN="${PHP_BIN:-/usr/local/lsws/lsphp83/bin/php}"
APP_USER="${APP_USER:-testl3865}"

die() { printf 'ERROR: %s\n' "$*" >&2; exit 1; }

cleanup_pw() {
  if [[ -n "${PASSFILE:-}" && -f "${PASSFILE}" ]]; then
    shred -u "$PASSFILE" 2>/dev/null || rm -f "$PASSFILE"
  fi
}
trap cleanup_pw EXIT

for arg in "$@"; do
  case "$arg" in
    --email=*) EMAIL="${arg#--email=}" ;;
    --first-name=*) FIRST_NAME="${arg#--first-name=}" ;;
    --last-name=*) LAST_NAME="${arg#--last-name=}" ;;
    -h|--help)
      printf '%s\n' "Usage: $0 --email=addr [--first-name=Super] [--last-name=Admin]"
      exit 0
      ;;
    --password*|-p=*|-p) die "Password must not be passed as an argument." ;;
    *) die "Unknown argument: $arg" ;;
  esac
done

[[ "$(id -u)" -eq 0 ]] || die "Run as root."
[[ -n "$EMAIL" ]] || die "--email is required."
[[ -x "$PHP_BIN" ]] || die "PHP binary missing: $PHP_BIN"
[[ -f "$APP_ROOT/bin/console" ]] || die "Console missing under $APP_ROOT"
[[ -t 0 && -t 1 ]] || die "Interactive TTY required (use: ssh -t ...). Do not pipe the password."

cd "$APP_ROOT"

run_app() {
  if command -v runuser >/dev/null 2>&1; then
    runuser -u "$APP_USER" -- env HOME="/home/testlig.net" "$@"
  else
    env HOME="/home/testlig.net" "$@"
  fi
}

sql_count() {
  local sql="$1"
  run_app "$PHP_BIN" bin/console dbal:run-sql --no-interaction "$sql" 2>/dev/null \
    | tr -d '\r' \
    | awk '/^[[:space:]]*[0-9]+[[:space:]]*$/ { v=$1 } END { if (v != "") print v; else print "err" }'
}

printf 'Preflight (no secrets)...\n'
SA_USERS="$(sql_count "SELECT COUNT(*) AS c FROM users WHERE JSON_CONTAINS(global_roles, '\"ROLE_SUPER_ADMIN\"', '\$') = 1")"
SA_GUARDS="$(sql_count "SELECT COUNT(*) AS c FROM security_bootstrap_guards WHERE name = 'super_admin'")"
STUDENT_ADMINS="$(sql_count "SELECT COUNT(*) AS c FROM users WHERE JSON_CONTAINS(global_roles, '\"ROLE_STUDENT\"', '\$') = 1 AND (JSON_CONTAINS(global_roles, '\"ROLE_ADMIN\"', '\$') = 1 OR JSON_CONTAINS(global_roles, '\"ROLE_SUPER_ADMIN\"', '\$') = 1)")"
EMAIL_NORM="$(printf '%s' "$EMAIL" | tr '[:upper:]' '[:lower:]' | sed 's/^[[:space:]]*//;s/[[:space:]]*$//')"
EMAIL_ESC="${EMAIL_NORM//\'/\\\'}"
EMAIL_TAKEN="$(sql_count "SELECT COUNT(*) AS c FROM users WHERE normalized_email = '${EMAIL_ESC}'")"

printf 'sa_users=%s\n' "$SA_USERS"
printf 'sa_guards=%s\n' "$SA_GUARDS"
printf 'student_admins=%s\n' "$STUDENT_ADMINS"
printf 'email_taken=%s\n' "$EMAIL_TAKEN"

[[ "$SA_USERS" =~ ^[0-9]+$ && "$SA_GUARDS" =~ ^[0-9]+$ && "$STUDENT_ADMINS" =~ ^[0-9]+$ && "$EMAIL_TAKEN" =~ ^[0-9]+$ ]] \
  || die "Preflight query parse failed."

[[ "$SA_USERS" -eq 0 && "$SA_GUARDS" -eq 0 ]] \
  || die "SUPER_ADMIN already exists or bootstrap guard is set (one-shot). Not bypassing."
[[ "$EMAIL_TAKEN" -eq 0 ]] \
  || die "E-mail already belongs to another account. Bootstrap will not take over."
[[ "$STUDENT_ADMINS" -eq 0 ]] \
  || die "Unexpected student+admin role combination detected; refusing to continue."

printf 'Password (hidden): '
read -r -s P1
printf '\n'
printf 'Confirm password (hidden): '
read -r -s P2
printf '\n'
[[ -n "$P1" && "$P1" == "$P2" ]] || die "Passwords empty or do not match."
unset P2

PASSFILE="$(mktemp /tmp/testlig-sa-pw.XXXXXX)"
chmod 600 "$PASSFILE"
chown "$APP_USER:$APP_USER" "$PASSFILE"
# No trailing newline semantics: write exact bytes then clear shell vars.
printf '%s' "$P1" >"$PASSFILE"
unset P1

printf 'Launching official app:user:bootstrap-super-admin...\n'
set +e
run_app env \
  ALLOW_SUPER_ADMIN_BOOTSTRAP=1 \
  TESTLIG_BOOTSTRAP_PASSWORD_FILE="$PASSFILE" \
  "$PHP_BIN" bin/console app:user:bootstrap-super-admin \
  --email="$EMAIL" \
  --first-name="$FIRST_NAME" \
  --last-name="$LAST_NAME" \
  --confirm
RC=$?
set -e
cleanup_pw
PASSFILE=""

if [[ "$RC" -ne 0 ]]; then
  die "Bootstrap command failed (rc=$RC). Check password policy / env; no account was created."
fi

printf 'OK: bootstrap finished. Sign in at /giris then open /yonetim/mufredat.\n'
if [[ -f /usr/local/sbin/testlig-bootstrap-super-admin-once.sh ]]; then
  rm -f /usr/local/sbin/testlig-bootstrap-super-admin-once.sh || true
fi
exit 0
