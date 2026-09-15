# Legacy, Duplicate & Risk Register

Everything below is flagged per the project's Legacy Rule ("do not automatically reproduce legacy behaviour"), Conflict Rule ("identify the conflict, recommend a solution, don't silently follow the reference"), and Uncertainty Rule ("mark UNKNOWN, don't invent"). Nothing in this file has been fixed or implemented — it is a punch list for the LARAVEL ARCHITECTURE / DATABASE DESIGN phases to explicitly resolve.

> **Update (2026-09-15): Item 1 below is RESOLVED by a CONFIRMED ACI business requirement.** See WORKFLOWS.md §0 and DATABASE.md's "CONFIRMED Membership Data Model" for the full replacement workflow and conceptual data model. Several other items in this file that referenced the two-system membership design (payment handling, temp-password provisioning, the "more details" resubmission gap) are also directly addressed by that confirmed requirement — each affected item below is annotated accordingly rather than removed, so the reverse-engineering record stays complete.

## 1. CRITICAL — Two parallel, disconnected membership systems — ✅ RESOLVED 2026-09-15 by CONFIRMED ACI requirement

**Historical severity note (no longer current): this was flagged as the single most important defect to resolve before any Laravel architecture work begins — it has now been resolved by explicit ACI confirmation, recorded in WORKFLOWS.md §0 and DATABASE.md.** The description below is retained as the reverse-engineering record of what the reference app actually did and why it was a problem; it is not the target design.

- **System A — `memberships`** (DATABASE.md): tied to an existing logged-in `profiles` row, `plan_id` FK to `membership_plans`, proper `membership_status` enum (`pending/approved/rejected/suspended`), manual admin-set payment link/status. Created by `applyMembership`/`submitMembershipApplication` (apparently unused/dead — see §3 below). Managed by `admin.memberships.tsx`. Surfaced on `/dashboard/membership` and `/dashboard/billing`.
- **System B — `membership_applications`** (DATABASE.md, WORKFLOWS.md §1): public, no-login-required application form with document uploads, free-text status (`submitted/approved/rejected/more_details_requested`, not an enum), and a rich issuance subsystem (membership number, 1-year digital card, verification token, auto-provisioned login with forced password change). Created by `submitPreApplication` (the live, actually-used public apply flow). Managed by `admin.membership-applications.tsx`.
- **No FK connects them.** A member can have an active System-A membership with no System-B application (no membership number, no digital card), or a fully-approved System-B application with no System-A row (so `/dashboard/membership` shows "no active membership" despite the member having a real card and login).
- Independent status vocabularies, independent "plan" concepts (FK'd plan vs. free-text type), independent review workflows, independent admin screens.
- **Recommendation — DONE**: this was surfaced to ACI, who confirmed (2026-09-15) a single unified `Membership Application → Approved → Membership` entity chain for MySQL (see DATABASE.md's "CONFIRMED Membership Data Model" and WORKFLOWS.md §0), plus a new admin-configurable `membership_promotions` concept and an audit-trailed `membership_status_history` concept that did not exist in either legacy system. Card issuance and verification-token behaviour are carried forward conceptually (§0.13 "digital membership card", §0.15 "membership verification"); the legacy plan-based pricing/duration concept becomes the new `membership_categories`, exact naming/pricing still TBD (see WORKFLOWS.md §0 "Remaining decisions"). The legacy manually-typed `payment_link` mechanic is **not** carried forward — replaced by the five confirmed payment states in WORKFLOWS.md §0.9, and the legacy free-text `next_membership_number` scheme is replaced by the authoritative 9-character format in §0.11.

## 2. HIGH — Security gaps to close, not reproduce

| # | Issue | Where | Recommendation |
|---|---|---|---|
| 1 | `has_role` RPC is publicly callable by `anon` — anyone can learn whether an arbitrary user id is admin | DATABASE.md functions, AUTHORIZATION.md §3/§6 | Never expose an equivalent "is user X role Y" endpoint in Laravel to unauthenticated/arbitrary-target callers |
| 2 | `must_change_password` enforced only by a client-side route redirect, not server-side | AUTHORIZATION.md §2 | Real `users.must_change_password` column + server-side middleware on the whole authenticated group |
| 3 | Membership-approval "accept" silently resets an existing Auth account's password if the applicant's email matches one, with only a string-equality check — potential account takeover | AUTHORIZATION.md §6 #9, FEATURES.md §D | Explicit, deliberate design: block, require manual linking, or verify ownership before ever resetting an existing account's credentials |
| 4 | Plaintext temporary passwords sent by email on membership acceptance | NOTIFICATIONS.md, WORKFLOWS.md §1 (legacy) | **CONFIRMED requirement now explicitly forbids reproducing this** (WORKFLOWS.md §0.13: a secure one-time account-setup token/link, single-use, time-limited, invalidated after use) — exact implementation still to be finalized at ARCHITECTURE |
| 5 | No app-level rate limiting anywhere (login, sign-up, password reset, contact form, membership apply, shop checkout, verify/QR endpoints) | AUTHORIZATION.md §6, VALIDATION.md | Apply Laravel's `throttle` middleware broadly — a recommended hardening addition, not a silent port since it isn't present in reference |
| 6 | Membership application document uploads: unauthenticated, up to ~7MB × 6 files, no real content-type validation (trusts client-supplied `type` string) | STORAGE.md, AUTHORIZATION.md §6 #8 | Real multipart upload + server-side `mimes:`/magic-byte validation |
| 7 | No safeguard against an admin revoking their own admin role or the last remaining admin | AUTHORIZATION.md §6 #10, FEATURES.md §D | Add an explicit "would this leave zero admins" guard before allowing revoke |
| 8 | Rich text editor content (blog/news/job HTML fields) is never sanitized at write time — stored-XSS risk if a lower-trust actor ever gains admin, or if paste-handling permits dangerous markup | FEATURES.md §A (blog detail), admin CRUD notes | Server-side allow-list HTML sanitization (e.g. `mews/purifier`) before persisting |
| 9 | `has_role` EXECUTE grant flip-flopped (revoked, then re-granted 10 days later) across migrations — evidence of a production incident fixed by reversing a hardening change | DATABASE.md legacy signal #1 | Don't carry the "helper function needs public EXECUTE for RLS to work" fragility into Laravel — a PHP `hasRole()` Gate check has no equivalent trap |
| 10 | `shop_order`/`getShopOrder` confirmation page has no ownership/auth check — the order UUID alone is the access control, and it's permanent/unexpiring | WORKFLOWS.md §2, AUTHORIZATION.md pattern | Decide explicitly (flag for approval): keep unauthenticated-by-UUID for parity, or add owner/admin + short-lived signed URL for guests — a genuine security-posture decision, not something to infer |

## 3. Dead / duplicate code — confirmed, do not port

- **`src/integrations/supabase/auth-attacher.ts`** — auto-generated scaffold, superseded by the hand-written `src/lib/supabase-auth-attacher.ts` (the only one actually wired into `src/start.ts`). Confirmed dead by absence from any import elsewhere.
- **`src/lib/api/example.functions.ts`** — illustrative scaffold/demo code (`getGreeting`), no importers found anywhere in the codebase. Not a real feature.
- **`src/lib/config.server.ts`** — near-empty scaffold (only real content is `nodeEnv`); its only consumer is the also-dead `example.functions.ts`.
- **`applyMembership` / `submitMembershipApplication`** (in `membership.functions.ts`) — write to System A `memberships` and even create a Supabase Auth user directly, but are not referenced by any live UI route found in the reverse-engineering pass. Candidate dead/superseded code from an earlier design iteration; do not port unless ACI confirms the flow is still wanted (the live UI clearly uses `submitPreApplication`/System B instead).
- **`.lovable/plan.md`, `.lovable/project.json`** — Lovable-platform build metadata and a historical AI-generated delivery plan, not current application logic. Useful only as a cross-check of originally-planned tables (confirms `user_roles`/`has_role()` was a deliberate from-day-one design intent to keep roles out of `profiles`). Do not treat as ground truth for what was actually built.
- **`src/routes/README.md`** — a generic TanStack Start framework onboarding note, not app-specific documentation.
- **Storage policy drop-and-recreate for `blogs` bucket (M10)** largely duplicates policies already granted in M3 — redundant, not harmful, but makes the security model harder to audit; consolidate to one policy per bucket/command in the Laravel design.
- **Two nearly-identical bearer-token-attacher client middlewares** — see AUTHORIZATION.md §1.

## 4. Placeholder / unfinished content — do not port as if real

- **Privacy Policy and Terms & Conditions are 100% Lorem Ipsum**, including a hardcoded fake "last updated" date. Real legal copy must come from ACI/legal review before go-live; section headings are a reasonable structural starting point only.
- **`/membership/benefits` pricing is entirely hardcoded**, disconnected from the `membership_plans`/`membership_benefits` tables that exist and are actively used elsewhere (the FAQ page uses them; Benefits does not). An admin editing the CMS tables has zero effect on this page.
- **Sitemap is broken**: relative (non-absolute) URLs (`BASE_URL = ""` with a literal `// TODO` comment), duplicate `/jobs` entries (one per published job, all pointing at the same URL), and missing several real public pages.
- **About page stats** (`2,500+ Active Members` etc.) are placeholder numbers, not live counts.
- **"First 100 students free" launch offer** exists only as UI copy with no backing counter/flag/enforcement anywhere in code or schema.
- **Referral feature has no code/tracking/reward mechanism** — it is a plain "send an invite email" action with a non-attributable generic link, despite the feature being named "Refer a Friend" with an implication of tracking.
- **Dashboard "Promotions" page is not personalized** — it is the identical public `membership_benefits` content shown to anonymous visitors, with no per-member/per-plan logic.
- **E-shop has no payment gateway, no order-status lifecycle beyond a single default value, and no customer-facing confirmation email** — "payment arranged directly with the club" is the entire model; do not assume Stripe/PayPal or invent an order-status workflow not evidenced in the reference.

## 5. Data-model & consistency drift (non-security, but real gaps)

- **Enum vs. free-text status columns**: M1-era tables use proper Postgres enums; every table added afterward (`news_items`, `events`, `memberships.payment_status`, `membership_applications.status`/`membership_type`, `shop_products.visibility`, `shop_orders.status`) uses plain text, sometimes `CHECK`-constrained, sometimes not at all. Standardize in MySQL (PHP backed enum + VARCHAR recommended — see DATABASE.md).
- **Missing `WITH CHECK` on several UPDATE RLS policies** (`blog_comments`, `contact_enquiries`, `notifications`) — theoretically allows updating a row into a state the `USING` clause wouldn't have permitted (e.g. reassigning `user_id`). Not observed exploited, but close explicitly via Laravel Form Request/Policy field allow-listing.
- **`shop_orders.user_id` FKs to `auth.users` directly**, unlike every other user-referencing table (which FKs to `profiles`) — normalize to one target in the MySQL redesign.
- **Guest checkout appears schema-intended but is RLS/GRANT-blocked in practice** (`shop_orders.user_id` is nullable, but the INSERT grant only covers `authenticated`) — confirm with ACI whether guest checkout is wanted before deciding the Laravel access rule.
- **`shop_orders.user_id` is never populated even for logged-in customers** at checkout time, despite the column/RLS existing to support it — an apparent defect; recommend fixing (populate it) in Laravel and flag as a deliberate behaviour change from the reference.
- **Order + order-items insert is not transactional** — a failure between the two steps can leave an orphaned order header with no line items. Wrap in `DB::transaction()`.
- **Membership-application accept flow (RPC call + Auth API call + row update) is not transactional either** — see WORKFLOWS.md §1.
- **`site_settings` has no DB-level singleton constraint** and (uniquely among timestamped tables) **no `updated_at` trigger**; `home_sections.section_name` has no uniqueness constraint despite acting as a lookup key.
- **`email_templates` table exists but is entirely unused** — every email is a hardcoded TypeScript template string. Confirm with ACI whether admin-editable email templates are a real requirement before building the table into the new schema.
- **`site_settings.facebook_url`/`instagram_url`/etc. columns appear unused** — the Footer actually reads from the separate `social_links` table. Resolve the duplication (likely drop the `site_settings` columns) during Database Design.
- **Two redundant/inconsistent password-change UIs** on the member side, one of which never clears the `must_change_password` flag — consolidate to one canonical flow.
- **Dashboard "unread notifications" count only checks the 5 most-recently-fetched notifications**, not a true total — a display bug.
- **Signed-URL TTLs for storage objects are inconsistent** (1 hour in most places, 7 days in one blog-listing code path) with no evident reason — standardize.
- **Professional membership tier labels submitted at apply-time don't match the tier names shown on the Benefits marketing page** — reconcile naming with ACI. Superseded in part by the CONFIRMED requirement that there will be exactly **three** membership categories overall (WORKFLOWS.md §0.1) — final category names/pricing remain an open decision, not to be inferred from either legacy naming scheme.
- **Admin `published_at` stamping only happens on create**, not on a later draft→published transition, across blog/news/events/jobs — a bug, fix in Laravel rather than reproduce.
- **`profiles.aviation_role` (a free-text profession field) sits next to the `user_roles`-driven "Admin" badge under a shared "Role" column label in the Users admin screen** — a naming collision to avoid in Laravel naming/UI.
- **No delete-user action exists anywhere** in the admin panel (only suspend/activate) — confirm whether Laravel needs one, or whether "soft-disable only" is the intended permanent policy.

## 6. Hardcoded / one-off operational data shipped as permanent migrations

- Two migrations grant the `admin` role to a specific hardcoded email address and a specific hardcoded UUID respectively — personal, environment-specific bootstrap actions committed as replayable schema migrations. **Do not replicate this pattern.** Laravel should seed its first admin via an environment-guarded seeder or a manual `php artisan` promote-to-admin command, never a permanent migration.
- `next_membership_number` was revised three times within roughly half an hour on the same day (a no-arg version created and abandoned in favor of a category-letter version) — evidence of live, iterative design rather than planning. **This has now been re-specified cleanly and made authoritative by ACI**: see WORKFLOWS.md §0.11 for the exact 9-character `C+YY+RR+SSSS` format, per-category sequencing, and the strict rules on when a number may/may not be generated (never at submission, never for rejected/payment-pending applications) — do not copy the legacy RPC's implementation, only its general shape (which matches).
- Redundant, repeated `REVOKE`/`GRANT` statements on the same two functions across three separate migrations — suggests the team was managing security grants by hand without a single source of truth. Laravel's Gate/Policy layer avoids this class of problem structurally (authorization isn't expressed as revocable DB function grants).

## 7. Architectural/UX inconsistencies flagged as design questions (not bugs to silently fix or silently copy)

- Content editor inconsistency across admin CMS entities: some use RichTextEditor (HTML), others plain Textarea, for conceptually similar "long content" fields (see FEATURES.md §D table). Standardize deliberately.
- Image handling inconsistency: some entities use the crop-and-upload component, others take a raw external URL text field, for conceptually similar "photo" fields.
- Public data-fetching inconsistency: most public reads use the service-role client (bypasses RLS, relies on hand-written filters); News/Events uniquely uses an anon-key client subject to RLS. Neither is "correct" vs. the other for Laravel's purposes (Laravel has no RLS), but the inconsistency itself signals no deliberate policy was ever set — establish one.
- Job board filtering is implemented server-side in the function signature but never actually invoked from the UI (client-side-only filtering of the full dataset instead) — an abandoned server capability.
- News/Blog are structurally near-duplicate content types; Events and Blog render "content" differently (HTML vs. plain text) with no apparent deliberate reason found.

## 8. Explicit UNKNOWNs carried forward from all reverse-engineering passes

Do not resolve these by inference — confirm with the reference project owner / ACI stakeholders before DATABASE DESIGN / ARCHITECTURE decisions depend on them:

- Whether Supabase's project-level "Confirm email before sign-in" setting is enabled (affects whether the immediate-login-after-signup UX is even consistent with current production behaviour).
- Full canonical value sets for several status-like columns that have no CHECK constraint (`membership_applications.status` beyond `submitted`/`approved`; `job_applications.status` beyond `applied`/`reviewed`; `comment_status` beyond `pending`/`approved`).
- Whether `memberships.payment_status`/`payment_link` have more values than the two (`paid`/`unpaid`) referenced in UI conditionals.
- Whether a scheduled job sends renewal/expiry-reminder notifications ahead of a membership card's `expires_on` — not found in any file reviewed.
- Whether client code retries on a `membership_number` unique-constraint collision from the randomized-component numbering scheme.
- Exact Supabase Storage bucket configuration (public/private flags, size/mime limits) — no bucket-creation DDL exists anywhere in the migrations.
- Whether `profiles.is_active = false` (suspended) actually blocks authentication at login.
- Whether any order-management (status/refund) admin capability exists somewhere outside the files reviewed for this pass.
- Whether the reference app has **any** automated test suite (no test script, no test files found) — the Laravel rebuild will need to build feature-test coverage from scratch, with no reference tests to cross-check against.

*Per the Approval Gate: this is the final document of the REVERSE ENGINEERING phase. Wait for explicit approval before moving to LARAVEL ARCHITECTURE.*
