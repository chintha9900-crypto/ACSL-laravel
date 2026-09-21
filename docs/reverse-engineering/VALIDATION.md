# Validation

Every form in the reference app, its client-side checks, and its server-side validation (Zod where used; several endpoints are hand-rolled with no schema library at all — noted explicitly). Laravel replacement is always a Form Request mirroring the **server** rules below (client-side rules are UX only and must be re-verified server-side regardless of what the reference does).

## Public forms

### Contact form (`/contact`)
SOURCE: `src/routes/contact.tsx`, server validator `src/lib/site.functions.ts:78-88` (hand-rolled, not Zod).
| Field | Client | Server |
|---|---|---|
| name | required, maxLength 150 | trimmed, required, max 150 (throws if over-length too) |
| email | `type=email`, maxLength 255 | trimmed, lowercased, regex `^[^\s@]+@[^\s@]+\.[^\s@]+$` (not RFC-strict) |
| phone | `react-phone-number-input`, `isValidPhoneNumber` (real per-country check), marked required via manual JS check (no native `required` attr) | regex `^\+[1-9]\d{6,19}$` (**shape only** — looser/different than the client's real validity check; client and server disagree) |
| subject | maxLength 255, optional | trimmed, truncated to 255, optional |
| message | required, rows 6, maxLength 5000 | trimmed, required, max 5000 |

No CAPTCHA/rate limiting on this fully public write endpoint — flag as a hardening recommendation for Laravel (not a silent port, since it isn't present in reference).

### Blog comment (`/blog/$slug`)
SOURCE: `src/routes/blog.$slug.tsx:136-170`, server `src/lib/blog.functions.ts:76-84`.
- Client: `required`, `minLength=2`, `maxLength=2000`, submit disabled while trimmed length < 2.
- Server: `blogId` required (throws "Missing post."); comment trimmed, length 2-2000 ("Comment must be 2-2000 characters."). Requires `requireSupabaseAuth` (authenticated only).
- **Comments are inserted with `status:"approved"` unconditionally** — no moderation gate is actually applied despite the `comment_status` enum supporting `pending`. Flag as a legacy/business-rule decision for ACI (moderate-by-default vs auto-approve), not to be silently ported either way.

### Membership pre-application (`/membership/apply`) — 3 forms, shared Zod schema server-side — legacy behaviour, target workflow now CONFIRMED
SOURCE: `src/routes/membership.apply.tsx`, server `preApplicationSchema` in `src/lib/membership.functions.ts:115-129` (Zod — the only public form using a real schema library).

> **CONFIRMED ACI requirement (final, see WORKFLOWS.md §0.1): every applicant, in all three membership categories (Student=`S`, Professional=`P`, Veteran=`V`), must provide proof/documentation of legitimate aviation participation** — application submission itself is not valid without it (WORKFLOWS.md §0.2). The legacy rules below show the Professional form requiring **no file upload at all** — that is a gap to close, not reproduce. The rebuilt equivalent of the Professional category must add a required document-upload step matching Student/Veteran's pattern. Exact acceptable proof types/formats and final commercial category names/pricing are still open (WORKFLOWS.md §0 "Remaining decisions") — do not assume `student|professional|veteran` survive unchanged as commercial labels (the one-letter codes S/P/V are confirmed regardless of final display names). Separately, the confirmed paid-membership path (§0.8/§0.9) introduces a **new** validation surface not present in the legacy app at all: payment reference + payment evidence document submission, needing the same class of file-type/size/authorization validation as aviation proof.

Server schema (applies to all 3 types):
```
membership_type: enum(student|professional|veteran)
full_name: string, 1-160, trimmed
email: valid email, ≤200
mobile: optional string ≤40
address: optional string ≤400
form_data: record<string, any>   — NOT individually validated server-side (see gap below)
documents: array, max 6, each { name ≤200, type? ≤120, data ≤10,000,000 chars (~7MB base64) }
```

**Gap**: `form_data`'s type-specific sub-fields (course_name, plan selection, years_experience, etc.) are validated **client-side only** — a direct API call could submit malformed/missing sub-fields with no server rejection. Laravel replacement should validate these explicitly (recommended improvement, flagged per the Conflict Rule rather than silently reproducing the gap).

Client-side per-type field rules (all maxLengths are also the de-facto server contract via `form_data`'s free-form nature, but only enforced in the browser today):
- **Student**: full_name required ≤160, address required ≤400 (textarea), phone required (manual check), email required ≤200, course_name required ≤160, training_institute required ≤160, course_start_date required (date), expected_completion_date required (date), ≥1 file required (≤5MB each client-side, `image/*,application/pdf`).
- **Professional**: full_name/email/phone/address as above, occupation required ≤160, employer required ≤200, plan required (`Basic|Intermediate|Inner Circle` — **does not match** the Benefits page's tier names `Core/Premier/Inner-Circle`, flag for ACI to reconcile). **No file upload in the legacy app — CONFIRMED requirement now mandates one** (see note above); the Laravel Form Request for this category must add a required aviation-proof document field it did not have before.
- **Veteran**: full_name/email/phone/address as above, previous_employers required ≤400 (textarea), position_held required ≤160, years_experience required number 0-80, ≥1 file required (same mechanism as Student).

Duplicate check (client pre-check + independent server re-check, both against `membership_applications` by email ILIKE / mobile exact match) blocks submission before any DB write — enforced twice (UX + integrity), a good pattern to keep in principle. **Legacy gap — RESOLVED by CONFIRMED requirement**: the legacy check is not status-aware, so a previously-rejected applicant could never reapply with the same email/mobile at all. WORKFLOWS.md §0.14 confirms reapplication is allowed at any time with **no cooldown**, with every historical application retained rather than overwritten — the rebuilt duplicate/eligibility check must be **status-aware** (block only while an application is still open, or when the person already has a membership and should renew; never because of a past rejection) rather than a permanent block.

### Referral invite (`/dashboard/refer`)
SOURCE: `src/lib/referral.functions.ts:5-11` (Zod).
```
emails: array of valid emails, max 255 chars each, min 1 - max 20 items (UI only ever sends 1)
origin: optional URL, max 300 chars
```
De-duplicated case-insensitively per call; no server-side rate limiting across calls (a member could resend to the same address repeatedly).

## Member dashboard forms

### Profile update (`/dashboard/profile`)
SOURCE: `src/lib/dashboard.functions.ts:46-97`.
- **No Zod schema at all** — `inputValidator` is the identity function; only an allow-list filter (`first_name, last_name, country, aviation_role, other_role, phone, occupation, company, linkedin_profile, bio, avatar_url`) is applied server-side, with no length/format validation on any field (e.g. `linkedin_profile` is never checked as a URL despite the label). **This is a real gap to fix in Laravel**, not reproduce — add explicit per-field rules (max lengths, `url` rule for LinkedIn, phone format).
- Avatar upload: UI claims "max 2MB" but **nothing enforces this**, client or server (see STORAGE.md).

### Change password (two separate, inconsistent forms — see LEGACY_RISKS.md)
SOURCE: `dashboard.password.tsx` (dedicated page), `dashboard.profile.tsx:95-101,177-186` (duplicate inline form).
- Dedicated page: client-only `pw.length < 8` + confirm-match check; no current-password re-verification before allowing a change (a session-hijack risk — an attacker with a live session could change the password without knowing the old one).
- Profile page's duplicate form: client-only `pw.length < 8`, no confirmation field at all, and — critically — does **not** clear the `must_change_password` flag (bug).
- No server-side password policy beyond whatever Supabase Auth enforces by default.

## Admin forms

### Generic CrudManager entities (FAQs, Hero Banners, Team, Testimonials)
SOURCE: `src/components/admin/CrudManager.tsx`, `makeCrud()` factory in `admin.functions.ts:382-417`.
**No server-side validation at all** — `makeCrud` copies any allow-listed key present in the payload with zero required/length/type checks; the generic `CrudForm` doesn't even set HTML `required` attributes. All 4 entities can currently be saved with empty required text (e.g. an FAQ with a blank question). **Flag as a gap to fix in Laravel** (per-model Form Requests with real required/length rules), not a pattern to preserve.

### Blog post / News / Job / Event (bespoke forms)
Client: `required` (HTML5) on Title/Slug (blog, news, events) or Title/Company (jobs) only — no other client validation.
Server (`adminUpsertBlogPost`/`adminUpsertJob`/`newsCrud`/`eventsCrud`): allow-list only, falsy→null coercion, and a **create-only** check ("Title and slug are required.") — **an update can null out a previously-required field** since the same check doesn't run on update. No slug-uniqueness check observed in application code (relies on the DB `UNIQUE` constraint alone, which produces a raw DB error rather than a friendly validation message). `status` values (`draft|published|archived` etc.) are **not allow-list-checked server-side** — the client `<Select>` is the only real constraint.

### Shop product (`admin.shop.tsx`)
Server: `name` required; `price`/`display_order` coerced via `Number(...)` with **no NaN guard** (`Number("abc")` → `NaN` would be written to the DB uncaught); `visibility` safely forced to `public` unless exactly `members`; `slug` auto-generated from name if blank, with a `product-${Date.now()}` fallback for all-symbol names. Laravel: `price: required|numeric|min:0`, `visibility: in:public,members`, unique slug validation.

### Site settings (`admin.settings.tsx`)
**No server-side validation at all** — no email-format check on `contact_email`, no URL-format check on any of the six `*_url` fields. Add `email`/`url` validation rules in Laravel (fix vs. reference gap).

### Membership review action (`admin.membership-applications.tsx`)
`request_details` requires non-empty `message` (only real required-field check in this whole file, "Please list the details or documents required."); `accept`/`decline` have no additional input beyond the target application id. See WORKFLOWS.md for the full accept-path side effects.

### Memberships / payment link (`admin.memberships.tsx`)
`payment_link` truncated to 1000 chars server-side; `payment_amount` coerced to `Number` or `null`; **no URL-format validation server-side** (client `type="url"`+`required` only); `status` not allow-list-checked server-side (5 values freely settable via the underlying function, client `<Select>` is the only real constraint — no workflow guard preventing e.g. jumping straight from `pending` to `expired`).

### User role/status (`admin.users.tsx`)
`role` coerced to only `"admin"` or `"member"` — any other value **silently becomes `"member"`** rather than erroring (a foot-gun: a typo in a role name silently downgrades intent rather than failing loud). No validation guards the "last admin"/self-revoke cases — see AUTHORIZATION.md §6.

## E-Shop checkout (`/shop/checkout`)
SOURCE: `src/lib/shop.functions.ts:13-29` — **hand-rolled, not Zod** (no zod import anywhere in the shop feature).
| Field | Rule |
|---|---|
| name | trimmed, required, max 150 |
| email | trimmed, lowercased, regex `/^[^\s@]+@[^\s@]+\.[^\s@]+$/` (simple shape check) |
| phone | trimmed, truncated to 40, optional, no format check |
| address | trimmed, truncated to 1000, **required** |
| notes | trimmed, truncated to 1000, optional |
| items | each `{id, qty}`; `qty` coerced/clamped to 1-99 (NaN→1); empty/falsy ids dropped; empty resulting array → "Your cart is empty." |

Server re-derives price/name from the current `shop_products` row for every submitted id — client-submitted price is never trusted (there isn't one to trust; the client never sends price at all).

## Cross-cutting validation gaps to fix in Laravel (not reproduce)

1. Several server allow-list handlers (`makeCrud`, profile update, blog/news/job/event upsert) have **zero required-field enforcement on update**, only sometimes on create.
2. `status`-like fields are almost never allow-list-checked server-side across the whole admin panel (comments, enquiries, job applications, memberships) — the client `<Select>` is the only real constraint today.
3. Email/phone validation is inconsistent between forms (contact form uses one regex, checkout uses another, membership-apply uses a real phone library client-side but no library server-side) — standardize on one Laravel validation approach (`email:rfc,dns` or similar, a phone validation package) across all forms in the rebuild.
4. No file content-type/magic-byte validation anywhere (avatar, blog/news/shop images, membership documents) — always trusts the browser-reported MIME type. Use Laravel's `mimes:`/`image` rules, which inspect actual file content.

*Per the Approval Gate: analysis only.*
