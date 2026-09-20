# 17 — Jobs & Community Architecture

Covers the Jobs domain (`02_DOMAIN_ARCHITECTURE.md` §8) and the Community domain (§9), grouped per the Phase 2 instructions' "JOBS AND COMMUNITY" section. This is an **additional** document beyond the 16 minimum deliverables (explicitly permitted: "you may create additional architecture documents if genuinely necessary") — split out from Content because several of its entities (partners, advisory members, referral contacts) have **no basis in the reverse-engineering record** and need to be visibly flagged as new/undefined scope, which would otherwise be buried inside the Content document.

## 1. Jobs — job postings & applications (CONFIRMED via reverse-engineering)

**Entities**: `JobPosting`, `JobApplication`, and (new, see §2) `JobApplicationDocument`.

**Replaces (legacy)**: `jobs`, `job_applications` (`DATABASE.md`, `FEATURES.md` §A/§D).

**Fixes carried from `LEGACY_RISKS.md`/`FEATURES.md`**:
- **Job board filtering is server-side from the start**: query-string-bound (mirrors the Blog index pattern), not the legacy's dead server-capability-but-client-side-filtered-full-dataset pattern (`FEATURES.md` §A "Jobs Board").
- **Slug/status handling** follows the same enum-backed, Form-Request-validated pattern as other Content entities (`10_CONTENT_ARCHITECTURE.md` §2, §9) — the legacy admin UI offered status values (`reviewing`, `shortlisted`, `hired`, etc.) not all enumerated anywhere in its schema (`WORKFLOWS.md` §4); this design requires a real, finite backed-enum `JobApplicationStatus` rather than free-text-passed-through values.
- **Applicant notification on status change** — a genuine gap-fix, not a restated requirement: the legacy app notified no one when a job application's status changed (`NOTIFICATIONS.md`). Whether ACI wants this added is recorded in `16_OPEN_DECISIONS.md` rather than assumed.

## 2. Job application documents (CONFIRMED REQUIREMENT: "job application documents")

**ASSUMPTION, minimal scope**: the legacy `job_applications` table (per `DATABASE.md`) had no document/attachment concept at all — applying to a job board posting in the legacy app was effectively just recording that a member clicked "apply" (or was directed externally via `external_link`), with **no résumé/CV upload anywhere in the reverse-engineered record**. The Phase 2 instructions now ask this architecture to support "job application documents." This document proposes the obvious minimal shape — a `JobApplicationDocument` (belongs to `JobApplication`, stored on the **private** disk per `07_FILE_STORAGE_ARCHITECTURE.md`, same validation/authorization pattern as aviation proof documents) — without inventing further rules (how many documents, required vs. optional, accepted formats) that ACI has not specified. Recorded in `16_OPEN_DECISIONS.md`.

## 3. Community: referrals — current scope only (CONFIRMED via reverse-engineering)

`ReferralInvitation` is deliberately built as **only** what the confirmed reverse-engineering record shows is current scope: an authenticated member sends a plain invite email, with no code, tracking, or reward (`WORKFLOWS.md`/`FEATURES.md` §C). A real tracked-referral program (unique codes, attribution, rewards) is explicitly **not** built now — it would be new scope requiring its own business-rule confirmation from ACI, exactly as `FEATURES.md` already flagged.

## 4. "Referral contacts" — new concept, not in the reverse-engineering record

**TBC, minimal placeholder only**: the Phase 2 instructions add "referral contacts" alongside referrals, which does not correspond to anything found in `docs/reverse-engineering/` — the legacy referral feature had no persisted contact list at all (the invite email's recipient address wasn't stored anywhere, `WORKFLOWS.md` §11 in the member-dashboard findings). Two plausible readings exist, and this document deliberately does **not** pick one:
1. A record of who a member has invited (an audit/history list of sent invitations), or
2. An address-book-style feature letting a member maintain contacts to invite.

Both are simple to add to the existing `ReferralInvitation` entity (either by keeping a row per send, which reading 1 needs and which this architecture already implies by having `ReferralInvitation` be a real table rather than a fire-and-forget action) — but which behaviour ACI actually wants (a history view vs. an editable contact book) is not specified anywhere and is **not invented here**. Recorded in `16_OPEN_DECISIONS.md`.

## 5. "Partners" — new concept, not in the reverse-engineering record

**TBC, minimal placeholder only**: no "partners" entity, page, or admin feature exists anywhere in the legacy application or the reverse-engineering documentation. Nothing in `FEATURES.md`, `ROUTES.md`, or `DATABASE.md` describes what a "partner" is for ACI (a sponsoring organization shown on the public site? A discount-partner directory for members? A flying-school/training partner directory?). **This document does not invent that definition.** If/when ACI defines it, it is very likely to fit the same generic admin-CRUD pattern already established for `Testimonial`/`TeamMember` (`10_CONTENT_ARCHITECTURE.md` §1: name, logo/photo, description, link, display order, active flag) — noted here only so a future `Partner` entity has an obvious, low-effort home in this architecture, not because its shape is confirmed. Recorded prominently in `16_OPEN_DECISIONS.md` as needing a business definition before any design proceeds.

## 6. "Advisory members" — new concept, not in the reverse-engineering record

**TBC, minimal placeholder only**: likewise absent from the legacy app and reverse-engineering record. Two plausible readings — (a) a distinct display category within `TeamMember` (e.g. an "Advisory Board" section on the About page, which would need only a `type`/`category` field on the existing `TeamMember` entity, not a new one), or (b) a distinct membership *category* or *status* beyond the three confirmed categories (Student/Professional/Veteran) — which would be a materially different, much larger change directly affecting `04_MEMBERSHIP_ARCHITECTURE.md`'s confirmed category model. **Given the confirmed membership business rules explicitly state "ACI has exactly three membership categories," this document assumes reading (a) — a `TeamMember` display variant — is far more likely to be correct than a fourth membership category, but this is a judgment call, not a confirmed answer, and must be verified with ACI before implementation.** Recorded in `16_OPEN_DECISIONS.md`.

## 7. Authorization summary for this domain

Admin CRUD on `JobPosting`, `Partner` (if defined), and the `TeamMember`/advisory display all require the `admin` role, matching every other Content-adjacent entity (`05_AUTHORIZATION_ARCHITECTURE.md`). `JobApplication`/`JobApplicationDocument` ownership follows the same member-owns-their-own-row-or-admin pattern as every other member-submitted entity (`04_MEMBERSHIP_ARCHITECTURE.md`'s `AviationProofDocument` is the template). `ReferralInvitation`/referral-contact access is scoped to the sending member (or admin), never public.

*Per the Phase 2 restriction: conceptual design only, and for §§4–6 explicitly **undefined** pending ACI business input — no Jobs/Community models, controllers, or Livewire components exist yet, and nothing here should be read as a confirmed design for the three new/undefined entities.*
