#!/usr/bin/env bash
# Write a locked-down OpenSSH client config for one multiplexed VDS session.
# Does not connect. Does not scan host keys. Does not print secrets.
# The runner ssh-agent holds the single VDS key.
# Do not pin a key file here; that would hide the agent key.
set -euo pipefail

require_one_agent_identity() {
  if [ -z "${SSH_AUTH_SOCK:-}" ]; then
    printf '%s\n' "ssh_agent_identity_count=0" >&2
    printf '%s\n' "ERROR: ssh-agent socket missing" >&2
    exit 1
  fi
  local rc=0
  local identity_list=""
  set +e
  identity_list="$(ssh-add -l 2>/dev/null)"
  rc=$?
  set -e
  local count=0
  if [ "$rc" -eq 0 ] && [ -n "$identity_list" ]; then
    count="$(
      awk 'END { print NR }' <<EOF
$identity_list
EOF
    )"
    count="$(printf '%s' "$count" | tr -d '[:space:]')"
  fi
  unset identity_list
  if [ "$count" -ne 1 ]; then
    printf 'ssh_agent_identity_count=%s\n' "$count" >&2
    printf '%s\n' "ERROR: ssh-agent identity count must be 1" >&2
    exit 1
  fi
  printf '%s\n' "ssh_agent_identity_count=1"
}

require_one_agent_identity

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
