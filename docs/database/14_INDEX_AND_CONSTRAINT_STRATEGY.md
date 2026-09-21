# 14 — Index and Constraint Strategy

Design only. This document consolidates the index, uniqueness, CHECK and generated-key decisions made in `03`–`12` and states the principles and engine constraints behind them. Where this document and a domain document ever differ, the domain document (which has the column-level detail) governs and this file must be corrected.

## 1. Index principles

1. **Index for a named access path**, not for a column. Every non-unique index below is justified by a query in a workflow (review queue, expiry job, unread count, webhook lookup…).
2. **Foreign-key columns are always indexed** (InnoDB requires it; Laravel's `foreignId()->constrained()` creates it). They are not repeated in the tables below unless a composite index covers them.
3. **Composite indexes lead with the equality column, then the range/sort column** (`status, submitted_at`; `email, status, decided_at`).
4. **Unique indexes carry business invariants** (idempotency, "exactly one", identifiers); they are the last line of defence behind application checks.
5. **No index on low-cardinality booleans alone.** `is_active` appears only as the leading column of a composite with the ordering column (`is_active, display_order`).
6. **Append-only high-volume tables** (`audit_logs`, `inventory_transactions`, `membership_status_history`, `email_logs`) carry the minimum indexes needed for their read paths; every extra index slows every insert.
7. **Prefix lengths:** InnoDB `DYNAMIC` row format allows 3072-byte keys; with `utf8mb4` a `VARCHAR(255)` is 1020 bytes, so no prefix indexes are needed.

## 2. Unique indexes (all business-significant)

| Table | Unique key | Invariant it protects |
|---|---|---|
| `users` | `email` | one account per email |
| `account_setup_tokens` | `token_hash` | token lookup; no duplicate hashes |
| `account_setup_tokens` | `live_key` (generated) | ≤ 1 live token per (user, purpose) |
| `membership_categories` | `code` (+ CHECK S/P/V) | exactly three categories |
| `membership_plans` | `active_category_key` (generated) | ≤ 1 active plan per category |
| `membership_applications` | `public_id` | public identifier |
| `membership_applications` | `open_email_key` (generated) | ≤ 1 open application per email |
| `membership_details_requests` | `open_request_key` (generated) | ≤ 1 unanswered request per application |
| `memberships` | `membership_application_id` | 1 application → ≤ 1 member |
| `memberships` | `user_id` | one person = one member for life |
| `memberships` | `membership_number` | **complete number unique** (issued once, never changes at renewal) |
| `memberships` | `(membership_category_id, number_year, number_sequence)` | **sequence never duplicated**, independent of the random digits |
| `memberships` | `verification_token` | future QR token |
| `membership_number_sequences` | `(membership_category_id, sequence_year)` | one counter per category/year; the lock target |
| `membership_terms` | `(membership_id, term_no)` | one row per term number (term 1 = the single introductory term) |
| `membership_terms` | `open_renewal_key` (generated) | ≤ 1 renewal awaiting payment per member |
| `payment_bank_accounts` | `active_currency_key` (generated) | ≤ 1 active account per currency |
| `payments` | `public_id` | |
| `payments` | `(gateway, idempotency_key)` | idempotent payment creation |
| `payment_refunds` | `idempotency_key`; `(payment_id, gateway_reference)` | idempotent refunds; provider refund id per payment |
| `payment_webhooks` | `(gateway, event_id)` | **webhook idempotency** |
| `documents` | `public_id`; `(disk, storage_path)` | identifier; one row per stored file |
| `email_templates` | `template_key`; `reference_code` | stable keys; M-codes |
| `blog_categories`, `blog_tags` | `slug` | |
| `blog_posts`, `news_items`, `event_listings`, `products`, `product_categories` | `slug` | friendly URL, real DB constraint |
| `blog_post_blog_tag` | PK `(post, tag)` | |
| `seo_pages` | `page_name` | |
| `subscribers` | `email` | |
| `job_applications` | `(job_posting_id, user_id)` | no duplicate application |
| `product_variants` | `sku` | |
| `inventory_transactions` | `once_key` (generated) | one reservation/sale/release per order item |
| `carts` | `user_id`; `guest_token` | one cart per member |
| `cart_items` | `(cart_id, product_variant_id)` | one line per variant |
| `coupons` | `code` | case-insensitive |
| `orders` | `public_id`; `order_number` | identifiers |
| `order_items` | `(order_id, product_variant_id)` | one line per variant |
| `order_addresses` | `(order_id, type)` | one shipping, ≤ one billing |
| `site_settings`, `membership_settings` | PK `id` with `CHECK (id = 1)` | singleton |

## 3. CHECK constraints

### 3.1 Closed vocabularies guarded by `CHECK (col IN (...))`

Only ACI-confirmed or legacy-closed sets. Columns are `VARCHAR` declared `ascii` + `ascii_bin` (exact-match, so the CHECK is not case-insensitive — `18` A-5); the PHP backed enum is the primary definition, the CHECK is a backstop against typos and raw writes.

| Column | Values |
|---|---|
| `users.role` | `member`, `admin` |
| `users.status` | `pending_setup`, `active`, `suspended` |
| `account_setup_tokens.purpose` | `account_setup`, `membership_link` |
| `membership_categories.code` | `S`, `P`, `V` |
| `membership_applications.status` | `submitted`, `more_details_required`, `approved`, `rejected` |
| `membership_terms.term_kind` | `introductory`, `renewal` |
| `membership_terms.status` | `pending_payment`, `active`, `expired` |
| `membership_terms.payment_status` | `payment_not_required`, `payment_pending`, `payment_confirmation_submitted`, `payment_confirmed`, `payment_rejected` |
| `membership_status_history.actor_type` | `applicant`, `admin`, `member`, `system` |
| `payments.status` | `pending`, `processing`, `paid`, `failed`, `cancelled`, `refunded`, `partially_refunded` |
| `payment_webhooks.processing_status` | `received`, `processing`, `processed`, `failed`, `ignored` |
| `documents.kind` | `aviation_proof`, `payment_evidence`, `job_application_document` |
| `documents.visibility` | `owner_and_admin`, `admin_only` |
| `audit_logs.actor_type` | `user`, `system`, `guest` |
| `email_logs.status` | `queued`, `sent`, `failed` |
| `products.visibility` | `public`, `members` |
| `job_postings.location_type` | `local`, `overseas` |
| `coupons.discount_type` | `percentage`, `fixed` |
| `orders.status`, `order_status_history.to_status` (and non-null `from_status`) | `pending_payment`, `paid`, `processing`, `packed`, `shipped`, `delivered`, `cancelled`, `refunded` |
| `order_addresses.type` | `shipping`, `billing` |
| `inventory_transactions.type` | `purchase`, `sale`, `return`, `adjustment`, `damage`, `loss`, `reservation`, `release` |

**Deliberately *not* CHECK-guarded** (provisional or open vocabularies — enum only): `job_applications.status`, `contact_enquiries.status`, `blog_comments.status`, `blog_posts/news_items/event_listings/job_postings.status`, `payment_refunds.status`, `payments.gateway`, `membership_status_history.event`, `audit_logs.event`.

**Maintainability of CHECK vocabularies:** adding a value to a CHECK-guarded set is a small migration (`ALTER TABLE … DROP CONSTRAINT …; ADD CONSTRAINT …`, valid on MySQL ≥ 8.0.19 including 8.4) on a table that is either tiny or metadata-cheap; that friction is intentional for sets that are business-locked (four application statuses, five payment states, eight order/ledger vocabularies). Sets expected to evolve are left to the enum.

### 3.2 Structural and money CHECKs

| Table | Constraint | Purpose |
|---|---|---|
| `payments` | `(membership_term_id IS NULL) <> (order_id IS NULL)` | **exactly one purpose** |
| `payments` | `amount > 0` | no £0 payment |
| `payment_refunds` | `amount > 0` | |
| `membership_plans` | `fee_amount > 0`; `duration_months > 0` | |
| `membership_terms` | `(term_kind = 'introductory') = (term_no = 1)`; `(term_kind = 'introductory') = (payment_status = 'payment_not_required')`; introductory ⇒ no fee/plan, renewal ⇒ `fee_amount > 0` + currency + plan | **free ⇔ introductory term; no £0 fee anywhere** |
| `memberships` | number REGEXP `^[SPV][0-9]{8}$`; `number_sequence BETWEEN 1 AND 9999`; `YY`/`SSSS` substrings equal `number_year`/`number_sequence` | **9-character number shape** (all columns NOT NULL — the row exists only after activation) |
| `membership_terms` | lifecycle: `pending_payment` ⇒ no dates; `active/expired` ⇒ dates set, `expires_on >= starts_on`; `pending_payment` only for renewals | dates exist exactly when a term is valid |
| `membership_terms` | `status = 'pending_payment' OR payment_status IN ('payment_not_required','payment_confirmed')` | a term is valid only when payment is settled or not required |
| `membership_number_sequences` | `last_number BETWEEN 0 AND 9999` | four-digit capacity |
| `membership_applications` | decision columns ⇔ decided status; `approved ⇒ proof_reviewed_at NOT NULL` | proof review before approval |
| `documents` | one-owner arc tied to `kind`; `disk <> 'public'`; `size_bytes > 0`; purge pair | privacy |
| `audit_logs` | subject pair both-or-neither; `actor_type='user' ⇒ user_id NOT NULL` | |
| `product_variants` | `price >= 0`; `quantity_on_hand >= 0`; `quantity_reserved >= 0`; `quantity_reserved <= quantity_on_hand` | **no negative/oversold stock** |
| `inventory_transactions` | per-type sign matrix; order-driven types require `order_item_id`; not both deltas zero | |
| `carts` | `user_id IS NOT NULL OR guest_token IS NOT NULL` | |
| `cart_items` | `quantity >= 1` | |
| `coupons` | `discount_value > 0`; percentage ≤ 100; `minimum_order_amount >= 0`; window order | |
| `shipping_methods` | `price >= 0` | |
| `orders` | amounts ≥ 0; `discount <= subtotal`; `total = subtotal − discount + shipping`; coupon/shipping snapshot pairs | |
| `order_items` | `unit_price >= 0`; `quantity >= 1`; `line_total = unit_price * quantity` | |
| `site_settings`, `membership_settings` | `id = 1` | singleton |

## 4. Generated-column "open/active" keys

| Table | Column | Expression | Guarantees |
|---|---|---|---|
| `membership_plans` | `active_category_key` | `IF(is_active=1, membership_category_id, NULL)` | one active plan/category |
| `membership_applications` | `open_email_key` | `IF(status IN ('submitted','more_details_required'), email, NULL)` | one open application/email |
| `membership_details_requests` | `open_request_key` | `IF(responded_at IS NULL, membership_application_id, NULL)` | one unanswered request/application |
| `membership_terms` | `open_renewal_key` | `IF(status = 'pending_payment', membership_id, NULL)` | one renewal awaiting payment/member |
| `account_setup_tokens` | `live_key` | `IF(used_at IS NULL AND invalidated_at IS NULL, CONCAT(user_id,':',purpose), NULL)` | one live token/(user,purpose) |
| `payment_bank_accounts` | `active_currency_key` | `IF(is_active=1, currency, NULL)` | one active account/currency |
| `inventory_transactions` | `once_key` | `IF(type IN ('reservation','sale','release'), CONCAT(order_item_id,':',type), NULL)` | once-only stock events per order line |

All are `VIRTUAL` (nothing stored beyond the unique index entry), read-only to Eloquent (excluded from `$fillable`; Laravel `virtualAs`). `NULL` is not equal to `NULL` in a MySQL unique index, which is exactly the "inactive rows do not compete" behaviour required.

## 5. Lookup / composite indexes by workflow

| Workflow | Index | Query it serves |
|---|---|---|
| Admin application review queue | `membership_applications (status, submitted_at)` | oldest `submitted` first |
| *(Former reapplication-cooldown lookup — **no longer used**; there is no cooldown)* | `(email, status, decided_at)` and `(mobile, status, decided_at)` | indexes retained from an earlier design; no workflow queries them |
| Member's own applications | `(user_id, status)` | dashboard |
| Expiry job + configurable renewal reminders | `membership_terms (status, expires_on)` | `status='active' AND expires_on = ?` / `< today` |
| Payment confirmation queue | `membership_terms (status, payment_status)`; `payments (status, submitted_at)` | evidence awaiting review |
| Member's current term + history | `membership_terms (membership_id, term_no)` unique; `memberships (user_id)` unique | |
| Membership card / verification | `memberships (membership_number)` unique | lookup by number |
| **Webhook idempotency / sweeper** | `payment_webhooks (gateway, event_id)` unique; `(processing_status, received_at)` | dedupe; re-dispatch stuck events |
| Payment lookup from provider | `payments (gateway, transaction_reference)` | webhook → payment |
| Payments by target | `(membership_term_id, status)`, `(order_id, status)` | |
| **Unread count** | `notifications (notifiable_type, notifiable_id, read_at)` | `COUNT(*) … read_at IS NULL` |
| Audit by subject / actor / event | see `09` | |
| Blog/news/events/jobs public lists | `(status, published_at)`; events `(status, starts_at)` | published-only listing |
| Job board filters | `job_postings (location_type, employment_type)` | server-side filtering |
| Ledger per variant | `inventory_transactions (product_variant_id, id)` | balance recompute |
| Orders | `(user_id, created_at)`, `(status, created_at)`, `(status, reservation_expires_at)` | my orders; admin queue; expiry sweep |
| Pending-email sweep | `email_logs (status, queued_at)` | failed/queued follow-up |

## 6. Indexes intentionally not created

`users(status)`, `users(name)` (admin search is a small `LIKE` scan; revisit with data), FULLTEXT on blog titles (optional later), `documents(kind)` alone, `audit_logs(ip_address)`, `payments(currency)`, `orders(customer_email)` unless guest checkout is approved, any single-column index on `is_active`/`status` for tables under a few thousand rows. Adding an index later is a cheap online DDL; removing dead weight from a hot append-only table is not free.

## 7. Engine constraints that shaped the design (target: MySQL 8.4)

**Target engine (confirmed, OD-08 RESOLVED):** production is **MySQL 8.4.6** (utf8mb4, PHP 8.2.33, Apache). The design targets MySQL **8.4 LTS** behaviour with Laravel's `mysql` driver. Minimum assumption: **MySQL ≥ 8.0.19** (CHECK enforcement since 8.0.16; `ALTER TABLE … DROP CONSTRAINT` since 8.0.19); development should use 8.4.x to match production. MariaDB (including the local XAMPP 10.4.32) is **not** a production-equivalent database and no MariaDB-specific syntax is used. The full compatibility review, with required adjustments, is in `18_MYSQL_84_COMPATIBILITY_REVIEW.md`.

| MySQL rule | Consequence in this design |
|---|---|
| CHECK constraints are enforced (8.0.16+) | Used for closed vocabularies, money, number shape, lifecycle consistency, payment purpose. Every CHECK-backed invariant *also* has an application guard and a reconciliation query (`15`). |
| CHECK names are **schema-wide** unique; a CHECK may not use subqueries, non-deterministic functions, or columns with `CASCADE`/`SET NULL`/`SET DEFAULT` FK actions | Name every CHECK `{table}_{rule}`; all CHECK expressions use only their own row's columns; all history-bearing FKs use the default `NO ACTION` (identical to `RESTRICT` in InnoDB) — `18` §3.1. |
| Generated columns: deterministic expressions only; a `VIRTUAL` column may carry a secondary (incl. UNIQUE) index; FKs must not reference a virtual column | Seven `*_key` columns (§4) are `VIRTUAL` with a UNIQUE index; none is an FK target. |
| No partial unique indexes | Replaced by generated `*_key` columns (§4). |
| Multiple `NULL`s allowed in a UNIQUE index | Used deliberately (`verification_token`, `guest_token`, `*_key`, `(payment_id, gateway_reference)`, `memberships.user_id`). |
| Native `JSON` type validates on write | No `JSON_VALID` CHECKs are needed or used (`18` §3.9). |
| InnoDB index key limit 3072 bytes (DYNAMIC) | Widest key is `documents(disk, storage_path)`; keep machine identifiers `ascii` (`18` §3.3). |
| `REGEXP` = ICU regex; `_bin` collations are case-sensitive | `membership_number` check is exact and case-sensitive (`ascii_bin`). |
| Default `sql_mode` includes `ONLY_FULL_GROUP_BY`, strict trans tables | Reconciliation queries and reports must be full-group-by clean (`18` §5). |
| UPDATE reports **changed** rows by default | Compare-and-set updates must always change a column (`18` §5). |
| No triggers/stored procedures used | Append-only and cross-row rules are application rules; optional DB-user privilege hardening only. |
| `TIMESTAMP` ends 2038-01-19 | Far-future business dates use `DATE` (`activated_on`, `starts_on`, `expires_on`). |
