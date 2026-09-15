# Routes

Every route in the reference app (`src/routes/*.tsx`, TanStack Router file-based routing), what it does, its auth requirement, and its key data dependencies. Full behavioural detail for each is in FEATURES.md; workflows spanning multiple routes are in WORKFLOWS.md.

## Public marketing & content

| Route | Purpose | Auth | Key data |
|---|---|---|---|
| `/` | Home / landing | Public | `hero_banners` (active), `blog_posts` (latest 3 published). `testimonials`/`jobs`/`membership_plans` fetched but **unused** on this page (dead fetch) |
| `/about` | Static About Us | Public | None — 100% hardcoded copy incl. placeholder stats |
| `/contact` | Contact info + enquiry form | Public (form write is unauthenticated) | Writes `contact_enquiries`; triggers admin email. Displayed contact info is hardcoded, **not** from `site_settings` (inconsistent with Footer) |
| `/blog` | Blog listing, search, category filter, pagination (9/page) | Public | `blog_posts` (published), `blog_categories` |
| `/blog/$slug` | Blog detail + comments | Public read; comment POST requires auth | `blog_posts` (published, by slug), `blog_comments` (auto-approved — no real moderation gate despite `status` column), `profiles` (comment author) |
| `/news-events` | Combined News + Events listing (30 each, no pagination) | Public | `news_items` (published), `events` (published) — via a different (anon-key/RLS) client than blog/jobs |
| `/news/$slug` | News detail | Public | `news_items` (published, by slug) |
| `/events/$slug` | Event detail (content rendered as plain text, unlike blog/news HTML) | Public | `events` (published, by slug) |
| `/jobs` | Job board, master-detail, client-side-only filtering (server filter params exist but unused) | Public | `jobs` (published, up to 50) |
| `/membership` | Layout wrapper only | Public | None |
| `/membership/` (index) | Redirects to `/membership/benefits` | Public | None |
| `/membership/benefits` | Tiers/pricing (100% hardcoded, despite matching `membership_plans`/`membership_benefits` tables existing) + non-persisted eligibility checkers | Public | None fetched |
| `/membership/apply` | Pre-application forms: Student / Professional / Veteran, each with file upload | Public (unauthenticated write) | Writes `membership_applications`; duplicate-checks same table; uploads to `documents` storage bucket; sends applicant + admin emails |
| `/membership/faq` | FAQ accordion (genuinely DB-driven) | Public | `faqs` (active) |
| `/membership/rules` | Static rules/code of conduct; "I agree" CTA is link-only, no consent capture | Public | None |
| `/privacy` | Privacy Policy — **placeholder Lorem Ipsum**, not real content | Public | None |
| `/terms` | Terms & Conditions — **placeholder Lorem Ipsum**, not real content | Public | None |
| `/sitemap.xml` | XML sitemap — **broken**: relative (non-absolute) URLs, missing several real pages, duplicate `/jobs` entries | Public | `blog_posts`, `jobs` (published) |
| (global) Header | Nav, Sign In / Join Now CTAs | Public | None (hardcoded logo asset, not `site_settings.logo_url`) |
| (global) Footer | About blurb, nav, legal, contact, social | Public | `site_settings`, `social_links`, `footer_links` via root loader |
| (global) 404 / error boundary | Not-found + crash fallback | Public | None |

## Auth

| Route | Purpose | Auth | Key data |
|---|---|---|---|
| `/auth` | Sign in / sign up / forgot password (tabs) | Public | `auth.users`, `profiles`, `user_roles` (via `handle_new_user` trigger) |
| `/reset-password` | Consume password-recovery link, set new password | Public (requires valid Supabase recovery session) | `auth.users` |
| `/verify/$token` | Public "verify this membership card" page | Public | `membership_applications` via `verify_membership` RPC |
| `/api/public/membership-qr/$token.png` | Public QR image endpoint (encodes verify URL only, no personal data) | Public | None (image generation only) |

## E-Shop

| Route | Purpose | Auth | Key data |
|---|---|---|---|
| `/shop` | Product catalog + category filter (client-side) | Public; `members`-visibility products additionally require an authenticated session (enforced by RLS today) | `shop_categories`, `shop_products` (active) |
| `/shop/$slug` | Product detail | Public (same visibility rule) | `shop_products` (active, by slug) — cannot distinguish "not found" from "hidden, members-only" |
| `/shop/cart` | Cart view/edit | Public | None (client `localStorage` only, no DB table) |
| `/shop/checkout` | Checkout form (name/email/phone/address/notes) | Public — guest checkout allowed, no login required | Writes `shop_orders` + `shop_order_items`; re-derives price/name server-side; no payment gateway exists — "payment arranged directly with the club after" |
| `/shop/order/$orderId` | Order confirmation ("Thank you") | **Public by UUID only — no ownership/auth check** | Reads `shop_orders` + `shop_order_items` by id |

## Member dashboard (`/dashboard/**`, under `_authenticated`)

Auth requirement for all: any authenticated user (any role). Ownership enforced by both handler-level `.eq("user_id", ...)` filters and RLS.

| Route | Purpose | Key data |
|---|---|---|
| `/dashboard` (shell) | Sidebar nav, conditional Admin Panel link, sign out | `checkIsAdmin` (`has_role` RPC) |
| `/dashboard` (index) | Overview: profile, latest membership, notifications, job applications | `profiles`, `memberships`, `notifications`, `job_applications`; `blog_comments` fetched but unused |
| `/dashboard/applications` | List own job applications (read-only) | `job_applications` joined `jobs` |
| `/dashboard/billing` | List own System-A `memberships` rows with payment status/link | `memberships` joined `membership_plans` — **not real billing**, a manual admin-set payment-link workflow |
| `/dashboard/comments` | List + delete own blog comments | `blog_comments` joined `blog_posts` |
| `/dashboard/membership` | List own System-A `memberships` history | `memberships` joined `membership_plans` |
| `/dashboard/notifications` | List/mark-read in-app notifications (up to 100) | `notifications` |
| `/dashboard/password` | Dedicated password-change page (clears `must_change_password`) | `auth.users` only |
| `/dashboard/profile` | Edit profile, avatar upload, duplicate inline password form (does **not** clear `must_change_password` — bug) | `profiles`; cross-table lookup into `membership_applications` for membership number display |
| `/dashboard/promotions` | "Promotions" grid — actually just `membership_benefits`, unscoped/unpersonalized | `membership_benefits` |
| `/dashboard/refer` | Refer-a-friend: single email invite, no code/tracking/reward | None persisted; reads own `profiles` name only |

## Admin panel (`/admin/**`, under `_authenticated`, admin role required)

Client-side layout guard is UX-only; the real gate is server-side `assertAdmin` on every function (see AUTHORIZATION.md).

| Route | Purpose | Key entity |
|---|---|---|
| `/admin` (shell) | Sidebar nav (19 links), `checkIsAdmin` client guard | — |
| `/admin` (index) | Overview: row counts + pending-item badges | `profiles`, `memberships`, `blog_posts`, `blog_comments`, `jobs`, `job_applications`, `contact_enquiries`, `subscribers` |
| `/admin/applications` | Job applications, inline status change | `job_applications` |
| `/admin/blog` | Blog post CRUD (rich text + image upload) | `blog_posts`, `blog_categories` |
| `/admin/comments` | Comment moderation (approve/reject/delete) | `blog_comments` |
| `/admin/enquiries` | Contact enquiries, manual status label only — **no in-app reply/send** | `contact_enquiries` |
| `/admin/events` | Event CRUD (plain-text content + URL image field — inconsistent with blog/news) | `events` |
| `/admin/faqs` | FAQ CRUD (generic CrudManager) | `faqs` |
| `/admin/hero` | Hero banner CRUD (URL-only image, generic CrudManager) | `hero_banners` |
| `/admin/jobs` | Job posting CRUD (rich text ×3) | `jobs` |
| `/admin/membership-applications` | **Approval workflow**: accept/decline/request-details, generates membership number + card + Auth account | `membership_applications` |
| `/admin/memberships` | Separate System-A membership management: status + payment link | `memberships`, `membership_plans` — see LEGACY_RISKS.md (two overlapping membership systems) |
| `/admin/news` | News CRUD (rich text + image upload) | `news_items` |
| `/admin/reports` | Read-only 12-month analytics, browser-print export only | `profiles`, `memberships`, `job_applications`, `contact_enquiries`, `membership_plans` |
| `/admin/settings` | Global site settings (de-facto singleton row, no DB constraint) | `site_settings` |
| `/admin/shop` | Shop product CRUD + read-only recent-orders list (**no order status/refund action anywhere**) | `shop_products`, `shop_categories`, `shop_orders` (read-only) |
| `/admin/subscribers` | Newsletter subscriber list, toggle active, client-side CSV export | `subscribers` |
| `/admin/team` | Team member CRUD (generic CrudManager) | `team_members` |
| `/admin/testimonials` | Testimonial CRUD (generic CrudManager) | `testimonials` |
| `/admin/users` | User list, suspend/activate, grant/revoke admin role — **no delete, no self-revoke/last-admin safeguard** | `profiles`, `user_roles` |

## Route-file → URL mapping note

TanStack Router's file-based convention (`src/routes/README.md`): a file = a route; dots in filenames (`membership.apply.tsx`) create nested path segments (`/membership/apply`); `__root.tsx` is the app shell; `_authenticated/` is a pathless layout route enforcing the auth gate on every child. This mapping is purely a Laravel routing-file organization concern going forward — `routes/web.php` (or route group files) replace it; no framework-level file convention needs to be preserved.
