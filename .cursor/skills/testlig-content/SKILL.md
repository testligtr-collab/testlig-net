---
name: testlig-content
description: >-
  Applies Testlig learning-content rules for LearningContent revisions, typed
  blocks, catalog placement, MEB/TYMM catalog and curriculum import, the
  question bank, assessments, student course and practice pages, and PDF or
  video media. Use when the task edits LearningContent, a revision, a typed
  block, CatalogTopicLesson, curriculum or catalog import, questions,
  assessments, student lesson or test content, PDF documents, YouTube or Vimeo
  blocks, or content publish. Do not use for general PHP explanation, payments,
  ads, or unrelated refactors.
---

# Testlig content

Keep the existing content model. Do not add a second table, service, or lesson tree for the same job.

This skill does not grant permission to create or publish production content. It does not replace `testlig-safety` or `testlig-delivery`. Read those too when the task writes production data, changes schema, or ships a PR.

## When selected

1. Read the current entity, service, repository, voter, and migration for the touched model. Names that must already exist are in [references/content-model.md](references/content-model.md).
2. Confirm the content source and scope. Do not invent MEB/TYMM text, outcome codes, or a new provider or file type.
3. List any production write (seed, import `--apply`, publish) before doing it. Dry-run first. Apply or publish only after the user explicitly approves that write.
4. If code or a migration is required, keep it a small slice on the existing chain.
5. Add tests for the slice. Unknown or malformed blocks, closed gates, and unpublished assets stay fail-closed.
6. Use `testlig-safety` for production, secrets, migrations, and ops. Use `testlig-delivery` when opening a PR, waiting on CI, or deploying.
7. Do not merge while CI is red.
8. Production seed or import: dry-run, user approval, apply, then verify. Report counters and what was not touched.
9. Do not start Docker. Do not ask for secrets or personal data. Do not change payment or ad policy.

Use the checks in [references/content-checklist.md](references/content-checklist.md) before finishing.

## Canonical chain

- `LearningContent` is the content record. Its revision holds typed JSON and the seal.
- `CatalogTopicLesson` is only the topic navigation link (placement). Publishing content and publishing the placement are separate steps.
- `CatalogSubject.canonicalSubject` is the only subject match. Do not infer it from name or slug.
- `Assessment` is the test. `AssessmentAttempt` and `AssessmentAttemptAnswer` are the attempt and the encrypted answer. Score with `DecimalScoreCalculator`. The correct answer lives on `QuestionAnswerKey`, not in student HTML.

## Non-negotiables

- A sealed revision is not edited in place. Clone a new unsealed revision.
- No hard delete of content, placements, questions, attempts, or document assets.
- The publisher of a learning-content revision is not the revision author (`LearningContentException::reviewSeparation`). Read publisher rights from the current voter or `AdminAuthorization`. Do not invent a role.
- A teacher manages only their own draft content. Reason codes stay in audit metadata. Users see short Turkish labels.
- Student bodies render only through `StudentTopicContentQuery`: published subject, unit, topic, and placement, published `LearningContent`, sealed published revision, and an allow from `LearningContentAccessGate`. Anything else is hidden. Another grade or a missing record stays an opaque 404.
- Twig gets immutable DTOs. No entity, UUID, `storageKey`, ciphertext, answer key, or raw JSON. No `|raw`. Autoescape stays on. `/ogrenci` and `/yonetim` stay `Cache-Control: no-store, private`.
- Editor block types are heading, paragraph, list, callout, quote, math, video, and document. Video and PDF are added from their own forms, not as empty generic blocks. The domain validator still accepts legacy `*_reference` types; the editor must not start exposing them.
- Video goes through `VideoUrlParser` only (YouTube and Vimeo). No iframe paste and no HTTP fetch of the URL. Players are `VideoEmbed::playerUrl()`.
- PDFs are `LearningDocumentAsset` only, outside the public web root, max 25 MB, `%PDF-` plus `application/pdf`. Upload stays `pending`. SuperAdmin or Admin marks `ready`. A teacher cannot approve their own file. There is no antivirus scanner, so do not mark a file scanned. Stream through the authorized controller after the same student gate. Do not log bytes, `storageKey`, or disk paths.
- Catalog and curriculum imports default to dry-run and stay idempotent. Import does not publish. `app:catalog:publish-tree` is a separate dry-run-then-`--apply` step. Do not mix an older program version with a new TYMM import.
- Do not accept `earnedPoints` or the correct answer from the client. Do not seed questions, attempts, PDFs, or placements in production unless the user approved that exact ops write.

## Out of scope

Payments, ads, new video hosts, Office files, MP4 upload, transcoding, DRM, CDN or S3 as a requirement, OCR, automatic captions, watch analytics, and automatic deletion of unused files.
