# VDS SSH

Production SSH is opened only by GitHub Actions, and only when repository variable `VDS_SSH_PAUSED` is not `true`. A paused run prints `VDS SSH operations are paused` and does not load the SSH agent or the VDS secrets. For deploy, that result is `Deploy skipped because SSH paused`. It is not a successful deploy.

## One session

Deploy uploads one bundle (`release.tgz`, `release-deploy.sh`, `rollback.sh`) with one `scp`, then installs, activates, and deletes the temporary bundle in one `ssh` on the same `ControlMaster` socket (`testlig-vds`). The remote script uses `trap` for that cleanup. There is no later SSH cleanup step. Closing the local control socket uses `ssh -O exit` only when the socket already exists, which does not start a new authentication.

Ops workflows (catalog import, catalog publish-tree, curriculum pilot import, official curriculum reconcile, learning-content package import, SuperAdmin bootstrap, ClamAV preflight, ClamAV install) each open one `ssh` session. The ClamAV preflight workflow is manual and read-only. It does not install packages, change services, scan files, or decide that installation is safe. The ClamAV install workflow is a separate manual dispatch and stays closed unless its confirmation input is exactly `install-clamav-isolated-accept`. A successful run leaves `clamav-freshclam.service` as the only enabled updater and does not change SELinux booleans. On 2026-10-15 it ran once on SHA `d9874e3c2720dc7610c369c767a7f0412a6ad262` (https://github.com/testligtr-collab/testlig-net/actions/runs/38733923777). That isolated acceptance passed. It did not connect the application, change user groups, restart OpenLiteSpeed, change upload providers, or scan production assets. Catalog, curriculum, and bootstrap jobs send a quoted remote heredoc. The learning-content package importer cannot put the actor secret in that command string: the remote command reads the first stdin line as `TESTLIG_CONTENT_ACTOR_EMAIL`, rejects an empty or CR-bearing value, exports it, and executes the remaining stdin with `bash -se`. Mode, plan, and package are `printf %q` environment exports, not stdin lines. Official reconcile and the package importer require a separate manual dry-run before apply. The package importer does not review, seal, publish, or place the lesson, and it does not import questions. Apply needs the dry-run plan fingerprint and a later explicit approval. The actor email is the repository secret `TESTLIG_CONTENT_ACTOR_EMAIL`; the workflow fails before SSH when that secret is empty or contains CR/LF and never writes the value to a VDS file. External HTTPS checks stay off that session. Dry-run, apply, and verify stay separate manual dispatches. They are not chained and they do not auto-dispatch.

## PHP security inventory

`ops-php-security-inventory.yml` is a separate manual read-only dispatch. It records installed lsphp packages, the configured OpenLiteSpeed handler, CLI `session.upload_progress.enabled`, and whether the cached package metadata contains an lsphp83 security update. It does not change PHP settings, packages, or services. CLI output and an ini file are not web-runtime acceptance, and a configured handler is not recorded as internet-facing. This document does not record a production run. `ops-lsphp83-security-apply.yml` is a separate manual dispatch with an explicit `mode` input. `mode=diagnose` opens one SSH session, requires the live release SHA to match the workflow SHA, runs the same worker discovery used by apply, then stops. It does not refresh metadata, verify package GPG for an update, install a package, write php.ini, or signal workers. Expected diagnose inputs are `mode=diagnose` and any confirm value (ignored). Expected outputs include `php_apply_mode=diagnose`, `package_applied=no`, `worker_identity`, `worker_discovery_stage`, and `worker_discovery_result`. `mode=apply` opens SSH only when `confirm=apply-lsphp83-php2shell-mitigation`. Apply updates the lsphp83 family when refreshed LiteSpeed metadata offers an lsphp83 candidate at 8.3.36 or newer, or an older candidate whose changelog names both CVE-2026-109618 and CVE-2026-109619. It sets `session.upload_progress.enabled=0` in that branch's main php.ini and signals only matching Testlig LSAPI workers. It does not write php.ini, install a package, or signal a worker when the candidate or transaction preview is not confirmed. Enabled LiteSpeed repository package signatures must use gpgcheck=1 and a published LiteSpeed key that is already installed; otherwise it stops. It does not import keys, pass --nogpgcheck, restart OpenLiteSpeed, update other PHP branches, or start an application deploy. A failed worker discovery stays unresolved, records only the failed stage and result, and stops before metadata refresh, package, ini, and worker signal. An empty worker result is recorded only after that discovery completes. Missing or invalid mode rejects the run before release checks. A run of this workflow is the record of the change; this document is not that record.

## Manual deploy input

`deploy.yml` can be started by hand with an optional `ref` (branch, tag, or SHA). The `Resolve deploy SHA` step reads that input, the event name, the CI head SHA, and the workflow SHA from `env:` and uses them only as quoted shell variables. A GitHub expression must never be written into a `run:` block, because the value would be pasted into the script text before Bash starts and could run as code on the runner that holds the VDS ssh-agent. An empty `ref` still deploys the workflow revision, and branch, tag, full ref, and SHA values are passed on unchanged. The step stops without writing an output only when the value holds a control character such as a newline, since that would forge a line in `GITHUB_OUTPUT`; git refs cannot contain one. `tests/Ops/DeployManualRefInputGuardTest.php` enforces this. This section does not record a production run.

## Runner egress diagnostic

The `Deploy to VDS` job has one log-only step, `Record runner egress diagnostics (before first remote transfer)`. It sits right after `Configure SSH client` and right before `Upload bundle and activate release`, where `gha-deploy-once.sh` runs the first `scp`. The ssh-agent and client-config steps before it do not connect. It is not a separate job, so it shares the pause guard, the `testlig-vds-ssh` queue, and the deploy SHA of the job.

It logs three things: the UTC time, `run_id` with `run_attempt`, and the runner public IPv4 from `curl -4fsS --max-time 5 https://api.ipify.org`. The answer is logged only when it is exactly one IPv4 address. Otherwise the step logs `runner public IPv4 alınamadı`. The step has `continue-on-error: true`, ends with `exit 0`, and has no retry, so it does not change deploy behavior. It opens no remote connection and prints no secret, key, fingerprint, or environment dump.

The address is the runner's outbound IPv4 as seen by an HTTPS service. It is not the proven source address the VDS or the hosting network saw. A runner can use a different path to the VDS. Use it as the lead for the hosting ticket, not as proof.

For a support ticket after a controlled deploy, take from that run: the UTC time window of the failed connection, the logged runner IPv4, `run_id` and `run_attempt`, the job link, and the result. This section does not record a production run.

## Shared queue

Every SSH job uses:

```yaml
concurrency:
  group: testlig-vds-ssh
  cancel-in-progress: false
  queue: max
```

The group name is repository-wide, so deploy and ops cannot open SSH at the same time. `queue: max` keeps later runs waiting instead of replacing the single pending run. `cancel-in-progress: false` does not abort the run that is already connected.

## Client

`deploy/vds/gha-ssh-config.sh` is the only client profile: public-key only, `BatchMode yes`, `ConnectionAttempts 1`, `StrictHostKeyChecking yes`, known hosts from the existing secret, no host-key scan, no password or keyboard-interactive fallback. The private key stays in `ssh-agent`. Workflows do not retry a failed connection.

The GitHub runner loads that one key through `webfactory/ssh-agent`. Before the client config is written, the runner checks its own agent and continues only when `ssh_agent_identity_count=1`. It does not connect to the VDS and it does not print the fingerprint or the public key. `IdentitiesOnly yes` is not set: without an explicit `IdentityFile` that option can hide the agent key. The private key is not written to disk. Password, keyboard-interactive, and every other method stay off. This is not a password fallback and not a loosening of host-key checks.

## After a ban

Leave `VDS_SSH_PAUSED=true` until the ban is lifted and a human asks for the next connection. Then one manual hardened deploy, its in-session local health, and an external HTTPS smoke are enough. A separate manual verify is a later, separate run.
