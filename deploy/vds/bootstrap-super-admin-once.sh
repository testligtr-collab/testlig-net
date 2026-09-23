#!/usr/bin/env bash
# One-shot interactive SUPER_ADMIN bootstrap for Testlig VDS.
# Password: Symfony hidden TTY prompt only (never argv / env file / history).
# ALLOW_SUPER_ADMIN_BOOTSTRAP is process-scoped; .env.local is not modified.
# Usage (from an interactive terminal with TTY):
#   ssh -t root@HOST '/usr/local/sbin/testlig-bootstrap-super-admin-once.sh --email=admin@example.com'
set -euo pipefail

EMAIL=""
FIRST_NAME="Super"
LAST_NAME="Admin"
APP_ROOT="${APP_ROOT:-/home/testlig.net/app}"
PHP_BIN="${PHP_BIN:-/usr/local/lsws/lsphp83/bin/php}"
APP_USER="${APP_USER:-testl3865}"

die() { printf 'ERROR: %s\n' "$*" >&2; exit 1; }

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
  # dbal:run-sql prints a table; take the last integer on the last non-empty line.
  run_app "$PHP_BIN" bin/console dbal:run-sql --no-interaction "$sql" 2>/dev/null \
    | tr -d '\r' \
    | awk 'NF{line=$0} END{ if (match(line, /[0-9]+/)) print substr(line, RSTART, RLENGTH); else print "err" }'
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

printf 'Launching official app:user:bootstrap-super-admin (hidden password prompt)...\n'
set +e
run_app env ALLOW_SUPER_ADMIN_BOOTSTRAP=1 \
  "$PHP_BIN" bin/console app:user:bootstrap-super-admin \
  --email="$EMAIL" \
  --first-name="$FIRST_NAME" \
  --last-name="$LAST_NAME" \
  --confirm
RC=$?
set -e

if [[ "$RC" -ne 0 ]]; then
  die "Bootstrap command failed (rc=$RC)."
fi

printf 'OK: bootstrap finished. Sign in at /giris then open /yonetim/mufredat.\n'
# Remove this helper after success (no secrets were written to disk by this script).
if [[ -f /usr/local/sbin/testlig-bootstrap-super-admin-once.sh ]]; then
  rm -f /usr/local/sbin/testlig-bootstrap-super-admin-once.sh || true
fi
exit 0
