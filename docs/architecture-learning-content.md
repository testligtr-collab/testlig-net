# Stage 2.15 — Learning Content + Stored Media foundation

Domain / application / security / persistence, plus admin workspace list/detail/create,
a safe typed revision block editor, student typed-block rendering, YouTube/Vimeo
URL blocks, and local PDF documents (Twig autoescape; no `|raw`). No REST API,
CDN/S3, payment, or AI. `StoredMediaAsset` stays metadata-only.

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
- DB owns current pointer: `trg_lcr_bi_revision_number` enforces `revision_number = MAX+1`;
  `trg_lcr_ai_sync_current` sets `current_revision_id/number` on every revision INSERT.
- App draft create still inserts content with null current, then revision 1 in the same
  transaction (MariaDB cannot defer FKs, so `current_revision_id NOT NULL` on content INSERT
  before the first revision is impossible). The app never commits content-only orphans.
- After revision flush, manager refreshes the content entity so UoW matches the AI sync.

## Publication integrity (DB)

- `trg_lcp_bi` — revision exists + sealed; `content_id` match; must target `current_revision_id`;
  `content_hash` / `schema_version` match revision; `publication_number = MAX+1`
- `trg_lcp_ai_sync_published` — sets published pointers, `status=published`, `published_at`,
  clears `archived_at` (AI owns pointers; manager must not call `LearningContent::publish()`)
- `trg_lc_bu_published_matches_latest` — no un-archive; while revisions exist, current must
  equal latest revision (cannot clear); while publications exist, published id/number/`published_at`
  must match latest publication; `status=published` requires pointers + `published_at`;
  archived may keep matching published metadata

## Asset tenant + lifecycle (DB)

- `trg_lcra_bi` — sealed check + tenant matrix via JOIN (platform content → platform asset only;
  institution content → same-institution asset OR platform asset; reject archived attach)
- `trg_sma_bu_lifecycle` — allowed status/scan transitions; immutable identity/storage/checksum;
  timestamp ↔ status pairing; `updated_at` monotonic; no pending→archived
- Timestamp policy (`StoredMediaAsset`):
  - pending: `readyAt` NULL, `quarantinedAt` NULL, `archivedAt` NULL
  - ready: `readyAt` NOT NULL, `quarantinedAt` NULL, `archivedAt` NULL
  - quarantined: `readyAt` NULL, `quarantinedAt` NOT NULL, `archivedAt` NULL
  - archived: `readyAt` NULL, `archivedAt` NOT NULL, `quarantinedAt` MAY remain if previously quarantined
- CHECKs: `chk_sma_ready_at`, `chk_sma_quarantined_at` (archived may keep quarantine timestamp),
  `chk_sma_archived_at`

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
returns `entitlement_required` unless an explicit `LearningContentAccessPolicy` marks
`free`. Do not loosen the global default.

Review separation: revision author cannot solely publish the same revision.

Platform authorization (updated): SUPER_ADMIN all; ADMIN create/manage/review/publish;
MODERATOR view + return-to-draft (no publish); HEAD/EXPERT create/review/publish;
TEACHER own draft + submit (no publish).

Catalog student navigation binds via `CatalogTopicLesson` (separate from CurriculumProgram
alignments). See `docs/architecture.md` catalog section.

## Admin revision editor (safe block form)

Admin-only draft editor under `/yonetim/icerikler/{id}/revision`:

- Reuses `LearningContentDocument` + `LearningContentDocumentValidator` +
  `LearningContentManager::updateUnsealedRevision` / `cloneAsNewRevision`
- UI allowlist: `heading`, `paragraph`, `list`, `callout`, `quote`, `math`, plus
  `video` and `document` added from dedicated forms (not empty generic blocks).
  Older media/interactive JSON types are still rejected by this editor.
- Optimistic concurrency via hidden `expected_revision_id` (+ optional `expected_content_hash`);
  stale / sealed / non-current → conflict flash, no silent overwrite
- Mutations are POST + CSRF (`learning_content_revision_{id}`) + PRG
- Twig autoescape only (plain text fields); admin preview at `.../revision/taslak-gorunum`
  is not a student body renderer and must not leak `storageKey` / raw JSON

## Admin lifecycle + placement UI

Detail (`/yonetim/icerikler/{id}`) exposes review/publish forms wired to
`LearningContentManager::submitForReview` / `returnToDraft` / `publish` / `archive`:

- Required short `note` field → audit `reason_code` (snake_case)
- Moderator: return-to-draft only (no publish control)
- Publish: access policy must already exist (reject with flash; do not auto-set Free);
  active canonical subject checked in controller; SoD enforced by manager
- Free policy form kept with `confirm_free` + warning text

Catalog topic show (`/yonetim/mufredat/konu/{id}`) lists placements and creates drafts via
`CatalogTopicLessonManager`; publish placement is a separate confirmed POST; archive is
irreversible. Duplicate slug/position/content → flash.

Platform assessment catalog placements (`CatalogTopicAssessment`) bind published platform
quizzes/practice tests to catalog topics for navigation only (same placement fields as
`CatalogTopicLesson`, but FK → `Assessment`). Admin create/publish/archive from test detail
(`/yonetim/testler/{id}`) via `CatalogTopicAssessmentManager`; grade and canonical subject
must match the catalog subject (fail-closed). Student topic **Kendini dene** cards reuse
Gate A assessment entitlement — LC access policy does not grant assessment discovery.

Student topic page (`StudentTopicContentQuery`) keeps the AND visibility chain, then
normalizes sealed published revision bodies via `StudentContentBlockNormalizer` into
immutable `StudentContentBlockView` DTOs (heading/paragraph/list/quote/math/callout/video/document).
Unknown/malformed/media blocks are skipped. Video iframes use the generated
`youtube-nocookie.com` or `player.vimeo.com` URL, not the pasted address. PDFs open
through the student route only after the same visibility AND gate. No revision UUID,
`storageKey`, audit notes, or raw JSON reach Twig. `/ogrenci/*` and PDF responses
use `Cache-Control: no-store, private`.

## Video and PDF (V1)

- `video` block keys: `provider`, `providerVideoId`, `title`, `description`.
  Hosts must match exactly: `youtube.com`, `www.youtube.com`, `youtu.be`,
  `youtube-nocookie.com`, `vimeo.com`, `www.vimeo.com`. No HTTP fetch, no embed HTML.
- `document` block keys: `assetId`, `label`. The asset is `LearningDocumentAsset`,
  not `StoredMediaAsset` (that model requires a clean scan status and this app has
  no antivirus scanner, so it must not be marked scanned).
- Upload writes the file first, then inserts `pending`. A failed insert deletes the file.
  A failed write never leaves a `ready` row. Ready status is an explicit SuperAdmin/Admin
  action after SHA-256 recheck. Teacher upload cannot self-approve. Archived, quarantined,
  and pending assets are not served to students.
- Bytes live in `var/learning-documents` (mode 0700, random 32-hex key). Display names
  are normalized metadata. Max 25 MB, `%PDF-` signature, `application/pdf` finfo.
- `LearningDocumentByteStore` is the write, checksummed-read, and failed-insert
  cleanup contract. `LearningDocumentStorage` is the local implementation.
  `R2LearningDocumentStorage` is the private Cloudflare R2 implementation. Its live
  acceptance is deferred because the selected provider for the next upload adapter
  is Bunny Storage. `BunnyLearningDocumentStorage` calls the documented HTTPS
  `PUT`, `GET`, and `DELETE` API and only `https://storage.bunnycdn.com`. It does
  not follow redirects or attach a pull zone. `LearningDocumentVerifiedCopy` is the
  shared temporary-file lifecycle for both cloud providers. `LearningDocumentAsset.storage_provider`
  is permanent. Existing `local` and `r2` rows stay on those providers.
  `LEARNING_DOCUMENT_UPLOAD_PROVIDER` selects the provider for a new upload only.
  Missing Bunny or R2 configuration fails the upload. A read streams the private
  object once, checks Content-Length before consuming the body, and also stops if
  the bytes read exceed 25 MB. The verified copy is in `var/learning-document-private`.
  SHA-256 is checked before the response. Kernel terminate removes copies from a
  request that finishes. A killed process can leave a file; a later sweep holds its
  lock until an unlocked file older than one hour is removed, and skips locked or
  younger files. A failed remote cleanup is logged only as
  `learning_document.r2_cleanup_failed` or `learning_document.bunny_cleanup_failed`
  and does not replace the original save error. There is no public bucket, CDN, or
  browser URL. `bin/bunny-pdf-live-accept.php` is a one-shot PDF acceptance tool.
  It uses the Bunny adapter directly, does not boot the Symfony kernel, and does not
  open a database or a production env file. The PDF adapter live acceptance ran once
  on 2026-10-13 against zone `testlig-pdf-test` and `https://storage.bunnycdn.com`:
  `result=accepted`, `range=206`, `anonymous=401`, `cleanup=404`. The production
  upload provider stayed Local. Uploaded video, question images, news images, and
  simulations remain open on their own feature records. Question image upload can register a pending JPEG, PNG, or WebP reference on a new question revision. A pending image can be read from its recorded provider and scanned with a local clamd INSTREAM client by an active verified super admin. A clean result marks that asset ready in the same transaction. The manual ClamAV preflight workflow only reads server measurements. Preparing the confirmed install workflow in pull request 131 was not itself the installation. On 2026-10-15 that workflow ran once for SHA d9874e3c2720dc7610c369c767a7f0412a6ad262 (https://github.com/testligtr-collab/testlig-net/actions/runs/38733923777). ClamAV installation and the isolated client acceptance passed, and signature freshness was verified at that moment: summary_install=installed, signature_dns=match, summary_signature=current, socket_mode=660, tcp_3310_listener=no, accept_clean=clean matched=1, accept_infected=infected matched=1, accept_missing=connection_failed matched=0, accept_timeout=timed_out matched=0, summary_accept=ok, summary_updater=enabled, summary_cleanup=ok. The application was not connected to the socket. User groups, OpenLiteSpeed, and upload providers were not changed. Production assets were not scanned or marked ready. A later code slice adds a SuperAdmin POST on the existing platform question detail and institution question detail. The form is shown only for the one pending image linked to that question's current revision, and the POST checks the same link. It does not accept an actor, storage key, or file path. Clean and ready still require the existing scan transaction. Before and after that scan, on-disk main, daily, and bytecode versions must match the official TXT fields as integers, and clamd `VERSION` must report the same loaded daily version. Those TXT fields are taken from one DNS response only when QR is response, opcode is QUERY, RCODE is NOERROR, TC is clear, the question is current.cvd.clamav.net TXT IN, and the TXT owner is that name or the end of an explicit CNAME chain in the answer section. A missing, truncated, contradictory, or unrelated TXT does not match. `VERSION` does not report the loaded main or bytecode databases, so those loaded copies are not treated as proven. An empty CVD directory, an unreadable reply, or a change between the two checks leaves the asset pending. The production socket and CVD settings stay empty. `public/index.php` boots `symfony/runtime` v7.4.14, which calls `Dotenv::bootEnv()` on each front-controller start unless dotenv is disabled. `dotenv_overload` defaults to false, so an existing process environment is not replaced by `.env.local`. `symfony/dependency-injection` v7.4.17 `PhpDumper` compiles `%env(...)%` arguments to `$container->getEnv('string:...')` rather than embedding the value present during `cache:warmup`. `config/services.yaml` supplies an empty default for `QUESTION_IMAGE_CLAMAV_DATABASE` only when that variable is absent. Warmup therefore does not freeze socket or CVD settings into the container. A later connection change must restore the previous values of those two keys, any group membership it adds, and any ACL it changes. Clearing the keys without that record is not a rollback. The manual access-probe workflow measures socket and signature files, and it treats a live lsphp uid as the site user only when that uid is the documented `testl3865` account and owns `/home/testlig.net`. `runuser` checks use the new process account groups, which are reported separately from the live worker groups. PING and VERSION each use their own time-bounded Unix connection and read until the newline frame. On 2026-10-16 that workflow ran once for SHA 1bddc659183921545603ddc64628700c6225c4c3 (https://github.com/testligtr-collab/testlig-net/actions/runs/38880052720). The read-only run succeeded. No live lsphp process was visible (`php_process=unknown`), so the application uid, groups, and DAC access were not verified, and PING/VERSION were not attempted. clamd@scan and clamav-freshclam were active and enabled. Disk signatures were main.cvd 63, daily.cld 28155, and bytecode.cvd 339; those readings are not current freshness and do not prove the loaded main or bytecode databases. The socket mode was 660 and its parent directory mode was 710; that shape is not application access. SELinux was Permissive, which is not an access acceptance. Both application environment keys were absent from the shared and release env files. Production settings and assets were unchanged. The next dependency is a reliable measurement of the live PHP worker uid and its relationship to the site. This record does not perform that measurement or the connection change. A later code change only separates the process-scan diagnostics: a finished scan with no lsphp candidate and no unreadable comm or status is `none`, an existing but unreadable comm or status is `unreadable`, and the existing two-second budget is `timed_out`. A proc entry that disappears during that single pass is counted separately from a permission failure and blocks application-user selection. Root and a CLI `php` process are not the web worker. Any lsphp candidate with a non-numeric UID or GID blocks application-user selection, runuser, and the socket attempt even when another candidate matches `testl3865` and the site owner. That diagnostic change was not run in production. `VERSION` still does not prove the loaded main or bytecode databases. Student image delivery and end-to-end production acceptance stay open.
- Download is `LearningDocumentResponse` (`BinaryFileResponse`, so Range works) with
  `Content-Type: application/pdf`, attachment-safe inline disposition, `nosniff`, and
  `Cache-Control: no-store, private`. No new site-wide CSP.
- `Version20260926153000` `down()` drops the table only. It does not delete files.

## Curriculum primary-outcome dependency

Admin create form (`outcomeChoiceMap`) lists only
`CurriculumLearningOutcomeRepository::findActiveOrderedForSubject` — active
`CurriculumLearningOutcome` rows for the selected canonical Subject (status=active).
CatalogSubject mapping alone is not enough; a curriculum program → unit → topic →
outcome chain must exist. Pilot slice:
`app:curriculum:import-pilot-outcome` +
`data/curriculum/meb/tymm-2026/grade-1-matematik-uzamsal-iliskiler.yaml`
(MAT.1.3.1 / Uzamsal İlişkiler). Import publishes the program for later LC publish
eligibility but does not create LearningContent or placements.
`app:curriculum:reconcile-official-program` is a later, closed completion of that
published program from `grade-1-matematik.yaml`. It is not a general published-program
editor. Curriculum theme order follows the TYMM theme numbers. The catalog keeps its
own işleniş order. The existing `mat_1_3_1` row and anything linked to it stay in place.
Production apply stays a separate approval.

## Lesson package import

`app:learning-content:import-package` reads one allowlisted `lesson.yaml` and, only with
`--mode=apply` plus the dry-run plan fingerprint, replaces the current unsealed revision of an
existing owned draft. The stored type for this lesson package is `topic_explanation`.
verify and dry-run write nothing. A second apply with the new fingerprint is a noop.
A stale fingerprint or a failed write rolls the transaction back and leaves the placeholder in place.

The draft must already exist: content and revision are draft, the revision is the default
`[Taslak]` paragraph or already identical to the package, the actor is the verified teacher
who owns it, there is no review, publication, or catalog placement, and the title, package summary,
subject, grade, and `mat_1_3_2` outcome match the package. Any other state is a conflict and
writes nothing. verify and dry-run print closed `conflict_reasons` codes and boolean match
flags. They never print emails, UUIDs, titles, summaries, or document text. The command does not create a second content row for this package.

Questions stay on the separate CSV importer or the closed `app:question:import-package`
allowlist path (`docs/question-package-import.md`). This lesson command does not submit review, seal, publish,
open free access, or create a placement. Production dry-run and apply are separate manual
SSH dispatches; they are not part of deploy. The ops workflow reads the actor email from the
GitHub secret `TESTLIG_CONTENT_ACTOR_EMAIL`, masks it, and injects it into that one remote
process environment. The SSH remote command reads the first stdin line with `read` (it is never
a `bash -s` script line), exports it, then runs the remaining stdin with `bash -se`. Mode and
package are `printf %q` exports, not stdin data. The workflow does not ask for the email in
inputs and does not write it to a VDS `.env` or profile file.

## Question package import

`app:question:import-package` reads one allowlisted `questions.csv` from the repository. It is
not a general upload API and does not replace the browser CSV importer. verify is the default.
dry-run plans create/noop/conflict counts inside a transaction and rolls back. apply requires
the matching 64-hex plan fingerprint and creates missing owned Draft questions, revisions, and
answer keys through `QuestionManager`. A second apply is a noop. Review, publish, placement,
and assessment links stay separate SoD steps. Production dispatch needs a separate approval.
Browser credential and file-picker security is not relaxed.

## Limitations

- No CDN/S3 or antivirus. PDF approval is a manual admin ready mark, not a scan result.
- Entitlement / student delivery policy pending
- No multi-process concurrency harness claim
- No public content REST API / CDN math renderer (math is plain escaped text)
- Unused attached assets may exist until publish; publish requires referenced assets to be fresh `ready` + `clean`
- Subtitle/transcript parent pairing is typed as a future hardening (documented limitation)
- MariaDB cannot defer foreign keys: content INSERT cannot require `current_revision_id NOT NULL`
  before the first revision exists. Integrity relies on same-transaction revision INSERT +
  `trg_lcr_ai_sync_current`, never a committed content-only row with revisions but null current.

## Production checkpoint (MAT.1.3.3 assessment)

External observation from a ChatGPT production admin/student browser. Not re-verified in this change.

- Assessment `01a11c00-d688-7513-b841-d0cd2cffed2f`: **Nesnelerin Biçimsel Özellikleri — 5 Soruluk Test**. Grade 1 Mathematics, 5 questions, 10 minutes, 5 points total.
- SuperAdmin created the draft and submitted it for review. A different Expert Teacher account published it.
- The assessment access policy was saved as free. The learning-content free policy is a separate record.
- A placement was created and published on Nesnelerin Geometrisi (2) / Nesnelerin Biçimsel Özellikleri.
- The student topic **Kendini dene** card opened that test detail, and **Teste başla** was visible. Topic HTML showed no answer-key, `storageKey`, or raw UUID.
- ChatGPT verified the solve and result flow: the test was started, five answers were saved, and the test was finished. Result: 5 correct, 0 wrong, 0 blank; 5/5, 100%. Geçmişim showed a Tamamlandı record and a result link. The attempt was created with the user's approval for this test. This checkout did not re-check production.
- On 8 October 2026, a ChatGPT production SuperAdmin browser verified these audit records as success, with matching sources. This checkout did not re-check production. `assessment_published` names assessment `01a11c00-d688-7513-b841-d0cd2cffed2f` and revision `01a11c00-d689-7447-a7f2-6ecd920c620f`. `assessment_access_policy_set` records `access_class=free` for that assessment. `catalog_topic_assessment_created` and `catalog_topic_assessment_published` share placement `01a11c31-ac50-7f0f-9e30-df6a3da6229b` on topic `01a0cebb-8c06-7d2a-b9ab-bb2373242175`.
- The audit screen showed the actor only as `user`, so author and publisher identity separation was not separately verified from that screen.
- Mobile/tablet 390px and 768px remain not accepted. The desktop observation does not replace that check.
