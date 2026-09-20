# 04 — Membership Schema

Design only. Tables: `membership_categories`, `membership_plans`, `membership_settings`, `membership_applications`, `membership_details_requests`, `memberships`, `membership_number_sequences`, `membership_status_history`. Promotions are in `05_PROMOTION_SCHEMA.md`; payments in `06_PAYMENT_SCHEMA.md`; aviation-proof files in `07_DOCUMENT_SCHEMA.md`.

Legend: **N** = `NOT NULL`, **Y** = nullable. All FKs are `ON UPDATE RESTRICT` (`01` §3.4).

## 1. Lifecycle model (what the tables represent)

```
Applicant (no account yet)
   │ submit
   ▼
membership_applications  status: submitted ⇄ more_details_required → approved | rejected
   │                                     (membership_details_requests holds each request/response)
   │ approved
   ▼
memberships  (created at approval)  status: pending_activation → active → expired
   │           payment_status: payment_not_required | payment_pending →
   │                           payment_confirmation_submitted ⇄ (payment_rejected → payment_pending) → payment_confirmed
   │ activation (free path immediately; paid path after payment_confirmed)
   ▼
membership number issued (membership_number_sequences, row lock) · starts_on/expires_on · promotion snapshot
   │
   ├── users row provisioned (pending_setup) + account_setup_tokens
   └── membership_status_history rows + audit_logs rows
```

Application and membership are **separate lifecycles**. An application can exist forever without a membership (rejected, more-details, awaiting review). A membership always originates from exactly one application. A returning member's renewal is a **new application → new membership row**, linked by `renews_membership_id` (`OD-04`).

## 2. `membership_categories` — CONFIRMED (exactly three)

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id` | BIGINT UNSIGNED AI | N | — | PK |
| `code` | CHAR(1) `ascii_bin` | N | — | **UNIQUE**. `CHECK (code IN ('S','P','V'))`. Immutable — it is embedded in issued membership numbers. |
| `name` | VARCHAR(100) | N | — | Display name (working: Student/Professional/Veteran; final commercial names TBC, OD-02) |
| `description` | TEXT | Y | NULL | |
| `is_active` | TINYINT(1) | N | 1 | Whether the category accepts new applications |
| `display_order` | SMALLINT UNSIGNED | N | 0 | |
| `created_at`, `updated_at` | TIMESTAMP | Y | NULL | |

"Exactly three categories" is enforced by `UNIQUE(code)` + `CHECK code IN ('S','P','V')` — a fourth row cannot exist. Rows are never deleted (referenced by RESTRICT FKs). Soft delete: **NO**. Seeded in the implementation phase.

## 3. `membership_plans` — commercial configuration (REWORK of legacy `membership_plans`)

Purpose: the fee, currency and standard duration a category currently sells for. Separate from the category so pricing can change **without** rewriting history (the membership snapshots the terms it was sold under).

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id` | BIGINT UNSIGNED AI | N | — | PK |
| `membership_category_id` | BIGINT UNSIGNED | N | — | FK |
| `name` | VARCHAR(100) | N | — | e.g. "Student — annual" |
| `description` | TEXT | Y | NULL | |
| `fee_amount` | DECIMAL(12,2) | N | — | `CHECK (fee_amount > 0)`. A "free" membership is never a zero-fee plan; free comes **only** from a promotion (no fake £0 payment can ever be generated). |
| `currency` | CHAR(3) | N | — | No default (OD-01) |
| `duration_months` | SMALLINT UNSIGNED | N | — | `CHECK (duration_months > 0)` |
| `is_active` | TINYINT(1) | N | 1 | |
| `active_category_key` | BIGINT UNSIGNED | Y | generated | `VIRTUAL` = `IF(is_active = 1, membership_category_id, NULL)`; **UNIQUE** ⇒ at most one active plan per category, so "the fee for this category" is never ambiguous. |
| `created_at`, `updated_at` | TIMESTAMP | Y | NULL | |

**Immutability rule (application layer):** once any membership references a plan, its `fee_amount`, `currency`, `duration_months` are not edited. A price change = create a new plan, deactivate the old one. (Audit-logged.) This is what makes historical pricing reconstructable.

| FK | Parent | Cardinality | ON DELETE |
|---|---|---|---|
| `membership_category_id` | `membership_categories` | many plans : 1 category (one active) | RESTRICT |

Indexes: PK; `UNIQUE active_category_key`; FK index on `membership_category_id`. Soft delete: **NO** (`is_active`). Legacy `membership_benefits`: **DEFER** — the benefits page is currently static Blade (`FEATURES.md` §A); no table until ACI decides benefits are admin-managed. The legacy 4-plan seed (Free/Student Premium/Professional Premium/School Club) is **not** carried forward; it does not map to three categories.

> Open dependency: `FEATURES.md` notes the Professional category had three tier labels in the legacy app. If ACI wants sold tiers *inside* Professional, `active_category_key` would have to be relaxed to a per-tier key. Confirmed rule today: exactly three categories, one plan each (OD-02).

## 4. `membership_settings` — singleton (REWORK; resolves "cooldown is admin-configurable")

Typed singleton for membership-workflow tunables. **Not** a key/value table (no EAV).

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id` | TINYINT UNSIGNED | N | 1 | PK, `CHECK (id = 1)` |
| `reapplication_cooldown_days` | SMALLINT UNSIGNED | N | 30 | Confirmed default (WORKFLOWS §0.14), admin-configurable |
| `account_setup_token_ttl_hours` | SMALLINT UNSIGNED | N | 72 | Proposed range 24–72 h is **unconfirmed** (OD list, architecture #4); the default is a placeholder that is data, not a rule |
| `updated_by_user_id` | BIGINT UNSIGNED | Y | NULL | FK → `users.id` RESTRICT |
| `created_at`, `updated_at` | TIMESTAMP | Y | NULL | |

Access pattern: `MembershipSettings::current()` (`firstOrCreate(['id'=>1])`). Changes are audit-logged with old/new values. Renewal reminder offsets (30/7/0 days) remain in `config/membership.php` because a list of integers is not a natural typed column.

## 5. `membership_applications` — CONFIRMED

One row per application attempt. **Never overwritten, never deleted.** A reapplication after rejection is a new row.

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id` | BIGINT UNSIGNED AI | N | — | PK |
| `public_id` | CHAR(26) `ascii_bin` | N | — | **UNIQUE** ULID; used in emails/signed URLs/routes |
| `user_id` | BIGINT UNSIGNED | Y | NULL | FK. NULL for the public (anonymous) applicant; set when a logged-in member applies (renewal) or when linked later. |
| `membership_category_id` | BIGINT UNSIGNED | N | — | FK. Exactly one category (a single-valued column enforces "exactly one"). |
| `status` | VARCHAR(30) | N | `'submitted'` | `CHECK (status IN ('submitted','more_details_required','approved','rejected'))` |
| `full_name` | VARCHAR(160) | N | — | Snapshot of the reviewed name |
| `email` | VARCHAR(255) | N | — | Stored lower-cased; the cooldown/duplicate key |
| `mobile` | VARCHAR(40) | N | — | Legacy: client-required, server-optional. Required here (used for duplicate/cooldown matching); relaxable in validation. |
| `address` | VARCHAR(400) | N | — | |
| `aviation_role` | VARCHAR(160) | N | — | Category-neutral: Student → course name; Professional → occupation; Veteran → position held |
| `aviation_organisation` | VARCHAR(200) | N | — | Student → training institute; Professional → employer; Veteran → most recent aviation employer |
| `study_start_date` | DATE | Y | NULL | Student only |
| `expected_completion_date` | DATE | Y | NULL | Student only |
| `years_experience` | TINYINT UNSIGNED | Y | NULL | Veteran only (legacy range 0–80) |
| `previous_employers` | TEXT | Y | NULL | Veteran only (legacy ≤ 400 chars) |
| `submitted_at` | TIMESTAMP | N | — | |
| `proof_reviewed_at` | TIMESTAMP | Y | NULL | Set when an admin has reviewed the aviation proof (WORKFLOWS §0.5) |
| `proof_reviewed_by_user_id` | BIGINT UNSIGNED | Y | NULL | FK → `users` |
| `decided_at` | TIMESTAMP | Y | NULL | Set on approve/reject; cleared never (a new attempt is a new row) |
| `decided_by_user_id` | BIGINT UNSIGNED | Y | NULL | FK → `users` |
| `decision_note` | TEXT | Y | NULL | Admin's note / rejection reason (latest; the full trail is in `membership_status_history`) |
| `open_email_key` | VARCHAR(255) | Y | generated | `VIRTUAL` = `IF(status IN ('submitted','more_details_required'), email, NULL)`; **UNIQUE** ⇒ one *open* application per email address. |
| `created_at`, `updated_at` | TIMESTAMP | Y | NULL | |

**Why category-specific fields are columns, not JSON/EAV:** the legacy `form_data` JSON was unvalidated and unqueryable (`VALIDATION.md` gap). The category-specific set is small, known and stable (three categories), so explicit nullable columns are the simplest normalised form; "which are required for which category" is a Form Request rule, not a DB rule (it depends on the category row). The exact *aviation-eligibility* field set beyond what the legacy forms captured is **not confirmed** by ACI — these columns are the legacy-evidenced minimum and may be extended by an additive migration.

**CHECK constraints**

| Constraint | Meaning |
|---|---|
| `status IN (…)` | closed, ACI-confirmed vocabulary |
| `(status IN ('approved','rejected')) = (decided_at IS NOT NULL AND decided_by_user_id IS NOT NULL)` | a decision timestamp exists exactly when the application is in a decided state |
| `status <> 'approved' OR proof_reviewed_at IS NOT NULL` | **an application cannot be approved without the aviation proof having been reviewed** (WORKFLOWS §0.5), enforced by the database |
| `expected_completion_date IS NULL OR study_start_date IS NULL OR expected_completion_date >= study_start_date` | |

**Not database-enforceable (application layer):** "at least one aviation-proof document exists before `submitted`" (cross-table; a CHECK cannot see `documents`; no triggers) and "category-specific fields present". See `15` rule R-05.

**Foreign keys**

| FK column | Parent | Cardinality | ON DELETE |
|---|---|---|---|
| `user_id` | `users` | many applications : 0..1 user | RESTRICT |
| `membership_category_id` | `membership_categories` | many : 1 | RESTRICT |
| `proof_reviewed_by_user_id`, `decided_by_user_id` | `users` | many : 0..1 | RESTRICT |

**Indexes**

| Index | Columns | Reason |
|---|---|---|
| `…_public_id_unique` | `public_id` | route binding / signed URLs |
| `…_open_email_key_unique` | `open_email_key` | one open application per email |
| `…_status_submitted_at_index` | `status, submitted_at` | admin review queue (oldest submitted first) |
| `…_email_status_decided_index` | `email, status, decided_at` | **reapplication cooldown**: latest `rejected` for this email |
| `…_mobile_status_decided_index` | `mobile, status, decided_at` | cooldown by mobile |
| `…_user_id_status_index` | `user_id, status` | member's own applications |
| FK indexes | `membership_category_id`, reviewer/decider FKs | |

**Reapplication + cooldown mechanics.** Cooldown days come from `membership_settings`. Allowed when there is no *open* application (DB-enforced by `open_email_key`) **and** the newest `rejected` application for the same email/mobile/user has `decided_at + cooldown_days <= now`. Historical rows are untouched, so every earlier decision stays reviewable. Soft delete: **NO** — the table is append-only by policy (rows are edited only by the review workflow).

## 6. `membership_details_requests` — the real "more details required" workflow

Each row is one admin request and the applicant's response, so the request/response history required by WORKFLOWS §0.3–§0.4 is a table, not an overwritten JSON note.

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id` | BIGINT UNSIGNED AI | N | — | PK |
| `membership_application_id` | BIGINT UNSIGNED | N | — | FK |
| `requested_by_user_id` | BIGINT UNSIGNED | N | — | FK → `users` (admin) |
| `request_message` | TEXT | N | — | What the applicant must supply |
| `requested_at` | TIMESTAMP | N | — | |
| `responded_at` | TIMESTAMP | Y | NULL | Set when the applicant responds; application returns to `submitted` |
| `response_message` | TEXT | Y | NULL | |
| `open_request_key` | BIGINT UNSIGNED | Y | generated | `VIRTUAL` = `IF(responded_at IS NULL, membership_application_id, NULL)`; **UNIQUE** ⇒ at most one unanswered request per application |
| `created_at`, `updated_at` | TIMESTAMP | Y | NULL | |

FKs: `membership_application_id` → `membership_applications` (many : 1, RESTRICT); `requested_by_user_id` → `users` (RESTRICT). Index: `UNIQUE open_request_key`, `(membership_application_id, requested_at)`. Files supplied in response are `documents` rows with `kind = 'aviation_proof'` and `membership_details_request_id` set (see `07`). No CHECK ties `response_message` to `responded_at`: a response may consist of documents only, so `response_message` is nullable independently. Soft delete: NO.

## 7. `memberships` — CONFIRMED

One row per membership **term**. Created at approval (`pending_activation`), activated later by a single guarded update.

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id` | BIGINT UNSIGNED AI | N | — | PK |
| `membership_application_id` | BIGINT UNSIGNED | N | — | FK, **UNIQUE** — a membership is never independently initiated; one application yields at most one membership |
| `user_id` | BIGINT UNSIGNED | Y | NULL | FK → `users`. Set at activation when the user is provisioned/linked. NULL only before activation, or after activation in the OD-06 "confirm before linking" resolution. |
| `membership_category_id` | BIGINT UNSIGNED | N | — | FK |
| `membership_plan_id` | BIGINT UNSIGNED | N | — | FK — the plan in force at approval |
| `renews_membership_id` | BIGINT UNSIGNED | Y | NULL | FK → `memberships.id` (self). Renewal lineage. |
| `status` | VARCHAR(30) | N | `'pending_activation'` | `CHECK IN ('pending_activation','active','expired')` |
| `payment_status` | VARCHAR(40) | N | — | `CHECK IN ('payment_not_required','payment_pending','payment_confirmation_submitted','payment_confirmed','payment_rejected')`. No default: the approval action states it explicitly. |
| `fee_amount` | DECIMAL(12,2) | N | — | **Snapshot** of the plan fee at approval (`CHECK > 0`); for a free membership it records the fee that was waived |
| `fee_currency` | CHAR(3) | N | — | Snapshot |
| `plan_duration_months` | SMALLINT UNSIGNED | N | — | Snapshot of the plan's standard term |
| `membership_promotion_id` | BIGINT UNSIGNED | Y | NULL | FK → `membership_promotions`. Set **once**, at activation. |
| `promotion_name` | VARCHAR(150) | Y | NULL | Snapshot |
| `promotion_free_months` | SMALLINT UNSIGNED | Y | NULL | Snapshot of the free duration actually granted |
| `membership_number` | CHAR(9) `ascii_bin` | Y | NULL | **UNIQUE**. NULL until activation. |
| `number_year` | SMALLINT UNSIGNED | Y | NULL | Four-digit year used for the number's `YY` and the sequence bucket |
| `number_sequence` | SMALLINT UNSIGNED | Y | NULL | The `SSSS` value (1–9999) |
| `activated_at` | TIMESTAMP | Y | NULL | Actual activation instant |
| `starts_on` | DATE | Y | NULL | Business-calendar activation date (timezone: OD-09) |
| `expires_on` | DATE | Y | NULL | **Inclusive last valid day** (worked example: start 15 Oct 2026 + 6 months → 14 Apr 2027) |
| `expired_at` | TIMESTAMP | Y | NULL | When the expiry job flipped `status` |
| `verification_token` | VARCHAR(64) | Y | NULL | **UNIQUE**. Reserved for future QR verification (`04_MEMBERSHIP_ARCHITECTURE.md` §10); unused until built. |
| `created_at`, `updated_at` | TIMESTAMP | Y | NULL | |

**Membership number storage.** `membership_number` is the 9-character string `C YY RR SSSS`. The `RR` digits are *not* stored separately (they are cosmetic). `number_year` and `number_sequence` are stored so that the **sequence** is independently unique and the string can be cross-checked (below). The category letter comes from `membership_category_id → code`.

**CHECK constraints**

| Constraint | Meaning |
|---|---|
| `status IN (…)`, `payment_status IN (…)` | closed vocabularies |
| `membership_number IS NULL OR membership_number REGEXP '^[SPV][0-9]{8}$'` | exactly 9 chars: uppercase category letter + 8 digits |
| `(membership_number IS NULL) = (number_year IS NULL) AND (number_year IS NULL) = (number_sequence IS NULL)` | number and its components exist together |
| `number_sequence IS NULL OR number_sequence BETWEEN 1 AND 9999` | four-digit sequence |
| `membership_number IS NULL OR (SUBSTRING(membership_number,2,2) = LPAD(number_year MOD 100,2,'0') AND SUBSTRING(membership_number,6,4) = LPAD(number_sequence,4,'0'))` | the string's `YY` and `SSSS` agree with the stored components |
| lifecycle: `(status = 'pending_activation' AND membership_number IS NULL AND activated_at IS NULL AND starts_on IS NULL AND expires_on IS NULL) OR (status IN ('active','expired') AND membership_number IS NOT NULL AND activated_at IS NOT NULL AND starts_on IS NOT NULL AND expires_on IS NOT NULL AND expires_on >= starts_on)` | **a number/dates exist exactly when the membership has been activated** |
| `status = 'pending_activation' OR payment_status IN ('payment_not_required','payment_confirmed')` | **no activation without payment settled** (free promotion or admin-confirmed payment) |
| `(payment_status = 'payment_not_required') = (membership_promotion_id IS NOT NULL)` | free ⇔ a promotion was applied. ("Not required" can only mean promotion; payment is otherwise required.) |
| `(membership_promotion_id IS NULL AND promotion_name IS NULL AND promotion_free_months IS NULL) OR (membership_promotion_id IS NOT NULL AND promotion_name IS NOT NULL AND promotion_free_months > 0)` | promotion snapshot is all-or-nothing |
| `fee_amount > 0` | |

`payment_not_required` is set in the same statement as the promotion, so the biconditional holds at every statement boundary. The only category-letter check the database cannot make is "first character equals the category's code" (cross-table) — see `15` rule R-03.

**Foreign keys**

| FK column | Parent | Cardinality | ON DELETE |
|---|---|---|---|
| `membership_application_id` | `membership_applications` | 1 application : 0..1 membership | RESTRICT |
| `user_id` | `users` | 1 user : many memberships (terms) | RESTRICT |
| `membership_category_id` | `membership_categories` | many : 1 | RESTRICT |
| `membership_plan_id` | `membership_plans` | many : 1 | RESTRICT |
| `membership_promotion_id` | `membership_promotions` | many : 0..1 | RESTRICT |
| `renews_membership_id` | `memberships` (self) | 1 : 0..1 next term | RESTRICT |

**Indexes**

| Index | Columns | Reason |
|---|---|---|
| `memberships_membership_application_id_unique` | `membership_application_id` | 1:1 with application |
| `memberships_membership_number_unique` | `membership_number` | **complete number is unique**; card/verification lookup |
| `memberships_sequence_unique` | `membership_category_id, number_year, number_sequence` | **the sequence itself is unique per category/year**, independent of the random digits — defence in depth if the counter were ever bypassed |
| `memberships_verification_token_unique` | `verification_token` | future QR |
| `memberships_user_id_status_index` | `user_id, status` | member dashboard: current/historical terms |
| `memberships_status_expires_on_index` | `status, expires_on` | daily expiry job and 30/7/0-day reminders |
| `memberships_status_payment_status_index` | `status, payment_status` | admin "payments awaiting confirmation" queue |
| FK indexes | `membership_category_id`, `membership_plan_id`, `membership_promotion_id`, `renews_membership_id` | |

A membership is **"current"** when `status = 'active' AND expires_on >= today`. Queries must not rely on the expiry job having run.

Soft delete: **NO** (historical by design). History/audit: `membership_status_history` (lifecycle facts) + `audit_logs` (who/when/old/new).

## 8. `membership_number_sequences` — CONFIRMED

One row per (category, year): the counter that makes `SSSS` safe under concurrency.

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id` | BIGINT UNSIGNED AI | N | — | PK |
| `membership_category_id` | BIGINT UNSIGNED | N | — | FK → `membership_categories` RESTRICT |
| `sequence_year` | SMALLINT UNSIGNED | N | — | Four-digit activation year |
| `last_number` | SMALLINT UNSIGNED | N | 0 | Last issued `SSSS`. `CHECK (last_number BETWEEN 0 AND 9999)`. 0 = none issued yet. |
| `created_at`, `updated_at` | TIMESTAMP | Y | NULL | |

Unique: `UNIQUE(membership_category_id, sequence_year)` — the lookup **and** the lock target. Soft delete: NO.

### 8.1 Exactly how the schema makes generation safe

All steps run inside **one database transaction** together with the activation `UPDATE`:

1. `BEGIN`.
2. `SELECT id, last_number FROM membership_number_sequences WHERE membership_category_id = ? AND sequence_year = ? FOR UPDATE;` — takes an exclusive row lock; a concurrent activation of the *same category and year* now waits. Other categories/years are unaffected (independent counters).
3. If no row exists: `INSERT … (category, year, 0) ON DUPLICATE KEY UPDATE id = id;` then repeat step 2. (Doing the locking read first, and inserting only on miss, avoids the shared-lock-then-upgrade deadlock a naive `INSERT IGNORE` + `SELECT FOR UPDATE` produces. Laravel's `DB::transaction(…, attempts: 3)` retries a residual deadlock/1213. Pre-creating next year's rows from the scheduler removes the miss path entirely.)
4. `next = last_number + 1`. If `next > 9999`, **abort** with a domain error (capacity exhausted) — the transaction rolls back and nothing is consumed.
5. `UPDATE membership_number_sequences SET last_number = next WHERE id = ?` (equivalently `SET last_number = LAST_INSERT_ID(last_number + 1)`).
6. `rr = random_int(0, 99)` (cosmetic; never used for uniqueness). `number = code . LPAD(year MOD 100,2,'0') . LPAD(rr,2,'0') . LPAD(next,4,'0')`.
7. Compare-and-set the membership: `UPDATE memberships SET membership_number=?, number_year=?, number_sequence=?, status='active', activated_at=?, starts_on=?, expires_on=?, membership_promotion_id=?, promotion_name=?, promotion_free_months=?, payment_status=? WHERE id = ? AND status = 'pending_activation'` — **0 rows affected ⇒ already activated by another request ⇒ roll back** (the counter increment rolls back with it).
8. Insert `membership_status_history` + `audit_logs` rows; `COMMIT`.

Why this is safe:

* **Concurrency:** step 2's row lock serialises same-category/year activations; two transactions can never read the same `last_number`. `MAX()+1` is never used.
* **No consumed numbers on failure:** the increment is in the same transaction as the activation; any failure (payment guard, CHECK violation, crash) rolls both back → the sequence has no gaps caused by failures. Rejected and payment-pending applications never touch this table at all because it is only reached from the activation action.
* **Layered uniqueness:** (a) counter lock; (b) `UNIQUE(category, number_year, number_sequence)` on `memberships`; (c) `UNIQUE(membership_number)`; (d) CHECKs tie the string to its components. Any single-layer bug is caught by another.
* **Idempotency:** the `WHERE status='pending_activation'` guard means a retried/replayed activation (e.g. a duplicated payment-confirmation event) issues no second number.
* **Year rollover / capacity:** rows are per (category, year), so the sequence restarts each January (semantics to confirm, OD-03). 9,999 activations per category per year is the hard ceiling of a four-digit sequence; exceeding it is an explicit error, never a wrap or a duplicate.

## 9. `membership_status_history` — CONFIRMED (append-only)

The rich, domain-specific trail (`12_AUDIT_LOGGING_ARCHITECTURE.md` mechanism #1). **Not polymorphic**: every membership-lifecycle event belongs to an application (a membership always has one), and optionally also to the membership.

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id` | BIGINT UNSIGNED AI | N | — | PK |
| `membership_application_id` | BIGINT UNSIGNED | N | — | FK → `membership_applications` RESTRICT |
| `membership_id` | BIGINT UNSIGNED | Y | NULL | FK → `memberships` RESTRICT; set for events after approval |
| `event` | VARCHAR(60) | N | — | `application.submitted`, `application.more_details_requested`, `application.details_responded`, `application.approved`, `application.rejected`, `payment.instructions_sent`, `payment.evidence_submitted`, `payment.confirmed`, `payment.rejected`, `membership.activated`, `membership.number_issued`, `membership.promotion_applied`, `membership.expired`, `membership.renewal_started` |
| `from_status` | VARCHAR(40) | Y | NULL | |
| `to_status` | VARCHAR(40) | Y | NULL | |
| `actor_type` | VARCHAR(20) | N | — | `applicant`, `admin`, `system`; `CHECK IN` those three |
| `actor_user_id` | BIGINT UNSIGNED | Y | NULL | FK → `users` RESTRICT; NULL for anonymous applicant or system |
| `note` | TEXT | Y | NULL | Admin note / applicant response summary (never a secret) |
| `created_at` | TIMESTAMP | N | CURRENT_TIMESTAMP | append-only; **no `updated_at`** |

Indexes: `(membership_application_id, id)` (timeline), `(membership_id)`, `(event, created_at)`. No update/delete path exists in the application (`15` R-12). The `event` value is deliberately free-form VARCHAR (not CHECK-guarded): it is a log vocabulary that will grow.

`membership.number_issued` records the issued number in `note` (the membership row also holds it; the history row is what proves *when* and *by whom/what*).

## 10. Legacy elements deliberately not carried forward

`memberships.application_data` JSONB and `membership_applications.form_data/documents` JSONB (→ columns + `documents` table); free-text `status`/`payment_status`; `payment_link`/`payment_amount`/`payment_sent_at` typed into the membership (→ `payments`); `next_membership_number()` RPC and `membership_number_seq`; the two parallel membership systems; `verify_membership` RPC (validity is derived from `status` + `expires_on` at query time); temp-password provisioning.
