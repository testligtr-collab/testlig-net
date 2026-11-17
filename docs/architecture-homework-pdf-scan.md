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
| Fake/transport tests | Real clamd install, ops workflows, Bunny/R2 |

Default binding: `ClosedHomeworkPdfScanReadiness` (never ready) + `HOMEWORK_PDF_UPLOAD_ENABLED=0`. Setting enabled alone does **not** open upload.

## Next integration contract (not implemented here)

1. Gate assert  
2. Inspect bytes → quarantine stage (same SHA)  
3. Scan quarantine → only `clean` continues  
4. Copy/rename to **durable** private key  
5. DB commit (revision)  
6. On DB/write failure: delete durable candidate; **keep existing draft attachment**  
7. On success replace: delete previous durable draft object  

Retention/quota/orphan cleanup and live scanner acceptance remain open. Unlimited storage is not assumed.

## Clean verdict wording

A `clean` reply means clamd returned the exact OK line for the streamed bytes under current client policy. It does **not** guarantee the file is harmless.
