# Stage 2.16 — Access Package / License / Entitlement foundation

Domain / application / security / persistence only.
No controllers, UI, REST API, payment SDK, or live-lesson product surface.

## Payment separation

Stage 2.16 never talks to a payment provider. Licenses are created by domain commands
(`AccessLicenseManager`) with `sourceType` values such as `manual`, `purchase`,
`promotion`, `institution_contract`, or `migration`. Stage **2.17** payment webhooks
must only invoke those license commands after verified settlement — never grant access
by mutating packages/grants directly.

## Packages and versions

- `AccessPackage`: immutable snake_case `code`; `targetType` individual|institution;
  status draft → active → retired. Individual packages cannot have seat limits.
- `AccessPackageVersion`: sequential `versionNumber` (DB trigger enforces MAX+1);
  draft mutations only; activate supersedes prior active version in the same TX.
- `AccessPackageActiveVersionGuard`: exactly one active version per package.
- Grants (draft only): learning-content IDs, assessment IDs, catalog rows.
- **Catalog adaptation:** Learning content catalog grants require `subject` + `grade_level`.
  Assessment has **no subject** — assessment catalog grants use `grade_level` only
  (`subject_id` MUST be NULL).

Stage 2.16 packages are platform commercial catalog products: grants may only reference
**platform published** learning content / assessments (institution-private resources rejected).

## Policy hash

`App\Access\AccessPackagePolicyHasher` (`SCHEMA_VERSION = 1`) hashes canonical JSON
(sorted grant IDs / catalog tuples). No PII, secrets, or content body. Activation and
access decisions recompute and `hash_equals()` against stored `policyHash` /
`policySnapshotHash`.

## Licenses and seats

- User license: `licenseeType=user`, institution NULL, no seat limit.
- Institution license: institution set, optional seat limit; seats only when **active**.
- Validity window (UTC): `validFrom <= now < validUntil` (exclusive upper bound).
- Lazy expire evaluation on mutation / seat assign paths.
- Retired package cannot create new licenses.
- Seats: lock order **Institution → License → User/Membership → Seat → Audit**.
  Revoked seats are not reactivated — assign a new row. Active uniqueness via
  `institution_license_active_seat_guards`.

## Free vs entitlement_required

`LearningContentAccessPolicy` / `AssessmentAccessPolicy` are separate from publication
bodies. When first set, default is `entitlement_required` (no silent mass conversion of
existing published rows). `free` allows access after published/auth/asset checks without
a license.

## EntitlementAccessGate

Priority: active+verified actor → resource published → free policy → individual license
covering resource → institution license via active seat → otherwise `entitlement_required`.

`LearningContentAccessGate` keeps published/auth/membership/asset checks and delegates
entitlement. Classroom `AssessmentDeliveryAccessGate` delivery window/recipient rules
remain **separate** from package entitlement (package covers “published assessment resource”
access, not delivery attempt eligibility).

## Audit policy

Mutation audits are mandatory (same-TX, `flush: false`). High-volume access **allows**
are **not** audited per view. Deny auditing is optional/debug and off by default.

## Concurrency limitation

Pessimistic locks + unique guards cover single-DB concurrency. There is no distributed
multi-process harness in this stage. Cache is intentionally **not** added.

## UTC

All timestamps use UTC (`ClockInterface` / `UtcInstant`).

## Live lesson

Live lesson / realtime classroom products are **out of scope** for Stage 2.16.
