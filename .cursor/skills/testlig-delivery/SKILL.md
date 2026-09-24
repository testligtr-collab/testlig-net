---
name: testlig-delivery
description: >-
  Guides Testlig feature, fix, refactor, migration, workflow, PR, CI, deploy,
  and production verification delivery. Use when implementing or shipping code
  changes, opening or merging PRs, watching GitHub Actions, deploying to VDS,
  or running post-deploy health/smoke checks. Do not use for pure explanation
  of existing code with no delivery intent.
---

# Testlig Delivery

Ship the smallest safe change with evidence. Do not claim success for failed or skipped gates.

## Start

1. Inspect active branch, HEAD, `origin/main`, and dirty/untracked files.
2. Preserve unrelated user files; do not commit local Docker overrides or secrets.
3. Read the relevant architecture docs and nearby tests before designing the change.

## Build

1. Prefer the smallest change that satisfies the task.
2. Implement application/domain changes with matching tests in the same delivery slice.
3. Use existing services/managers/voters; avoid controller entity mutation shortcuts.
4. Keep commits focused on one intent; work on a dedicated branch (not `main`).

## Verify locally or via CI

Run the appropriate gates for the change:

- Targeted PHPUnit for the touched area
- Full PHPUnit / GitHub Actions CI when shipping
- PHPStan, PHP-CS-Fixer dry-run, Twig/container lint as relevant
- Doctrine mapping / migration status when schema is involved

If the local environment cannot run PHP/DB checks, do **not** open Docker unless the user explicitly asks. Use available host tools or wait for GitHub Actions CI instead.

## PR and merge

1. Open a focused PR: changed files, architecture decisions, security impact, and test results.
2. Never merge while CI is red.
3. When CI is green and the task authorizes it: merge, then follow the deploy path used by this repo.
4. After deploy, confirm revision/commit match and safe HTTP/health smoke (`/`, auth entry, admin anon redirect, external `/health` deny, local health allow when ops verify is in scope).
5. Deploy code only; do not write production data unless the user separately and explicitly approved that write.

## Final report

Include:

- PR URL and merge SHA
- CI run result
- Deploy run result (if any)
- Verify / production smoke results
- What changed vs what was intentionally untouched
- Explicit note of any failed or skipped check (do not disguise as success)
