#!/usr/bin/env bash
# Write a locked-down OpenSSH client config for one multiplexed VDS session.
# Does not connect. Does not scan host keys. Does not print secrets.
set -euo pipefail

: "${VDS_HOST:?}"
: "${VDS_USER:?}"
: "${VDS_SSH_KNOWN_HOSTS:?}"

if ! printf '%s' "$VDS_HOST" | grep -Eq '^[A-Za-z0-9._:-]+$'; then
  printf '%s\n' "ERROR: invalid SSH config input" >&2
  exit 1
fi
if ! printf '%s' "$VDS_USER" | grep -Eq '^[A-Za-z0-9._-]+$'; then
  printf '%s\n' "ERROR: invalid SSH config input" >&2
  exit 1
fi

umask 077
mkdir -p "${HOME}/.ssh"
chmod 700 "${HOME}/.ssh"
printf '%s\n' "$VDS_SSH_KNOWN_HOSTS" > "${HOME}/.ssh/known_hosts"
chmod 600 "${HOME}/.ssh/known_hosts"

cat > "${HOME}/.ssh/config" <<EOF
Host testlig-vds
  HostName ${VDS_HOST}
  User ${VDS_USER}
  BatchMode yes
  PasswordAuthentication no
  KbdInteractiveAuthentication no
  PreferredAuthentications publickey
  PubkeyAuthentication yes
  IdentitiesOnly yes
  NumberOfPasswordPrompts 0
  ConnectionAttempts 1
  ConnectTimeout 15
  ServerAliveInterval 15
  ServerAliveCountMax 2
  StrictHostKeyChecking yes
  UserKnownHostsFile ${HOME}/.ssh/known_hosts
  LogLevel ERROR
  ControlMaster auto
  ControlPersist 120
  ControlPath ${HOME}/.ssh/cm-%C
EOF
chmod 600 "${HOME}/.ssh/config"
