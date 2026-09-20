# 04 — Membership Schema

Design only. Tables: `membership_categories`, `membership_plans`, `membership_settings`, `membership_applications`, `membership_details_requests`, `memberships`, `membership_terms`, `membership_number_sequences`, `membership_status_history`. Payments are in `06_PAYMENT_SCHEMA.md`; aviation-proof files in `07_DOCUMENT_SCHEMA.md`; the (deferred) marketing-promotions capability in `05_PROMOTION_SCHEMA.md`.

**Revised by the confirmed decisions OD-10 and OD-04** (see `17_DATABASE_OPEN_DECISIONS.md`). This document is the single authority for the membership model.

Legend: **N** = `NOT NULL`, **Y** = nullable. All FKs are `ON UPDATE RESTRICT` and follow the delete policy in `01` §3.4.

## 1. The confirmed rules this schema implements

| Rule | Schema consequence |
|---|---|
| **Every approved new member gets an introductory period — the first 6 months are free.** It is a standard new-member rule, **not** a promotion, coupon or eligibility calculation, and nothing is re-evaluated after approval. | The free period is the member's first *term* (`term_kind = 'introductory'`, `payment_not_required`). Its length comes from `membership_settings.introductory_period_months` (default 6, data not code). No promotion FK exists on memberships or terms. |
| No payment for the introductory period; **no fake £0 payment**. | No `payments` row is created for term 1; `payments.amount > 0` is CHECK-enforced. |
| Before expiry the member is notified, **pays a renewal fee**, and is renewed for another (normally) **12 months**. Renewal price comes from the database; notification timing is configurable; **no automatic charging, no automatic renewal without payment.** | Renewal = a new `membership_terms` row (`term_kind = 'renewal'`) that becomes valid only when its payment is admin-confirmed. Fee/duration are snapshotted from `membership_plans`. Reminder offsets are configuration (`config/membership.php`), not hard-coded. |
| **The membership number identifies the MEMBER for life** and never changes at renewal. Format `CYYRRSSSS`; `YY` = original activation year. | The number lives on `memberships` (one row per member). Terms never carry a number. It is generated exactly once, at first activation. |
| Rejected/pending applications never consume a sequence number; sequence generation is transaction-safe (row lock, never `MAX()+1`). | `membership_number_sequences` locked inside the activation transaction (§9). |
| Application and membership are separate lifecycles; historical applications are preserved. | Separate tables; `memberships.membership_application_id` is the *originating* application; rejected applications are never edited. |

## 2. Lifecycle model

```
Applicant (no account yet)
   │ submit
   ▼
membership_applications   submitted ⇄ more_details_required → approved | rejected
   │  (membership_details_requests holds each request/response)
   │  approved (aviation proof reviewed)
   ▼
PAYMENT / FREE DECISION  ← for every new member: payment NOT required (first N months free, standard rule)
   │  no payments row, no £0 payment
   ▼
ACTIVATION (follows the decision — separate step, not part of approval itself)
   ▼
memberships            ← stable member record: number issued ONCE (row-locked sequence)
membership_terms #1    ← introductory term: first N months free, payment_not_required, active
users row (pending_setup) + account_setup_tokens · history + audit rows · emails M8, M9 (M4 was sent at approval / payment-free decision)
   │
   │  … before expiry: reminders (configurable offsets, no auto-charge) …
   ▼
member starts renewal  → membership_terms #n (renewal, pending_payment) + payments (pending)
   payment_pending → payment_confirmation_submitted → payment_confirmed → term active (normally 12 months)
                     └ rejected → back to payment_pending (same term/payment row)
   ▼
term expiry → terms.status = expired (M11); the member and the number persist
   ▼
if the renewal is never paid → the membership eventually becomes DEACTIVATED (grace period NOT defined — OD-22; not invented here)
```

**The confirmed workflow for every new member is Application → Admin approval → Payment/Free decision → Activation** (approval is not immediate activation). The decision is deterministic — the first 6 months are a standard introductory rule, so payment is not required — and is recorded as a history event; activation then creates the member, issues the number and starts term 1. An application that is `approved` with **no** `memberships` row is the normal **"approved, awaiting activation"** state (reconciliation Q13 in `15` monitors how long rows stay there rather than expecting zero). No new application status is added: the four ACI-confirmed statuses are unchanged. Whether the decision and activation steps run automatically after approval or on an explicit admin action is not specified by ACI (OD-23); the schema supports both.

## 3. `membership_categories` — CONFIRMED (exactly three)

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id` | BIGINT UNSIGNED AI | N | — | PK |
| `code` | CHAR(1) `ascii_bin` | N | — | **UNIQUE**. `CHECK (code IN ('S','P','V'))`. Immutable — embedded in issued membership numbers. |
| `name` | VARCHAR(100) | N | — | Display name (Student / Professional / Veteran; final commercial names are data) |
| `description` | TEXT | Y | NULL | |
| `is_active` | TINYINT(1) | N | 1 | Whether the category accepts new applications |
| `display_order` | SMALLINT UNSIGNED | N | 0 | |
| `created_at`, `updated_at` | TIMESTAMP | Y | NULL | |

"Exactly three" = `UNIQUE(code)` + `CHECK`. Never deleted. Soft delete: **NO**.

## 4. `membership_plans` — renewal pricing (REWORK)

The **paid** commercial terms for a category: the fee charged for a **renewal term** and the standard renewal length. (The introductory period is *not* a plan — it has no fee; it is a setting, §5.) Separate from the category so pricing can change without rewriting history — each renewal term snapshots the plan it was sold under.

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id` | BIGINT UNSIGNED AI | N | — | PK |
| `membership_category_id` | BIGINT UNSIGNED | N | — | FK |
| `name` | VARCHAR(100) | N | — | e.g. "Student — annual renewal" |
| `description` | TEXT | Y | NULL | |
| `fee_amount` | DECIMAL(12,2) | N | — | Renewal fee. `CHECK (fee_amount > 0)`. **Value not fixed by any document** — it is data entered by ACI (OD-02); never hard-coded. |
| `currency` | CHAR(3) | N | — | No default (OD-01) |
| `duration_months` | SMALLINT UNSIGNED | N | — | Renewal term length, **normally 12**. `CHECK (duration_months > 0)`. |
| `is_active` | TINYINT(1) | N | 1 | |
| `active_category_key` | BIGINT UNSIGNED | Y | generated | `VIRTUAL` = `IF(is_active = 1, membership_category_id, NULL)`; **UNIQUE** ⇒ at most one active plan per category (Professional stays one plan unless ACI approves more). |
| `created_at`, `updated_at` | TIMESTAMP | Y | NULL | |

**Immutability rule (application layer):** once any term references a plan its `fee_amount`, `currency`, `duration_months` are not edited — create a new plan and deactivate the old one (audit-logged). FK: `membership_category_id` → `membership_categories` (many : 1, RESTRICT). Indexes: PK; `UNIQUE active_category_key`; FK index. Soft delete **NO**. Legacy `membership_benefits`: **DEFER**; the legacy 4-plan seed is not carried forward.

## 5. `membership_settings` — singleton

Typed singleton for membership-workflow tunables. **Not** a key/value table.

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id` | TINYINT UNSIGNED | N | 1 | PK, `CHECK (id = 1)` |
| `introductory_period_months` | SMALLINT UNSIGNED | N | **6** | Length of the free introductory term granted to **every** approved new member. `CHECK (introductory_period_months > 0)`. Confirmed default; configurable data, never hard-coded in code or views. Changing it affects **only future** activations (each term snapshots its own length). |
| `reapplication_cooldown_days` | SMALLINT UNSIGNED | N | 30 | Confirmed default (WORKFLOWS §0.14), admin-configurable |
| `account_setup_token_ttl_hours` | SMALLINT UNSIGNED | N | 72 | Placeholder within the 24–72 h range; data, not a rule |
| `updated_by_user_id` | BIGINT UNSIGNED | Y | NULL | FK → `users` RESTRICT |
| `created_at`, `updated_at` | TIMESTAMP | Y | NULL | |

Access: `MembershipSettings::current()` (`firstOrCreate(['id'=>1])`). Changes are audit-logged (old/new). **Renewal reminder offsets** (recommended 30/7/0 days before expiry) stay in `config/membership.php` because a list of integers is not a natural typed column; they are configurable without code changes to the schedule logic.

## 6. `membership_applications` — CONFIRMED

One row per application attempt for **new** membership. **Never overwritten, never deleted.** A reapplication after rejection is a new row. Renewals are **not** applications (they are terms, §8).

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id` | BIGINT UNSIGNED AI | N | — | PK |
| `public_id` | CHAR(26) `ascii_bin` | N | — | **UNIQUE** ULID; used in signed links and routes. **Knowing it grants no access by itself** (signed, expiring link required — frontend C-05). |
| `user_id` | BIGINT UNSIGNED | Y | NULL | FK. NULL for the public applicant (accounts are provisioned at activation, so there is normally no user yet). Set only in the OD-06 collision case. |
| `membership_category_id` | BIGINT UNSIGNED | N | — | FK. Exactly one category. |
| `status` | VARCHAR(30) | N | `'submitted'` | `CHECK (status IN ('submitted','more_details_required','approved','rejected'))` |
| `full_name` | VARCHAR(160) | N | — | Snapshot of the reviewed name; copied verbatim to `users.name` at activation (no splitting) |
| `email` | VARCHAR(255) | N | — | Stored lower-cased; the cooldown/duplicate key |
| `mobile` | VARCHAR(40) | N | — | Used for duplicate/cooldown matching |
| `address` | VARCHAR(400) | N | — | |
| `aviation_role` | VARCHAR(160) | N | — | Student → course name; Professional → occupation; Veteran → position held |
| `aviation_organisation` | VARCHAR(200) | N | — | Student → training institute; Professional → employer; Veteran → most recent aviation employer |
| `study_start_date` | DATE | Y | NULL | Student only |
| `expected_completion_date` | DATE | Y | NULL | Student only |
| `years_experience` | TINYINT UNSIGNED | Y | NULL | Veteran only |
| `previous_employers` | TEXT | Y | NULL | Veteran only |
| `submitted_at` | TIMESTAMP | N | — | |
| `proof_reviewed_at` | TIMESTAMP | Y | NULL | Set when an admin has reviewed the aviation proof (WORKFLOWS §0.5) |
| `proof_reviewed_by_user_id` | BIGINT UNSIGNED | Y | NULL | FK → `users` |
| `decided_at` | TIMESTAMP | Y | NULL | Set on approve/reject |
| `decided_by_user_id` | BIGINT UNSIGNED | Y | NULL | FK → `users` |
| `decision_note` | TEXT | Y | NULL | Admin note / rejection reason (latest; full trail in `membership_status_history`) |
| `open_email_key` | VARCHAR(255) | Y | generated | `VIRTUAL` = `IF(status IN ('submitted','more_details_required'), email, NULL)`; **UNIQUE** ⇒ one *open* application per email. |
| `created_at`, `updated_at` | TIMESTAMP | Y | NULL | |

**Why category-specific fields are columns, not JSON/EAV:** the small, stable set for three categories is simplest as explicit nullable columns; "required for which category" is a Form Request rule. The eligibility field set beyond the legacy-evidenced minimum is not confirmed (OD-20); additions are additive migrations.

**CHECK constraints**

| Constraint | Meaning |
|---|---|
| `status IN (…)` | closed, ACI-confirmed vocabulary |
| `(status IN ('approved','rejected')) = (decided_at IS NOT NULL AND decided_by_user_id IS NOT NULL)` | decision data exists exactly when decided |
| `status <> 'approved' OR proof_reviewed_at IS NOT NULL` | **cannot be approved without aviation-proof review** (WORKFLOWS §0.5) |
| `expected_completion_date IS NULL OR study_start_date IS NULL OR expected_completion_date >= study_start_date` | |

**Application-layer only:** ≥ 1 aviation-proof document exists before `submitted`; category-specific fields present; **an email/person that already has a `memberships` row cannot open a new application — they renew** (R-06 in `15`).

**Foreign keys:** `user_id` → `users`; `membership_category_id` → `membership_categories`; `proof_reviewed_by_user_id`, `decided_by_user_id` → `users` — all many : 0..1/1, `RESTRICT`.

**Indexes:** `UNIQUE(public_id)`; `UNIQUE(open_email_key)`; `(status, submitted_at)` (review queue); `(email, status, decided_at)` and `(mobile, status, decided_at)` (**reapplication cooldown**); `(user_id, status)`; FK indexes.

**Cooldown:** allowed when there is no open application (DB-enforced) **and** the newest `rejected` application for the same email/mobile has `decided_at + reapplication_cooldown_days <= now`. Soft delete: **NO**.

## 7. `membership_details_requests` — the real "more details required" workflow

Each row is one admin request and the applicant's response (WORKFLOWS §0.3–§0.4).

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id` | BIGINT UNSIGNED AI | N | — | PK |
| `membership_application_id` | BIGINT UNSIGNED | N | — | FK |
| `requested_by_user_id` | BIGINT UNSIGNED | N | — | FK → `users` (admin) |
| `request_message` | TEXT | N | — | |
| `requested_at` | TIMESTAMP | N | — | |
| `responded_at` | TIMESTAMP | Y | NULL | |
| `response_message` | TEXT | Y | NULL | May be empty when the response is documents only |
| `open_request_key` | BIGINT UNSIGNED | Y | generated | `VIRTUAL` = `IF(responded_at IS NULL, membership_application_id, NULL)`; **UNIQUE** ⇒ ≤ 1 unanswered request per application |
| `created_at`, `updated_at` | TIMESTAMP | Y | NULL | |

FKs `RESTRICT`. Indexes: `UNIQUE(open_request_key)`, `(membership_application_id, requested_at)`. Response files are `documents` rows (`kind = aviation_proof`, `membership_details_request_id` set). Soft delete: NO.

## 8. `memberships` — the stable member record (OD-04 RESOLVED)

**One row per member, for life.** It carries the membership number and is created **once, at first activation**. It does **not** carry validity dates, fees, payment state or status — those belong to terms (§9). Renewal never creates or changes a `memberships` row.

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id` | BIGINT UNSIGNED AI | N | — | PK |
| `membership_application_id` | BIGINT UNSIGNED | N | — | FK, **UNIQUE** — the *originating* application; one application yields at most one member |
| `user_id` | BIGINT UNSIGNED | Y | NULL | FK → `users`, **UNIQUE** (a person is one member). Set at activation (user provisioned/linked). NULL only in the OD-06 "confirm before linking" resolution. |
| `membership_category_id` | BIGINT UNSIGNED | N | — | FK — the category at first activation; matches the number's prefix and does not change (category change is not supported, OD-21) |
| `membership_number` | CHAR(9) `ascii_bin` | N | — | **UNIQUE.** `CYYRRSSSS`. Issued once, never regenerated. |
| `number_year` | SMALLINT UNSIGNED | N | — | Four-digit **original activation year** (drives `YY` and the sequence bucket) |
| `number_sequence` | SMALLINT UNSIGNED | N | — | The `SSSS` value (1–9999) |
| `activated_at` | TIMESTAMP | N | — | Instant of first activation |
| `activated_on` | DATE | N | — | Business-calendar date of first activation (timezone: OD-09) = start of term 1 |
| `verification_token` | VARCHAR(64) | Y | NULL | **UNIQUE**. Reserved for future QR verification (unused until built) |
| `created_at`, `updated_at` | TIMESTAMP | Y | NULL | |

Because the row is created **at** activation, number, year, sequence and activation timestamps are all `NOT NULL` — there is no "pending" member row (the pending-payment/pending-activation states of the previous design no longer exist for new members).

**Membership number.** The string is `C YY RR SSSS`; the random `RR` digits are not stored separately (cosmetic). `number_year` and `number_sequence` are stored so the **sequence** is independently unique and the string can be cross-checked. The category letter comes from `membership_category_id → code`.

**CHECK constraints**

| Constraint | Meaning |
|---|---|
| `membership_number REGEXP '^[SPV][0-9]{8}$'` | exactly 9 chars: uppercase category letter + 8 digits (binary collation ⇒ case-sensitive) |
| `number_sequence BETWEEN 1 AND 9999` | four-digit sequence |
| `SUBSTRING(membership_number,2,2) = LPAD(number_year MOD 100,2,'0') AND SUBSTRING(membership_number,6,4) = LPAD(number_sequence,4,'0')` | string agrees with stored components |

The only check the database cannot make is "first character equals the category's code" (cross-table) — rule R-03 (`15`).

**Foreign keys:** `membership_application_id` → `membership_applications` (1 : 0..1, RESTRICT); `user_id` → `users` (0..1 : 1, RESTRICT); `membership_category_id` → `membership_categories` (many : 1, RESTRICT).

**Indexes:** `UNIQUE(membership_application_id)`; `UNIQUE(membership_number)` (**complete number unique**; card/verification lookup); `UNIQUE(membership_category_id, number_year, number_sequence)` (**the sequence is unique per category/year independent of the random digits**); `UNIQUE(user_id)`; `UNIQUE(verification_token)`; FK index on category.

**Derived state (not stored):** a member is **current** when a term is `active` and `starts_on <= today <= expires_on`. A membership is **not current (lapsed)** when no term is current. If renewal payment is never made the membership **eventually becomes deactivated**; the grace period between term expiry and deactivation is **not defined by ACI and is not invented here (OD-22)**, so until it is defined "deactivated" is represented as "no current term" and no stored member status exists (an additive `memberships` status/`deactivated_at` may be needed once OD-22 is answered). Queries must never rely on the expiry job having run.

Soft delete: **NO** — never deleted. History: `membership_status_history` + `audit_logs`.

## 9. `membership_terms` — one row per validity term (introductory + renewals)

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id` | BIGINT UNSIGNED AI | N | — | PK |
| `membership_id` | BIGINT UNSIGNED | N | — | FK → `memberships` RESTRICT |
| `term_no` | SMALLINT UNSIGNED | N | — | 1 = introductory; 2, 3, … = renewals. `CHECK (term_no >= 1)` |
| `term_kind` | VARCHAR(20) | N | — | `CHECK IN ('introductory','renewal')` |
| `status` | VARCHAR(30) | N | — | `CHECK IN ('pending_payment','active','expired')`. `pending_payment` = renewal awaiting confirmed payment (not valid yet). |
| `payment_status` | VARCHAR(40) | N | — | `CHECK IN ('payment_not_required','payment_pending','payment_confirmation_submitted','payment_confirmed','payment_rejected')`. Introductory: always `payment_not_required`. |
| `duration_months` | SMALLINT UNSIGNED | N | — | Length granted. Introductory: **snapshot of `membership_settings.introductory_period_months`** (6). Renewal: **snapshot of `membership_plans.duration_months`** (normally 12). `CHECK (duration_months > 0)` |
| `membership_plan_id` | BIGINT UNSIGNED | Y | NULL | FK → `membership_plans`. **Renewal only.** |
| `fee_amount` | DECIMAL(12,2) | Y | NULL | Snapshot of the renewal fee. **NULL for the introductory term** (there is no fee and no £0 amount). |
| `fee_currency` | CHAR(3) | Y | NULL | Snapshot; NULL for the introductory term |
| `starts_on` | DATE | Y | NULL | Term start (business date). NULL until the term is valid. |
| `expires_on` | DATE | Y | NULL | **Inclusive last valid day** (e.g. start 15 Oct 2026 + 6 months → 14 Apr 2027). NULL until valid. |
| `activated_at` | TIMESTAMP | Y | NULL | Introductory: = member activation. Renewal: admin payment confirmation. |
| `expired_at` | TIMESTAMP | Y | NULL | When the expiry job set `status = 'expired'` |
| `open_renewal_key` | BIGINT UNSIGNED | Y | generated | `VIRTUAL` = `IF(status = 'pending_payment', membership_id, NULL)`; **UNIQUE** ⇒ at most one renewal awaiting payment per member |
| `created_at`, `updated_at` | TIMESTAMP | Y | NULL | |

**CHECK constraints**

| Constraint | Meaning |
|---|---|
| vocabularies (`term_kind`, `status`, `payment_status`) | closed sets |
| `(term_kind = 'introductory') = (term_no = 1)` | exactly the first term is introductory |
| `(term_kind = 'introductory') = (payment_status = 'payment_not_required')` | **free ⇔ introductory**; every renewal has a payment state; nothing else is "free" |
| `(term_kind = 'introductory' AND fee_amount IS NULL AND fee_currency IS NULL AND membership_plan_id IS NULL) OR (term_kind = 'renewal' AND fee_amount > 0 AND fee_currency IS NOT NULL AND membership_plan_id IS NOT NULL)` | no fee/plan on the free term; a positive snapshotted fee on every renewal (**no £0 fee anywhere**) |
| `(status = 'pending_payment' AND starts_on IS NULL AND expires_on IS NULL AND activated_at IS NULL) OR (status IN ('active','expired') AND starts_on IS NOT NULL AND expires_on IS NOT NULL AND activated_at IS NOT NULL AND expires_on >= starts_on)` | dates exist exactly when the term is valid |
| `status = 'pending_payment' OR payment_status IN ('payment_not_required','payment_confirmed')` | **a term is valid only when its payment is settled** (or none is required) |
| `status <> 'pending_payment' OR term_kind = 'renewal'` | the introductory term is never pending |

**Renewal term dates (recommended default, schema-neutral, OD-21):** when the member renews while a term is still current, the new term starts the day after the current term's `expires_on` (no lost days); when renewing after expiry it starts at confirmation. Set by the confirm action; the schema does not depend on the rule.

**Foreign keys:** `membership_id` → `memberships` (many : 1, RESTRICT); `membership_plan_id` → `membership_plans` (many : 0..1, RESTRICT).

**Indexes:** `UNIQUE(membership_id, term_no)`; `UNIQUE(open_renewal_key)`; `(status, expires_on)` (expiry job + reminder queries); `(status, payment_status)` (admin "payments awaiting confirmation" queue); `membership_plan_id` FK index.

Soft delete: **NO** — terms are historical facts. Renewal price/length come from the database plan; nothing is hard-coded.

## 10. `membership_number_sequences` — CONFIRMED

One row per (category, year): the counter that makes `SSSS` safe under concurrency.

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id` | BIGINT UNSIGNED AI | N | — | PK |
| `membership_category_id` | BIGINT UNSIGNED | N | — | FK → `membership_categories` RESTRICT |
| `sequence_year` | SMALLINT UNSIGNED | N | — | Four-digit activation year |
| `last_number` | SMALLINT UNSIGNED | N | 0 | Last issued `SSSS`. `CHECK (last_number BETWEEN 0 AND 9999)`. 0 = none issued yet. |
| `created_at`, `updated_at` | TIMESTAMP | Y | NULL | |

Unique: `UNIQUE(membership_category_id, sequence_year)` — the lookup **and** the lock target. Sequence scope (category + year) is confirmed by the business brief ("category/year sequence"). Soft delete: NO.

### 10.1 How the schema makes generation safe

The number is generated **exactly once per member**: inside the **first-activation** transaction, which runs after approval and the payment/free decision (renewals never touch this table). Approval itself is a separate, earlier transaction (the application compare-and-set below). The activation steps are **one transaction**:

1. `BEGIN`.
2. Lock the application row: `SELECT id FROM membership_applications WHERE id=? AND status='approved' FOR UPDATE`, and verify no `memberships` row exists for it — otherwise (not approved, or already activated) roll back. *(The earlier approval step already did `UPDATE membership_applications SET status='approved', decided_at=…, decided_by_user_id=… WHERE id=? AND status='submitted' AND proof_reviewed_at IS NOT NULL` — a compare-and-set in its own transaction; `0 rows` ⇒ already decided.)*
3. `SELECT id, last_number FROM membership_number_sequences WHERE membership_category_id=? AND sequence_year=? FOR UPDATE;` — exclusive row lock; a concurrent activation of the *same category and year* waits. Other categories/years are unaffected.
4. If no row exists: `INSERT … (category, year, 0) ON DUPLICATE KEY UPDATE id = id;` then repeat step 3. (Locking read first, insert only on miss, avoids the shared-then-exclusive lock deadlock of a naive insert-first pattern; `DB::transaction(…, attempts: 3)` retries a residual deadlock. Pre-creating next year's rows from the scheduler removes the miss path.)
5. `next = last_number + 1`; if `next > 9999` abort with a domain error (nothing consumed).
6. `UPDATE membership_number_sequences SET last_number = next WHERE id = ?`.
7. `rr = random_int(0, 99)` (cosmetic). `number = code . LPAD(year MOD 100,2,'0') . LPAD(rr,2,'0') . LPAD(next,4,'0')`.
8. `INSERT` the `memberships` row (number, `number_year`, `number_sequence`, activation timestamps) — the `UNIQUE(membership_application_id)` blocks a second member for the same application even if step 2 were bypassed.
9. `INSERT` `membership_terms` term 1 (`introductory`, `active`, `payment_not_required`, `duration_months` = settings snapshot, `starts_on`/`expires_on` computed).
10. Provision/link the user + `account_setup_tokens`; insert `membership_status_history` + `audit_logs`; `COMMIT`.

Why this is safe:

* **Concurrency:** step 3's row lock serialises same-bucket activations; `MAX()+1` is never used.
* **No consumed numbers on failure:** the increment is in the same transaction as the member insert — any failure rolls both back. Rejected, more-details and pending applications never reach this table.
* **Once per member for life:** renewals create `membership_terms` rows only; `memberships.membership_number` has no update path (application rule R-03/R-25), and `UNIQUE(user_id)` + `UNIQUE(membership_application_id)` prevent a second identity.
* **Layered uniqueness:** counter lock; `UNIQUE(category, number_year, number_sequence)`; `UNIQUE(membership_number)`; CHECKs tie the string to its parts.
* **Idempotency:** the application lock + "no member yet" check (step 2) and `UNIQUE(membership_application_id)` mean a replayed or concurrent activation issues nothing.
* **Year rollover / capacity:** rows are per (category, year); 9,999 activations per category per year is the ceiling — exceeding it is an explicit error, never a wrap or duplicate.

MySQL note: CAS `UPDATE`s report **changed** rows, so each guarded update must always change at least one column (see `18` §5).

## 11. `membership_status_history` — CONFIRMED (append-only)

The domain-specific lifecycle trail. **Not polymorphic**: events before activation anchor to the application; events afterwards anchor to the member (and optionally a term).

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id` | BIGINT UNSIGNED AI | N | — | PK |
| `membership_application_id` | BIGINT UNSIGNED | Y | NULL | FK → `membership_applications` RESTRICT. Set for application-stage events (and the activation event). |
| `membership_id` | BIGINT UNSIGNED | Y | NULL | FK → `memberships` RESTRICT. Set for member-stage events. |
| `membership_term_id` | BIGINT UNSIGNED | Y | NULL | FK → `membership_terms` RESTRICT. Set for term/payment events. |
| `event` | VARCHAR(60) | N | — | `application.submitted`, `application.more_details_requested`, `application.details_responded`, `application.approved`, `application.payment_decision` (note: payment not required — introductory period), `application.rejected`, `membership.activated`, `membership.number_issued`, `membership.introductory_term_started`, `membership.reminder_sent`, `membership.renewal_started`, `payment.instructions_sent`, `payment.evidence_submitted`, `payment.confirmed`, `payment.rejected`, `membership.term_activated`, `membership.term_expired` |
| `from_status` | VARCHAR(40) | Y | NULL | |
| `to_status` | VARCHAR(40) | Y | NULL | |
| `actor_type` | VARCHAR(20) | N | — | `CHECK IN ('applicant','admin','member','system')` |
| `actor_user_id` | BIGINT UNSIGNED | Y | NULL | FK → `users` RESTRICT |
| `note` | TEXT | Y | NULL | Admin note / summary (never a secret) |
| `created_at` | TIMESTAMP | N | CURRENT_TIMESTAMP | append-only; no `updated_at` |

`CHECK (membership_application_id IS NOT NULL OR membership_id IS NOT NULL)` — every event has an anchor. Indexes: `(membership_application_id, id)`, `(membership_id, id)`, `(membership_term_id)`, `(event, created_at)`. No update/delete path (R-12). `membership.number_issued` records the number in `note` (the member row holds it; history proves *when* and *by what*).

## 12. Legacy elements deliberately not carried forward

`memberships.application_data` / `form_data` / `documents` JSONB; free-text `status`/`payment_status`; manually typed `payment_link`/`payment_amount`; the `next_membership_number()` RPC and `membership_number_seq`; the two parallel membership systems; `verify_membership` RPC (validity is derived from terms at query time); temp-password provisioning; **and, from the previous revision of this design:** the promotion-resolution columns on memberships, the `pending_activation` membership state, payment-before-activation for new members, and the `renews_membership_id` lineage (a renewal is a term of the same member, not a new membership).
