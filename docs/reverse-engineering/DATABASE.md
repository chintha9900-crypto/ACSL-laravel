# Database (current Supabase/Postgres schema)

Reconstructed from all 19 files in `supabase/migrations/` (read chronologically, tracking final post-all-migrations state) cross-checked against `src/integrations/supabase/types.ts`. This documents **current behaviour of the reference app**, not the target MySQL design — see the "MySQL Migration Considerations" section at the end for what changes, and LEGACY_RISKS.md for what should NOT be carried forward.

> **CONFIRMED ACI BUSINESS REQUIREMENT (final, 2026-09-15) overrides the `memberships`/`membership_applications` design below.** See "CONFIRMED Membership Data Model" immediately after the Schema Summary for the target conceptual shape, and WORKFLOWS.md §0 for the full, authoritative business workflow (including the exact membership-number format, payment-evidence handling, and promotion rules). The `memberships` and `membership_applications` table descriptions further down are kept as historical reverse-engineering record only (what the Lovable app actually built) — they are **not** the target design.

> **UPDATE (later confirmations OD-10 / OD-04):** where this section describes `membership_promotions`, activation-time promotion eligibility, a payment record "created only when payment is actually required" for a new member, or a membership number belonging to a single membership row, it is **superseded**: the first 6 months are free for every approved new member (a standard introductory term, not a promotion), payment applies to renewals only, and the membership number belongs to the **member** for life (`memberships` = stable member record, `membership_terms` = terms). See `docs/database/04_MEMBERSHIP_SCHEMA.md` and `05_PROMOTION_SCHEMA.md`.

## CONFIRMED Membership Data Model (supersedes legacy `memberships` + `membership_applications`)

**Status: CONFIRMED by ACI — authoritative and final. Conceptual only — no migrations have been created, per the Implementation Rule.** Full business workflow this supports is in WORKFLOWS.md §0.

Resolves LEGACY_RISKS.md §1 (two parallel, disconnected membership systems). The target relationship is a single chain: **Membership Application → Approval → Membership**, never two independent entities joined only by an email string.

Likely concepts (exact columns/types deferred to DATABASE DESIGN):

| Concept | Replaces (legacy) | Notes |
|---|---|---|
| `membership_categories` (or a repurposed `membership_plans`) | `membership_plans` (4 seeded plans, loosely related to the legacy `membership_type` check-constraint) | Exactly **three** categories, each with a one-letter code used in the membership number: Student=`S`, Professional=`P`, Veteran=`V` (WORKFLOWS.md §0.1). Names/pricing/duration TBD — see WORKFLOWS.md §0 "Remaining decisions" |
| `membership_applications` | legacy `membership_applications` (System B) | Same core idea (application + mandatory-for-all-3-categories aviation-proof documents, admin-reviewed before approval) but status vocabulary is now exactly **four** authoritative values: `submitted`, `more_details_required`, `approved`, `rejected` (WORKFLOWS.md §0.3) — a real enumerated set, not the legacy's unconstrained free-text `status` column, and notably **no** intermediate `under_review` state (see MySQL Migration Considerations). **CONFIRMED**: a rejected application is never overwritten — reapplication is allowed at any time with no cooldown, and every historical application row is retained (WORKFLOWS.md §0.14) |
| `memberships` | legacy `memberships` (System A) **and** the issuance fields legacy bolted onto `membership_applications` (`membership_number`, `issued_on`, `expires_on`, `verification_token`) | Created **only** on activation (WORKFLOWS.md §0.10), FK'd back to its originating application — not a separately-initiated table as System A was. Membership number follows the **authoritative 9-character format** `C+YY+RR+SSSS` (category letter, year, 2 random digits, 4-digit sequence, **per-category sequenced** — CONFIRMED, WORKFLOWS.md §0.11); generated only at activation, never at submission, and never consumed by a rejected or payment-pending application. References at most one `membership_promotions` row (never more than one — see below) |
| `membership_status_history` | *(did not exist in the legacy app)* | Audit trail of every application and membership status transition, admin decision, payment decision, and membership-number generation event (WORKFLOWS.md §0.16) — replaces the legacy pattern of overwriting a single `form_data.admin_requested_details` JSON note with no history |
| `membership_promotions` | *(did not exist in the legacy app)* | Admin-configurable: name, active/inactive, start date, end date, free-membership flag, free-duration-months, applicable categories, and a **priority/order** value — see WORKFLOWS.md §0.6. **CONFIRMED**: multiple promotion rows may exist and be simultaneously active; eligibility is evaluated at **activation** time (not application/approval time); if more than one active promotion is eligible for the same applicant, the priority/order value deterministically selects exactly one — a membership never receives more than one promotion (no stacking) unless ACI changes this rule in future |
| Payment records | legacy `memberships.payment_link`/`payment_amount`/`payment_status` (manual, unconstrained-string status) | Created **only when payment is actually required** — a free-promotion membership has no payment record with a zero amount; payment status must distinguish exactly the five confirmed states in WORKFLOWS.md §0.9 (`payment_not_required`, `payment_pending`, `payment_confirmation_submitted`, `payment_confirmed`, `payment_rejected`), and should accommodate both a payment reference and an uploaded evidence document, never a self-reported "I paid" flag alone |
| A dedicated bank/payment-details entity | *(did not exist in the legacy app; legacy hard-coded a mailbox address only)* | Admin-managed approved payment instructions (account name, bank name, account number, sort code/IBAN/SWIFT where applicable, instructions) — WORKFLOWS.md §0.9. Not folded into `site_settings`, not hard-coded into email templates |

This also resolves several specific legacy gaps documented elsewhere in this file and in LEGACY_RISKS.md: the unconstrained free-text status columns on both legacy tables, the missing audit trail for status changes, the plaintext-temp-password account provisioning (see AUTHORIZATION.md), the ad hoc `next_membership_number` numbering scheme (now a precisely specified, per-category-sequenced format — WORKFLOWS.md §0.11), and the two-systems duplication itself.

## Migration index

| ID | Filename (short) | Summary |
|----|---|---|
| M1 | `20260613093235` | Initial schema: enums, all core tables, RLS, triggers, `has_role`, `handle_new_user` |
| M2 | `20260613093304` | Tightens `subscribers`/`contact_enquiries` INSERT policies; revokes EXECUTE on `has_role`/`handle_new_user` |
| M3 | `20260613093408` | `storage.objects` RLS policies (avatars, blogs, jobs, hero-banners, team, testimonials, logos, documents) |
| M4 | `20260618053618` | Seed data (blog categories/posts, jobs, membership plans/benefits) |
| M5 | `20260623171237` | Adds `jobs.location_type`; creates `news_items`, `events` |
| M6 | `20260623175414` | Adds payment columns to `memberships`; one-off admin grant to a hardcoded email |
| M7 | `20260623181245` | One-off admin grant to a hardcoded user_id |
| M8 | `20260623182647` | Re-grants EXECUTE on `has_role` to `authenticated, anon` (reverses M2) |
| M9 | `20260718171153` | Creates `membership_applications` + RLS + trigger |
| M10 | `20260723154837` | Re-creates `blogs` bucket storage policies (drop-and-recreate, duplicates M3) |
| M11 | `20260723222730` | Adds `contact_enquiries.phone`; re-tightens INSERT policy |
| M12 | `20260813155922` | Data-only content update (hero banner copy) |
| M13 | `20260912115729` | Adds `membership_applications.membership_number` + sequence |
| M14 | `20260912115907` | Creates `next_membership_number()` no-arg (superseded same day) |
| M15 | `20260912122544` | Creates `next_membership_number(_category text)` (current version) |
| M16 | `20260912122601` | Drops the M14 no-arg overload; re-tightens grants |
| M17 | `20260912163130` | Adds `issued_on`/`expires_on`/`verification_token`; creates `verify_membership` |
| M18 | `20260912163143` | Re-revokes EXECUTE on `verify_membership`/`next_membership_number` |
| M19 | `20260912164903` | Creates shop module: `shop_categories`, `shop_products`, `shop_orders`, `shop_order_items` |

## Schema Summary

| Table | Purpose |
|---|---|
| `profiles` | Extended user profile, 1:1 with `auth.users` |
| `user_roles` | Role assignment (admin/member), separate from `profiles` for security |
| `membership_plans` | Paid membership tier catalog |
| `membership_benefits` | Benefit bullets per plan |
| `memberships` | **System A**: plan subscription/approval/payment record, tied to an existing logged-in profile |
| `membership_applications` | **System B**: public application form (no login required) with document uploads, membership-number/card issuance, verification token — see LEGACY_RISKS.md for the A/B conflict |
| `blog_categories`, `blog_posts`, `blog_tags`, `blog_post_tags`, `blog_comments` | Blog content + moderated comments |
| `jobs`, `job_applications` | Job board + applications |
| `contact_enquiries` | Public contact form submissions |
| `subscribers` | Newsletter list |
| `testimonials`, `faqs`, `team_members` | CMS content blocks |
| `notifications` | In-app per-user notifications |
| `activity_logs` | Admin-visible audit trail (polymorphic `entity`/`entity_id`, no FK) |
| `hero_banners`, `home_sections`, `social_links`, `footer_links`, `site_settings` | Site-wide CMS/config content |
| `email_templates` | Exists but **unused** — all emails are hardcoded TS templates, not read from this table |
| `seo_pages` | Per-page SEO overrides |
| `news_items`, `events` | Separate content types, added later, structurally near-identical to blog |
| `shop_categories`, `shop_products`, `shop_orders`, `shop_order_items` | Merchandise e-shop (added last, M19) |

`auth.users` (Supabase-managed) is FK'd from `profiles`, `user_roles`, `shop_orders`; it is not in these migrations — maps to Laravel's own `users` table.

## Enums

Defined in M1 only; nothing altered/dropped later.

| Enum | Values | Used by |
|---|---|---|
| `app_role` | `admin`, `member` | `user_roles.role` |
| `membership_status` | `pending`, `approved`, `rejected`, `suspended` | `memberships.status` |
| `blog_status` | `draft`, `published` | `blog_posts.status` |
| `comment_status` | `pending`, `approved`, `hidden` | `blog_comments.status` |
| `job_status` | `draft`, `published` | `jobs.status` |
| `application_status` | `applied`, `reviewed` | `job_applications.status` |
| `enquiry_status` | `new`, `replied`, `closed` | `contact_enquiries.status` |

Every table added **after** M1 (`news_items`, `events`, `memberships.payment_status`, `membership_applications.status`/`membership_type`, `shop_products.visibility`, `shop_orders.status`) uses plain `TEXT` (sometimes `CHECK`-constrained, sometimes not) instead of a native enum — a drift in convention (see LEGACY_RISKS.md).

## Tables

### profiles
`id` UUID PK = FK → `auth.users.id` CASCADE · `first_name`, `last_name` varchar(100) · `email` varchar(255) UNIQUE · `country` varchar(100) · `aviation_role` varchar(50) (free text — a *display* attribute, not the authorization role; naming collision flagged in AUTHORIZATION.md) · `other_role` text · `phone` varchar(30) · `occupation` varchar(150) · `company` varchar(150) · `linkedin_profile` text · `bio` text · `avatar_url` text · `is_active` boolean default true · timestamps.
RLS: SELECT `USING(true)` (public-readable); UPDATE/INSERT `auth.uid()=id`; admin ALL.
Row auto-created by `handle_new_user()` trigger on signup.

### user_roles
`id` UUID PK · `user_id` FK → `auth.users` CASCADE · `role` `app_role` · `created_at`. UNIQUE(`user_id`,`role`) — a user may hold both roles.
GRANTS: `authenticated` SELECT only (no client INSERT/UPDATE/DELETE grant at all).
RLS: SELECT own-or-admin; ALL admin-gated. This is the security-critical table — role checks always go through `has_role()` rather than direct joins, avoiding recursive RLS (a correct pattern worth preserving as a Gate/`hasRole()` check, not embedded joins).

### membership_plans / membership_benefits
Plans: `id`, `name`, `slug` UNIQUE, `price` decimal(10,2), `price_usd` nullable, `description`, `duration_months` default 12, `is_active`, `display_order`. Benefits: `id`, `plan_id` FK CASCADE, `title`, `display_order`. Both: public SELECT `USING(true)`, admin-managed writes. Seeded in M4 (Free/Student Premium/Professional Premium/School Club).

### memberships (System A) — LEGACY RECORD ONLY, superseded by the CONFIRMED Membership Data Model above
`id`, `user_id` FK→profiles CASCADE, `plan_id` FK→membership_plans, `status` `membership_status` default `pending`, `application_data` jsonb, `start_date`, `expiry_date`, `approved_by`, `approved_at`, timestamps, plus (M6) `payment_link` text, `payment_amount` numeric, `payment_status` text default `'unpaid'` (**unconstrained free text**, inconsistent with the enum `status` column), `payment_sent_at`.
RLS: SELECT/INSERT own; admin ALL (covers approval/payment UPDATE).

### membership_applications (System B) — LEGACY RECORD ONLY, superseded by the CONFIRMED Membership Data Model above
`id`, `membership_type` text CHECK IN (`student`,`professional`,`veteran`), `full_name`, `email`, `mobile`, `address`, `form_data` jsonb default `{}`, `documents` jsonb default `[]`, `status` text default `'submitted'` (**no CHECK enumerating allowed values** — only `submitted` and `approved` are confirmed in SQL; `rejected`/`more_details_requested` are inferred from application code, not the schema), `notes`, timestamps, plus (M13/M17) `membership_number` text UNIQUE, `issued_on` date, `expires_on` date, `verification_token` text with a partial UNIQUE index (`WHERE verification_token IS NOT NULL`).
GRANTS: `anon` INSERT (public can apply); `authenticated` full CRUD; `service_role` ALL.
RLS: INSERT `WITH CHECK(true)` for `anon,authenticated`; SELECT/UPDATE/DELETE admin-only (**an applicant cannot view their own submitted application** through RLS — by design, since this is a public/anonymous-first form).

### blog_categories / blog_posts / blog_tags / blog_post_tags / blog_comments
`blog_posts`: title, slug UNIQUE, excerpt, content (HTML), featured_image, reading_time default 5, `status` `blog_status` default `draft`, meta_title/description, og_image, published_at, author_id FK→profiles SET NULL, category_id FK→blog_categories SET NULL. RLS SELECT `status='published' OR admin`; only admins have write grant (no member authoring).
`blog_comments`: blog_id FK CASCADE, user_id FK CASCADE, `parent_comment_id` self-FK (threading support, unused in UI), comment text, `status` `comment_status` default `pending`. RLS SELECT `approved OR own OR admin`; INSERT own; UPDATE/DELETE own-or-admin. **No `WITH CHECK` on UPDATE** — a user could reassign `user_id` via UPDATE (latent gap, close explicitly in Laravel policy).

### jobs / job_applications
`jobs`: title, company, location, employment_type (free text), salary (free text, not numeric), description/requirements/application_instructions (HTML), external_link, `status` `job_status` default `draft`, posted_by FK SET NULL, published_at, plus (M5) `location_type` text CHECK IN (`local`,`overseas`) default `local`. RLS SELECT published-or-admin; admin writes.
`job_applications`: job_id FK CASCADE, user_id FK CASCADE, `status` `application_status` default `applied`. UNIQUE(job_id,user_id). GRANTS: `authenticated` SELECT/INSERT only (status changes are admin-only via the admin ALL policy).

### contact_enquiries
name, email, subject, message, `status` `enquiry_status` default `new`, `phone` (M11, nullable column despite the INSERT policy requiring it non-null at insert time).
RLS INSERT (public, no role restriction) `WITH CHECK` embeds full field-length/regex validation directly in the policy (name 1-150, email format regex, message 1-5000, phone E.164-ish 4-30) — **this validation logic must move to Laravel Form Requests, not a DB constraint**. SELECT/UPDATE/DELETE admin-only; UPDATE has no `WITH CHECK` (same latent gap pattern).

### subscribers
email UNIQUE, is_active default true. RLS: public INSERT (with regex/length WITH CHECK); admin-only SELECT/UPDATE/DELETE — **no public self-unsubscribe policy**, so any "unsubscribe" link must go through a privileged backend endpoint.

### testimonials / faqs / team_members
Simple CMS content tables, public read (`USING(true)`, no `is_active` filter at the RLS level — app must filter client/query-side), admin write. `faqs`/`team_members` have **no timestamp columns** (inconsistent with the rest of the schema).

### notifications
user_id FK CASCADE, title, message, `is_read` default false. GRANTS: `authenticated` SELECT/UPDATE only — **no INSERT grant for members**, notifications are created only by admin/service_role paths. RLS UPDATE has no `WITH CHECK` (same latent gap).

### activity_logs
user_id FK SET NULL, action, entity (varchar), entity_id (UUID, **no FK — deliberately polymorphic**). GRANTS: `authenticated` SELECT only; writes are service_role-only. RLS SELECT admin-only — **regular users cannot see even their own activity**.

### hero_banners / home_sections / social_links / footer_links / site_settings / email_templates / seo_pages
All public-read, admin-write CMS tables. Notable gaps:
- `site_settings`: de-facto singleton by convention only, **no DB constraint** prevents multiple rows; **no `updated_at` trigger** (only table missing one despite having the column); `facebook_url`/`instagram_url`/etc. columns exist but appear **unused** — the Footer actually renders from the separate `social_links` table.
- `email_templates`: `authenticated` SELECT grant exists but **no matching RLS SELECT policy** (only the admin ALL policy provides SELECT) — a non-admin has table GRANT but zero visible rows. Also **confirmed unused by application code** — every email is a hardcoded TS template literal, not read from this table.
- `home_sections.section_name` has no UNIQUE constraint despite acting as a lookup key (UNCERTAIN whether the app treats it as unique in practice).

### news_items / events
Near-identical shape: title, slug UNIQUE, excerpt, content, image_url, `status` text CHECK IN (`draft`,`published`) default `draft` (free-text, unlike blog's proper enum), published_at, `created_by` (**no FK** — unlike `blog_posts.author_id` which has one). `events` adds `event_date` timestamptz, `location`. GRANTS on both give `authenticated` full CRUD at the table level, but RLS still restricts writes to admin — grant is broader than what RLS ultimately allows. RLS SELECT for `news_items`/`events` has **no `OR has_role(admin)` clause** (unlike `blog_posts`) — admins see drafts only via the separate "manage all" ALL policy, a different code path than blog uses.

### shop_categories / shop_products / shop_orders / shop_order_items
Added last (M19). `shop_products`: category_id FK SET NULL, name, slug UNIQUE, short_description, description, price numeric default 0, image_url, `visibility` text CHECK IN (`public`,`members`) default `public`, is_active, display_order. RLS: three permissive SELECT policies OR'd — anon sees `is_active AND visibility='public'`; any authenticated user sees `is_active` regardless of visibility (i.e. no membership-tier distinction, just "logged in or not"); admin ALL.
`shop_orders`: **`user_id` FKs to `auth.users` directly, not `profiles`** — the only table with this inconsistency. `customer_name/email/phone`, `shipping_address`, `notes`, `total`, `status` text default `'new'` (**zero CHECK, the least-constrained status column in the schema — and in practice never updated by any code path found**). GRANTS: `authenticated` SELECT/INSERT only (no UPDATE — status changes are service-role-only, and no such function exists). RLS SELECT own-or-admin. **Guest (anon) checkout is not actually possible via this table's RLS/GRANT** despite `user_id` being nullable (schema was seemingly designed to support it, but the grant only covers `authenticated`) — see LEGACY_RISKS.md.
`shop_order_items`: order_id FK CASCADE, product_id FK SET NULL, `product_name`/`unit_price` **denormalized snapshots** (correct practice — preserves history). GRANTS: `authenticated` SELECT only — items can only be created via service-role (checkout server function).

## Functions / RPCs / Triggers

| Function | Security | Purpose |
|---|---|---|
| `has_role(_user_id, _role)` | `SECURITY DEFINER`, EXECUTE flip-flopped between migrations — **currently granted to `authenticated, anon`** (see LEGACY_RISKS.md #1) | `EXISTS(SELECT 1 FROM user_roles WHERE user_id=... AND role=...)`. Called from nearly every RLS policy and from `src/lib/admin.functions.ts`/`src/routes/auth.tsx` directly |
| `handle_new_user()` | `SECURITY DEFINER`, trigger-only (EXECUTE revoked from all client roles) | `AFTER INSERT ON auth.users` → inserts `profiles` row from `raw_user_meta_data` + a `user_roles` row with role `member`. Laravel: a `Registered` listener, not a DB trigger |
| `update_updated_at_column()` | invoker, not definer | Generic `BEFORE UPDATE` → `NEW.updated_at = now()`. Laravel: native Eloquent timestamp handling replaces this entirely — no port needed |
| `next_membership_number(_category text DEFAULT 'member')` | `SECURITY DEFINER`, EXECUTE restricted to `service_role` only (re-revoked from anon/authenticated three separate times across M15/M16/M18 — see LEGACY_RISKS.md #2-3) | Maps category→letter (student=S, professional=P, veteran=V, else=M), pulls `nextval(membership_number_seq)`, formats `LETTER+YY+2-random-digits+4-seq-digits`. True uniqueness guarantee is the column's `UNIQUE` constraint, not the random component. Called only from `src/lib/admin.functions.ts` via the service-role client |
| `verify_membership(_token text)` | `SECURITY DEFINER`, EXECUTE `service_role`-only | Returns `valid/membership_number/full_name/expires_on` for a `membership_applications` row where `status='approved' AND membership_number/issued_on/expires_on all set AND current_date BETWEEN issued_on AND expires_on` — re-derives validity live on every call, no batch expiry job needed. Called from `src/lib/verify.functions.ts` |

## Storage buckets & policies

Bucket **creation itself is not in any migration** — buckets (`avatars`, `blogs`, `jobs`, `hero-banners`, `team`, `testimonials`, `logos`, `documents`) are referenced only as string literals inside RLS policies, implying manual creation via the Supabase dashboard. Full bucket-by-bucket usage and access rules are in **STORAGE.md**; the RLS policy text itself:
- SELECT (public read) for 7 buckets excluding `documents`.
- INSERT/UPDATE/DELETE own-avatar, folder-scoped to `{user_id}/...`.
- ALL for admins across all 8 buckets including `documents`.
- M10 redundantly re-declares narrower `blogs`-specific policies already covered by M3 (see LEGACY_RISKS.md #6).

## LEGACY / TECHNICAL DEBT SIGNALS

Full detail and Laravel-facing recommendations in **LEGACY_RISKS.md**. Summary list (all sourced to specific migrations):
1. `has_role` EXECUTE privilege flip-flop — revoked then re-granted 10 days later, evidence of a production incident.
2. `next_membership_number` — three revisions within 27 minutes on the same day; abandoned no-arg version replaced by the category-letter version.
3. Redundant repeated REVOKE/GRANT on the same two functions across 3 migrations.
4. **Two overlapping "become a member" flows** (`memberships` vs `membership_applications`) with no FK between them. **RESOLVED (2026-09-15)** — see "CONFIRMED Membership Data Model" near the top of this file and WORKFLOWS.md §0: ACI has confirmed a single unified `Membership Application → Approved → Membership` relationship as the target design.
5. Hardcoded one-off admin-privilege grants shipped as permanent, replayable migrations (a specific email, a specific UUID).
6. Storage policy drop-and-recreate for `blogs` bucket duplicates existing M3 policies.
7. Inconsistent status-column typing: proper Postgres enum (M1 tables) vs free-text/CHECK (every table added afterward).
8. Missing `WITH CHECK` on several UPDATE policies (`blog_comments`, `contact_enquiries`, `notifications`).
9. No bucket-creation DDL found anywhere.
10. `shop_orders.user_id` FKs to `auth.users` directly, unlike every other user-referencing table (→ `profiles`).

## MySQL Migration Considerations

**Cross-cutting:**
- **UUID PKs** (`gen_random_uuid()`): re-evaluate rather than defaulting to UUID-everywhere — Laravel's standard auto-increment `BIGINT UNSIGNED` is simpler unless a public-facing opaque ID is actually needed (as `membership_number`/`verification_token` already provide).
- **`TIMESTAMPTZ`** → MySQL `TIMESTAMP`/`DATETIME`, store UTC consistently (Laravel default).
- **`JSONB`** (`memberships.application_data`, `membership_applications.form_data`/`documents`) → MySQL `JSON`; consider normalizing `documents` into a real table if per-document querying is ever needed (MySQL JSON querying is materially weaker than Postgres JSONB).
- **Native Postgres enums** → recommend PHP backed enums + `VARCHAR` column (adding a value doesn't require `ALTER TABLE`).
- **Row Level Security has no MySQL equivalent.** This is the single most important structural gap: every `USING`/`WITH CHECK` policy becomes a Laravel Policy/Gate (ownership) or a **global Eloquent scope** (visibility, e.g. `PublishedScope` for `status='published' OR admin`) — unlike RLS, forgetting to apply a scope in a new controller method silently exposes draft/admin-only data, since Eloquent has no automatic per-query row filtering.
- **`nextval()`/`CREATE SEQUENCE`** (`membership_number_seq`) → MySQL has no native sequence object; use a counter table with `lockForUpdate()`/`DB::transaction`, or an auto-increment column if strict ordering matters more than the letter/year/random format.
- **Partial unique index** (`verification_token WHERE ... IS NOT NULL`) → MySQL treats multiple NULLs in a UNIQUE index as distinct by default, so a plain UNIQUE index should suffice (confirm for the chosen MySQL version).
- **Regex/length CHECKs embedded in RLS policies** (email format, string lengths) → move entirely to Laravel Form Request rules, not DB constraints.
- **Storage buckets**: no SQL to port (not even defined in migrations) — design Laravel filesystem disks fresh; see STORAGE.md.
- **`auth.users`**: replaced entirely by Laravel's own `users` table; decide whether `profiles` merges into `users` or stays a 1:1 extension table.

**Per-table specifics:** `activity_logs.entity`/`entity_id` → Laravel polymorphic relation (`morphTo`) or an activity-log package. All money columns (`shop_products.price`, `shop_orders.total`, `membership_plans.price`/`price_usd`) → explicit `DECIMAL(10,2)` everywhere (several are bare `numeric` with no declared precision in Postgres).

## UNCERTAIN (per the Uncertainty Rule — do not guess before ARCHITECTURE)

- Exact Supabase Storage bucket configuration (public/private flag, size/mime limits) — no creation DDL exists.
- Full set of valid `membership_applications.status` values beyond `submitted`/`approved` (no CHECK enumerates them).
- Whether `site_settings`/`home_sections.section_name` singleton/uniqueness is enforced anywhere outside application code.
- Whether guest checkout for `shop_orders` was ever actually functional given the RLS/GRANT gap.
- Whether client code retries on `membership_number` unique-constraint collisions.
- Whether `memberships` and `membership_applications` are both still-live parallel flows or one has been effectively abandoned — needs a business decision, not an inference.

*Per the project's Approval Gate: this document is for review only.*
