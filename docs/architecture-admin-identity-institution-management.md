# Stage 2.21 — Admin identity & institution management

## Purpose

Authenticated `/yonetim` surfaces for **user** and **institution** operations that
delegate mutations exclusively to existing domain services
(`UserGlobalRoleManager`, `UserStatusManager`, `InstitutionCreator`,
`InstitutionStatusManager`, `InstitutionMembershipManager`). No new identity or
institution domain model; no invite/owner-transfer/hard-delete; no e-mail/password
change flows.

Builds on Stage 2.20 shell (`AdminPermission` / `AdminVoter` / `AdminAuthorization` /
`AdminActorGuard` / panel layout).

## Routes

| Method | Path | Name | Gate |
|--------|------|------|------|
| GET | `/yonetim/kullanicilar` | `app_admin_users` | `ADMIN_USERS_VIEW` |
| GET | `/yonetim/kullanicilar/{id}` | `app_admin_user_detail` | `ADMIN_USERS_VIEW` |
| POST | `/yonetim/kullanicilar/{id}/roller` | `app_admin_user_roles` | `ADMIN_USERS_MANAGE` |
| POST | `/yonetim/kullanicilar/{id}/durum` | `app_admin_user_status` | `ADMIN_USERS_MANAGE` |
| GET | `/yonetim/kurumlar` | `app_admin_institutions` | `ADMIN_INSTITUTIONS_VIEW` |
| GET | `/yonetim/kurumlar/yeni` | `app_admin_institution_new` | `ADMIN_INSTITUTIONS_CREATE` |
| POST | `/yonetim/kurumlar` | `app_admin_institution_create` | `ADMIN_INSTITUTIONS_CREATE` |
| GET | `/yonetim/kurumlar/{id}` | `app_admin_institution_detail` | `ADMIN_INSTITUTIONS_VIEW` |
| POST | `/yonetim/kurumlar/{id}/durum` | `app_admin_institution_status` | `ADMIN_INSTITUTIONS_MANAGE` |
| GET | `/yonetim/kurumlar/{id}/uyeler` | `app_admin_institution_members` | `ADMIN_MEMBERSHIPS_VIEW` |
| POST | `/yonetim/kurumlar/{institutionId}/uyeler/{membershipId}/rol` | `app_admin_membership_role` | `ADMIN_MEMBERSHIPS_MANAGE` |
| POST | `/yonetim/kurumlar/{institutionId}/uyeler/{membershipId}/durum` | `app_admin_membership_status` | `ADMIN_MEMBERSHIPS_MANAGE` |

Mutations are POST-only. Unknown UUID → **404**. Unauthorized but existing → **403**.
Cross-institution membership id → **404** (no oracle).

## Permission matrix

| Permission | ADMIN | SUPER_ADMIN | Notes |
|------------|-------|-------------|-------|
| `ADMIN_USERS_VIEW` / `ADMIN_USERS_MANAGE` | yes | yes | Domain managers still enforce SA target / peer-ADMIN rules |
| `ADMIN_INSTITUTIONS_VIEW` | yes | yes | Read-only for plain ADMIN |
| `ADMIN_INSTITUTIONS_CREATE` / `ADMIN_INSTITUTIONS_MANAGE` | no | yes | Matches `InstitutionCreator` / `InstitutionStatusManager` |
| `ADMIN_MEMBERSHIPS_VIEW` / `ADMIN_MEMBERSHIPS_MANAGE` | no | yes | Panel exposes SA path only |

MODERATOR and institution roles have no automatic `/yonetim` identity/institution access.

## Read models

- `AdminUserQuery` → `AdminUserListItemView` / `AdminUserDetailView`
- `AdminInstitutionQuery` → `AdminInstitutionListItemView` / `AdminInstitutionDetailView`
- `AdminMembershipQuery` → `AdminMembershipListItemView`
- Shared `AdminPagedResult`, `AdminPagination` (25 default / 100 max), `AdminLikeEscape`, `AdminShortRef`

No Doctrine entities are passed to Twig. Password hashes, tokens, audit keys, IPs, and
raw payloads are never projected.

## Mutations & CSRF

Event/target-bound CSRF token ids (`admin_user_roles_{userId}`, …). Missing/wrong/cross-target
token → **403** with no mutation/audit. Reason codes are allowlisted form choices; confirm
checkbox required; `allow_extra_fields: false`.

## Rate limits

Config `config/packages/rate_limiter.yaml`:

- `admin_user_roles`, `admin_user_status`, `admin_institution_status`, `admin_membership_mutate`
  — actor:target:action keys
- `admin_institution_create` — actor:create

Over-limit → **429** + positive `Retry-After`.

## Fresh / stale

List/detail gates reload via `AdminActorGuard` + `FreshUserLoader` /
`InstitutionalFreshEntityLoader` (`HINT_REFRESH` + lock). UI capability flags mirror domain
policy; services re-assert on every mutation.

## Out of scope

Invite flows, owner transfer, hard-delete, e-mail/password change, Stage 2.22.
