#!/usr/bin/env bash
# Read-only SSH target diagnostic for the Deploy VDS job.
# Never opens a connection: it only asks the OpenSSH client for the configuration it would use.
# Never prints the raw hostname, a resolved address, or any other config value.
# Always exits 0 so a diagnostic problem cannot change the deploy outcome.
#
# Modes:
#   summary  effective target kind, port, and whether a literal address equals the expected IPv4
#   dns      for a hostname target only: one time-limited resolver lookup, summarised as counts
set -u

mode="${1:-summary}"
EXPECTED_IPV4="212.87.197.196"
DNS_TIMEOUT_SECONDS="${TARGET_DIAG_DNS_TIMEOUT:-5}"

OCTET='(0|[1-9][0-9]{0,2})'
IPV4_RE="^${OCTET}\.${OCTET}\.${OCTET}\.${OCTET}$"
HOSTNAME_RE='^[A-Za-z0-9]([A-Za-z0-9.-]{0,251}[A-Za-z0-9])?$'

TARGET_HOST=""
TARGET_PORT=""

is_ipv4() {
  local value="$1"
  local part
  [[ "$value" =~ $IPV4_RE ]] || return 1
  for part in "${BASH_REMATCH[@]:1}"; do
    [ "$part" -le 255 ] || return 1
  done
  return 0
}

classify_target() {
  local host="$1"
  if [ -z "$host" ]; then
    echo "unreadable"
  elif is_ipv4 "$host"; then
    echo "ipv4"
  elif [[ "$host" == *:* ]] && [[ "$host" =~ ^[0-9A-Fa-f:.]+$ ]]; then
    echo "ipv6"
  elif [[ "$host" =~ [A-Za-z] ]] && [[ "$host" =~ $HOSTNAME_RE ]]; then
    echo "hostname"
  else
    echo "other"
  fi
}

read_target() {
  local config=""
  config="$(ssh -G testlig-vds 2>/dev/null)" || config=""
  TARGET_HOST="$(printf '%s\n' "$config" | awk '$1 == "hostname" { print $2; exit }')"
  TARGET_PORT="$(printf '%s\n' "$config" | awk '$1 == "port" { print $2; exit }')"
  config=""
}

valid_port() {
  local port="$1"
  [[ "$port" =~ ^[0-9]{1,5}$ ]] || return 1
  [ "$((10#$port))" -ge 1 ] && [ "$((10#$port))" -le 65535 ]
}

summary() {
  local kind port matches
  kind="$(classify_target "$TARGET_HOST")"
  if valid_port "$TARGET_PORT"; then
    port="$((10#$TARGET_PORT))"
  else
    port="unreadable"
  fi
  case "$kind" in
    ipv4)
      if [ "$TARGET_HOST" = "$EXPECTED_IPV4" ]; then
        matches="yes"
      else
        matches="no"
      fi
      ;;
    ipv6) matches="no" ;;
    *) matches="not_evaluated" ;;
  esac
  echo "ssh_target_diagnostic=read-only (client config only; no connection opened)"
  echo "ssh_target_kind=${kind}"
  echo "ssh_target_port=${port}"
  echo "ssh_target_matches_expected=${matches}"
  if [ "$kind" = "hostname" ]; then
    echo "note: hedef alan adı; eşleşme ayrı DNS adımında değerlendirilir."
  fi
  echo "note: beklenen adres betikte sabittir. Ham hostname ve adresler loglanmaz."
}

dns() {
  local kind resolved rc counts v4 v6 hit only
  kind="$(classify_target "$TARGET_HOST")"
  if [ "$kind" != "hostname" ]; then
    echo "ssh_target_dns=not_applicable kind=${kind}"
    return 0
  fi
  resolved="$(timeout "$DNS_TIMEOUT_SECONDS" getent ahosts "$TARGET_HOST" 2>/dev/null)"
  rc=$?
  if [ "$rc" -eq 124 ]; then
    echo "ssh_target_dns=timeout limit_seconds=${DNS_TIMEOUT_SECONDS}"
    return 0
  fi
  if [ "$rc" -ne 0 ]; then
    echo "ssh_target_dns=failed"
    return 0
  fi
  counts="$(printf '%s\n' "$resolved" | awk -v expected="$EXPECTED_IPV4" '
    {
      a = $1
      if (tolower(a) ~ /^::ffff:[0-9]+\.[0-9]+\.[0-9]+\.[0-9]+$/) {
        sub(/^::[fF][fF][fF][fF]:/, "", a)
      }
      if (a == "" || seen[a]++) next
      if (a ~ /:/) { v6++ }
      else if (a ~ /^[0-9]+\.[0-9]+\.[0-9]+\.[0-9]+$/) { v4++; if (a == expected) hit = 1 }
    }
    END { printf "%d %d %d\n", v4, v6, hit }')"
  resolved=""
  read -r v4 v6 hit <<<"$counts"
  v4="${v4:-0}"
  v6="${v6:-0}"
  hit="${hit:-0}"
  if [ "$((v4 + v6))" -eq 0 ]; then
    echo "ssh_target_dns=empty"
    return 0
  fi
  if [ "$hit" -eq 1 ] && [ "$v4" -eq 1 ] && [ "$v6" -eq 0 ]; then
    only="yes"
  else
    only="no"
  fi
  echo "ssh_target_dns=resolved"
  echo "ssh_target_dns_ipv4_count=${v4}"
  echo "ssh_target_dns_ipv6_count=${v6}"
  if [ "$hit" -eq 1 ]; then
    echo "ssh_target_dns_includes_expected=yes"
  else
    echo "ssh_target_dns_includes_expected=no"
  fi
  echo "ssh_target_dns_only_expected=${only}"
  echo "note: DNS eşleşmesi, scp'nin gerçekte hangi adrese bağlandığını tek başına kanıtlamaz."
}

read_target
case "$mode" in
  summary) summary ;;
  dns) dns ;;
  *) echo "ssh_target_diagnostic=unknown_mode" ;;
esac
exit 0
