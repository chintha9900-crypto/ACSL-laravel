# 16 — Database Decisions

Design only. Each decision states what was decided, **why**, and what was rejected. Decisions marked ▲ change or refine an earlier architecture document (see `01` §7). Open questions live in `17`.

---

## DD-01 Primary keys: `BIGINT UNSIGNED` internal, ULID `public_id` where exposed

**Decision.** Every domain table uses `id BIGINT UNSIGNED AUTO_INCREMENT`. Tables whose rows are addressed from URLs/emails (`membership_applications`, `documents`, `payments`, `orders`) additionally carry `public_id CHAR(26)` ULID, `UNIQUE`. Business identifiers (`membership_number`, `order_number`) are neither PKs nor FKs.

**Why.** InnoDB clusters rows on the PK and copies the PK into **every** secondary index and every FK column. An 8-byte monotonic integer keeps inserts append-only (no page splits) and keeps ~50 tables' worth of FK and index entries small; a 26-byte string (or 16-byte binary) PK multiplies that overhead everywhere, on shared hosting where buffer pool is small. ACI's volumes do not need globally unique or client-generated keys. Enumeration risk (guessing `/applications/17`) is real but is a defence-in-depth concern — Policies are the actual control — so exposing an unguessable identifier only where rows are addressed externally captures the benefit at the cost of one indexed column on four tables. `DATABASE.md` already recommended re-evaluating UUID-everywhere for exactly these reasons.

**Rejected.** *ULID/UUID as PK everywhere* (Laravel `HasUlids`/`HasUuids`): larger clustered and secondary indexes for no functional gain here; ULIDs are time-ordered so the fragmentation argument against UUIDv4 is weaker, but size still applies. *Integer ids in URLs*: enumerable. *Membership/order number as PK*: mutable business formats, generated late (a number exists only after activation), and would leak into every FK. *Laravel `notifications` UUID*: kept — it is the framework's own contract.

## DD-02 Naming conventions

`snake_case` plural tables; `{singular}_id` FKs; `*_at` timestamps, `*_on` dates, `is_*` booleans, `*_path` files, `*_amount` money, `*_key` generated uniqueness helpers. Job board is `job_postings` because Laravel's queue owns `jobs`. Reserved words avoided (`template_key`, not `key`; `position` retained from legacy is allowed unquoted as a MySQL non-reserved word, and Laravel quotes identifiers regardless).

**Why.** Zero surprise for Laravel relations/factories; names that read as facts (`expires_on` is a date, `expired_at` an instant).

## DD-03 Status strategy: VARCHAR + PHP enum, `CHECK` only for locked vocabularies

**Decision.** No native `ENUM`. Status columns are `VARCHAR(20–40)`; the PHP backed enum is authoritative. ACI-confirmed, closed vocabularies (application status, membership status, five payment states, seven payment statuses, eight order statuses, eight ledger types, three category codes, the two roles) also get `CHECK (col IN (...))` (`14` §3.1). Provisional/open vocabularies (job application statuses, enquiry status, comment status, gateway names, audit event names, history event names) do not.

**Why.** Native `ENUM` needs an `ALTER TABLE` per value, sorts by ordinal, and behaves inconsistently across MySQL/MariaDB. A VARCHAR keeps evolution cheap; the CHECK gives the *locked* sets a database-level backstop so a typo or a raw write cannot invent a state, while sets that will change are not frozen into DDL. Application-level enum casting alone would leave raw writes (tinker, imports, another tool) unprotected exactly where mistakes are most expensive (money, memberships).

**Rejected.** ENUM everywhere; CHECK on every status; no CHECKs at all.

## DD-04 Money: `DECIMAL(12,2)`, `CHAR(3)` currency with no default, snapshots

**Decision.** All money is `DECIMAL(12,2)`. Currency is `CHAR(3)` on the money-bearing row (`membership_plans`, `memberships.fee_currency`, `payments`, `payment_refunds`, `payment_bank_accounts`, `orders`); catalogue prices (`product_variants.price`, `coupons`, `shipping_methods`) carry no per-row currency and inherit the single shop currency at order time. **No database default currency.** Amounts are stored, never recomputed from current prices, after they are charged.

**Why.** `FLOAT` cannot represent 0.10 exactly; `DECIMAL(12,2)` holds up to 9,999,999,999.99, enough for LKR-scale amounts, and every currency in play (GBP, LKR, USD) has two decimals (a zero-decimal currency would need a rethink, recorded). The currency is genuinely unresolved (OD-01: the reference priced in LKR, the instructions used £); a default would silently encode a guess into data, so the application must state it at creation. A per-row currency on catalogue prices would invite mixed-currency carts that nothing in scope supports.

**Rejected.** Integer minor units (correct but hostile to reporting and admin SQL for a small club; DECIMAL is exact enough); `DECIMAL(10,2)` (legacy) — insufficient headroom if LKR is chosen.

## DD-05 JSON strategy: four uses, all provider- or diff-shaped

**Decision.** JSON columns exist only in `payments.metadata`, `payment_webhooks.payload`, `audit_logs.old_values`/`new_values`, plus the framework's `notifications.data`. Everything normalisable is relational.

**Why.** These four hold data whose shape is defined by someone else (a payment provider) or is inherently a per-event diff. MySQL JSON is materially weaker than Postgres JSONB for filtering/indexing (`DATABASE.md`), so JSON is kept away from anything queried. This reverses three legacy JSONB uses (`application_data`, `form_data`, `documents`) into columns/tables, and reverses the architecture's JSON `applicable_categories` (▲ C4) into a pivot.

## DD-06 Soft-delete strategy: two tables only

**Decision.** `SoftDeletes` on `products` and `product_variants` only. Documents use a **purge tombstone** (`purged_at/by/reason`). Everything else is status-flagged (`is_active`, `status`) or never removed.

| Table group | Approach | Reason |
|---|---|---|
| `products`, `product_variants` | soft delete | referenced by ledger, carts, historical order lines; must vanish from the catalogue without breaking history |
| Applications, memberships, payments, refunds, webhooks, orders, ledger, history, audit, email logs | never deleted | legal/financial/operational record |
| Documents | tombstone | privacy erasure must remove the *file* but keep proof that it existed; `SoftDeletes` would leave the file |
| Promotions, plans, bank accounts, coupons, templates, categories | `is_active` | referenced by history; "deleted" and "inactive" mean the same to the business |
| Users | `status = suspended` / anonymise | FK integrity of every history table |
| Content (blog, news, events, FAQs…) | hard delete allowed after confirmation | low-stakes, recoverable by republishing; unpublish via `status` covers the safe path |

**Why.** Soft deletes add a hidden filter to every query and unique-constraint ambiguity (a soft-deleted slug still blocks reuse). They are used only where they buy real referential safety.

## DD-07 Polymorphism strategy: two pairs, both justified

**Decision.** Polymorphic columns exist only in `audit_logs.subject_type/subject_id` (approved exception, ADR-15) and Laravel's `notifications.notifiable_type/notifiable_id` (framework contract; restricted to the `user` morph alias). Payments, documents, email logs, status history, and inventory use **typed nullable FKs**.

**Why.** Polymorphic pairs cannot carry FKs, so the database cannot detect orphans or protect history with `RESTRICT`. Where the set of targets is small and known (2 payment purposes, 3 document owners, 2 email-related entities, 1 anchor for membership history) typed FKs give real integrity for the price of one nullable column each. Audit genuinely targets "any row" and must survive deletes, so a morph is right there.

**Rejected.** `payable_type/payable_id`; `documentable_*`; a polymorphic `membership_status_history` (every event has an application anchor, so a typed FK plus optional `membership_id` suffices).

## DD-08 Membership-number sequence: locked counter row per (category, year)

**Decision.** `membership_number_sequences(category, year, last_number)` locked with `SELECT … FOR UPDATE` inside the activation transaction; number stored with its components; `UNIQUE` on the number and on `(category, year, sequence)`.

**Why.** The counter makes concurrency safe by serialising same-bucket activations on one row lock; being inside the activation transaction means a failed activation returns its number (no gaps caused by failures, no numbers consumed by rejected or unpaid applications, which never reach the table). Storing `number_year`/`number_sequence` lets the database independently guarantee the *sequence* is unique (the random `RR` digits would otherwise mask a duplicated sequence behind a "unique" full number) and lets CHECKs tie the string to its parts. Full mechanics: `04` §8.1.

**Rejected.** `MAX()+1` (forbidden; races). Global `AUTO_INCREMENT` + display prefix (one counter for all categories). Insert-and-retry on the unique constraint (works, but burns attempts under contention and interleaves poorly with the other writes in the activation transaction). Application-level mutex/cache lock (not safe across processes on shared hosting). `sequence per category only` vs per (category, year): (category, year) follows architecture `04` §4; annual reset semantics need confirmation (OD-03).

## DD-09 Historical snapshot strategy

**Decision.** History is protected two ways: (1) **snapshot** the facts a later edit could change onto the transaction row; (2) make decision/event tables **append-only**. Snapshots: plan fee/currency/duration and promotion name/free months on `memberships`; product/variant name, SKU, unit price, line total on `order_items`; shipping/coupon labels and customer contact on `orders`; addresses in `order_addresses`; the bank account link on `payments`; the applicant's reviewed identity on `membership_applications`. Master data that snapshots point at (`membership_plans`, `payment_bank_accounts`, promotions) is treated as immutable-once-used (`R-25`) and changed by replacement.

**Why.** Reconstructing "what did this member pay and why was it free" from *current* master data is impossible once a price or promotion changes. Snapshots cost a few columns; the alternative is versioned master tables everywhere (more complex, still needs the FK).

## DD-10 Payment-purpose integrity: two typed FKs + XOR CHECK

**Decision.** `payments.membership_id` and `payments.order_id`, both nullable `RESTRICT` FKs, plus `CHECK ((membership_id IS NULL) <> (order_id IS NULL))`, plus creation only through two narrow Actions, plus a reconciliation query.

**Why.** MySQL cannot make "exactly one of two FKs" structural, but a CHECK does it at the row level for every writer, the FKs give referential integrity, and the two-Action rule gives clear error messages and covers an engine that ignores CHECKs (< 8.0.16). `CHECK (amount > 0)` closes the fake-£0-payment hole for free.

**Rejected.** Polymorphic payable (no FK); a `purpose` column (redundant with the FKs — two facts that can disagree); two payment tables (duplicates the gateway/idempotency/webhook/refund machinery and splits reporting); a `NOT NULL` union key via generated column (cannot carry an FK).

## DD-11 Document ownership: exclusive arc in one table

Full comparison in `07` §2. **Decision:** one `documents` table, three typed owner FKs, `CHECK` that exactly one is set and matches `kind`, `CHECK disk <> 'public'`. ▲ (C2).

**Why.** Reusable metadata/purge/audit code written once; every owner is a real `RESTRICT` FK; adding an owner is additive. Polymorphism was rejected for lack of FKs; per-domain tables for duplication.

## DD-12 Audit design: three mechanisms with distinct jobs

**Decision.** `membership_status_history` (typed, per-application lifecycle, anonymous-applicant aware), `order_status_history` (typed, per-order), and `audit_logs` (cross-domain who/what/old/new/IP/UA/request id, the one polymorphic exception). All append-only. Written inside the action's transaction.

**Why.** The lifecycle histories need domain fields and relational integrity; the audit log needs to reference anything and outlive it. Collapsing them would either force polymorphism into the histories or force domain-specific columns into the audit log. `request_id` correlates several rows written by one action. Sensitive values are redacted before insert; passwords, tokens and file contents are never logged.

## DD-13 Inventory ledger: signed two-delta rows + CHECK-guarded projection

**Decision.** `inventory_transactions` carries `on_hand_delta` and `reserved_delta` (signed) per row, typed by the confirmed eight-value vocabulary with a per-type sign CHECK; `product_variants.quantity_on_hand/quantity_reserved` are projections updated in the same transaction and guarded by CHECKs; once-only generated key stops double reservation/sale/release.

**Why.** A single signed quantity cannot express "a sale that consumes a reservation" (both balances change). Two deltas make every event's effect explicit and let the ledger reproduce both balances. The projection exists so the **database** can refuse overselling (a CHECK cannot see other rows); the ledger remains the explanation. At a club shop's scale a `SUM()` alone would also work, so this is optional performance/safety — but the CHECK guard is worth the two columns.

**Rejected.** A bare mutable `stock_quantity` (forbidden; no audit trail). Pure `SUM()` with no projection (no DB-level oversell guard). Full warehouse/lot/valuation modelling (out of scope).

## DD-14 ▲ Application and membership separate; membership row created at approval

**Decision.** `membership_applications` and `memberships` are separate tables (1 : 0..1). The membership row is created **at approval** in `pending_activation` with no number/dates; `payments.membership_id` targets it.

**Why.** ACI's confirmed `Payment` field list references `membership_id`, and payment happens *before* activation, so a membership record must exist to be paid for. The rule that matters for numbering is preserved and strengthened: the number, dates, promotion and `active` status are set **only** at activation, enforced by a lifecycle CHECK. This resolves an inconsistency in Phase 2 (architecture `04` §1 vs `08` §5). Consequence documented: "a membership row exists" does not mean "is a member" — only `status='active' AND expires_on >= today` does.

**Rejected.** Payment pointing at the application (contradicts the confirmed Payment shape and the Phase 3 statement that a payment belongs to a membership or an order); creating the membership only at activation (leaves the payment with nothing to reference).

## DD-15 Roles: one column, no RBAC tables

Two flat roles as `users.role` with a CHECK. **Why.** ADR-05: nothing in the confirmed requirements needs more; a pivot adds a join to every check. The upgrade path is additive. Supabase's `user_roles` (a user may hold both roles) had no meaning in Laravel.

## DD-16 ▲ Users merged with profiles; user provisioned at activation

`auth.users` + `profiles` → `users` (ADR-04). Because `account_setup_tokens` must reference a user, the row is created at activation with `password NULL`, `status pending_setup` (▲ C5); a NULL password can never validate and login also requires `status='active'`. **Why.** Keeps `memberships.user_id` and the token FK meaningful and lets the setup flow *set* the first password rather than *create* the account, matching architecture `04` §7's intent while removing its inconsistency. **Rejected:** creating the user only when the password is set (token would need a nullable/absent user and an email key).

## DD-17 Promotions as data; categories as a pivot; snapshot on the membership

`membership_promotions` + `membership_promotion_category`; resolution query at activation; membership stores promotion id **and** snapshot. ▲ (C4). **Why.** Directly implements the confirmed rules without any hard-coded "six months"; the pivot replaces a JSON array so the category link is a real FK and indexable; the snapshot makes an edited promotion unable to rewrite history.

## DD-18 ▲ Email templates and logs are tables; notifications keep Laravel's shape

`email_templates` (content) and `email_logs` (delivery) are separate from `notifications` (in-app inbox). ▲ (C3). **Why.** The Phase 3 requirement that content is manageable without deployment overrides the Phase 2 default of Blade-only; the log gives operational proof that M5 (bank details) or M9 (setup link) was actually sent to a non-user applicant, which Laravel's `failed_jobs` alone cannot. Bodies are never compiled as Blade (template-injection risk) and are not stored in the log (PII).

## DD-19 No triggers/procedures; constraints where MySQL can, rules in Actions where it cannot

**Decision.** No triggers, stored procedures or events. Use NOT NULL, UNIQUE, FK, CHECK and generated-column keys for everything they can express; document the rest as application rules with reconciliation queries (`15`).

**Why.** Triggers are often unavailable or restricted on shared hosting, are invisible to Laravel tests and factories, and make behaviour depend on hidden DB state. The engine baseline (MySQL ≥ 8.0.16) is stated so the CHECK dependency is explicit and verifiable (OD-08).

## DD-20 Time: UTC `TIMESTAMP` for instants, `DATE` for business dates

Instants (`*_at`) are UTC `TIMESTAMP` (Laravel default). Business-calendar facts (`starts_on`, `expires_on`, promotion window) are `DATE`. **Why.** `DATE` is immune to the 2038 `TIMESTAMP` limit and matches how members and promotions reason ("valid until 14 April"). The business timezone used to decide *today* at activation is unresolved (OD-09).

## DD-21 Generated-column keys instead of partial unique indexes

MySQL has no partial unique index; "at most one open/active X" is a virtual generated column that is `NULL` when inactive, plus `UNIQUE`. Six uses (`14` §4). **Why.** Portable (MySQL 5.7+/MariaDB 10.2+), no triggers, cheap (virtual), and expresses exactly the business sentence. **Rejected:** application-only uniqueness (racy); soft-delete-style sentinel values (pollutes data).

## DD-22 Tables intentionally reduced or removed relative to the reference

| Reference | Outcome | Reason |
|---|---|---|
| `memberships` (System A) + `membership_applications` (System B) | one lifecycle, two tables | LEGACY_RISKS §1 |
| `user_roles` | `users.role` | DD-15 |
| `profiles` | merged into `users` | ADR-04 |
| `activity_logs` | `audit_logs` (+ histories) | never populated in legacy; needs actor/IP/old/new |
| `email_templates` | rebuilt, keyed by `template_key` | was unused, now required |
| `home_sections`, `membership_benefits` | deferred | not confirmed |
| `site_settings.*_url` | dropped | `social_links` is the source |
| `blog_comments.parent_comment_id` | dropped | unused |
| `blog_posts.reading_time` | dropped | derived |
| `subscribers.is_active` | `unsubscribed_at` | one source of truth + time |
| `shop_orders` (no payment, free-text address, `status` never updated) | `orders` + `order_addresses` + `order_status_history` + shared `payments` | commerce rebuilt, not ported |
