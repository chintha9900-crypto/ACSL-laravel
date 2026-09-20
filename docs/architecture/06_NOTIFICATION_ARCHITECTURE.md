# 06 — Notification Architecture

Implements every confirmed notification in `docs/reverse-engineering/NOTIFICATIONS.md` (the M1–M11 membership emails, plus the remaining non-membership legacy-pattern admin notifications) using Laravel's built-in Notification system, replacing the legacy app's single hand-rolled `email.server.ts` utility and its silent-failure/Lovable-fallback design.

## 1. Laravel mechanism — Notifications, not ad hoc Mail calls

**ARCHITECTURAL DECISION**: one Laravel `Notification` class per distinct message in the tables below (`app/Notifications/{Domain}/...`), each implementing the `mail` channel (and `database` channel where an in-app equivalent is also needed — see §4), dispatched via `$notifiable->notify(new ...)` or `Notification::send()`, and **queued** (`ShouldQueue`) by default.

- **Why appropriate for ACI**: Notifications are Laravel's purpose-built abstraction for "tell someone something happened," support multiple channels from one class (mail today, database for in-app, SMS/other later if ever needed) without restructuring, and integrate with the queue and failed-job mechanisms for free — directly replacing the legacy's bespoke `sendAppEmail`/`notifyAdmin` utility and its Lovable-platform fallback (dropped entirely, `LEGACY_RISKS.md`).
- **Alternatives considered**: raw `Mail::send()` calls scattered through Actions — rejected: harder to test (`Mail::fake()`/`Notification::fake()` both exist, but Notifications also give the database-channel option for free and keep "what can happen to this user" discoverable in one namespace); a single generic `NotificationService::send($type, $data)` dispatcher — rejected as exactly the kind of generic layer the Phase 2 instructions warn against; one class per notification is barely more code and is self-documenting.
- **Queued by default**: every notification implements `ShouldQueue` so a slow/failed SMTP send never blocks the HTTP request that triggered it — directly fixes the legacy's synchronous, in-request `sendAppEmail` calls (`NOTIFICATIONS.md`).
- **Failure visibility**: a failed send lands in Laravel's `failed_jobs` table (queue) and is logged, rather than the legacy's silent `{sent:false, note:...}` return value that no caller ever surfaced to the end user (`NOTIFICATIONS.md` §"Email infrastructure"). Whether ACI wants a UI-visible "this email may not have sent" indicator anywhere is `TBC` — not assumed here.

## 2. Membership notifications (CONFIRMED REQUIREMENT list — M1–M11, re-scoped by OD-10)

> **Re-scope note (OD-10).** The initial membership is free for the first 6 months for every approved new member, so **no payment email is sent at approval**. M4 is the single approval email for every new member; M5–M7 (payment instructions / acknowledgement / rejection) now belong to **renewal payments**, and M10 is the renewal reminder. The M-numbers are kept for continuity with `NOTIFICATIONS.md`.

| ID | Class (proposed) | Trigger | Notes |
|---|---|---|---|
| M1 | `Membership\ApplicationSubmitted` | `MembershipApplicationSubmitted` event | To applicant; admin copy TBC (legacy pattern, not reconfirmed — see `16_OPEN_DECISIONS.md`) |
| M2 | `Membership\MoreDetailsRequested` | admin review Action | Includes a link back into the system to respond (§`04_MEMBERSHIP_ARCHITECTURE.md` §2), never "reply to this email" |
| M3 | `Membership\ApplicationRejected` | admin review Action | |
| M4 | `Membership\ApprovedIntroductory` | approval and the payment/free decision for **every** new member (activation follows; M8/M9 are sent at activation) | Confirms approval and that the member's **first N months are free** (N read from the introductory term/`membership_settings` — never hard-coded "6 months" text). Not a promotion email. Mandatory. |
| M5 | `Membership\RenewalPaymentInstructions` | member starts a renewal (`StartRenewal`) | **Re-scoped:** renewal fee (from the active plan) + `BankAccountDetails` read at send time (`08_PAYMENT_ARCHITECTURE.md` §5). Not sent at approval. |
| M6 | `Payments\ConfirmationSubmitted` | `SubmitPaymentEvidence` Action | Acknowledgement of **renewal** payment evidence only |
| M7 | `Payments\ConfirmationRejected` | `RejectPayment` Action | States resubmission is possible; the renewal is not restarted |
| M8 | `Membership\Welcome` | `MembershipActivated` event | |
| M9 | `Membership\AccountSetup` | `MembershipActivated` event | Carries the one-time setup link (`04_MEMBERSHIP_ARCHITECTURE.md` §7); **may be merged into one email with M8** at implementation time — kept as two logical messages here so the setup-link content isn't accidentally dropped if M8's copy is edited later |
| M10 | `Membership\RenewalReminder` | scheduled job, **configurable** offsets before the current term's `expires_on` (recommended 30/7/0 days) | Parameterized by days-remaining; explains the renewal fee and how to renew; **never implies auto-charge or automatic renewal** |
| M11 | `Membership\Expired` | scheduled job, when the last term expires without a confirmed renewal | Notice that the term has expired; the membership number is retained |

## 3. Non-membership notifications (carried forward from legacy scope, not newly invented)

| Trigger | Recipient | Notes |
|---|---|---|
| Contact form submitted | Admin | No confirmation to the enquirer in the legacy app (`NOTIFICATIONS.md` #1) — kept as-is unless ACI asks for one; adding one is a product decision, not assumed here |
| E-shop order placed (once Commerce is built) | Admin | Legacy sent no customer confirmation either — same treatment; `09_ECOMMERCE_ARCHITECTURE.md` doesn't mandate adding one |
| Referral invite sent | The invited friend | Plain invite only, matches current confirmed scope (`17_JOBS_AND_COMMUNITY_ARCHITECTURE.md` §3) |

Whether ACI wants customer/enquirer-facing confirmations added to either of the first two is recorded as a `TBC` enhancement in `16_OPEN_DECISIONS.md`, not silently added or silently omitted.

## 4. In-app notifications (database channel)

**ARCHITECTURAL DECISION**: use Laravel's built-in `notifications` table (the `database` notification channel) as the in-app equivalent, replacing the legacy `notifications` table 1:1 in purpose. Any Notification class that should also show up in the member dashboard's notification list implements both `mail` and `database` channels — e.g. `Membership\ApprovedFreePromotion` might be email-only, while a future "payment link updated" style notice would be `database`-only or both, exact choice per notification at implementation time.

- **Fixes the legacy bug**: the legacy dashboard's "unread count" only checked the 5 most-recently-fetched rows, not a true total (`FEATURES.md` §C). The Laravel rebuild queries `->unreadNotifications()->count()` (an indexed count query against the full set), not a paginated slice.

## 5. Mail delivery configuration

- SMTP via a SiteGround mailbox, configured through Laravel's standard `config/mail.php` / `.env` (`MAIL_MAILER=smtp`, `MAIL_HOST`, etc.) — the one piece of the legacy stack already shaped correctly for this target (`NOTIFICATIONS.md`, `SUPABASE.md`).
- No Lovable-platform fallback path is carried forward (dropped entirely, per `LEGACY_RISKS.md`).
- From/reply-to address: **ARCHITECTURAL DECISION** — read from `config('mail.from')`, itself sourced from an admin-configurable value (likely `SiteSetting.contact_email` or a dedicated mail-from setting) rather than the legacy's hard-coded `admin@aviationclub.lk` constant in TypeScript source — so ACI can change the sending address without a code deploy.
- `email_templates` (the legacy table that existed but was never actually used — every email was a hardcoded TS string, `NOTIFICATIONS.md`): **not built** in this design. Every notification's content lives in a Blade mail view (`resources/views/emails/...`), which is Laravel's standard, version-controlled approach — building a DB-backed, admin-editable template system is a separate, larger feature that would need its own confirmation from ACI (recorded in `16_OPEN_DECISIONS.md`), not assumed as part of this rebuild.

## 6. Notification content & rendering

- Each Mailable/Notification's mail representation uses a Blade view under `resources/views/emails/{domain}/...`, sharing a common layout (ACI branding header/footer) via a Blade component, so a branding change (logo, colours) is a one-file edit, not N edits.
- The digital membership card embedded inline in the legacy's acceptance email (`membership-card.server.ts`) is **not** reproduced as an email-embedded HTML card — per `04_MEMBERSHIP_ARCHITECTURE.md` §10, the card lives in the member dashboard (view + download); M8/M9 link there rather than embedding the card itself, simplifying the email and avoiding inconsistent rendering across email clients that plagued the legacy's inline-HTML-table approach (an incidental but real improvement, not a stated requirement — noted as ARCHITECTURAL DECISION).

*Per the Phase 2 restriction: conceptual design only. No Notification/Mailable classes or mail views exist yet.*
