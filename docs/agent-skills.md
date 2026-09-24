# Testlig Agent Skills

Project skills live under `.cursor/skills/*/SKILL.md` (Cursor discovers them automatically).

| Skill | Purpose | Typical triggers |
| --- | --- | --- |
| `testlig-safety` | Production, secrets, migrations, ops, and fail-closed security invariants | deploy, migration, DB, users/roles, SMTP/DNS, catalog import, production writes |
| `testlig-delivery` | Branch → implement+test → PR → CI → merge → deploy → smoke | feature, fix, refactor, PR, CI, VDS deploy, health verify |

## Auto vs manual

- **Auto:** Cursor may load a skill when the agent judges the `description` relevant. Neither skill sets `disable-model-invocation`, so both are eligible for automatic selection.
- **Manual:** In Agent chat type `/testlig-safety` or `/testlig-delivery` (or `@` attach).

## Docker default

Docker Desktop and `docker compose` stay **off** unless the user explicitly asks for Docker in that task. Prefer host tools or GitHub Actions CI for verification.

## Out of scope

These skills do not replace architecture ADRs or always-on Cursor rules. They package repeated safety and delivery procedure only.
