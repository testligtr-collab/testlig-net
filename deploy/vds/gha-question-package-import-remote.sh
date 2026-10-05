#!/usr/bin/env bash
# Remaining stdin after the actor secret is read. MODE, PLAN, and PACKAGE are
# already exported by the SSH command. The actor email is in the process
# environment only.
set -Eeuo pipefail
APP_ROOT=/home/testlig.net/app
PHP_BIN=/usr/local/lsws/lsphp83/bin/php
APP_USER=testl3865
ACTOR_ENV_NAME=TESTLIG_CONTENT_ACTOR_EMAIL
case "${MODE:-}" in
  verify|dry-run|apply) ;;
  *)
    echo "ERROR: unknown mode"
    exit 1
    ;;
esac
case "${PACKAGE:-}" in
  data/content/tymm-2026/grade-1/matematik/mat-1-3-3) ;;
  *)
    echo "package is not allowlisted"
    exit 1
    ;;
esac
if [ "$MODE" = "apply" ] && ! printf '%s' "${PLAN:-}" | grep -Eq '^[a-f0-9]{64}$'; then
  echo "apply requires the dry-run plan fingerprint"
  exit 1
fi
[ -n "${TESTLIG_CONTENT_ACTOR_EMAIL:-}" ] || { echo "Actor is not available."; exit 1; }
cd "$APP_ROOT"
run_app() {
  runuser -u "$APP_USER" --preserve-environment -- env HOME=/home/testlig.net APP_ENV=prod "$@"
}
echo "mode=${MODE}"
echo "revision=$(basename "$(readlink -f "$APP_ROOT")")"
echo "package_present=$([ -f "$PACKAGE/questions.csv" ] && echo 1 || echo 0)"
echo "command_present=$([ -f bin/console ] && run_app "$PHP_BIN" bin/console list --raw 2>/dev/null | grep -c '^app:question:import-package' || echo 0)"
[ -f "$PACKAGE/questions.csv" ] || { echo "ERROR: package missing"; exit 1; }
ARGS=(bin/console app:question:import-package --no-interaction --package="$PACKAGE" --mode="$MODE" --actor-email-env="$ACTOR_ENV_NAME")
if [ "$MODE" = "apply" ]; then
  ARGS+=(--expected-plan-fingerprint="$PLAN")
fi
run_app "$PHP_BIN" "${ARGS[@]}"
