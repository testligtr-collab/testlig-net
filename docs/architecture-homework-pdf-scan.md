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

## Release switch, rollback contract, and evidence still needed

**Persistence proof in code (not production):** `TeacherHomeworkPdfHttpTest::testPdfSurvivesReleaseSwitchAndOldReleaseCleanupWithIdenticalBytesAndWrongRootNeverReadsSilently` creates a PDF and submission with the durable/quarantine roots in a simulated `shared/var/` outside `releases/` and `public/`, downloads it as the assigning teacher ("release 1"), boots a fresh container for "release 2", deletes the old release tree, and downloads again: size and SHA-256 equal the stored bytes both times. The same test then points the app at a wrong root: the download is a controlled `404`, the wrong root is **not** created by the `GET`, nothing falls back to another location, and the original object is untouched. `ReleaseDeployScriptTest` covers the deploy side (seven releases + prune, multi-release adoption, abort before activation on a byte conflict). #229's authorization checks and file-integrity read gate (`assertReadable`: size + SHA before streaming) are unchanged.

**Real migration of existing PDFs (not run this slice):** production has no homework PDF yet because upload is closed. If PDFs ever exist in a release directory, adopt them in an operator-run step: for each file record the owner/submission and DB row (`homework_submission_attachments.storage_key`, `byte_size`, `sha256`), copy (never move) to the shared root, verify size and SHA-256 against the DB row, only then let a later deploy prune the old release. The deploy script's copy-only adoption plus the abort on conflict is the safety net; it does not replace the DB cross-check.

**Rollback / compatibility limit:** the shared store is append-only for code. Switching back to an older release is safe only if that release reads the same configured root (`HOMEWORK_PDF_STORAGE_DIR`, kept in `shared/.env.local`). An older release without this setting uses its release-scoped default and will not see shared PDFs; it fails closed (`404`, readiness closed) and never deletes, overwrites or silently reads another root. Do not delete `shared/var/homework-pdf*` during rollback.

**Evidence still needed before the upload gate may open (each is separate; none was produced here):**

1. Live `clamd` reachable from the OLS/lsphp user over the Unix socket (permission proof), plus the real `StreamMaxLength`/`MaxFileSize` values and an INSTREAM near-limit transfer on the VDS.
2. Real OLS / lsphp request-body and `upload_max_filesize`/`post_max_size` limits against the 25 MiB application limit.
3. Live signature freshness on the VDS (freshclam schedule, `daily` age under the 72 h threshold).
4. `HOMEWORK_PDF_STORAGE_DIR` / `HOMEWORK_PDF_QUARANTINE_DIR` / `HOMEWORK_PDF_CLAMD_STREAM_MAX_BYTES` present in `shared/.env.local` and `isSafe*DirectoryReady()` true on the live release.
5. Quota, retention, and orphan-durable-object policy (none exists yet; the quarantine purge only covers quarantine). Unlimited storage is not assumed.
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
