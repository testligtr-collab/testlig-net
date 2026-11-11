#!/usr/bin/env bash
# Install Playwright OS dependencies with at most one retry for recognized
# transient DNS/network apt download failures. Does not change apt sources,
# disable package verification, clear apt locks, or skip tests.
set -u

BROWSER="${1:-chromium}"
SLEEP_SECONDS="${PLAYWRIGHT_INSTALL_DEPS_RETRY_SLEEP_SECONDS:-20}"

if ! [[ "${SLEEP_SECONDS}" =~ ^[0-9]+$ ]] || [[ "${SLEEP_SECONDS}" -gt 120 ]]; then
  echo "playwright-install-deps: invalid PLAYWRIGHT_INSTALL_DEPS_RETRY_SLEEP_SECONDS=${SLEEP_SECONDS}" >&2
  exit 2
fi

is_recognized_transient_dns_or_network() {
  local log_file=$1

  # Permanent / non-retryable markers win even if a network string also appears.
  if grep -Eiq \
    'NO_PUBKEY|EXPKEYSIG|BADSIG|GPG error|The following signatures were invalid|Unable to locate package|E: Package .* has no installation candidate|Permission denied|Operation not permitted|No space left on device|Hash Sum mismatch|404 Not Found|E: Unmet dependencies|dpkg: error processing|Conflicting values set' \
    "${log_file}"
  then
    return 1
  fi

  grep -Eiq \
    'Temporary failure resolving|Could not resolve host|Name or service not known|Temporary failure in name resolution|Network is unreachable|Connection timed out|Connection reset by peer|Unable to connect to|Failed to connect to .* port|curl: \(6\)|curl: \(7\)|curl: \(28\)' \
    "${log_file}"
}

LOG1="$(mktemp)"
LOG2="$(mktemp)"
trap 'rm -f "${LOG1}" "${LOG2}"' EXIT

echo "playwright-install-deps: attempt=1 browser=${BROWSER}"
if [[ -n "${PLAYWRIGHT_INSTALL_DEPS_BIN:-}" ]]; then
  "${PLAYWRIGHT_INSTALL_DEPS_BIN}" "${BROWSER}" 2>&1 | tee "${LOG1}"
else
  npx playwright install-deps "${BROWSER}" 2>&1 | tee "${LOG1}"
fi
# Preserve the installer exit code; do not use the tee status.
status=${PIPESTATUS[0]}

if [[ "${status}" -eq 0 ]]; then
  echo "playwright-install-deps: ok attempts=1"
  exit 0
fi

if ! is_recognized_transient_dns_or_network "${LOG1}"; then
  echo "playwright-install-deps: no_retry reason=non_transient_or_unrecognized exit=${status}"
  exit "${status}"
fi

echo "playwright-install-deps: transient_dns_or_network detected; sleep=${SLEEP_SECONDS}s then one retry"
sleep "${SLEEP_SECONDS}"

echo "playwright-install-deps: attempt=2 browser=${BROWSER}"
if [[ -n "${PLAYWRIGHT_INSTALL_DEPS_BIN:-}" ]]; then
  "${PLAYWRIGHT_INSTALL_DEPS_BIN}" "${BROWSER}" 2>&1 | tee "${LOG2}"
else
  npx playwright install-deps "${BROWSER}" 2>&1 | tee "${LOG2}"
fi
status=${PIPESTATUS[0]}

if [[ "${status}" -eq 0 ]]; then
  echo "playwright-install-deps: ok attempts=2"
  exit 0
fi

echo "playwright-install-deps: failed after retry exit=${status}"
exit "${status}"
