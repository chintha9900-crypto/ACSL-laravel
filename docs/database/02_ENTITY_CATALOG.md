# 02 — Entity Catalog

Complete catalogue of the **53** designed application tables, plus deferred/not-proposed structures. Column-level detail (types, nullability, defaults, FKs with delete behaviour) is in the per-domain documents `03`–`12`; this catalogue is the index and the cross-check.

## Legend

**Status**
* **CONFIRMED** — explicitly required by ACI (Phase 3 instructions / `WORKFLOWS.md` §0) or listed in the approved architecture.
* **DERIVED** — a support/derived table required by a design decision (e.g. a normalising pivot, a history or settings table).
* **TBC** — the *concept* is confirmed but its rules/columns are partly unresolved; the design is deliberately minimal (see `17`).

**Priority** — **P0** foundation · **P1** membership launch (application → activation → card) · **P2** public site, jobs, community · **P3** future: payment gateway/refunds and e-commerce.

**Soft delete** — `NO` unless stated. Only `products` and `product_variants` use it (`01` §3.6); `documents` use a purge tombstone.

**PK** — `id BIGINT UNSIGNED` unless stated. `pub` = also has `public_id CHAR(26)` ULID (UNIQUE).

## 1. Identity & access (`03`)

| Table | Purpose | PK | Important columns | Important FKs | Unique | Important indexes | Audit / history | Status | Pri |
|---|---|---|---|---|---|---|---|---|---|
| `users` | Everyone who can log in (member/admin), incl. pending-setup accounts | id | `name` (single field), `email`, `password`(NULL until setup), `role`, `status`, profile fields | — | `email` | `role` | role/suspend/email changes → `audit_logs` | CONFIRMED | P0 |
| `account_setup_tokens` | One-time hashed account-setup tokens | id | `token_hash`, `purpose`, `expires_at`, `used_at`, `invalidated_at`, `live_key` | `user_id`, `membership_id`, `issued_by_user_id` | `token_hash`, `live_key` | `(user_id,purpose)`, `expires_at` | issue/use/invalidate → `audit_logs` | CONFIRMED | P1 |

## 2. Membership (`04`)

| Table | Purpose | PK | Important columns | Important FKs | Unique | Important indexes | Audit / history | Status | Pri |
|---|---|---|---|---|---|---|---|---|---|
| `membership_categories` | The exactly-three categories S/P/V | id | `code`, `name`, `is_active` | — | `code` (CHECK S/P/V) | — | changes → `audit_logs` | CONFIRMED | P1 |
| `membership_plans` | **Renewal** fee/currency/duration per category (commercial config; the free introductory term has no plan) | id | `fee_amount`, `currency`, `duration_months`, `is_active`, `active_category_key` | `membership_category_id` | `active_category_key` (one active plan/category) | — | price changes = new row; audited | CONFIRMED | P1 |
| `membership_settings` | Singleton: introductory free months (6), setup-token TTL (`reapplication_cooldown_days` is unused/deprecated — no cooldown exists) | tinyint =1 | `introductory_period_months`, `reapplication_cooldown_days`, `account_setup_token_ttl_hours` | `updated_by_user_id` | PK (CHECK id=1) | — | old/new → `audit_logs` | DERIVED | P1 |
| `membership_applications` | One row per application attempt; never overwritten | id · pub | `status`, applicant snapshot, aviation fields, `proof_reviewed_at`, `decided_at`, `open_email_key` | `user_id`, `membership_category_id`, reviewer/decider users | `public_id`, `open_email_key` | `(status,submitted_at)`, `(email,status,decided_at)`, `(mobile,status,decided_at)`, `(user_id,status)` | `membership_status_history` + `audit_logs`; row is append-only by policy | CONFIRMED | P1 |
| `membership_details_requests` | Each "more details" request and the applicant's response | id | `request_message`, `responded_at`, `open_request_key` | `membership_application_id`, `requested_by_user_id` | `open_request_key` | `(application,requested_at)` | is itself history | DERIVED | P1 |
| `memberships` | **Stable member record — one row per member for life** (created once, at first activation; carries the membership number) | id | `membership_number`, `number_year`, `number_sequence`, `activated_at`, `activated_on`, `verification_token` | originating application (1:1), `user_id` (1:1), category | `membership_application_id`, `user_id`, `membership_number`, `(category,number_year,number_sequence)`, `verification_token` | — (state is derived from terms) | history + audit; never deleted | CONFIRMED | P1 |
| `membership_terms` | One row per validity term: term 1 = free introductory term (no payment); further terms = paid renewals | id | `term_no`, `term_kind`, `status`, `payment_status`, `duration_months`, `fee_amount`/`fee_currency` (renewals), `starts_on`, `expires_on`, `open_renewal_key` | `membership_id`, `membership_plan_id` (renewals only) | `(membership_id,term_no)`, `open_renewal_key` | `(status,expires_on)`, `(status,payment_status)` | history + audit; never deleted | CONFIRMED | P1 |
| `membership_number_sequences` | Per (category, year) counter; lock target for number issue | id | `sequence_year`, `last_number` (0–9999) | `membership_category_id` | `(membership_category_id, sequence_year)` | — | issuance recorded in history/audit | CONFIRMED | P1 |
| `membership_status_history` | Append-only lifecycle/payment/number events | id | `event`, `from_status`, `to_status`, `actor_type`, `note` | `membership_application_id`, `membership_id`, `membership_term_id`, `actor_user_id` | — | `(application,id)`, `(membership_id,id)`, `(membership_term_id)`, `(event,created_at)` | is the history | CONFIRMED | P1 |

## 3. Introductory period and promotions (`05`)

No tables in the initial set. The mandatory first-6-month free period is the member's **term 1** (`membership_terms`, driven by `membership_settings.introductory_period_months`) — it is **not** a promotion. `membership_promotions` and `membership_promotion_category` are **DEFERRED** (future marketing promotions only; see §12).


## 4. Payments (`06`)

| Table | Purpose | PK | Important columns | Important FKs | Unique | Important indexes | Audit / history | Status | Pri |
|---|---|---|---|---|---|---|---|---|---|
| `payment_bank_accounts` | Approved ACI bank/payment details | id | bank/account fields, `currency`, `is_active`, `active_currency_key` | created/updated users | `active_currency_key` | — | edits audited | CONFIRMED | P1 |
| `payments` | Shared payment ledger (membership **renewal term** XOR order) | id · pub | `gateway`, `transaction_reference`, `idempotency_key`, `amount`(>0), `currency`, `status`, `paid_at`, `failed_at`, `metadata` | `user_id`, `membership_term_id`, `order_id`, `bank_account_id`, `reviewed_by_user_id` | `public_id`, `(gateway,idempotency_key)` | `(gateway,transaction_reference)`, `(membership_term_id,status)`, `(order_id,status)`, `(status,submitted_at)` | audit + membership history; never deleted | CONFIRMED | P1 |
| `payment_refunds` | Refund attempts against a payment | id | `amount`(>0), `status`, `gateway_reference`, `idempotency_key` | `payment_id`, `requested_by_user_id` | `idempotency_key`, `(payment_id,gateway_reference)` | `(payment_id,status)` | audit `payment.refunded` | CONFIRMED | P3 |
| `payment_webhooks` | Idempotent inbound provider events | id | `gateway`, `event_id`, `event_type`, `payload`(JSON), `processing_status`, `signature_state` | `payment_id` | `(gateway,event_id)` | `(processing_status,received_at)` | is itself the record | CONFIRMED | P3 |

## 5. Documents (`07`)

| Table | Purpose | PK | Important columns | Important FKs | Unique | Important indexes | Audit / history | Status | Pri |
|---|---|---|---|---|---|---|---|---|---|
| `documents` | Private-file metadata: aviation proof, payment evidence, job docs | id · pub | `kind`, `disk`(≠public), `storage_path`, `original_filename`, `mime_type`, `size_bytes`, `checksum_sha256`, `visibility`, `purged_at` | `membership_application_id` / `payment_id` / `job_application_id` (exactly one), `membership_details_request_id`, `uploaded_by_user_id`, `purged_by_user_id` | `public_id`, `(disk,storage_path)` | per-owner, `checksum_sha256` | upload/view/purge → `audit_logs`; purge tombstone, no soft delete | CONFIRMED | P1 |

## 6. Notifications (`08`)

| Table | Purpose | PK | Important columns | Important FKs | Unique | Important indexes | Audit / history | Status | Pri |
|---|---|---|---|---|---|---|---|---|---|
| `notifications` | In-app inbox (Laravel database channel) | UUID char(36) | `type`, `notifiable_*`, `data`, `read_at` | none (polymorphic — framework) | PK | `(notifiable_type,notifiable_id,read_at)` | — | CONFIRMED | P1 |
| `email_templates` | Editable subject/body for M1–M11 + referral | id | `template_key`, `reference_code`, `subject`, `body`, `is_active` | `updated_by_user_id` | `template_key`, `reference_code` | — | edits audited (version history) | CONFIRMED (Phase 3) | P1 |
| `email_logs` | Outbound email delivery log | id | `to_email`, `template_key`, `subject`, `status`, `attempts`, `error_message` | `user_id`, `membership_application_id`, `order_id` | — | `(application,template_key)`, `(to_email,created_at)`, `(status,queued_at)` | is the record | DERIVED | P1 |

## 7. Audit (`09`)

| Table | Purpose | PK | Important columns | Important FKs | Unique | Important indexes | Audit / history | Status | Pri |
|---|---|---|---|---|---|---|---|---|---|
| `audit_logs` | Cross-domain admin/security event log (approved polymorphic exception) | id | `event`, `actor_type`, `subject_type`, `subject_id`, `old_values`, `new_values`, `ip_address`, `user_agent`, `request_id` | `user_id` | — | `(subject_type,subject_id,id)`, `(user_id,created_at)`, `(event,created_at)`, `created_at` | append-only | CONFIRMED | P0 |

## 8. Content / CMS (`10`)

| Table | Purpose | Important columns | Important FKs | Unique | Indexes | Status | Pri |
|---|---|---|---|---|---|---|---|
| `blog_categories` | Blog taxonomy | `name`, `slug` | — | `slug` | — | CONFIRMED | P2 |
| `blog_posts` | Blog articles | `title`, `slug`, `content`, `status`, `published_at`, SEO | `author_id`(SET NULL), `blog_category_id`(SET NULL) | `slug` | `(status,published_at)` | CONFIRMED | P2 |
| `blog_tags` | Tags | `name`, `slug` | — | `slug` | — | CONFIRMED | P2 |
| `blog_post_blog_tag` | Post↔tag pivot | — | post, tag (both CASCADE) | PK composite | `(tag,post)` | DERIVED | P2 |
| `blog_comments` | Member comments (moderated) | `comment`, `status` | `blog_post_id`(CASCADE), `user_id`(RESTRICT) | — | `(post,status,created_at)`, `(user,created_at)`, `(status,created_at)` | CONFIRMED · TBC default status | P2 |
| `news_items` | News (separate from blog) | `title`, `slug`, `content`, `status` | `created_by_user_id`(SET NULL) | `slug` | `(status,published_at)` | CONFIRMED | P2 |
| `event_listings` | Club events (listing only) | `title`, `slug`, `starts_at`, `location`, `status` | `created_by_user_id`(SET NULL) | `slug` | `(status,starts_at)` | CONFIRMED | P2 |
| `faqs` | FAQ entries | `question`, `answer`, order, active | — | — | `(is_active,display_order)` | CONFIRMED | P2 |
| `testimonials` | Testimonials | `member_name`, `testimonial`, `photo_path` | — | — | same | CONFIRMED | P2 |
| `team_members` | Team/board display | `name`, `position`, `photo_path`, `bio` | — | — | same | CONFIRMED · TBC advisory variant | P2 |
| `hero_banners` | Home hero | `title`, `subtitle`, `image_path`, `button_*` | — | — | same | CONFIRMED | P2 |
| `site_settings` | Singleton site config | `site_name`, contact, `logo_path`, `mail_from_*` | `updated_by_user_id` | PK (id=1) | — | CONFIRMED | P2 |
| `social_links` | Social links | `platform`, `url` | — | — | `(is_active,display_order)` | CONFIRMED | P2 |
| `footer_links` | Footer links | `title`, `url` | — | — | same | CONFIRMED | P2 |
| `seo_pages` | Per-page SEO overrides | `page_name`, meta fields | — | `page_name` | — | CONFIRMED | P2 |
| `subscribers` | Newsletter list | `email`, `unsubscribed_at` | — | `email` | — | CONFIRMED | P2 |
| `contact_enquiries` | Contact-form submissions | `name`, `email`, `message`, `status` | `handled_by_user_id` | — | `(status,created_at)` | CONFIRMED | P2 |

## 9. Jobs & community (`11`)

| Table | Purpose | Important columns | Important FKs | Unique | Indexes | Status | Pri |
|---|---|---|---|---|---|---|---|
| `job_postings` | Job board | `title`, `company`, `location_type`, `status`, `external_link` | `posted_by_user_id`(SET NULL) | — | `(status,published_at)`, `(location_type,employment_type)` | CONFIRMED | P2 |
| `job_applications` | Member applications to postings | `status` | `job_posting_id`(RESTRICT), `user_id`(RESTRICT) | `(job_posting_id,user_id)` | `(user_id,created_at)`, `(job_posting_id,status)` | CONFIRMED · TBC document rules | P2 |
| `referral_invitations` | Refer-a-friend send history | `invitee_email`, `created_at` | `referrer_user_id` | — | `(referrer,created_at)`, `(invitee_email)` | TBC (minimal) | P2 |

## 10. E-commerce (`12`) — all future scope

| Table | Purpose | Important columns | Important FKs | Unique | Indexes | Soft delete | Status | Pri |
|---|---|---|---|---|---|---|---|---|
| `product_categories` | Shop taxonomy | `name`, `slug` | — | `slug` | — | NO | CONFIRMED | P3 |
| `products` | Sellable product | `name`, `slug`, `visibility`, `is_active` | `product_category_id`(SET NULL) | `slug` | catalogue | **YES** | CONFIRMED | P3 |
| `product_variants` | SKU, price, cached stock | `sku`, `price`, `quantity_on_hand`, `quantity_reserved` (CHECKs ≥0, reserved ≤ on-hand) | `product_id` | `sku` | `(product,active,order)` | **YES** | CONFIRMED | P3 |
| `product_images` | Product images (public disk) | `image_path`, `alt_text` | `product_id`(CASCADE) | — | `(product,order)` | NO | DERIVED | P3 |
| `inventory_transactions` | Append-only stock ledger | `type`, `on_hand_delta`, `reserved_delta`, `once_key` | variant, `order_item_id`, `created_by_user_id` | `once_key` | `(variant,id)`, `(type,created_at)` | NO | CONFIRMED | P3 |
| `carts` | Transient basket | `user_id`, `guest_token` | user, coupon(SET NULL) | `user_id`, `guest_token` | — | NO | CONFIRMED · TBC guest | P3 |
| `cart_items` | Cart lines (no prices) | `quantity` | cart(CASCADE), variant | `(cart,variant)` | — | NO | DERIVED | P3 |
| `coupons` | Minimal discount codes | `code`, `discount_type`, `discount_value`, window, `usage_limit` | — | `code` | — | NO | CONFIRMED | P3 |
| `shipping_methods` | Shipping options + cost | `name`, `price` | — | — | — | NO | CONFIRMED | P3 |
| `orders` | Placed order (snapshots) | `order_number`, `status`, `currency`, amounts, `reservation_expires_at` | user, coupon, shipping method | `public_id`, `order_number` | `(user,created_at)`, `(status,created_at)`, `(status,reservation_expires_at)` | NO | CONFIRMED | P3 |
| `order_items` | Line items with price snapshot | `product_name`, `sku`, `unit_price`, `quantity`, `line_total` | order, variant | `(order,variant)` | — | NO | CONFIRMED | P3 |
| `order_addresses` | Shipping/billing snapshot | address fields | order | `(order,type)` | — | NO | DERIVED | P3 |
| `order_status_history` | Order lifecycle events | `from_status`, `to_status`, `note` | order, actor | — | `(order,id)` | NO | DERIVED | P3 |

## 11. Classification summary

* **CONFIRMED tables (44):** all rows marked CONFIRMED above (including those with a TBC sub-aspect noted in the same cell, e.g. `job_applications`, `blog_comments`, `team_members`, `carts`).
* **DERIVED / support tables (8):** `membership_settings`, `membership_details_requests`, `email_logs`, `blog_post_blog_tag`, `product_images`, `cart_items`, `order_addresses`, `order_status_history`.
* **TBC — concept confirmed, rules unresolved, minimal design (1):** `referral_invitations`.
* Total: 44 + 8 + 1 = **53** (previous revision: 54 — `membership_promotions` and `membership_promotion_category` deferred, `membership_terms` added).
* **Deferred / TBC structures not proposed as tables:** see §12.

## 12. Deferred / not proposed

| Structure | Decision | Why |
|---|---|---|
| `membership_promotions`, `membership_promotion_category` | **DEFER** (retained as a design reservation, `05` §3) | The mandatory first-6-month free period is not a promotion (OD-10); no confirmed requirement defines marketing promotions |
| `roles`, `permissions`, pivots | DEFER | Two flat roles satisfy the confirmed model (`03` §1) |
| `pages` (generic CMS page) | DEFER | Unconfirmed new scope (OD-17); minimal shape in `10` §14 |
| `event_registrations` | DEFER | No business rules exist (OD-17) |
| `partners` | DEFER | Undefined concept (OD-16) |
| `home_sections` | DEFER | Hard-coded in the legacy homepage; no confirmed requirement |
| `membership_benefits` | DEFER | Benefits page is static |
| `user_addresses` (address book) | DEFER | Orders snapshot addresses; no confirmed address-book feature |
| Variant option tables (`product_options`, …) | DEFER | `product_variants.name` is a label; avoid EAV |
| `shipping_zones`, tax tables, returns/RMA, supplier/purchase orders | DEFER / out of scope | Accounting/warehouse creep |
| `application_access_tokens` | DEFER | Default is stateless signed URLs (OD-07) |
| `notification_preferences`, SMS/push tables | DEFER | No confirmed requirement |
| `email_bodies` (stored rendered mail) | NOT proposed | PII/size; reconstructable from template + records |

## 13. Framework/infrastructure tables (not designed here)

`migrations`, `cache`, `cache_locks`, `jobs`, `job_batches`, `failed_jobs`, `password_reset_tokens`, and `sessions` (if the database session driver is chosen). Laravel-standard shapes.
