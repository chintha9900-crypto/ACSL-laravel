# 10 — Content / CMS Schema

Design only. 17 tables. Column evidence for legacy content tables comes from the reference migrations (`supabase/migrations/20260613093235…`, `20260623171237…`) and `docs/reverse-engineering/DATABASE.md`; the architecture is `10_CONTENT_ARCHITECTURE.md`.

Legend: **N** = `NOT NULL`, **Y** = nullable. All FKs `ON UPDATE RESTRICT`. Public images are stored as `*_path` on the **`public` disk** (never in `documents`).

## 1. Decision matrix — every legacy/considered content table

| Table (legacy → target) | Decision | Reason |
|---|---|---|
| `blog_categories` | **KEEP** | Real taxonomy |
| `blog_posts` | **REWORK** | Adds real `MEDIUMTEXT` content, drops derived `reading_time`, path columns, sanitised HTML |
| `blog_tags` | **KEEP** | |
| `blog_post_tags` → `blog_post_blog_tag` | **KEEP** (Laravel pivot name) | |
| `blog_comments` | **REWORK** | Drops the unused `parent_comment_id` threading (**DEFER**), typed `status`, restricts author FK |
| `news_items` | **KEEP** (separate from blog — architecture `10` §3, OD #18) | Merge into blog **not** confirmed |
| `events` → `event_listings` | **REWORK** (renamed to match the `EventListing` model; avoids the "Event" class collision) | |
| `event_registrations` | **DEFER** | No business rules exist (OD-17) |
| `faqs`, `testimonials`, `team_members`, `hero_banners` | **KEEP** (add timestamps; upload paths instead of URLs) | Uniform CrudManager entities |
| `home_sections` | **DEFER (not carried)** | Homepage About/Benefits are hard-coded in the legacy app; `section_name` had no uniqueness; no confirmed requirement |
| `site_settings` | **REWORK** → enforced singleton; social `*_url` columns **dropped** (single source: `social_links`) | Resolves duplication flagged in `FEATURES.md` |
| `social_links`, `footer_links` | **KEEP** (+`is_active`) | |
| `seo_pages` | **KEEP** | |
| `pages` (generic CMS Page) | **DEFER** — see §14 | New scope, not confirmed (OD-17) |
| `email_templates` | **REWORK** → in `08` | |
| `subscribers` | **REWORK** | `is_active` → `unsubscribed_at` |
| `contact_enquiries` | **KEEP** | |
| `membership_benefits` | **DEFER** | Static page today |
| `activity_logs` | **REWORK** → `audit_logs` (`09`) | |
| `notifications` | **KEEP** → `08` | |

## 2. `blog_categories`

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id` | BIGINT UNSIGNED AI | N | — | PK |
| `name` | VARCHAR(100) | N | — | |
| `slug` | VARCHAR(100) | N | — | **UNIQUE** |
| `created_at`, `updated_at` | TIMESTAMP | Y | NULL | |

Soft delete NO. Delete is allowed; posts survive (`SET NULL`, §3).

## 3. `blog_posts`

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id` | BIGINT UNSIGNED AI | N | — | PK |
| `author_id` | BIGINT UNSIGNED | Y | NULL | FK → `users`, **SET NULL** |
| `blog_category_id` | BIGINT UNSIGNED | Y | NULL | FK → `blog_categories`, **SET NULL** |
| `title` | VARCHAR(255) | N | — | |
| `slug` | VARCHAR(255) | N | — | **UNIQUE** (real DB constraint + friendly Form Request message) |
| `excerpt` | TEXT | Y | NULL | |
| `content` | MEDIUMTEXT | N | — | Allow-list-sanitised HTML on save (ADR-14) |
| `featured_image_path` | VARCHAR(255) | Y | NULL | `public` disk |
| `featured_image_alt` | VARCHAR(255) | Y | NULL | accessibility (new) |
| `status` | VARCHAR(20) | N | `'draft'` | `draft`, `published` (enum only) |
| `published_at` | TIMESTAMP | Y | NULL | Stamped by an Action on **any** transition into `published` (fixes the legacy create-only bug) |
| `meta_title` | VARCHAR(255) | Y | NULL | |
| `meta_description` | TEXT | Y | NULL | |
| `og_image_path` | VARCHAR(255) | Y | NULL | |
| `created_at`, `updated_at` | TIMESTAMP | Y | NULL | |

Dropped: `reading_time` (derived from content — an accessor). Indexes: `UNIQUE(slug)`, `(status, published_at)` (public list), FKs `author_id`, `blog_category_id`. Search is title-only (`LIKE`) at club scale; a `FULLTEXT(title, excerpt)` index is an optional later optimisation, not part of the baseline. Soft delete **NO** (draft status unpublishes; deletion is a deliberate confirmed admin action). CHECK: `status = 'draft' OR published_at IS NOT NULL` is **not** used — publication stamping is an Action rule.

## 4. `blog_tags` and `blog_post_blog_tag`

`blog_tags`: `id` PK, `name VARCHAR(100) N`, `slug VARCHAR(100) N UNIQUE`, timestamps.
`blog_post_blog_tag`: `blog_post_id` FK → `blog_posts` **CASCADE**, `blog_tag_id` FK → `blog_tags` **CASCADE**, composite PK (`blog_post_id`, `blog_tag_id`), secondary index `(blog_tag_id, blog_post_id)`. Pure link rows — CASCADE is safe.

## 5. `blog_comments` — Community

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id` | BIGINT UNSIGNED AI | N | — | PK |
| `blog_post_id` | BIGINT UNSIGNED | N | — | FK → `blog_posts`, **CASCADE** (comments have no life beyond their post) |
| `user_id` | BIGINT UNSIGNED | N | — | FK → `users`, **RESTRICT** (authorship preserved) |
| `comment` | TEXT | N | — | Max length via Form Request (legacy limit 2000) |
| `status` | VARCHAR(20) | N | — | `pending`, `approved`, `hidden`. **No DB default** — the moderation default (`pending` vs `approved`) is configuration, not schema (architecture OD #17). |
| `created_at`, `updated_at` | TIMESTAMP | Y | NULL | |

Indexes: `(blog_post_id, status, created_at)` (public thread), `(user_id, created_at)` (member's own comments), `(status, created_at)` (moderation queue). Threading (`parent_comment_id`) **DEFER** — unused in the legacy UI. Author-immutability (`user_id` cannot be reassigned by an update) is a Form Request whitelist rule (closes the legacy `WITH CHECK` gap). Soft delete NO (a "hidden" status covers moderation).

## 6. `news_items`

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id` | BIGINT UNSIGNED AI | N | — | PK |
| `title` | VARCHAR(255) | N | — | |
| `slug` | VARCHAR(255) | N | — | **UNIQUE** |
| `excerpt` | TEXT | Y | NULL | |
| `content` | MEDIUMTEXT | N | — | sanitised HTML |
| `image_path` | VARCHAR(255) | Y | NULL | |
| `status` | VARCHAR(20) | N | `'draft'` | `draft`, `published` |
| `published_at` | TIMESTAMP | Y | NULL | |
| `created_by_user_id` | BIGINT UNSIGNED | Y | NULL | FK → `users`, SET NULL (legacy had no FK) |
| `created_at`, `updated_at` | TIMESTAMP | Y | NULL | |

Indexes: `UNIQUE(slug)`, `(status, published_at)`, FK index. Soft delete NO.

## 7. `event_listings`

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id` | BIGINT UNSIGNED AI | N | — | PK |
| `title` | VARCHAR(255) | N | — | |
| `slug` | VARCHAR(255) | N | — | **UNIQUE** (legacy did not auto-slugify; the Action does) |
| `excerpt` | TEXT | Y | NULL | |
| `content` | MEDIUMTEXT | N | — | Rendering as plain text vs HTML is undecided (architecture OD #19); the column type is identical either way |
| `image_path` | VARCHAR(255) | Y | NULL | (legacy: pasted URL) |
| `starts_at` | TIMESTAMP | N | — | Legacy `event_date` |
| `location` | VARCHAR(255) | Y | NULL | |
| `status` | VARCHAR(20) | N | `'draft'` | `draft`, `published` |
| `published_at` | TIMESTAMP | Y | NULL | |
| `created_by_user_id` | BIGINT UNSIGNED | Y | NULL | FK → `users`, SET NULL |
| `created_at`, `updated_at` | TIMESTAMP | Y | NULL | |

Indexes: `UNIQUE(slug)`, `(status, starts_at)` (upcoming events; the legacy listed past events forever — filtering is a query concern), FK index. `ends_at`, capacity, registration: not modelled (no confirmed requirement). Soft delete NO.

## 8. Uniform CrudManager entities: `faqs`, `testimonials`, `team_members`, `hero_banners`

All: `id` PK, `display_order SMALLINT UNSIGNED N DEFAULT 0`, `is_active TINYINT(1) N DEFAULT 1`, `created_at`/`updated_at` (added — the legacy lacked timestamps on `faqs`/`team_members`). Index `(is_active, display_order)`. Soft delete NO.

| Table | Entity-specific columns |
|---|---|
| `faqs` | `question VARCHAR(500) N`, `answer TEXT N` |
| `testimonials` | `member_name VARCHAR(150) N`, `designation VARCHAR(150) Y`, `photo_path VARCHAR(255) Y`, `testimonial TEXT N` |
| `team_members` | `name VARCHAR(150) N`, `position VARCHAR(150) Y`, `photo_path VARCHAR(255) Y`, `bio TEXT Y` |
| `hero_banners` | `title VARCHAR(255) N`, `subtitle TEXT Y`, `image_path VARCHAR(255) Y`, `button_text VARCHAR(100) Y`, `button_link VARCHAR(500) Y` |

`team_members` carries **no** `group`/`type` column yet: whether "advisory members" are a display variant of team members (working assumption, architecture `17` §6) is undecided (OD-16); adding a `group` column later is additive. `photo_path`/`image_path` replace the legacy pasted external URLs with uploads on the `public` disk (an upload-mechanism decision the architecture already made for consistency).

## 9. `site_settings` — enforced singleton

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id` | TINYINT UNSIGNED | N | 1 | PK; `CHECK (id = 1)` — a second row cannot exist |
| `site_name` | VARCHAR(255) | N | — | **No legacy default** ("ACSL Aviation Club" is not carried) |
| `site_description` | TEXT | Y | NULL | |
| `contact_email` | VARCHAR(255) | Y | NULL | |
| `phone` | VARCHAR(50) | Y | NULL | |
| `address` | TEXT | Y | NULL | |
| `logo_path` | VARCHAR(255) | Y | NULL | `public` disk |
| `favicon_path` | VARCHAR(255) | Y | NULL | |
| `mail_from_address` | VARCHAR(255) | Y | NULL | Source for `config('mail.from')` (architecture `06` §5) |
| `mail_from_name` | VARCHAR(150) | Y | NULL | |
| `updated_by_user_id` | BIGINT UNSIGNED | Y | NULL | FK → `users` RESTRICT |
| `created_at`, `updated_at` | TIMESTAMP | Y | NULL | (legacy lacked the update trigger) |

Dropped: `facebook_url`, `linkedin_url`, `instagram_url`, `youtube_url` (unused; `social_links` is the source). Access: `SiteSetting::current()` = `firstOrCreate(['id' => 1])`. Changes audit-logged. **Bank/payment details and membership settings are deliberately not here** (`06`, `04`).

## 10. `social_links`, `footer_links`

`social_links`: `platform VARCHAR(50) N`, `url VARCHAR(500) N`, `icon VARCHAR(50) Y`, `display_order`, `is_active`, timestamps.
`footer_links`: `title VARCHAR(150) N`, `url VARCHAR(500) N`, `display_order`, `is_active`, timestamps.
Both indexed `(is_active, display_order)`. The footer's two hard-coded legal links (Privacy/Terms) remain code; these rows are appended (legacy hybrid pattern preserved). No uniqueness on `platform` (two links to one platform are not forbidden by any confirmed rule).

## 11. `seo_pages`

`id`, `page_name VARCHAR(100) N UNIQUE` (route key, e.g. `home`, `blog.index`), `meta_title VARCHAR(255) Y`, `meta_description TEXT Y`, `og_image_path VARCHAR(255) Y`, `canonical_url VARCHAR(500) Y`, timestamps. Per-item SEO for blog posts lives on `blog_posts.meta_*`. JSON-LD structured data: not modelled (architecture OD #23).

## 12. `subscribers` — REWORK

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id` | BIGINT UNSIGNED AI | N | — | PK |
| `email` | VARCHAR(255) | N | — | **UNIQUE**, lower-cased |
| `unsubscribed_at` | TIMESTAMP | Y | NULL | NULL = subscribed. Replaces `is_active` (one source of truth, and it records *when*). Admin toggle sets/clears it. |
| `created_at`, `updated_at` | TIMESTAMP | Y | NULL | `created_at` = subscription time |

Index: `UNIQUE(email)`. The legacy lacked a public self-unsubscribe path; a signed-URL unsubscribe action needs no extra column. Consent evidence (IP/time/source) is **not** modelled — no confirmed requirement (flag for legal review if newsletters are sent). Soft delete NO.

## 13. `contact_enquiries`

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id` | BIGINT UNSIGNED AI | N | — | PK |
| `name` | VARCHAR(150) | N | — | |
| `email` | VARCHAR(255) | N | — | |
| `phone` | VARCHAR(40) | Y | NULL | |
| `subject` | VARCHAR(255) | Y | NULL | |
| `message` | TEXT | N | — | length via Form Request (legacy ≤ 5000) |
| `status` | VARCHAR(20) | N | `'new'` | `new`, `replied`, `closed` (enum only) |
| `handled_by_user_id` | BIGINT UNSIGNED | Y | NULL | FK → `users` RESTRICT — admin who last set the status |
| `created_at`, `updated_at` | TIMESTAMP | Y | NULL | |

Indexes: `(status, created_at)`, FK index. The legacy's regex/length validation embedded in RLS policies moves to a Form Request. No in-app reply mechanism is modelled (legacy had none; new scope). Enquiry PII retention: OD-11. Soft delete NO.

## 14. Deferred structures (documented, **not** proposed as tables)

* **`pages`** (generic CMS Page, architecture `10` §6) — *minimum safe shape if ACI approves:* `id`, `slug UNIQUE`, `title`, `content MEDIUMTEXT` (sanitised), `status`, `published_at`, `created_by_user_id SET NULL`, timestamps; SEO via `seo_pages`. Not mandatory: static/legal pages may stay as developer-maintained views (OD-17).
* **`event_registrations`** — no rules exist; a table would invent capacity/waitlist/payment semantics. Reserved conceptually only.
* **`partners`** — undefined concept (OD-16). If defined it is expected to be another uniform CrudManager table (`name`, `logo_path`, `description`, `url`, `display_order`, `is_active`).

## 15. Visibility rule

"Published/active only" for public reads is applied by **global Eloquent scopes** (`PublishedScope`, `ActiveScope`; ADR-18), replacing Postgres RLS. The schema supports it with `(status, published_at)` and `(is_active, display_order)` indexes.
