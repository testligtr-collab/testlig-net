# Content model (verified on main)

Read these types in the repo before editing. Do not recreate them.

## Learning content

- `LearningContent` status (`LearningContentStatus`): `draft` → `in_review` or `archived`; `in_review` → `draft` or `published`; `published` → `archived` or `draft`; `archived` is terminal.
- Publish runs only from `in_review`, and the actor must not be the revision author.
- `LearningContentRevision` is sealed on submit for review. `isSealed()` blocks in-place edits. A later draft is a new revision.
- Structured JSON is checked by `LearningContentDocumentValidator`. Editor allowlist is `LearningContentRevisionFormMapper::UI_ALLOWED_TYPES`: heading, paragraph, list, callout, quote, math, video, document.
- Student render: `StudentTopicContentQuery`, `StudentContentBlockNormalizer`, `StudentContentBlockView`. Unknown, malformed, and legacy media-reference blocks are skipped.
- Access: `LearningContentAccessGate`. Free access exists only when a `LearningContentAccessPolicy` is explicitly free.

## Catalog placement

- `CatalogSubject` → `CatalogUnit` → `CatalogTopic`. Student visibility uses `CatalogPublicationStatus`: `draft` → `published` or `archived`; `published` → `archived`; `archived` is terminal.
- `CatalogTopicLesson` placement is published by `CatalogTopicLessonManager::publish` only when the linked `LearningContent` is published and its published revision is sealed.
- Duplicate slug, content, or position on the same topic is a conflict, not a silent rewrite.
- Archive is irreversible for catalog rows. There is no hard delete.

## Video and PDF

- `VideoUrlParser` hosts, exact match only: `youtube.com`, `www.youtube.com`, `youtu.be`, `youtube-nocookie.com`, `vimeo.com`, `www.vimeo.com`. HTTPS, no user, password, or port. It does not request the URL.
- `VideoEmbed::playerUrl()` uses `https://www.youtube-nocookie.com/embed/{id}` or `https://player.vimeo.com/video/{id}`.
- `LearningDocumentAsset` / `LearningDocumentStatus`: `pending` → `ready` or `quarantined`; `ready` → `quarantined` or `archived`; `quarantined` → `archived`; `archived` is terminal. `LearningDocumentManager::canApprove` is active verified `ROLE_SUPER_ADMIN` or `ROLE_ADMIN` only.
- `PdfDocumentInspector::MAX_BYTES` is 26214400. Storage is `LearningDocumentStorage` under `var/learning-documents` with a random 32-hex key. `StoredMediaAsset` stays metadata-only and is not the PDF or video record.
- Responses: `LearningDocumentResponse` (`Content-Type: application/pdf`, `nosniff`, `Cache-Control: no-store, private`). Range uses Symfony `BinaryFileResponse`. Student download repeats the topic gate in `StudentTopicContentQuery::findServableDocument`.

## Questions and assessments

- `QuestionStatus` and `AssessmentStatus` use the same four states as learning content (`draft`, `in_review`, `published`, `archived`).
- Correct answers are stored on `QuestionAnswerKey` (`correctStableKey` / `correctStableKeys`). Student attempt answers are `AssessmentAttemptAnswer` ciphertext.
- `DecimalScoreCalculator` (`testlig_default_v1`) aggregates with bcmath. Points scale 2, percentage scale 4, `finalPoints = max(0, rawPoints)`. Do not reimplement weighting or penalties.
- Result pages reveal a correct answer only through the existing result reader and review policy. Platform practice result pages are SuperAdmin and platform Admin. Teacher result access stays fail-closed unless an existing voter, ownership, or delivery already proves coverage.

## Imports

- `app:catalog:import` — MEB/TYMM catalog fixture, dry-run unless `--apply`, writes drafts.
- `app:catalog:publish-tree` — separate publish, dry-run unless `--apply`, with required expected subject/unit/topic counts.
- `app:curriculum:import-pilot-outcome` — one curriculum outcome fixture, dry-run unless `--apply`. It does not create `LearningContent` or placements.
- Ops workflow `ops-curriculum-pilot-import.yml` modes: `dry-run`, `apply`, `verify`. Dispatch `apply` only after the user approves that production write.
