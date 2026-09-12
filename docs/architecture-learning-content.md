# Stage 2.15 — Learning Content + Stored Media foundation

Domain / application / security / persistence only.
No controllers, UI, REST API, real upload, storage SDK, payment, or AI.

## Model

- `LearningContent` — stable identity (`platform` | `institution`), title/slug/code, status lifecycle
- `LearningContentRevision` — structured content JSON; unsealed updates allowed; seal is irreversible
- `LearningContentPublication` — append-only publication snapshot + `contentHash`
- `LearningContentOutcomeAlignment` + primary guard — curriculum denormalization (Question pattern)
- `StoredMediaAsset` — metadata-only media registry; `storageKey` serializer-ignored
- `LearningContentRevisionAsset` — role-tagged attachment to a revision

Structured content lives under `src/LearningContent/Content/` (separate from Question content).
Media policy under `src/LearningContent/Media/` (MIME/size/sha256 + app-generated keys).

## Revision pointers

- `current_revision_id` + `current_revision_number` — null-pair CHECK; composite FK
  `FK_LC_CURRENT_REVISION_CONTENT` → `(id, content_id, revision_number)` **ON DELETE CASCADE**
- `published_revision_id` + `published_revision_number` — null-pair CHECK; composite FK
  `FK_LC_PUBLISHED_REVISION_CONTENT` → same target **ON DELETE CASCADE**
- CASCADE (Assessment Stage 2.9 pattern) resolves the content↔revision cycle on parent DELETE
  without clearing published pointers while publications exist. Append-only BEFORE DELETE
  triggers still protect direct child deletes; MariaDB does not fire them for FK cascades.
- Manager assigns current via `assignCurrentRevision` after revision INSERT; draft create
  inserts content with null current, then assigns revision 1 in the same transaction.

## Publication integrity (DB)

- `trg_lcp_bi` — revision exists + sealed; `content_id` match; must target `current_revision_id`;
  `content_hash` / `schema_version` match revision; `publication_number = MAX+1`
- `trg_lcp_ai_sync_published` — sets published pointers, `status=published`, `published_at`,
  clears `archived_at` (AI owns pointers; manager must not call `LearningContent::publish()`)
- `trg_lc_bu_published_matches_latest` — no un-archive; cannot clear current while revisions
  exist; cannot clear published while publications exist; published pointer must match latest
  publication; `status=published` requires pointers + `published_at`

## Asset tenant + lifecycle (DB)

- `trg_lcra_bi` — sealed check + tenant matrix via JOIN (platform content → platform asset only;
  institution content → same-institution asset OR platform asset; reject archived attach)
- `trg_sma_bu_lifecycle` — allowed status/scan transitions; immutable identity/storage/checksum;
  timestamp ↔ status pairing

## Lock order

1. Institution
2. Subject
3. Curriculum / Program
4. Topic / Outcome
5. LearningContent
6. Users (UUID ascending)
7. Revision
8. Alignment
9. Asset
10. RevisionAsset / guard / publication
11. Audit

Snapshot IDs without locks first; then acquire locks in this order.
Stored media mutations: Institution → Users → Asset → Audit.

Audit events are written in the same transaction as the mutation.
Auth snapshot cache is invalidated only after successful commit.

## Access

`LearningContentAccessGate` is fail-closed. Published platform (and institution) content
returns `entitlement_required` — free student delivery is not allowed in this stage.

Review separation: revision author cannot solely publish the same revision.

## Limitations

- No real file upload or storage SDK integration
- Entitlement / student delivery policy pending
- No multi-process concurrency harness claim
- No controllers / HTTP API
- Unused attached assets may exist until publish; publish requires referenced assets to be fresh `ready` + `clean`
- Subtitle/transcript parent pairing is typed as a future hardening (documented limitation)
