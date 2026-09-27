#!/usr/bin/env bash
# One ControlMaster transport: one scp, then one ssh. No retry. No second cleanup connection.
# Usage: gha-deploy-once.sh <sha> <tarball> <release-deploy.sh> <rollback.sh>
set -euo pipefail

SHA="${1:?git sha required}"
TARBALL="${2:?tarball path required}"
RELEASE_DEPLOY_SH="${3:?release-deploy.sh required}"
ROLLBACK_SH="${4:?rollback.sh required}"

if ! printf '%s' "$SHA" | grep -Eq '^[0-9a-fA-F]{7,64}$'; then
  printf '%s\n' "ERROR: deploy sha is not a hex git revision" >&2
  exit 1
fi
if [ ! -f "$TARBALL" ] || [ ! -f "$RELEASE_DEPLOY_SH" ] || [ ! -f "$ROLLBACK_SH" ]; then
  printf '%s\n' "ERROR: deploy bundle input missing" >&2
  exit 1
fi

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
STAGE="$(mktemp -d)"
BUNDLE="$(mktemp "${TMPDIR:-/tmp}/testlig-bundle.XXXXXX")"

cleanup_local() {
  status=$?
  set +e
  rm -rf "$STAGE"
  rm -f "$BUNDLE"
  bash "${ROOT}/gha-ssh-close-master.sh"
  close_status=$?
  if [ "$status" -ne 0 ]; then
    exit "$status"
  fi
  exit "$close_status"
}
trap cleanup_local EXIT

cp "$TARBALL" "${STAGE}/release.tgz"
cp "$RELEASE_DEPLOY_SH" "${STAGE}/release-deploy.sh"
cp "$ROLLBACK_SH" "${STAGE}/rollback.sh"
tar -czf "$BUNDLE" -C "$STAGE" release.tgz release-deploy.sh rollback.sh

scp "$BUNDLE" testlig-vds:/tmp/testlig-deploy-bundle.tgz

ssh testlig-vds "DEPLOY_SHA=$(printf '%q' "$SHA") bash -s" <<'EOS'
set -euo pipefail
BUNDLE=/tmp/testlig-deploy-bundle.tgz
WORKDIR="$(mktemp -d /tmp/testlig-deploy.XXXXXX)"
cleanup() {
  rm -f "$BUNDLE" /tmp/testlig-release.tgz /tmp/release-deploy.sh /tmp/rollback.sh
  rm -rf "$WORKDIR"
}
trap cleanup EXIT
tar -xzf "$BUNDLE" -C "$WORKDIR"
install -m 750 "$WORKDIR/release-deploy.sh" /usr/local/sbin/testlig-release-deploy.sh
install -m 750 "$WORKDIR/rollback.sh" /usr/local/sbin/testlig-rollback.sh
install -m 640 "$WORKDIR/release.tgz" /tmp/testlig-release.tgz
/usr/local/sbin/testlig-release-deploy.sh "$DEPLOY_SHA" /tmp/testlig-release.tgz
EOS
