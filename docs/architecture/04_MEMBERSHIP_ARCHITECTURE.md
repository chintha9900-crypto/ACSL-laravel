# 04 — Membership Architecture

This is the most detailed domain document because Membership is the core of ACI's system and its business rules are **CONFIRMED** in `docs/reverse-engineering/WORKFLOWS.md` §0 **as superseded by the later confirmed decisions OD-10 (free introductory period) and OD-04 (lifetime membership number)** — see `docs/database/17_DATABASE_OPEN_DECISIONS.md`. Every rule tagged CONFIRMED REQUIREMENT below is restated from those confirmations, not invented here; this document's job is to propose the **Laravel mechanism** that implements each rule. Table-level detail is in `docs/database/04_MEMBERSHIP_SCHEMA.md` (the authority for columns and constraints).

## 0. Confirmed model in one paragraph

An applicant submits an application with mandatory aviation proof. An admin reviews it (approve / reject / request more details). **The confirmed workflow for every new member is Application → Admin approval → Payment/Free decision → Activation** (approval is *not* immediate activation). After approval the **payment/free decision** confirms that payment is **not** required — the first 6 months are a standard introductory rule for every approved new member (configurable, not a promotion, no payment, no £0 payment record) — and only then is the membership **activated**: a stable **member record** is created and the **membership number is issued once** (`CYYRRSSSS`, per-category/year row-locked sequence), the member's **first term** (the free introductory term) starts, and the account-setup link and welcome/setup emails follow. Before the term ends the member is reminded (configurable timing, no auto-charge), **pays a renewal fee** (price from the database), and — once an admin confirms payment — is renewed for another (normally) 12 months. **The membership number never changes**; validity dates and renewals are separate term records.

## 1. Entities (conceptual)

| Entity | Purpose | Key relationships |
|---|---|---|
| `MembershipCategory` | The 3 categories (Student/`S`, Professional/`P`, Veteran/`V`) as configurable data | Referenced by `MembershipApplication`, `Membership`, `MembershipPlan` |
| `MembershipPlan` | Renewal pricing per category (fee, currency, normally 12 months) — data, not code | belongsTo `MembershipCategory`; snapshotted onto each renewal term |
| `MembershipSettings` | Typed singleton: introductory free months (default 6), setup-link lifetime (the legacy `reapplication_cooldown_days` column is unused/deprecated) | read by activation/renewal Actions |
| `MembershipApplication` | One row per application attempt for **new** membership | belongsTo category; hasMany proof `Document`s; hasOne `Membership` (after approval); hasMany status-history entries |
| `Membership` | **The stable member record — one per member for life; owns the membership number** | belongsTo originating `MembershipApplication`; belongsTo `User`; belongsTo category; hasMany `MembershipTerm` |
| `MembershipTerm` | One validity term: term 1 = **introductory (free)**, later terms = **paid renewals** | belongsTo `Membership`; belongsTo `MembershipPlan` (renewals); hasMany `Payment` (renewals) |
| `MembershipNumberSequence` | Per-category, per-year atomic counter (see §4) | referenced only by the number-generation Action |
| `MembershipStatusHistory` | Audit trail of every application/member/term/payment transition | anchored to the application (before activation) or the member (after), optional term — typed FKs, not polymorphic |
| `AccountSetupToken` | Secure one-time setup token (§7) | belongsTo `User` and `Membership` |

The deferred **Promotions** capability (`05` in the database set) is a *separate future concept* and **does not control** the introductory period. This resolves `LEGACY_RISKS.md` §1 (the two disconnected legacy systems): one `MembershipApplication → Membership → MembershipTerm` chain, never a second independently initiated membership table.

## 2. Application statuses & the review workflow

**CONFIRMED REQUIREMENT** (`WORKFLOWS.md` §0.3): exactly four statuses — `submitted`, `more_details_required`, `approved`, `rejected`. No intermediate `under_review` state.

- **Laravel mechanism**: a backed PHP enum `MembershipApplicationStatus`, cast on the model via `casts()`. Transitions are only ever performed through `Actions/Membership/ReviewMembershipApplication`, never a direct `->update(['status' => ...])` from a controller.
- **"More Details Required" is a real workflow state**, not just an email: the applicant-facing side is a page reached through a **signed, expiring link** in the M2 notification (frontend C-05) that lets them submit the requested information/documents via `Actions/Membership/RespondToMoreDetailsRequest`, moving the status back toward `submitted`.
- **Audit trail**: every transition is written to `MembershipStatusHistory` by a Listener on a `MembershipApplicationStatusChanged` event, in the same transaction.
- **Security**: `MembershipApplicationPolicy::review()` restricted to `admin`, server-side only.
- **Approval outcome**: approving a `submitted` application (proof reviewed) sets it to `approved`. Approval is **not** activation: it is followed by the **payment/free decision** and then **activation** (§4/§5) as distinct steps. For every new member the decision resolves to "payment not required" (introductory period). An `approved` application with no `Membership` record yet is therefore a normal "awaiting activation" state. Whether the follow-on steps run automatically or need an explicit admin action is not specified by ACI (database OD-23).

**CONFIRMED REQUIREMENT** (`WORKFLOWS.md` §0.5): aviation proof must be reviewed by an administrator before approval is possible — modelled as `proof_reviewed_at`/`proof_reviewed_by` on the application and enforced by a database CHECK plus a guard in the review Action.

## 3. Reapplication after rejection

**CONFIRMED REQUIREMENT** (`WORKFLOWS.md` §0.14): reapplication after rejection is allowed **with no cooldown or waiting period**; every historical application retained; a new application undergoes the full process again.

- **Laravel mechanism**: the eligibility check blocks a new application only when (a) an *open* application already exists for the email (a database unique key, `open_email_key`, enforces this), or (b) the person **already has a `Membership` record** — they use the existing membership/renewal process instead, never a second member identity (a membership is the lifelong identity; expired or lapsed members renew rather than reapply). A past rejection never blocks a new application.
- A `MembershipApplication` is immutable once terminal except for the fields the workflow itself updates; a reapplication after rejection is always a **new row**.
- `membership_settings.reapplication_cooldown_days` exists from an earlier design but is **unused/deprecated**: no rule reads it and the application must not use it.

## 4. Membership number — authoritative generation (issued once per member)

**CONFIRMED REQUIREMENT**: exactly 9 characters, `C+YY+RR+SSSS` (category letter `S/P/V`, 2-digit **original activation year**, 2 random digits that never affect the sequence, 4-digit **per-category, per-year** sequential number); **the number identifies the member and never changes at renewal**; generated only at first activation; never consumed by a rejected or pending application; unique; generated safely and transactionally, never via `MAX()+1`.

**ARCHITECTURAL DECISION — sequence mechanism**: a `membership_number_sequences` table, one row per `(category, year)`, updated via `SELECT ... FOR UPDATE` inside the **first-activation transaction** (the activation step that follows approval and the payment/free decision; it creates the member and term 1).

```
Step A — Actions/Membership/ApproveMembershipApplication (own transaction):
  compare-and-set application: submitted → approved (proof reviewed); history/audit   -- 0 rows ⇒ already decided ⇒ abort
Step B — payment/free decision (own recorded step): for every new member, "payment not required — introductory period"
         (history event; no Payment row is created)
Step C — Actions/Membership/ActivateMembership (single DB::transaction, first activation only):
  1. lock the application row (must be `approved` and have no Membership yet)   -- else abort (already activated)
  2. $seq = MembershipNumberSequence where (category, year) lockForUpdate   -- create-on-miss handled safely
  3. next = $seq->last_number + 1;  if > 9999 abort;  $seq->last_number = next
  4. rr = random_int(0, 99)  (cosmetic)
  5. number = C . YY . RR . SSSS
  6. insert Membership (number, number_year, number_sequence, activated_at/on, user link)
  7. insert MembershipTerm #1 (introductory, no payment, duration = settings snapshot, dates)
  8. provision User (pending_setup) + AccountSetupToken; write history/audit
```

- **Why appropriate**: a locked-row counter is the standard collision-free per-key sequence in MySQL without relying on `AUTO_INCREMENT`; being inside the activation transaction means a failed activation returns its number.
- **Laravel mechanism**: `lockForUpdate()` inside `DB::transaction()` (with deadlock retry); `UNIQUE(membership_number)`, `UNIQUE(category, year, sequence)` and `UNIQUE(membership_application_id)` as defence in depth.
- **Renewals never call this Action**; the number attributes are immutable on the model.
- **Alternatives considered** (rejected): `MAX()+1` (unsafe under concurrency, forbidden); a global `AUTO_INCREMENT` with a display prefix (no per-category sequences); optimistic retry on a unique violation (more moving parts). A new number per renewal is **not** a valid alternative — it contradicts the confirmed lifetime-number rule.

## 5. The introductory free period (replaces "promotion eligibility resolution")

**CONFIRMED REQUIREMENT (OD-10)**: the initial membership is **free for the first 6 months for every approved new member**. It is a standard membership-lifecycle rule — **not** an optional promotion, coupon, or eligibility calculation — and nothing is re-evaluated after approval.

- **Laravel mechanism**: after approval and the payment/free decision, `Actions/Membership/ActivateMembership` unconditionally creates the member's first `MembershipTerm` with `term_kind = introductory`, `payment_status = payment_not_required`, and `duration_months` snapshotted from `MembershipSettings::current()->introductory_period_months` (default 6). **No promotion lookup, no priority, no category applicability, no resolver Action.** `expires_on = starts_on + duration − 1 day` (inclusive), computed once and stored.
- **No payment**: no `Payment` row is created for term 1 — not even with a zero amount. The *absence* of a `Payment` plus `payment_not_required` on the term is how "free" is represented; the database rejects a zero-amount payment and any fee on the introductory term.
- **Configurable, not hard-coded**: the length is data (`membership_settings`), never a literal in code, views or email copy (M4 reads the granted length from the term).
- **Separation from promotions**: the Promotions domain (`docs/architecture/02_DOMAIN_ARCHITECTURE.md` §3) is retained only as a *deferred* capability for possible future marketing promotions; it must never gate, shorten or replace the introductory period.
- **Legacy**: the reference app's "first 100 students free" idea is not this rule and is not carried over.

## 6. Renewal (paid) — replaces the old "payment path before activation"

**CONFIRMED REQUIREMENT (OD-10, `WORKFLOWS.md` §0.12 as amended)**: before the current term expires the member is notified, **pays the renewal fee**, and is renewed for another (normally) **12 months**. Renewal price comes from the database (`membership_plans`); notification timing is configurable; **no automatic charging; no automatic renewal without payment.**

- **Flow**: reminder (M10, configurable offsets, recommended 30/7/0 days) → member starts renewal (`Actions/Membership/StartRenewal`: creates a `MembershipTerm` `renewal`/`pending_payment` with plan/fee snapshot and a `Payment` `pending` via `Actions/Payments/CreateMembershipPayment`; sends the renewal payment instructions M5 with fee + active bank details) → member submits reference + evidence (`Payment` → `processing`, term `payment_confirmation_submitted`, M6) → admin **confirms** (`Actions/Payments/ConfirmPayment`: `Payment` → `paid`, term `payment_confirmed` → **`active`** with dates) or **rejects** (`Payment` → `failed`, term back to `payment_pending`, M7; the same rows are resubmitted, nothing restarts).
- **The membership record and number are untouched by renewal.** A renewal is only a new term.
- **Term dates**: recommended default — contiguous (starts the day after the current term's `expires_on` when renewed while current; otherwise on the confirmation date). Not confirmed by ACI (database OD-21); the schema is neutral.
- This domain does not implement payment processing itself — it consumes the Payments domain (`08_PAYMENT_ARCHITECTURE.md`). A **renewal `Payment`** relates to a *membership term* (`membership_term_id`) XOR an order.

## 7. Secure account setup (replaces legacy plaintext temp passwords)

**CONFIRMED REQUIREMENT** (`WORKFLOWS.md` §0.13): no permanent/plaintext passwords by email; a secure, time-limited, single-use setup token/link; the applicant sets their own password; the token is invalidated after use.

- **Laravel mechanism**: `AccountSetupToken` table (not Laravel's `password_reset_tokens`, whose semantics assume an existing account). The `User` row is **provisioned at activation** with `password NULL`, `status pending_setup` and `name` copied from the application's `full_name` (single field — no first/last split); the token references that user and the new member record. Only a **hash** of the token is stored (`hash('sha256', $plainToken)`); the email carries the plaintext once; on visiting the link the applicant sets their password, the user becomes `active`, and the token is consumed.
- **Security**: CSPRNG token (`Str::random(40)`+); short config-driven expiry (`membership_settings.account_setup_token_ttl_hours`, default 72, 24–72 h proposed); expired/used tokens get a generic "no longer valid" message. **There is no public self-registration** (frontend C-03, approved): accounts exist only through activation.
- **Closes** `AUTHORIZATION.md` §6 items 2, 4, 7 and 9 and `LEGACY_RISKS.md` §2 item 4.

## 8. The pre-existing-account edge case

`AUTHORIZATION.md` §6 item 9 flagged the legacy app's account-takeover risk. **ARCHITECTURAL DECISION**: if an activation's applicant email already matches an existing `User`, the system does **not** silently reset that account's credentials; the owner must confirm/link through the secure setup-token flow before the membership is attached (database OD-06 — still open, recommended default).

## 9. Expiry & renewal jobs

**CONFIRMED REQUIREMENT** (`WORKFLOWS.md` §0.12): expiry is calculated from the actual start date; reminders before expiry (configurable, recommended 30/7/0 days); no auto-charge; no auto-convert; status `expired` if not renewed.

- **Laravel mechanism**: each `MembershipTerm.expires_on` is computed once and stored. A scheduled job (`11_BACKGROUND_JOBS_ARCHITECTURE.md`) queries **current terms** whose `expires_on` matches a configured reminder offset **and that have no confirmed/pending renewal**, and dispatches M10 (renewal reminder with fee and instructions); a second daily job flips `MembershipTerm.status` to `expired` for terms past `expires_on` and dispatches M11. Both are pure status-and-notification jobs that never touch payment or charge anyone.
- A member is **current** when a term is `active` and today is within its dates; otherwise the membership is **not current** (lapsed). The membership number persists in both states. **Non-payment:** if the renewal payment is not made the membership **eventually becomes deactivated**. The duration of any grace period between term expiry and deactivation is **not defined by ACI and is not invented here** (database OD-22); until it is defined, "deactivated" is represented as "no current term".
- Unconfirmed renewal details (start-date rule, renewal after a long lapse, category change) are tracked as database OD-21; the non-payment grace period/deactivation rule as OD-22; the trigger between approval and activation as OD-23. All are schema-neutral or additive.

## 10. Digital membership card

**CONFIRMED REQUIREMENT** (`WORKFLOWS.md` §0.13): viewable and downloadable from the member dashboard; shows ACI branding, member name, **membership number**, category, status, valid-until date; no unnecessary personal information; future QR verification possible; public verification (if built) exposes the minimum.

- **Laravel mechanism**: a Blade view rendered on demand (view + PDF via the integration in `13_INTEGRATION_ARCHITECTURE.md`); "valid until" is the **current term's** `expires_on`; the number is the member's lifetime number. Future QR: a nullable `verification_token` on `Membership` (unique, unused until built).

## 11. Summary status model

```
MembershipApplication.status:  submitted ─┬─▶ more_details_required ─┐
                                            │◀────────────────────────┘
                                            ├─▶ approved ──▶ PAYMENT/FREE DECISION ──▶ ACTIVATION
                                            └─▶ rejected  ──(no cooldown)──▶ new application allowed
                                            (a person who already has a Membership renews; they do not reapply)

PAYMENT/FREE DECISION (every approved new member): payment NOT required — first 6 months free (standard rule, no £0 payment)

ACTIVATION (follows the decision; every approved new member — no eligibility check):
  lock number sequence → issue membership number ONCE → create Membership
  → create MembershipTerm #1: introductory, first 6 months free (setting), payment_not_required, active
  → provision User (pending_setup) + AccountSetupToken → history/audit → M4, M8, M9

Later (before expiry): reminders (M10) → member starts renewal
  MembershipTerm #n (renewal): pending_payment / payment_pending
        │ member submits reference + evidence → payment_confirmation_submitted (M6)
        ├─ admin rejects → payment_pending (M7, same rows resubmitted)
        └─ admin confirms → payment_confirmed → term active (normally 12 months); number unchanged
  term expiry → status expired (M11); the membership number persists; if the renewal is never paid the membership eventually becomes deactivated (grace period undefined, OD-22)
```

*Conceptual design only. No migrations, models, Actions, or other code exist yet.*
