# Stage 2.20 — Admin / SuperAdmin operations panel foundation

## Purpose

Authenticated HTTP shell under `/yonetim` for **active+verified** `ROLE_ADMIN` and
`ROLE_SUPER_ADMIN`. Controllers stay thin; mutations go through existing domain services
(e.g. `PaymentWebhookDeadLetterRequeueService`). No EasyAdmin, no REST admin API, no
user/institution/content CRUD, no hard-delete, no Stage 2.21 work.

## Routes

| Method | Path | Name | Gate |
|--------|------|------|------|
| GET | `/yonetim` | `app_admin_dashboard` | shell |
| GET | `/yonetim/sistem` | `app_admin_system` | system view |
| GET | `/yonetim/odemeler` | `app_admin_payments` | payment ops (SA) |
| GET | `/yonetim/odemeler/{attemptId}` | `app_admin_payment_detail` | payment ops (SA) |
| GET | `/yonetim/webhook` | `app_admin_webhooks` | payment ops (SA) |
| GET | `/yonetim/webhook/{eventId}` | `app_admin_webhook_detail` | payment ops (SA) |
| POST | `/yonetim/webhook/{eventId}/yeniden-dene` | `app_admin_webhook_retry` | dead-letter requeue (SA) |
| GET | `/yonetim/uzlastirma` | `app_admin_reconciliations` | payment ops (SA) |
| GET | `/yonetim/uzlastirma/{runId}` | `app_admin_reconciliation_detail` | payment ops (SA) |
| GET | `/yonetim/denetim` | `app_admin_audit` | audit view (SA) |
| GET | `/onizleme/admin` | `ui_preview_admin` | UiPreview (dev/test only) |

Anonymous callers are redirected to login (`access_control` + firewall entry point).
Logged-in unauthorized actors receive **403**. Unknown / out-of-scope UUIDs return **404**.

## Authorization

- `App\Security\AdminAuthorization` — authoritative asserts (fresh User required).
- `App\Security\AdminVoter` + `AdminPermission` — menu / `IsGranted` attributes:
  `ADMIN_SHELL_ACCESS`, `ADMIN_SYSTEM_VIEW`, `ADMIN_PAYMENT_OPS`, `ADMIN_AUDIT_VIEW`,
  `ADMIN_DEAD_LETTER_REQUEUE`.
- Controllers resolve `actorId` from the security user; services re-load via
  `FreshUserLoader` + `PESSIMISTIC_READ` (`AdminActorGuard`) before asserts.
- Payment / webhook / reconciliation / dead-letter / audit: **SUPER_ADMIN only**
  (`CommerceAuthorization::assertCanOperatePayments` / audit assert).
- Plain **ADMIN** may open the shell and system summary; payment/audit nav items are
  hidden via `AdminNavBuilder` (permission results, not Twig role checks alone).
- Audit is SA-only because metadata may contain operational identifiers.

## Read models

Under `App\Service\Admin\`:

- `AdminDashboardReadModel` — Stage 2.19 summary + awaiting payment / captured-awaiting-fulfillment for SA
- `AdminSystemHealthReadModel` — app/DB/Redis/migration probes; generic “Kullanılamıyor”; webhook queue for SA
- `AdminPaymentOperationsQuery` — allowlisted filters, page size 25 default / 100 max, order `createdAt DESC, id ASC`
- `AdminWebhookQueueQuery` — status tabs including due / stale_lease / dead_letter
- `AdminReconciliationQuery` — run list + paged discrepancy projections (page size 25 / max 100)
- `AdminAuditReadModel` — re-sanitized allowlisted metadata only (no email/ip/ua/hash/secrets)

DTO projections only — no entity graphs to Twig.

## Dead-letter requeue

- Form DTO `AdminWebhookDeadLetterRequeueRequest` + `AdminWebhookDeadLetterRequeueFormType`
- CSRF intention `admin_webhook_dead_letter_requeue_{eventId}`; missing/wrong/cross-event token → **HTTP 403** (no flash hide); `allow_extra_fields: false`; confirm required
- Rate limiter `admin_dead_letter_requeue` (5 / 10 minutes, key `userId:eventId`); over-limit → **429** + `Retry-After`
- Calls `PaymentWebhookDeadLetterRequeueService::requeue`; PRG + flash; GET → 405
- Detail read uses `PaymentWebhookInboxEventRepository::findFreshById()` (`HINT_REFRESH`, no WRITE lock)

## Presentation

- Layout `templates/admin/layout.html.twig` (panel chrome, real logout CSRF, no önizleme banner)
- Money: Twig filter `money_try` (minor units → `₺1.234,56`)
- Time: Twig filter `utc_present` via `UtcInstant::formatForUser` (user TZ or `Europe/Istanbul`)
- Responses: `Cache-Control: no-store, private`

## Preview isolation

`AdminPreviewController` lives in `src/UiPreview/Controller/` and is imported only under
`when@dev` / `when@test`. Production router must not expose `/onizleme/*`. No
`PUBLIC_ACCESS` exception for `/onizleme` in `security.yaml`.

## Out of scope

EasyAdmin / commercial SDKs / card UI / checkout / user CRUD / institution CRUD /
content CRUD / bulk delete / hard-delete / role UI / direct DB mutation / REST admin API /
Stage 2.21+ (see `architecture-admin-identity-institution-management.md` once shipped).

Stage 2.21. Prefer no new migrations (existing schema sufficient).
