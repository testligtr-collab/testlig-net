# Testlig Agent Skills

Project skills live under `.cursor/skills/*/SKILL.md` (Cursor discovers them automatically).

| Skill | Purpose | Typical triggers |
| --- | --- | --- |
| `testlig-safety` | Production, secrets, migrations, ops, and fail-closed security invariants | deploy, migration, DB, users/roles, SMTP/DNS, catalog import, production writes |
| `testlig-delivery` | Branch → implement+test → PR → CI → merge → deploy → smoke | feature, fix, refactor, PR, CI, VDS deploy, health verify |
| `testlig-content` | Learning content, catalog placement, MEB/TYMM import, questions, assessments, and PDF/video blocks | LearningContent, revision, typed block, placement, curriculum import, question bank, test, student lesson, PDF, video |

`testlig-safety` owns production and secret safety. `testlig-delivery` owns branch, PR, CI, and deploy. `testlig-content` owns the content domain. None of them copies the others. A task that matches more than one uses all of those skills together.

## Auto vs manual

- **Auto:** Cursor may load a skill when the agent judges the `description` relevant. None of the three skills sets `disable-model-invocation`.
- **Manual:** In Agent chat type `/testlig-safety`, `/testlig-delivery`, or `/testlig-content` (or `@` attach).

## Which skills load

- “TYMM Matematik konularını içe aktar” → `testlig-safety` + `testlig-content` + `testlig-delivery` when the import is implemented or applied.
- “Konu anlatımına PDF ve video ekle” → `testlig-content` + `testlig-safety`. Add `testlig-delivery` when the change is shipped.
- “Soru bankası editörünü geliştir” → `testlig-content` + `testlig-delivery`. Add `testlig-safety` when the change writes production data or schema.
- “Bir PHP fonksiyonunu açıkla” → do not load a Testlig skill.

## Docker default

Docker Desktop and `docker compose` stay **off** unless the user explicitly asks for Docker in that task. Prefer host tools or GitHub Actions CI for verification.

## Out of scope

These skills do not replace architecture docs or always-on Cursor rules. `testlig-content` does not authorize a production content create, import apply, or publish by itself. Those writes stay dry-run until the user approves that exact step.
