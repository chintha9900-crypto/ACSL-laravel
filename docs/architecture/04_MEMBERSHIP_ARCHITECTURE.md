# 04 — Membership Architecture

This is the most detailed domain document because Membership is the core of ACI's system and its business rules are fully **CONFIRMED** in `docs/reverse-engineering/WORKFLOWS.md` §0 (authoritative, final specification). Every rule tagged CONFIRMED REQUIREMENT below is restated from that document, not invented here; this document's job is to propose the **Laravel mechanism** that implements each rule.

## 1. Entities (conceptual)

| Entity | Purpose | Key relationships |
|---|---|---|
| `MembershipCategory` | The 3 categories (Student/`S`, Professional/`P`, Veteran/`V`) as configurable data | Referenced by `MembershipApplication`, `Membership` |
| `MembershipApplication` | One row per application attempt | belongsTo `MembershipCategory`; hasMany `AviationProofDocument`; hasOne `Membership` (nullable, only after activation); hasMany `MembershipStatusHistory` entries |
| `AviationProofDocument` | Mandatory eligibility evidence, all 3 categories | belongsTo `MembershipApplication`; file reference via Files/Documents domain |
| `Membership` | The activated membership record | belongsTo `MembershipApplication` (its origin — never a separately-initiated row); belongsTo `MembershipCategory`; belongsTo `User` (once account setup completes); belongsTo `MembershipPromotion` (nullable); hasOne `Payment` (nullable, Payments domain) |
| `MembershipNumberSequence` | Per-category atomic counter, one row per category per year (see §4) | referenced only by the number-generation Action, never read/written elsewhere |
| `MembershipStatusHistory` | Audit trail of every application and membership status transition | belongsTo `MembershipApplication` or `Membership` (polymorphic — a narrow, deliberate exception, see `12_AUDIT_LOGGING_ARCHITECTURE.md`) |
| `AccountSetupToken` | Secure one-time setup token (§7) | belongsTo `User` |

This directly implements the "CONFIRMED Membership Data Model" already recorded in `docs/reverse-engineering/DATABASE.md` and resolves `LEGACY_RISKS.md` §1 (the two disconnected legacy systems) — there is exactly one `MembershipApplication → Membership` relationship, never a second independently-initiated membership table.

## 2. Application statuses & the review workflow

**CONFIRMED REQUIREMENT** (`WORKFLOWS.md` §0.3): exactly four statuses — `submitted`, `more_details_required`, `approved`, `rejected`. No intermediate `under_review` state.

- **Laravel mechanism**: a backed PHP enum `MembershipApplicationStatus` (see `03_LARAVEL_ARCHITECTURE.md` §4), cast on the `MembershipApplication` model via `casts()`. Transitions are only ever performed through `Actions/Membership/ReviewMembershipApplication`, never a direct `->update(['status' => ...])` from a controller — this keeps the "more details required → applicant responds → returns to review" loop (§0.3) as one auditable code path rather than scattered writes.
- **"More Details Required" is a real workflow state**, not just an email (CONFIRMED REQUIREMENT): the applicant-facing side is a Livewire component the applicant can return to (via a link in the M2 notification, `06_NOTIFICATION_ARCHITECTURE.md`) that lets them submit the requested information/documents, which calls `Actions/Membership/RespondToMoreDetailsRequest`, moving the status back toward `submitted` and re-queuing it for admin review.
- **Audit trail**: every transition (who, when, from/to status, and any admin note or applicant response) is written to `MembershipStatusHistory` by a Listener on a `MembershipApplicationStatusChanged` event, not inline in the Action — see `03_LARAVEL_ARCHITECTURE.md` §5.
- **Security considerations**: the review action (`Approve`/`Reject`/`Request More Details`) is authorized via a `MembershipApplicationPolicy::review()` method restricted to the `admin` role (`05_AUTHORIZATION_ARCHITECTURE.md`) — server-side only, correcting the legacy's client-side-only `AdminLayout` gate.
- **Data integrity considerations**: the status transition and its history entry are written in the same database transaction so a crash never leaves a status change unaudited.

**CONFIRMED REQUIREMENT** (`WORKFLOWS.md` §0.5): aviation proof must be reviewed by an administrator before approval is possible. **Laravel mechanism**: `MembershipApplicationPolicy::approve()` (or a guard clause inside the review Action) refuses an `approve` decision if the application's proof documents have not been marked reviewed — modelled as a `proof_reviewed_at`/`proof_reviewed_by` pair on the application (set implicitly the first time an admin opens the review screen, or explicitly via a "mark reviewed" affordance — exact UX is a DATABASE DESIGN/UI decision, not fixed here).

## 3. Reapplication after rejection

**CONFIRMED REQUIREMENT** (`WORKFLOWS.md` §0.14): reapplication allowed; default cooldown 30 days, admin-configurable; every historical application retained, never overwritten; a new application undergoes the full process again.

- **Laravel mechanism**: the cooldown value lives in `config/membership.php` (`'reapplication_cooldown_days' => 30`), overridable via an admin-editable `SiteSetting`-equivalent value at runtime if ACI wants it changeable without a deploy (ARCHITECTURAL DECISION — config file as the default source, with an optional DB-backed override read at request time via `config()` merged from a cached settings row; exact mechanism is a DATABASE DESIGN detail, not fixed here). The duplicate-check (`Actions/Membership/CheckApplicationEligibility` or a custom Form Request validation rule) queries the applicant's most recent `rejected` application by email/mobile and blocks only if it falls inside the cooldown window — replacing the legacy's permanent, non-status-aware block (`VALIDATION.md`'s resolved gap).
- **Data integrity considerations**: because applications are never overwritten, a `MembershipApplication` row is immutable once terminal (`approved`/`rejected`) except for the fields the workflow itself updates (e.g. `membership_number` on approval) — a fresh application after cooldown is always a **new row**, so historical review data is never lost.

## 4. Membership number — authoritative generation

**CONFIRMED REQUIREMENT** (`WORKFLOWS.md` §0.11, non-negotiable): exactly 9 characters, `C+YY+RR+SSSS` (category letter, 2-digit activation year, 2 random digits that never affect the sequence, 4-digit **per-category** sequential number); generated only at activation; never consumed by a rejected or payment-pending application; must be unique; must be generated safely and transactionally, never via `MAX()+1`.

**ARCHITECTURAL DECISION — sequence mechanism**: a dedicated `membership_number_sequences` table with one row per `(category, year)` pair, holding the next integer to issue, updated via `SELECT ... FOR UPDATE` inside the same database transaction as the membership activation.

```
Actions/Membership/GenerateMembershipNumber:
  DB::transaction(function () use ($category, $year) {
      $sequence = MembershipNumberSequence::where('category', $category)
          ->where('year', $year)
          ->lockForUpdate()
          ->first();                      // or firstOrCreate, seeded at 0, inside the same lock
      $next = $sequence->next_value + 1;
      $sequence->update(['next_value' => $next]);
      $random = random_int(0, 99);        // cosmetic only, per §0.11 — never used for uniqueness
      return sprintf('%s%02d%02d%04d', $category, $year % 100, $random, $next);
  });
```

- **Why appropriate for ACI**: a locked-row counter table is the standard, well-understood way to get a gap-free-enough, collision-free, per-key sequence in MySQL without relying on `AUTO_INCREMENT` (which is global, not per-category) or an application-level retry loop racing on a `UNIQUE` constraint (which works but wastes a generation attempt per collision under concurrency — unlikely at ACI's scale, but the locked-counter approach is no more complex and removes the retry logic entirely).
- **Laravel mechanism**: `lockForUpdate()` inside `DB::transaction()`, exactly as Laravel's own documentation recommends for this class of problem; the final number is validated against a `UNIQUE` column constraint on `memberships.membership_number` as a defence-in-depth backstop, not the primary uniqueness guarantee.
- **Security considerations**: none specific — the number is not a secret (it's shown on the digital card), only its *forgeability* matters, which the sequence+uniqueness design addresses.
- **Data integrity considerations**: the lock is held only for the duration of the increment, inside the same transaction that also activates the membership, so a crash between "number generated" and "membership activated" cannot happen — both commit together or neither does.
- **Alternatives considered**:
  1. *`MAX(sequence) + 1` computed per category at generation time* — explicitly rejected by ACI's own requirement ("Do NOT use MAX()+1") because it is not safe under concurrent activations (two transactions can read the same MAX before either commits).
  2. *A single global `AUTO_INCREMENT` column, with the category letter as a display-only prefix computed separately* — rejected: this would not give each category its own sequence (a Student and a Professional activated back-to-back would consume from the same counter), contradicting the confirmed "independent per-category" requirement.
  3. *Optimistic retry on a `UNIQUE` constraint violation* (generate a candidate number, insert, catch a duplicate-key error, retry) — considered viable but rejected in favor of the locked-counter approach for simplicity and because it avoids a retry loop in a workflow that also needs to be transactionally consistent with several other writes (status update, history entry).

## 5. Promotion eligibility resolution

**CONFIRMED REQUIREMENT** (`WORKFLOWS.md` §0.6): multiple promotions may exist and be simultaneously active; each defines its own applicable categories; a membership receives **at most one** promotion, selected **deterministically** via a configurable priority/order when more than one is eligible; eligibility is evaluated **at activation time**; the selected promotion is recorded for audit; no stacking without a future explicit rule change.

- **Laravel mechanism**: `Actions/Membership/ResolveEligiblePromotion($category, now())` queries `MembershipPromotion::where('is_active', true)->where('start_date', '<=', $now)->where('end_date', '>=', $now)->whereJsonContains('applicable_categories', $category)->orderBy('priority')->first()` — the single highest-priority match wins; `null` means no promotion applies (paid path). This Action is called from `Actions/Membership/ActivateMembership`, never earlier, per the confirmed activation-time-eligibility rule.
- **Data integrity considerations**: `Membership.membership_promotion_id` is a nullable FK, set exactly once at activation and never changed afterward (even if the promotion is later deactivated or expires) — this is what makes the "6 months run their full course regardless of the campaign window" rule (§0.6 worked example) correct: the membership's own `expires_on` is computed once, from the promotion's `free_duration_months` *at the moment of activation*, not recomputed from the promotion's live state later.
- See `02_DOMAIN_ARCHITECTURE.md` §3 for the `MembershipPromotion` entity's field list (name, active flag, start/end date, free-membership flag, free-duration-months, applicable categories, priority).

## 6. Payment path integration

**CONFIRMED REQUIREMENT** (`WORKFLOWS.md` §0.7–§0.9): free path skips payment entirely (`payment_not_required`, never a fabricated £0 record); paid path requires a payment reference **and** evidence document, admin-verified, with a `payment_rejected` outcome looping back to `payment_pending` (never restarting the whole application).

This domain does not implement payment processing itself — it consumes the Payments domain (`08_PAYMENT_ARCHITECTURE.md`). The integration points:

- On Approve, `Actions/Membership/ResolveEligiblePromotion` runs first; if it returns a promotion, the application proceeds straight to `Actions/Membership/ActivateMembership` with no `Payment` row created at all (not even with a zero amount — the *absence* of a `Payment` row, combined with the membership's own `payment_status = payment_not_required`, is how "free" is represented, exactly as `WORKFLOWS.md` §0.7 requires).
- If no promotion applies, a `Payment` row is created (`membership_id` set, `order_id` null, `status` starting at the Payments domain's own pending state) and the `MembershipApplication`/`Membership`'s own **business-facing** `payment_status` (the 5-value enum: `payment_not_required`/`payment_pending`/`payment_confirmation_submitted`/`payment_confirmed`/`payment_rejected`) tracks the membership-specific workflow state, kept in sync with (but distinct from) the `Payment` record's own gateway-facing status — see `08_PAYMENT_ARCHITECTURE.md` §3 for why these two statuses are deliberately not the same enum.
- `Actions/Membership/ActivateMembership` is triggered by either: (a) `ResolveEligiblePromotion` returning a match, or (b) a `PaymentConfirmed` event (Payments domain) whose `Payment.membership_id` points at this application's not-yet-activated membership record. Both paths converge on the same Action, so activation logic (number generation, expiry calculation, welcome/setup emails, card availability) is written once.

## 7. Secure account setup (replaces legacy plaintext temp passwords)

**CONFIRMED REQUIREMENT** (`WORKFLOWS.md` §0.13): no permanent/plaintext passwords by email; a secure, time-limited, single-use setup token/link; the applicant sets their own password; the token is invalidated after use.

- **Laravel mechanism**: model this as its own `AccountSetupToken` table (not simply reusing Laravel's built-in `password_reset_tokens`, because that table's semantics assume an existing account with a password to *reset* — here the account may not exist yet at the point the token is issued). Store only a **hash** of the token (`hash('sha256', $plainToken)`), never the plaintext, mirroring exactly how Laravel's own password broker stores reset tokens. The email link carries the plaintext token once; on visiting the link, the applicant is shown a "create your password" form; on successful submission, the `User` account is created (or, for the rare pre-existing-account case, see §8) with the applicant's chosen password (`Hash::make()`), and the token row is deleted (single-use, invalidated after use).
- **Security considerations**: token generation uses `Str::random(40)` or equivalent CSPRNG (matching the legacy's already-good practice of `crypto.getRandomValues` for its temp password, just applied to a setup token instead of a password); the token has a short expiry (config-driven, e.g. 24–72 hours — TBC, see `16_OPEN_DECISIONS.md`); expired or already-used tokens are rejected with a generic "this link is no longer valid" message (no information disclosure about *why*).
- **This directly closes** `AUTHORIZATION.md` §6 items 2, 4, 7, and 9 and `LEGACY_RISKS.md` §2 item 4 — no password ever appears in an email; there is no `must_change_password` flag to forget to enforce, because the applicant never has a system-assigned password to change away from in the first place.
- **Alternatives considered**: reusing Laravel Fortify's/Breeze's existing password-reset broker unmodified — rejected as the sole mechanism because it assumes the target `User` row already exists with a (possibly random, unusable) password; a purpose-built setup-token flow better matches "the applicant creates their own password" as the very first credential the account ever has, with no intermediate system-generated secret to leak.

## 8. The pre-existing-account edge case

`AUTHORIZATION.md` §6 item 9 flagged the legacy app's account-takeover risk: approving an application whose email already has an account silently reset that account's password on an email-string match alone. **ARCHITECTURAL DECISION** (not a silent inheritance of the legacy behaviour, and not explicitly resolved by ACI's restated business rules, so treated conservatively): if an activation's applicant email already matches an existing `User`, the system does **not** silently reset that account's credentials. Instead, the account-setup token flow (§7) is used to let the *existing* account's owner confirm and link the new membership to their existing login (they must already be able to authenticate as that user, or complete the same secure setup-token flow, before the membership is attached) — this preserves the "never rely on an email string match alone" principle from `05_AUTHORIZATION_ARCHITECTURE.md` without inventing a business rule ACI hasn't stated. Flagged for confirmation in `16_OPEN_DECISIONS.md` since it is an architectural judgment call, not a restated requirement.

## 9. Expiry & renewal

**CONFIRMED REQUIREMENT** (`WORKFLOWS.md` §0.12): expiry calculated from actual activation date; reminders at 30/7/0 days before expiry; no auto-charge, no auto-convert-to-paid; status becomes `expired` if not renewed.

- **Laravel mechanism**: `Membership.expires_on` is computed once at activation (`activated_on + category.duration_months` for paid, or `activated_on + promotion.free_duration_months` for promotional) and stored, never recomputed live. A scheduled job (`11_BACKGROUND_JOBS_ARCHITECTURE.md`) runs daily, queries memberships expiring in exactly 30/7/0 days, and dispatches the M10/M11 notifications (`06_NOTIFICATION_ARCHITECTURE.md`); a separate daily job flips `Membership.status` to `expired` for any membership whose `expires_on` has passed and which was never renewed — this is a pure status-and-notification job, it never touches payment or triggers any charge, per the confirmed "no automatic payment or automatic conversion" rule.
- **Renewal itself** (an expired or expiring member starting a new membership term) is **not fully specified** by the confirmed rules (exact renewal pricing/workflow is explicitly deferred to architecture per `WORKFLOWS.md` §0.12) — this document proposes that renewal reuses the same `MembershipApplication`-style intake (a returning member "applies" again, possibly pre-filled) rather than inventing a separate renewal-specific data model now; the exact UX is left open — see `16_OPEN_DECISIONS.md`.

## 10. Digital membership card

**CONFIRMED REQUIREMENT** (`WORKFLOWS.md` §0.13, restated in Phase 2 instructions): viewable and downloadable from the member dashboard; shows ACI branding, member name, membership number, category, status, valid-until date, and the company logo; future QR verification may be added later; public verification (if built) exposes only the minimum necessary information.

- **Laravel mechanism**: a Blade view rendering the card (reusable for both on-screen display and PDF export), and a PDF generation library bound behind a small `MembershipCardRenderer` (not a full "service layer," just the one integration point — see `13_INTEGRATION_ARCHITECTURE.md` for the specific package choice). The card is generated **on demand** at request time from the `Membership` row's current data (not pre-rendered and cached as a static file) so that a status change (e.g. later suspension, if ACI ever adds that) is reflected immediately without needing to regenerate/invalidate a cached artifact — ARCHITECTURAL DECISION, revisit only if card generation becomes a measurable performance concern (unlikely at ACI's scale).
- **Future QR verification**: the architecture leaves room for this (a `verification_token` column on `Membership`, unique + indexed, unused until the feature is actually built) without building the public verification endpoint now — matches "suitable for future QR membership verification" in the Phase 2 quality requirements without inventing the feature itself. If/when built, `WORKFLOWS.md` §0.15's rule (expose only the minimum necessary information — no full name/PII beyond what's needed to confirm validity) governs its design.

## 11. Summary status model

```
MembershipApplication.status:  submitted ─┬─▶ more_details_required ─┐
                                            │◀────────────────────────┘
                                            ├─▶ approved
                                            └─▶ rejected  ──(30-day cooldown, configurable)──▶ new application allowed

On approved:
  ResolveEligiblePromotion(category, now)
    ├─ match found  ─▶ payment_status = payment_not_required ─▶ ActivateMembership
    └─ no match     ─▶ payment_status = payment_pending
                          │
                          ▼ applicant submits reference + evidence
                       payment_status = payment_confirmation_submitted
                          │
                 admin verifies
                    ├─ reject ─▶ payment_status = payment_pending (resubmit; application NOT restarted)
                    └─ confirm ─▶ payment_status = payment_confirmed ─▶ ActivateMembership

ActivateMembership:
  GenerateMembershipNumber (per-category, transactional)
  → set Membership.status = active, start_date = today, expires_on = computed
  → record applied promotion (if any)
  → write MembershipStatusHistory
  → issue AccountSetupToken, send account-setup + welcome notifications
  → digital card becomes available
```

*Per the Phase 2 restriction: conceptual design only. No migrations, models, Actions, or other code exist yet.*
