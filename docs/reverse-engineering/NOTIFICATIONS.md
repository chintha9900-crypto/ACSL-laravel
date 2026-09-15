# Notifications (email + in-app)

All outbound email routes through a single module, `src/lib/email.server.ts:1-97`, imported by five feature areas. There is no persisted email log/audit table in use (an `email_templates` table exists in the schema but is **confirmed unused** — every email body is a hardcoded TypeScript template literal, not read from that table — see LEGACY_RISKS.md).

## Email infrastructure

SOURCE: `src/lib/email.server.ts`.
- Fixed sender/reply-to for every email: `Aviation Club International <admin@aviationclub.lk>`.
- `sendAppEmail({to, subject, text, html?, purpose})`: (1) if `SMTP_HOST`/`SMTP_USER`/`SMTP_PASSWORD` env vars are set, sends via `nodemailer` over SMTP (the intended production path — a SiteGround mailbox, directly relevant since ACI's target host **is** SiteGround); (2) else falls back to `@lovable.dev/email-js`, a Lovable-platform-hosted API — **must not be ported, no Laravel equivalent, drop entirely**; (3) if neither is configured, **silently no-ops** (`{sent:false, note:"Email sending is not configured yet..."}`) without throwing.
- Callers generally do **not** surface `sent:false` to the end user — e.g. the membership-apply success screen shows the same "you will receive email notifications" message regardless of whether the email actually sent. This is a real UX gap: if SMTP is misconfigured, users are told they'll be notified but silently aren't.
- `textToHtml()`: naive plain-text→HTML fallback when no explicit HTML body is given.

**Laravel replacement**: Mailable classes per email type below, dispatched via `Mail`/queued `Notification` (`ShouldQueue`) against Laravel's `mail` config (`MAIL_MAILER=smtp`, SiteGround credentials). No Lovable-style fallback needed — Laravel's own failed-job/queue-retry mechanism replaces the silent no-op. Recommend surfacing delivery failure via the failed-jobs table/log rather than silently swallowing it as the reference does, and reconsidering whether checkout/application/enquiry submissions should block on email success (reference never does — flag as a deliberate UX decision to make, not infer).

## Every notification, trigger, and recipient (legacy reference app behaviour)

Rows 2, 3, 4, 5, and 8 below describe the **legacy Lovable app's** membership-related emails/notifications. They are superseded by the CONFIRMED set of membership emails in the section immediately following this table — kept here only as the reverse-engineering record.

| # | Trigger | Mechanism | Recipient | Subject / content | Source |
|---|---|---|---|---|---|
| 1 | Contact form submitted | Email (best-effort, errors swallowed internally so this can't fail the request) | Admin mailbox only — **no confirmation to the enquirer** | `New enquiry[: subject] – {name}`, plain-text dump of the enquiry | `site.functions.ts:90-103` |
| 2 | *(legacy)* Membership pre-application submitted | Email ×2 | (a) Applicant, (b) Admin | (a) "Membership Application Successfully Submitted" (generic, not type-specific); (b) `New membership application – {full_name}` | `membership.functions.ts:193-201`, `membership-review.server.ts:89-94` |
| 3 | *(legacy)* Admin declines a membership application | Email | Applicant | "Aviation Club International – Membership Application Update" — polite rejection, invites re-application | `membership-review.server.ts` (`buildDecisionEmail`, decline branch) |
| 4 | *(legacy)* Admin requests more details on an application | Email | Applicant | "Aviation Club International – Additional Details Required..." — asks applicant to reply-to-email with more info; **no structured re-submission path**, the loop back to review happens out-of-band | `admin.functions.ts:589-727` (`request_details` branch) |
| 5 | *(legacy)* Admin accepts a membership application | Email (HTML) | Applicant | "Welcome to Aviation Club International – Your Membership is Confirmed" — includes **membership number, login email, and a plaintext temporary password**, plus the digital membership card HTML fragment (with embedded QR) | `admin.functions.ts` accept branch, `membership-card.server.ts:1-75` |
| 6 | Referral invite sent | Email | The invited friend (not the inviting member) | "You're invited to join Aviation Club International" — generic apply link, **carries no referrer identifier**, so no attribution is possible | `referral.server.ts:1-33`, `dashboard.refer.tsx` |
| 7 | E-shop order placed | Email (best-effort, wrapped in try/catch — failure never blocks order creation) | Admin mailbox only — **no confirmation to the customer**, anywhere, at any point in the order lifecycle | `New E-Shop order – {customer name}`, plain-text order summary + line items + total | `shop.functions.ts:71-82` |
| 8 | *(legacy)* Admin sets/updates a membership payment link | **In-app notification only (not email)** — insert into `notifications` table | The member (`memberships.user_id`) | Title: "Payment link for your membership", message includes plan name | `admin.functions.ts:157-163` (`adminSetMembershipPayment`) |

**All other admin actions trigger nothing**: comment moderation (approve/reject/delete), enquiry status changes, job-application status changes, blog/news/events/jobs/shop CRUD, subscriber toggle, and user suspend/role-change all notify no one. Whether an author/applicant should be notified on these is a product decision for ACI, not something to infer.

## CONFIRMED ACI membership emails (2026-09-15) — supersedes rows 2–5 and 8 above

Per WORKFLOWS.md §0, the confirmed membership application/activation workflow requires the following notifications. None of these have been implemented (no Mailable/Notification classes exist yet) — this is a requirements record for the ARCHITECTURE phase.

| # | Trigger | Recipient | Required content |
|---|---|---|---|
| M1 | Application submitted | Applicant (+ admin, carrying forward the legacy pattern of also notifying admin — confirm if still wanted) | Confirmation to applicant that the application was received |
| M2 | Admin requests more details | Applicant | What specifically is needed, and how to provide it back into the system (not an out-of-band email reply — see WORKFLOWS.md §0.3) |
| M3 | Admin rejects | Applicant | Decision notice |
| M4 | Admin approves, applicant **qualifies for the free promotion** | Applicant | Must confirm approval and explicitly explain that their first six months (the active promotion's configured free-duration) of membership are free under the ACI introductory promotion — a **CONFIRMED, mandatory** email, distinct in content from M5 below |
| M5 | Admin approves, applicant **does not qualify for the free promotion** | Applicant | Payment instructions: the applicable membership fee and the approved ACI bank/payment details |
| M6 | Applicant submits payment confirmation | Applicant | Confirmation of receipt of the submitted payment evidence (acknowledgement only — activation still awaits admin review) |
| M7 | Admin rejects a submitted payment confirmation | Applicant | Explains payment evidence could not be confirmed and allows resubmission; **CONFIRMED**: payment status returns to `payment_pending` — the application itself is never restarted (WORKFLOWS.md §0.9) |
| M8 | Membership activated (either path) | Applicant | Welcome to Aviation Club International |
| M9 | Membership activated (either path) | Applicant | **Secure one-time account setup link** (not a plaintext temporary password — see AUTHORIZATION.md §2/§6, WORKFLOWS.md §0.13); may be combined with M8 into one email at implementation time |
| M10 | Membership approaching expiry | Member | Renewal reminder — recommended at 30 days, 7 days, and optionally on the day of expiry (WORKFLOWS.md §0.12); must not auto-charge or auto-convert to paid |
| M11 | Membership expiry date reached, not renewed | Member | Notice that the membership has expired |

**Do not build a fake "payment received" email for a free-promotion membership** — the promotional email above is the only communication a qualifying applicant should receive about payment, and it must be clear no payment is due, not a receipt for a £0 charge.

## In-app notifications (`notifications` table)

SOURCE: `src/lib/dashboard.functions.ts:111-149` (list/mark-read/mark-all-read), `admin.functions.ts:157-163` (the only creation site found).
- Members view up to 100 of their own notifications (`dashboard.notifications.tsx`), mark individually or all-at-once as read — both mutations double-scope by `id` AND `user_id` (a member cannot mark another member's notification, backed by RLS).
- **The only code path that ever creates a notification row is the membership payment-link action.** There is no general-purpose in-app notification system wired into any other event in the app (not comment approval, not application decisions, not order status — those are handled by email or nothing).
- The dashboard overview's "unread count" stat is computed **only over the 5 most-recently-fetched notifications**, not a true count of all unread — a bug in the reference app to fix, not reproduce, in Laravel (count all unread directly).

## Security note

Item #5 above (legacy) — a **plaintext temporary password transmitted by email** — is a significant security smell that has now been explicitly closed by the CONFIRMED requirement (WORKFLOWS.md §0.13): a secure one-time account-setup token/link — securely generated, time-limited, single-use, invalidated after use, never exposing a password by email. The legacy mechanism was otherwise well-implemented (CSPRNG-generated, never persisted to any app table) but must not be reproduced as-is.

## UNCERTAIN

- Whether `email_templates` was ever wired up as a real content source, or the hardcoded TS strings are final — code evidence points to the latter, but this was not exhaustively confirmed across every admin surface.
- Whether any persisted audit log of sent emails exists (the `purpose` string looks like an audit/categorization tag but no email-log table was found).
- Exact call site that invokes `sendApplicationDecisionEmail` for the `accept`/`decline` cases beyond what's documented (confirmed via `admin.functions.ts`, but the email-content module itself was only partially read).

*Per the Approval Gate: analysis only, no Laravel Mail/Notification classes have been written.*
