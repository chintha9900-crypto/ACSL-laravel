# 10 — Content / CMS Architecture

Covers the Content/CMS domain from `02_DOMAIN_ARCHITECTURE.md` §6 and the Events domain (§7) content side — pages, blog, news, events, comments, FAQs, testimonials, team, hero banners, social/footer links, SEO, and site settings. Jobs and Community (referrals, partners, advisory members) are covered separately in `17_JOBS_AND_COMMUNITY_ARCHITECTURE.md`, per the Phase 2 domain split.

## 1. Content entities & the CrudManager pattern

**Entities**: `Page` (see §6 — new, not a legacy carry-over), `BlogPost`, `BlogCategory`, `BlogTag`, `BlogComment`, `NewsItem`, `EventListing` (+ optional `EventRegistration`, see §7), `Faq`, `Testimonial`, `TeamMember`, `HeroBanner`, `SiteSetting`, `SeoPage`, `SocialLink`, `FooterLink`.

**ARCHITECTURAL DECISION**: reproduce the legacy app's own reasonably good split (`FEATURES.md` §D) between a **generic, config-driven admin CRUD component** for uniform, simple entities and **bespoke** admin components for entities with real per-entity logic:

| Uses the generic pattern | Uses a bespoke component |
|---|---|
| `Faq`, `Testimonial`, `TeamMember`, `HeroBanner` | `BlogPost`, `NewsItem`, `EventListing` (rich text, image upload, publish workflow), `Page` (rich text, no image), `SiteSetting` (singleton, not a list) |

The generic component (one reusable Livewire component, config-driven per entity — see `03_LARAVEL_ARCHITECTURE.md` §3) is the **one** place a small generic abstraction is justified, because the legacy `CrudManager` pattern already proved these four entities are genuinely uniform (id, display field, `display_order`, `is_active`, no per-entity business logic). This is not "a generic service layer for every CRUD operation" (which the Phase 2 instructions warn against) — it is a single, narrow, UI-level abstraction for entities with literally the same shape, with validation still enforced per-entity via a real Form Request (fixing the legacy's flagged validation gap, `VALIDATION.md`: "no server-side validation at all" for these four entities).

## 2. Fixing specific legacy gaps (CONFIRMED via `LEGACY_RISKS.md`, not new invention)

- **`SiteSetting` becomes a true singleton**: `ARCHITECTURAL DECISION` — fixed primary key (`id = 1`), all reads/writes go through a single `SiteSetting::current()` accessor that does `firstOrCreate(['id' => 1])`, removing the legacy's application-logic-only singleton with no DB guarantee (`FEATURES.md` §D, `DATABASE.md`).
- **Redundant social-link storage removed**: `SiteSetting` does not carry `facebook_url`/`instagram_url`/etc. columns — `SocialLink` is the single source of truth (resolves the duplication flagged in `FEATURES.md` §A "Root shell").
- **`published_at` stamping fixed**: an Action-level rule (not per-controller ad hoc code) stamps `published_at` on **any** transition into `published` status, not only on create — fixes the legacy bug where republishing an edited post never restamped it (`FEATURES.md` §D).
- **Slug uniqueness** is a real `UNIQUE` database constraint plus a friendly Form Request validation message, on every sluggable entity (blog, news, events, shop products) — the legacy relied on the DB constraint alone with no friendly error (`VALIDATION.md`).

## 3. News vs. Blog — not silently merged

`FEATURES.md` §D raised whether News and Blog should unify into one "articles" concept. **This is not a confirmed requirement** — this architecture keeps them as **separate** entities (matching the legacy structure) to avoid inventing a data-model merge ACI hasn't asked for. If ACI later confirms they want one unified concept, that is a straightforward follow-up change (a `type` discriminator column) rather than something this design should pre-empt. Recorded in `16_OPEN_DECISIONS.md`.

## 4. Event content rendering — not silently resolved

The legacy app rendered blog/news content as HTML but event content as plain text, with no evidence this was deliberate (`FEATURES.md` §A). **Not resolved here** — recorded as `TBC` in `16_OPEN_DECISIONS.md` rather than guessed. Whichever is chosen, this document's rich-text handling rule (§5) applies the same sanitization regardless of which content types end up using rich text.

## 5. Rich text — safe handling (CONFIRMED REQUIREMENT: "safe rich-text handling")

**What is being proposed**: every HTML-bearing content field (`BlogPost.content`, `Page.content`, and any other field later given a rich-text editor) is passed through a server-side HTML sanitizer (allow-list based) on **save**, not merely trusted because "only admins can author it."

- **Why appropriate for ACI**: `LEGACY_RISKS.md` §2 item 8 and `FEATURES.md` §A flag this exact gap — the legacy app never sanitized rich-text content at write or read time, a real stored-XSS risk if a lower-trust actor ever gains admin access or if the editor's paste-handling permits dangerous markup.
- **Laravel mechanism**: a small, centrally-defined sanitization step (e.g. an allow-list HTML purifier package — see `13_INTEGRATION_ARCHITECTURE.md` for the specific package choice) invoked from the relevant Form Requests' `passedValidation()` hook or a shared trait, so every rich-text field goes through the same one code path rather than each admin controller reimplementing it.
- **Security considerations**: allow-list (not block-list) sanitization — strips anything not explicitly permitted (basic formatting tags, no `<script>`, no inline event-handler attributes, no `javascript:` URLs).
- **Alternatives considered**: sanitizing only at render time (Blade output escaping) — rejected as the sole measure, because the raw unsanitized HTML would still be stored and could be exposed via any future code path that doesn't apply the same escaping (e.g. an API export, an email digest); sanitizing at write time makes the stored data itself safe, which is the more robust default.

## 6. `Page` — a new content type, not present in the legacy app

**ASSUMPTION, flagged for confirmation**: the Phase 2 instructions list "pages" as a content area to design for, but no such generic CMS "page" entity exists in the reverse-engineering record — the legacy app's About/Privacy/Terms/Rules content was **hard-coded** in React components (`FEATURES.md` §A), not admin-editable rows. This document proposes a minimal `Page` entity (slug, title, rich-text content, SEO fields via `SeoPage`) so ACI *can* make such content admin-editable if wanted, reusing the same bespoke-CRUD-with-sanitized-rich-text pattern as `BlogPost`. **This is new scope, not a restatement of a confirmed requirement** — whether ACI actually wants Privacy/Terms/About/etc. converted from static Blade views into `Page` rows (vs. keeping them as static, developer-maintained views, which is simpler and arguably more appropriate for legal text that shouldn't be casually admin-edited) is recorded as `TBC` in `16_OPEN_DECISIONS.md`.

## 7. Event registration — new capability, not present in the legacy app

**ASSUMPTION, flagged for confirmation**: the legacy app has `EventListing` content only (no registration mechanism at all — `DATABASE.md`, `FEATURES.md` §A). The Phase 2 instructions ask this architecture to evaluate "event registration" as a content area. No business rules for it (who can register, capacity limits, waitlisting, payment-for-events, confirmation emails, cancellation) exist anywhere in the reverse-engineering record, so **none are invented here**. This document reserves the conceptual shape only: an optional `EventRegistration` entity (belongs to `EventListing` and a `User` or guest contact details, a status field, a registered-at timestamp) that can be designed properly once ACI confirms the actual rules — deliberately not fleshed out further, per "never silently invent a business rule." Recorded in `16_OPEN_DECISIONS.md`.

## 8. Blog comments (moved here per the Phase 2 domain grouping)

Comment moderation default (`pending` vs. legacy's actual `approved`-on-insert behaviour) is **not confirmed** — architecture supports either via one config value (`config('content.comment_moderation_default')`), not silently decided. Regardless of the default, comment authorization follows the confirmed-good legacy pattern: a comment's `update()`/`delete()` Policy checks both ownership (`auth.id() === comment.user_id`) **and** the field being changed (an update Form Request explicitly whitelists only the `comment` text field, closing the legacy's flagged latent gap where a missing `WITH CHECK` could theoretically let a user reassign `user_id`, `DATABASE.md` legacy signal #8). Recorded in `16_OPEN_DECISIONS.md`.

## 9. Authorization summary for this domain

All content admin actions require the `admin` role (`05_AUTHORIZATION_ARCHITECTURE.md`); public read access has no auth requirement but every query enforces the "published/active only" visibility rule via a **global Eloquent scope** per model (`PublishedScope`, `ActiveScope`) rather than relying on each controller to remember to filter — this is the direct Laravel-side answer to `DATABASE.md`'s MySQL Migration Considerations note that RLS's "always applies" guarantee has no automatic Eloquent equivalent and must be deliberately designed, not assumed.

*Per the Phase 2 restriction: conceptual design only. No Content models, controllers, or Livewire components exist yet.*
