# 05 — Identity, Access & Authorization Architecture

Replaces every mechanism documented in `docs/reverse-engineering/AUTHORIZATION.md` with Laravel-native equivalents, and closes every weakness that document and `LEGACY_RISKS.md` flagged. The governing principle, restated from the Phase 2 instructions: **authorization must be explicit and server-side; never rely solely on frontend restrictions.**

## 1. Users, roles, and authentication

**ARCHITECTURAL DECISION**: merge the legacy's split `auth.users` (Supabase Auth) + `profiles` (application data) into **one** Laravel `User` model/table — Laravel's own auth system has no anon/service-role key split to manage (`SUPABASE.md`), so there is no structural reason to keep identity and profile data in two tables the way Supabase's architecture forced.

- Two flat roles, matching the confirmed, deliberately-simple legacy model (`AUTHORIZATION.md` §3: "two flat roles, no hierarchy... every admin check ultimately funnels through this one function") — a single `role` enum column (`member`/`admin`) on `User`, not a roles/permissions package. **Alternative considered**: Spatie `laravel-permission` — rejected for now as unnecessary complexity for exactly two flat roles with no per-resource permission variation confirmed as needed; revisit only if ACI later confirms a need for finer-grained admin permissions (e.g. "blog editor" vs. "membership approver," which `AUTHORIZATION.md` §4 notes the legacy app never had either). Recorded as a scalable-but-not-yet-needed option in `16_OPEN_DECISIONS.md`.
- Authentication: Laravel's native session-based web auth (`Auth::attempt()`, `Auth::logout()`), Laravel's built-in `throttle` middleware on login/registration/password-reset (CONFIRMED gap fix — the legacy had **no** rate limiting anywhere on these endpoints, `AUTHORIZATION.md` §6 item 6). Email verification (`MustVerifyEmail` + `verified` middleware) — whether ACI wants self-registration/email verification at all is largely moot for Membership, since accounts are provisioned only through the confirmed activation flow (`04_MEMBERSHIP_ARCHITECTURE.md` §7), not open self-registration; recorded as `TBC` if any open self-registration surface is later added (`16_OPEN_DECISIONS.md`).

### 1a. "Profiles" — folded into `User`, not a separate concept

The Phase 2 instructions list "profiles" as its own item alongside users/roles. **CONFIRMED via ADR-04** (`15_ARCHITECTURAL_DECISIONS.md`): this architecture does not create a separate `Profile` model — the legacy's `profiles` table existed only because Supabase Auth's own `auth.users` table was off-limits to applications joins/extension, forcing a 1:1 companion table for every application-owned field (name, country, aviation role, phone, occupation, company, LinkedIn, bio, avatar). Laravel's `User` model has no such restriction, so all of those fields live directly on `User`. "Profile data" is therefore not a distinct architectural concept in this design — it is simply the non-authentication columns of `User` — though the **member-facing "Edit Profile" screen** (`FEATURES.md` §C) remains a distinct Livewire component/UI concern, it just edits fields on the one `User` model rather than a second table. Field-level validation gaps the legacy had here (`VALIDATION.md`: "essentially unvalidated free-text profile fields," no `url` rule on the LinkedIn field, no enforced avatar size limit) are closed via a proper Form Request, per `14_ERROR_VALIDATION_ARCHITECTURE.md` §2.

### 1b. "Permissions" — covered by the role model, not a separate permission table (for now)

The Phase 2 instructions also list "permissions" and "role assignment" explicitly. Per §1's ADR-05 decision, ACI's confirmed model is **exactly two flat roles with no per-resource permission variation** (`AUTHORIZATION.md` §3–§4: "no hierarchy, no per-resource permissions/abilities — coarse admin/member only" was true of the legacy app and nothing in the confirmed requirements changes this). "Role assignment" is therefore simply setting `User.role`, changeable only by an admin via `UserPolicy::updateRole()` (§2) — there is no separate `permissions`/`role_user` pivot table in this design, because there is nothing yet for such a table to express beyond the single boolean "is this user an admin." If ACI later confirms a need for finer-grained permissions, the upgrade path (Spatie `laravel-permission`, or a hand-rolled `permissions`/`role_has_permissions` pair) is additive — it would not require restructuring anything else in this document, since every current authorization check already goes through named Gate/Policy methods (`canReviewMembershipApplications()`-style checks) rather than inline `if ($user->role === 'admin')` scattered through the codebase, which is exactly what makes that future migration low-risk. Recorded in `16_OPEN_DECISIONS.md`.

## 2. Gates & Policies

**ARCHITECTURAL DECISION**: a single `Gate::before` callback grants `admin`-role users blanket access (mirroring the legacy's correctly-designed "admin = has the role, full access, no finer permission" model, `AUTHORIZATION.md` §4), plus one Eloquent **Policy per model** that needs an ownership or visibility check beyond the blanket admin bypass:

| Policy | Enforces |
|---|---|
| `MembershipApplicationPolicy` | `review()` (Approve/Reject/Request-More-Details) — admin only; `view()` — the owning applicant (once linked to a `User`) or admin |
| `MembershipPolicy` | `view()` — the owning member or admin |
| `PaymentPolicy` | `confirm()`/`reject()` — admin only; `view()` — the paying user or admin |
| `AviationProofDocumentPolicy`, `PaymentEvidencePolicy` | `view()`/`download()` — the owning applicant/member or admin (§`07_FILE_STORAGE_ARCHITECTURE.md` §2) |
| `BlogCommentPolicy` | `update()`/`delete()` — the comment's own author or admin, with the update Form Request additionally whitelisting only the editable field (closes the legacy's flagged missing-`WITH CHECK` gap, `DATABASE.md` legacy signal #8) |
| `OrderPolicy` (Commerce, future) | `view()` — the owning customer or admin; **never** "anyone who knows the order UUID," directly correcting the legacy's unauthenticated-by-UUID shop-order-confirmation gap (`LEGACY_RISKS.md` §2 item 10) — exact guest-checkout confirmation-page access rule is `TBC`, `09_ECOMMERCE_ARCHITECTURE.md` §6 |
| `UserPolicy` | `updateRole()`/`suspend()` — admin only, **plus a "would this leave zero admins" guard** on role revocation and a self-revoke guard (closes `AUTHORIZATION.md` §6 item 10) |

- **Laravel mechanism**: standard `php artisan make:policy`, registered via auto-discovery or `AuthServiceProvider`; every Controller/Livewire action that touches a model calls `$this->authorize(...)` (Controllers) or `Gate::authorize(...)`/`$this->authorize(...)` (Livewire) **before** performing the action — never after, and never only in the Blade view (which would be the same client-trusting mistake the legacy app made with its `AdminLayout`/`beforeLoad` client-only gates, `AUTHORIZATION.md` §4).
- **IDOR protection**: Laravel's route-model binding combines with Policy checks so that a URL like `/dashboard/applications/{application}` authorizes against the *actual* bound model instance, not just "is this a valid application ID" — closes every "any authenticated user can fetch/mutate another user's row by guessing an ID" class of gap the reverse-engineering pass looked for (`AUTHORIZATION.md` §4 "Member dashboard" test requirement).

## 3. Never expose "does user X have role Y"

**CONFIRMED FIX** (`AUTHORIZATION.md` §3, §6 item 1): the legacy's publicly-callable `has_role` RPC (anyone could learn whether an arbitrary user id is admin) is **not reproduced in any form** — there is no Laravel route, Livewire method, or API endpoint that answers "is this user an admin" for an arbitrary target user. Client-side UI decisions (e.g. showing an "Admin Panel" nav link) check **only the current authenticated user's own** role (`auth()->user()->role`), never another user's, and even that check is UX-only — the actual `/admin/*` routes are gated by the server-side Gate regardless of what the nav link shows.

## 4. Secure account setup (cross-reference)

Fully specified in `04_MEMBERSHIP_ARCHITECTURE.md` §7 — a purpose-built, single-use, time-limited setup token, never a system-generated password emailed in plaintext. This closes `AUTHORIZATION.md` §6 items 2, 4, 7, 9 simultaneously (the `must_change_password` enforcement gap, the plaintext-password transmission, the re-approval re-issuance question, and the account-takeover-on-email-match risk) because the mechanism they all revolved around — a system-assigned password — no longer exists.

## 5. Rate limiting

**ARCHITECTURAL DECISION**: Laravel's `throttle` middleware applied to: login, registration (if any self-registration surface exists), password reset requests, the membership application submission endpoint (public, unauthenticated — the legacy had none, `AUTHORIZATION.md` §6 item 6, `VALIDATION.md`), the contact form, and (if/when built) the public membership-verification endpoint (`04_MEMBERSHIP_ARCHITECTURE.md` §10). Rate limits are configuration values, tuned during implementation, not fixed in this document.

## 6. CSRF

Laravel's default CSRF protection (session-based token on every state-changing form) applies to every web route by default — the legacy app had no CSRF-equivalent concern in the same way (its mutations went through bearer-token-authenticated server functions), but Laravel's own default is strictly appropriate here and requires no special design beyond excluding genuine webhook endpoints (`08_PAYMENT_ARCHITECTURE.md` §4), which is standard practice.

## 7. Secure file uploads & private document storage

Cross-referenced in full in `07_FILE_STORAGE_ARCHITECTURE.md` §§1–3 — content-validated uploads, Policy-gated private access, no client-direct-to-storage path.

## 8. Safe admin operations

- **Destructive/high-impact admin actions get an explicit safeguard**, not just the blanket admin Gate: revoking the last admin or self-revoking (§2 `UserPolicy`), and any future "irreversible" action (e.g. permanently deleting a membership application) should require a confirmation step server-side (not just a JS `confirm()` dialog, which the legacy relied on for delete actions, `FEATURES.md` §D — a client-side confirm is fine as UX, but it is never the only check).
- **Payment confirmation/rejection is always an explicit admin action** (never automatic beyond a verified gateway webhook) — see `08_PAYMENT_ARCHITECTURE.md` §5.

## 9. Password handling

Laravel's default `Hash::make()` (bcrypt/argon2, whichever Laravel 12 defaults to) for every stored password — no custom hashing, no legacy carry-over (the legacy's Supabase-managed password hashing is entirely superseded since Supabase Auth itself is not part of this architecture).

## 10. Summary — legacy weaknesses closed

| `AUTHORIZATION.md` / `LEGACY_RISKS.md` finding | Closed by |
|---|---|
| Publicly-callable `has_role` RPC | No equivalent endpoint exists at all (§3) |
| `must_change_password` client-only gate | No system-assigned password to begin with (§4) |
| Plaintext temp password by email | Secure setup token (§4) |
| Account-takeover on email-string match | Setup-token-based linking, not a silent password reset (`04_MEMBERSHIP_ARCHITECTURE.md` §8) |
| No rate limiting anywhere | `throttle` middleware on every sensitive endpoint (§5) |
| No safeguard against revoking last admin | `UserPolicy` guard (§2, §8) |
| Unauthenticated-by-UUID order confirmation | `OrderPolicy` (§2) |
| Client-side-only admin route gate | Server-side Gate on every `/admin/*` route (§2) |
| No server-side MIME validation on uploads | Content-inspecting validation rules (`07_FILE_STORAGE_ARCHITECTURE.md` §3) |

*Per the Phase 2 restriction: conceptual design only. No Gates, Policies, middleware, or auth code exist yet.*
