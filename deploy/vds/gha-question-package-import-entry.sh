#!/usr/bin/env bash
# Remote SSH command body for the question package importer.
# First stdin line is the actor secret. Remaining stdin is the importer script.
# Does not print the secret. Does not write it to a file. Does not run it as a command.
set -Eeuo pipefail
IFS= read -r TESTLIG_CONTENT_ACTOR_EMAIL || true
if [ -z "${TESTLIG_CONTENT_ACTOR_EMAIL}" ]; then
  echo "Actor is not available."
  exit 1
fi
case "$TESTLIG_CONTENT_ACTOR_EMAIL" in
  *$'\r'*)
    echo "Actor is not available."
    exit 1
    ;;
esac
export TESTLIG_CONTENT_ACTOR_EMAIL
exec bash --norc --noprofile -se
