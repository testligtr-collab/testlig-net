#!/usr/bin/env bash
# Local (runner-side) reporter for the manual homework PDF inventory workflow.
# Usage: gha-homework-pdf-inventory-report.sh <remote-stdout-file> <ssh-stderr-file> <ssh-exit-status>
#
# It never connects anywhere and never prints a raw remote line. A remote line is shown only when it has the
# exact allowlisted shape (known key, [a-z0-9_] names, a small value alphabet, bounded length); anything else is
# only counted. The ssh exit status is kept as the job result: 0 is success, 255 is a connection failure, any
# other value is a remote command failure. Missing or unreadable output is never turned into a pass or into an
# empty store.
set -u
set -f
set -o pipefail

out_file="${1:-}"
err_file="${2:-}"
ssh_status="${3:-}"
expected_sha="${EXPECTED_SHA:-}"
summary_file="${GITHUB_STEP_SUMMARY:-}"

max_lines=400
max_line_length=300

allowed_keys=" hw_pdf_ops hw_pdf_ops_changes hw_pdf_ops_ssh_sessions hw_pdf_ops_complete upload_gate_changed scan_gate_changed"
allowed_keys="${allowed_keys} inventory_threshold_is_retention_policy cli_or_root_is_web_acceptance measured_utc expected_sha"
allowed_keys="${allowed_keys} app_link release_name release_revision release_revision_match current_revision_match"
allowed_keys="${allowed_keys} inventory_command_in_release prod_cache_present storage_dir_config durable_dir_state"
allowed_keys="${allowed_keys} inventory_result inventory_exit_code inventory_status inventory_options inventory_output_dropped_lines"
allowed_keys="${allowed_keys} readiness_section testlig_php_handler web_worker web_worker_count web_worker_site_uid_count"
allowed_keys="${allowed_keys} web_worker_identity web_worker_socket_dac_inference web_worker_socket_connect clamd_conf"
allowed_keys="${allowed_keys} clamd_local_socket clamd_config clamd_service_active clamd_conf_newer_than_service_start"
allowed_keys="${allowed_keys} clamd_running_stream_max_length clamd_running_max_file_size clamd_socket signature_daily"
allowed_keys="${allowed_keys} web_limit ols_limit web_limits_note homework_pdf_upload_enabled homework_pdf_scan_ready"
allowed_keys="${allowed_keys} homework_pdf_clamd_stream_max_declared env_values_logged scan_readiness_summary"
# Inventory counters and status words (the remote script applies the same list before it prints them).
allowed_keys="${allowed_keys} homework_pdf_inventory_status homework_pdf_inventory_code preview_only writes point_in_time"
allowed_keys="${allowed_keys} orphan_min_age_seconds store_empty disk_files disk_bytes matched_files matched_bytes"
allowed_keys="${allowed_keys} unreferenced_young_files unreferenced_young_bytes timestamp_unreliable_files"
allowed_keys="${allowed_keys} orphan_candidate_files orphan_candidate_bytes orphan_candidate_temp_files unsafe_entries"
allowed_keys="${allowed_keys} unsafe_symlink unsafe_directory unsafe_special unsafe_invalid_name unsafe_stat_failed"
allowed_keys="${allowed_keys} db_references db_declared_bytes db_physical_bytes db_missing_file db_unreadable_file"
allowed_keys="${allowed_keys} db_size_mismatch db_invalid_key duplicate_reference ambiguous_owner_references"
allowed_keys="${allowed_keys} draft_references draft_declared_bytes draft_physical_bytes submitted_references"
allowed_keys="${allowed_keys} submitted_declared_bytes submitted_physical_bytes evaluated_references"
allowed_keys="${allowed_keys} evaluated_declared_bytes evaluated_physical_bytes unknown_lifecycle_references"
allowed_keys="${allowed_keys} draft_older_than_threshold db_declared_minus_physical_bytes "

# Names that may follow the first key on a line (`key a=b c=d`). Anything else is dropped, so a stray
# `token=...` or `password=...` pair cannot ride along on an allowed first key.
allowed_pairs=" age_hours ancestors_checked basis binary bytes cli_ini_not_used code_default_is_live_evidence"
allowed_pairs="${allowed_pairs} connect_measured declared default_if_unset effective_web effective_web_limit exe exists"
allowed_pairs="${allowed_pairs} exposure file gates_unchanged gid group identity_source key loaded_signature_verified mode"
allowed_pairs="${allowed_pairs} multipart_overhead_not_measured owner present proven reason runtime_verified selinux_measured"
allowed_pairs="${allowed_pairs} serves_site_uid source supplementary_groups uid user user_ini_not_read vs_72h"
allowed_pairs="${allowed_pairs} vs_app_limit_25mib web_acceptance "

value_re='[A-Za-z0-9_.,:/+-]*'
word_re='[a-z][a-z0-9_]*'
line_re="^${word_re}(=${value_re})?( ${word_re}(=${value_re})?)*\$"

if ! [[ "$ssh_status" =~ ^[0-9]+$ ]] || [ "${#ssh_status}" -gt 3 ] || [ "$ssh_status" -gt 255 ]; then
  ssh_status_valid=no
  ssh_status=1
else
  ssh_status_valid=yes
fi

if [ "$ssh_status" -eq 0 ]; then
  ssh_result=success
elif [ "$ssh_status" -eq 255 ]; then
  ssh_result=connection_failed
else
  ssh_result=remote_command_failed
fi

# --- Allowlisted remote lines ---
shown=()
dropped=0
total=0
complete=no
stage=""
if [ -n "$out_file" ] && [ -r "$out_file" ]; then
  while IFS= read -r line || [ -n "$line" ]; do
    total=$((total + 1))
    if [ "$total" -gt "$max_lines" ]; then
      dropped=$((dropped + 1))
      continue
    fi
    if [ "${#line}" -gt "$max_line_length" ] || ! [[ "$line" =~ $line_re ]]; then
      dropped=$((dropped + 1))
      continue
    fi
    key="${line%%[= ]*}"
    if [[ "$allowed_keys" != *" ${key} "* ]]; then
      dropped=$((dropped + 1))
      continue
    fi
    pairs_ok=yes
    first_token=yes
    for token in $line; do
      if [ "$first_token" = yes ]; then
        first_token=no
        continue
      fi
      pair_name="${token%%=*}"
      if [[ "$allowed_pairs" != *" ${pair_name} "* ]]; then
        pairs_ok=no
        break
      fi
    done
    if [ "$pairs_ok" != yes ]; then
      dropped=$((dropped + 1))
      continue
    fi
    shown+=("$line")
    if [ "$line" = "hw_pdf_ops_complete=yes" ]; then
      complete=yes
    fi
    if [ "$key" = "inventory_result" ] && [ -z "$stage" ]; then
      stage="${line#inventory_result=}"
    fi
  done < "$out_file"
fi
if [ -z "$stage" ]; then
  stage=undetermined
fi

# --- Safe ssh client error class (only a class and a line count, never the text) ---
error_class=none
stderr_lines=0
if [ "$ssh_status" -ne 0 ]; then
  error_class=no_stderr
  if [ -n "$err_file" ] && [ -r "$err_file" ]; then
    stderr_lines="$(wc -l < "$err_file" 2>/dev/null | tr -d ' ')"
    [[ "$stderr_lines" =~ ^[0-9]+$ ]] || stderr_lines=0
    if [ -s "$err_file" ]; then
      error_class=other_stderr
      if grep -qiF 'Host key verification failed' "$err_file" 2>/dev/null; then
        error_class=host_key_verification_failed
      elif grep -qiF 'Permission denied' "$err_file" 2>/dev/null; then
        error_class=permission_denied
      elif grep -qiF 'timed out' "$err_file" 2>/dev/null; then
        error_class=connection_timed_out
      elif grep -qiF 'Connection refused' "$err_file" 2>/dev/null; then
        error_class=connection_refused
      elif grep -qiF 'Could not resolve' "$err_file" 2>/dev/null; then
        error_class=name_resolution_failed
      elif grep -qiE 'No route to host|Network is unreachable' "$err_file" 2>/dev/null; then
        error_class=network_unreachable
      elif grep -qiE 'Connection (closed|reset)' "$err_file" 2>/dev/null; then
        error_class=connection_closed
      elif grep -qiE 'control socket|ControlSocket|mux_client' "$err_file" 2>/dev/null; then
        error_class=control_socket_error
      fi
    fi
  fi
fi

# --- Verdict: the ssh status is kept; incomplete output never passes ---
verdict=pass
exit_code=0
if [ "$ssh_status" -ne 0 ]; then
  verdict=fail_ssh_nonzero
  exit_code="$ssh_status"
elif [ "$complete" != yes ]; then
  verdict=fail_remote_report_incomplete
  exit_code=1
fi

report=()
report+=("ssh_exit_code=${ssh_status}")
report+=("ssh_status_valid=${ssh_status_valid}")
report+=("ssh_result=${ssh_result}")
report+=("ssh_error_class=${error_class}")
report+=("ssh_stderr_lines=${stderr_lines}")
report+=("remote_output_lines=${total}")
report+=("remote_lines_shown=${#shown[@]}")
report+=("remote_lines_dropped=${dropped}")
report+=("remote_complete=${complete}")
report+=("remote_stage=${stage}")

print_report() {
  local item
  for item in "${report[@]}"; do
    printf '%s\n' "$item"
  done
  if [ "$stage" = undetermined ]; then
    printf '%s\n' "remote_stage_note=remote_stage_could_not_be_determined"
  fi
  if [ "${#shown[@]}" -gt 0 ]; then
    for item in "${shown[@]}"; do
      printf '%s\n' "$item"
    done
  fi
}

print_report
printf '%s\n' "report_verdict=${verdict}"

summary_write=skipped
if [ -n "$summary_file" ]; then
  if {
    printf '%s\n' "## Homework PDF inventory (read-only preview)"
    printf '%s\n' ""
    if [[ "$expected_sha" =~ ^[0-9a-f]{40}$ ]]; then
      printf '%s\n' "Expected live release SHA: ${expected_sha}"
    else
      printf '%s\n' "Expected live release SHA: invalid"
    fi
    printf '%s\n' "Allowlisted output only. Not quota, retention or upload acceptance."
    if [ "$stage" = undetermined ]; then
      printf '%s\n' ""
      printf '%s\n' "Uzak asama belirlenemedi: no allowlisted inventory_result line was received."
    fi
    printf '%s\n' ""
    printf '%s\n' '```'
    print_report
    printf '%s\n' "report_verdict=${verdict}"
    printf '%s\n' '```'
  } >> "$summary_file" 2>/dev/null; then
    summary_write=ok
  else
    summary_write=failed
  fi
fi
printf '%s\n' "summary_write=${summary_write}"

# A summary that cannot be written is not allowed to turn a success into silence, and it never hides the ssh status.
if [ "$summary_write" = failed ] && [ "$exit_code" -eq 0 ]; then
  exit_code=1
fi
exit "$exit_code"
