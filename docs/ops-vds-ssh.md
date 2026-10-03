# VDS SSH

Production SSH is opened only by GitHub Actions, and only when repository variable `VDS_SSH_PAUSED` is not `true`. A paused run prints `VDS SSH operations are paused` and does not load the SSH agent or the VDS secrets. For deploy, that result is `Deploy skipped because SSH paused`. It is not a successful deploy.

## One session

Deploy uploads one bundle (`release.tgz`, `release-deploy.sh`, `rollback.sh`) with one `scp`, then installs, activates, and deletes the temporary bundle in one `ssh` on the same `ControlMaster` socket (`testlig-vds`). The remote script uses `trap` for that cleanup. There is no later SSH cleanup step. Closing the local control socket uses `ssh -O exit` only when the socket already exists, which does not start a new authentication.

Ops workflows (catalog import, catalog publish-tree, curriculum pilot import, official curriculum reconcile, learning-content package import, SuperAdmin bootstrap) each open one `ssh` heredoc. Official reconcile and the learning-content package importer require a separate manual dry-run before apply. The package importer does not review, seal, publish, or place the lesson, and it does not import questions. Apply needs the dry-run plan fingerprint and a later explicit approval. The package importer actor email is the repository secret `TESTLIG_CONTENT_ACTOR_EMAIL`; the workflow fails before SSH when that secret is empty and never writes the value to a VDS file. External HTTPS checks stay off that session. Dry-run, apply, and verify stay separate manual dispatches. They are not chained and they do not auto-dispatch.

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
