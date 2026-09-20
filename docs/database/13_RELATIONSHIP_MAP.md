# 13 — Relationship Map

Design only. Mermaid notation: `||` exactly one · `o|` zero or one · `o{` zero or many · `|{` one or many. Every FK is `ON UPDATE RESTRICT`; delete behaviour is stated in the tables under each diagram (`RESTRICT` unless noted).

## 1. Identity → Membership Application → Membership → Promotion → Payment

```mermaid
erDiagram
    users ||--o{ membership_applications : "applies (user_id NULL until linked)"
    membership_categories ||--o{ membership_applications : "selected category"
    membership_categories ||--o{ membership_plans : "commercial terms"
    membership_categories ||--o{ membership_number_sequences : "counter per year"
    membership_applications ||--o{ membership_details_requests : "more-details loop"
    membership_applications ||--o| memberships : "approval creates (UNIQUE)"
    membership_plans ||--o{ memberships : "terms at approval (snapshotted)"
    membership_categories ||--o{ memberships : "category"
    users |o--o{ memberships : "owner (set at activation)"
    memberships |o--o| memberships : "renews_membership_id"
    membership_promotions |o--o{ memberships : "applied once (snapshotted)"
    membership_promotions ||--o{ membership_promotion_category : "applies to"
    membership_categories ||--o{ membership_promotion_category : "eligible"
    memberships |o--o{ payments : "membership_id (XOR order_id)"
    payment_bank_accounts |o--o{ payments : "instructed account"
    payments ||--o{ payment_refunds : "refunded by"
    payments |o--o{ payment_webhooks : "matched event"
    memberships ||--o{ account_setup_tokens : "activation setup link"
    users ||--o{ account_setup_tokens : "token for"
    membership_applications ||--o{ membership_status_history : "timeline"
    memberships |o--o{ membership_status_history : "after approval"
```

| Parent → Child | Cardinality | FK column | ON DELETE | Note |
|---|---|---|---|---|
| `users` → `membership_applications` | 0..1 : many | `user_id` | RESTRICT | NULL for a pre-account applicant |
| `membership_categories` → `membership_applications` | 1 : many | `membership_category_id` | RESTRICT | |
| `membership_applications` → `memberships` | 1 : 0..1 | `membership_application_id` (UNIQUE) | RESTRICT | application may exist without a membership; never the reverse |
| `users` → `memberships` | 0..1 : many | `user_id` | RESTRICT | one user, many terms over time |
| `membership_plans` → `memberships` | 1 : many | `membership_plan_id` | RESTRICT | fee/duration also **snapshotted** on the row |
| `membership_promotions` → `memberships` | 0..1 : many | `membership_promotion_id` | RESTRICT | name/free months also snapshotted; **only one** promotion per membership by construction |
| `memberships` → `memberships` | 0..1 : 0..1 | `renews_membership_id` | RESTRICT | renewal lineage |
| `memberships` → `payments` | 0..1 : many | `payments.membership_id` | RESTRICT | free membership ⇒ zero payments |
| `orders` → `payments` | 0..1 : many | `payments.order_id` | RESTRICT | **XOR** with `membership_id` (CHECK) |
| `payments` → `payment_refunds` | 1 : many | `payment_id` | RESTRICT | |
| `payments` → `payment_webhooks` | 0..1 : many | `payment_id` | RESTRICT | idempotency key is `(gateway,event_id)`, not this FK |
| `membership_promotions` ↔ `membership_categories` | many : many | pivot | promotion side CASCADE, category side RESTRICT | |
| `memberships` → `account_setup_tokens` | 1 : many | `membership_id` | RESTRICT | at most one *live* token per (user, purpose) |
| `membership_applications` → `membership_status_history` | 1 : many | `membership_application_id` | RESTRICT | append-only |

**Reading the chain:** an *application* is created and reviewed; on approval a *membership* row is created in `pending_activation` with a snapshot of the plan terms; a *payment* is created only when no promotion applies; activation (free path immediately, paid path after admin confirmation) atomically issues the number (`membership_number_sequences`), sets dates, and records the promotion snapshot; the user and setup token are provisioned; history and audit rows are written.

## 2. Identity → Documents

```mermaid
erDiagram
    membership_applications ||--o{ documents : "aviation proof (application_id)"
    membership_details_requests |o--o{ documents : "response files"
    payments ||--o{ documents : "payment evidence (payment_id)"
    job_applications ||--o{ documents : "job documents (job_application_id)"
    users |o--o{ documents : "uploaded_by / purged_by"
```

| Owner → `documents` | Cardinality | FK | ON DELETE | Rule |
|---|---|---|---|---|
| `membership_applications` | 1 : many (≥1 required to submit — app rule) | `membership_application_id` | RESTRICT | `kind = aviation_proof` |
| `membership_details_requests` | 0..1 : many | `membership_details_request_id` | RESTRICT | must belong to the same application (app rule) |
| `payments` | 1 : many | `payment_id` | RESTRICT | `kind = payment_evidence`; resubmission adds rows |
| `job_applications` | 1 : many | `job_application_id` | RESTRICT | `kind = job_application_document`; rules TBC |
| `users` | 0..1 : many | `uploaded_by_user_id`, `purged_by_user_id` | RESTRICT | NULL uploader = anonymous applicant |

**Exactly one owner FK is non-NULL** (CHECK, tied to `kind`). This is an *exclusive arc*, not polymorphism: every owner is a real FK.

## 3. Identity → Notifications and Audit Logs

```mermaid
erDiagram
    users ||--o{ notifications : "notifiable (polymorphic, framework)"
    users |o--o{ email_logs : "recipient if a user"
    membership_applications |o--o{ email_logs : "related email"
    orders |o--o{ email_logs : "related email"
    email_templates ||..o{ email_logs : "template_key snapshot (no FK)"
    users |o--o{ audit_logs : "actor"
    audit_logs }o..o| membership_applications : "subject_type/subject_id (morph, no FK)"
```

| Relationship | Kind | Delete | Note |
|---|---|---|---|
| `users` → `notifications` | polymorphic (`notifiable_type='user'`, `notifiable_id`) | n/a (no FK) | Laravel contract; only `user` alias allowed |
| `users` → `email_logs` | typed, nullable | RESTRICT | recipients may be non-users |
| `membership_applications` / `orders` → `email_logs` | typed, nullable | RESTRICT | dominant operational lookups |
| `email_templates` → `email_logs` | **no FK**, `template_key` snapshot | — | log survives template changes |
| `users` → `audit_logs` | typed, nullable | RESTRICT | actor; NULL = system/guest |
| any entity → `audit_logs` | polymorphic (`subject_type/subject_id`) | n/a | the approved exception |

## 4. Products → Variants → Inventory

```mermaid
erDiagram
    product_categories |o--o{ products : "SET NULL"
    products ||--|{ product_variants : "1..n SKUs (soft-deleted)"
    products ||--o{ product_images : "CASCADE"
    product_variants ||--o{ inventory_transactions : "ledger"
    order_items |o--o{ inventory_transactions : "reservation/sale/release/return"
    users |o--o{ inventory_transactions : "created_by"
    product_variants ||--o{ order_items : "sold as (snapshotted)"
    product_variants ||--o{ cart_items : "wanted"
```

Stock reading: `product_variants.quantity_on_hand` / `quantity_reserved` are **projections** of `SUM(on_hand_delta)` / `SUM(reserved_delta)` over `inventory_transactions`, updated in the same transaction as each ledger insert, guarded by CHECKs (`>= 0`, `reserved <= on_hand`).

## 5. Cart → Cart Items

```mermaid
erDiagram
    users |o--o| carts : "one cart per member (UNIQUE user_id)"
    carts ||--o{ cart_items : "CASCADE"
    product_variants ||--o{ cart_items : "RESTRICT"
    coupons |o--o{ carts : "SET NULL"
```

Carts hold intent only — **no prices**. Everything financial is re-derived at checkout.

## 6. Order → Order Items → Payment

```mermaid
erDiagram
    users |o--o{ orders : "customer (NULL only for approved guest checkout)"
    orders ||--|{ order_items : "price snapshot"
    orders ||--|{ order_addresses : "shipping (+ optional billing) snapshot"
    orders ||--|{ order_status_history : "lifecycle"
    orders |o--o{ payments : "order_id (XOR membership_id)"
    coupons |o--o{ orders : "RESTRICT + code snapshot"
    shipping_methods |o--o{ orders : "RESTRICT + name snapshot"
    product_variants ||--o{ order_items : "RESTRICT (soft-deleted only)"
    payments ||--o{ payment_refunds : "order refunds reuse this"
```

| Parent → Child | Cardinality | FK | ON DELETE |
|---|---|---|---|
| `orders` → `order_items` | 1 : 1..many | `order_id` | RESTRICT |
| `orders` → `order_addresses` | 1 : 1..2 (`UNIQUE(order_id,type)`) | `order_id` | RESTRICT |
| `orders` → `order_status_history` | 1 : many | `order_id` | RESTRICT |
| `orders` → `payments` | 1 : 0..many (normally 1) | `payments.order_id` | RESTRICT |
| `order_items` → `inventory_transactions` | 1 : 0..many (once-only per reservation/sale/release) | `order_item_id` | RESTRICT |

## 7. Content, jobs and community (brief)

```mermaid
erDiagram
    blog_categories |o--o{ blog_posts : "SET NULL"
    users |o--o{ blog_posts : "author, SET NULL"
    blog_posts }o--o{ blog_tags : "blog_post_blog_tag (CASCADE)"
    blog_posts ||--o{ blog_comments : "CASCADE"
    users ||--o{ blog_comments : "author RESTRICT"
    job_postings ||--o{ job_applications : "RESTRICT"
    users ||--o{ job_applications : "RESTRICT, UNIQUE(posting,user)"
    users ||--o{ referral_invitations : "referrer RESTRICT"
```

## 8. Delete-behaviour policy at a glance

| Class of relationship | ON DELETE | Examples |
|---|---|---|
| Historical / financial / legal / lifecycle | **RESTRICT** | applications, memberships, payments, refunds, webhooks, documents, history, audit, orders, order items, ledger, job applications, users |
| Pure child/link rows | CASCADE | pivot rows, `cart_items`, `product_images`, `blog_comments` |
| Optional attribution/taxonomy | SET NULL | `blog_posts.author_id`, `created_by_user_id`, `products.product_category_id`, `carts.coupon_id` |

No CASCADE exists on any path that leads to a membership, payment, order, document, history or audit row. A cascading chain that could reach historical data does not exist (verified in `15` §6 circularity/orphan review).
