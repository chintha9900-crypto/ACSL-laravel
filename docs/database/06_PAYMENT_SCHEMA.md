# 06 — Payment Schema

Design only. Tables: `payment_bank_accounts`, `payments`, `payment_refunds`, `payment_webhooks`. One provider-independent payment ledger shared by Membership (manual bank transfer today) and E-commerce (future).

Legend: **N** = `NOT NULL`, **Y** = nullable. All FKs `ON UPDATE RESTRICT`.

## 1. Two status vocabularies — do not conflate (ADR-09)

| Vocabulary | Lives on | Values |
|---|---|---|
| Business workflow status | `memberships.payment_status` | `payment_not_required`, `payment_pending`, `payment_confirmation_submitted`, `payment_confirmed`, `payment_rejected` |
| Gateway/transaction status | `payments.status` | `pending`, `processing`, `paid`, `failed`, `cancelled`, `refunded`, `partially_refunded` |

They are synchronised by the application layer in the **same transaction**. A free membership has no `payments` row, so it can only be expressed in the first vocabulary. `payment_rejected` is a **transient** state: WORKFLOWS §0.9 says a rejection immediately returns the membership to `payment_pending`; the rejection is recorded as a `payment.rejected` history event and the M7 notification, not as a long-lived resting state.

Mapping for the manual bank-transfer gateway:

| Event | `payments.status` | `memberships.payment_status` |
|---|---|---|
| Approved, no promotion | `pending` | `payment_pending` |
| Applicant submits reference + evidence | `processing` | `payment_confirmation_submitted` |
| Admin confirms | `paid` (`paid_at` set) | `payment_confirmed` → activation |
| Admin rejects | `failed` (`failed_at` set) | `payment_rejected` → immediately `payment_pending` |
| Applicant resubmits (same `payments` row) | `failed → processing` | `payment_confirmation_submitted` |

`failed → processing` is a **manual-gateway-only** transition (C8 in `01`): for a real gateway `failed` is terminal and a retry is a new payment.

## 2. `payment_bank_accounts` — CONFIRMED (source of truth for bank/payment details)

Purpose: the approved ACI bank details shown in M5 and on the payment screen. Not folded into `site_settings`; not embedded in email copy.

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id` | BIGINT UNSIGNED AI | N | — | PK |
| `label` | VARCHAR(100) | N | — | Admin-facing name, e.g. "Membership fees — GBP" |
| `bank_name` | VARCHAR(150) | N | — | |
| `account_name` | VARCHAR(150) | N | — | |
| `account_number` | VARCHAR(50) | N | — | ACI's own account; published to payers, not a secret. Changes are audit-logged. |
| `branch` | VARCHAR(150) | Y | NULL | |
| `sort_code` | VARCHAR(20) | Y | NULL | where applicable (UK) |
| `iban` | VARCHAR(34) | Y | NULL | where applicable |
| `swift_bic` | VARCHAR(11) | Y | NULL | where applicable |
| `currency` | CHAR(3) | N | — | No default (OD-01) |
| `instructions` | TEXT | Y | NULL | Free-text payment instructions/reference format |
| `is_active` | TINYINT(1) | N | 1 | |
| `active_currency_key` | CHAR(3) | Y | generated | `VIRTUAL` = `IF(is_active = 1, currency, NULL)`; **UNIQUE** ⇒ one active account per currency, so "the bank details for a membership payment" is unambiguous |
| `created_by_user_id`, `updated_by_user_id` | BIGINT UNSIGNED | Y | NULL | FK → `users` RESTRICT |
| `created_at`, `updated_at` | TIMESTAMP | Y | NULL | |

Design notes: effective dates are **not** modelled (over-engineering); "effective from" = `is_active` flipped, with the change in `audit_logs`. **Immutability rule:** once a payment references an account (`payments.bank_account_id`) its bank fields are not edited — replace by creating a new row and deactivating the old one — so "which account were they told to pay into" stays true. Soft delete: **NO** (`is_active`). Indexes: `UNIQUE active_currency_key`; FK indexes on the user FKs.

## 3. `payments` — CONFIRMED

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id` | BIGINT UNSIGNED AI | N | — | PK |
| `public_id` | CHAR(26) `ascii_bin` | N | — | **UNIQUE** ULID for routes (evidence upload, admin review) |
| `user_id` | BIGINT UNSIGNED | Y | NULL | FK → `users`. The paying party where known. Nullable for an unauthenticated applicant (pre-account) and for a possible guest checkout (OD-18). |
| `membership_id` | BIGINT UNSIGNED | Y | NULL | FK → `memberships` — set for a membership payment |
| `order_id` | BIGINT UNSIGNED | Y | NULL | FK → `orders` — set for an e-commerce payment |
| `bank_account_id` | BIGINT UNSIGNED | Y | NULL | FK → `payment_bank_accounts` — the account the payer was instructed to use (manual gateway) |
| `gateway` | VARCHAR(30) | N | — | `manual_bank_transfer` today; `stripe`, … later. Open vocabulary (no CHECK) — gateways are added by config. |
| `transaction_reference` | VARCHAR(191) | Y | NULL | Applicant's bank reference (manual) or the provider's payment/charge id |
| `idempotency_key` | VARCHAR(100) | N | — | Unique per attempted payment operation |
| `amount` | DECIMAL(12,2) | N | — | `CHECK (amount > 0)` |
| `currency` | CHAR(3) | N | — | No default (OD-01) |
| `status` | VARCHAR(30) | N | `'pending'` | `CHECK IN ('pending','processing','paid','failed','cancelled','refunded','partially_refunded')` |
| `payment_url` | VARCHAR(2048) | Y | NULL | Gateway checkout URL where applicable; unused by manual transfer |
| `submitted_at` | TIMESTAMP | Y | NULL | When the applicant last submitted reference/evidence (manual) |
| `reviewed_by_user_id` | BIGINT UNSIGNED | Y | NULL | FK → `users` — admin who confirmed/rejected (latest decision; full trail in history/audit) |
| `reviewed_at` | TIMESTAMP | Y | NULL | |
| `rejection_reason` | VARCHAR(500) | Y | NULL | Latest rejection reason (shown to the applicant in M7) |
| `paid_at` | TIMESTAMP | Y | NULL | Set only when confirmed |
| `failed_at` | TIMESTAMP | Y | NULL | Set only when failed/rejected |
| `metadata` | JSON | Y | NULL | Provider-specific extras only (the one JSON column in this domain). Anything normalisable is a real column. Evidence files are `documents` rows, **not** metadata. |
| `created_at`, `updated_at` | TIMESTAMP | Y | NULL | |

### 3.1 Payment-purpose integrity: `membership_id` XOR `order_id`

MySQL cannot express a partial/conditional unique or a "one of two FKs" relationship natively, so the strategy is layered:

| Layer | Mechanism | Protects against |
|---|---|---|
| **Database (primary)** | `CHECK ((membership_id IS NULL) <> (order_id IS NULL))` named `payments_exactly_one_purpose` — exactly one purpose FK is set. Both FKs are `RESTRICT` (a CHECK column may not carry `CASCADE`/`SET NULL`). | a payment for both, or for neither, via any writer (app, tinker, SQL console, import) |
| **Database (typed FKs)** | each purpose is a real FK to its own parent table | a payment pointing at a non-existent membership/order |
| **Database (no £0)** | `CHECK (amount > 0)` | a fake £0 "free membership payment" |
| **Application** | payments are constructed **only** by `CreateMembershipPayment` and `CreateOrderPayment` actions, each setting exactly one FK; a `saving` guard in the model rejects violations with a clear error | clear error messages; defence in depth if CHECK is not enforced (MySQL < 8.0.16, OD-08) |
| **Reconciliation** | scheduled/test query: `SELECT id FROM payments WHERE (membership_id IS NULL) = (order_id IS NULL)` must return 0 rows | silent loss of CHECK enforcement after an engine change |

There is deliberately **no polymorphic `payable_type/payable_id`**: a polymorphic pair cannot have FKs, and the domain has exactly two purposes.

Additional cross-table rules (application layer, `15`): a membership payment's `amount`/`currency` equal the membership's `fee_amount`/`fee_currency`; an order payment's equal `orders.total_amount`/`currency`; the sum of processed refunds never exceeds `amount`.

**Foreign keys**

| FK column | Parent | Cardinality | ON DELETE |
|---|---|---|---|
| `user_id` | `users` | many payments : 0..1 user | RESTRICT |
| `membership_id` | `memberships` | many : 0..1 (normally 1 payment, possibly more after cancel/refund) | RESTRICT |
| `order_id` | `orders` | many : 0..1 (normally 1) | RESTRICT |
| `bank_account_id` | `payment_bank_accounts` | many : 0..1 | RESTRICT |
| `reviewed_by_user_id` | `users` | many : 0..1 | RESTRICT |

**Indexes**

| Index | Columns | Reason |
|---|---|---|
| `payments_public_id_unique` | `public_id` | routes |
| `payments_gateway_idempotency_key_unique` | `gateway, idempotency_key` | **idempotency**: a retried "create payment" or double-submit cannot create a second row |
| `payments_gateway_transaction_reference_index` | `gateway, transaction_reference` | webhook → payment lookup; **not unique** because a manual reference is applicant-typed free text (uniqueness would reject legitimate collisions and create a probing oracle). Provider-issued ids are protected by webhook idempotency instead. |
| `payments_membership_id_status_index` | `membership_id, status` | membership payment lookup |
| `payments_order_id_status_index` | `order_id, status` | order payment lookup |
| `payments_status_submitted_at_index` | `status, submitted_at` | admin "evidence awaiting review" queue |
| FK indexes | `user_id`, `bank_account_id`, `reviewed_by_user_id` | |

Soft delete: **NO** — financial records are never deleted or hidden. Audit: `payment.created`, `payment.evidence_submitted`, `payment.confirmed`, `payment.rejected`, `payment.refunded` in `audit_logs`; membership-facing events in `membership_status_history`.

"At most one open payment per membership/order" is an application rule with a reconciliation query, not a unique key — a manual payment cycles `failed → processing` on the same row, and a gateway retry legitimately creates a new row, so a status-conditioned unique key cannot express both cleanly.

## 4. `payment_refunds` — CONFIRMED (concept)

One row per refund attempt. Reused for order refunds (`payments.order_id`) — there is no separate commerce refund table (`09_ECOMMERCE_ARCHITECTURE.md` §1).

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id` | BIGINT UNSIGNED AI | N | — | PK |
| `payment_id` | BIGINT UNSIGNED | N | — | FK → `payments` RESTRICT |
| `amount` | DECIMAL(12,2) | N | — | `CHECK (amount > 0)`; may be partial |
| `currency` | CHAR(3) | N | — | Snapshot; must equal `payments.currency` (application rule) |
| `reason` | VARCHAR(500) | N | — | |
| `status` | VARCHAR(20) | N | `'pending'` | `pending`, `processed`, `failed` (enum only; provisional) |
| `gateway_reference` | VARCHAR(191) | Y | NULL | Provider refund id, or a manual bank reference |
| `idempotency_key` | VARCHAR(100) | N | — | **UNIQUE** — a double-clicked "Refund" cannot create two refunds |
| `requested_by_user_id` | BIGINT UNSIGNED | N | — | FK → `users` RESTRICT — the authorising admin |
| `processed_at` | TIMESTAMP | Y | NULL | |
| `created_at`, `updated_at` | TIMESTAMP | Y | NULL | |

Indexes: `UNIQUE(idempotency_key)`; `UNIQUE(payment_id, gateway_reference)` (provider refund id uniqueness per payment; `NULL`s allowed); `(payment_id, status)`. FKs: `payment_id` (many : 1, RESTRICT), `requested_by_user_id` (RESTRICT). Soft delete: NO.

Refund arithmetic — "sum of `processed` refunds ≤ `payments.amount`" — is enforced in the application under `SELECT … FOR UPDATE` on the **payment row** (a cross-row aggregate cannot be a CHECK). After a refund the payment moves to `partially_refunded` or `refunded`. The effect of a refund on a **membership** (revocation? proration?) is not defined (OD-13); the table is generic and does not decide it.

## 5. `payment_webhooks` — CONFIRMED (idempotent processing ledger)

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id` | BIGINT UNSIGNED AI | N | — | PK |
| `gateway` | VARCHAR(30) | N | — | |
| `event_id` | VARCHAR(191) | N | — | The provider's event id |
| `event_type` | VARCHAR(100) | N | — | |
| `signature_state` | VARCHAR(20) | N | `'verified'` | `verified` (real gateways), `not_applicable` (manual/no-signature source). See §5.2 for why `failed` is not stored here. |
| `payload` | JSON | N | — | Raw provider payload, for audit/replay. May contain payer PII → retention (OD-11). |
| `processing_status` | VARCHAR(20) | N | `'received'` | `received`, `processing`, `processed`, `failed`, `ignored` |
| `attempts` | SMALLINT UNSIGNED | N | 0 | Processing attempts |
| `error_message` | TEXT | Y | NULL | Sanitised failure information (no secrets, no full payload) |
| `payment_id` | BIGINT UNSIGNED | Y | NULL | FK → `payments` RESTRICT — set once the event is matched to a payment |
| `received_at` | TIMESTAMP | N | CURRENT_TIMESTAMP | |
| `processed_at` | TIMESTAMP | Y | NULL | |
| `created_at`, `updated_at` | TIMESTAMP | Y | NULL | |

Indexes: `UNIQUE payment_webhooks_gateway_event_id_unique (gateway, event_id)` — **the idempotency key**; `(processing_status, received_at)` (stuck-event sweeper); `payment_id` FK index. `CHECK (processing_status IN ('received','processing','processed','failed','ignored'))`. Soft delete: NO (pruned by retention once decided, OD-11).

### 5.1 How duplicate delivery cannot cause duplicate business actions

1. **Insert-once:** the endpoint verifies the signature, then `INSERT`s `(gateway, event_id, …)`. A redelivery violates `UNIQUE(gateway, event_id)` → treated as "already seen" → respond `200` so the provider stops retrying → **no job dispatched, no state change**.
2. **Claim-once:** the processing job claims the row with a compare-and-set: `UPDATE payment_webhooks SET processing_status='processing', attempts=attempts+1 WHERE id=? AND processing_status IN ('received','failed')`. `0 rows affected` ⇒ another worker owns it or it is done → exit.
3. **Transition-once:** the business change is itself a guarded update: `UPDATE payments SET status='paid', paid_at=UTC_TIMESTAMP() WHERE id=? AND status IN ('pending','processing')`. `0 rows affected` ⇒ already paid → exit without firing `PaymentConfirmed`. The domain event fires **only** when exactly one row changed.
4. **Same transaction:** the payment update, the membership/order update, and `processing_status='processed'` commit together. A crash leaves the row `processing`/`received` and the sweeper re-dispatches it; steps 2–3 make the retry harmless.
5. **Out-of-order events** (e.g. `refund` before `paid`) are stored, marked `ignored` or retried by application logic — the ledger never blocks on ordering.

### 5.2 Why a failed-signature request is not stored here

If unverified requests were inserted, an attacker could pre-register a **fake `event_id`** and cause the *real* provider event with that id to be discarded as a duplicate. So only events that passed signature verification (or come from a source with no signature concept) enter this table; rejected requests are written to the application log and `audit_logs` (`payment.webhook_rejected`) with rate limiting. The `signature_state` column therefore records `verified`/`not_applicable` and exists so a future gateway with a "signature checked later" model has somewhere to express it without a migration.

## 6. Bank details and historical accuracy

Emails (M5) read the active bank account **at send time** and `payments.bank_account_id` records which one the payer was given, so a later change of bank details never rewrites history. Snapshotting the *amount* is done twice on purpose: `memberships.fee_amount` (what the term cost) and `payments.amount` (what was requested) — a payment is a legal record of an amount at a point in time.
