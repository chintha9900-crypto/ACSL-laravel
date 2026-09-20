# 14 — Index and Constraint Strategy

Design only. This document consolidates the index, uniqueness, CHECK and generated-key decisions made in `03`–`12` and states the principles and engine constraints behind them. Where this document and a domain document ever differ, the domain document (which has the column-level detail) governs and this file must be corrected.

## 1. Index principles

1. **Index for a named access path**, not for a column. Every non-unique index below is justified by a query in a workflow (review queue, cooldown check, expiry job, unread count, webhook lookup…).
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
| `memberships` | `membership_application_id` | 1 application → ≤ 1 membership |
| `memberships` | `membership_number` | **complete number unique** |
| `memberships` | `(membership_category_id, number_year, number_sequence)` | **sequence never duplicated**, independent of the random digits |
| `memberships` | `verification_token` | future QR token |
| `membership_number_sequences` | `(membership_category_id, sequence_year)` | one counter per category/year; the lock target |
| `membership_promotion_category` | PK `(promotion, category)` | no duplicate links |
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

Only ACI-confirmed or legacy-closed sets. Columns are `VARCHAR`; the PHP backed enum is the primary definition, the CHECK is a backstop against typos and raw writes.

| Column | Values |
|---|---|
| `users.role` | `member`, `admin` |
| `users.status` | `pending_setup`, `active`, `suspended` |
| `account_setup_tokens.purpose` | `account_setup`, `membership_link` |
| `membership_categories.code` | `S`, `P`, `V` |
| `membership_applications.status` | `submitted`, `more_details_required`, `approved`, `rejected` |
| `memberships.status` | `pending_activation`, `active`, `expired` |
| `memberships.payment_status` | `payment_not_required`, `payment_pending`, `payment_confirmation_submitted`, `payment_confirmed`, `payment_rejected` |
| `membership_status_history.actor_type` | `applicant`, `admin`, `system` |
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

**Maintainability of CHECK vocabularies:** adding a value to a CHECK-guarded set is a small migration (`ALTER TABLE … DROP CHECK …; ADD CONSTRAINT …`) on a table that is either tiny or metadata-cheap; that friction is intentional for sets that are business-locked (four application statuses, five payment states, eight order/ledger vocabularies). Sets expected to evolve are left to the enum.

### 3.2 Structural and money CHECKs

| Table | Constraint | Purpose |
|---|---|---|
| `payments` | `(membership_id IS NULL) <> (order_id IS NULL)` | **exactly one purpose** |
| `payments` | `amount > 0` | no £0 payment |
| `payment_refunds` | `amount > 0` | |
| `membership_plans` | `fee_amount > 0`; `duration_months > 0` | |
| `memberships` | `fee_amount > 0` | |
| `memberships` | number REGEXP `^[SPV][0-9]{8}$`; number ⇔ components; `number_sequence BETWEEN 1 AND 9999`; `YY`/`SSSS` substrings equal components | **9-character number shape** |
| `memberships` | lifecycle (pending ⇒ no number/dates; active/expired ⇒ number + dates, `expires_on >= starts_on`) | number only at activation |
| `memberships` | `status='pending_activation' OR payment_status IN ('payment_not_required','payment_confirmed')` | no activation before payment settled |
| `memberships` | `(payment_status='payment_not_required') = (membership_promotion_id IS NOT NULL)` | free ⇔ promotion |
| `memberships` | promotion snapshot all-or-nothing | |
| `membership_number_sequences` | `last_number BETWEEN 0 AND 9999` | four-digit capacity |
| `membership_applications` | decision columns ⇔ decided status; `approved ⇒ proof_reviewed_at NOT NULL` | proof review before approval |
| `membership_promotions` | `ends_on >= starts_on`; free ⇒ months > 0; months ≤ 120 | |
| `documents` | one-owner arc tied to `kind`; `disk <> 'public'`; `size_bytes > 0`; purge pair | privacy |
| `audit_logs` | subject pair both-or-neither; `actor_type='user' ⇒ user_id NOT NULL`; `JSON_VALID` on old/new | |
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
| `account_setup_tokens` | `live_key` | `IF(used_at IS NULL AND invalidated_at IS NULL, CONCAT(user_id,':',purpose), NULL)` | one live token/(user,purpose) |
| `payment_bank_accounts` | `active_currency_key` | `IF(is_active=1, currency, NULL)` | one active account/currency |
| `inventory_transactions` | `once_key` | `IF(type IN ('reservation','sale','release'), CONCAT(order_item_id,':',type), NULL)` | once-only stock events per order line |

All are `VIRTUAL` (nothing stored beyond the unique index entry), read-only to Eloquent (excluded from `$fillable`; Laravel `virtualAs`). `NULL` is not equal to `NULL` in a MySQL unique index, which is exactly the "inactive rows do not compete" behaviour required.

## 5. Lookup / composite indexes by workflow

| Workflow | Index | Query it serves |
|---|---|---|
| Admin application review queue | `membership_applications (status, submitted_at)` | oldest `submitted` first |
| **Reapplication cooldown** | `(email, status, decided_at)` and `(mobile, status, decided_at)` | newest `rejected` for this applicant |
| Member's own applications | `(user_id, status)` | dashboard |
| Expiry job + 30/7/0-day reminders | `memberships (status, expires_on)` | `status='active' AND expires_on = ?` / `< today` |
| Payment confirmation queue | `memberships (status, payment_status)`; `payments (status, submitted_at)` | evidence awaiting review |
| Member's memberships (current + history) | `memberships (user_id, status)` | |
| Membership card / verification | `memberships (membership_number)` unique | lookup by number |
| **Promotion resolution** | `membership_promotions (is_active, starts_on, ends_on, priority)` + pivot `(category, promotion)` | activation-time resolution query |
| **Webhook idempotency / sweeper** | `payment_webhooks (gateway, event_id)` unique; `(processing_status, received_at)` | dedupe; re-dispatch stuck events |
| Payment lookup from provider | `payments (gateway, transaction_reference)` | webhook → payment |
| Payments by target | `(membership_id, status)`, `(order_id, status)` | |
| **Unread count** | `notifications (notifiable_type, notifiable_id, read_at)` | `COUNT(*) … read_at IS NULL` |
| Audit by subject / actor / event | see `09` | |
| Blog/news/events/jobs public lists | `(status, published_at)`; events `(status, starts_at)` | published-only listing |
| Job board filters | `job_postings (location_type, employment_type)` | server-side filtering |
| Ledger per variant | `inventory_transactions (product_variant_id, id)` | balance recompute |
| Orders | `(user_id, created_at)`, `(status, created_at)`, `(status, reservation_expires_at)` | my orders; admin queue; expiry sweep |
| Pending-email sweep | `email_logs (status, queued_at)` | failed/queued follow-up |

## 6. Indexes intentionally not created

`users(status)`, `users(last_name, first_name)` (admin search is a small `LIKE` scan; revisit with data), FULLTEXT on blog titles (optional later), `documents(kind)` alone, `audit_logs(ip_address)`, `payments(currency)`, `orders(customer_email)` unless guest checkout is approved, any single-column index on `is_active`/`status` for tables under a few thousand rows. Adding an index later is a cheap online DDL; removing dead weight from a hot append-only table is not free.

## 7. Engine constraints that shaped the design

| MySQL rule | Consequence |
|---|---|
| CHECK enforced only from 8.0.16 (5.7 ignores it silently); MariaDB from 10.2.1 | Every CHECK-backed invariant also has an application guard and a reconciliation query (`15`). Engine/version verification is OD-08. |
| A column used in a CHECK may not carry a FK with `CASCADE`/`SET NULL`/`SET DEFAULT` referential actions (`RESTRICT`/`NO ACTION` are permitted — **confirm this exact combination on the target server as the first migration spike**, OD-08) | All FKs on CHECKed columns are `RESTRICT` (`payments.membership_id/order_id`, `documents.*_id`, `carts.user_id`, `orders.coupon_id/shipping_method_id`, `inventory_transactions.order_item_id`, `memberships.membership_promotion_id`, …). |
| CHECK expressions may not use subqueries or non-deterministic functions | No CHECK depends on another table or on `NOW()`; cross-table rules are application-layer (`15`). |
| Generated columns cannot reference columns with `ON UPDATE CASCADE`/`SET NULL` FKs | All referenced base columns are `RESTRICT`-FK or plain columns. |
| No partial unique indexes | Replaced by generated `*_key` columns (§4). |
| Multiple `NULL`s allowed in a UNIQUE index | Used deliberately (`membership_number`, `verification_token`, `guest_token`, `*_key`, `(payment_id, gateway_reference)`). |
| No triggers/stored procedures | Append-only and cross-row rules are application rules; optional DB-user privilege hardening only. |
| `TIMESTAMP` ends 2038-01-19 | Far-future business dates use `DATE` (`expires_on`, promotion windows). |
