# 01 — Architecture Overview

Phase: **LARAVEL ARCHITECTURE (Phase 2)** — design-only. No migrations, models, controllers, routes, views, Livewire components, tests, seeders, or factories have been created as part of this document set.

## 1. Inputs to this design

This architecture is derived from, and must remain consistent with:
- `docs/reverse-engineering/*.md` (the completed reverse-engineering record — in particular `WORKFLOWS.md` §0, which is the authoritative, ACI-confirmed membership business specification; `DATABASE.md`, `AUTHORIZATION.md`, `NOTIFICATIONS.md`, `STORAGE.md`, `VALIDATION.md`, `LEGACY_RISKS.md`, `ROUTES.md`, `FEATURES.md`, `SOURCE_INVENTORY.md`, `SUPABASE.md`).
- `CLAUDE.md` (project rules: reverse-engineer-first, Laravel/MySQL target, phase gating, conflict/legacy/uncertainty rules).
- The Phase 2 instructions given directly by ACI/the project owner (membership business rules restated and reconfirmed; architecture principles; target domains; payment and commerce architecture requirements; security requirements; deliverable list).

Where the legacy Lovable/Supabase reference application's actual behaviour conflicts with the confirmed ACI business rules recorded in `docs/reverse-engineering/WORKFLOWS.md` §0, **the confirmed rules govern**. The reference application at `C:\xampp\htdocs\aviationclub\aci-referance` remains read-only reference material for this and all future phases — it is not touched by this or any prior phase.

## 2. Target stack (confirmed)

| Layer | Choice | Notes |
|---|---|---|
| Language/runtime | PHP 8.3+ | Updated per the latest Phase 2 instruction (supersedes `CLAUDE.md`'s earlier "PHP 8.2" foundational note — PHP 8.3+ is backward-compatible with 8.2-targeted guidance elsewhere in this document set, so no other document needs a corresponding change) |
| Framework | Laravel 13 | Confirmed by Phase 2 instructions |
| Database | MySQL 8 | XAMPP locally, SiteGround MySQL in production |
| Frontend rendering | Blade + Livewire + Alpine.js + Tailwind CSS | Per `CLAUDE.md` / the reverse-engineering prompt's target stack |
| Auth | Laravel-native session auth (no Supabase, no third-party auth-as-a-service) | CONFIRMED REQUIREMENT |
| Hosting | SiteGround (shared/cloud hosting or Laravel Cloud if adopted later) | CONFIRMED REQUIREMENT — see §7 for deployment topology implications |
| Payments | Provider-independent architecture; initial implementation is manual bank transfer with evidence upload (WORKFLOWS.md §0.8–§0.9); real gateways (Stripe/PayPal-style) are a future, not-yet-built integration behind the same abstraction | See `08_PAYMENT_ARCHITECTURE.md` |
| Queue | Database queue driver by default (ASSUMPTION pending hosting-tier confirmation — see `11_BACKGROUND_JOBS_ARCHITECTURE.md` and `16_OPEN_DECISIONS.md`) | |
| File storage | Laravel Filesystem, local disk(s) on SiteGround; S3-compatible disk left as a future option, not required now | See `07_FILE_STORAGE_ARCHITECTURE.md` |

**Explicitly excluded** (CONFIRMED REQUIREMENT, stated directly by ACI and consistent with `SUPABASE.md`'s conclusion that none of the reference app's Supabase mechanics map 1:1 onto Laravel): Supabase (Postgres-as-a-service, Auth/GoTrue, Row Level Security, Supabase Storage), Vercel/Nitro serverless packaging, any hybrid Laravel+Supabase arrangement, and the Lovable platform's build tooling or telemetry (`@lovable.dev/*`, `window.__lovableEvents`).

## 3. Architectural style

**ARCHITECTURAL DECISION: Laravel-native modular monolith**, organized into the 13 domains listed in `02_DOMAIN_ARCHITECTURE.md`, deployed and operated as a single Laravel application.

- **What is being proposed**: one Laravel codebase, one deployable artifact, one database — internally organized so that each business domain's models, requests, policies, actions, and views are easy to locate and reason about independently, without physically separating them into different services or repositories.
- **Why appropriate for ACI**: ACI is a small club with a small engineering team (per `CLAUDE.md`'s "maintainable by a small team" quality requirement and the reverse-engineering finding that the reference app itself was a single small codebase). A monolith avoids the operational overhead of service discovery, distributed transactions, and multi-repo coordination that ACI has no organizational capacity to run.
- **Laravel mechanism**: standard Laravel application structure (`app/`, `routes/`, `resources/`), with domain-oriented **namespacing** inside the standard top-level folders rather than a package-per-domain split (see `03_LARAVEL_ARCHITECTURE.md` for the exact directory layout).
- **Security considerations**: a monolith centralizes authorization enforcement (one Gate/Policy layer, one session/auth guard) rather than requiring the same checks to be re-implemented and kept in sync across services — directly addresses `LEGACY_RISKS.md`'s finding that the reference app's authorization was inconsistently enforced across different code paths (browser RPC vs. server function vs. RLS).
- **Data integrity considerations**: a single MySQL database lets cross-domain invariants (e.g. "a payment relates to exactly one of membership or order") be enforced with real foreign keys and database transactions, which a multi-service split would force into eventual-consistency patterns ACI does not need.
- **Alternatives considered**:
  1. *Microservices* (one service per domain) — rejected: explicitly excluded by the Phase 2 instructions ("do not introduce microservices"), and would require infrastructure (service mesh, message broker, distributed tracing) unjustified by ACI's scale and unsupportable on typical SiteGround hosting.
  2. *Package-based modular monolith* (e.g. `nwidart/laravel-modules`, each domain as an installable Laravel package with its own service provider, routes file, views, migrations) — rejected for now: adds a layer of package-manager indirection and boilerplate (module manifests, autoloading config, separate namespaces per module) that a team of the size implied by this project does not need today. Namespacing within the standard app structure gives most of the organizational benefit at a fraction of the tooling cost. Revisit only if the team or codebase grows substantially (see `16_OPEN_DECISIONS.md`).
  3. *Fully flat, undifferentiated Laravel app* (everything in `app/Models`, `app/Http/Controllers` with no domain subfolders, as the default `laravel new` scaffold would produce) — rejected: with 13 domains and the entity counts implied by `DATABASE.md` (30+ legacy tables, likely a similar or larger count in the new design once commerce/payments/promotions are added), a flat structure becomes hard to navigate and violates the "understandable to a non-expert project owner" and "maintainable by a small team" quality requirements.

## 4. Layered request flow (conceptual)

```
Browser
  │
  ▼
Route (web.php, grouped by domain + middleware: auth, admin, throttle)
  │
  ▼
Controller (thin) or Livewire Component (stateful UI)
  │
  ▼
Form Request (validation + authorize())      ──────────────► Policy / Gate (authorization)
  │
  ▼
Action (single-purpose class for a business operation, e.g. ApproveMembershipApplication)
  │        │                          │                    │
  ▼        ▼                          ▼                    ▼
Eloquent  Event (domain event,     Job (queued,          Notification / Mail
Models    e.g. MembershipActivated) background work)      (queued)
  │
  ▼
MySQL (transactions, constraints, DECIMAL money columns)
```

Simple CRUD (FAQs, testimonials, team members, hero banners, site settings — the legacy `CrudManager` entities in `FEATURES.md` §D) skips the Action layer entirely: Controller/Livewire → Form Request → Eloquent model directly. Actions are reserved for genuine multi-step business workflows (membership review/activation, payment confirmation, checkout, promotion resolution) per the Phase 2 instruction "do not introduce a generic service layer for every CRUD operation." See `03_LARAVEL_ARCHITECTURE.md`.

## 5. Domain map (summary)

Full detail in `02_DOMAIN_ARCHITECTURE.md`. The 13 domains, at a glance:

| # | Domain | One-line purpose |
|---|---|---|
| 1 | Identity & Access | Users, authentication, roles, sessions, account setup |
| 2 | Membership | Applications, categories, activated memberships, status history |
| 3 | Promotions | Admin-configurable membership promotions and eligibility resolution |
| 4 | Payments | Provider-independent payment records, webhooks, refunds — shared by Membership and Commerce |
| 5 | Commerce / E-commerce | Products, cart, orders, inventory, shipping, coupons |
| 6 | Content / CMS | Blog, news, FAQs, testimonials, team, hero banners, site settings, SEO |
| 7 | Events | Event listings (distinct from "domain events" in the code sense) |
| 8 | Jobs | Job board postings and applications |
| 9 | Community / Referrals | Referral invitations, blog comments |
| 10 | Notifications / Communication | Mail, in-app notifications, scheduled reminders |
| 11 | Files / Documents | Uploads, private/public storage, signed access |
| 12 | Audit / Activity Logging | Security- and business-sensitive event history |
| 13 | Administration | Admin panel shell, dashboards, cross-domain admin actions |

## 6. Cross-cutting principles (apply to every domain)

These are restated here because they recur throughout every document in this package; each is elaborated where relevant:

- **Money is always `DECIMAL`**, never float, in any schema design (Commerce and Payments).
- **Historical prices and addresses are snapshotted** onto the transaction (order line item, payment record), never looked up live from a mutable current row — this was already identified as correct legacy practice in `DATABASE.md` (`shop_order_items.product_name`/`unit_price`) and is now a standing rule for the whole system.
- **Payments must be verifiable and idempotent**: no business state changes on a browser redirect alone; webhook processing is keyed and idempotent (`08_PAYMENT_ARCHITECTURE.md`).
- **Inventory uses a ledger**, not a bare mutable stock integer (`09_ECOMMERCE_ARCHITECTURE.md`).
- **Authorization is explicit and server-side** everywhere — no client-side-only gate is ever the real security boundary (directly correcting the reference app's client-side `AdminLayout`/`beforeLoad` patterns flagged throughout `AUTHORIZATION.md`).
- **Ownership/IDOR protections** are designed per-resource via Policies, not assumed (correcting `AUTHORIZATION.md`/`LEGACY_RISKS.md` findings such as the unauthenticated-by-UUID shop order confirmation page).
- **Private documents use private storage** with authorized, time-limited access — never a public bucket (`07_FILE_STORAGE_ARCHITECTURE.md`).
- **Audit logging** captures administrative and security-sensitive changes (`12_AUDIT_LOGGING_ARCHITECTURE.md`), replacing the reference app's inconsistent/absent audit trail.
- **No EAV, no unnecessary polymorphism, no JSON columns where a relational structure fits better** — the one deliberate, narrow exception is generic activity/audit logging, which is a recognized legitimate use of a polymorphic relation (see `12_AUDIT_LOGGING_ARCHITECTURE.md`).

## 7. Deployment topology (conceptual)

```
                    ┌───────────────────────────────┐
  Visitor/Member ──▶│  SiteGround web server         │
                    │  (Apache/Nginx + PHP-FPM)      │
                    │  public/ document root          │
                    └───────────────┬────────────────┘
                                    │
                    ┌───────────────▼────────────────┐
                    │  Laravel application            │
                    │  (this codebase)                 │
                    └───┬───────┬───────┬─────────────┘
                        │       │       │
                ┌───────▼─┐ ┌───▼───┐ ┌─▼──────────────┐
                │ MySQL    │ │ Local │ │ SMTP (SiteGround│
                │ (SiteGround│ storage│ │ mailbox)        │
                │ managed) │ │ disks │ │                 │
                └──────────┘ └───────┘ └─────────────────┘

  Cron (SiteGround "Cron Jobs" panel):
    * * * * *  php artisan schedule:run   (drives the Laravel Scheduler)
    scheduler dispatches queue:work runs / queued jobs per 11_BACKGROUND_JOBS_ARCHITECTURE.md
```

This is a conventional shared/cloud-hosting Laravel deployment — no serverless functions, no edge runtime, no per-request cold-start constraints (directly the opposite of the reference app's Vercel/Nitro packaging, which `SUPABASE.md`/`LEGACY_RISKS.md` flagged as inapplicable). Exact hosting tier (shared vs. SiteGround Cloud vs. Laravel Cloud) affects queue-driver and scheduler mechanics — see `16_OPEN_DECISIONS.md`.

## 8. Non-goals for this phase and this system

- No Supabase, no Vercel, no hybrid architecture (CONFIRMED REQUIREMENT).
- No microservices, no generic repository layer over Eloquent, no generic CRUD service layer, no EAV, no blanket polymorphism (CONFIRMED REQUIREMENT, Phase 2 instructions).
- No migrations, models, controllers, routes, views, Livewire components, API endpoints, tests, seeders, or factories in this phase (CONFIRMED REQUIREMENT — DESIGN-ONLY phase per `CLAUDE.md`'s Implementation Rule and the Phase 2 restriction).
- No premature optimization or enterprise complexity ACI does not need (e.g. no message queue broker beyond Laravel's own queue abstraction, no read-replica design, no caching layer beyond Laravel's built-in cache facade used where genuinely useful).

## 9. How to read this document set

| File | Contents |
|---|---|
| `02_DOMAIN_ARCHITECTURE.md` | The 13 domains in detail: entities, relationships, responsibilities |
| `03_LARAVEL_ARCHITECTURE.md` | Directory/namespace layout, layer responsibilities, Blade vs. Livewire decision, frontend/visual-design-preservation approach |
| `04_MEMBERSHIP_ARCHITECTURE.md` | Full membership application/promotion/activation/renewal design |
| `05_AUTHORIZATION_ARCHITECTURE.md` | Identity & Access + authorization: users, roles, permissions, Policies, Gates, IDOR protection, rate limiting |
| `06_NOTIFICATION_ARCHITECTURE.md` | Mail/notification design, mapped to `NOTIFICATIONS.md`'s confirmed list |
| `07_FILE_STORAGE_ARCHITECTURE.md` | Disks, visibility, signed URLs, validation, retention |
| `08_PAYMENT_ARCHITECTURE.md` | Provider-independent payment, webhook, refund design |
| `09_ECOMMERCE_ARCHITECTURE.md` | Products, cart, orders, inventory ledger, checkout revalidation |
| `10_CONTENT_ARCHITECTURE.md` | CMS content: pages, blog, news, events, comments, FAQs, testimonials, team, site settings |
| `11_BACKGROUND_JOBS_ARCHITECTURE.md` | Queues, scheduled jobs, SiteGround cron |
| `12_AUDIT_LOGGING_ARCHITECTURE.md` | Audit trail design |
| `13_INTEGRATION_ARCHITECTURE.md` | External integrations (SMTP, future payment gateways, future QR, SMS/analytics/social — identified only) |
| `14_ERROR_VALIDATION_ARCHITECTURE.md` | Exception handling, validation strategy |
| `15_ARCHITECTURAL_DECISIONS.md` | Consolidated ADR log of every decision across this document set |
| `16_OPEN_DECISIONS.md` | Every TBC item requiring ACI/product-owner input before or during implementation |
| `17_JOBS_AND_COMMUNITY_ARCHITECTURE.md` | Jobs/applications + Community (referrals, and the undefined "partners"/"advisory members"/"referral contacts" concepts) |

`17_JOBS_AND_COMMUNITY_ARCHITECTURE.md` is an **additional** document beyond the 16 minimum deliverables, split out from Content specifically because several of its entities have no basis in the reverse-engineering record and needed to be visibly flagged rather than buried inside a larger document — see its own introduction for why.

*Per the Phase 2 restriction: this and every document in `docs/architecture/` is documentation only. No application code has been created or modified.*
