# Workflows

Multi-step business processes that span several routes/tables. Single-page features are documented in FEATURES.md; this file covers only end-to-end sequences.

---

## 0. CONFIRMED ACI BUSINESS REQUIREMENT — Membership Application & Activation Workflow

> **UPDATE (later ACI confirmations OD-10 and OD-04) — read this before §0.6–§0.12.** The historical wording below describes the workflow as first confirmed. Two later confirmations supersede parts of it; the authoritative interpretation is in `docs/database/04_MEMBERSHIP_SCHEMA.md`, `docs/database/05_PROMOTION_SCHEMA.md`, `docs/database/17_DATABASE_OPEN_DECISIONS.md` and `docs/architecture/04_MEMBERSHIP_ARCHITECTURE.md`:
> * **§0.6–§0.9 (promotions, free path vs paid path, payment before activation):** the initial membership is **free for the first 6 months for EVERY approved new member**. It is a standard introductory period — **not** an optional promotion, not an eligibility calculation, nothing re-evaluated after approval. There is **no payment before activation** and no "paid path" for a new member. Payment (states `payment_pending` … `payment_confirmed`, evidence, rejection loop) applies to **renewal** payments after the introductory period. The confirmed workflow for every new member is Application → Admin approval → Payment/Free decision (not required for an initial membership) → Activation; approval is not immediate activation. No fake £0 payment is ever created.
> * **§0.11 (membership number):** the number identifies the **member for life** — it is generated **once, at first activation**, and **never changes at renewal**. `YY` = original activation year. Format and the row-locked sequence are unchanged.
> * **§0.12 (renewal):** before expiry the member is notified (configurable timing), pays the renewal fee (price from the database), and is renewed for another (normally) 12 months; no auto-charge, no automatic renewal without payment.

**Status: CONFIRMED by ACI — authoritative and final business specification (2026-09-15, superseding the preliminary confirmation captured in an earlier revision of this section).** This supersedes the legacy Lovable app's two-system membership behaviour documented in Section 1 below. Section 1 is retained purely as a historical record of what the reference application did — it is no longer the target design. LEGACY_RISKS.md §1 (the "two parallel, disconnected membership systems" defect) is resolved by this confirmed requirement: the new design is a single, unified **Membership Application → Approval → Membership** relationship, never two independent tables/entities.

This is a business-requirements record, not a database or Laravel design — exact schema/column names are deferred to DATABASE DESIGN (see the conceptual data model in §0.13 and in DATABASE.md). **Nothing in this section has been implemented** — no migrations, models, controllers, services, routes, views, or code.

### 0.1 Categories & aviation eligibility

- ACI will offer **exactly three membership categories**, each with a one-letter category code used in the membership number (§0.9):

  | Category | Code |
  |---|---|
  | Student | `S` |
  | Professional | `P` |
  | Veteran | `V` |

  Final commercial names, descriptions, pricing, and membership duration per category remain **configurable data**, not hard-coded application logic. *(Exact commercial names/pricing/duration are not yet finalized — see "Remaining decisions" below.)*
- **Every applicant, in all three categories with no exception, must demonstrate ACI's aviation eligibility requirement** — that they work in aviation, study aviation, or otherwise satisfy ACI's approved aviation participation/eligibility criteria — and must provide proof/documentation of this. This is a confirmed change from the legacy app, where only the Student and Veteran application forms required a file upload and the Professional form required none (see FEATURES.md §A, VALIDATION.md); the Professional-equivalent category must now also require this proof. The exact acceptable proof types may be configured/defined later, but the requirement itself — proof mandatory for every category — is not optional and is not deferred.

### 0.2 Application content & completeness

An applicant must, in one application:
- select exactly one of the three membership categories;
- provide the required personal information;
- provide aviation-related information (aviation role/study, and the aviation organisation/institution where applicable);
- provide evidence/proof of aviation eligibility (§0.1);
- submit the application.

**An application must not be considered complete/submittable if the mandatory aviation proof is missing.** The Application and the Membership are separate business concepts, related as:

```
Membership Application  →  Approval  →  Membership
```

Do **not** reproduce the legacy app's disconnected `memberships` + `membership_applications` architecture (see LEGACY_RISKS.md §1) — there is exactly one application-to-membership relationship, not two independently-initiated entities joined only by an email string.

### 0.3 Application statuses (authoritative)

Exactly four application statuses exist — no `under_review`/intermediate status beyond these:

- `submitted`
- `more_details_required`
- `approved`
- `rejected`

Admin, reviewing a `submitted` (or re-submitted, see below) application, takes exactly one of three actions:

- **Approve** — the applicant passes eligibility review and proceeds toward promotion-eligibility determination (§0.6) and ultimately membership activation (§0.10).
- **Reject** — the application is rejected (terminal, subject to reapplication rules in §0.14).
- **Request More Details** — the application is returned to the applicant with a request for additional information or evidence, moving it to `more_details_required`. **This must be a real workflow state that the applicant can act on inside the system, not merely an email notification** — the applicant provides the requested information/evidence and the application returns to the review process (back toward `submitted`/admin's queue). This explicitly resolves the legacy gap (LEGACY_RISKS.md: "no structured re-submission mechanism... the loop closes out-of-band via email reply").

An **auditable status history** of application decisions must be maintained (see §0.14) — every transition between these four statuses, who made the decision, and when, rather than the legacy pattern of overwriting a single JSON note with no history.

### 0.4 Admin review — what admins see

When reviewing an application, admins review:
- applicant information;
- selected membership category;
- aviation role/study information;
- aviation organisation/institution where applicable;
- submitted proof (§0.5);
- any additional information provided in response to a "More Details Required" request;
- the application's previous review/request history (§0.14).

### 0.5 Aviation proof — storage & handling requirements

Aviation proof is mandatory for **all three** categories (§0.1). The system must support secure document submission/storage for this proof, and the architecture must anticipate:
- file type validation;
- file size limits;
- secure (non-public) storage;
- authorization checks;
- prevention of unauthorized document access;
- auditability.

**Uploaded proof documents must never be publicly accessible.** See STORAGE.md for the cross-reference to the legacy document-upload mechanism this supersedes/extends.

**CONFIRMED**: submitted aviation proof must be reviewed by an administrator as part of the review in §0.4, and an application must not be Approved without that review having taken place — proof review is a mandatory step of admin decision-making, not an optional/automatic check. Exact acceptable proof types/formats per category remain configurable/TBC for the ARCHITECTURE and VALIDATION design phase — the requirement that proof is mandatory and admin-reviewed is settled; the accepted formats are not.

### 0.6 Promotion eligibility (on Approve)

Once an application is Approved, the system must determine whether the applicant qualifies for an **active ACI membership promotion**.

**CONFIRMED — multiple promotions:**
- ACI may have **multiple promotions stored in the system**, and **more than one promotion may be active at the same time**.
- Each promotion must define its own eligibility rules/applicable membership categories (in addition to name, active/inactive status, start date, end date, free-membership flag, and free-duration-months, per the configurable-data requirement below).
- **A given membership may receive only ONE promotion.** Promotions never stack — an applicant who happens to be eligible under two simultaneously-active promotions still receives exactly one entitlement.
- If more than one active promotion is eligible for the same applicant/category, the system must resolve the conflict **deterministically** using a **configurable priority/order** across promotions (e.g. an explicit priority number or ordering field on each promotion) — never an arbitrary or unspecified tie-break.
- The **selected** promotion (the one actually applied) must be recorded against the membership for audit/history (§0.16).
- **Do not allow two promotions to stack** unless ACI explicitly changes this business rule in the future.

ACI's initial promotion (the first row of this data, not a special case in code): **INTRODUCTORY FREE MEMBERSHIP PROMOTION** — membership fee FREE for the first **6 months**, purpose: attract and grow the ACI membership base. The promotion must be represented as **configurable business data**, administered through the admin panel — **do not hard-code "6 months free" into application logic.** At minimum each promotion supports: promotion name; active/inactive status; start date; end date; free-membership flag; free duration in months (not hard-coded to 6, so other promotions can differ); applicable membership categories; and a priority/order value for deterministic conflict resolution.

**Authoritative eligibility-timing rule**: eligibility is determined **at the point of membership activation**, not at application or approval time. If the applicant is approved and an applicable promotion is active **when the membership is activated** (selecting the single highest-priority eligible promotion per the rule above), the applicant receives that promotion's free-membership entitlement.

The six-month free period (for the introductory promotion specifically) begins from the **actual membership activation/start date** — worked example:

```
Promotion active:      1 October 2026 – 31 March 2027
Membership activated:  15 October 2026
→ Membership start:    15 October 2026
→ Free period:         6 months
→ Membership expiry:   14 April 2027
```

**Do not shorten the promised six-month benefit merely because the promotion campaign's own end date occurs earlier than the calculated expiry** — once granted, the member's 6 months run their full course regardless of the campaign window. Record **which promotion** was applied to a membership so the historical reason for a free entitlement remains auditable.

### 0.7 Free promotional path

If the applicant qualifies for the active free-membership promotion:
- **Do NOT request payment.**
- **Do NOT create a fake £0 payment transaction.**
- Mark payment as `payment_not_required` (§0.8).
- Record that the membership was activated under a promotional/free-membership entitlement, and record the applicable promotion (§0.6).
- Calculate membership expiry from the actual membership activation date (§0.6 worked example).
- Send the applicant an email confirming that their first six months of membership are free under the ACI introductory promotion (see NOTIFICATIONS.md).
- Proceed directly to Activation (§0.10).

Flow: `Approved → Promotion Eligibility Check → Free Promotion Applies → Payment Not Required → Activate Membership → Generate Membership Number → Secure Account Setup → Welcome Email → Digital Membership Card`

### 0.8 Paid membership path

If the free promotion does not apply:

1. Application is approved.
2. System determines the applicable membership fee (from the category's configured pricing).
3. Applicant receives payment instructions by email, including the approved ACI bank/payment details (§0.9) and instructions to pay and then submit payment confirmation.
4. Applicant makes the bank/payment transfer.
5. Applicant submits payment confirmation — **the preferred ACI workflow collects BOTH a payment reference and payment confirmation evidence/document** (§0.9), not just one or the other.
6. Admin reviews the submitted payment confirmation and either confirms or rejects it.

**Do not activate the membership merely because the applicant claims payment was made** — activation for the paid path requires a verified `payment_confirmed` decision by admin (§0.8/§0.10), never a self-reported claim.

### 0.9 Payment status & evidence

Authoritative payment states — exactly these five:

| State | Meaning |
|---|---|
| `payment_not_required` | Promotional/free membership — payment is skipped entirely, never a fabricated £0 record |
| `payment_pending` | Standard path: applicant has been sent the fee amount + bank/payment details, awaiting their payment |
| `payment_confirmation_submitted` | Applicant has submitted a payment reference and/or evidence document; awaiting admin review |
| `payment_confirmed` | Admin has reviewed and confirmed the payment — triggers Activation (§0.10) |
| `payment_rejected` | Admin has reviewed and rejected the submitted evidence as invalid/insufficient |

**Rejection loop (confirmed)**: `payment_rejected` → applicant is notified → payment status returns to `payment_pending` → applicant can submit corrected payment evidence. **Do NOT require the applicant to restart the entire membership application** — only the payment step re-opens.

Payment evidence handling: the system should support secure upload of a payment confirmation document alongside a payment reference; evidence must not be publicly accessible; admin must be able to review the evidence and record the payment decision; evidence needs the same class of validation/authorization/audit controls as aviation proof (§0.5).

**Bank/payment details** should have a dedicated configurable source of truth — not hard-coded into email templates, and not mixed indiscriminately into general `site_settings`. At minimum consider: account name; bank name; account number; sort code where applicable; IBAN where applicable; SWIFT/BIC where applicable; payment instructions. Exact fields can be finalized during ARCHITECTURE.

### 0.10 Membership activation

A membership is activated only when:
- **Free path**: the applicant is approved AND the valid promotional entitlement applies (§0.7); OR
- **Paid path**: the applicant is approved AND payment has been confirmed (`payment_confirmed`, §0.9).

Upon activation:
1. Create/activate the membership.
2. Generate the **unique membership number** (§0.11 — authoritative format; never generated earlier than this point).
3. Set the membership start date (the actual activation date) and calculate the expiry date from it (§0.6, §0.12).
4. Record whether the membership is promotional/free, and which promotion applied where relevant.
5. Record the payment status.
6. Create the appropriate membership status history entry (§0.14).
7. Initiate secure account setup (§0.13).
8. Send a welcome email (see NOTIFICATIONS.md).
9. Make the digital membership card available (§0.13).

### 0.11 Membership number — AUTHORITATIVE FORMAT (CRITICAL)

The membership number must be **exactly 9 characters**: 1 uppercase letter + 8 digits, structured as:

```
C YY RR SSSS
```

- `C` — membership category code: Student=`S`, Professional=`P`, Veteran=`V` (always uppercase).
- `YY` — last two digits of the **registration/activation year** (the year the membership is actually activated, not the application year if they differ).
- `RR` — two randomly generated digits, `00`–`99` inclusive, including leading zeroes. The random component is cosmetic/obfuscating only — it **must not** replace or affect the sequential counter below.
- `SSSS` — a four-digit **sequential** registration number, always four digits including leading zeroes.

Examples: `S26070003` (Student, registered 2026, random `07`, 3rd registrant in sequence), `P26120001`, `V26990015`.

**Non-negotiable rules:**
- The complete membership number must be **unique**.
- Membership numbers must **NOT** be generated at application submission.
- Membership numbers must be generated **only** when the membership is actually activated (§0.10).
- A rejected application must **NOT** consume a membership sequence number.
- An application awaiting payment (`payment_pending`/`payment_confirmation_submitted`) must **NOT** consume a membership sequence number.
- A free promotional membership receives a membership number when activated (§0.7).
- A paid membership receives a membership number only after payment is confirmed and the membership is activated (§0.8).

**Sequence safety**: the four-digit sequence must be generated safely and transactionally to prevent duplicate numbers when two memberships activate concurrently — the architecture should support a reliable sequence mechanism, **not** an unsafe `MAX()+1` calculation.

**CONFIRMED — per-category sequencing**: each category maintains its **own independent** four-digit sequential counter — Student `S26XX0001, S26XX0002, S26XX0003...`, Professional `P26XX0001, P26XX0002, P26XX0003...`, Veteran `V26XX0001, V26XX0002, V26XX0003...`, each counted separately from the others. This is settled, not a preference pending confirmation.

### 0.12 Membership expiry & renewal

Expiry is always calculated from the **actual membership start/activation date** according to the applicable membership duration — for the introductory promotion, `start date + 6 months` (§0.6).

When a promotional (or any) membership approaches expiry:
- notify the member before expiry — recommended reminders at 30 days before, 7 days before, and optionally on the day of expiry (see NOTIFICATIONS.md);
- provide renewal instructions;
- **do not automatically charge** the member;
- **do not automatically convert** the member to a paid membership without explicit ACI business approval.

After expiry, membership status becomes `expired` unless renewed. Exact renewal pricing and workflow can be finalized during ARCHITECTURE.

### 0.13 Secure account setup & digital membership card

**Do NOT send permanent passwords by ordinary email** — this replaces the legacy app's insecure temporary-password-by-email pattern entirely (AUTHORIZATION.md §6, LEGACY_RISKS.md §2). Preferred workflow:

```
Membership Activated
  → generate secure one-time account setup token/link
  → email applicant
  → applicant opens secure link
  → applicant creates password
  → token becomes invalid after successful use
  → applicant can log in
```

The setup token must be securely generated, time-limited, single-use, invalidated after successful use, and must never expose the user's password.

After activation, the **digital membership card must be available in the member dashboard** — preferred functionality: view and download. The card should display Aviation Club International branding, member name, membership number, membership category, membership status, and valid-until date — **do not expose unnecessary personal information**. Exact visual/card format can be finalized during UI/architecture design; the architecture should leave room for a future QR-based membership verification feature (§0.15), without that feature needing to be built now.

### 0.14 Reapplication after rejection

**CONFIRMED — final decision**: a rejected application does **not** permanently prevent an applicant from applying again.

- Reapplication is allowed.
- Recommended cooldown period: **30 days** from rejection before a new application from the same applicant is accepted.
- The 30-day figure is **admin-configurable data**, not hard-coded, so ACI can change it later without a code change.
- **Previous applications must remain in the historical record** — a new application is a new row/entity, never an overwrite of the rejected one (this also resolves the legacy duplicate-check gap noted in VALIDATION.md/LEGACY_RISKS.md, where a rejected applicant could never reapply with the same email/mobile at all).
- A new application must go through the **normal** eligibility, proof, and admin-review process in full (§0.1–§0.5) — no shortcut or carry-over from the earlier rejected application.

### 0.15 Membership verification (future-facing)

The future architecture should be capable of supporting a secure membership verification mechanism keyed on the unique membership number. If a public verification page is introduced, it must expose only the minimum information necessary to confirm a membership is valid — never sensitive member information.

### 0.16 Audit requirements

The architecture should maintain historical records for: application status changes; admin decisions; requests for more details; applicant responses; payment confirmation decisions; membership activation; which promotion (if any) was applied; membership status changes; membership number generation; and other important administrative actions. **Do not overwrite important historical events where an audit trail is required.**

### 0.17 Security requirements (cross-cutting)

The Laravel rebuild must not reproduce the reference system's known security weaknesses (see AUTHORIZATION.md, LEGACY_RISKS.md). Membership functionality specifically must include appropriate controls for: authentication; authorization (Laravel policies/gates, role-based admin access); ownership checks; IDOR prevention; server-side validation; secure file uploads; private document storage; CSRF protection; rate limiting where appropriate; secure password handling; secure account setup via signed/expiring one-time links; audit logging of important membership/admin actions; and protection of both payment information and applicant personal information. **Never rely solely on frontend restrictions for security.**

### 0.18 Authoritative business flow

```
Applicant
  → Select 1 of 3 Categories
  → Provide Aviation Information
  → Upload Mandatory Aviation Proof
  → Submit Application
  → Admin Review

Admin decision:  Reject  |  More Details Required  |  Approve

  If More Details Required:
    Applicant Provides Details → Return to Review

  If Approve:
    Check Active Promotion

    FREE PATH:
      Promotion Applies → No Payment Required → Activate Membership

    PAID PATH:
      Payment Required → Send Bank Details → Applicant Pays
        → Submit Payment Reference + Evidence → Admin Verifies
        → Payment Confirmed → Activate Membership

  Then both paths:
    Generate Membership Number → Calculate Membership Expiry
      → Secure Account Setup → Welcome Email → Digital Membership Card
```

### 0.19 Conceptual data model (NOT an implementation — for DATABASE DESIGN phase)

Per the Database Rule and Implementation Rule, this is a conceptual recommendation only; no migrations/models have been created. Likely concepts, replacing the legacy `memberships`/`membership_applications` pair with one coherent relationship:

- **`membership_categories`** (or `membership_plans`, renamed/repurposed) — the three categories (§0.1), their pricing, and standard duration.
- **`membership_applications`** — one row per application: category, applicant details, aviation role/study info, aviation proof documents, status (`submitted`/`more_details_required`/`approved`/`rejected`, §0.3), review notes.
- **`memberships`** — one row per activated membership, created only on activation (§0.10), FK'd back to its originating application (`Membership Application → Approval → Membership`, never a disconnected pair): membership number (§0.11), category, start date, expiry date, status (active/expired/suspended), whether promotional/free, and which promotion (if any) granted the entitlement.
- **`membership_status_history`** — an audit trail of every status transition on both the application and the membership (§0.16), replacing the legacy app's un-auditable single-JSON-blob-note pattern.
- **`membership_promotions`** — the admin-configurable promotion entity (§0.6): name, active/inactive, start date, end date, free-membership flag, free-duration-months, applicable categories, and a **priority/order** value for deterministic conflict resolution when multiple promotions are simultaneously active and eligible. Multiple rows may exist and be active at once; a `memberships` row references at most **one** selected promotion (never a stacked combination).
- **Payment records — only created when payment is actually required** (§0.9). A free-promotional membership must never have a payment record with a zero amount; `payment_not_required` with no monetary record is how "free" is represented. Should also accommodate a payment reference and evidence document per §0.9.
- **A dedicated bank/payment-details entity** (§0.9) — not folded into `site_settings`, not hard-coded into email templates.

### Remaining business decisions requiring ACI approval (do not infer)

All previously-open structural questions (reapplication cooldown, per-category vs. global membership-number sequencing, and whether multiple promotions can be active/how conflicts resolve) are now **CONFIRMED and closed** — see §0.6, §0.11, §0.14. The specification also explicitly defers several details to ARCHITECTURE as non-blocking implementation choices (exact bank-detail field set, exact card visual design, exact renewal pricing/workflow) — those are noted inline above and are **not** listed again here. What remains genuinely open:

- Final commercial names, pricing, and standard duration for the three membership categories.
- Exact acceptable aviation-proof document types/formats per category (the requirement that proof is mandatory and admin-reviewed is settled; the accepted formats are not — §0.5).
- The exact priority/order values ACI wants for its promotions once more than one exists (the mechanism is confirmed; the concrete ordering of real promotions is a data-entry decision at launch, not a design question).

---

## 1. LEGACY REFERENCE ONLY — Lovable App's Membership Application Workflow (System B — `membership_applications`)

**This section documents what the reference application actually did. It is superseded by the CONFIRMED ACI requirement in Section 0 above and must not be used as the target design** — it is retained only so the reverse-engineering record stays complete and auditable. Where Section 0 conflicts with what follows, Section 0 governs.

This was the richer, currently-live (in the reference app) membership onboarding path (see LEGACY_RISKS.md for why there were *two* membership systems and which one this is).

Statuses observed: `submitted` → (`approved` | `rejected` | `more_details_requested`). Tables touched: `membership_applications` (primary), `auth.users`/Laravel `users` (account provisioning on accept). The separate `memberships` (System A) table is **never touched by this workflow**.

1. **Apply (public, no login).** Visitor picks Student/Professional/Veteran, fills the type-specific form → client + server duplicate-check (email ILIKE / mobile exact match against existing `membership_applications`, any status) → on pass, documents uploaded to Storage bucket `documents` → row inserted with `status='submitted'`. [`submitPreApplication`, `membership.functions.ts:156-204`]
2. **Applicant notified** — "Membership Application Successfully Submitted" email. [`sendApplicationSubmittedEmail`]
3. **Admin notified** — "New membership application – {name}" email.
4. **Pending review** — visible to any admin via `adminListMembershipApplications`/`adminGetMembershipApplication` (documents shown via 1-hour signed URLs).
5. **Admin decides** (`adminReviewMembershipApplication`, `admin.functions.ts:589-727`), one of three actions:
   - **`request_details`** → `status='more_details_requested'`; message appended into `form_data.admin_requested_details` (+timestamp), no separate audit table; applicant emailed. No structured re-submission mechanism — the loop closes out-of-band (email reply).
   - **`decline`** → `status='rejected'`; decline email sent; no account created. Terminal (a new application with the same email is blocked by the duplicate check unless the row is manually edited/deleted).
   - **`accept`** → `status='approved'` AND, in the same handler:
     a. Allocate `membership_number` via `next_membership_number(_category)` RPC (category letter + 2-digit year + random 2-digit + 4-digit sequence), unless already set — idempotent on re-run.
     b. Compute `issued_on=today`, `expires_on=issued_on+1 year`, unless already set.
     c. Generate `verification_token` (32-hex, stripped UUID), unless already set.
     d. Generate a one-time CSPRNG temp password (`"Av"+12 random chars+"#1"`), never persisted to any app table.
     e. Create (or, if the email already has an Auth account, **reset the password of**) a Supabase Auth user with `must_change_password:true` metadata — see AUTHORIZATION.md §6 item 9 for the account-takeover risk this "reset on collision" fallback carries.
     f. Persist `membership_number`/`issued_on`/`expires_on`/`verification_token` onto the application row.
6. **Card built + decision email sent** — an inline HTML "card" (name, number, dates, QR pointing at the public verify URL) is embedded in the acceptance email, which also carries the login email + temp password in plaintext. [`membership-card.server.ts:1-75`]
7. **Member logs in** using the temp password; `must_change_password:true` is expected to force a change, but is enforced only by the client-side `/dashboard/password` redirect (see AUTHORIZATION.md §2 — a real gap) — and only the *dedicated* password page clears the flag; the duplicate inline form on `/dashboard/profile` does not (see LEGACY_RISKS.md).
8. **Ongoing verification** — anyone with the card's QR/token can hit `/verify/{token}`; validity (`status='approved' AND current_date BETWEEN issued_on AND expires_on`) is re-evaluated live on every call, so a card auto-stops verifying the day after expiry with no batch job, and would also stop verifying if an admin ever changed status away from `approved` — **no UI path was found anywhere in the reviewed files for un-approving/revoking an already-approved application**; UNKNOWN whether one exists elsewhere.
9. **Not connected to System A.** At no point does this workflow create or update a `memberships` row. A member who completed this flow has a membership number and login but `/dashboard/membership` shows "no active membership" unless a separate, unrelated `applyMembership`/`submitMembershipApplication` flow was also used. **RESOLVED by the confirmed requirement in Section 0 above**: the target design is a single `Membership Application → Approved → Membership` relationship — this legacy split is not to be reproduced (see LEGACY_RISKS.md §1).

**Transactionality gap (flag, do not reproduce)**: steps 5a–5f above are not wrapped in a database transaction across the Postgres RPC call, the Auth-API call, and the application-row update — a crash mid-sequence can leave a created/password-reset Auth user with no matching approved application state. The Laravel rebuild should wrap the equivalent sequence in `DB::transaction()` and treat the external Auth-provisioning step carefully (compensating action or idempotency key if it can't be inside the same transaction).

## 2. E-Shop Checkout Workflow

1. **Browse** — `/shop` fetches active `shop_products` (+ `shop_categories` for filter chips); RLS hides `members`-visibility products from anonymous visitors. Category filter is client-side over the already-fetched list.
2. **View product** — `/shop/$slug`; a hidden (members-only) or missing product renders the same generic "not available" message — no way to distinguish the two cases.
3. **Add to cart** — `localStorage["acsl-cart"]` only, no DB/session persistence for guest or member. Line item snapshots `{id, name, price, qty, image_url}` at add-time; price can go stale vs. the live DB row until checkout re-derives it.
4. **View/edit cart** (`/shop/cart`) — quantity clamped 1-99; removal only via the explicit delete button (the minus button cannot reduce a qty-1 line to zero due to a clamp-order quirk).
5. **Checkout** (`/shop/checkout`) — form collects name/email/phone/address/notes (email pre-filled if logged in but not otherwise tied to the account); if cart is empty, redirected to an empty-cart message. Static copy: *"Payment is arranged directly with the club after your order is confirmed."* — **no payment gateway exists anywhere in this codebase**; confirmed absent from all 7 shop files, `email.server.ts`, and the schema (no `payment_status`/`payment_link` columns on `shop_orders`, unlike the unrelated `memberships` table which does have those).
6. **Submit** (`placeShopOrder`, `shop.functions.ts:30-85`, unauthenticated-accessible, `supabaseAdmin`):
   a. Hand-validates fields (see VALIDATION.md) — not Zod.
   b. Re-fetches real `shop_products` rows for the submitted ids, `is_active=true` only — this is the sole source of truth for price/name; a tampered/stale client cart cannot manipulate the charged total (there isn't a charge, but the recorded total either).
   c. Computes `total` server-side from resolved lines.
   d. Inserts `shop_orders` — **`user_id` is never populated, even for a logged-in customer** (schema/RLS support it; the insert code just doesn't set it — a reference-app defect, recommend fixing in Laravel and flagging as a deliberate behaviour change).
   e. Inserts `shop_order_items` — **not wrapped in the same transaction as (d)**, so a failure here can leave an orphaned order header with zero items. Wrap both in one `DB::transaction()` in Laravel.
   f. Fires a best-effort admin-only email notification (see NOTIFICATIONS.md) — failure here never fails the order.
   g. Returns `{orderId, total}`.
7. **Client cleanup** — cart cleared, navigate to `/shop/order/$orderId`.
8. **Order confirmation** (`getShopOrder`, no ownership/auth check — the UUID alone is the access control) — renders a "Thank you" page; states the club will follow up by email to confirm delivery **and payment**. Everything after this point (actual payment collection) happens entirely outside the application, by phone/bank transfer/in-person — **out of system, not implemented in code**.
9. **No further state transition exists.** `shop_orders.status` remains at its inserted default (`'new'`) indefinitely — **no code path anywhere updates it**, unlike the parallel status-update functions that exist for memberships/comments/job-applications/enquiries. Admin can only *view* orders (`adminListShop`), never change their status. A real order-status lifecycle (`new → processing → paid → fulfilled/cancelled`) and an access-control decision for the confirmation page are both **DATABASE DESIGN / business-requirements decisions** for ARCHITECTURE phase, not something the reference app gives real precedent for.

## 3. Comment Moderation Workflow (as currently implemented — note the gap)

1. Authenticated member posts a comment on a published blog post → inserted with `status:"approved"` **immediately, unconditionally** (`blog.functions.ts:76-84`) — despite the `comment_status` enum (`pending|approved|hidden`) implying a moderation queue.
2. The comment is publicly visible right away (RLS: `status='approved' OR own OR admin`).
3. Admin can separately Approve/Reject/Delete any comment (`admin.comments.tsx`) — but since new comments start life already `approved`, this admin action currently only ever *demotes* an already-visible comment, rather than gating publication in the first place.
4. Member can delete their own comment (`dashboard.comments.tsx`) at any time; no edit capability exists.

**Flag for ACI**: decide explicitly whether the Laravel rebuild should moderate-by-default (`status='pending'` on insert, admin must approve before public visibility — closer to what the schema implies) or continue the reference's effective auto-approve behaviour. Do not silently pick either.

## 4. Job Application status workflow

`job_applications.status` (Postgres enum `application_status`, only `applied`/`reviewed` confirmed in the schema; the admin UI additionally offers `reviewing|shortlisted|rejected|hired` as free-text-passed-through values not enumerated anywhere in SQL — UNCERTAIN full canonical set, confirm before Laravel `ENUM`/lookup-table design). Admin changes status via an inline `<Select>` with no confirmation and no notification to the applicant on any transition (see NOTIFICATIONS.md — this is one of several admin actions that notify no one).

*Per the Approval Gate: analysis only, no workflow has been implemented in Laravel.*

