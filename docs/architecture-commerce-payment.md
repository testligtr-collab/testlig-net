# Stage 2.17 — Commerce payment / subscription / fulfillment foundation

Domain, application, security and persistence only.

**Not in this stage:** no payment provider SDK (no iyzico, PayTR, Stripe or similar), no
checkout UI, no REST endpoints, no webhook controllers, no card data anywhere in the model,
no dunning/retry scheduler, no invoice/e-archive document generation, no multi-currency
pricing beyond the single-currency-per-order rule.

**In this stage:** priced catalog offers, orders with frozen snapshots, provider-neutral
payment attempts and event chains, subscriptions with explicit periods, refunds, and the
single transaction that turns a captured payment into exactly one `AccessLicense`.

---

## 1. Money

`App\Money\Money` is the only money type in commerce code.

- `amountMinor` is an `int` in minor units (kuruş for `TRY`). There is no float anywhere in
  the money path — not in the VO, not in columns, not in hashes.
- `currency` is a 3-letter uppercase ISO 4217 code. `TRY` is the only currency Stage 2.17
  offers are created with, but the VO and columns are currency-agnostic.
- `add` / `subtract` / `compare` / `equals` require the same currency and throw
  `MoneyException::currencyMismatch()` otherwise. Results may not go negative
  (`MoneyException::negativeAmount()`), and every arithmetic step is overflow-checked
  against `PHP_INT_MAX` (`MoneyException::overflow()`).
- `multiply(int)` and `percentageOfBasisPoints(int)` are the only scaling operations, both
  integer-only with deterministic **half-up** rounding to the minor unit.
- `MoneyFailureReason` gives callers a machine-readable reason code instead of string
  matching on messages.

## 2. Tax and rounding policy — tax **exclusive**

Offer prices are stored **net (tax exclusive)** together with a `taxRateBasisPoints`
snapshot. This was chosen over tax-inclusive pricing because:

- the KDV rate is a property of the sale, so freezing it per order line keeps historical
  orders reproducible after a catalog rate change;
- deriving tax from a net price is exact, whereas extracting tax from a gross price needs a
  division and loses a kuruş on many rates.

`App\Commerce\CommerceMoneyPolicy` is the single implementation:

```
lineSubtotal = unitPrice * quantity                  (net)
lineTax      = halfUp(lineSubtotal * basisPoints / 10000)
lineTotal    = lineSubtotal + lineTax
subtotal     = Σ lineSubtotal
tax          = Σ lineTax
grandTotal   = subtotal - discount + tax
```

- Rounding happens **per line**, so recomputing an order from its persisted items always
  reproduces the stored totals — this is asserted on every settlement and fulfillment.
- `discount` defaults to `0` in Stage 2.17 (no coupon engine yet) and may never exceed the
  subtotal. Discount applies to the net subtotal, before tax.
- `taxRateBasisPoints` is `0..10000`; `quantity` is `1..10` (`MAX_QUANTITY`).
- Unit tax is computed for display/receipt purposes only and never feeds the totals.

## 3. Entities

| Entity | Purpose | Key invariants |
| --- | --- | --- |
| `CommercialOffer` | priced, sellable wrapper around one `AccessPackageVersion` | immutable snake_case `code`; draft → active → retired; price/currency/tax/target/billing immutable once active; `offerHash` |
| `CommerceOrder` | purchase intent with frozen totals | random `publicReference`; null-pair purchaser; draft → awaiting_payment → paid/cancelled/expired; `orderHash` |
| `CommerceOrderItem` | one purchased offer line | `UNIQUE(order, offer)`; append-only while order is draft; carries offer + package-policy snapshot hashes |
| `PaymentAttempt` | one provider-neutral charge attempt | `UNIQUE(order, attempt_number)`, `UNIQUE(idempotency_key_hash)`, `UNIQUE(provider_code, provider_payment_reference)`; never deletable |
| `PaymentEvent` | append-only settlement ledger | hash chain via `previousEventHash`; UPDATE/DELETE denied by trigger; sanitized metadata only |
| `CommerceSubscription` | recurring entitlement periods | null-pair subscriber; explicit `currentPeriodStart/End`; `cancelAtPeriodEnd`; `subscriptionHash` |
| `CommerceFulfillment` | the bridge to entitlement | links order/item/attempt/(subscription)/license; one `completed` row per order item or per subscription period |
| `PaymentRefund` | full or partial money return | `UNIQUE(attempt, refund_number)`; sum of succeeded refunds ≤ captured amount |

All primary keys are UUID v7 binary(16), all timestamps are UTC, and every entity carries a
`schemaVersion` where a hash is stored.

**`publicReference`** is `ORD-` plus 24 hex characters from `random_bytes()`. It is
deliberately unguessable and not sequential: it is the only order identifier safe to show a
buyer or send to a provider, so it must leak neither order volume nor ordering.

**Null-pair purchaser/subscriber** follows the Stage 2.16 licensee pattern: exactly one of
`user_id` / `institution_id` is set, enforced by a CHECK constraint plus entity guards.

## 4. Hashing

`CommerceIdempotencyKeyHasher` turns a caller-supplied idempotency key into a 64-character
lowercase hex HMAC-SHA256 digest, scoped by operation (`order:`, `attempt:`, `event:`,
`refund:`, `fulfillment:` …) so the same key cannot be replayed across operations.

- Key comes from `COMMERCE_IDEMPOTENCY_HASH_KEY`, minimum 32 bytes.
- **No `APP_SECRET` fallback.** A missing, short, or placeholder key throws at construction,
  and `prod` additionally rejects the known dev/test/CI placeholder values.
- **Raw idempotency keys are never persisted or audited** — only the digest.

`CommercialOfferHasher`, `CommerceOrderHasher`, `PaymentEventHasher` and
`CommerceSubscriptionHasher` follow the `AccessPackagePolicyHasher` pattern: canonical JSON
built through the `QuestionContentCanonicalEncoder` conventions (sorted keys, integers as
integers, UTC instants normalised by `CommerceCanonicalInstant`), SHA-256, and verification
via `hash_equals()`. No PII, no provider secrets, no free text beyond snapshotted
descriptions.

`PaymentEventHasher` additionally chains events: each event hash covers the previous event
hash, so removing or rewriting a middle event breaks every later link. The chain is verified
before capture is trusted.

## 5. Payment lifecycle (provider-neutral)

```
PaymentAttemptManager::start              order draft → awaiting_payment, attempt initiated
PaymentCheckoutOrchestrator::checkout     attempt → provider authorize (outside DB TX) → settlement
PaymentSettlementManager::authorize       attempt initiated → authorized   + PaymentEvent
PaymentSettlementManager::capture         attempt authorized/initiated → captured + PaymentEvent
PaymentSettlementManager::fail            attempt → failed  + PaymentEvent
PaymentSettlementManager::cancel          attempt → cancelled + PaymentEvent
PaymentWebhookIngress + Processor         verified webhook → inbox → settlement/refund/fulfillment
```

- `PaymentProviderAdapterInterface` is the only provider charge/refund surface.
  Stage 2.18 registers a **sandbox** adapter (`SandboxPaymentProviderAdapter`) for
  dev/test only — no commercial SDK (iyzico/PayTR/Stripe/…) may appear in executable
  `src/` code. `PaymentProviderRegistry` resolves adapter + webhook verifier + parser by
  provider code; unknown/disabled providers fail closed.
- Checkout never accepts client prices: amount/currency/publicReference come from the
  sealed order. Raw idempotency keys are never persisted (HMAC digest only). Provider
  network calls run **outside** open DB transactions. Ambiguous/timeout outcomes leave
  the attempt fail-closed (not marked failed) for webhook/reconciliation.
- Webhook route: exact `POST /webhook/odeme/{providerCode}` (stateless firewall, no CSRF).
  Signature verification uses `hash_equals` over the raw body; timestamp replay window
  applies. Verified events land in append-only `PaymentWebhookInboxEvent` (payload hash +
  signature fingerprint only — never raw body/signature/secrets/card data).
- Inbox lifecycle: `received → processing → processed|rejected|failed` (terminal states
  never roll back). Duplicate `(provider, environment, event_ref)` is UNIQUE; same ref +
  different payload hash is an integrity conflict. Out-of-order: late authorize after
  capture is a no-op; capture after terminal failure is rejected.
- Settlement actor for provider-driven mutations is the platform SUPER_ADMIN
  (`PAYMENT_PLATFORM_SETTLEMENT_ACTOR_ID` / test override), never the buyer.
- Provider metadata passes through `PaymentEventMetadataSanitizer` before storage: an
  allowlist of scalar keys, bounded sizes, no nested payloads, no PII.

### Lock order (Stage 2.18)

Institution → purchaser/actor → CommerceOrder → PaymentAttempt → WebhookInboxEvent →
PaymentEvent/Refund → Fulfillment/AccessLicense → Audit.

### Webhook denial audit policy

High-volume signature/provider denials record a sparse system audit
(`payment_webhook_rejected`) with only `provider_code` + `reason_code` — never body,
signature, headers, or PII — to avoid log amplification.

## 6. Fulfillment — captured payment becomes exactly one license

`CommerceFulfillmentManager::fulfill()` runs in a single transaction:

1. lock the order (`PESSIMISTIC_WRITE`) and reload the actor and purchaser fresh;
2. authorize the settlement actor (`CommerceAuthorization::assertCanSettlePayments`);
3. verify the attempt belongs to the order, is `captured`, matches order currency and
   grand total, and has a verified capture `PaymentEvent` in an intact hash chain;
4. recompute order totals from the items and `hash_equals()` the `orderHash` (covers
   purchaser scope, currency, order totals, and each line's offer/package/version IDs,
   quantity, unit price, tax rate, line money breakdown, and both snapshot hashes —
   see `CommerceOrderItem::toHashPayload()`);
5. re-verify each line against the live catalog:
   - `CommercialOfferManager::assertOfferIntegrity()` recomputes the offer hash from
     fresh offer fields + package/version identity (never stored↔stored alone);
   - currency / price / tax / billing snapshots must still match the live offer;
   - package policy is recomputed from a **fresh DBAL grant graph** via
     `EntitlementAuthorizationProjector` + `AccessPackagePolicyHasher`, then
     `hash_equals()` across fresh graph ↔ `version.policyHash` ↔
     order-item `packagePolicySnapshotHash` (and again against the minted license
     `policySnapshotHash` after grant);
   - target type versus purchaser type and package/version identity chain;
6. create the `CommerceFulfillment` (pending), then `AccessLicenseManager::createUserLicense`
   / `createInstitutionLicense` with `AccessLicenseSourceType::Purchase` followed by
   `activate()`;
7. mark the fulfillment `completed`, the order `paid`, record audits, commit.

**Retired offers after checkout:** once an order is sealed, retiring the commercial offer
does **not** block fulfillment of that order. Purchasability is enforced at order creation;
fulfillment trusts the frozen item snapshots plus the live grant-graph digest, not
`CommercialOfferStatus::Active`.

Replaying the same idempotency key returns the **same** fulfillment and the same license.
The database also guards it: a partial unique index allows only one `completed` fulfillment
per order item (or per subscription period), so even a concurrent duplicate loses at the
constraint rather than minting a second license.

### Validity window

- **One-time offers:** `validFrom = capturedAt`,
  `validUntil = capturedAt + (version.validityDays ?? package.defaultValidityDays)` days,
  exclusive upper bound, matching Stage 2.16 license semantics.
- If both are `NULL`, fulfillment **throws** `CommerceException::validityPolicyMissing()`.
  A commercial package must state how long the buyer keeps access; an open-ended `NULL`
  license is never created from a purchase. Catalog operators therefore have to set
  `validityDays` on the version (or a package default) before an offer can be fulfilled.

### Recurring offers — period-scoped licenses

The chosen model is **one license per subscription period**, not a mutated/extended license:

- `CommerceSubscriptionManager::ensureForCapturedItem()` creates or advances the
  subscription, whose `currentPeriodStart/End` come from
  `CommercialOfferBillingInterval::advance()` (calendar-safe monthly/yearly arithmetic).
- the license window is exactly the subscription period, and its `externalReference` is
  `<publicReference>:oi:<orderItemId>:p:<periodKey>` where `periodKey` is the canonical
  UTC period start;
- a unique guard on `(subscription, period_key)` for completed fulfillments makes renewal
  fulfillment idempotent per period.

Rationale: extending an existing license would rewrite history and make "what did this
buyer have access to in March" unanswerable, and `AccessLicense` treats its validity window
as effectively immutable after activation. Period-scoped licenses keep the audit trail and
let a lapsed renewal expire naturally without a revoke command.

## 7. Refunds and reversal

- `PaymentRefundManager::request()` / `markSucceeded()` / `markFailed()` record
  `PaymentRefund` rows against a captured attempt. Cumulative succeeded refunds may not
  exceed the captured amount — enforced in the manager and by a DB trigger.
- **A partial refund never touches the license.** Access is unchanged.
- **A full refund does not auto-revoke either.** Money and entitlement are separate
  decisions; a full refund plus continued access is a legitimate goodwill outcome.
- Revocation is always explicit: `CommerceFulfillmentManager::reverse()` marks the
  fulfillment `reversed` and revokes the license through `AccessLicenseManager::revoke()` in
  the **same transaction**, with an audit on both sides. Reversal requires a settled
  fulfillment and is itself idempotent.

## 8. Authorization

`App\Security\CommerceAuthorization`, mirroring `AccessPackageAuthorization`:

| Action | Allowed |
| --- | --- |
| Offer create/update/activate/retire | active + verified `SUPER_ADMIN` only |
| Individual purchase / payment start | the buyer themselves, active + verified |
| Institution purchase / payment start | institution **Owner** with an active membership in an **active** institution |
| Settlement, fulfillment, refund, reversal | active + verified `SUPER_ADMIN` (platform settlement operator) |

- **No proxy purchase.** `ADMIN`, `MODERATOR` and `SUPER_ADMIN` cannot start a payment on
  behalf of an individual user: a purchase creates a paid entitlement for a real person, so
  there is no silent ownership bypass. `SUPER_ADMIN` manages the catalog, not other people's
  wallets.
- **Managers stay seat-only.** The Stage 2.16 rule holds: institution `Manager` assigns
  seats but cannot spend institution money. Teacher, staff and student are denied outright,
  and a global `ROLE_INSTITUTION_MANAGER` alone never grants tenant access.
- Authorization is re-proved from freshly loaded rows on every mutation
  (`CommerceFreshEntityLoader`, `HINT_REFRESH`), never from a possibly stale in-memory
  association. `CommerceStaleAuthorizationTest` covers suspension, email de-verification,
  owner demotion and institution suspension between order creation and payment.

## 9. Lock order

Always acquire in this order; never reverse:

```
Institution → Purchaser/Membership → CommercialOffer → AccessPackage → AccessPackageVersion
→ CommerceOrder → CommerceOrderItem → CommerceSubscription → PaymentAttempt
→ PaymentEvent / PaymentRefund → CommerceFulfillment → AccessLicense → Audit
```

This extends the Stage 2.16 chain (`Institution → License → User/Membership → Seat → Audit`)
at its head, so a commerce transaction that ends in a license grant never deadlocks against
a seat assignment.

## 10. Persistence and database guards

Migration `Version20260913120000` (after `Version20260912170000`) creates all eight tables
with CHECK constraints, unique/partial indexes and 19 triggers. It aborts on preflight if any
commerce table already exists, and `down()` is intentionally **irreversible** — a payment
ledger is not something a rollback should silently drop. There is no `@testlig` bypass and
no `FOREIGN_KEY_CHECKS` toggling.

Trigger families:

- **Identity immutability** — `trg_co_bu_immutable`, `trg_coi_bu_immutable`: offer code /
  package pair / currency and order-item snapshots cannot be rewritten.
- **Lifecycle** — `trg_cord_bu_lifecycle`, `trg_pa_bu_lifecycle`, `trg_cs_bu_lifecycle`,
  `trg_cf_bu_lifecycle`, `trg_pr_bu_lifecycle`: only legal status transitions, and status
  checks fire only when the status actually changes so unrelated column updates stay legal.
- **Append-only ledger** — `trg_pe_bu_append_only`, `trg_pe_bd_append_only`,
  `trg_pa_bd_deny`, `trg_cf_bd_deny`, `trg_pr_bd_deny`: payment history cannot be edited or
  deleted, only appended.
- **Chain and state preconditions** — `trg_pe_bi_chain` (sequence + previous-hash linkage),
  `trg_pa_bi_order_state` (attempts only on payable orders), `trg_cf_bi_captured`
  (fulfillment only from a captured attempt), `trg_cs_bi_offer_recurring` (subscriptions
  only from recurring offers), `trg_coi_bi_draft_only` / `trg_coi_bd_draft_only` (items only
  while the order is draft).
- **Money cap** — `trg_pr_bi_cap`: refunds may not exceed the captured amount.

`CommerceCompositeForeignKeyListener` adds the composite FKs Doctrine cannot express
(offer → version+package, order_item → order+offer, fulfillment → order+item+attempt and
→ license, subscription → offer+version), and `CommerceSchemaListener` applies the partial
unique guards. Both run on `postGenerateSchema`, so `doctrine:schema:validate` stays clean.

## 11. Audit

`SecurityAuditAction` gains 25 commerce actions (`commercial_offer_*`, `commerce_order_*`,
`payment_*`, `commerce_subscription_*`, `commerce_fulfillment_*`). Mutation audits are
mandatory and recorded in the same transaction with `flush: false`, matching Stage 2.16.

`SecurityAuditMetadataSanitizer::ALLOWED_KEYS` gains commerce identifiers, integer minor-unit
amounts, digests and enum values only. The sanitizer **refuses** (throws
`SecurityAuditMetadataException`) rather than silently dropping unknown keys, so
`idempotency_key`, `idempotency_key_hash`, `card_number`, `cvv` or `email` in commerce
metadata is a hard failure in tests and in production. Idempotency HMAC digests stay on
access-controlled commerce tables (`payment_attempts`, `payment_events`, `payment_refunds`,
`commerce_fulfillments`) and are never copied into audit metadata.

## 12. Environment

```
COMMERCE_IDEMPOTENCY_HASH_KEY=<min 32 bytes, unique per environment>
```

Wired in `.env`, `.env.example`, `.env.test`, `phpunit.dist.xml`, `compose.yaml`,
`config/services.yaml` (`env()` default) and `.github/workflows/ci.yml`. Dev/test/CI values
are obvious non-secrets; `prod` refuses them. Generate with:

```bash
php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"
```

Never reuse `APP_SECRET`, `AUDIT_HASH_KEY`, `QUESTION_ANSWER_INTEGRITY_KEY` or
`ATTEMPT_ANSWER_ENCRYPTION_KEY` for it.

## 13. Order expiry

Orders get a TTL (`DEFAULT_TTL_SECONDS = 3600`, max 7 days, min 60 seconds). Expiry is lazy
and evaluated on mutation paths: `CommerceOrderManager::evaluateAndExpireIfNeeded()` runs
before a payment attempt opens its transaction, so the expiry is committed even when the
subsequent attempt is rejected. There is no cron/worker in this stage.

## 14. Known limitations

- Single-database pessimistic locking only; no distributed lock harness.
- No automatic renewal driver — `CommerceSubscriptionManager::renew()` exists, but nothing
  schedules it yet. Renewal is triggered by whatever settles the next period's payment.
- No dunning, proration, plan change, or coupon/discount engine (discount is always 0).
- One currency per order; no FX conversion.
- No invoice document, receipt PDF, or tax reporting export.
- `PaymentProviderAdapterInterface` has no production implementation, so nothing charges
  real money yet. Wiring a provider is Stage 2.18+ work and must keep the settlement path
  (event chain → capture verification → fulfillment) untouched.
- Concurrent refund races are bounded by the payment-attempt `PESSIMISTIC_WRITE` lock and
  the `uniq_pr_idempotency_key_hash` / cumulative-refund trigger checks. There is **no**
  separate DB-level SERIALIZABLE isolation claim beyond those mechanisms.

## 15. UTC

All instants are UTC via `ClockInterface` / `UtcInstant::ensure()`, and canonical hashing
normalises them through `CommerceCanonicalInstant`. Tests use `MockClock`; there is no
`sleep()` in the commerce suite.
