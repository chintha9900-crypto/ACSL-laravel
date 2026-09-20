# 17 — Database Open Decisions

Only genuinely unresolved issues **that affect the schema or its safe implementation** are listed. Each has a recommended default that the design already tolerates, so none blocks approval of the architecture; each must be resolved (or explicitly deferred with ACI's agreement) before the phase shown. Issues carried over from `docs/architecture/16_OPEN_DECISIONS.md` that have **no** schema effect are listed in §2 only so nothing is lost.

Resolution phases: **P4** = implementation-planning/migrations · **P4-late** = before that domain's migration is written · **Launch** = before go-live · **Later** = when the feature is scheduled.

## 1. Decisions with schema impact

### OD-01 — System currency
* **Why it matters:** `currency` is on plans, memberships (`fee_currency`), payments, refunds, bank accounts and orders, with **no database default**. The reference priced in LKR; the instructions' examples used £. Money precision (`DECIMAL(12,2)`), bank-detail fields (sort code/IBAN vs local formats) and the fee CHECKs depend on it. A mixed-currency future would change the order/cart model.
* **Options:** (a) single system currency in config; (b) per-record currency with multi-currency support; (c) GBP for membership, other for shop.
* **Recommended default:** (a) one currency from `config`, written explicitly on every money row; the column exists so that (b)/(c) need no migration.
* **Resolve:** P4 (needed before seeding plans/bank accounts).

### OD-02 — Final category names, prices, durations; tiers inside a category
* **Why it matters:** the schema has exactly one active plan per category (generated unique key). If Professional really has several sold tiers (legacy: Basic/Intermediate/Inner Circle), that key must be relaxed to per-tier and `membership_plans` gains a tier concept. Names/prices themselves are data.
* **Options:** (a) one plan per category (current); (b) multiple named tiers per category, applicant picks a plan.
* **Recommended default:** (a) — matches the confirmed "exactly three categories".
* **Resolve:** P4-late (before `membership_plans` migration).

### OD-03 — Does the four-digit sequence restart every year? Capacity
* **Why it matters:** the counter row is keyed (category, year) per architecture `04` §4, so `SSSS` restarts each January; `WORKFLOWS` only says "per category". If the sequence must be lifetime-per-category, the key becomes category-only and the ceiling is 9,999 memberships per category **ever**. Either way exhausting 9,999 must be an explicit error (already designed).
* **Options:** (a) reset yearly (current); (b) never reset.
* **Recommended default:** (a). Confirm before the first membership is issued — it cannot be changed after numbers exist without renumbering.
* **Resolve:** P4-late.

### OD-04 — Renewal model (number, overlap, pricing, lineage)
* **Why it matters:** the renewal *workflow* is deliberately undefined (`WORKFLOWS` §0.12). The schema needs to know: does a renewing member **keep** their membership number (then `membership_number` cannot live on each term row — it moves to a per-member identity table) or receive a new one at each activation (current design: new number, `UNIQUE` holds)? May a new term overlap an active one? Is a renewal priced as a plan fee, and may it get a promotion (see OD-05)?
* **Options:** (a) each term is a new membership with a new number, linked by `renews_membership_id` (current); (b) stable member number across terms (introduce `member_identities`, number moves there).
* **Recommended default:** (a) — it is the only reading consistent with "the number is generated at activation". (b) is a contained migration if chosen later, but cheaper now.
* **Resolve:** P4-late (before `memberships` migration).

### OD-05 — May a person receive a promotion more than once?
* **Why it matters:** as designed, a returning member reapplying while a promotion is active would get another free term. Nothing in the confirmed rules limits it. A per-person limit needs a query (`memberships.user_id` + `membership_promotion_id`) and possibly a `max_uses_per_person` column on `membership_promotions`.
* **Options:** (a) no limit; (b) first-time members only; (c) per-promotion `max_uses_per_person`.
* **Recommended default:** (c) added as a nullable column when confirmed — no impact on existing data.
* **Resolve:** Launch (before a second promotion or first renewals).

### OD-06 — Applicant email already matches an existing user
* **Why it matters:** decides whether `memberships.user_id` is ever NULL after activation and whether `account_setup_tokens.purpose='membership_link'` is used (architecture open decision #5). Default is that an activation **never** modifies an existing account's credentials.
* **Options:** (a) attach the membership to the existing user only after the owner confirms via a `membership_link` token; (b) attach immediately, no credential change; (c) block and route to an admin.
* **Recommended default:** (a), as the architecture proposes. Schema supports (a)–(c) unchanged.
* **Resolve:** P4-late.

### OD-07 — How a pre-account applicant reaches "respond to more details" and "submit payment evidence"
* **Why it matters:** the architecture never says (C9). Determines whether an `application_access_tokens` table is needed and what "owner" means for documents before `users.id` exists.
* **Options:** (a) signed, expiring URLs keyed on `membership_applications.public_id` — **no schema**; (b) DB-backed hashed access tokens (revocable, single-use) — new table; (c) create the user at approval instead of activation.
* **Recommended default:** (a). Revisit (b) if revocation of a leaked link is required.
* **Resolve:** P4-late.

### OD-08 — SiteGround database engine and version (and the local XAMPP engine)

* **Known local fact:** the XAMPP install on the development machine bundles **MariaDB 10.4.32**, and `.env` uses `DB_CONNECTION=sqlite`. The target is documented as "MySQL"; the local server is not MySQL 8. MariaDB 10.4 enforces CHECK constraints and supports generated columns, so the design should work, but Laravel 12 tests and migrations must be run against the *same engine family and version* as SiteGround, and the exact behaviours listed below verified on both.
* **Why it matters:** the design relies on **enforced CHECK constraints** (MySQL ≥ 8.0.16 or MariaDB ≥ 10.2.1), generated columns, and JSON. On MySQL 5.7 CHECKs are silently ignored; on MariaDB `JSON` is `LONGTEXT` (no `JSON_VALID` by default). Payment-purpose XOR, no-£0-payment, membership-number shape and non-negative stock would fall back to application guards + reconciliation only.
* **Options:** (a) confirm MySQL 8.0.16+ (or MariaDB 10.5+) and keep the design; (b) if older, keep application guards and add reconciliation as a scheduled hard check.
* **Recommended default:** (a); verify with `SELECT VERSION()` on the SiteGround plan **and** in CI (tests must run on MySQL, not SQLite — `database/database.sqlite` exists in the skeleton). The first implementation spike should create one throw-away table exercising the three engine behaviours this design leans on — a CHECK on a column that carries a `RESTRICT` FK, a `UNIQUE` index on a `VIRTUAL` generated column, and a CHECK using `REGEXP`/`SUBSTRING` — so any surprise is found before 54 migrations are written.
* **Resolve:** P4 (first task).

### OD-09 — Business timezone for `starts_on` / `expires_on`
* **Why it matters:** the activation *date* depends on which timezone decides "today" (ACI's members span time zones; the entity and its cron may not). A membership activated at 23:30 UTC may be "tomorrow" locally, shifting expiry by a day.
* **Options:** (a) UTC date; (b) a configured business timezone (`config('membership.timezone')`).
* **Recommended default:** (b), one value, applied only when deriving `DATE` columns; instants stay UTC.
* **Resolve:** P4-late.

### OD-10 — Promotion timing on the paid path
* **Why it matters:** eligibility is decided "at activation". On the paid path activation follows payment confirmation, possibly weeks after approval; a promotion could have started in between, making the applicant eligible for a free term after paying. The schema forbids the inconsistent result (free ⇔ promotion; free ⇒ no payment) but cannot pick the policy.
* **Options:** (a) resolve the promotion **once**, at the approval decision; if none, the member is on the paid path and stays there; (b) re-resolve at post-payment activation and refund/credit if one now applies.
* **Recommended default:** (a). Simplest, deterministic, no automatic refunds.
* **Resolve:** P4-late (before the activation Action is designed).

### OD-11 — Retention and deletion policy
* **Why it matters:** no retention is confirmed for aviation proof, payment evidence, job documents, audit logs, status history, email logs (third-party addresses), webhook payloads (payer PII), setup tokens, contact enquiries, referral invitee emails. The schema provides mechanisms (purge tombstone, `created_at` indexes) but **deletes nothing by default**.
* **Options:** per-class retention windows enforced by scheduled prune jobs; or indefinite retention.
* **Recommended default:** keep everything until ACI sets windows; prune only setup tokens/expired sessions/queue data automatically.
* **Resolve:** Launch.

### OD-12 — Approved-but-unpaid memberships that never activate
* **Why it matters:** because a membership row exists from approval (DD-14), an applicant who never pays leaves a `pending_activation` row indefinitely. There is no confirmed abandonment rule and no `cancelled` status.
* **Options:** (a) leave as-is, filter by status; (b) add `cancelled` status + a timeout job; (c) allow admin cancellation only.
* **Recommended default:** (a) now; (c)/(b) additive (a new status value + timestamp) when ACI defines the rule.
* **Resolve:** Later.

### OD-13 — Refunds of membership payments
* **Why it matters:** `payment_refunds` is generic, but the effect of refunding a **membership** payment (revoke the membership? shorten it? nothing?) is undefined and would need a status or timestamp on `memberships`.
* **Options:** (a) not supported — refunds are orders-only; (b) refund without changing the membership; (c) refund ends the membership (new status).
* **Recommended default:** (a) until ACI states a policy.
* **Resolve:** Later.

### OD-14 — Job applications: documents, status set, duplicates, notifications
* **Why it matters:** the capability "job application documents" is requested but its rules (count, required/optional, formats, add-after-applying) are not; the status vocabulary is unconfirmed (UI: `applied, reviewing, shortlisted, rejected, hired`); withdrawal/re-application is undefined, which decides whether `UNIQUE(job_posting_id, user_id)` stays absolute; applicant notification on status change is unconfirmed.
* **Recommended default:** no schema minimum/maximum for documents; keep the unique key; statuses as listed (enum-only, no CHECK); no notification until requested (would use an `email_templates` row).
* **Resolve:** P4-late (for `job_applications`) / Later (rules).

### OD-15 — Referral "contacts": history vs address book; invitee personal data
* **Why it matters:** the current table (`referral_invitations`) is the minimum for a *send history*. An editable contact book would be a different table. Either way it retains a **non-member's email** without consent.
* **Options:** (a) keep invitee emails with a retention window; (b) store a hash (dedupe/rate-limit only); (c) no table — rely on `email_logs` and throttling.
* **Recommended default:** (a) pending a privacy decision; do **not** build an address book.
* **Resolve:** P4-late.

### OD-16 — Partners and advisory members
* **Why it matters:** neither concept exists in the reference. "Advisory members" as a `team_members` display variant needs one additive `group` column; as a fourth membership category it would contradict the confirmed three-category rule and affect `membership_categories`' CHECK and the number format.
* **Recommended default:** no tables/columns now; if "team variant", add `team_members.group`; "partners" would be another uniform CMS table.
* **Resolve:** Later (before those pages are built). Escalate to ACI if "advisory member" means a membership category.

### OD-17 — Event registration and a generic `Page` entity
* **Why it matters:** both are new scope with no rules. Designing them now would invent capacity/waitlist/payment semantics and a page-management surface. Minimal safe shape for `pages` is documented (`10` §14); `event_registrations` is deliberately not sketched.
* **Recommended default:** not created; static/legal pages remain developer-maintained views.
* **Resolve:** Later.

### OD-18 — Guest checkout
* **Why it matters:** `carts.guest_token`, nullable `orders.user_id`/`payments.user_id`, and an order-confirmation access rule depend on it.
* **Recommended default:** not enabled; the nullable columns cost nothing and can stay unused.
* **Resolve:** Later (before Commerce build).

### OD-19 — Tax on shop orders
* **Why it matters:** `orders` has no tax column; the total CHECK is `subtotal − discount + shipping`.
* **Options:** add `tax_amount DECIMAL(12,2) NOT NULL` and extend the CHECK if tax is required; otherwise nothing.
* **Recommended default:** none now (additive later).
* **Resolve:** Later.

### OD-20 — Aviation-eligibility evidence and fields
* **Why it matters:** ACI's accepted proof types, per-category rules and any extra eligibility questions are unconfirmed. The application columns (`04` §5) are the legacy-evidenced minimum; new mandatory questions need additive columns, and the `documents` MIME/size limits are configuration (not schema).
* **Recommended default:** proceed with the current columns; add columns via migration when ACI finalises the form.
* **Resolve:** P4-late / Launch.

## 2. Carried forward from Phase 2 — no schema impact

| Architecture item | Effect on schema |
|---|---|
| Account-setup token TTL (24–72 h) | `membership_settings.account_setup_token_ttl_hours` is data; placeholder default 72. |
| Blog-comment moderation default | `blog_comments.status` has no DB default; configuration. |
| Event content plain vs HTML | column type identical (`MEDIUMTEXT`). |
| News/blog merge | kept separate; a merge would be an additive `type` column later. |
| Payment gateway choice | `gateway` is an open VARCHAR; webhook/refund tables are gateway-neutral. |
| Queue driver / hosting tier | framework tables only. |
| Package vs custom audit log (`spatie/laravel-activitylog`) | **Decide before writing the audit migration** — choosing the package replaces `audit_logs`' shape. |
| Consent recording for membership rules / newsletter | not modelled (unconfirmed); additive columns (`terms_accepted_at`, subscription source/IP) if required. |
| Order-number format | implementation choice (random prefixed code recommended); `UNIQUE` backstop. |
| Sequence "next" vs "last" naming | schema stores `last_number`; purely internal. |
