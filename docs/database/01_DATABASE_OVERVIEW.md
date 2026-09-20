# 01 — Database Overview

Phase: **DATABASE ARCHITECTURE (Phase 3)** — design only. No migrations, models, seeders, factories, controllers, routes, views, Livewire components, jobs, notifications or any other application code have been created. The database described here **has not been implemented**.

## 1. Inputs and authority

Authoritative sources, in order of precedence:

1. The Phase 3 instructions (membership rules, membership-number format, payment rules, promotion rules, inventory/order vocabularies).
2. `docs/architecture/*` (Phase 2 — approved target architecture).
3. `docs/reverse-engineering/*`, in particular `WORKFLOWS.md` §0 (ACI-confirmed membership specification).
4. The reference Supabase migrations — **reference only**. Nothing in this design is a PostgreSQL→MySQL conversion; where the reference schema is used as evidence (content tables) it is called out.

Where the Phase 3 instructions and Phase 2 architecture disagree, this design follows Phase 3 and records the disagreement in §7 below. Where the architecture is internally inconsistent, the inconsistency is resolved explicitly and recorded there too.

## 2. Target engine baseline

**Implementation target:** Laravel 12 (12.x) on PHP 8.2+ with MySQL, XAMPP for local development, SiteGround for production. Current local environment: PHP 8.2.12, Laravel 12.69.2 (locked project baseline). The database design is independent of the Laravel major version; nothing below requires Laravel 13 or PHP 8.3. Laravel 12 has no dedicated CHECK-constraint helper on its schema builder, so the implementation phase will add CHECK constraints with raw `DB::statement()` calls inside migrations (generated columns use the builder's `virtualAs`/`storedAs`). This is an implementation note, not a design change.

**Local database note (environment fact, no design change):** the XAMPP installation on the development machine currently bundles **MariaDB 10.4.32** (`mysqld --version`), and `.env` currently has `DB_CONNECTION=sqlite`. MariaDB 10.4 enforces CHECK constraints and supports generated columns and JSON-as-LONGTEXT, but it is not MySQL 8. See OD-08 in `17_DATABASE_OPEN_DECISIONS.md`.

| Item | Baseline | Why it matters |
|---|---|---|
| Engine | MySQL **8.0.16 or newer** (InnoDB) | CHECK constraints are only *enforced* from 8.0.16; MySQL 5.7 parses and **silently ignores** them. Several integrity rules in this design (payment purpose XOR, non-negative money, membership-number shape) use CHECKs. |
| MariaDB | 10.2.1+ enforces CHECK; JSON is an alias of LONGTEXT | Acceptable, but must be verified. See `17_DATABASE_OPEN_DECISIONS.md` OD-08 — the SiteGround engine/version is not confirmed. |
| Character set | `utf8mb4`, collation `utf8mb4_unicode_ci` | Case-insensitive uniqueness for emails, slugs, coupon codes and SKUs. |
| Binary-safe columns | `ascii_bin` for token hashes, ULIDs, membership numbers, category codes | Exact-match, case-sensitive, smaller indexes. |
| Generated columns | Used for a small number of "at most one open/active row" unique keys | Portable across MySQL 5.7+/8.x and MariaDB 10.2+; avoids triggers (which are often unavailable on shared hosting). |
| Triggers / stored procedures | **None** | Not portable to SiteGround shared hosting, invisible to Laravel tests, and unnecessary given CHECKs + application actions. |

Every rule that depends on CHECK enforcement also has an application-layer guard and a reconciliation query (`15_DATA_INTEGRITY_RULES.md`), so a lower engine version degrades protection but does not make the design unsafe.

## 3. Global conventions

### 3.1 Primary keys and public identifiers

| Rule | Decision |
|---|---|
| Internal PK | `id BIGINT UNSIGNED AUTO_INCREMENT` (Laravel `id()`), on every domain table. |
| Public identifier | Tables whose rows appear in URLs or emails carry `public_id CHAR(26)` (ULID, `ascii_bin`, `UNIQUE`): `membership_applications`, `documents`, `payments`, `orders`. The internal `id` is never exposed. This is defence in depth against enumeration — **policies remain the real access control**. |
| Human-readable business identifiers | `memberships.membership_number`, `orders.order_number`. They are **never** primary or foreign keys. |
| Exceptions | Singleton tables (`site_settings`, `membership_settings`): `id TINYINT UNSIGNED` fixed at `1`. Pivot tables: composite PK. Framework tables keep Laravel's own keys (`notifications.id` UUID, `password_reset_tokens.email`, `sessions.id`). |

Rationale and rejected alternatives (UUID/ULID as PK) are in `16_DATABASE_DECISIONS.md` DD-01.

### 3.2 Naming

Tables: `snake_case`, plural (`membership_applications`); pivots singular-alphabetical per Laravel unless a domain name reads better (`membership_promotion_category`). Columns: `snake_case`; FKs `{singular}_id`; timestamps `*_at`; calendar dates `*_on`; booleans `is_*`/`has_*`; file locations `*_path`; money `*_amount`; generated uniqueness helpers `*_key`. The legacy queue table name `jobs` is taken by Laravel's queue, so the job board is `job_postings` / `job_applications`.

### 3.3 Data types

| Concern | Type |
|---|---|
| Money | `DECIMAL(12,2)`, always. Never FLOAT/DOUBLE. All ACI currencies in scope (GBP, LKR, USD) use two decimals. |
| Currency | `CHAR(3)` ISO 4217. **No database default** — the system currency is unresolved (OD-01). |
| Timestamps | `TIMESTAMP` in UTC (`app.timezone = UTC`). Laravel `timestamps()` (nullable, always populated by Eloquent). Append-only tables use `created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP` and no `updated_at`. |
| Calendar dates | `DATE` for business dates (membership `starts_on` / `expires_on`, promotion windows). `DATE` has no 2038 limit; `TIMESTAMP` does, so no far-future business date is stored as a timestamp. |
| Booleans | `TINYINT(1) NOT NULL DEFAULT …` |
| Status / type vocabularies | `VARCHAR(20–40)` + PHP backed enum. See §3.5. |
| Short free text | `VARCHAR(n)` with an explicit `n` matching the validation rule. |
| Long text | `TEXT` / `MEDIUMTEXT` (sanitized rich text). |
| JSON | Only where the content is genuinely provider-shaped or a diff: `payments.metadata`, `payment_webhooks.payload`, `audit_logs.old_values/new_values`, `notifications.data` (framework). |

### 3.4 Foreign-key policy (applies to every FK in the schema)

* **ON UPDATE: `RESTRICT`** everywhere. Surrogate keys never change. (This also keeps CHECK constraints legal — MySQL forbids CHECKs on columns that carry `CASCADE`/`SET NULL` referential actions.)
* **ON DELETE: `RESTRICT`** for anything historical, financial, legal, or referenced by a lifecycle: applications, memberships, payments, orders, documents, ledger, history, audit, users.
* **ON DELETE: `CASCADE`** only for pure child/link rows with no independent value: pivot rows, `cart_items → carts`, `product_images → products`, `blog_comments → blog_posts`, `membership_promotion_category → membership_promotions`.
* **ON DELETE: `SET NULL`** only for optional editorial attribution (`blog_posts.author_id`, `created_by` columns) and optional taxonomy (`blog_posts.blog_category_id`, `products.product_category_id`).
* **Users are never hard-deleted.** Erasure requests are handled by anonymisation (`users.status`), which is why `RESTRICT` on `users` is safe and why audit/financial rows can hold real user FKs.

### 3.5 Status columns: VARCHAR + PHP enum, CHECK only for locked vocabularies

Native MySQL `ENUM` is rejected (an `ALTER TABLE` per new value, ordinal-index pitfalls, poor portability). Statuses are `VARCHAR` + PHP backed enum cast. For vocabularies that ACI has **confirmed as closed** the column also carries `CHECK (col IN (...))` so that a typo or a raw write cannot invent a state; provisional vocabularies (job application status, enquiry status, comment status, etc.) rely on the enum only. The list of CHECK-guarded vocabularies is in `14_INDEX_AND_CONSTRAINT_STRATEGY.md` §3.

### 3.6 Soft deletes — used in exactly two tables

`products` and `product_variants` (rows are referenced by the stock ledger, carts and historical order items and must disappear from the catalogue without breaking history). Everything else uses a **status/`is_active` flag** (content, promotions, plans, bank accounts, coupons) or is **never deleted** (applications, memberships, payments, orders, documents, history, audit). Documents use a *purge tombstone* (`purged_at`) rather than Laravel `SoftDeletes` — see `07_DOCUMENT_SCHEMA.md`.

### 3.7 "At most one open/active row" keys

MySQL has no partial unique indexes. Where the business says "at most one X per Y while active", the schema uses a **virtual generated column that is `NULL` when the row is inactive**, with a `UNIQUE` index on it (multiple `NULL`s are allowed). Used for: one active plan per category, one open application per email, one open details-request per application, one live setup token per user/purpose, one active bank account per currency, and once-only inventory events per order item. Each is named `*_key`.

## 4. Domain and table map

Counts are of **designed application tables** (excluding pure framework/infrastructure tables). Full detail in `02_ENTITY_CATALOG.md`.

| Domain | Doc | Tables |
|---|---|---|
| Identity & access | `03` | `users`, `account_setup_tokens` |
| Membership | `04` | `membership_categories`, `membership_plans`, `membership_settings`, `membership_applications`, `membership_details_requests`, `memberships`, `membership_number_sequences`, `membership_status_history` |
| Promotions | `05` | `membership_promotions`, `membership_promotion_category` |
| Payments | `06` | `payment_bank_accounts`, `payments`, `payment_refunds`, `payment_webhooks` |
| Documents | `07` | `documents` |
| Notifications | `08` | `notifications` (framework-shaped), `email_templates`, `email_logs` |
| Audit | `09` | `audit_logs` |
| Content / CMS | `10` | `blog_categories`, `blog_posts`, `blog_tags`, `blog_post_blog_tag`, `blog_comments`, `news_items`, `event_listings`, `faqs`, `testimonials`, `team_members`, `hero_banners`, `site_settings`, `social_links`, `footer_links`, `seo_pages`, `subscribers`, `contact_enquiries` |
| Jobs & community | `11` | `job_postings`, `job_applications`, `referral_invitations` |
| E-commerce | `12` | `product_categories`, `products`, `product_variants`, `product_images`, `inventory_transactions`, `carts`, `cart_items`, `coupons`, `shipping_methods`, `orders`, `order_items`, `order_addresses`, `order_status_history` |
| **Total** | | **54** |

Framework/infrastructure tables that also exist but are **not** designed here (Laravel-standard shape, listed for completeness): `migrations`, `cache`, `cache_locks`, `jobs` (queue), `job_batches`, `failed_jobs`, `password_reset_tokens`, and `sessions` (only if the database session driver is chosen). `database/migrations/` currently holds Laravel's default `users`, `cache` and `jobs` migrations; the default `users` shape differs from `03_IDENTITY_SCHEMA.md` and will be superseded in the implementation phase — it has not been touched here.

Deferred / not proposed (each decided KEEP / REWORK / MERGE / DEFER in the relevant domain doc): `pages`, `event_registrations`, `partners`, `home_sections`, `membership_benefits`, `roles`/`permissions`/pivots, user address book, product option matrices, shipping zones, returns/RMA, tax tables.

## 5. Structural decisions in one page

1. **Application ≠ Membership.** `membership_applications` (one row per attempt, never overwritten) and `memberships` (one row per term) are separate tables, linked 1:1 by `memberships.membership_application_id UNIQUE`.
2. **A membership row exists from approval, not only from activation** (status `pending_activation`, no number). This lets `payments.membership_id` point at something real while payment is outstanding (resolves a Phase 2 inconsistency, §7 C1). The membership number, dates and promotion are set **only** at activation, by a single guarded `UPDATE`.
3. **Promotion is data.** `membership_promotions` + a category pivot; the membership stores a **snapshot** of what was applied. No promotion logic in the memberships table beyond that snapshot.
4. **Membership number** = component columns (`number_year`, `number_sequence`) + the 9-character string, backed by `membership_number_sequences` locked with `SELECT … FOR UPDATE`. `UNIQUE(category, year, sequence)` guarantees the *sequence* is never duplicated even if the random digits differ.
5. **Payments are one table** with two nullable purpose FKs and a CHECK that exactly one is set; **no `£0` payment can exist** (`CHECK amount > 0`).
6. **Documents are one table** with an *exclusive arc* of three typed FKs (application / payment / job application) — no polymorphism.
7. **Polymorphism appears in exactly two places**: `audit_logs.subject_*` (approved exception) and Laravel's `notifications.notifiable_*` (framework contract, restricted to `User`). Everything else uses typed FKs.
8. **Inventory** is an append-only signed ledger with a cached `quantity_on_hand` / `quantity_reserved` projection on the variant, protected by CHECKs so stock cannot go negative even if application code is wrong.
9. **History is snapshotted**: plan fee/duration and promotion name/free months on the membership; product name/SKU/price and addresses on the order; the bank account used on the payment.

## 6. How to read the rest of the set

`02` table catalog · `03`–`12` per-domain schemas (columns, FKs, indexes, constraints, rationale) · `13` relationship map · `14` index & constraint strategy · `15` integrity rules (DB-enforced vs application-enforced) · `16` decisions · `17` open decisions.

## 7. Reconciliations with earlier documentation

These are contradictions or gaps found while reading the approved documentation. Each has a resolution used in this design; none is silently absorbed.

**Reference convention.** In this set, a bare number such as `04` or `15` means the corresponding file in `docs/database/`. References to the earlier phase are written "architecture `NN`" or by full file name. **In the "Sources" column of the table below only**, bare numbers such as `04` §1 or `08` §5 refer to `docs/architecture/` (Phase 2) files, not to this set.

| # | Finding | Sources | Resolution in this design |
|---|---|---|---|
| C1 | `Membership` is "created only on activation", yet `Payment.membership_id` must point at a "not-yet-activated membership record" and the payment is created at approval. | `04` §1/§6 vs `08` §1/§5 | `memberships` row is created **at approval** with `status = pending_activation`, `payment_status` set, and **no number/dates**. ACI's confirmed `Payment` field list (`membership_id`) is honoured. "Number only at activation" is unaffected. |
| C2 | `02` §11 says no single `Document` entity should exist; Phase 3 requires a reusable document-metadata table. | `02` §11, `07` §6 vs Phase 3 | One `documents` table using an exclusive arc of typed FKs (not polymorphic). Public images are still stored as path columns on their own rows. |
| C3 | `email_templates` "not built — every notification is a Blade view". Phase 3: templates must be manageable without embedding content in code. | architecture `06` §5, architecture `16` #22 vs Phase 3 | `email_templates` table designed (membership M1–M11 + referral invite). Branding layout stays a Blade component. Content is substituted with an allow-listed placeholder engine, **never compiled as Blade**. |
| C4 | Promotion `applicable_categories` queried with `whereJsonContains`. Phase 3 forbids arrays-in-columns and generic JSON. | `04` §5 vs Phase 3 | Normalised pivot `membership_promotion_category`. |
| C5 | `AccountSetupToken` "belongs to User", but the `User` is only "created when the password is set". | `04` §1 vs §7 | The `users` row is provisioned **at activation** with `password NULL` and `status = pending_setup`; the token references it. Existing-email collision handled per `04` §8 (OD-06). |
| C6 | `04` §4 stores `next_value` (the next integer). | `04` §4 | Stored as `last_number` (the last issued value); "next" is derived under the row lock. Avoids off-by-one and makes `0` = "none issued". |
| C7 | Phase 2 places category `duration_months` on the category; Phase 3 separates categories from commercial plans. | `04` §9 vs Phase 3 items 5–6 | `membership_categories` = identity (S/P/V); `membership_plans` = fee + currency + duration, one active per category. |
| C8 | Payment rejection sets `Payment.status = failed`, but the *same* row is then resubmitted; `failed` is otherwise terminal for gateways. | `08` §5 vs §3 | Allowed transition `failed → processing` **only for the `manual_bank_transfer` gateway**, documented in `06_PAYMENT_SCHEMA.md`. |
| C9 | The architecture never states how a pre-account applicant (no `User` yet) reaches the "more details" or "submit payment evidence" screens. | `04` §2, `08` §5 | Gap. Default: signed, expiring URLs keyed on `membership_applications.public_id` (no schema needed). OD-07. |
| C10 | `CLAUDE.md` and Phase 2 disagreed on the PHP/Laravel target (PHP 8.2 vs 8.3+; Laravel 12 in `composer.json` vs "Laravel 13" in the Phase 2 overview). | `CLAUDE.md`, `01_ARCHITECTURE_OVERVIEW.md`, `composer.json` | **Resolved by a final project decision:** the locked baseline is **Laravel 12 (12.x) / PHP 8.2+** (current local: Laravel 12.69.2, PHP 8.2.12). `CLAUDE.md` and the architecture documents were aligned; this design has no Laravel-13 or PHP-8.3 dependency. |
| C11 | `CLAUDE.md` names the reference app path `C:\Projects\aci-reference`; the actual path used by the architecture is `C:\xampp\htdocs\aviationclub\aci-referance`. | `CLAUDE.md` vs `01` §1 | Not changed; reported. |
| C12 | Legacy `site_settings.site_name` defaults to "ACSL Aviation Club" and contact copy uses `acsl.lk`; ACI's domain is `aviationclub.lk`. | reference migration | No legacy default carried; `site_name` is required data. |
| C13 | Sequence scope: `WORKFLOWS.md` says "per category"; `04` §4 keys the counter by (category, year). | `WORKFLOWS` §0.11 vs `04` §4 | Keyed by (category, **year**) as in `04`; annual reset semantics flagged for confirmation (OD-03). |
