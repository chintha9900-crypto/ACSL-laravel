# 01 — Frontend Architecture

Documentation only. Nothing in this set has been implemented: no views, components, routes, CSS or JS exist yet.

## 1. Sources and precedence

1. Approved ACI business requirements → 2. `docs/architecture/*` → 3. `docs/database/*` → 4. Lovable **UI/UX** → 5. Lovable **implementation**.

The reference (`C:\xampp\htdocs\aviationclub\aci-referance\docs\zip files`) is a React 19 SPA (TanStack Start/Router/Query, shadcn/ui "new-york", Radix, Tailwind 4, lucide icons, Sonner toasts, Tiptap, react-easy-crop, Recharts, react-phone-number-input, Supabase). It is used here for **look, page structure and interaction patterns only**. Its authorization (client `checkIsAdmin`, `beforeLoad` redirects, `useAuth`) and every Supabase call are ignored: Laravel middleware, policies and gates are the only authorization mechanism (see §7).

Reference facts that shape this document:

| Fact | Consequence |
|---|---|
| 46 UI routes: 18 public, 10 member, 18 admin (+ `sitemap.xml`, 404/error views, 3 layout shells) | Catalogued in `03`–`05`. |
| The snapshot has **no shop, verify or membership-card routes** (earlier reverse-engineering notes mention them from a later build) | No reference UI for e-commerce or the digital card. Only the design language is reusable. E-commerce frontend is out of scope (`08` D-08). |
| Logo is a Lovable-hosted asset (`logo-aviation-club.png.asset.json`, 2.7 KB, 292×95); the file itself is not in the repo | ACI-approved logo assets are authoritative and are supplied by ACI; the logo is not recreated or redesigned (`08` C-02, approved). |
| Brand naming is mixed: "ACSL Aviation Club" (meta titles, contact, auth, rules) vs "Aviation Club International" (home, about, footer) | **Approved (`08` C-02):** display name Aviation Club International, short name ACI; the legacy ACSL name is not used anywhere in the new UI. Wording remains ACI's to approve. |

## 2. Frontend toolchain in the Laravel project today

| Item | Status |
|---|---|
| Tailwind CSS 4 + `@tailwindcss/vite`, Vite 7, `laravel-vite-plugin` | **Installed.** The reference is also Tailwind 4 with `@theme`/`oklch` tokens, so tokens port 1:1 (`02`). |
| Blade | Available (only `welcome.blade.php` exists). |
| Livewire | **Not installed yet.** Livewire 3 is **approved** for genuinely server-interactive admin functionality only (`08` C-01); installation is an implementation-phase task. Never used for private document uploads. |
| Alpine.js | **Not installed yet.** Approved for local browser interaction where needed (`08` C-01); ships with Livewire 3. |
| Rich-text editor, charts, image cropper, phone input | Not installed; each deferred (`08`). |

Rule: **no new frontend framework**. No React, Vue, Inertia, jQuery or component library. Blade + Tailwind first; Alpine for local UI state; Livewire only where §5 says a server round-trip is genuinely needed.

## 3. Areas, layouts and shells

| Area | URL prefix | Layout | Auth |
|---|---|---|---|
| Public site | `/` | `layouts.public`: sticky header, `<main>`, 4-column footer | none |
| Applicant flow (no account yet) | `/applications/{public_id}/…` | `layouts.public` (narrow content) | signed, expiring link tied to the application (approved in principle, `08` C-05) **or** owner login — server-checked; IDs alone never grant access |
| Auth | `/login`, `/forgot-password`, `/reset-password/{token}`, `/account/setup/{token}` | `layouts.auth`: split screen (brand panel `lg` only + form) | guest |
| Member | `/dashboard/*` | `layouts.app` + member sidebar | `auth`, active user |
| Admin | `/admin/*` | `layouts.app` + admin sidebar | `auth` + admin gate/policy |
| Errors | — | `errors.*` (404, 403, 419, 429, 500) in brand | — |

`layouts.app` keeps the reference's structure — site header on top, 240 px sticky sidebar + content — because it is part of the recognisable UX. Difference: the large marketing footer is replaced by a slim footer inside app areas (`08` D-06).

Reference-vs-target auth screens:

| Reference `/auth` | Target |
|---|---|
| Tabs: Sign in / Create account | **Sign in only.** No public "Create account" (rejected/removed — approved decision `08` C-03; accounts are provisioned at membership activation). |
| "Forgot?" toggles an inline form | Separate `/forgot-password` page (same card styling). |
| `/reset-password` (Supabase session) | `/reset-password/{token}` (Laravel broker). |
| — | **New** `/account/setup/{token}`: set first password from the activation email (one-time hashed token, `docs/database/03_IDENTITY_SCHEMA.md`). |
| Role check via RPC then redirect | Server-side redirect: admin → `/admin`, member → `/dashboard`. |

## 4. Navigation

**Public header** (from reference): logo · Home · About · Membership ▾ (Membership Benefits · Club Rules · Become a Member · FAQ) · Blog · News & Events · Jobs · Contact · right side **Sign In** (ghost) + **Join Now** (gradient primary → `/membership/benefits`). Target change: when signed in, "Sign In" becomes "Dashboard". `/membership` itself redirects to `/membership/benefits`.

**Footer** (from reference): brand + description + social icons · Explore (About, Membership, Blog, Jobs, Contact) · Legal (Privacy, Terms + admin-managed footer links) · Get in touch (email, phone, address) · bottom bar (© year site name; tagline). Content comes from `site_settings`, `social_links`, `footer_links`. Add News & Events to Explore (missing in reference).

**Member sidebar** (reference 10 items → target 8): Overview · Profile · Membership · Notifications · Job Applications · My Comments · Refer a Friend · Security. Removed: *Billing Details* (folded into Membership — payment is now part of the approved workflow, not a separate payment-link page), *Promotions* (was just the public benefits list; the free first-6-month period is a standard introductory term shown inside the membership flow, not a promotion), *Change Password* (renamed Security; the reference had two duplicate forms). "Admin Panel" link shows only if the server says the user is admin. Sign out is a POST form.

**Admin sidebar** — reference is a flat list of 18; target groups the same pages and adds those the approved architecture needs:

| Group | Items |
|---|---|
| Overview | Dashboard |
| Membership | Applications · Members & terms · Plans & categories* · Bank details* |
| People | Users |
| Content | Blog · News · Events · Comments · FAQs · Testimonials · Team · Hero banners · Social & footer links* |
| Jobs | Job postings · Job applications |
| Communication | Enquiries · Subscribers · Email templates* |
| Insight | Reports · Audit log* |
| Settings | Site settings · Membership settings* |

`*` = does not exist in the reference; required by the approved architecture/database (or, for social/footer links, present in the data model but with no admin UI in the reference).

## 5. Blade vs Livewire

Default: **server-rendered Blade**. Progressive enhancement with Alpine for purely local state. Livewire only when the interaction is a stateful server conversation.

| Interaction | Reference | Target | Why |
|---|---|---|---|
| Header dropdown, mobile menu, accordion (FAQ), tabs, show/hide password, eligibility self-check, flash toasts, modals open/close | React state | **Blade + Alpine** | Local UI state; no server |
| Blog search + category filter + pagination | debounced input + URL params | **Blade** GET form (`?q=&cat=&page=`) | Shareable URLs, SEO, no JS required |
| Jobs search / Local-Overseas / type filters + master-detail | client-side over full dataset | **Blade** GET filters (`?job=<id>`) + server pagination | Legacy shipped the whole dataset to the browser; filter server-side |
| Contact form, comment form, referral invite, profile, password, application forms | mutations via RPC | **Blade POST** + Form Request + redirect with flash | Simple request/response |
| **Membership application** (category switch, proof upload) | base64-in-JSON, 5 MB/file | **Blade multipart POST**; Alpine switches visible fields client-side, server validates per category | Proof is a private document: standard multipart to a controller → private disk. Livewire temporary uploads would stage sensitive files on a default disk before validation. |
| **Payment evidence upload** | none | **Blade multipart POST** | Same reason |
| Admin data tables (search, filters, sort, pagination) | client-side filtering over 200 rows | **Livewire table component** per list page (one shared base) | Live filtering with server pagination |
| Admin application review (approve / reject / request details, mark proof reviewed) | Select + Dialogs | **Livewire** detail page with action modals | Multi-step, stateful, must re-render status and history after each action |
| Admin payment confirm / reject | none | **Livewire** (same page as above) | Same |
| Admin generic CRUD (FAQs, testimonials, team, hero, social/footer links) | `CrudManager` | **One Livewire CRUD component**, config-driven | Genuinely uniform entities |
| Notifications list, mark read / mark all read | mutations | **Blade POST**; optional Livewire refresh later | No live push needed |
| Reports charts | Recharts | server-rendered numbers + a small chart lib (`08` D-03) | Deferred |

Alternatives considered: full Livewire everywhere (unnecessary round-trips for static/read pages, weaker SEO/caching); Blade + Alpine only (admin filter/review screens become hand-rolled JS). The split above keeps the public site cacheable and framework-light while keeping the admin productive.

## 6. Reusable component strategy

* **Anonymous Blade components** (`resources/views/components/**`) for everything presentational — one component per concept (`06`). The reference repeats the gradient icon tile, the dark hero band and the dashed empty state in 6+ files; each becomes exactly one component.
* **Design tokens live in CSS** (`resources/css/app.css`, `@theme`), not in components. Variants are Tailwind classes selected by a component prop.
* **Class-based/Livewire components** only for the admin table base, CRUD manager and review screens.
* **No duplicated "slightly different" variants**: buttons have one component with a `variant`/`size` prop; badges one component; cards one shell with named slots.

## 7. Security rules for the frontend

* Authorization is Laravel middleware + policies/gates + Form Requests. A hidden link, disabled button or client redirect is **never** a control. Reference patterns dropped: `AdminLayout` client gate with "Verifying access…", `checkIsAdmin` query, `beforeLoad` redirects, RLS assumptions.
* Every destructive action posts a real form behind a confirmation modal (replaces `window.confirm`).
* Private documents (proof, payment evidence) are never linked by path; downloads go through a policy-gated route. Public images use the `public` disk.
* Rich HTML shown to users (blog, news, jobs) is sanitised on save and rendered through one `x-content.rich-text` component — never raw `{!! !!}` scattered in views.
* Forms carry `@csrf`; server validation messages are shown inline; throttling on auth, application, contact and comment endpoints (architecture `05` §5).

## 8. Recommended Laravel frontend structure (proposal — not created)

```
resources/
  css/app.css                      @import "tailwindcss"; @theme tokens (02); utilities: gradient-hero, gradient-primary, text-gradient, shadow-*
  js/app.js                        Alpine (approved, `08` C-01) + tiny helpers only
  views/
    layouts/  public · auth · app
    components/
      ui/        button badge card alert modal confirm empty-state icon-tile pagination toast
      form/      field input textarea select switch file-upload phone password date
      layout/    section page-hero container
      nav/       header footer dropdown mobile-menu sidebar sidebar-link
      content/   rich-text post-card list-card job-item job-detail feature-card stat-card comment accordion
      membership/ category-card benefit-list process-steps eligibility-check introductory-offer status-badge status-timeline card payment-instructions details-request
      admin/     page-header table-toolbar data-table row-actions info-grid stat-card action-card
    public/  home about membership/{benefits,rules,faq,apply} blog/{index,show} news-events news/show events/show jobs contact legal/{privacy,terms}
    applications/  status respond-details
    auth/    login forgot-password reset-password account-setup
    member/  overview profile membership notifications job-applications comments refer security
    admin/   …one folder per area
    errors/  404 403 419 429 500
app/Livewire/Admin/…  (Livewire 3 — approved for server-interactive admin only, `08` C-01)
```

URL conventions keep the reference where it is good: `/membership/benefits`, `/membership/rules`, `/membership/faq`, `/membership/apply`, `/blog/{slug}`, `/news-events`, `/news/{slug}`, `/events/{slug}`, `/jobs`, `/contact`, `/privacy`, `/terms`, `/dashboard/*`, `/admin/*`. Changed: `/auth` → `/login`; `/reset-password` → `/reset-password/{token}`. Use named routes and `route()` in views.

## 9. Cross-cutting behaviour to reproduce

| Concern | Reference | Target |
|---|---|---|
| Page metadata | per-route `<title>`, description, `og:*`, canonical (inconsistent coverage) | One `<x-seo>` partial fed by page + `seo_pages`; canonical on every public page |
| Sitemap / robots | `sitemap.xml` broken (empty base URL, duplicate `/jobs`); `robots.txt` allow-all | Correct absolute-URL sitemap incl. news/events/membership pages; noindex on auth/reset/dashboard/admin |
| Flash messages | Sonner top-right, `richColors` | `x-ui.toast` fed by session flash (success/error/warning) |
| Loading | plain "Loading…" text | Not needed for Blade pages; `wire:loading` on Livewire tables only |
| Error pages | minimal 404 + generic error boundary | Branded Blade error views (404 "Go home", 500 "Try again / Go home") |
| Dates | `en-GB` for content, browser locale for admin | One formatter helper (`d M Y`); locale/time-zone rules TBC (DB OD-09) |
| Fonts | Google Fonts `Sora`, `Plus Jakarta Sans` | Same families; hosting method deferred (`08` D-05) |
| Dark mode | tokens defined, **no toggle anywhere** | Not implemented (`08` D-04) |

## 10. Explicitly not carried over

Supabase client/auth/storage calls; base64 file uploads; client-side admin gate; `useAuth` hook; TanStack Query caching layer; `window.confirm`/`window.print` as workflows; hard-coded prices, benefit lists and "first 100 students free" copy in views; the legacy `Create account` tab; the "Promotions" member page; dual password-change forms; `SLUG_IMAGES` (images mapped to specific blog slugs in code); the unused `ComingSoonPage` component.
