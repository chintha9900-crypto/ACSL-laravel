# 11 — Jobs & Community Schema

Design only. Tables: `job_postings`, `job_applications`, `referral_invitations`. Blog comments are in `10` (Content). Architecture: `17_JOBS_AND_COMMUNITY_ARCHITECTURE.md`.

Legend: **N** = `NOT NULL`, **Y** = nullable. All FKs `ON UPDATE RESTRICT`.

## 1. Naming note

Legacy tables `jobs` / `job_applications`. Laravel's queue uses a table literally named `jobs`; the postings table is therefore **`job_postings`** (matches the architecture's `JobPosting` model). No collision handling or custom queue table name is needed.

## 2. `job_postings` — REWORK (legacy `jobs`)

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id` | BIGINT UNSIGNED AI | N | — | PK |
| `title` | VARCHAR(255) | N | — | |
| `company` | VARCHAR(255) | N | — | |
| `location` | VARCHAR(255) | Y | NULL | |
| `location_type` | VARCHAR(20) | N | `'local'` | `local`, `overseas` — `CHECK (location_type IN ('local','overseas'))`; closed in the legacy schema and kept closed |
| `employment_type` | VARCHAR(50) | Y | NULL | Free text in the legacy schema; a controlled list is undecided |
| `salary` | VARCHAR(100) | Y | NULL | **Display text** (e.g. "Negotiable"), deliberately not numeric — matches legacy behaviour |
| `description` | MEDIUMTEXT | Y | NULL | sanitised HTML |
| `requirements` | MEDIUMTEXT | Y | NULL | sanitised HTML |
| `application_instructions` | MEDIUMTEXT | Y | NULL | sanitised HTML |
| `external_link` | VARCHAR(500) | Y | NULL | "Apply externally" target; when set the in-app apply flow is not offered (application rule) |
| `status` | VARCHAR(20) | N | `'draft'` | `draft`, `published` (legacy set). A `closed` state is not confirmed and not added. |
| `posted_by_user_id` | BIGINT UNSIGNED | Y | NULL | FK → `users`, **SET NULL** |
| `published_at` | TIMESTAMP | Y | NULL | stamped on any transition into `published` |
| `created_at`, `updated_at` | TIMESTAMP | Y | NULL | |

No `slug`: the legacy schema has none and the jobs board is master-detail without per-job URLs. Indexes: `(status, published_at)` (public board), `(location_type, employment_type)` (server-side filtering — the fix for the legacy client-side filtering), FK index on `posted_by_user_id`. Soft delete **NO** — a posting with applications cannot be deleted at all (`job_applications` → `RESTRICT`, §3); unpublish via `status`.

## 3. `job_applications` — CONFIRMED (concept), REWORK

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id` | BIGINT UNSIGNED AI | N | — | PK |
| `job_posting_id` | BIGINT UNSIGNED | N | — | FK → `job_postings`, **RESTRICT** (legacy CASCADE would silently destroy applicants' records when a posting is removed) |
| `user_id` | BIGINT UNSIGNED | N | — | FK → `users`, **RESTRICT** (legacy CASCADE). Applicants are authenticated members. |
| `status` | VARCHAR(30) | N | `'applied'` | Working vocabulary from the admin UI: `applied`, `reviewing`, `shortlisted`, `rejected`, `hired`. The legacy DB enum only had `applied`/`reviewed`, so the UI and schema disagreed; a real backed enum replaces both (architecture `17` §1). **Provisional → no CHECK.** |
| `created_at`, `updated_at` | TIMESTAMP | Y | NULL | `created_at` = applied time |

**Duplicate-application rule:** `UNIQUE(job_posting_id, user_id)` — one application per member per posting (carried from the legacy schema; prevents inappropriate duplicates). Whether a rejected/withdrawn applicant may re-apply is not defined (OD-14); if allowed later, the unique key would be relaxed to a status-conditioned generated key — no data migration needed.

| FK | Parent | Cardinality | ON DELETE |
|---|---|---|---|
| `job_posting_id` | `job_postings` | many : 1 | RESTRICT |
| `user_id` | `users` | many : 1 | RESTRICT |

Indexes: `UNIQUE(job_posting_id, user_id)`, `(user_id, created_at)` (member's list), `(job_posting_id, status)` (admin per-posting review). Soft delete NO. Status changes → `audit_logs` (`job_application.status_changed`); applicant notification on change is not confirmed (architecture OD-list; would use an `email_templates` key, no schema change).

### 3.1 Job-application documents

Stored in the shared `documents` table with `kind = 'job_application_document'` and `job_application_id` set (`07`). Rules that **are** decided: private disk, owner-or-admin access, server-side content validation, uploads audit-logged, files never overwritten. Rules that are **not** decided (OD-14) and therefore **not** encoded in the schema: how many documents, whether any are required, accepted formats/sizes, whether documents may be added after applying, retention. The legacy had no CV/attachment at all, so this is new scope; the schema imposes no minimum and no maximum.

## 4. `referral_invitations` — CONFIRMED concept, minimal shape

Confirmed current scope (`WORKFLOWS.md` / `FEATURES.md` §C): a member sends a plain invitation email; no code, tracking, reward or attribution. The architecture makes it a real table (rather than fire-and-forget) so sent invitations can be listed and abuse-limited. Anything beyond that is **unresolved** (OD-15) and deliberately not designed.

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id` | BIGINT UNSIGNED AI | N | — | PK |
| `referrer_user_id` | BIGINT UNSIGNED | N | — | FK → `users`, RESTRICT |
| `invitee_email` | VARCHAR(255) | N | — | Lower-cased. **Third-party personal data of a non-member** — see privacy note. |
| `created_at` | TIMESTAMP | N | CURRENT_TIMESTAMP | = send time. Append-only; no `updated_at`. |

Indexes: `(referrer_user_id, created_at)` (member history + per-member rate limit), `(invitee_email)` (abuse: many members inviting one address). **Deliberately not unique** on `(referrer, invitee)` — re-inviting a friend is not forbidden by any confirmed rule; rate limiting is an application concern. No status, no `applied_at`, no link to `membership_applications` (that would be the tracked-referral programme, out of scope, architecture OD #20). Soft delete NO.

**Privacy note:** the table retains an email address of someone who has not consented. Options recorded in OD-15: (a) keep as-is with a retention window, (b) store a one-way hash of the invitee address (enough for rate limiting/duplicate detection, no contact list), (c) drop the table and rely on `email_logs` + throttling. The design's default is (a) pending ACI's decision; the table is the *minimum* structure that supports the confirmed "history" reading of "referral contacts", and is **not** an address book.

## 5. Not designed (undefined by ACI — no structure invented)

| Concept | Status | Where it would go |
|---|---|---|
| Referral contacts as an editable address book | Unconfirmed reading (OD-15) | not designed |
| Partners | Undefined (OD-16) | likely a uniform CMS table (`10` §14) |
| Advisory members | Undefined (OD-16); working assumption "team member display variant" | additive `group` column on `team_members` |
| Job categories | Not confirmed as required; `employment_type` + `location_type` cover the legacy filters | — |
| Event registration | Undefined (OD-17) | `10` §14 |
