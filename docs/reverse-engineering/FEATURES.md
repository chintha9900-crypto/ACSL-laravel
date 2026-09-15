# Features

Per-feature behaviour, data, business rules, and Laravel-replacement notes. Route-level auth/data summary is in ROUTES.md; full validation rules are in VALIDATION.md; storage/upload mechanics in STORAGE.md; every email/notification in NOTIFICATIONS.md; role/gate mechanics in AUTHORIZATION.md; multi-step processes in WORKFLOWS.md. This file focuses on what each feature actually *does* and its business rules, cross-referencing those files rather than repeating them.

---

## A. Public marketing & content

### Home (`/`)
SOURCE: `src/routes/index.tsx:1-252`, `src/lib/site.functions.ts:17-58`.
Hero (from `hero_banners`, active row, falls back to hardcoded copy), a hardcoded (non-CMS) About/Benefits section, and "Latest from the blog" (3 published posts). `getHomeData` also fetches testimonials, latest jobs, and membership plans — **all three fetched but never rendered** on this page (dead data, possibly reserved for a future section). LARAVEL: single controller/Blade view; drop the three unused queries unless ACI approves a future section using them. TEST: hero fallback when none active; exactly 3 latest published posts, excluding drafts.

### About (`/about`)
100% static/hardcoded — mission/vision/values and a stats strip (`2,500+ Active Members` etc.) that are **placeholder numbers, not live counts**. No DB call at all. UNKNOWN whether ACI wants these to become real computed stats. LARAVEL: static Blade view; live stats would be new scope requiring approval.

### Contact (`/contact`)
Static contact-info cards using **hardcoded** email/phone/address (`hello@acsl.lk` etc.) — inconsistent with the Footer, which reads the same information from `site_settings`. Form: see VALIDATION.md. On submit: insert `contact_enquiries` + admin email (NOTIFICATIONS.md #1). No CAPTCHA/rate limiting. LARAVEL: decide whether the Contact page should read `site_settings` for consistency (flag, don't silently pick).

### Blog index (`/blog`)
Search (title-only, debounced 350ms), category filter, pagination (9/page); search/category/page are URL-bound via a Zod `fallback()` schema so bad params silently reset to defaults rather than error. Featured images resolved as 7-day signed URLs from the `blogs` bucket (see STORAGE.md — TTL inconsistent with other blog-bucket consumers). LARAVEL: query-string-bound Eloquent `paginate(9)`.

### Blog detail (`/blog/$slug`)
Public read (`status='published'` + approved comments only); content rendered via raw HTML (`dangerouslySetInnerHTML` equivalent) — admin-authored today, but see LEGACY_RISKS.md for the stored-XSS risk since no sanitization exists at write time. Comment form: auth-gated, see VALIDATION.md and WORKFLOWS.md §3 for the auto-approve gap.

### News & Events index (`/news-events`)
Two independent columns, 30 items each, no pagination, **not filtered to future events only** — past events remain listed indefinitely (flag as a possible UX gap for ACI, not silently fixed). Uses a *different* Supabase client (`publicClient()`, anon key, subject to RLS) than the equivalent blog/jobs reads (which use the service-role `supabaseAdmin`) — an architectural inconsistency worth resolving in the Laravel controller layer (just query `where('status','published')` consistently everywhere; there's no RLS-vs-not distinction in Laravel).

### News detail / Event detail (`/news/$slug`, `/events/$slug`)
Both public, published-only, 404 on miss. **Inconsistency**: news content renders as HTML (`dangerouslySetInnerHTML`), event content renders as **plain preformatted text** — confirm with ACI which is intended per content type rather than assuming one is a bug.

### Jobs board (`/jobs`)
Master-detail layout; filtering (search/type/location-type) happens **entirely client-side over the full fetched dataset** even though the server function accepts filter params that are simply never passed from this page — dead server-side capability. "Apply Now" links externally (`external_link`) or falls back to `/contact`; **no in-app job-application flow is exposed from this page** despite `job_applications` existing as a table (that flow lives in the member dashboard instead, applying to jobs presumably requires being logged in — confirm exact entry point during Architecture phase, not observed in the public-page files). LARAVEL: recommend moving filtering server-side (flag as an improvement, confirm with ACI since it's a behaviour change).

### Membership section (`/membership/*`)
- **Layout + index**: `/membership` is a shell; `/membership/` immediately redirects to `/membership/benefits` — there is no unique "membership home".
- **Benefits** (`/membership/benefits`): **100% hardcoded pricing/benefits** despite `membership_plans`/`membership_benefits` tables existing and being actively used elsewhere (`getMembershipPageData` powers the FAQ page but not this one) — an admin editing those tables has **zero effect** on what's shown here. This is a significant inconsistency to flag prominently: reproduce as static, or wire to the DB (recommended but is a business/architecture decision, not to be silently assumed). Also contains three non-persisted "eligibility checker" widgets (pure client-side self-assessment, no submission) and a static 6-step process diagram. The "first 100 students free" launch offer is stated only in UI copy with **no backing counter/flag anywhere** — UNKNOWN how it would actually be enforced, and is a **different** promotion from the confirmed ACI 6-month-free-membership introductory offer described in WORKFLOWS.md §0 — do not conflate the two. **CONFIRMED ACI requirement**: exactly three membership categories will exist (WORKFLOWS.md §0.1) — the legacy page's category/tier naming (Student / 3-tier Professional / Veteran, backed by 4 seeded `membership_plans` rows) does not cleanly map to "three categories" and must be reconciled with ACI, not assumed.
- **Apply** (`/membership/apply`): the legacy live application flow, superseded by **WORKFLOWS.md §0 (CONFIRMED)** — see also VALIDATION.md. **CONFIRMED ACI requirement change**: the reference app only required a file upload for Student and Veteran applicants; the confirmed requirement is that **all three membership categories must require proof of aviation participation**, so the rebuilt Professional-equivalent category form must add a document-upload step it did not have in the reference app. Note: Professional tier labels submitted here (`Basic/Intermediate/Inner Circle`) **do not match** the Benefits page's tier names (`Core/Premier/Inner-Circle (Prestige)`) — reconcile with ACI before implementation (final category names/pricing are still an open decision, see WORKFLOWS.md §0 "Remaining decisions"). There is also a **separate, apparently-unused** pair of functions (`applyMembership`, `submitMembershipApplication`) that write to the *other* (legacy System A) membership table and even create a Supabase Auth user directly — not referenced by any live UI route found. Mark as legacy/candidate-dead-code, not to be ported unless ACI confirms it's still wanted (see LEGACY_RISKS.md).
- **FAQ** (`/membership/faq`): genuinely DB-driven (`faqs`, active, ordered) — unlike Benefits, this one is real CMS content.
- **Rules** (`/membership/rules`): static; the "I agree" CTA is a plain link, **no actual consent is recorded anywhere**, and no "I accept the rules" checkbox exists in any of the three apply forms either — flag as a possible compliance gap if ACI wants consent tracking.

### Privacy / Terms (`/privacy`, `/terms`)
**Both are placeholder Lorem Ipsum** — not real legal content, including a hardcoded "Last updated: June 2026" string. Section headings are a reasonable structural starting point pending legal review. Do not port the placeholder body text as if it were real content.

### Sitemap (`/sitemap.xml`)
**Broken as implemented**: `BASE_URL` is a hardcoded empty string (`// TODO: replace with your project URL`), so every `<loc>` is a bare relative path — invalid per the sitemap protocol. The jobs loop produces one duplicate `/jobs` `<url>` entry per published job (should be one entry total, or per-job detail pages if those existed). Missing entirely: `/news-events`, all `/news/{slug}` and `/events/{slug}`, all `/membership/*` sub-pages, `/privacy`, `/terms`. LARAVEL: build a correct, complete, absolute-URL sitemap from scratch — do not port the bug.

### Root shell / global layout
Loads `site_settings`/`social_links`/`footer_links` once (5-minute stale time) for Header/Footer. `site_settings.facebook_url`/`instagram_url`/etc. columns appear **unused** — Footer actually renders from the separate `social_links` table, a redundant-columns situation to resolve (drop the unused columns, or decide `social_links` is the single source of truth) in DATABASE DESIGN. Global error boundary reports to a Lovable-platform telemetry global (`window.__lovableEvents`) — **drop entirely**, not portable; if ACI wants client-side JS error tracking, that's a fresh product decision (e.g. Sentry), not a carry-over.

### Header / Footer (global components)
Header: hardcoded static logo asset, not `site_settings.logo_url` (unused/inconsistent, same pattern as above). Footer: a **hybrid** legal-links pattern — 2 hardcoded internal links (Privacy/Terms) plus all rows from `footer_links` appended after — don't assume `footer_links` alone drives the whole legal column.

### ComingSoonPage (shared placeholder component)
A generic "under construction" component exists but its actual usage sites were not found within the files reviewed for the public-pages pass — confirm which (if any) sections should use an equivalent placeholder in the Laravel rebuild before assuming none are needed.

### SEO handling
Per-route `<title>`/description/OG tags exist on most pages but **canonical `<link>` coverage is inconsistent** (News/Events index+detail and Jobs lack it, others have it). **No structured data (JSON-LD)** exists anywhere despite natural candidates (blog→Article, jobs→JobPosting, events→Event) — a gap/opportunity for Laravel, but new scope requiring approval, not an inferred requirement.

---

## B. E-Shop

Full checkout sequence is in WORKFLOWS.md §2; validation in VALIDATION.md; notifications in NOTIFICATIONS.md #7. Feature-level notes not covered there:

### Product catalog (`/shop`, `/shop/$slug`)
Category filter is client-side over the full active-product list (no server-side filtered query per category). Two visibility tiers — `public` (anyone) and `members` (any authenticated user, **no distinction by plan/tier/expiry** — just logged-in-or-not) — enforced entirely by Postgres RLS today, which has no Laravel equivalent; must become an explicit policy/scope (`scopeVisibleTo(?User $user)`). A hidden (members-only) product and a genuinely nonexistent slug render the **same** generic "not available" message — cannot be distinguished by the visitor; decide if that ambiguity is desired for the rebuild (currently ambiguous by accident, not clearly by design). Images share the `blogs` storage bucket, folder `shop` (see STORAGE.md).

### Cart (`/shop/cart`)
Entirely client `localStorage`, no DB table, not tied to a member account, lost on cleared site data. Quantity clamped 1-99; a quirk in the clamp order means the UI's minus button cannot reduce a qty-1 line to zero (removal requires the explicit delete button) — cosmetic, not a data-integrity issue since checkout re-derives everything server-side.

### Inventory
**None exists.** No `stock`/`quantity_available`/`sku` column anywhere; the only purchasability gate is the manual `is_active` toggle. Adding stock tracking is new scope for ACI to approve, not something inferable from the reference.

### Admin product management (`admin.shop.tsx`)
See ADMIN section below and STORAGE.md for the image-upload mechanics. Orders are **view-only** in this admin page — no status/refund/cancel action exists anywhere in the reference app (see WORKFLOWS.md §2 step 9).

---

## C. Member Dashboard

Full membership-application trace is in WORKFLOWS.md §1; the System A/B duplication is flagged prominently in LEGACY_RISKS.md — every feature below that touches "membership" is scoped to whichever system is named.

### Dashboard shell + Overview
Sidebar nav with a conditional Admin Panel link (`checkIsAdmin` RPC). Overview shows profile, latest System-A `memberships` row, notifications, job applications; `blog_comments` fetched but **unused** on this page (dead fetch, consistent with the pattern seen on Home/FAQ). **Bug**: the "unread notifications" stat only counts unread within the 5 most-recently-fetched notifications, not a true total — fix in Laravel (count all unread directly), don't reproduce.

### Job Applications (read-only list)
Lists the member's own `job_applications` joined to `jobs`; no apply/withdraw action on this page itself (application entry point lives elsewhere/out of this file set).

### Billing (`/dashboard/billing`) — legacy behaviour; payment workflow is now CONFIRMED (see WORKFLOWS.md §0.9)
**Not a real billing/invoicing system** — no payment gateway, no invoice records, no transaction history. `payment_link` is a manually admin-entered URL and `payment_status` a manually admin-set string (legacy System A `memberships` only). "Pay now" only shows when a link exists and status isn't `paid`. **CONFIRMED**: no payment gateway is being added either — ACI's confirmed workflow is still bank-transfer-plus-manual-admin-confirmation, not a Stripe/PayPal-style gateway. What changes vs. the legacy page: the payment state must now be one of the five confirmed states (`payment_not_required`/`payment_pending`/`payment_confirmation_submitted`/`payment_confirmed`/`payment_rejected`, WORKFLOWS.md §0.9) rather than an unconstrained free-text string, a promotional/free membership must show `payment_not_required` and never a fabricated £0 amount, and the applicant needs to submit **both** a payment reference and evidence document themselves (the legacy app had no such submission step at all — only an admin-set status), with a rejected submission looping back to `payment_pending` for resubmission rather than restarting the whole application.

### My Comments (`/dashboard/comments`)
List + delete-own only (no edit). Deletion is double-scoped (`user_id` match + RLS) so a member cannot delete another's comment.

### Membership (dashboard) (`/dashboard/membership`)
Lists the member's System-A `memberships` history only — **completely separate from** the System-B application a member may have actually gone through (see LEGACY_RISKS.md). No cancellation/renewal action.

### Notifications (`/dashboard/notifications`)
See NOTIFICATIONS.md for the single creation site (payment-link action) — this page is otherwise a near-empty inbox for most members, since nothing else in the app creates an in-app notification.

### Change Password
**Two separate, inconsistent forms exist** (dedicated page + a duplicate inline one on the Profile page) — see VALIDATION.md and LEGACY_RISKS.md. Consolidate to one canonical Laravel form; add current-password re-verification (missing in both reference forms — a session-hijack risk).

### Profile (`/dashboard/profile`)
9-field form + bio, allow-list-only server validation (see VALIDATION.md — a real gap to fix). Displays a read-only "Membership Number" resolved via a **cross-table `ILIKE` email lookup into `membership_applications`, run with the service-role client** — the only way a member's System-B membership number surfaces on their own profile, since that table has no member-scoped RLS SELECT policy. This ad hoc bridge should be replaced by a proper relationship once the System A/B unification decision is made. Avatar upload: see STORAGE.md (no size enforcement despite UI copy claiming one; old avatars never cleaned up).

### Promotions (`/dashboard/promotions`)
**Not a real per-member promotions engine** — it is literally the same `membership_benefits` content shown to anonymous visitors on the public membership page, fetched unscoped via the service-role client. No personalization, no per-plan filtering, no discount codes, no expiry. If ACI wants genuine personalized promotions, that is new scope for Architecture phase; document current (non-personalized) behaviour only, don't build the real thing based on this reverse-engineering pass.

### Refer a Friend (`/dashboard/refer`)
**No referral code, tracking, or reward mechanism at all** — purely a "send an invite email" feature with a generic, non-attributable apply link. No `referrals` table exists anywhere in the schema. If ACI wants a real referral system (unique code/link, attribution, reward), that is new scope to design fresh during Architecture — current functionality is invite-email-only.

---

## D. Admin Panel

Authorization gate mechanics are in AUTHORIZATION.md §4; validation gaps are in VALIDATION.md; storage/upload mechanics (ImageCropUpload) are in STORAGE.md; every notification triggered by an admin action is tabulated in NOTIFICATIONS.md.

### Shared patterns
- **CrudManager** (FAQs, Hero Banners, Team, Testimonials): a config-driven list+dialog-form component (`fields[]`, `list`/`upsert`/`del` server functions) backed by a generic `makeCrud(table, allowedFields)` factory. No pagination/sorting/filtering built in; **no server-side validation at all** (see VALIDATION.md).
- **Bespoke DataTable + Dialog forms** (everything else — blog, news, events, jobs, shop, applications, comments, enquiries, membership-applications, memberships, subscribers, users): used wherever image/rich-text/status-workflow needs exceed what CrudManager supports.
- **Rich text vs plain text vs URL-only image, inconsistently applied per entity** — see the cross-cutting table below.

| Entity | Long content field | Editor | Image field | Upload mechanism |
|---|---|---|---|---|
| Blog post | content | RichTextEditor (HTML) | featured_image | ImageCropUpload → `blogs/blog` |
| News | content | RichTextEditor (HTML) | image_url | ImageCropUpload → `blogs/news` |
| Event | content | **plain Textarea** | image_url | **plain URL text field** |
| Job | description/requirements/instructions | RichTextEditor ×3 | — | — |
| Shop product | description | **plain Textarea** | image_url | ImageCropUpload → `blogs/shop` |
| Hero banner | subtitle | plain Textarea | image_url | **plain URL text field** |
| Team member | bio | plain Textarea | photo_url | **plain URL text field** |
| Testimonial | testimonial | plain Textarea | photo_url | **plain URL text field** |

These inconsistencies (events/shop not using rich text despite similar CMS purpose; hero/team/testimonials needing an externally-hosted URL instead of upload) are pre-existing product decisions or oversights in the reference app — surface them as explicit design questions for ACI at the Architecture stage rather than silently standardizing or silently preserving the inconsistency.

### Admin Overview (`/admin`)
Row-count tiles across 8 tables + 3 pending-item badges (pending memberships, pending comments, new enquiries) — purely `COUNT(*)` queries, no business logic.

### Job Applications (`admin.applications.tsx`)
Inline status `<Select>` (`applied, reviewing, shortlisted, rejected, hired` in the UI — not all of these are enumerated in the DB schema, see WORKFLOWS.md §4); no notification to the applicant on any transition; no confirmation dialog.

### Blog / News / Events / Jobs CRUD
All four follow a near-identical bespoke DataTable+Dialog pattern. Notable per-entity quirks: blog/news auto-slugify on title change or blank-at-submit; **events does not auto-slugify** (plain manual text field) — an inconsistency. `published_at` is stamped **only on create**, not on a later draft→published transition of an existing record — republishing an edited post/job/event/news item never (re)stamps `published_at`, a bug to fix in Laravel rather than reproduce. Required-field checks (`"Title and slug are required."`) only run on create, never on update — an update can null out a previously-required field (see VALIDATION.md).

### Comment moderation (`admin.comments.tsx`)
Approve/Reject/Delete actions; see WORKFLOWS.md §3 for why "approve" mostly only matters for demoting an already-visible comment given comments are auto-approved on insert. No author notification on any action.

### Contact Enquiries (`admin.enquiries.tsx`)
**No in-app reply/send mechanism exists** — "replied" is purely a manual status label the admin sets after presumably emailing the person externally via a `mailto:` link. This is the entire enquiry-handling feature; adding a real in-app reply/send-email capability is new scope for ACI to approve, not an inferred gap to silently fill.

### Membership Applications (`admin.membership-applications.tsx`) — legacy behaviour; target workflow is now CONFIRMED
The most complex admin workflow in the reference app — see WORKFLOWS.md §1 for the full legacy accept/decline/request-details trace (kept as historical record), and AUTHORIZATION.md §6 items 7-9 for its specific security concerns (account-takeover risk on re-approval, predictable-structure temp passwords, best-effort/possibly-non-functional email delivery acknowledged by the reference code's own comments). List/filter here is **fully client-side over the entire unfiltered result set** (no server-side pagination, unlike the 200-row-limited lists used everywhere else in the admin panel) — a scalability gap to fix if application volume grows. **The target admin review workflow is now CONFIRMED in WORKFLOWS.md §0**: Approve / Reject / Request More Details, with Request More Details returning the application to a real, in-system review queue (not an out-of-band email reply as the legacy app does), and Approve triggering promotion-eligibility determination before any payment or activation step — the legacy immediate-account-creation-with-temp-password behaviour on Approve is superseded by §0.13's "secure account setup/login instructions" requirement, and the legacy free-text `next_membership_number` scheme is superseded by the authoritative 9-character format in §0.11.

### Memberships (`admin.memberships.tsx`) — legacy behaviour; superseded by the CONFIRMED Membership Data Model
Manages the **separate** legacy System-A `memberships` table — status transitions (`pending|active|rejected|suspended|expired`, freely settable via one `<Select>`, no workflow guard preventing e.g. jumping straight to `expired`) and payment-link/amount/status. Approving (`pending→active`) stamps `approved_by`/`approved_at`/`start_date`. Setting a payment link creates the one in-app notification found anywhere in the reference app (see NOTIFICATIONS.md #8). **RESOLVED (2026-09-15)**: the System A/B conflict flagged in LEGACY_RISKS.md §1 — where this page and Membership Applications above were two different, overlapping membership products — is resolved by the CONFIRMED unified `Membership Application → Approved → Membership` model in DATABASE.md/WORKFLOWS.md §0. Neither legacy admin screen's exact status vocabulary or payment-link mechanic should be ported as-is; the new design uses the five confirmed payment states (WORKFLOWS.md §0.9) and an admin-configurable promotion (§0.6) instead of a manually-typed payment link.

### News (`admin.news.tsx`)
Structurally near-identical to Blog admin (title/slug/status/image/rich-text/short-description) but without categories or reading time. Consider at Architecture stage whether News and Blog should unify into one "articles" concept with a type discriminator — a design question to raise, not decide here.

### Monthly Reports (`admin.reports.tsx`)
Read-only, trailing-12-months aggregation across `profiles`/`memberships`/`job_applications`/`contact_enquiries`, computed at request time with no caching/stored snapshots. "Print"/"Save as PDF" both just call `window.print()` — no server-generated PDF, no CSV/data export on this page (contrast with Subscribers' CSV export). A true server-rendered PDF (`barryvdh/laravel-dompdf` or similar) would be an enhancement, not required for parity.

### Site Settings (`admin.settings.tsx`)
Single-row global form; enforces a de-facto singleton via application logic only (no DB constraint) — see VALIDATION.md for the missing email/URL validation gap.

### Subscribers (`admin.subscribers.tsx`)
List + toggle active/inactive; "Export CSV" is entirely client-side (builds and downloads a Blob in-browser from already-fetched data) — a server-side export would be more robust for large lists, a minor improvement to consider, not a required behaviour change.

### Users (`admin.users.tsx`)
See AUTHORIZATION.md §6 items 10 for the missing self-revoke/last-admin safeguards. **No delete-user action exists anywhere.** The displayed "Role" column is actually `profiles.aviation_role` (a free-text profession field like "Pilot") sitting right next to an "Admin" badge driven by the completely unrelated `user_roles` authorization table — a naming collision the reference UI itself doesn't disambiguate; keep these conceptually and, in Laravel, *nominally* separate. Search is client-side over the fetched 200-row page only — a scalability gap if the user base grows past that.

*Per the Approval Gate: analysis only. No Laravel migrations, models, controllers, routes, or UI have been created as part of this pass.*
