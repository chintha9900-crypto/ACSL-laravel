# 09 — Audit Schema

Design only. One table: `audit_logs`. Together with `membership_status_history` (`04`) and `order_status_history` (`12`) it forms the audit/history layer:

| Mechanism | Scope | Shape |
|---|---|---|
| `membership_status_history` | Application-stage and member/term/payment lifecycle facts | typed FKs (application / member / term), no polymorphism |
| `order_status_history` | Order lifecycle facts for one order | typed FK |
| **`audit_logs`** | Cross-domain "who did what" for administrative and security-sensitive actions | the **one approved polymorphic exception** (ADR-15) |

Application/operational logging (exceptions, failed jobs) stays in Laravel log channels and `failed_jobs`, not here.

Legend: **N** = `NOT NULL`, **Y** = nullable.

## 1. `audit_logs` — CONFIRMED

Append-only. No `updated_at`, no soft delete, no delete path in the application.

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id` | BIGINT UNSIGNED AI | N | — | PK. Also the ordering tiebreaker for events in the same second. |
| `user_id` | BIGINT UNSIGNED | Y | NULL | FK → `users` (actor). NULL = system/scheduler/webhook, or an unauthenticated actor. |
| `actor_type` | VARCHAR(20) | N | `'user'` | `user`, `system`, `guest`; `CHECK IN` those. Lets "system" and "anonymous applicant" be distinguished when `user_id` is NULL. |
| `event` | VARCHAR(100) | N | — | Dotted machine name, e.g. `membership_application.reviewed`, `payment.confirmed`, `user.role_changed`, `document.viewed`. Open vocabulary (grows; no CHECK). |
| `subject_type` | VARCHAR(100) | Y | NULL | Morph alias of the affected model (`membership_application`, `payment`, `user`, …). NULL for events with no single subject. **Morph map enforced** so raw class names are never stored. |
| `subject_id` | BIGINT UNSIGNED | Y | NULL | Affected row's `id`. **No FK** (polymorphic, and audit rows must outlive/precede any subject). |
| `old_values` | JSON | Y | NULL | Changed attributes before. Sensitive fields redacted **before** storing. |
| `new_values` | JSON | Y | NULL | Changed attributes after |
| `ip_address` | VARCHAR(45) | Y | NULL | IPv4/IPv6 text |
| `user_agent` | VARCHAR(512) | Y | NULL | Truncated |
| `request_id` | CHAR(36) | Y | NULL | UUID assigned per HTTP request/job; correlates several audit rows written by one action and ties them to application logs |
| `created_at` | TIMESTAMP | N | CURRENT_TIMESTAMP | |

**CHECK constraints:** `actor_type IN ('user','system','guest')`; `(subject_type IS NULL) = (subject_id IS NULL)`; `actor_type <> 'user' OR user_id IS NOT NULL`. (No `JSON_VALID` CHECK: the native MySQL `JSON` type already rejects invalid JSON — `18` §3.9.)

**Foreign key**

| FK column | Parent | Cardinality | ON DELETE |
|---|---|---|---|
| `user_id` | `users` | many events : 0..1 actor | RESTRICT (users are never hard-deleted; anonymisation keeps the id) |

**Indexes**

| Index | Columns | Reason |
|---|---|---|
| `audit_logs_subject_index` | `subject_type, subject_id, id` | "everything that happened to this application/payment/user" |
| `audit_logs_user_id_created_at_index` | `user_id, created_at` | "everything this admin did today" (FK index) |
| `audit_logs_event_created_at_index` | `event, created_at` | reports by event type (e.g. all `document.viewed`) |
| `audit_logs_created_at_index` | `created_at` | retention pruning, recent-activity feed |

## 2. What is logged (schema-relevant summary; full list in `12_AUDIT_LOGGING_ARCHITECTURE.md` §2)

Application decisions and status changes; payment confirmation/rejection/refund; membership activation (incl. introductory term start), membership-number issue, renewal start/confirmation; role/suspension changes; settings changes (`site_settings`, `membership_settings`, `payment_bank_accounts`, `email_templates`, `membership_plans`) with old/new values; account-setup token lifecycle; **private-document access** (`aviation_proof`, `payment_evidence`) — upload, view, purge; blocked security-sensitive attempts (e.g. last-admin demotion); rejected webhook deliveries.

**Never stored:** passwords or hashes, setup tokens (plain or hashed), `verification_token`, full bank credentials beyond ACI's own published account, file contents. Redaction is applied to `old_values`/`new_values` before insert via a per-model redaction list, not by trusting callers.

## 3. Integrity and transactionality

* Written **inside the same transaction** as the action they record where that action is transactional (activation, payment confirmation): rollback removes the audit row together with the change; commit guarantees both.
* Append-only is an **application rule** (no update/delete code path, no admin UI). MySQL cannot cleanly prevent `UPDATE`/`DELETE` without triggers or a dedicated restricted DB user; the recommended hardening (a database user for the application that lacks `UPDATE`/`DELETE` on `audit_logs`) is compatible with SiteGround only if a second DB user can be created — noted as an optional operations measure, not a design dependency.
* Volume is bounded: views of *sensitive* documents and admin mutations only — not page views, not routine content CRUD. Retention/archival policy is unresolved (OD-11); nothing is deleted by default.

## 4. Considered and rejected

| Alternative | Why not |
|---|---|
| One audit table per domain | Same shape ×N; cross-domain "what did this admin do" needs UNION (ADR-15). |
| Storing a single combined diff column | The confirmed field list has separate `old_values`/`new_values`, and "what was it before" stays directly queryable. |
| `spatie/laravel-activitylog` | Equivalent design; a package-vs-custom choice for implementation (architecture open decision #28), not a schema question. If the package were adopted its own table shape would replace this one, so the choice must be made before the audit migration is written. |
| Polymorphism in `membership_status_history` | Not needed — every event anchors to an application (before activation) or to the member (after), with an optional typed `membership_term_id`. |
