# 07 — File & Storage Architecture

Centralizes the file-handling rules every domain's uploads follow, replacing the legacy app's inconsistent per-feature Supabase Storage bucket handling (`docs/reverse-engineering/STORAGE.md`).

## 1. Disks (ARCHITECTURAL DECISION)

Two Laravel Filesystem disks, both local on SiteGround initially (S3-compatible remains a future option, not required now):

| Disk | Visibility | Used for |
|---|---|---|
| `public` | Public-readable, served directly (symlinked `storage/app/public` → `public/storage`, standard Laravel convention) | Blog/news images, product images, hero banners, team/testimonial photos, member avatars, site logo/favicon — content that is genuinely meant to be publicly viewable |
| `private` (or `local`, non-symlinked) | Never web-accessible directly; served only through an authorized, signed route | **Aviation proof documents** and **payment evidence documents** (both CONFIRMED REQUIREMENT: "must not be publicly accessible" / "must not be exposed publicly") |

- **Why appropriate for ACI**: this directly matches the confirmed requirement split (public marketing images vs. private applicant/payment documents) and is simpler than the legacy's actual setup, which put *everything* — including the non-public `documents` bucket — behind Supabase's private-bucket-plus-signed-URL mechanism even for genuinely public images (`STORAGE.md` flags this as likely an inconsistency, not a deliberate choice). Public assets get real public URLs (cheaper, cacheable, no signing overhead); only genuinely sensitive documents pay the cost of signed access.
- **Laravel mechanism**: `config/filesystems.php` disks; `Storage::disk('public')->putFile(...)` / `Storage::disk('private')->putFile(...)`.
- **Alternatives considered**: one disk for everything, all served through signed URLs (matches legacy behaviour) — rejected: unnecessary overhead for public content, and blurs the "private documents use private storage" principle by making "private" mechanics apply uniformly regardless of actual sensitivity.

## 2. Access to private files (aviation proof, payment evidence)

**CONFIRMED REQUIREMENT**: "prevention of unauthorized document access," "must not be exposed publicly," admin must be able to review.

**ARCHITECTURAL DECISION**: private files are served through a dedicated Controller route, gated by a Policy check (e.g. `AviationProofDocumentPolicy::view()` — the applicant themself, or an admin, only), using Laravel's `Storage::disk('private')->response()`/`download()` or a short-lived signed URL (`URL::temporarySignedRoute()`), never a direct public path.

- **Security considerations**: this closes the legacy's actual weakest point in this area — its uploads were *stored* privately (`documents` bucket excluded from public read) but access was gated by ad hoc 1-hour Supabase signed URLs generated per-view rather than a real per-request authorization check; the Laravel design authorizes **every** access attempt through a Policy (server-side, per `05_AUTHORIZATION_ARCHITECTURE.md`), which is strictly stronger than "anyone who has this URL, valid for the next hour, can view it."
- **Data integrity considerations**: file paths are never derived from user input directly (no path traversal surface) — stored paths are generated server-side (e.g. `Str::uuid()` + validated extension), matching the legacy's own (correct) sanitization intent but enforced consistently in one place rather than per-feature.

## 3. Upload validation (CONFIRMED REQUIREMENT: file type validation, size limits)

**ARCHITECTURAL DECISION**: every upload (aviation proof, payment evidence, avatar, product/blog/news images) is validated server-side via Laravel's built-in `image`/`mimes`/`max` validation rules, which inspect actual file content/magic bytes — not the browser-reported MIME type the legacy app trusted (`STORAGE.md`, `AUTHORIZATION.md` §6 item 8, `VALIDATION.md`: "no file content-type/magic-byte validation anywhere ... always trusts the browser-reported MIME type"). Concrete limits (max file size per upload type, accepted formats for aviation proof) are configuration, not hard-coded per controller — `config('uploads.aviation_proof.max_kb')` etc. — and the exact values are `TBC` pending ACI's confirmation of accepted proof types (`WORKFLOWS.md` §0.5, §0 "Remaining decisions").

- **Multi-file uploads (aviation proof, up to N documents per application)**: validated as an array of files, each individually checked; a sane per-application total-size ceiling is applied (the legacy allowed up to 6 files × ~7MB via base64-in-JSON, which is both a request-size problem and a storage-cost risk, `AUTHORIZATION.md` §6 item 8) — the Laravel rebuild uses genuine multipart form uploads (not base64-encoded JSON), which avoids the ~33% base64 size inflation and lets standard `POST` size limits (`upload_max_filesize`/`post_max_size` in PHP, plus Laravel's own request size handling) apply naturally.

## 4. Orphaned file cleanup

**ARCHITECTURAL DECISION**: model updates that replace a file reference (a new avatar, a new blog featured image) delete the previous file as part of the same update — via a model observer (`updating` event comparing the old and new file-path attribute) rather than leaving it to each controller to remember. This fixes the legacy's flagged storage leak (every avatar/image replace left the old file orphaned forever, `STORAGE.md`, `FEATURES.md` §C). A scheduled cleanup job (`11_BACKGROUND_JOBS_ARCHITECTURE.md`) is a secondary safety net for any file left behind by a failed/interrupted request, not the primary mechanism.

## 5. Digital membership card & PDFs

Generated on demand from a Blade view (`04_MEMBERSHIP_ARCHITECTURE.md` §10) — not stored as a file at all by default (regenerated per request), avoiding a whole class of "stale cached card" and "who can access this cached PDF" storage/authorization questions. If ACI later wants cards cached for performance, that cache would live on the `private` disk with the same Policy-gated access as any other private document.

## 6. Storage organization, retention, and access auditing

- **Storage organization**: private documents are namespaced by owning entity and domain, not dumped into one flat folder — e.g. `private/aviation-proof/{application_id}/...`, `private/payment-evidence/{payment_id}/...`, `private/job-applications/{application_id}/...` (the last covering `JobApplicationDocument`, `17_JOBS_AND_COMMUNITY_ARCHITECTURE.md` §2) — so a future retention or export job can target one document category without scanning unrelated files.
- **Retention**: **TBC** — no confirmed retention or deletion policy exists for aviation proof, payment evidence, or job application documents (how long ACI must/should keep applicant-submitted documents after a rejection, after a membership lapses, or after a job posting closes is not specified anywhere in the reverse-engineering record or the Phase 2 instructions). This architecture does not delete any private document automatically by default — recorded in `16_OPEN_DECISIONS.md` rather than assumed either way, since silently deleting could destroy records ACI needs and silently keeping forever could be a data-minimization concern once ACI has a retention policy.
- **Access auditing**: every authorized access to a private document (§2) — not just uploads — is written to the generic `AuditLog` (`12_AUDIT_LOGGING_ARCHITECTURE.md` §2: "document access where appropriate") when the document is sensitive enough to warrant it (aviation proof and payment evidence, specifically — routine admin browsing of, say, a blog post's public featured image is not audit-logged, that would be noise). This closes a gap the legacy app never had any equivalent of: Supabase's signed-URL model left no record of *who actually viewed* a document, only that a URL was generated.

## 7. Summary — what changes vs. the legacy app

| Legacy behaviour (`STORAGE.md`) | This design |
|---|---|
| One shared `"blogs"` bucket for blog/news/shop images (likely a copy-paste artifact) | Public disk, organized by a clear directory-per-domain convention (`public/blog/`, `public/news/`, `public/products/`) |
| Browser-direct upload to Storage, authorized only by Storage RLS policies (no server-side re-validation) | Every upload goes through a server-side Controller/Livewire action with real Form Request validation, authorized by a Policy — no client-direct-to-storage path at all |
| Inconsistent signed-URL TTLs (1 hour in most places, 7 days in one) | Public assets need no signed URL at all; private-document access is a per-request authorization check, not a time-boxed URL whose expiry is the only thing standing between "authorized" and "not" |
| No orphaned-file cleanup anywhere | Model-observer cleanup on replace + a scheduled safety-net job |
| No server-side MIME/content validation | Laravel's content-inspecting validation rules on every upload |

*Per the Phase 2 restriction: conceptual design only. No disks, upload controllers, or file-handling code exist yet.*
