# Stage 2.19 — Payment operations & reconciliation foundation

Domain, application, CLI ops, and persistence only.

**Not in this stage:** commercial payment SDKs, real credentials, card data,
cron installers, provider-side reverse settlement, license revocation.
HTTP admin surface for these ops is Stage 2.20 (`docs/architecture-admin-operations-panel.md`).

**In this stage:** due-webhook batch processing, controlled dead-letter requeue, provider
reconciliation adapters, append-only reconciliation runs/items, safe read models, and
CLI commands that emit aggregate-only output.

---

## 1. Goals

- Recover stuck / retryable webhook inbox rows without replaying raw provider bodies.
- Let a verified `SUPER_ADMIN` requeue `dead_letter → retry_pending` without resetting
  `attempt_count`.
- Observe provider vs local payment state and converge **only** via the existing verified
  webhook inbox + `PaymentWebhookProcessor` path.
- Persist an append-only reconciliation audit trail.
- Expose safe read models for a future ops UI (no controllers here).

---

## 2. Webhook due processing

`PaymentWebhookDueProcessor` + `app:payment:webhook:process-due`

Selects due ids via `PaymentWebhookInboxEventRepository::findDueEventIds()`:

- `received`
- `retry_pending` with `next_retry_at <= now`
- `processing` with `lease_expires_at <= now` (stale lease reclaim)

Order: `nextRetryAt ASC NULLS last`, then `receivedAt ASC`, then `id ASC`.
Limit: `1..500` (default `50`).

Each id is handed to `PaymentWebhookProcessor::process()` independently. Item failures are
isolated; the batch still exits `SUCCESS` unless options are invalid. `--dry-run` counts due
rows only (no claim). Audit action: `payment_webhook_batch_processed` (`Cli`).

CLI output is numeric summary only — no UUIDs, refs, hashes, or PII.

---

## 3. Dead-letter requeue

`PaymentWebhookDeadLetterRequeueService` + `app:payment:webhook:retry-dead-letter`

Requires `--confirm`, `--actor-id`, `--event-id`, `--reason-code`.

Same transaction:

1. Fresh-lock actor → `CommerceAuthorization::assertCanOperatePayments` (active+verified SA)
2. `PESSIMISTIC_WRITE` inbox row → `requeueFromDeadLetter()`
3. Audit `payment_webhook_dead_letter_requeued` (audit failure rolls back)

Migration `Version20260915120000` recreates `trg_pwie_bu_lifecycle` so
`dead_letter → retry_pending` is allowed while `attempt_count` stays monotonic.

---

## 4. Reconciliation provider seam

| Type | Role |
| --- | --- |
| `PaymentProviderReconciliationAdapterInterface` | Provider query contract |
| `PaymentReconciliationQuery` | Safe query inputs |
| `PaymentReconciliationLookupResult` | `found` / `missing` / `unsupported` / `ambiguous` / `failed` |
| `PaymentProviderTransactionSnapshot` | Safe snapshot (no card/PII/raw payload) |
| `PaymentReconciliationSnapshotHasher` | Canonical SHA-256 of safe fields |
| `SandboxPaymentProviderReconciliationAdapter` | In-memory/test adapter (`setSnapshot` / `clear`) |
| `UnsupportedPaymentProviderReconciliationAdapter` | Fail-closed stub |

`PaymentProviderRegistration` optionally carries a reconciliation adapter.
`PaymentProviderRegistry::findReconciliationAdapter()` returns `null` when missing; the
service treats that as unsupported.

Provider network/query calls run **outside** open DB transactions.

---

## 5. Decision matrix

| Observation | Outcome | Action |
| --- | --- | --- |
| Local and provider status + money + refs align | `matched` | `none` |
| Provider `captured`, local `initiated`/`authorized` | `local_behind` | `webhook_requeued` (verified Captured inbox event) |
| Local `captured`, provider behind | `provider_behind` | `manual_review_required` (no reverse) |
| Amount / currency / reference mismatch | matching `*_mismatch` | `manual_review_required` (no mutation) |
| Missing at provider | `missing_at_provider` | `none` (no auto-fail) |
| Unsupported / ambiguous / failed lookup | corresponding / `failed` | fail-closed `manual_review_required` |
| Refund totals mismatch | discrepancy + review | `manual_review_required` |

Rules:

- Never revoke licenses.
- Never reverse a local capture because the provider looks behind.
- Auto mutation is limited to enqueueing a deterministic reconciliation-sourced verified
  Captured webhook (`provider_event_reference = recon_<sha16>`, hashes = SHA-256 of
  canonical safe fields, `event_source=reconciliation`), then processing it through the
  normal inbox pipeline (idempotent on the unique inbox key).

---

## 6. Persistence

Migration `Version20260915120000` (forward-only; `down()` aborts):

- Recreates inbox lifecycle trigger for dead-letter requeue.
- Creates append-only `payment_reconciliation_runs` and `payment_reconciliation_items`
  (UUID `BINARY(16)`, CHECKs, indexes, `UNIQUE(run_id, payment_attempt_id)`, completion
  null-pairs, non-negative counters, sum consistency).
- Triggers: items no UPDATE/DELETE; runs terminal immutable; DELETE denied on runs.

Entities:

- `PaymentReconciliationRun::start` / `complete` (`SCHEMA_VERSION = 1`)
- `PaymentReconciliationItem::record` (immutable after insert)

Run terminal statuses: `completed`, `completed_with_discrepancies`, `failed`.

---

## 7. CLI reconciliation

`PaymentReconciliationService` + `app:payment:reconcile`

Options: `--provider`, `--environment`, `--attempt-id`, `--limit`, `--reason-code`,
`--dry-run`, `--confirm`, `--actor-id`.

Manual/mutating and dry-run both require an active+verified `SUPER_ADMIN` actor.
Mutating runs also require `--confirm`.

Aggregate CLI output only (`checked` / `matched` / `discrepancy` / `failed` / `run_status`).

---

## 8. Read models (no UI)

`PaymentOperationsReadModel` builds:

- `PaymentOperationsSummaryView`
- `PaymentAttemptOperationsView`
- `PaymentWebhookQueueSummaryView`
- `PaymentReconciliationRunView`
- `PaymentDiscrepancyView`

No raw body/hash/signature/email/card fields.

---

## 9. Authorization & audit

- Ops bar: `CommerceAuthorization::assertCanOperatePayments` → settle/catalog SA bar.
- New audit actions: batch processed, dead-letter requeued, reconciliation
  started/completed/discrepancy found/action applied.
- Sanitizer allowlist adds reconciliation aggregates (`reconciliation_run_id`,
  `reconciliation_outcome`, counter keys, `environment`, …). Prefer discrepancy audits over
  per-matched-item noise.

---

## 10. Known limitations

- No cron / messenger scheduler install in this stage.
- HTTP admin surface for these ops is Stage 2.20 (`docs/architecture-admin-operations-panel.md`).
- No production provider SDK — sandbox adapter only.
- Composite `(id, provider_code, environment)` FK from items is optional; Stage 2.19 uses
  simple FKs plus PHP provider/environment validation.
- Scheduled reconciliation mode exists on the enum/run model but is not driven by a worker yet.
