# 03 — Laravel Component Architecture

How the modular monolith (`01_ARCHITECTURE_OVERVIEW.md` §3) is organized inside a standard Laravel 13 application. Directory names below are proposed layout for the DATABASE DESIGN / IMPLEMENTATION phases — nothing has been created yet.

## 1. Directory / namespace layout

**ARCHITECTURAL DECISION**: domain-oriented subdirectories within Laravel's standard top-level folders.

```
app/
  Models/
    Membership/        MembershipApplication.php, Membership.php, MembershipCategory.php, ...
    Promotions/         MembershipPromotion.php
    Payments/           Payment.php, PaymentWebhookEvent.php, PaymentRefund.php
    Commerce/           Product.php, Order.php, OrderItem.php, Cart.php, ...
    Content/            BlogPost.php, NewsItem.php, Faq.php, ...
    Events/             EventListing.php   (avoids colliding with Laravel's own Events concept)
    Jobs/               JobPosting.php, JobApplication.php   (avoids colliding with Laravel's Queue Jobs)
    Community/          BlogComment.php, ReferralInvitation.php
    Audit/              AuditLog.php
    User.php                                    (Identity & Access — stays at the Models root, it's the framework's own primary auth model)
  Http/
    Controllers/
      Public/           HomeController.php, BlogController.php, ...            (public marketing site)
      Member/            DashboardController.php, ProfileController.php, ...    (authenticated member area)
      Admin/             per-domain admin controllers, grouped in subfolders matching Models/
      Api/                only if/when a JSON API surface is needed (none confirmed yet — see 16_OPEN_DECISIONS.md)
    Requests/            one Form Request per validated action, mirrored under the same domain subfolders
    Middleware/          EnsureAccountSetupComplete.php, EnsureAdmin.php (if not using a Gate-only approach — see 05_AUTHORIZATION_ARCHITECTURE.md), ...
  Livewire/              domain-subfoldered Livewire components (see §3 for when Livewire is used)
  Policies/              one per Model that needs authorization beyond a blanket admin Gate
  Actions/               single-purpose workflow classes, domain-subfoldered (see §2)
  Events/                domain events (e.g. Membership\MembershipActivated) — Laravel's Event/Listener system
  Listeners/             reactions to domain events (e.g. send a notification, write an audit log entry)
  Notifications/         one class per notification in NOTIFICATIONS.md's M1–M11 (+ legacy-pattern admin ones), domain-subfoldered
  Mail/                  only used if a Mailable needs more structure than a Notification's mail channel provides
  Jobs/                  queued jobs (renewal reminders, webhook processing, PDF generation, cleanup)
  Services/              reserved for genuine cross-domain integrations only (e.g. a PaymentGatewayManager) — NOT a generic CRUD layer, see §2
  Support/               small framework-agnostic helpers (e.g. the membership-number formatter)
resources/
  views/
    public/, member/, admin/, emails/, pdf/       mirrors the controller/notification grouping above
  css/, js/               Tailwind + minimal Alpine
routes/
  web.php                 requires domain-grouped route files, e.g. routes/membership.php, routes/commerce.php, routes/admin.php
  console.php             Artisan commands, scheduled task registration
database/
  migrations/, seeders/, factories/                (DATABASE DESIGN / IMPLEMENTATION phase — not created yet)
```

- **Why appropriate for ACI**: keeps every file inside Laravel's own expected top-level folders (so `php artisan` generators, IDE tooling, and any Laravel developer's existing muscle memory all work unmodified), while the domain subfolders give the "which of the 13 domains does this belong to" clarity the reverse-engineered feature set needs (30+ legacy tables' worth of concepts, more once Commerce/Payments/Promotions are added).
- **Alternatives considered**: see `01_ARCHITECTURE_OVERVIEW.md` §3 (package-based modules; fully flat structure) — same reasoning applies here at the file level.

## 2. Actions vs. a generic Service layer

**ARCHITECTURAL DECISION**: use small, single-purpose, invokable **Action** classes for genuine multi-step business workflows; use plain Controllers/Livewire components + Form Requests + Eloquent directly for simple CRUD. Do **not** introduce a blanket `XService` class per model.

- **What is being proposed**: an `app/Actions/{Domain}/` class per distinct business operation — e.g. `Actions/Membership/SubmitMembershipApplication`, `Actions/Membership/ReviewMembershipApplication` (handles Approve/Reject/Request-More-Details), `Actions/Membership/ResolveEligiblePromotion`, `Actions/Membership/ActivateMembership`, `Actions/Membership/GenerateMembershipNumber`, `Actions/Payments/RecordManualPaymentEvidence`, `Actions/Payments/ConfirmPayment`, `Actions/Payments/ProcessGatewayWebhook`, `Actions/Commerce/PlaceOrder`. Each is a small `__invoke()` class taking typed input (often a DTO or the Form Request's validated array) and returning a typed result.
- **Why appropriate for ACI**: the reverse-engineered legacy workflows that actually need this (membership approval, activation, checkout) are exactly the ones `WORKFLOWS.md` documents as multi-step, multi-table, and requiring transactional integrity (`LEGACY_RISKS.md` flags the legacy app's *lack* of transactions around exactly these operations as a defect). Everything else — FAQs, testimonials, team members, hero banners, site settings, blog/news/jobs/events CRUD — is genuinely simple CRUD per `FEATURES.md` §D, and wrapping it in a service class would only add indirection with no benefit, which the Phase 2 instructions explicitly warn against.
- **Laravel mechanism**: plain PHP classes autoloaded under `app/Actions`, invoked from Controllers/Livewire components/Jobs; each wraps its own multi-step logic in `DB::transaction()` where the confirmed rules require atomicity (e.g. membership activation's number-generation + status-update + history-write, per `WORKFLOWS.md` §0.10 and the legacy's flagged non-transactional gap in `WORKFLOWS.md` §1 "Transactionality gap").
- **Security considerations**: Actions are called only after Form Request validation and Policy authorization have already passed at the Controller/Livewire boundary — Actions themselves assume authorized input and focus purely on business logic, keeping the authorization surface auditable in one place (`05_AUTHORIZATION_ARCHITECTURE.md`).
- **Data integrity considerations**: each Action that spans more than one write wraps itself in a transaction; Actions that call an external system (Auth account creation, a future payment gateway, a notification) as part of a workflow are designed so the external call is either idempotent or ordered *after* the local transaction commits (to avoid the legacy's "Auth user created but application row not yet updated" crash-consistency gap noted in `WORKFLOWS.md` §1).
- **Alternatives considered**:
  1. *Fat Eloquent models with static workflow methods* (`Membership::approve()`) — rejected: mixes persistence concerns with business orchestration, makes multi-model transactions awkward to express, and tends to grow into an unmaintainable "God model."
  2. *A generic `Service` class per domain* (`MembershipService`, `PaymentService`) holding every operation for that domain — rejected: this is the "generic service layer for every CRUD operation" the Phase 2 instructions explicitly say to avoid; it also tends to become a dumping ground that's hard to test in isolation compared to one small class per operation.
  3. *Repository pattern over Eloquent* — rejected: Eloquent models already are Laravel's repository/unit-of-work abstraction; adding another layer on top (explicitly listed as unwanted in the Phase 2 instructions: "do not introduce unnecessary repositories") would only obscure Eloquent's own query builder and relationship features without a corresponding benefit — ACI has no plan to swap MySQL/Eloquent for another persistence engine.

## 3. Blade + Controllers vs. Livewire — when to use which

**ARCHITECTURAL DECISION**:

| Surface | Approach | Why |
|---|---|---|
| Public marketing pages (Home, About, Blog, News/Events, Jobs, Membership info, Contact, Privacy/Terms) | Blade views + thin Controllers | Mostly read-only, SEO-relevant, benefits from simple server-rendered HTML and Laravel's response caching; no need for the reactivity Livewire provides |
| Simple public forms (Contact) | Controller + Form Request, standard POST + redirect/flash, OR a small Livewire component if inline validation feedback is wanted | Either works; Livewire only if ACI wants live validation feedback beyond a full-page reload — not a confirmed requirement, default to the simpler Controller approach unless UI design says otherwise |
| Membership application (3 category-specific forms, duplicate-check, file upload) | **Livewire** (multi-step/wizard component per category, or one component with conditional fields) | Needs the inline duplicate-check UX (`checkApplicationDuplicates` in the legacy app), file upload progress, and multi-field client-side validation feedback that a Livewire component naturally provides without hand-rolled JS/AJAX |
| Blog index (search/category/pagination) | Livewire component with URL-bound public properties (`WithPagination` + query string binding) | Matches the legacy's URL-reflected search state (`?q=&cat=&page=`) with far less code than a hand-rolled fetch/query-string sync |
| Member dashboard (profile, notifications, comments, membership status, billing) | Livewire components per page/section | Interactive (mark-as-read, delete-own-comment, avatar upload) without full-page reloads; matches the legacy's SPA-like UX without needing a separate frontend framework |
| Admin CRUD (FAQs, testimonials, team, hero banners — the legacy `CrudManager` entities) | **One reusable Livewire component**, config-driven per entity (mirroring the legacy's own `CrudManager` pattern, which was already a reasonable abstraction) | Avoids duplicating list+dialog+form Blade/JS for 4+ near-identical entities; this is the one place a small generic abstraction is justified because the legacy pattern already proved it out and the entities are genuinely uniform (no per-entity business logic beyond field lists) |
| Admin CRUD needing rich text/image upload/status workflow (blog, news, events, jobs, shop products, membership applications, memberships) | Bespoke Livewire components per entity | These have real per-entity logic (workflow states, notifications, file handling) that the generic CrudManager pattern deliberately does not attempt to cover — matches the legacy's own split between `CrudManager`-driven and bespoke `DataTable`+Dialog admin pages |
| Checkout | Livewire (or a plain Controller if ACI prefers a traditional multi-page checkout) | TBC — see `16_OPEN_DECISIONS.md`; either is compatible with this architecture, decide during UI design |

- **Laravel mechanism**: Livewire 3 components registered under `app/Livewire`, Blade views under `resources/views/livewire`; standard Controllers for the non-interactive surfaces.
- **Alternatives considered**: a full SPA (Vue/React) consuming a JSON API — rejected: this is exactly the reference app's architecture (TanStack Start/React), and re-introducing a client-side framework would reproduce the "client-side is never the real security boundary" problem class the reverse-engineering pass spent significant effort documenting (`AUTHORIZATION.md`), for no benefit ACI has asked for; Livewire keeps all state/authorization decisions server-side by construction.

## 4. Naming conventions

- Models are named for the business concept, not the legacy table (`MembershipApplication`, not `membership_applications` mirrored verbatim) — standard Laravel convention.
- Actions are named as verb-phrases matching the business operation (`ActivateMembership`, not `MembershipActivator`).
- Domain events are named in the past tense (`MembershipActivated`, `PaymentConfirmed`, `MembershipApplicationSubmitted`) and are the mechanism by which cross-domain reactions (send a notification, write an audit log entry) are decoupled from the Action that caused them — see §5.
- Enums (application status, payment status, order status) are implemented as **PHP 8.1+ backed enums** (`MembershipApplicationStatus::Submitted`, etc.) rather than bare strings or Postgres-style native DB enums — matches the recommendation already made in `DATABASE.md`'s MySQL Migration Considerations (a VARCHAR column + PHP enum cast, so adding a value never requires an `ALTER TABLE`).

## 5. Events & Listeners — where they earn their place

**ARCHITECTURAL DECISION**: use Laravel's Event/Listener system specifically to decouple "a business fact happened" from "here's everything that should react to it" — not as a universal pattern for every state change.

Concretely: `Actions/Membership/ActivateMembership` fires a `MembershipActivated` event after its transaction commits; separate Listeners handle sending the welcome/account-setup notifications (`06_NOTIFICATION_ARCHITECTURE.md`), writing the `MembershipStatusHistory` entry, and writing an `AuditLog` entry (`12_AUDIT_LOGGING_ARCHITECTURE.md`) — so the Action itself doesn't need to know about every downstream consequence, and a future new consequence (e.g. a CRM sync) can be added as a new Listener without touching the Action.

- **Why appropriate for ACI**: several confirmed workflows have multiple independent side effects on one trigger (membership activation alone triggers: number generation, expiry calculation, welcome email, account setup email, digital card availability, status history, audit log) — Events/Listeners keep `ActivateMembership` itself focused on the core business logic rather than growing into a class that also knows how to send five kinds of email.
- **Alternatives considered**: doing everything inline in the Action — rejected only for the workflows with genuinely multiple, independent side effects (membership activation, payment confirmation); simple single-effect operations (e.g. a comment's `approve()`) do not need an event at all and should just perform their one side effect directly, per "avoid unnecessary complexity."

## 6. Configuration & service providers

- Domain-specific configuration (promotion priority defaults, reapplication cooldown default of 30 days, bank-detail field set, membership number format constants) lives in dedicated config files (`config/membership.php`, `config/payments.php`) rather than hard-coded constants — directly satisfies the CONFIRMED REQUIREMENT that the cooldown period, promotion parameters, and similar values remain admin/config-configurable, not hard-coded into application logic (`WORKFLOWS.md` §0.6, §0.11, §0.14).
- One `AppServiceProvider`-registered binding for the `PaymentGatewayContract` (see `08_PAYMENT_ARCHITECTURE.md`) so the concrete gateway (manual bank transfer today, a real gateway later) is swappable via config, not a code change.

## 7. Frontend architecture — preserving ACI's visual design without Supabase

**Scope note**: per the Phase 2 restriction, no Blade views, Livewire components, or pages are built in this phase. This section defines the *target structure and approach* for when they are, so that visual-design preservation is planned for rather than improvised page-by-page during implementation.

- **What is being proposed**: the reference application (`aci-referance`) remains the UI/UX source of truth for look-and-feel — colours, typography, spacing, component styling, layout patterns — but its React/TanStack **component code** is never ported or referenced by the Laravel implementation; only its **rendered visual design** is. Concretely: during implementation (not this phase), the reference app's compiled Tailwind configuration/CSS (class usage patterns, colour palette, font choices) is used as a **design reference** to populate this project's own `tailwind.config.js` (theme colours, font family, spacing scale) and a small set of reusable Blade components (`resources/views/components/`) for the recurring visual elements the reference app has (site header/nav, footer, card layouts, buttons, form field styling, the hero banner treatment) — each rebuilt natively in Blade/Tailwind, not extracted as a build artifact from the reference app.
- **Why appropriate for ACI**: ACI's members and visitors already know this site's look — a technology-stack rebuild should not also be an unrequested visual redesign. Rebuilding the same visual language natively in Blade/Tailwind (rather than trying to literally reuse any reference-app frontend code, which is React/TanStack and not portable to Blade) achieves visual continuity while genuinely removing every Supabase/React dependency, per the CONFIRMED requirement.
- **Laravel mechanism**: standard `laravel-vite-plugin` asset pipeline (Tailwind CSS + a small amount of Alpine.js, per the confirmed target stack) — one root layout (`resources/views/layouts/app.blade.php`) with `@section`/`@yield` or Blade component slots for the header/footer/nav (matching the reference app's own single-root-layout structure, `ROUTES.md`'s "(global) Header"/"(global) Footer" entries), and reusable Blade components for repeated UI patterns (card, button, form field, badge) rather than duplicating Tailwind utility strings across every view.
- **Security considerations**: none specific to visual design; the removal of the reference app's client-side framework structurally removes the "authorization decisions made in JS component state" problem class documented at length in `AUTHORIZATION.md`, as already noted in ADR-03.
- **Data integrity considerations**: none — presentation-layer concern only.
- **Alternatives considered**: (1) attempting to directly reuse/transpile the reference app's React components — rejected, not meaningfully possible into Blade/Livewire and would reintroduce exactly the client-side architecture being removed; (2) a from-scratch visual redesign, ignoring the reference app's look entirely — rejected as out of scope (ACI asked for a technology migration, not a rebrand) unless ACI explicitly requests one; (3) keeping the reference app running behind Laravel as a "just for the frontend" layer (a disguised hybrid) — explicitly excluded by the "do not create a hybrid Laravel + Supabase architecture" requirement, since the reference app's data layer is inseparable from its component code.
- **Exact design-token extraction process** (which specific colours/fonts/spacing values to carry over, and whether any visual refresh is wanted alongside the migration) is implementation-time work informed by direct inspection of the reference app's rendered output — not fixed as specific hex/rem values in this architecture document, and not something this phase performs (per the Phase 2 restriction against building pages). Recorded in `16_OPEN_DECISIONS.md`.

*Per the Phase 2 restriction: this is a proposed structure for future implementation phases. No files under `app/`, `resources/`, `routes/`, or `database/` have been created.*
