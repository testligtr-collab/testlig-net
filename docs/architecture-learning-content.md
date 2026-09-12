<?php

declare(strict_types=1);

/**
 * Stage 2.15 — Learning Content + Stored Media foundation
 *
 * Domain / application / security / persistence only.
 * No controllers, UI, REST API, real upload, storage SDK, payment, or AI.
 *
 * ## Model
 * - `LearningContent` — stable identity (platform|institution), title/slug/code, status lifecycle
 * - `LearningContentRevision` — structured content JSON; unsealed updates allowed; seal is irreversible
 * - `LearningContentPublication` — append-only publication snapshot + contentHash
 * - `LearningContentOutcomeAlignment` + primary guard — curriculum denormalization (Question pattern)
 * - `StoredMediaAsset` — metadata-only media registry; `storageKey` serializer-ignored
 * - `LearningContentRevisionAsset` — role-tagged attachment to a revision
 *
 * Structured content lives under `src/LearningContent/Content/` (separate from Question content).
 * Media policy under `src/LearningContent/Media/` (MIME/size/sha256 + app-generated keys).
 *
 * ## Lock order
 * Institution → Subject → Curriculum/Program → Topic/Outcome → LearningContent
 * → Users (UUID ascending) → Revision → Alignment → Asset → RevisionAsset/guard/publication → Audit
 *
 * Audit events are written in the same transaction as the mutation.
 * Auth snapshot cache is invalidated only after successful commit.
 *
 * ## Access
 * `LearningContentAccessGate` is fail-closed. Published platform (and institution) content
 * returns `entitlement_required` — free student delivery is not allowed in this stage.
 *
 * Review separation: revision author cannot solely publish the same revision.
 *
 * ## Limitations
 * - No real file upload or storage SDK integration
 * - Entitlement / student delivery policy pending
 * - No multi-process concurrency harness claim
 * - No controllers / HTTP API
 */
