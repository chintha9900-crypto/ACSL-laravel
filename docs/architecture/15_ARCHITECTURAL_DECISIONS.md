# 15 — Architectural Decisions (ADR Log)

A consolidated, browsable index of every architectural decision made across this document set, in short ADR form (Context / Decision / Consequences / Alternatives). Full reasoning, security, and data-integrity discussion for each lives in the referenced domain document — this log is the "at a glance" index, not a replacement for reading those documents.

Status of every ADR below: **Accepted** (for this design phase — subject to human review before Phase 3, per the Phase 2 restriction).

---

### ADR-01 — Laravel-native modular monolith
**Context**: 13 business domains, small team, SiteGround hosting, no appetite for distributed-systems operational overhead.
**Decision**: one Laravel codebase, domain-oriented namespacing inside standard top-level folders (`01_ARCHITECTURE_OVERVIEW.md` §3, `03_LARAVEL_ARCHITECTURE.md` §1).
**Consequences**: simple deployment and transactional integrity across domains; requires discipline to keep domain boundaries clean without physical enforcement.
**Alternatives rejected**: microservices (explicitly excluded); package-based modules (`nwidart/laravel-modules`, unnecessary tooling overhead now); fully flat structure (unnavigable at this entity count).

### ADR-02 — Actions over a generic service layer
**Context**: several genuinely multi-step, transactional workflows (membership review/activation, payment confirmation, checkout) alongside many simple CRUD entities.
**Decision**: single-purpose `Actions/{Domain}/*` classes for workflows; plain Controller/Livewire + Form Request + Eloquent for CRUD (`03_LARAVEL_ARCHITECTURE.md` §2).
**Consequences**: workflows are testable in isolation and transactionally safe; CRUD stays simple with no indirection.
**Alternatives rejected**: fat Eloquent models with static workflow methods; a generic `{Domain}Service` class per domain (explicitly excluded); repository pattern over Eloquent (explicitly excluded).

### ADR-03 — Livewire for interactive surfaces, Blade+Controllers for static/read-mostly pages
**Context**: legacy app was a full SPA (TanStack Start/React); target stack specifies Blade/Livewire/Alpine.
**Decision**: per-surface split documented in `03_LARAVEL_ARCHITECTURE.md` §3 (public marketing = Blade; membership application, dashboard, blog search, admin CRUD = Livewire).
**Consequences**: no client-side framework, no JSON API surface needed for the web UI, authorization stays server-side by construction.
**Alternatives rejected**: reproducing a client-side SPA (would reintroduce the exact "client-side is never the real boundary" problem class the reverse-engineering pass documented at length).

### ADR-04 — Merge `auth.users` + `profiles` into one Laravel `User` model
**Context**: legacy split identity (Supabase Auth) from profile data (`profiles` table) because Supabase's architecture forced it.
**Decision**: one `User` table (`05_AUTHORIZATION_ARCHITECTURE.md` §1).
**Consequences**: simpler queries, no cross-table identity joins; standard Laravel auth scaffolding applies unmodified.
**Alternatives rejected**: keeping a separate `Profile` table 1:1 with `User` — no longer necessary once there's no anon/service-role key split to work around; revisit only if profile data volume/access patterns later justify separation.

### ADR-05 — Two flat roles via a simple enum column, not a permissions package
**Context**: legacy model was exactly two flat roles (`admin`/`member`), no per-resource permission variation ever built.
**Decision**: `User.role` enum + `Gate::before` blanket admin bypass + per-model Policies for ownership (`05_AUTHORIZATION_ARCHITECTURE.md` §1–§2).
**Consequences**: minimal complexity matching actual current need.
**Alternatives rejected**: Spatie `laravel-permission` — deferred, not rejected outright; upgrade path noted in `16_OPEN_DECISIONS.md` if finer-grained permissions are ever confirmed as needed.

### ADR-06 — Secure one-time account-setup token replaces plaintext temp passwords
**Context**: `AUTHORIZATION.md`/`LEGACY_RISKS.md` flagged plaintext-temp-password-by-email as a significant weakness; CONFIRMED ACI requirement explicitly forbids it.
**Decision**: purpose-built `AccountSetupToken` (hashed at rest, single-use, time-limited) — `04_MEMBERSHIP_ARCHITECTURE.md` §7.
**Consequences**: closes 4 distinct legacy weaknesses simultaneously (see `05_AUTHORIZATION_ARCHITECTURE.md` §10 summary table).
**Alternatives rejected**: reusing Laravel's built-in password-reset broker unmodified — assumes an existing account/password to reset, doesn't fit "applicant creates their first password."

### ADR-07 — Per-category/year membership number sequence via locked counter table; number issued once per member
**Context**: CONFIRMED, non-negotiable 9-character format, per-category (per-year) sequencing, no `MAX()+1`, transaction-safe; **the number identifies the member for life and never changes at renewal (OD-04)**.
**Decision**: `membership_number_sequences` table, `SELECT ... FOR UPDATE` inside the **first-activation** transaction (`04_MEMBERSHIP_ARCHITECTURE.md` §4); the number lives on the stable `Membership` (member) record, renewals are separate `MembershipTerm` rows and never call the generator.
**Consequences**: collision-free under concurrency; one extra table, trivial maintenance; a member keeps one number across all renewals.
**Alternatives rejected**: `MAX()+1` (explicitly forbidden); global `AUTO_INCREMENT` with a display-only prefix (doesn't give independent per-category sequences); optimistic retry-on-duplicate-key (more moving parts than the locked-counter approach); a new number per renewal term (contradicts the confirmed rule).

### ADR-08 — The free introductory period is a standard membership rule, not a promotion (supersedes the earlier "promotion eligibility" ADR)
**Context**: CONFIRMED (OD-10): the initial membership is free for the first 6 months for **every** approved new member; not optional, not an eligibility calculation, no re-evaluation after approval; renewal afterwards is paid.
**Decision**: the introductory period is the member's first `MembershipTerm` (`introductory`, `payment_not_required`), its length read from `membership_settings.introductory_period_months` (default 6) at activation (after approval and the payment/free decision) and snapshotted; no promotion lookup exists in the activation path (`04_MEMBERSHIP_ARCHITECTURE.md` §5). The Promotions domain is **deferred** for possible future marketing promotions and never controls this rule.
**Consequences**: no promotion resolution machinery in the initial build; the length is configurable data; no fake £0 payment (structurally impossible); adding or changing a *future* marketing promotion can never alter the mandatory rule.
**Alternatives rejected**: modelling the free period as a configurable promotion with priority/category applicability and activation-time resolution (decides something that is not conditional; ambiguous evaluation timing); hard-coding "6 months" in code (explicitly forbidden).

### ADR-09 — Two distinct payment-status vocabularies, not one shared enum
**Context**: the Phase 2 instructions specify two different status value sets under the name "payment status" — one business-workflow-shaped (membership term), one gateway-transaction-shaped (generic `Payment`/Commerce).
**Decision**: keep them as separate enums on separate models, synchronized by application-layer event listeners (`08_PAYMENT_ARCHITECTURE.md` §3).
**Consequences**: each vocabulary stays meaningful for its own purpose; the free introductory term (`payment_not_required`) never needs a nonsensical gateway status; the business-workflow vocabulary now lives on `MembershipTerm`.
**Alternatives rejected**: one unified enum covering both — would force meaningless values into one context or the other.

### ADR-10 — Provider-independent payment gateway interface
**Context**: CONFIRMED requirement (`WORKFLOWS.md`, `SUPABASE.md`, CLAUDE.md) for provider-independent payment architecture; current implementation is manual bank transfer.
**Decision**: `PaymentGatewayContract`, bound via config, `ManualBankTransferGateway` as the only implementation today (`08_PAYMENT_ARCHITECTURE.md` §2).
**Consequences**: a future real gateway is additive, not a rewrite.
**Alternatives rejected**: hard-coding bank-transfer logic directly into Membership (tight coupling, contradicts the explicit requirement); Laravel Cashier (subscription-billing-shaped, premature before any gateway is chosen).

### ADR-11 — Idempotent webhook processing via a unique `(gateway, event_id)` ledger
**Context**: CONFIRMED requirement: uniquely identifiable webhook events, idempotent processing, never trust a redirect.
**Decision**: `PaymentWebhookEvent` with a unique constraint, queued processing job only for newly-seen events (`08_PAYMENT_ARCHITECTURE.md` §4).
**Consequences**: safe against gateway retry/redelivery; auditable record of every inbound call.
**Alternatives rejected**: processing inline in the webhook Controller with no dedup record (not idempotent, blocks the response on external processing time).

### ADR-12 — Inventory as an append-only ledger, not a mutable stock column
**Context**: CONFIRMED requirement, standard e-commerce data-integrity practice.
**Decision**: `InventoryTransaction` rows per typed event; current stock is derived, optionally cached with reconciliation (`09_ECOMMERCE_ARCHITECTURE.md` §2).
**Consequences**: every stock change is explainable; supports reserve/release for checkout correctly.
**Alternatives rejected**: a bare `stock_quantity` column decremented directly (explicitly forbidden; race-prone; no audit trail).

### ADR-13 — Two Filesystem disks: `public` and `private`, split by actual sensitivity
**Context**: legacy put everything (including genuinely public images) behind private-bucket-plus-signed-URL; CONFIRMED requirement that proof/payment documents specifically must be private.
**Decision**: `public` disk for marketing/content images and avatars; `private` disk, Policy-gated access, for aviation proof and payment evidence (`07_FILE_STORAGE_ARCHITECTURE.md` §1–§2).
**Consequences**: simpler, cheaper public asset delivery; genuinely sensitive documents get real per-request authorization, stronger than a time-boxed signed URL alone.
**Alternatives rejected**: one disk, everything signed (legacy pattern) — unnecessary overhead for public content.

### ADR-14 — Rich text sanitized server-side on save, allow-list based
**Context**: CONFIRMED requirement ("safe rich-text handling"); legacy never sanitized at write or read time.
**Decision**: `mews/purifier` (HTMLPurifier) applied in Form Requests for every rich-text field (`10_CONTENT_ARCHITECTURE.md` §5, `13_INTEGRATION_ARCHITECTURE.md` §6).
**Consequences**: stored data itself is safe, not just escaped-at-render.
**Alternatives rejected**: sanitizing only at render/output time — leaves raw unsafe HTML in the database, a latent risk for any future code path that forgets to escape it.

### ADR-15 — Generic `AuditLog` uses the one deliberate, scoped polymorphic relation in the system
**Context**: audit logging genuinely needs to reference "any kind of thing"; the system's general principle forbids blanket polymorphism.
**Decision**: `AuditLog.subject` `morphTo`, explicitly called out as the sole exception (`12_AUDIT_LOGGING_ARCHITECTURE.md` §1).
**Consequences**: one clear, queryable cross-domain admin action log; no other domain needs or uses polymorphism.
**Alternatives rejected**: a separate audit table per domain (duplicated shape, harder cross-domain reporting).

### ADR-16 — Database queue driver by default, cron-triggered `queue:work --stop-when-empty`
**Context**: SiteGround shared hosting typically has no Redis and cannot run a persistent worker process.
**Decision**: `database` queue driver; SiteGround Cron Jobs panel runs the worker once per minute (`11_BACKGROUND_JOBS_ARCHITECTURE.md` §1).
**Consequences**: works within shared-hosting constraints; trivially upgradable to `redis`/Supervisor on a higher hosting tier with zero application-code change.
**Alternatives rejected**: `sync` driver as the default (blocks requests on email/webhook processing); assuming Redis availability (unconfirmed for ACI's actual hosting tier — see `16_OPEN_DECISIONS.md`).

### ADR-17 — Digital membership card generated on demand, never cached by default
**Context**: card must reflect current membership state (branding, status, valid-until) accurately.
**Decision**: render from a Blade view + `laravel-dompdf` at request time (`04_MEMBERSHIP_ARCHITECTURE.md` §10, `13_INTEGRATION_ARCHITECTURE.md` §4).
**Consequences**: no stale-cache/invalidation problem; adequate performance at ACI's scale.
**Alternatives rejected**: pre-rendering and caching the PDF (adds invalidation complexity for no confirmed performance need — premature optimization).

### ADR-18 — Global Eloquent scopes for "published/active only" visibility, replacing RLS
**Context**: MySQL/Eloquent has no automatic per-query row filtering equivalent to Postgres RLS; forgetting a manual filter is a real risk class the legacy's own dependence on RLS avoided by construction.
**Decision**: a `PublishedScope`/`ActiveScope` global scope per content model (`10_CONTENT_ARCHITECTURE.md` §9, `DATABASE.md`'s own MySQL Migration Considerations).
**Consequences**: visibility rules apply automatically to every query against the model, not just ones a developer remembers to filter.
**Alternatives rejected**: relying on each controller/query to manually filter — exactly the risk this ADR exists to avoid.

### ADR-19 — Jobs & Community split into an additional document, undefined entities flagged rather than designed
**Context**: the Phase 2 instructions add Jobs alongside Community entities (referrals, referral contacts, partners, advisory members) that partially have no basis anywhere in the reverse-engineering record.
**Decision**: pull Jobs+Community out of the Content document into a dedicated `17_JOBS_AND_COMMUNITY_ARCHITECTURE.md` (beyond the 16 minimum deliverables) so the undefined entities are visibly called out rather than buried; no business rules are invented for "partners," "advisory members," or "referral contacts" (`17_JOBS_AND_COMMUNITY_ARCHITECTURE.md` §4–§6).
**Consequences**: a clear, honest record of what is and isn't defined; implementation cannot proceed on these three entities without first getting a definition from ACI.
**Alternatives rejected**: guessing a reasonable-sounding shape for each undefined entity and presenting it as settled design — rejected outright; directly contradicts "never silently invent a business rule."

### ADR-20 — Frontend rebuilt natively in Blade/Tailwind to match existing visual design, not ported from React
**Context**: ACI wants its existing visual design preserved while all Supabase/React dependencies are removed; the reference app's frontend is React/TanStack, which cannot run inside a Blade/Livewire application.
**Decision**: treat the reference app as a visual/UX reference only — rebuild the same look via a native Tailwind config + reusable Blade components, never porting or embedding any reference-app frontend code (`03_LARAVEL_ARCHITECTURE.md` §7).
**Consequences**: genuine, complete removal of the React/Supabase frontend stack while preserving visual continuity for ACI's members; exact design tokens are an implementation-time extraction task, not fixed here.
**Alternatives rejected**: porting/transpiling React components (not meaningfully possible into Blade); keeping the reference app running as a disguised frontend layer (a hybrid architecture, explicitly excluded).

---

*This log will grow during DATABASE DESIGN and IMPLEMENTATION as further, more granular decisions are made. Every ADR here is subject to the human review gate before Phase 3 begins.*
