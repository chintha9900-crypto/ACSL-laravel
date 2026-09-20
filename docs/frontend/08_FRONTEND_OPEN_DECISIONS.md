# 08 — Frontend Open Decisions

Decisions are split into **APPROVED DECISIONS** (settled by ACI direction — implementation must follow them), **CRITICAL** (must be decided before implementation — currently none) and **DEFERRED** (safe to decide later). Everything else in `01`–`07` follows from the approved architecture/database or is a straightforward reproduction of the reference.

## APPROVED DECISIONS

These five items were previously listed as CRITICAL. They are now approved and are **rules for implementation**, not open questions. IDs are kept because other frontend documents cite them.

### C-01 — Rendering stack — **APPROVED**
* **Blade** = default rendering for every page.
* **Alpine.js** = local browser interaction only, where needed (dropdowns, mobile menu, accordion, modals, show/hide password, eligibility self-check, flash toasts).
* **Livewire 3** = approved **only for genuinely server-interactive admin functionality** (filterable/sortable admin tables, application review and payment confirm/reject screens, the generic CRUD manager) — as scoped in `01` §5.
* **Livewire is not used for private document uploads.** Aviation proof, payment evidence and job-application documents use standard multipart POST forms to controllers that store on the private disk.
* **Not installed yet.** Neither Livewire nor Alpine has been installed, and no package files were changed; installation happens in the implementation phase.
* Still no other frontend framework (no React/Vue/Inertia/jQuery/component library).

### C-02 — Branding — **APPROVED**
* Legal/display name: **Aviation Club International**. Short name: **ACI**.
* **ACI-approved logo assets are authoritative.** The logo is not invented, redrawn or redesigned; it is supplied by ACI (the reference only contains a Lovable-hosted descriptor, not the file).
* The legacy **"ACSL"** name (and its "Sri Lanka" positioning copy) must not appear in the new application UI, page titles, meta tags, emails or error pages.
* The extracted design tokens (`02`) remain the **starting point**. **Final colours and logo assets are controlled brand assets**: the values in `02` are provisional until ACI supplies the final brand palette, and the off-token colours (`#D9A233`, `#004d82`, Tailwind `yellow-400`) stay unresolved until then.

### C-03 — Public self-registration — **REJECTED / REMOVED**
* **No public "Create account" page** or tab, and no registration form.
* Accounts are **provisioned during membership activation** (`account_setup_tokens`; `docs/database/03_IDENTITY_SCHEMA.md`).
* Login is available to **existing accounts** only.
* The **membership application is the entry point** for new members; header "Join Now" leads to the membership pages/application.

### C-04 — Membership categories, prices and promotions — **APPROVED**
* **Exactly three membership categories: Student, Professional, Veteran.**
* **No hard-coded prices** (or currency) in views. Prices and currency come from the database (`membership_plans`). The choice of currency value itself is a database decision (DB OD-01); the frontend only renders what the data says.
* The legacy **three Professional tiers (Core / Premier / Inner-Circle) are not hard-coded.** **Professional remains one active plan** unless ACI later approves multiple plans.
* **Promotions are database-driven** (`membership_promotions`); promotion banners appear only when an active promotion exists.
* The legacy **"first 100 students free"** offer is **not carried over** unless ACI later configures it explicitly as a promotion.

### C-05 — Pre-account applicant access — **APPROVED in principle**
* Applicant pages (status, respond to a details request, submit payment evidence) use **secure signed, expiring links tied to the application**.
* Final Laravel implementation and security details (expiry length, signing, throttling, link re-issue) will be documented during implementation.
* **No public application access by guessing IDs** — the identifier alone never grants access.
* **No applicant account is created merely to provide pre-activation access.**

## CRITICAL — decide before implementation

None outstanding. (C-01 to C-05 above are approved.)

## DEFERRED — safe to decide later

| ID | Decision | Default until decided |
|---|---|---|
| D-01 | **Rich-text editor & prose styling** (Tiptap as in the reference vs Trix vs Markdown) and whether to add the Tailwind typography plugin; blog/news/jobs need it at admin-content time, not before | Tiptap-class editor behind `form.rich-text-editor`; small `.rich-text` stylesheet; also decides full-page vs dialog forms (recommend full page for long forms) |
| D-02 | **Image upload/crop UX** (client cropper vs server-side resize); recommended featured image 1200×630 JPEG | Plain upload + server-side resize/crop to the standard ratio |
| D-03 | **Reports charts** (library, which charts) and PDF export | Server-rendered numbers + tables; charts later |
| D-04 | **Dark mode** (tokens exist, never toggled in the reference) | Not implemented |
| D-05 | **Font hosting** (Google Fonts vs self-hosted Sora/Plus Jakarta Sans) | Self-host later for privacy/performance; same families |
| D-06 | **App-area shell details:** slim vs full footer inside member/admin, and mobile nav pattern for the sidebar (pills vs drawer) | Slim footer; horizontal scroll pills |
| D-07 | **Public surfaces for admin-managed content the reference never displayed:** team members, testimonials, hero *carousel* (vs single banner), stats numbers on About, partners/advisory (DB OD-16), member "perks" page | None shown; About stats omitted until verified |
| D-08 | **E-commerce frontend** — no reference UI exists in the snapshot; commerce is future scope | Not designed; reuse `02`/`06` when scheduled |
| D-09 | **Content supply:** Privacy Policy, Terms, Club Rules, About copy/claims, benefit lists, eligibility thresholds (reference has Lorem ipsum, legacy rules and unverified numbers); optional rules-consent checkbox on the application form | Layout built with placeholder headings; **launch gate**, not an implementation gate |
| D-10 | **Extra status values the reference UI offers:** blog `archived`, job `closed`, comment `rejected` (DB has `draft/published`, `draft/published`, `pending/approved/hidden`) | Use the DB vocabularies; add values via an additive DB change if ACI wants them |
| D-11 | **Phone input** (international widget with country picker vs plain `tel` with server validation, and whether phone is mandatory on the contact form) | Plain `tel` + server validation; picker later |

## Not decisions (recorded so they are not re-asked)

Route naming (`/login`, `/dashboard/*`, `/admin/*`), Blade component naming, moving payment/billing into the Membership page, removing the Promotions member page, replacing `window.confirm` with modals, server-side filtering, one shared status-badge map — all follow directly from the approved architecture and are already reflected in `01`–`07`.
