# 03 — Identity Schema

Design only. Tables: `users`, `account_setup_tokens`. Framework tables `password_reset_tokens` and (if the database session driver is chosen) `sessions` keep Laravel's standard shape and are not redesigned.

Legend for column tables: **N** = `NOT NULL`, **Y** = nullable.

## 1. Roles and authorisation data

Approved architecture (ADR-05, `05_AUTHORIZATION_ARCHITECTURE.md` §1): exactly two flat roles, `member` and `admin`, enforced by Policies/Gates. There is **no** `roles`, `permissions` or `role_user` table. The role is one column on `users`.

| Considered | Verdict |
|---|---|
| Copy Supabase `user_roles` (user × role rows, a user may hold both) | **REJECTED.** A user is either an admin or a member; "both" is meaningless in Laravel. A pivot table would add a join to every authorisation check for no modelling gain. |
| `roles` table + `role_user` pivot | **DEFERRED.** Nothing in the confirmed requirements needs more than two flat roles. |
| `permissions` / `role_has_permissions` | **DEFERRED** (OD in `16_OPEN_DECISIONS.md` #24 of the architecture). The upgrade path is additive: a `roles` table can be introduced and `users.role` backfilled into a pivot without touching any other table, because every check already goes through Gate/Policy methods. |
| **`users.role VARCHAR(20)` + CHECK `IN ('member','admin')`** | **CHOSEN.** |

Application-layer rules that MySQL cannot express (recorded in `15_DATA_INTEGRITY_RULES.md`): the last remaining admin cannot be demoted or suspended; an admin cannot revoke their own role; role changes are audit-logged.

## 2. `users` — REWORK (merges legacy `auth.users` + `profiles`)

Purpose: every person who can authenticate. Members, admins, and users provisioned at membership activation who have not yet set a password. There is **no public self-registration**: a member account is created only by the activation action (frontend C-03, approved).

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id` | BIGINT UNSIGNED AI | N | — | PK |
| `name` | VARCHAR(255) | N | — | **Single full-name field** (Laravel's standard `users.name`; confirmed decision — there are **no** `first_name`/`last_name` columns). At membership activation it is copied **verbatim** from `membership_applications.full_name`; names are never split or reconstructed. Length is ≥ the application's 160 so the copy can never truncate. |
| `email` | VARCHAR(255) | N | — | **UNIQUE**. Stored lower-cased. Collation is case-insensitive so `UNIQUE` is case-insensitive too. |
| `email_verified_at` | TIMESTAMP | Y | NULL | Set when the setup link is used (the link proves control of the mailbox). |
| `password` | VARCHAR(255) | **Y** | NULL | NULL while `status = pending_setup`. A NULL password can never validate (Laravel's hasher rejects an empty hash); the login action must additionally refuse `status <> 'active'`. |
| `remember_token` | VARCHAR(100) | Y | NULL | Laravel standard |
| `role` | VARCHAR(20) | N | `'member'` | `CHECK (role IN ('member','admin'))`. Default is the *least* privileged value. |
| `status` | VARCHAR(20) | N | `'pending_setup'` | `CHECK (status IN ('pending_setup','active','suspended'))`. `suspended` replaces the legacy `profiles.is_active`. |
| `phone` | VARCHAR(40) | Y | NULL | E.164-ish; validated in the Form Request, not the DB |
| `country` | VARCHAR(100) | Y | NULL | |
| `aviation_occupation` | VARCHAR(150) | Y | NULL | Merges legacy `aviation_role` + `other_role`. **Renamed** to remove the naming collision with the authorisation `role` flagged in `AUTHORIZATION.md`. |
| `job_title` | VARCHAR(150) | Y | NULL | Legacy `occupation` |
| `company` | VARCHAR(150) | Y | NULL | |
| `linkedin_url` | VARCHAR(255) | Y | NULL | Legacy `linkedin_profile`; `url` rule in the Form Request |
| `bio` | TEXT | Y | NULL | |
| `avatar_path` | VARCHAR(255) | Y | NULL | `public` disk (public marketing/avatars). Never a private-document reference. |
| `created_at`, `updated_at` | TIMESTAMP | Y | NULL | |

**Keys and indexes**

| Index | Columns | Reason |
|---|---|---|
| PK | `id` | |
| `users_email_unique` | `email` | login, identity |
| `users_role_index` | `role` | admin list, "is there another admin" guard (tiny table; kept because it is the guard's only lookup) |

**Soft delete: NO.** Suspension is `status = 'suspended'`; erasure is anonymisation. `RESTRICT` FKs from history tables make an accidental hard delete impossible.

**Audit/history:** role changes, suspension/reactivation, and email changes are written to `audit_logs` (`user.role_changed`, `user.suspended`, `user.activated`). Passwords/hashes are never logged.

**Legacy dropped:** `profiles.id = auth.users.id` 1:1 split; `profiles.is_active` (→ `status`); `profiles.email` duplicate; `avatar_url` (full URL → `avatar_path`).

**Note on `name` vs the membership application `full_name`:** the application keeps its own `full_name` snapshot (the name that was reviewed). At activation the provisioned user's `name` is set to that same value; afterwards the member may edit their profile name, and the application row is left untouched. The membership card shows the current `users.name`. Greeting text uses the whole name (no first-name extraction). Public-facing surfaces that need a short label (e.g. blog comment author) use `name` as is.

## 3. `account_setup_tokens` — CONFIRMED (secure one-time account setup)

Purpose: replaces plaintext temporary passwords (`WORKFLOWS.md` §0.13). One row per issued token. The plaintext token exists only in the email; the database holds a hash.

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id` | BIGINT UNSIGNED AI | N | — | PK |
| `user_id` | BIGINT UNSIGNED | N | — | FK → `users.id` |
| `membership_id` | BIGINT UNSIGNED | N | — | FK → `memberships.id` — the (stable) member record created by the activation this token belongs to |
| `purpose` | VARCHAR(30) | N | `'account_setup'` | `CHECK (purpose IN ('account_setup','membership_link'))`. `membership_link` is used only if OD-06 resolves to "confirm before linking to an existing account". |
| `token_hash` | CHAR(64) `ascii_bin` | N | — | **UNIQUE**. SHA-256 hex of a ≥40-character CSPRNG token. High-entropy secret, so an unsalted SHA-256 is appropriate (same rationale as Laravel's own reset tokens). The plaintext is never stored, logged, or written to `audit_logs`. |
| `expires_at` | TIMESTAMP | N | — | Issue time + `membership_settings.account_setup_token_ttl_hours` |
| `used_at` | TIMESTAMP | Y | NULL | Set in the same transaction that sets the password |
| `invalidated_at` | TIMESTAMP | Y | NULL | Set when superseded by a re-issue, or revoked by an admin |
| `invalidated_reason` | VARCHAR(30) | Y | NULL | `superseded`, `admin_revoked` |
| `issued_by_user_id` | BIGINT UNSIGNED | Y | NULL | FK → `users.id`. NULL = system (automatic issue at activation); set when an admin re-sends. |
| `live_key` | VARCHAR(64) | Y | generated | `VIRTUAL` = `IF(used_at IS NULL AND invalidated_at IS NULL, CONCAT(user_id,':',purpose), NULL)`. |
| `created_at`, `updated_at` | TIMESTAMP | Y | NULL | |

**Validity of a token** (checked in one query by the setup action): `token_hash = ? AND used_at IS NULL AND invalidated_at IS NULL AND expires_at > UTC_TIMESTAMP()`.

**Foreign keys**

| FK column | Parent | Cardinality | ON DELETE | ON UPDATE |
|---|---|---|---|---|
| `user_id` | `users` | many tokens : 1 user | RESTRICT | RESTRICT |
| `membership_id` | `memberships` | many : 1 | RESTRICT | RESTRICT |
| `issued_by_user_id` | `users` | many : 1 | RESTRICT | RESTRICT |

**Indexes**

| Index | Columns | Reason |
|---|---|---|
| `account_setup_tokens_token_hash_unique` | `token_hash` | the only lookup path from an emailed link |
| `account_setup_tokens_live_key_unique` | `live_key` | **at most one live token per (user, purpose)** — issuing a new token must first set `invalidated_at` on the previous one in the same transaction, otherwise the insert fails |
| `account_setup_tokens_user_purpose_index` | `user_id, purpose` | admin "resend" screen |
| `account_setup_tokens_expires_at_index` | `expires_at` | the `ExpireAccountSetupTokens` housekeeping job |
| FK indexes | `membership_id`, `issued_by_user_id` | created by the FK constraints |

**Soft delete: NO.** Expired/used tokens are kept for a retention window (unresolved, OD-11) so a support question "was a link ever issued and used?" can be answered; housekeeping prunes by `expires_at`.

**Auditability:** `account_setup.issued`, `account_setup.completed`, `account_setup.invalidated` events go to `audit_logs`; IP/user-agent of the completion request are captured there (not duplicated on the token row).

**Security notes (enforced in application code, listed for completeness):** generic "link is no longer valid" message for expired/used/unknown tokens; compare via indexed hash lookup; rate-limit the setup route; the same token flow never resets the password of an already-`active` user (OD-06).

## 4. Existing-account collision (dependency on OD-06)

If an activated applicant's email already matches a `users` row, the schema supports both resolutions without change: `memberships.user_id` is nullable (see `04_MEMBERSHIP_SCHEMA.md`) and `account_setup_tokens.purpose = 'membership_link'` exists. Default behaviour pending confirmation: the existing account's credentials are **never** modified by an activation.

## 5. Framework tables (not redesigned)

| Table | Note |
|---|---|
| `password_reset_tokens` | Standard Laravel table, used for *forgot password* by existing active users. Deliberately separate from `account_setup_tokens` (first credential vs reset). |
| `sessions` | Only if the `database` session driver is chosen for SiteGround. Standard shape; `user_id` has an index but **no FK** (Laravel's own definition — sessions are ephemeral). |
