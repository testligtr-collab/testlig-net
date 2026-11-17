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
| Fake/transport tests (symlink reject, partial-write cleanup) | Real clamd install, ops workflows, Bunny/R2 |

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

## Next integration contract (not implemented here)

1. Gate assert  
2. Inspect bytes → quarantine stage (locked handle + SHA)  
3. Scan same handle → only `clean` continues  
4. Re-verify bytes when copying/renaming to **durable** private key  
5. DB commit (revision)  
6. On DB/write failure: delete durable candidate; **keep existing draft attachment**  
7. On success replace: delete previous durable draft object  

Still open: live scanner/freshness/StreamMaxLength acceptance, quota/retention/orphan cleanup. Unlimited storage is not assumed.

## Clean verdict wording

A `clean` reply means clamd returned the exact OK line for the bytes streamed from the locked quarantine handle under current client policy. It does **not** guarantee the file is harmless, and it does **not** certify a later durable object without re-verification.
