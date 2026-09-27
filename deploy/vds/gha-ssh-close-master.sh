#!/usr/bin/env bash
# Close an existing ControlMaster socket. Never starts a new authenticated session.
set -euo pipefail

shopt -s nullglob
sockets=( "${HOME}/.ssh"/cm-* )
if [ "${#sockets[@]}" -eq 0 ]; then
  printf '%s\n' "no SSH control socket; not opening a connection"
  exit 0
fi

exec ssh -o ControlMaster=no -O exit testlig-vds
