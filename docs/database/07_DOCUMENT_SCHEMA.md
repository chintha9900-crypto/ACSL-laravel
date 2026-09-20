# 07 — Document Schema

Design only. One table: `documents` — metadata for **private** files (aviation proof, payment evidence, job-application documents). The file bytes live on the `private` Laravel disk; this table is never a pointer to public assets.

Legend: **N** = `NOT NULL`, **Y** = nullable. All FKs `ON UPDATE RESTRICT`.

## 1. What is and is not a "document"

| File kind | Where its metadata lives | Disk |
|---|---|---|
| Aviation eligibility proof | `documents` (`kind = aviation_proof`) | `private` |
| Payment evidence | `documents` (`kind = payment_evidence`) | `private` |
| Job-application documents | `documents` (`kind = job_application_document`) | `private` |
| Blog/news/event images, hero banners, team/testimonial photos, product images, avatars, logo/favicon | a `*_path` column on the owning row (or `product_images`) | `public` |

Public marketing files are deliberately **not** in `documents`: they need no ownership, checksum or access audit, and mixing them would blur the private/public boundary that `07_FILE_STORAGE_ARCHITECTURE.md` establishes. This is a change from `02_DOMAIN_ARCHITECTURE.md` §11 ("no single Document entity") required by the Phase 3 instruction for a reusable document-metadata system (C2 in `01`).

## 2. Ownership design: exclusive arc, not polymorphism

Owners today: a membership application, a payment, a job application. Three options were evaluated:

| Option | Verdict |
|---|---|
| **Polymorphic** `documentable_type` + `documentable_id` | **REJECTED.** No FK is possible (a document could point at a deleted or wrong-typed row, undetectable by the database), it prevents `RESTRICT` protection, and it invites reuse for unrelated entities. The Phase 3 rule is "avoid unnecessary polymorphism"; here it is unnecessary. |
| Three tables (`membership_application_documents`, `payment_evidence_documents`, `job_application_documents`) | Valid, but triplicates identical metadata columns, checksums, purge logic and policies, contradicting the "reusable document system" requirement. |
| **One table + exclusive arc of typed nullable FKs + CHECK** | **CHOSEN.** Every owner is a real FK with `RESTRICT`; exactly one is set (CHECK); metadata, purge handling and access-audit code are written once. Adding a fourth owner type is one additive column + CHECK edit. |

## 3. `documents` — CONFIRMED (capability), REWORK (shape)

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id` | BIGINT UNSIGNED AI | N | — | PK |
| `public_id` | CHAR(26) `ascii_bin` | N | — | **UNIQUE** ULID. The only identifier used in download routes. |
| `kind` | VARCHAR(40) | N | — | `CHECK IN ('aviation_proof','payment_evidence','job_application_document')` — closed set tied to the owner columns |
| `membership_application_id` | BIGINT UNSIGNED | Y | NULL | FK → `membership_applications` |
| `membership_details_request_id` | BIGINT UNSIGNED | Y | NULL | FK → `membership_details_requests` — set when the file was supplied in response to a "more details" request |
| `payment_id` | BIGINT UNSIGNED | Y | NULL | FK → `payments` |
| `job_application_id` | BIGINT UNSIGNED | Y | NULL | FK → `job_applications` |
| `uploaded_by_user_id` | BIGINT UNSIGNED | Y | NULL | FK → `users`. NULL = the anonymous (pre-account) applicant. |
| `disk` | VARCHAR(30) | N | `'private'` | `CHECK (disk <> 'public')` — a document row can never describe a publicly served file |
| `storage_path` | VARCHAR(500) | N | — | Server-generated relative key, e.g. `aviation-proof/{application_public_id}/{ulid}.pdf`. Never derived from user input (no traversal surface). |
| `original_filename` | VARCHAR(255) | N | — | Display only; sanitised; never used to build a path |
| `mime_type` | VARCHAR(127) | N | — | **Server-detected** (magic bytes), not the browser-reported type |
| `size_bytes` | INT UNSIGNED | N | — | `CHECK (size_bytes > 0)` |
| `checksum_sha256` | CHAR(64) `ascii_bin` | N | — | Integrity + duplicate/reuse detection (e.g. the same payment slip submitted for two payments) |
| `visibility` | VARCHAR(20) | N | `'owner_and_admin'` | `CHECK IN ('owner_and_admin','admin_only')`. There is no `public` value. |
| `purged_at` | TIMESTAMP | Y | NULL | Tombstone: file removed from disk, metadata retained |
| `purged_by_user_id` | BIGINT UNSIGNED | Y | NULL | FK → `users` |
| `purge_reason` | VARCHAR(255) | Y | NULL | |
| `created_at`, `updated_at` | TIMESTAMP | Y | NULL | `created_at` = upload time |

**CHECK constraints**

| Constraint | Meaning |
|---|---|
| `(kind = 'aviation_proof' AND membership_application_id IS NOT NULL AND payment_id IS NULL AND job_application_id IS NULL) OR (kind = 'payment_evidence' AND payment_id IS NOT NULL AND membership_application_id IS NULL AND job_application_id IS NULL) OR (kind = 'job_application_document' AND job_application_id IS NOT NULL AND membership_application_id IS NULL AND payment_id IS NULL)` | **exactly one owner, and it matches the kind** |
| `membership_details_request_id IS NULL OR kind = 'aviation_proof'` | response files are aviation proof |
| `disk <> 'public'` | private only |
| `(purged_at IS NULL) = (purged_by_user_id IS NULL)` | a purge always names who did it |

**Cross-row consistency the database cannot check** (application layer, `15`): a `membership_details_request_id`, when set, belongs to the *same* application as `membership_application_id`; a `payment_evidence` document's payment belongs to a membership payment (never an order payment) in the current design.

**Foreign keys**

| FK column | Parent | Cardinality | ON DELETE |
|---|---|---|---|
| `membership_application_id` | `membership_applications` | 1 application : many proof documents (≥ 1 required before submit — application rule) | RESTRICT |
| `membership_details_request_id` | `membership_details_requests` | 1 request : many response documents | RESTRICT |
| `payment_id` | `payments` | 1 payment : many evidence documents (resubmissions keep history) | RESTRICT |
| `job_application_id` | `job_applications` | 1 application : many documents (rules unresolved, OD-14) | RESTRICT |
| `uploaded_by_user_id`, `purged_by_user_id` | `users` | many : 0..1 | RESTRICT |

**Indexes**

| Index | Columns | Reason |
|---|---|---|
| `documents_public_id_unique` | `public_id` | download route |
| `documents_disk_storage_path_unique` | `disk, storage_path` | no two rows may claim one file |
| `documents_membership_application_id_kind_index` | `membership_application_id, kind` | admin review screen |
| `documents_payment_id_index` | `payment_id` | evidence for a payment |
| `documents_job_application_id_index` | `job_application_id` | job application files |
| `documents_checksum_sha256_index` | `checksum_sha256` | reuse/duplicate detection |
| FK indexes | `membership_details_request_id`, `uploaded_by_user_id`, `purged_by_user_id` | |

## 4. Deletion, retention and audit

* **Soft delete: NO** (Laravel `SoftDeletes` would leave the file and row "hidden but present", which is the wrong privacy semantics). Instead a **purge tombstone**: the file is removed from disk, `purged_at/by/reason` are set, and the metadata + checksum remain as evidence that a document existed, when, and who removed it. Automatic purging is **not** designed to run by default — no retention policy is confirmed (OD-11).
* **Replacement:** a resubmitted payment slip or an extra proof file is a **new** row; earlier ones are retained (the review history depends on them). Files are never overwritten in place.
* **Access audit:** every authorised read of an `aviation_proof` or `payment_evidence` file writes `audit_logs` event `document.viewed` (`subject = documents`, actor, IP, user agent). Uploads: `document.uploaded`. Purges: `document.purged`. Job-application documents: uploads audited; views audited if ACI wants parity (`10`/OD-14).
* **Privacy controls that live in code:** downloads go through a Policy-gated controller (`documents.public_id` → owner-or-admin check); no path is ever exposed; responses are `Content-Disposition: attachment` with the stored `mime_type`.
* **Owner definition when there is no user yet:** for an anonymous applicant `membership_applications.user_id` is NULL, so only admins (and signed-link actions such as *upload a requested document*) can act on the application's documents until the account exists (OD-07). Viewing proof is admin-only until then.

## 5. Legacy elements not carried forward

The `documents` Supabase bucket, the JSONB `membership_applications.documents` array, 1-hour signed URLs as the only barrier, and browser-trusted MIME types.
