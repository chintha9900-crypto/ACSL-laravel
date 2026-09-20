# 08 — Notification Schema

Design only. Tables: `notifications` (Laravel database channel — in-app inbox), `email_templates` (admin-manageable content), `email_logs` (delivery log). Three separate concerns; **no generic EAV/"message" super-table**.

Legend: **N** = `NOT NULL`, **Y** = nullable. FKs `ON UPDATE RESTRICT`.

## 1. Concern split

| Concern | Table | Why separate |
|---|---|---|
| In-app inbox, read/unread | `notifications` | Members read it; `read_at` drives the unread count |
| What an email says (editable content) | `email_templates` | Manageable without a deploy (Phase 3 requirement) |
| What was actually sent to whom, and did it succeed | `email_logs` | Operational evidence; recipients are often **not** users (applicants, enquirers, invitees) |

Queue failure records stay in Laravel's `failed_jobs`. `email_logs` is the business-readable view of the same events.

## 2. `notifications` — KEEP (framework-standard shape)

The table Laravel's `database` notification channel writes and `Notifiable::notifications()` reads. Replaces legacy `notifications` (`is_read` boolean → `read_at`).

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id` | CHAR(36) | N | — | PK — UUID (framework contract) |
| `type` | VARCHAR(255) | N | — | Notification class (or morph alias) — the "notification type" |
| `notifiable_type` | VARCHAR(255) | N | — | Always the `user` morph alias (see below) |
| `notifiable_id` | BIGINT UNSIGNED | N | — | `users.id` |
| `data` | TEXT (JSON) | N | — | `{title, message, action_url, related public_id …}` produced by the Notification class. **Never** contains secrets, tokens or bank details. |
| `read_at` | TIMESTAMP | Y | NULL | **NULL = unread.** No `is_read` boolean anywhere. |
| `created_at`, `updated_at` | TIMESTAMP | Y | NULL | |

Indexes: `(notifiable_type, notifiable_id, read_at)` — the unread-count query (replaces the framework default `(notifiable_type, notifiable_id)`; covers "unread for user" and "all for user ordered"); `(created_at)` for retention pruning.

**Polymorphism — justified, narrow.** `notifiable_*` is Laravel's framework contract. To keep it from becoming a general polymorphic relation: only `User` is ever notifiable (enforced with `Relation::enforceMorphMap(['user' => User::class])` so only the alias `user` is written), and admin/contact-mailbox notifications are **email-only** (not database rows). This is the second and last polymorphic pair in the schema (the first being `audit_logs.subject_*`).

FK: none possible on a polymorphic key. Integrity is restored by the fact that users are never hard-deleted. Soft delete: NO. Audit: none needed (member-facing convenience data).

**In-app vs email:** which of M1–M11 also create a `notifications` row is a per-class choice at implementation time (`06_NOTIFICATION_ARCHITECTURE.md` §4); the table does not constrain it.

**Fixes the legacy bug** (unread count over only the newest 5): `unread = COUNT(*) WHERE notifiable_type='user' AND notifiable_id=? AND read_at IS NULL`, answered from the composite index.

## 3. `email_templates` — REWORK (built, unlike Phase 2's default; C3 in `01`)

Admin-manageable subject and body for the confirmed membership emails M1–M11 and the referral invitation. Branding (logo/header/footer) stays in a shared **Blade layout component**; only the *content* is data.

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id` | BIGINT UNSIGNED AI | N | — | PK |
| `template_key` | VARCHAR(100) | N | — | **UNIQUE**, immutable machine key, e.g. `membership.application_submitted`. Code refers to templates only by key. |
| `reference_code` | VARCHAR(10) | Y | NULL | **UNIQUE** business code `M1`…`M11` where one exists |
| `name` | VARCHAR(150) | N | — | Admin-facing title |
| `description` | VARCHAR(500) | Y | NULL | When it is sent; which placeholders are available |
| `subject` | VARCHAR(255) | N | — | May contain placeholders |
| `body` | MEDIUMTEXT | N | — | Plain text/limited-Markdown with placeholders. Sanitised on save. |
| `is_active` | TINYINT(1) | N | 1 | A *mandatory* template (e.g. M4) cannot be deactivated — application rule |
| `updated_by_user_id` | BIGINT UNSIGNED | Y | NULL | FK → `users` RESTRICT |
| `created_at`, `updated_at` | TIMESTAMP | Y | NULL | |

**Template key catalogue** (seeded in the implementation phase; keys are stable, bodies are editable):

| Ref | `template_key` | Trigger |
|---|---|---|
| M1 | `membership.application_submitted` | application submitted |
| M2 | `membership.more_details_requested` | admin requests more details |
| M3 | `membership.application_rejected` | rejected |
| M4 | `membership.approved_free_promotion` | approved + promotion applies (must render the promotion's actual free duration) |
| M5 | `membership.approved_payment_required` | approved, payment required (renders fee + active bank account at send time) |
| M6 | `payment.confirmation_submitted` | evidence submitted |
| M7 | `payment.confirmation_rejected` | evidence rejected (resubmission possible) |
| M8 | `membership.welcome` | activated |
| M9 | `membership.account_setup` | activated (setup link; may be merged with M8) |
| M10 | `membership.renewal_reminder` | 30/7/0 days before `expires_on` |
| M11 | `membership.expired` | expired |
| — | `referral.invitation` | member sends an invite |

Not templated (remain Blade views): contact-enquiry admin alert and order-placed admin alert (internal mail, no editing need).

**Security rule (critical):** template bodies are stored data and are **never compiled as Blade/PHP**. Rendering uses an allow-listed `{{ placeholder }}` substitution over a fixed variable set per key; unknown placeholders render empty; values are escaped. Compiling database content as Blade would be a server-side template-injection route for anyone with template-edit access. Rich per-locale variants are **DEFER** (single language today).

Indexes: `UNIQUE(template_key)`, `UNIQUE(reference_code)`, FK index on `updated_by_user_id`. Soft delete: **NO** — the key set is fixed by code; there is no create/delete UI, only edit. **History:** edits are audit-logged with old/new values (`email_template.updated`), which is the version history.

## 4. `email_logs` — REWORK (new; the legacy had none)

Operational record of each outbound email. Written from Laravel's `NotificationSent` / `NotificationFailed` / `MessageSent` events.

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id` | BIGINT UNSIGNED AI | N | — | PK |
| `to_email` | VARCHAR(255) | N | — | The address used (lower-cased) |
| `to_name` | VARCHAR(160) | Y | NULL | |
| `user_id` | BIGINT UNSIGNED | Y | NULL | FK → `users` — set when the recipient is a user |
| `template_key` | VARCHAR(100) | Y | NULL | **Snapshot of the key**, not an FK (a template's key never changes, but the log must survive any future template reorganisation). NULL for Blade-only mails. |
| `subject` | VARCHAR(255) | N | — | The **rendered** subject as sent. Bodies are **not** stored (PII/size); the template + the payment/order record reconstruct what was communicated. |
| `status` | VARCHAR(20) | N | `'queued'` | `queued`, `sent`, `failed`. `sent` = accepted by the SMTP server; SMTP provides no delivery receipt, so **`delivered`/`bounced` are not modelled** (DEFER until a provider with webhooks is adopted). |
| `attempts` | SMALLINT UNSIGNED | N | 0 | |
| `error_message` | VARCHAR(1000) | Y | NULL | Truncated transport error; no credentials |
| `membership_application_id` | BIGINT UNSIGNED | Y | NULL | FK — typed link for the dominant use ("was M5 sent for this application?") |
| `order_id` | BIGINT UNSIGNED | Y | NULL | FK — order emails |
| `queued_at` | TIMESTAMP | N | CURRENT_TIMESTAMP | |
| `sent_at` | TIMESTAMP | Y | NULL | |
| `failed_at` | TIMESTAMP | Y | NULL | |
| `created_at`, `updated_at` | TIMESTAMP | Y | NULL | |

Two typed nullable FKs (not polymorphic) cover the only entities for which "did the email go out" is an operational question. Emails for other entities are found by recipient + template + time. Adding a third link later is an additive column.

| FK | Parent | Cardinality | ON DELETE |
|---|---|---|---|
| `user_id` | `users` | many : 0..1 | RESTRICT |
| `membership_application_id` | `membership_applications` | many : 0..1 | RESTRICT |
| `order_id` | `orders` | many : 0..1 | RESTRICT |

Indexes: `(membership_application_id, template_key)`, `(to_email, created_at)`, `(status, queued_at)` (failed-email sweep), FK indexes on `user_id`, `order_id`. `CHECK (status IN ('queued','sent','failed'))`. Soft delete: **NO**. Retention: contains third-party addresses (referral invitees, enquirers) → retention policy open (OD-11, OD-15).

## 5. Explicitly not designed

A `notification_preferences` table (no confirmed preference feature); an SMS/push channel table; an outbox table (Laravel's queue is the outbox); per-notification "recipient list" tables; storing rendered bodies.
