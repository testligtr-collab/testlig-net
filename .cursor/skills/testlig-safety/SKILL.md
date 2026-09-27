---
name: testlig-safety
description: >-
  Enforces Testlig production, deployment, migration, database, secret, and
  operations safety. Use when the task touches security, production writes,
  VDS deploy, migrations, MariaDB/Redis, users/roles, secrets, SMTP/DNS,
  catalog/curriculum imports, ops workflows, VDS SSH, or any
  destructive/data-changing command. Do not use for pure code explanation or
  unrelated local edits.
---

# Testlig Safety

Apply these invariants for every matching task. Prefer stopping over unsafe improvisation.

## Before acting

1. Read relevant repository rules, handoffs, and architecture docs for the touched domain.
2. Inspect branch, HEAD, `git status`, and preserve the user's existing/untracked local changes (for example Docker overrides).
3. Confirm the user's explicit scope before any production, ops, or data-mutating step.

## Hard stops

- Do not run destructive/irreversible commands (`reset --hard`, force-push to main, volume delete, `prune`, hard delete) without explicit user permission for that action.
- Do not open Docker Desktop or run `docker` / `docker compose` unless the user explicitly requests Docker for this task.
- Never write secrets, passwords, tokens, connection strings, or verification URLs into logs, commits, PRs, or chat responses.
- Do not change users/roles, DNS, SMTP, catalog trees, `CurriculumProgram`, or production LearningContent/placements unless the task scope explicitly allows it.
- Do not broaden a production operation on your own; if scope is ambiguous, ask the user.

## Production and data writes

When production or shared-state writes are in scope:

1. Require an explicit write scope from the user.
2. Prefer dry-run / preflight first.
3. Prefer idempotent operations with transaction or lock discipline where the domain already uses them.
4. Plan verification checks and a rollback / recovery approach before applying.
5. Re-verify counts and invariants after the write.

## Migrations

- Preserve backward compatibility for running code during rollout.
- Protect existing rows; review FK, unique, and index implications.
- Validate Doctrine mapping/schema after migration changes.
- Never invent destructive data migrations to "clean up" without explicit approval.

## Authorization and abuse resistance

- Keep authorization fail-closed.
- For state-changing HTTP: CSRF + PRG, audit where the domain audits, and object-level authorization (no IDOR).
- Do not relax security invariants for test convenience.

## VDS SSH

Production SSH is a ban risk. Hosting treats repeated connections as brute force.

- Do not open production SSH, SCP, rsync, or SFTP from a local Cursor terminal unless the user explicitly asks for that operation in the current task.
- No background SSH watcher or SSH status polling.
- No parallel SSH, SCP, or rsync. One operation uses one session. Batch the remote commands in that session.
- On authentication failure, timeout, connection refused, or host-key failure: stop immediately. Do not retry. Do not ask for another attempt unless the user explicitly allows it.
- After a failed connection, wait at least 15 minutes and do not try again without user approval.
- Watch GitHub workflow status with the GitHub API or `gh`, not over SSH.
- Check external health with HTTPS. Run localhost health or database counters only inside the one deploy or ops session.
- Never write a password, private key, or other secret into chat, logs, commits, or workflow output.
- If a ban is suspected, repository variable `VDS_SSH_PAUSED` must be `true`. Do not set it back to false until the user says the ban is lifted and asks for a connection.
- While that variable is `true`, do not deploy and do not run production verify. A skipped deploy is not a successful deploy. Report `Deploy skipped because SSH paused`.
- Workflows must use `ConnectionAttempts=1`, public-key only, no password fallback, and the shared concurrency group `testlig-vds-ssh` with `cancel-in-progress: false`.

## Reporting

State plainly what was changed, what was intentionally left untouched, and any skipped or failed safety check. Never present a skipped check as success.
