---
name: testlig-safety
description: >-
  Enforces Testlig production, deployment, migration, database, secret, and
  operations safety. Use when the task touches security, production writes,
  VDS deploy, migrations, MariaDB/Redis, users/roles, secrets, SMTP/DNS,
  catalog/curriculum imports, ops workflows, or any destructive/data-changing
  command. Do not use for pure code explanation or unrelated local edits.
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

## Reporting

State plainly what was changed, what was intentionally left untouched, and any skipped or failed safety check. Never present a skipped check as success.
