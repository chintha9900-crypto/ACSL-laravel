# 02 — Domain Architecture

These are architectural boundaries within one Laravel monolith (per `01_ARCHITECTURE_OVERVIEW.md` §3), not separate applications or services. Each domain below lists its purpose, key conceptual entities (not final schema — exact columns/types are DATABASE DESIGN phase), its relationships to other domains, and which reverse-engineered legacy features it replaces. Entity lists here are **conceptual** and intentionally stop short of full column definitions except where a specific field list was already given as a CONFIRMED REQUIREMENT (e.g. the `payments` entity in §4).

## 1. Identity & Access

**Purpose**: who a person is, how they authenticate, and what role they hold.

**Key entities**: `User` (replaces legacy `auth.users` + `profiles`, merged into one Laravel-native table — ARCHITECTURAL DECISION, see `04_MEMBERSHIP_ARCHITECTURE.md` and `05_AUTHORIZATION_ARCHITECTURE.md` for the merge rationale), `AccountSetupToken` (the secure one-time setup link required by WORKFLOWS.md §0.13, replacing the legacy plaintext-temp-password pattern), password reset tokens (Laravel's built-in mechanism).

**Relationships**: a `User` may have zero or one `Membership` (Membership domain); a `User` has a `role`; a `User` may be the actor recorded on `AuditLog` entries (Audit domain); a `User` may place `Order`s (Commerce domain) and hold `Payment`s (Payments domain).

**Replaces (legacy)**: Supabase Auth (`auth.users`, GoTrue), `profiles` table, `user_roles` table, `has_role()` RPC, `requireSupabaseAuth` middleware, the two duplicate bearer-token attacher files (all superseded — see `AUTHORIZATION.md`).

**Depends on**: nothing (foundational domain).

## 2. Membership

**Purpose**: the full applicant → application → approval → activation → renewal lifecycle, per `WORKFLOWS.md` §0 (CONFIRMED, authoritative).

**Key entities**: `MembershipCategory` (Student/Professional/Veteran, codes S/P/V), `MembershipApplication` (one row per application; statuses `submitted`/`more_details_required`/`approved`/`rejected`), `AviationProofDocument` (mandatory per application, all 3 categories), `Membership` (created only on activation; FK to its originating application), `MembershipNumberSequence` (per-category counter, see `04_MEMBERSHIP_ARCHITECTURE.md`), `MembershipStatusHistory` (audit trail of every application/membership status transition).

**Relationships**: one `MembershipApplication` → at most one `Membership` (never the reverse legacy two-table split); a `Membership` references at most one applied `MembershipPromotion` (Promotions domain, nullable); a `Membership`/`MembershipApplication` may have a related `Payment` (Payments domain, nullable — absent entirely for `payment_not_required`); a `Membership` belongs to a `User` (Identity domain) once activated.

**Replaces (legacy)**: the disconnected `memberships` (System A) + `membership_applications` (System B) pair (`LEGACY_RISKS.md` §1 — RESOLVED), `next_membership_number`/`verify_membership` Postgres RPCs, the plaintext-temp-password provisioning flow.

**Depends on**: Identity & Access (user creation on activation), Promotions (eligibility), Payments (paid path), Files/Documents (proof storage), Notifications (every M1–M11 email).

## 3. Promotions

**Purpose**: admin-configurable membership promotions (the introductory 6-month-free offer being the first, not a special case in code) and deterministic eligibility resolution when multiple promotions are simultaneously active, per `WORKFLOWS.md` §0.6 (CONFIRMED).

**Key entities**: `MembershipPromotion` (name, active flag, start/end date, free-membership flag, free-duration-months, applicable categories, priority/order).

**Relationships**: many `MembershipPromotion` rows may be active at once; at most one is ever selected per `Membership` (never stacked); the selection is recorded on the `Membership` record for audit.

**Replaces (legacy)**: nothing — this concept did not exist in the reference app at all; it is new, ACI-confirmed scope.

**Depends on**: nothing on its own; consumed by Membership at activation time.

## 4. Payments

**Purpose**: a single, provider-independent payment ledger shared by Membership (manual bank-transfer path today) and Commerce (future), per the Phase 2 payment architecture instructions.

**Key entities** (fields as directly specified by ACI — CONFIRMED REQUIREMENT):
- `Payment`: `user_id` (nullable), `order_id` (nullable), `membership_id` (nullable), `gateway`, `transaction_reference`, `idempotency_key`, `amount` (DECIMAL), `currency`, `status`, `payment_url`, `paid_at`, `failed_at`, `metadata`, timestamps.
- `PaymentWebhookEvent`: uniquely identifies an inbound gateway event (gateway + event id), records processing state, ensures idempotent handling.
- `PaymentRefund`: a refund against a `Payment`.

**Business rule (CONFIRMED)**: a `Payment` relates to exactly one business purpose — `membership_id` XOR `order_id` — never both, never neither for a real (non-`payment_not_required`) transaction.

**Relationships**: a `Payment` optionally belongs to a `Membership` or an `Order` (never both); a `Payment` has many `PaymentRefund`s; a `Payment`'s gateway events are recorded via `PaymentWebhookEvent`.

**Replaces (legacy)**: the manually-typed `memberships.payment_link`/`payment_amount`/`payment_status` columns and the complete absence of any payment concept on the legacy `shop_orders` table.

**Depends on**: nothing structurally (a shared service consumed by Membership and Commerce); see `08_PAYMENT_ARCHITECTURE.md` for the important distinction between this domain's gateway-facing `Payment.status` and Membership's own business-facing `payment_status` on the application/membership record.

## 5. Commerce / E-commerce

**Purpose**: the future e-shop — products, cart, checkout, orders, inventory, shipping, coupons, refunds. Scope is **future-facing**: the legacy e-shop (`FEATURES.md` §B, `WORKFLOWS.md` §2) had no payment gateway and a broken order lifecycle; this domain replaces it with a properly designed (but not over-built) commerce model per the Phase 2 commerce architecture instructions.

**Key entities**: `Product`, `ProductCategory`, `ProductImage`, `ProductVariant` (SKU-level), `InventoryTransaction` (ledger — see `09_ECOMMERCE_ARCHITECTURE.md`), `Cart`, `CartItem`, `Order`, `OrderItem` (snapshotted price/name, continuing the one correct legacy pattern), `Address` (snapshotted per order, not a live FK to a mutable address book row), `ShippingMethod`, `Coupon`, `Refund`.

**Relationships**: an `Order` has many `OrderItem`s, an optional `Payment` (Payments domain), a snapshotted shipping `Address`; a `Product` has many `ProductVariant`s and `InventoryTransaction`s; a `Cart` belongs to a `User` or a guest session.

**Replaces (legacy)**: `shop_categories`, `shop_products`, `shop_orders`, `shop_order_items` — see `09_ECOMMERCE_ARCHITECTURE.md` for what specifically changes (transactional order+items insert, populated `user_id`, a real inventory concept, a real order-status lifecycle, and payment integration via the Payments domain instead of no payment concept at all).

**Depends on**: Payments (checkout), Files/Documents (product images), Identity & Access (customer accounts, guest checkout TBC — see `16_OPEN_DECISIONS.md`).

## 6. Content / CMS

**Purpose**: all admin-managed marketing/informational content.

**Key entities**: `BlogPost`, `BlogCategory`, `BlogTag`, `NewsItem`, `Faq`, `Testimonial`, `TeamMember`, `HeroBanner`, `SiteSetting` (a true singleton — ARCHITECTURAL DECISION, see below), `SeoPage`, `SocialLink`, `FooterLink`.

**Relationships**: mostly independent content tables; `BlogPost` has many `BlogComment`s (Community domain).

**Replaces (legacy)**: the corresponding legacy tables 1:1 in concept, but fixes several `LEGACY_RISKS.md` items: `site_settings` becomes a true enforced singleton (`ARCHITECTURAL DECISION`: fixed primary key `id = 1`, `updateOrCreate`, no possibility of a second row — replacing the legacy's application-logic-only singleton with no DB guarantee); the redundant `site_settings.*_url` social columns are dropped in favor of `SocialLink` alone (resolves the duplication `FEATURES.md` flagged); News and Blog remain **separate** tables per this design (unifying them into one "articles" concept was raised as a question in `FEATURES.md` but is not a confirmed requirement — left as `TBC`, see `16_OPEN_DECISIONS.md`, to avoid inventing a merge ACI hasn't asked for).

**Depends on**: Files/Documents (images), Community (blog comments), Identity & Access (authorship).

## 7. Events

**Purpose**: event listings (club events, not to be confused with Laravel "Events" in the code sense — named distinctly as `EventListing` in code to avoid the collision, see `03_LARAVEL_ARCHITECTURE.md`).

**Key entities**: `EventListing` (title, slug, content, event date, location, status).

**Replaces (legacy)**: `events` table. Content-rendering inconsistency (plain text vs. HTML vs. blog/news) is left as a design question for the CMS content model, not silently resolved — see `16_OPEN_DECISIONS.md`.

**Depends on**: Files/Documents (images), Content (shares admin CRUD patterns).

## 8. Jobs

**Purpose**: the job board.

**Key entities**: `JobPosting`, `JobApplication`.

**Replaces (legacy)**: `jobs`, `job_applications`. The legacy's dead server-side-filter capability (never wired to the UI) is resolved as an `ARCHITECTURAL DECISION` to filter server-side from the start (query-string bound, matching the Blog index pattern) rather than shipping the full dataset to the browser.

**Depends on**: Identity & Access (applicants), Notifications (status-change emails — a new capability; the legacy notified no one on status change, `NOTIFICATIONS.md`).

## 9. Community / Referrals

**Purpose**: member-generated content and peer-to-peer invitation.

**Key entities**: `BlogComment` (moderation default TBC — see `16_OPEN_DECISIONS.md`), `ReferralInvitation` (plain invite-email only, no tracking/reward, matching confirmed current scope per `WORKFLOWS.md`/`FEATURES.md` — building a real tracked referral program is explicitly **not** in scope unless ACI approves it later).

**Depends on**: Content (comments belong to blog posts), Identity & Access, Notifications.

## 10. Notifications / Communication

**Purpose**: every outbound email and in-app notification, mapped to `NOTIFICATIONS.md`'s confirmed M1–M11 list plus the remaining legacy-pattern admin notifications (contact enquiry, shop order) that are not membership-specific.

**Key entities**: no persisted "email" entity beyond Laravel's own `notifications` table (database channel, replacing the legacy `notifications` table 1:1) and the queue's `jobs`/`failed_jobs` tables.

**Depends on**: every domain that triggers a notification (Membership, Commerce, Content/Community, Identity & Access).

## 11. Files / Documents

**Purpose**: all file upload/storage/access concerns, cross-cutting but centralized so every domain applies the same validation/authorization/private-vs-public rules rather than re-inventing them (directly correcting the legacy app's inconsistent per-feature upload handling, `STORAGE.md`).

**Key entities**: no single "Document" entity is imposed on every domain (would be over-generalization / a step toward EAV-like design the principles forbid) — instead each domain's file-bearing entity (`AviationProofDocument`, `PaymentEvidence`, `ProductImage`, `BlogPost.featured_image`, `User.avatar_path`) stores its own path/disk reference, and this domain owns the shared **Filesystem disk configuration, validation rules, and signed-URL access pattern** all of them use. See `07_FILE_STORAGE_ARCHITECTURE.md`.

**Depends on**: nothing; consumed by Membership, Commerce, Content, Identity & Access.

## 12. Audit / Activity Logging

**Purpose**: a record of administrative and security-sensitive actions, independent of (and generic across) the domain-specific `MembershipStatusHistory` (which is a richer, business-specific audit trail scoped to one entity's lifecycle).

**Key entities**: `AuditLog` (actor, action, subject — see `12_AUDIT_LOGGING_ARCHITECTURE.md` for the one deliberate, scoped use of a polymorphic relation in this system).

**Replaces (legacy)**: `activity_logs` (which existed but, per `DATABASE.md`, was write-only from a privileged path with no application code observed actually writing to it — a gap this domain closes).

**Depends on**: every domain that performs an administrative or security-sensitive action.

## 13. Administration

**Purpose**: the admin panel shell, cross-domain admin dashboards (overview counts, monthly reports), and admin-only actions that don't belong to any single business domain (e.g. the reports aggregation view).

**Key entities**: none of its own — this domain is primarily a UI/authorization surface (the `/admin/*` route group, admin layout, admin navigation) composing views and queries from every other domain.

**Replaces (legacy)**: `admin.tsx` shell, `admin.index.tsx` overview, `admin.reports.tsx`.

**Depends on**: all domains (read access for dashboards/reports); Identity & Access (the `admin` role gate).

## Domain dependency diagram

```
Identity & Access ──┬──────────────────────────────────────────┐
                     │                                          │
                     ▼                                          ▼
              Membership ◀──── Promotions                 Commerce ◀── (future)
                     │                                          │
                     ▼                                          ▼
                 Payments ◀───────────────────────────────────┘
                     │
        ┌────────────┼─────────────────┬───────────────┐
        ▼            ▼                 ▼               ▼
 Notifications   Files/Documents   Audit Logging   Administration
        ▲                                 ▲
        │                                 │
    Content/CMS ── Community ─────────────┘
        ▲
        │
      Events, Jobs
```

*Per the Phase 2 restriction: conceptual only — no schema has been created.*
