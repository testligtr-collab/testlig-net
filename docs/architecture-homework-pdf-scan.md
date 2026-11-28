# X01 homework PDF — closed scan foundation (0.1e)

**Status:** Infrastructure only. Upload UI/routes **closed**. Product decision A: no taramasız kabul.  
**Feature:** `X01-homework` remains `partial`; `active_feature_ids` unchanged.

## Product bounds

- Text submission remains required; one optional PDF is future work.
- Private local homework storage (not LearningDocumentAsset, not web-root).
- Antivirus required before any upload accept; MIME/PDF magic is not a scan.
- Teacher download and attachment DB come in later slices.

## This slice

| Included | Excluded |
|----------|----------|
| Quarantine staging under `var/homework-pdf-quarantine` | Attachment entity / migration / FK |
| `PdfDocumentInspector` + clamd INSTREAM adapter (25 MB) | Student/teacher upload or download controllers |
| Fail-closed verdicts + default-closed upload gate | Enabling upload in production |
| Same-handle scan + locked-inode post-hash | Claiming durable store integrity |
| Fake/transport tests (symlink reject, partial-write cleanup) | Production clamd/OLS accept, Bunny/R2, upload UI |
| Isolated `workflow_dispatch` runner clamd accept (Unix socket) | Production VDS SSH ops as this accept |

Default binding: `ClosedHomeworkPdfScanReadiness` (never ready) + `HOMEWORK_PDF_UPLOAD_ENABLED=0`. Setting enabled alone does **not** open upload.

## Byte identity (this PR)

1. Quarantine writes to a random `.tmp`, renames to `.bin` only when the target does not already exist, then opens that regular file with `LOCK_EX`.
2. `ClamdHomeworkPdfScanAdapter` streams via `ClamdInstreamClient::scanHandle` on that same open handle (not a second path open).
3. After a clean reply, `hashFromLockedHandle()` re-reads the locked handle and requires size+SHA match.
4. Triple path-hash alone is **not** the TOCTOU control. The binding is the open locked inode for the scan window.

**Not claimed:** protection against a same-UID attacker who can already write/replace any path the PHP process can reach once the handle is closed. Durable+DB integration must re-verify bytes when copying into durable storage and again before trusting a DB commit.

## Path / symlink threat model (this PR)

- Client filenames are never storage keys; keys are 32-hex random values; files chmod 0600 under a 0700 root.
- Symlinks and non-regular files are rejected for root and staged objects; preexisting final/temp paths are not overwritten.
- Escape outside the resolved quarantine root is rejected.
- **Not claimed:** a general filesystem sandbox against every same-UID actor.

## Cleanup (this PR)

- Partial write, rename failure, or later exception runs quarantine cleanup for `.tmp` / `.bin`.
- Non-clean scan and unexpected errors delete the quarantine candidate before returning a fixed error code.
- Cleanup failure is fail-closed (`homework_pdf_quarantine`); it is never converted into a clean staging result.
- Homework PDF classes do **not** log. Exception messages are stable codes only (no path, body, virus name, or secret). There is no log-redaction suite because there is no logging.

## Student PDF attach integration (0.1f; production upload still closed)

Implemented in application code (tests open the gate with Fake scanner + env flags):

1. Gate assert (`HomeworkPdfUploadGate` + readiness)  
2. Inspect bytes → quarantine stage (locked handle + SHA)  
3. Scan same handle → only `clean` continues  
4. Re-verify bytes when copying to **durable** private key (`HomeworkPdfDurableStore` under `var/homework-pdf`)  
5. DB commit (`homework_submission_attachments`, UNIQUE per submission; bump draft revision)  
6. On DB/write failure: delete durable candidate; never leave an orphan attachment row  
7. Existing attachment is **not** replaced in this slice (conflict / `pdf_exists`)

Teacher download (assigning teacher only, private stream, no public URL) is implemented in code; live upload remains closed. Still open for production enablement: OLS/lsphp clamd socket access, StreamMaxLength/MaxFileSize measurement, live signature freshness evidence, quota/retention/orphan cleanup (runtime readiness replaced `ClosedHomeworkPdfScanReadiness` in the readiness slice below). Unlimited storage is not assumed. `HOMEWORK_PDF_UPLOAD_ENABLED` remains `0` in production.

## Shared durable storage, runtime readiness, and quarantine purge (readiness slice)

- **Shared non-release paths:** `HomeworkPdfStoragePaths` + `HomeworkPdfStorageFactory` resolve `HOMEWORK_PDF_STORAGE_DIR` (durable) and `HOMEWORK_PDF_QUARANTINE_DIR` (quarantine). Empty values fall back to `var/homework-pdf` and `var/homework-pdf-quarantine` in local/CI dev; release deployments must configure explicit shared directories outside `releases/` and outside `public/` / `public_html/` (e.g. `/home/testlig.net/shared/var/homework-pdf` and `/home/testlig.net/shared/var/homework-pdf-quarantine`).
- **Deploy persistence (no symlink, copy-only adoption):** Because `HomeworkPdfDurableStore` rejects symlinked roots (`is_link`), `deploy/vds/release-deploy.sh` and `deploy/vds/bootstrap-layout.sh` provision `0700` shared directories under `shared/var/` and keep `$RELEASE_DIR/var/homework-pdf` unsymlinked, so `KEEP_RELEASES=5` pruning never deletes the shared store. The deploy scans **every** existing release (not only the previous one, because pruning would otherwise delete a PDF that only lives in an older release) for legacy `var/homework-pdf/<32-hex>.bin` objects and adopts them copy-only (`0600`). It never deletes or overwrites: identical bytes already in shared are counted, a name collision with different bytes, a symlinked destination or a failed copy aborts the deploy **before activation** (the new release directory is removed, the `app` symlink is untouched), and entries that are not durable store keys are left alone and only reported. `HomeworkPdfStoragePaths` rejects any path resolving inside `releases/<name>` (including through `app/` symlinks).
- **Fail-closed runtime readiness:** Production wires `HomeworkPdfScanReadiness` to `RuntimeHomeworkPdfScanReadiness` (keeping `HOMEWORK_PDF_UPLOAD_ENABLED=0` and `HOMEWORK_PDF_SCAN_READY=0` by default). Readiness returns `true` only when `HOMEWORK_PDF_SCAN_READY=1`, the operator-declared `HOMEWORK_PDF_CLAMD_STREAM_MAX_BYTES` is at least the 25 MiB application limit (`PdfDocumentInspector::MAX_BYTES`, default `0` = closed), both storage roots pass non-release/non-symlink/non-public/non-overlapping writable checks, `QUESTION_IMAGE_CLAMAV_SOCKET` passes `UnixClamdSocketPath`, `CvdDatabaseDirectoryReader` reads valid `main`/`daily`/`bytecode` headers, a bounded (`1.5s`) `clamd` Unix socket check gets `nPING` → `PONG\n`, `nVERSION` is well formed with a loaded `daily` equal to disk `daily`, and the signature build date in the `nVERSION` reply is at most `72 h` old (and not more than `1 h` in the future). Socket unreachable, an untrustworthy or malformed reply, a stale signature, or a too-small/undeclared stream limit each keep it closed; PHPUnit shows each case with fixtures (see `HomeworkPdfReadinessAndStorageTest`), which is not live evidence. `HOMEWORK_PDF_CLAMD_STREAM_MAX_BYTES` mirrors `clamd.conf` `StreamMaxLength`; it is a declaration, not a live measurement.
- **Stale orphan quarantine cleanup:** `HomeworkPdfQuarantineCleaner` + `app:homework-pdf:purge-quarantine` (`--max-age >= 60`, `--dry-run`) remove only unlocked (`LOCK_EX | LOCK_NB`) stale 32-hex `.tmp`/`.bin` files inside the quarantine root, without touching symlinks, active uploads, files that resolve outside the root, or durable objects. Because durable PDFs use the same `<key>.bin` naming, the cleaner refuses to run (and deletes nothing) when the quarantine root equals or nests with the durable root.

## Storage policy proposal and read-only inventory (quota / retention / orphan preview)

Status: **proposal + read-only preview only.** No quota rejection, no automatic deletion and no production setting exists or is enabled by this slice. `HOMEWORK_PDF_UPLOAD_ENABLED` and `HOMEWORK_PDF_SCAN_READY` stay `0`. Anything marked *karar bekliyor* needs a product decision and is **not** applied.

### How record and file relate (from code)

- Durable file key = `homework_submission_attachments.storage_key` (`<32 lowercase hex>.bin`, UNIQUE, case-insensitive collation). The durable file is written **inside** the DB transaction, before the row commits; on DB failure `deleteQuietly` removes it.
- *Impossible by schema:* two rows with the same key, two attachments for one submission (UNIQUE `submission_id`). The inventory still counts `duplicate_reference` defensively; a non-zero value means data corruption, not normal operation, and physical bytes are counted once per key.
- *Possible and normal:* a file without a row while an upload is between write and commit (short window), or after a crash or rollback whose `deleteQuietly` failed. This is why only **old** unreferenced files are ever called candidates.
- *Inconsistency:* a row whose file is missing, unreadable, replaced by a different size, or whose key is malformed (`db_missing_file`, `db_unreadable_file`, `db_size_mismatch`, `db_invalid_key`). The teacher download already fails closed (`404`) on these; the inventory only reports them and never repairs.
- Trustworthy timestamps: `created_at` (UTC) on the row; file `mtime` only for rows-less files. `mtime` can be reset by copy/restore, which only makes a file look younger (safe side). Missing, zero or future `mtime` keeps the file young (`timestamp_unreliable_files`).

### Quota owner (proposal)

- **Proposed owner: the institution**, derived `attachment → submission → assignment.institution_id`. The inventory counts a reference for an institution only when the chain is consistent: `homework_recipients.assignment_id = submission.assignment_id` **and** `classrooms.institution_id = assignment.institution_id`. Any other case (missing join, mismatch) is `ambiguous_owner_references`, reported in a separate `institution=ambiguous` line and **never** charged to an institution.
- Rationale: assignments, classrooms and teachers are institution-scoped, so institution isolation stays intact; a per-student or per-teacher quota would need a second owner model and could not be enforced against one institution's storage bill.
- Declared vs physical: `db_declared_bytes` sums `byte_size` from rows; `db_physical_bytes` sums only files that exist, are regular, readable and **match the declared size** (metadata only, no PDF body or SHA read). `db_declared_minus_physical_bytes` is the difference. A future quota should charge **verified physical** bytes, with declared bytes as an upper bound while an upload is in flight. Unreferenced files are not charged to anyone (they have no owner).
- The 25 MiB (`PdfDocumentInspector::MAX_BYTES`) is a per-file limit, **not** a total quota. *Karar bekliyor:* the approved per-institution (or per-plan) byte limit; no live number is invented. `--preview-limit-bytes` accepts an explicit **test** number and only prints `usage_percent_*`; it has no effect on upload acceptance (`preview_limit_applied=no`).

### Retention by lifecycle (proposal)

| Class | Meaning (derived) | Proposal | Status |
| --- | --- | --- | --- |
| Draft | submission `draft` | Student-owned work in progress; never auto-removed by age alone. `draft_older_than_threshold` is information for a future stale-draft rule. | *karar bekliyor* (window) |
| Submitted | submission `submitted`, no evaluation | Teacher must still reach it; **never** a cleanup candidate by age. | keep |
| Evaluated | `current_evaluation_id` set | Retention window after evaluation (course/school-year end, legal need). | *karar bekliyor* |
| Unreferenced young | no row, younger than threshold | Protected (possible in-flight upload). | keep |
| Orphan candidate | no row, at least the threshold old | Preview only, never "definitely deletable". | *karar bekliyor* (threshold, who may delete) |

The command default of 86400 s and the 3600 s floor are **test values, not production policy**. Unknown lifecycle, missing timestamp or ambiguous ownership means *keep*.

### Read-only inventory command

```
php bin/console app:homework-pdf:inventory [--orphan-min-age=86400] [--by-institution] [--preview-limit-bytes=N]
```

- Reads the configured durable root only (no symlink follow, no directory descent, no `mkdir`, no fallback to a wider scan) and the database in bounded pages (500 rows; one `IN (...)` lookup per 500 file names, never one query per file). No PDF body is read, no full-store SHA scan. There is no `--apply`, `--delete` or hidden write mode, no scheduler/cron/workflow and no admin screen.
- Output is `key=value` lines and contains **no** original file names, student emails, PDF content, storage keys, full server paths or env values. `--by-institution` adds institution id, reference count and bytes only.
- Point-in-time: the filesystem and the DB are read at different instants; counts can differ by files created meanwhile.
- Header: `homework_pdf_inventory_status` is `ok`, `root_missing` (nothing created; DB side still reported), `root_unsafe` (release-scoped, public, nested/equal to quarantine, or a symlink), `root_unreadable`, `invalid_option` or `error` (code `inventory_failed`; an I/O or DB failure is never shown as an empty store). `store_empty=yes` only for a readable, truly empty root.
- Disk side: `disk_files/bytes`, `matched_*` (file has a row), `unreferenced_young_*`, `orphan_candidate_files/bytes/temp_files`, `timestamp_unreliable_files`, `unsafe_*` (symlink, directory, special, invalid name, stat failure).
- DB side: `db_references`, `db_declared_bytes`, `db_physical_bytes`, `db_missing_file`, `db_unreadable_file`, `db_size_mismatch`, `db_invalid_key`, `duplicate_reference`, `ambiguous_owner_references`, per lifecycle `draft_|submitted_|evaluated_` `references|declared_bytes|physical_bytes`, `unknown_lifecycle_references`, `draft_older_than_threshold`.
- Exit codes: `0` ok / root missing, `1` unsafe root, unreadable root or internal error, `2` invalid option.
- **A live run is a separate decision** (read-only, but it reads production data; needs explicit scope). This slice ran it only against temporary fixture stores (PHPUnit) and without a database for wiring.

### Next phase (design notes only; not implemented)

- *Race with uploads:* a delete step must re-check the key against the DB **inside** a transaction that also blocks a new row for that key, re-stat the file (size, mtime still older than the threshold) and hold a per-key exclusive lock; a key that gained a row meanwhile is skipped.
- *Authorization:* a deletion command needs an explicit operator scope, a dry-run that prints a bounded count first, and no teacher/student route.
- *Rollback:* move to a recoverable quarantine location before final removal; never delete rows and files in one unrecoverable step; the shared store stays append-only until then.
- *Quota enforcement:* charge in the upload transaction (verified bytes), reject with a fixed message, and keep the number a deploy-time setting that defaults to "no limit" until approved.

## Release switch, rollback contract, and evidence still needed

**Persistence proof in code (not production):** `TeacherHomeworkPdfHttpTest::testPdfSurvivesReleaseSwitchAndOldReleaseCleanupWithIdenticalBytesAndWrongRootNeverReadsSilently` creates a PDF and submission with the durable/quarantine roots in a simulated `shared/var/` outside `releases/` and `public/`, downloads it as the assigning teacher ("release 1"), boots a fresh container for "release 2", deletes the old release tree, and downloads again: size and SHA-256 equal the stored bytes both times. The same test then points the app at a wrong root: the download is a controlled `404`, the wrong root is **not** created by the `GET`, nothing falls back to another location, and the original object is untouched. `ReleaseDeployScriptTest` covers the deploy side (seven releases + prune, multi-release adoption, abort before activation on a byte conflict). #229's authorization checks and file-integrity read gate (`assertReadable`: size + SHA before streaming) are unchanged.

**Real migration of existing PDFs (not run this slice):** production has no homework PDF yet because upload is closed. If PDFs ever exist in a release directory, adopt them in an operator-run step: for each file record the owner/submission and DB row (`homework_submission_attachments.storage_key`, `byte_size`, `sha256`), copy (never move) to the shared root, verify size and SHA-256 against the DB row, only then let a later deploy prune the old release. The deploy script's copy-only adoption plus the abort on conflict is the safety net; it does not replace the DB cross-check.

**Rollback / compatibility limit:** the shared store is append-only for code. Switching back to an older release is safe only if that release reads the same configured root (`HOMEWORK_PDF_STORAGE_DIR`, kept in `shared/.env.local`). An older release without this setting uses its release-scoped default and will not see shared PDFs; it fails closed (`404`, readiness closed) and never deletes, overwrites or silently reads another root. Do not delete `shared/var/homework-pdf*` during rollback.

**Evidence still needed before the upload gate may open (each is separate; none was produced here):**

1. Live `clamd` reachable from the OLS/lsphp user over the Unix socket (permission proof), plus the real `StreamMaxLength`/`MaxFileSize` values and an INSTREAM near-limit transfer on the VDS.
2. Real OLS / lsphp request-body and `upload_max_filesize`/`post_max_size` limits against the 25 MiB application limit.
3. Live signature freshness on the VDS (freshclam schedule, `daily` age under the 72 h threshold).
4. `HOMEWORK_PDF_STORAGE_DIR` / `HOMEWORK_PDF_QUARANTINE_DIR` / `HOMEWORK_PDF_CLAMD_STREAM_MAX_BYTES` present in `shared/.env.local` and `isSafe*DirectoryReady()` true on the live release.
5. Quota, retention, and orphan-durable-object policy: approve the values proposed in "Storage policy proposal and read-only inventory" and run a separate, explicitly approved read-only live inventory (`app:homework-pdf:inventory`). Neither quota enforcement nor automatic deletion exists; the quarantine purge only covers quarantine. Unlimited storage is not assumed.
6. Explicit production scope/approval for any live deploy, migration, or setting change.

## Isolated GitHub Actions ClamAV accept (runner only)

Manual workflow: `.github/workflows/x01-homework-pdf-clamav-ci-accept.yml` (`workflow_dispatch` only).  
Harness: `bin/homework-pdf-clamav-ci-accept.php` + `.github/scripts/homework-pdf-clamav-ci-setup.sh`.

- Ephemeral `ubuntu-24.04` runner installs Ubuntu `clamav` packages, runs one `freshclam` into an isolated DB dir, starts `clamd` with a **Unix socket only** (no TCP), then runs the homework pipeline/adapter as the unprivileged runner user.
- Test process uses a temporary open gate/readiness; production remains `HOMEWORK_PDF_UPLOAD_ENABLED=0` + `ClosedHomeworkPdfScanReadiness`.
- **Signature pass criterion (before scenarios):** disk `main`/`daily`/`bytecode` versions from `sigtool` must equal the consensus TXT from `current.cvd.clamav.net`, **and** `nPING` → `PONG` on the job socket. A successful download alone is not enough.
- Near-limit INSTREAM records configured `StreamMaxLength` / `MaxFileSize` (bytes) and transfer completion. `clean` or `streamMatched` alone is not full-coverage proof; if a same-size trailing AV-marker probe does not detect, coverage stays `unproven` (`tam_kapsam_dogrulanmadi`).
- Runner success is **not** production OLS/lsphp socket permission proof, production limit proof, production signature freshness, or readiness wiring.

Fake PHPUnit cases (timeout, malformed reply, hash mismatch, symlink, partial write, gate matrix) stay in `tests/Homework/Pdf/HomeworkPdfScanFoundationTest.php`.

## Clean verdict wording

A `clean` reply means clamd returned the exact OK line for the bytes streamed from the locked quarantine handle under current client policy. It does **not** guarantee the file is harmless, and it does **not** certify a later durable object without re-verification.
