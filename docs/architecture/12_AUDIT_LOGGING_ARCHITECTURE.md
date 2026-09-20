# 12 — Audit & Activity Logging Architecture

Two distinct, deliberately separate audit mechanisms — do not conflate them:

1. **`MembershipStatusHistory`** — a rich, domain-specific audit trail scoped to one entity's lifecycle (`04_MEMBERSHIP_ARCHITECTURE.md` §2), capturing exactly the fields that lifecycle needs (from-status, to-status, admin note, applicant response).
2. **`AuditLog`** (this document) — a generic, cross-domain record of administrative and security-sensitive actions, for the actions that don't warrant their own dedicated history table.

## 1. `AuditLog` entity

**Fields (conceptual, matching the Phase 2 instructions' explicit field list)**: `user_id`/`actor_id` (the `User` who performed the action, nullable for system-initiated actions), `action` (a short machine-readable string, e.g. `membership.approved`, `user.role_changed`, `payment.confirmed`), `entity_type` + `entity_id` (`subject_type`/`subject_id` — polymorphic reference to the affected model, §1a below), `old_values` (JSON, nullable), `new_values` (JSON, nullable), `ip_address`, `user_agent`, `created_at`. Kept as **two** JSON columns (`old_values`/`new_values`) rather than one combined diff blob, matching the Phase 2 instructions' explicit field list and making "what did this used to be" queryable without parsing a diff structure.

**ARCHITECTURAL DECISION — the one deliberate, scoped polymorphic relation in this system**: `AuditLog.subject` is a `morphTo` relation. This is a considered exception to the "do not make everything polymorphic" principle, justified because audit logging is a recognized, narrow, legitimate use case for it (an audit log genuinely needs to reference "any kind of thing," and `DATABASE.md` itself already recommended exactly this pattern — a `spatie/laravel-activitylog`-style morph — when documenting the legacy `activity_logs` table's `entity`/`entity_id` columns). No other part of this system uses a polymorphic relation.

- **Alternatives considered**: a separate audit table per domain (`membership_audit_log`, `payment_audit_log`, ...) — rejected: duplicates the same shape N times for no benefit, and makes "show me everything this admin did today" (a genuinely useful cross-domain admin report) require a UNION across many tables instead of one query; a dedicated package (`spatie/laravel-activitylog`) — a reasonable alternative, left as an implementation-time choice (either the package or a small custom model achieves the same design) — recorded in `16_OPEN_DECISIONS.md` as a package-vs-custom decision, not a design question.

## 2. What gets logged (CONFIRMED REQUIREMENT categories, restated from `WORKFLOWS.md` §0.16 and the Phase 2 security instructions)

- Application status changes and admin decisions (also captured richly in `MembershipStatusHistory` — an `AuditLog` entry is still written for the generic "an admin did X" view, e.g. `membership_application.reviewed`).
- Payment confirmation/rejection decisions.
- Membership activation and membership-number generation.
- Which promotion was applied to a membership.
- Role grants/revocations (`user.role_changed`) and account suspend/activate (`user.suspended`/`user.activated`) — important account changes.
- Settings changes (`site_settings.updated`, `payment_instructions.updated` — the bank/payment-details entity, `08_PAYMENT_ARCHITECTURE.md` §5) — logged with `old_values`/`new_values` so a change to, say, the published bank account number is traceable to a specific admin and moment.
- Refunds (`payment.refunded`, `08_PAYMENT_ARCHITECTURE.md` §1) — amount, reason, and which admin authorized it.
- Document access, where appropriate (aviation proof and payment evidence specifically — see `07_FILE_STORAGE_ARCHITECTURE.md` §6 for the scoping rationale; not every document view in the system, to avoid drowning the log in noise).
- Admin CRUD on content where it matters for accountability — lower-stakes CRUD like a testimonial edit is not necessarily audit-logged unless ACI wants full coverage; exact scope of "which CRUD actions get logged" is an implementation-time tuning question, not fixed here.
- Failed/rejected security-sensitive attempts worth knowing about (e.g. an attempt to revoke the last admin, blocked by the `UserPolicy` guard) — logged as a blocked-attempt entry, not silently swallowed.

**Explicitly never logged**: passwords (hashed or plain), raw payment card/bank credentials, or the full contents of a private document — per the Phase 2 instruction "avoid logging sensitive secrets or passwords." Where an action's `old_values`/`new_values` would otherwise include such a field, that field is redacted/omitted from the logged JSON, not merely relied upon to "not come up."

## 3. Data integrity considerations

- `AuditLog` rows are **append-only** — no update/delete path exists in the application for a written entry (mirrors "do not overwrite important historical events where an audit trail is required," `WORKFLOWS.md` §0.16).
- Written in the **same transaction** as the action it records wherever the action itself is transactional (e.g. membership activation), via the Events/Listeners pattern (`03_LARAVEL_ARCHITECTURE.md` §5) — a Listener on the domain event writes the `AuditLog` row before the outer transaction commits, so a rollback of the business action also rolls back its own audit entry (there is nothing to audit if the action didn't actually happen), while a successfully committed action is guaranteed to have its audit trail.

## 4. Security considerations

- `AuditLog` (and `MembershipStatusHistory`) are viewable only by admins (a dedicated Policy), and their content must itself avoid storing sensitive raw values unnecessarily (e.g. log "payment status changed to confirmed," not the full payment evidence document content inline — the document itself stays in private storage, referenced by id).
- Retention: no confirmed retention/deletion policy exists — recorded in `16_OPEN_DECISIONS.md`.

## 5. Application-level (non-database) logging

Standard Laravel `Log` facade / channels (`config/logging.php`) for operational/error logging (exceptions, failed jobs, webhook processing errors) — see `14_ERROR_VALIDATION_ARCHITECTURE.md`. This is distinct from `AuditLog` (business/security events) and should not be conflated with it: application logs are for developers debugging the system; `AuditLog` is for admins/compliance reviewing what happened in the business.

*Per the Phase 2 restriction: conceptual design only. No `AuditLog` model, migration, or logging code exists yet.*
