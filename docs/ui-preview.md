# Testlig UI Preview Panels

## Purpose

Stage 2.14.1 delivers **design-approval previews** for student, teacher, and parent shells. They are not connected to domain services, Doctrine repositories, or real user accounts.

## URLs (dev / test only)

| Role | URL |
|------|-----|
| Student | `/onizleme/ogrenci` |
| Teacher | `/onizleme/ogretmen` |
| Parent | `/onizleme/veli` |

Routing is registered only under `when@dev` and `when@test` in `config/routes/ui_preview.yaml`. Controllers live in `src/UiPreview/Controller/` (outside `src/Controller/`) so production’s `config/routes.yaml` auto-import never sees them.

Production route tables must not contain `/onizleme/*`.

## Demo data

Immutable ViewModels in `App\UiPreview`:

- `StudentPreviewView::demo()` — Ece Yılmaz
- `TeacherPreviewView::demo()` — Deniz Öğretmen
- `ParentPreviewView::demo()` — Ece’nin Velisi

No database queries, no mutations, GET-only endpoints.

## Security notes

- No fake login / role switching.
- `/hesabim` remains authenticated.
- Firewall rules are not relaxed beyond public GET access for preview paths when those routes exist.
- Preview HTML must not embed real emails, tokens, secrets, or ciphertext.

## After design approval

Lock visual tokens and component markup; wire real panels to domain readers/managers in a later stage without keeping production preview routes.
