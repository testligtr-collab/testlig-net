# VDS SSH

Production SSH is opened only by GitHub Actions, and only when repository variable `VDS_SSH_PAUSED` is not `true`. A paused run prints `VDS SSH operations are paused` and does not load the SSH agent or the VDS secrets. For deploy, that result is `Deploy skipped because SSH paused`. It is not a successful deploy.

## One session

Deploy uploads one bundle (`release.tgz`, `release-deploy.sh`, `rollback.sh`) with one `scp`, then installs, activates, and deletes the temporary bundle in one `ssh` on the same `ControlMaster` socket (`testlig-vds`). The remote script uses `trap` for that cleanup. There is no later SSH cleanup step. Closing the local control socket uses `ssh -O exit` only when the socket already exists, which does not start a new authentication.

Ops workflows (catalog import, catalog publish-tree, curriculum pilot import, SuperAdmin bootstrap) each open one `ssh` heredoc. External HTTPS checks stay off that session. Dry-run, apply, and verify stay separate manual dispatches. They are not chained and they do not auto-dispatch.

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

## After a ban

Leave `VDS_SSH_PAUSED=true` until the ban is lifted and a human asks for the next connection. Then one manual hardened deploy, its in-session local health, and an external HTTPS smoke are enough. A separate manual verify is a later, separate run.
