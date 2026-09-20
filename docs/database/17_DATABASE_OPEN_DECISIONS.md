# 17 — Database Open Decisions

Reconciled with the confirmed business decisions OD-10, OD-04 and OD-08 (and the confirmed single `users.name`). Only genuinely unresolved issues that affect the schema or its safe implementation remain OPEN. Resolution phases: **P4** = migration planning · **P4-late** = before that domain's migration · **Launch** = before go-live · **Later** = when the feature is scheduled.

## 0. Status board

| ID | Topic | Status |
|---|---|---|
| **OD-04** | Membership number across renewals | **RESOLVED** — the number identifies the member for life (§1) |
| **OD-08** | Production DB engine/version | **RESOLVED** — MySQL 8.4.6 (§1) |
| **OD-10** | Free initial period | **RESOLVED** — mandatory 6-month introductory term for every approved new member; not a promotion (§1) |
| OD-05 | Promotion more than once per person | **OBSOLETE** (§2) |
| OD-12 | Approved-but-unpaid memberships never activating | **OBSOLETE** (§2) |
| OD-21 | Renewal start-date / lapse / category-change details | OPEN (new, non-blocking) |
| OD-22 | Non-payment: grace period and deactivation | OPEN (new, non-blocking) — grace period undefined |
| OD-23 | What triggers activation after approval | OPEN (new, non-blocking) |
| OD-01, 02, 06, 09, 11, 13, 14, 15, 16, 17, 18, 19, 20 | see §3 | OPEN (none blocks migration generation) |
| OD-03, OD-07 | sequence scope; pre-account access | **Confirmed in principle** by the business brief and frontend C-05; no schema effect (§3) |

## 1. RESOLVED decisions

### OD-10 — Initial 6-month free membership — **RESOLVED**
**Decision (confirmed by ACI):** the initial membership is **free for the first 6 months for every approved new member**. It is not an optional promotion and not a promotion-eligibility calculation. Flow: application submitted → admin review → admin approval → payment/free decision (payment not required for an initial membership) → membership activated → first 6 months free → before expiry, renewal/payment notifications → member pays the renewal fee → renewed for another (normally) 12 months.

**Consequences (authoritative interpretation, applied everywhere):**
* No payment for the initial period; **no fake £0 payment** (`payments.amount > 0`; term 1 has no fee).
* Not a coupon; no eligibility check; **no re-evaluation after approval**; no "promotion resolved at approval/payment decision point".
* Schema: the free period is **term 1** of `membership_terms` (`term_kind = 'introductory'`, `payment_not_required`), length from `membership_settings.introductory_period_months` (default 6, configurable, snapshotted per term). `memberships` carries no promotion columns.
* Renewal: a further term (`renewal`, normally 12 months) valid only after admin-confirmed payment; renewal fee and length come from `membership_plans` (data, **the fee amount is not fixed by any document — do not invent it**); reminder timing is configuration (recommended 30/7/0 days); **no automatic charging and no automatic renewal without payment.**
* The Promotions domain is **deferred** and explicitly decoupled (`05`); the mandatory rule never depends on it.

### OD-04 — Membership number across renewals — **RESOLVED**
**Decision (confirmed by ACI):** a member keeps the **same** membership number for their entire membership lifetime, including all renewals (e.g. `P26070003` throughout). The number identifies the **member**, not a term. It is generated **once**, at first activation; renewal never generates or changes it; `YY` = original activation year. Format `CYYRRSSSS` and the row-lock sequence are unchanged.

**Consequences:** `memberships` is the stable member record holding the number; validity/renewals live in `membership_terms`; `renews_membership_id` and the per-term number were removed; `UNIQUE(user_id)` and `UNIQUE(membership_application_id)` guarantee one identity per person/application; the generator is called only in the first-activation transaction (`04` §10.1). The former overlap/pricing sub-questions are reduced to the non-blocking OD-21.

### OD-08 — Production database engine — **RESOLVED**
**Decision (confirmed):** production is **MySQL 8.4.6** (utf8mb4), PHP 8.2.33, Apache. **Target: MySQL 8.4-compatible behaviour with Laravel's `mysql` driver.** Documented minimum: **MySQL ≥ 8.0.19** (CHECK enforcement 8.0.16; `ALTER TABLE … DROP CONSTRAINT` 8.0.19); development should use MySQL 8.4.x. **MariaDB (including the local XAMPP 10.4.32) is not production-equivalent**; no MariaDB syntax is used and the design is not shaped around it. Database-integrity/integration tests must run on MySQL 8.4, not SQLite (`15` §7, `18` §6). The compatibility review is `18_MYSQL_84_COMPATIBILITY_REVIEW.md`.

## 2. OBSOLETE decisions (made irrelevant by the resolutions above)

| ID | Was | Why obsolete |
|---|---|---|
| OD-05 | May a person receive a promotion more than once? | The mandatory introductory period is once per member by construction (only term 1 can be introductory; one identity per person, `UNIQUE(user_id)`; a person with a `memberships` row renews rather than reapplies — R-06). Marketing promotions are deferred; if ever defined, their per-person rule is part of that future design. |
| OD-12 | Approved-but-unpaid memberships that never activate | New members need no payment, so activation is never blocked on payment and no pending-activation member row exists (an `approved` application without a member is only the transient "awaiting activation" state — OD-23). The only analogue is an abandoned *renewal* term left `pending_payment`; it has no effect on validity (the previous term simply expires) and is covered by OD-21. |

## 3. OPEN decisions

None of these blocks generating migrations.

### OD-21 — Renewal details not yet confirmed (NEW)
* **Issue:** (a) when a renewal term starts (day after the current term ends vs the confirmation date); (b) whether/how long an expired member may still renew vs must reapply (see also OD-22 for deactivation after non-payment); (c) whether the category may change at renewal (the number's prefix is the original category).
* **Why it matters:** these are renewal-Action rules; the schema is neutral to all three (dates are set by the confirm action; category is on `memberships` and derivable per term via the plan).
* **Recommended defaults (until ACI decides):** (a) contiguous — start the day after the current `expires_on` when renewing while current, else on confirmation; (b) an expired member can renew with no time limit invented by us; (c) category is fixed; no change supported.
* **Schema change if answered differently:** none expected; (c) could add a nullable category snapshot to terms. **Resolve:** P4-late (renewal Actions), non-blocking.

### OD-22 — Non-payment: grace period and deactivation (NEW)
* **Confirmed:** if the renewal payment is not made, the membership eventually becomes **deactivated**.
* **Open (UNKNOWN — not invented):** how long after term expiry before deactivation (the grace period, if any); whether deactivation is automatic or an admin action; what deactivation changes (login, digital card, renewal still allowed); whether a stored member status (or `deactivated_at`) is wanted.
* **Why it matters:** these are Action/job rules. Until answered, "deactivated" is represented as "no current term" (derived from `membership_terms`); the membership number is retained for life either way.
* **Schema change if answered:** possibly an additive nullable `memberships.deactivated_at` or a `status` column; nothing needed now. **Resolve:** with OD-21 (renewal Actions), non-blocking.

### OD-23 — What triggers activation after approval (NEW)
* **Confirmed:** the workflow is Application → Admin approval → Payment/Free decision → Activation; approval is not immediate activation, and for an initial membership the decision is always "payment not required".
* **Open (UNKNOWN):** whether `ActivateMembership` runs automatically once the decision is recorded (e.g. in the same request, or by a queued job) or is a separate explicit admin action; and whether the payment/free decision is recorded automatically or by an admin.
* **Why it matters:** it decides only how quickly an `approved` application with no `memberships` row ("awaiting activation", reconciliation Q13) is resolved and who is notified. The schema is neutral: no new application status is required and `UNIQUE(membership_application_id)` guards double activation.
* **Recommended default (until ACI decides):** the decision is recorded as a history event and activation is a separate, idempotent Action triggered automatically after it (a queued job with the admin able to re-run it).

### OD-01 — System currency
* **Why:** `currency` appears on renewal plans, renewal terms (`fee_currency`), payments, refunds, bank accounts, orders; no DB default. The reference used LKR; the business brief uses £.
* **Schema change:** none (`CHAR(3)`, `DECIMAL(12,2)` fit GBP/LKR/USD). **Recommended:** one system currency in config, stated on every money row. **Resolve:** before seeding plans/bank accounts (not before migrations).

### OD-02 — Category names, **renewal** fees and durations
* **Why:** `membership_plans` now holds the paid renewal terms only (fee + normally 12 months). Fee values are data ACI must supply; nothing here fixes them. Exactly three categories, one active plan each (Professional stays one plan; frontend C-04 approved).
* **Schema change:** none. **Recommended:** one active plan per category. **Resolve:** before seeding.

### OD-03 — Sequence restarts each year — confirmed in principle
The business brief specifies a category/year sequence (`SSSS`), matching the (category, year) counter. Cap 9,999 per category-year (overflow = explicit error). No further schema effect.

### OD-06 — Applicant email already matches an existing user
* **Why:** decides whether `memberships.user_id` is ever NULL after activation and whether `account_setup_tokens.purpose='membership_link'` is used. **Recommended:** never touch an existing account's credentials; attach after the owner confirms via a link token. **Resolve:** P4-late.

### OD-07 — Pre-account applicant access — confirmed in principle
Signed, expiring links tied to the application (frontend C-05); no access by guessing `public_id`; no applicant account created for pre-activation access. With OD-10 the pre-activation applicant only **views status and responds to requests** (there is no pre-activation payment).

### OD-09 — Business timezone for `starts_on` / `expires_on` / `activated_on`
* **Why:** the activation *date* depends on the timezone that decides "today". **Recommended:** one configured business timezone applied only when deriving `DATE` columns; instants stay UTC. **Owner value needed** before seeding.

### OD-11 — Retention and deletion policy
No retention confirmed for documents, audit, history, tokens, email logs, webhook payloads, enquiries, referral emails. Mechanisms exist (purge tombstone, indexes); nothing is deleted by default. **Resolve:** Launch.

### OD-13 — Refunds of membership (renewal) payments
`payment_refunds` is generic; the effect of refunding a **renewal** payment on the renewal term/validity is undefined. **Recommended:** orders-only until ACI states a policy. **Resolve:** Later.

### OD-14 — Job applications: documents, statuses, duplicates, notifications
No document minimum/maximum; keep `UNIQUE(job_posting_id, user_id)`; statuses `applied…hired` (enum only); no applicant notification until requested. **Resolve:** P4-late / Later.

### OD-15 — Referral "contacts" and invitee personal data
`referral_invitations` is the minimum send-history table; it stores a non-member's email (privacy). Options: keep with a retention window (default), store a hash, or drop the table and rely on `email_logs` + throttling. **Resolve:** before the T3 migration.

### OD-16 — Partners / advisory members
No tables now; "advisory member" as a display variant needs one additive `team_members.group`; as a fourth membership category it would contradict the confirmed three-category rule (escalate). **Resolve:** Later.

### OD-17 — Event registration and a generic `Page` entity
Not created (no rules). **Resolve:** Later.

### OD-18 — Guest checkout
Nullable columns exist and stay unused. **Resolve:** Later (before Commerce).

### OD-19 — Tax on shop orders
No tax column; additive later. **Resolve:** Later.

### OD-20 — Aviation-eligibility evidence and extra fields
Application columns are the legacy-evidenced minimum; new mandatory questions are additive migrations; proof types/limits are configuration. **Resolve:** P4-late / Launch.

## 4. Accepted defaults with no owner input (schema-relevant, not OPEN)

| Item | Default |
|---|---|
| `users.name` | **Single field (confirmed)**; copied verbatim from `membership_applications.full_name`; no `first_name`/`last_name` |
| Skeleton migrations | The default `create_users_table` migration (`users`, `password_reset_tokens`, `sessions`) predates this design and has never been deployed; the implementation phase replaces the `users` part (or adds an alter migration if any shared DB already ran it) and keeps sessions/cache/queue tables on the `database` drivers |
| Audit log | Custom `audit_logs` table as designed (the package alternative would replace its shape — decide before the audit migration; default custom) |
| Account-setup token TTL | `membership_settings.account_setup_token_ttl_hours` default 72 (data) |
| Renewal reminder offsets | `config/membership.php`, recommended 30/7/0 days (configurable, not hard-coded in schedule logic) |
| Blog-comment moderation default | `pending` (configuration; no DB default) |
| Event content plain vs HTML | sanitised rich text (`MEDIUMTEXT` either way) |
| News/blog | kept separate |
| Order-number format | random prefixed code with `UNIQUE` backstop |
| Consent recording / JSON-LD / gateway choice | not modelled; additive later |
