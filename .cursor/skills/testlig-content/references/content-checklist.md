# Content change checklist

Use this before opening a PR or running a production content command.

## Model

- [ ] The change uses `LearningContent`, `CatalogTopicLesson`, `Assessment`, or `AssessmentAttempt` instead of a new parallel table.
- [ ] Subject mapping is `CatalogSubject.canonicalSubject`, not a name or slug guess.
- [ ] Sealed revision JSON is not updated in place.
- [ ] No hard delete.

## Student surface

- [ ] Bodies pass `StudentTopicContentQuery` (published subject, unit, topic, placement, content, sealed published revision, access gate).
- [ ] Twig receives `StudentContentBlockView` (or the existing admin preview fields), never an entity or raw JSON.
- [ ] No `|raw`. HTML does not contain `storageKey`, local paths, asset UUIDs, ciphertext, or answer keys.
- [ ] Closed or foreign records return the existing opaque 404.
- [ ] Student and admin responses keep `Cache-Control: no-store, private`.

## Editor blocks

- [ ] New student-facing blocks are one of: heading, paragraph, list, callout, quote, math, video, document.
- [ ] Video is a normal URL parsed by `VideoUrlParser`. Iframe/HTML paste is rejected. No outbound fetch.
- [ ] PDF uses `LearningDocumentAsset`, stays `pending` until SuperAdmin/Admin `ready`, and is streamed by the authorized controller.
- [ ] A teacher cannot mark their own PDF ready. Pending, quarantined, and archived assets are not served to students.
- [ ] Logs and audit metadata omit file bytes, `storageKey`, and secrets.

## Questions and tests

- [ ] Correct answer stays on `QuestionAnswerKey`.
- [ ] Scores come from `DecimalScoreCalculator` or the existing scoring services. The client does not send earned points or the correct answer.
- [ ] No production question, assessment, or attempt seed unless that ops write was explicitly approved.

## Placement and import

- [ ] Content publish and placement publish are separate. Placement publish requires a published content and sealed published revision.
- [ ] Catalog/curriculum commands stay dry-run until the user approves `--apply` or workflow `mode=apply`.
- [ ] Import does not publish the catalog tree.
- [ ] Official source version, source code, and URL stay traceable. Source text is not rewritten.

## Ship

- [ ] Tests cover the gate, the deny path, and the new block or import.
- [ ] `testlig-safety` applies if production, schema, or secrets are involved.
- [ ] `testlig-delivery` applies if a PR, CI, or deploy is involved.
- [ ] CI is green before merge.
- [ ] The report lists counters and states that no production content row was written when that is true.
