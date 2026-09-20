# 05 — Admin Pages

The reference admin has 18 pages (`/admin/*`). Each is documented, followed by the pages the approved architecture requires that the reference lacks. Membership screens are **redesigned** around the approved workflow; the reference's free-form status selects, payment-link dialog and JSON detail dump are not reproduced.

## 1. Shell and shared patterns

| Item | Reference | Target |
|---|---|---|
| Layout | public header + `grid lg:grid-cols-[240px_1fr]`; sidebar title "Admin", flat 18-link list, "← Back to member area" | same skeleton in `layouts.app`; **grouped** sidebar (`01` §4) |
| Access | client `checkIsAdmin` query → "Verifying access… / Access denied" screen; a client redirect to `/dashboard` | **`admin` middleware/gate on every route**; non-admins get a server 403/redirect — no client check, no flash of admin UI |
| Page header | `h1` + muted description; primary action ("New …") top-right | `x-app.page-header` (title, description, action slot) |
| List pages | `DataTable` (shadcn table in bordered box, empty text) | `x-admin.data-table` (Livewire base for filterable lists) |
| Create/edit | Radix Dialog (`max-w-lg/2xl/3xl`, scroll) | Dialog for short forms; **full page** for long/rich forms (blog, news, jobs, application review) — `08` D-01 |
| Confirmations | `window.confirm("Delete…?")` | `x-ui.confirm-dialog` posting a form |
| Feedback | Sonner toast | flash toast |
| Search/filters | client-side over the first 200 rows only | server-side search + filters + pagination (Livewire) |
| Row actions | icon buttons (pencil, trash) or inline `Select` "Set status" | icon buttons; **workflow buttons** for lifecycle states (no arbitrary status jumps) |
| Status colour | `Badge` variants (`default/secondary/destructive/outline`) | `x-ui.status-badge` (one mapping table, `06`) |

**Permissions (all pages):** admin role only, enforced by route middleware + policy per model. Destructive/high-impact actions (role change, suspend, payment confirm/reject, application decision, refund, settings change) are additionally audit-logged; last-admin / self-revoke guards are server-side. Private documents open through a policy-gated download and are audit-logged when viewed.

## 2. Dashboard — `/admin`
* **Reference:** "Admin Overview"; 8 count cards (Users, Memberships, Blog posts, Comments, Jobs, Applications, Enquiries, Subscribers; `sm:2/lg:4`); 3 action cards (Pending memberships, Comments to review, New enquiries) with an "Action" badge when > 0.
* **Target:** action-oriented queue cards (each links to a pre-filtered list): **Applications awaiting review** (`submitted`) · **More-details awaiting response** · **Payments awaiting confirmation** (`payment_confirmation_submitted`) · **Memberships expiring in 30 days** · Comments to review · New enquiries. Secondary count cards: active members, open job postings, subscribers. Charts: none here (Reports page).
* **Type:** Blade.

## 3. Membership Applications — `/admin/membership-applications` (+ detail)
* **Purpose:** review applications (approve / reject / request more details) — the core admin workflow.
* **Nav:** Membership → Applications.
* **List — reference:** filters: search (name/email/mobile, client-side), type (all/student/professional/veteran), status (`submitted, pending, under_review, approved, more_details_requested, rejected`); columns: Application ID (8-char mono), Type badge, Full name, Email, Mobile, Status badge, Submitted, actions (Select "Set status": Accepted / Declined / Need more details; **View**).
* **List — target:** status filter = the **four approved statuses** (`submitted`, `more_details_required`, `approved`, `rejected`), category filter, search, oldest-first default for `submitted`. Columns: Reference (`public_id` short), Category, Name, Email, Mobile, **Proof reviewed** (✓/—), Status, Submitted. Row action: **Review** (opens detail). No inline status select.
* **Detail (full page — replaces the reference's "Application Details" dialog):**
  1. **Header:** name, category, status badge, submitted date, decision (who/when).
  2. **Applicant + aviation information:** personal fields and category-specific fields as labelled rows (not the raw key/value `form_data` dump).
  3. **Aviation proof:** document list (name, type, size, uploaded, **Open** via secure route — never a public URL); **Mark proof reviewed** button (required before Approve).
  4. **More-details history:** each request and the applicant's response + response files.
  5. **History timeline:** from `membership_status_history` (events, actor, note, time).
  6. **Decision panel:** **Approve** (disabled until proof reviewed; confirm modal explains the outcome: *free promotion → membership activated immediately, number issued* / *no promotion → payment instructions sent*), **Request more details** (modal: message, "emailed and saved on the application"), **Reject** (modal: note/reason shown to applicant). Reapplication cooldown date shown after rejection.
  7. After approval: link to the **Membership** record.
* **Modals:** request details, reject, approve-confirm. **Emails:** M2/M3/M4/M5 are queued by the actions (an email status flash replaces the reference's "emailSent" toast note).
* **Permissions:** admin; one decision at a time (compare-and-set) — a second admin sees "already decided". **Type:** **Livewire** (list = table base; detail = review component).

## 4. Memberships — `/admin/memberships` (+ detail)
* **Purpose:** view memberships/terms, **confirm or reject payment evidence**, monitor activation and expiry.
* **Nav:** Membership → Memberships.
* **List — reference:** Member (name/email), Plan, Applied, Status badge (`pending, active, rejected, suspended, expired`), Payment badge, actions: status `Select` (any → any), **Approve** (pending), **Send/Update payment link** (dialog: URL, amount USD, status). *This is the legacy System-A page and is superseded.*
* **List — target:** columns: Member, **Membership number**, Category, Status (`pending_activation/active/expired`), **Payment status** (five states), Starts, Expires, Promotion. Filters: status, payment status, category, "expiring within 30 days", "awaiting payment confirmation", search (name/email/number). No free-form status select; no payment-link dialog; no `suspended/rejected` states.
* **Detail:** membership facts (fee snapshot, plan, promotion snapshot, dates), **Payment panel** — bank account instructed, reference, **evidence documents** (secure open), payment status, history; actions **Confirm payment** (→ activation, number issued, setup email) and **Reject payment** (modal: reason → membership back to `payment_pending`, applicant notified). Refund (payment) action: **deferred** (P3, DB OD-13). Links to the source application and account-setup resend (re-issues a token).
* **Permissions:** admin; confirm/reject are audit-logged; guarded state transitions only. **Type:** Livewire.

## 5. Promotions — `/admin/promotions` (new)
List (Name, Active, Window, Free months, Priority, Categories) + create/edit form: name, description, active, **starts_on / ends_on**, free-membership flag, **free duration (months)**, **priority** (lower = higher), applicable categories (checkbox group). **No delete** — deactivate. Edits audit-logged. Shows "used by N memberships". **Type:** Blade dialog form (or CRUD component).

## 6. Plans & categories — `/admin/plans` (new)
Three fixed categories (read-only code S/P/V; name/description/active editable) and their **plans** (fee, currency, duration, active). **Price change = "New plan version"** (never edit a plan already used — DB rule R-25); only one active plan per category. **Type:** Blade.

## 7. Bank details — `/admin/bank-accounts` (new)
List + form for `payment_bank_accounts`: label, bank, account name, account number, branch, sort code, IBAN, SWIFT/BIC, currency, instructions, active (one active per currency). Editing a used account = create new + deactivate old. Audit-logged. **Type:** Blade dialog.

## 8. Users — `/admin/users`
* **Reference:** search (name/email, client-side) · columns Name+email, Country, "Role" (this was the **aviation role text**, easily confused with the admin role), Status (Active/Suspended badge), Admin badge · actions **Suspend/Activate**, **Make/Revoke admin** (no guards).
* **Target:** server-side search + status filter; columns Name+email, Country, **Aviation occupation** (relabelled), **Membership number/status**, Status (`pending_setup/active/suspended`), Role. Actions: Suspend/Activate, Make/Revoke admin — via confirm modals, with **last-admin and self-revoke guards** (server-side, audit-logged). No delete (never hard-delete). Detail view: profile, memberships, recent audit events (TBC).
* **Type:** Livewire table.

## 9. Blog posts — `/admin/blog`
* **Reference:** table (Title + slug, Category, Status badge, Updated, edit/delete icons); dialog `max-w-2xl`: Title (auto-slug), Slug, Category select, Status (draft/published/**archived**), **ImageCropUpload** (JPEG, 1200×630 crop with zoom slider, private bucket), Excerpt, **RichTextEditor** (Tiptap: bold/italic/strike, paragraph, H1–H3, bullet/numbered list, quote, undo/redo), Reading time.
* **Target:** same fields; **full-page form**; reading time dropped (derived); categories/tags managed inline (tags UI absent in the reference — optional); `published_at` stamped on any publish; content sanitised on save; statuses `draft/published` (`archived` not in DB — `08` D-10). Search + status/category filters + pagination. **Blog categories** manager (name/slug) — reference had none.
* **Type:** Blade full-page form (editor per `08` D-01); list = Livewire table.

## 10. News — `/admin/news` · 11. Events — `/admin/events`
* **News reference:** table (Title+slug, Status, Published), dialog `max-w-3xl`: Title, Slug (auto if blank), Status, featured image crop, Short description, Full content (rich text). **Events reference:** table (Title+slug, When, Where, Status); dialog: Title, Slug (manual), Date & time (`datetime-local`), Location, **Image URL (pasted, no upload)**, Status, Short description, Full content (**plain textarea**).
* **Target:** same fields; events get image **upload** (consistent with news); content type for events (rich vs plain) per architecture OD #19; events list defaults to upcoming first; no registration UI (DB OD-17). **Type:** as Blog.

## 12. Comments — `/admin/comments`
* **Reference:** table Author(name+email), Post, Comment (2-line), Status badge, actions Approve / Reject / Delete.
* **Target:** filter by status (default: pending), search; actions Approve / **Hide** (DB status `hidden`; reference "rejected" maps to it) / Delete (confirm). Deep link to the article. **Type:** Livewire table.

## 13. Jobs — `/admin/jobs` · 14. Job applications — `/admin/applications`
* **Jobs reference:** table Title/company, Location, Type, Status (draft/published/**closed**); dialog: Title, Company, Location, Employment type (free text), Salary (text), Location type (local/overseas), Status, Description / Requirements / How-to-apply (three rich-text editors), External link. **Target:** same; statuses `draft/published` (`closed` proposed by the reference UI, not in DB — `08` D-10); full-page form; filters. Cannot delete a posting that has applications (server rule).
* **Applications reference:** table Applicant, Job, Applied, Status badge, `Select` (applied, reviewing, shortlisted, rejected, hired). **Target:** same, plus filters by job/status, applicant documents (secure open), and notifying the applicant on status change **only if approved** (DB OD-14). Route renamed `/admin/job-applications` to avoid confusion with membership applications.
* **Type:** Livewire tables; job form Blade.

## 15. Enquiries — `/admin/enquiries`
* **Reference:** table From (name + mailto), Phone (tel link), Subject, Message (2-line), Received, Status badge, actions **View** + status `Select` (new/replied/closed); rows clickable; dialog `max-w-2xl`: subject, received time, name, email, phone, status badge, message in a bordered pre-wrap box. **No reply tool** (status is a manual label; reply happens in the admin's mail client).
* **Target:** same, plus status filter (default: new), search, pagination; "Reply by email" mailto shortcut; `handled_by` recorded. **Type:** Livewire table + Alpine dialog.

## 16. Subscribers — `/admin/subscribers`
* **Reference:** table Email, Status (Active/Unsubscribed), Joined, action Deactivate/Reactivate; header count; **Export CSV** built in the browser.
* **Target:** search + status filter + pagination; toggle via POST (sets/clears `unsubscribed_at`); **server-side CSV export** (streamed). **Type:** Livewire table / Blade.

## 17. Testimonials — `/admin/testimonials` · FAQs — `/admin/faqs` · Team — `/admin/team` · Hero banners — `/admin/hero`
All four use the reference **`CrudManager`**: title + description, "New" button, table (Item, Order, Active, edit/delete), dialog `max-w-lg` with a config-driven field list.

| Page | Fields (reference) | Target changes |
|---|---|---|
| Testimonials | Member name, Designation, **Photo URL**, Testimonial, Order, Active | photo **upload** (public disk); no public surface today (`08` D-07) |
| FAQs | Question, Answer, Order, Active | as is (used on `/membership/faq`) |
| Team | Name, Position, **Photo URL**, Bio, Order, Active | photo upload; no public surface today; "advisory" grouping undecided (DB OD-16) |
| Hero banners | Title, Subtitle, **Image URL**, Button text, Button link, Order, Active | image upload; described as "homepage carousel slides" but home renders **one** banner — carousel or single is TBC (`08` D-07) |

**Target:** one **`x-admin.crud-manager`** (Livewire) driven by a per-entity field config — the only generic admin abstraction. Validation per entity (Form Request rules), server-side; delete confirmation modal; ordering by `display_order` (drag-and-drop **not** in the reference — TBC).

## 18. Social & footer links — `/admin/links` (new)
Reference has **no admin UI** for `social_links` / `footer_links` (they were seeded/edited in the database). Uniform CRUD (platform/title, URL, icon, order, active) via the same crud-manager. Replaces the unused `*_url` fields in Site Settings.

## 19. Reports — `/admin/reports`
* **Reference:** header + month/year selects + **Print** and **Save as PDF** (both `window.print()`); 4 summary cards (New signups 12-mo, Active memberships, Signups in selected month, Memberships in selected month); 2×2 grid: line chart (monthly signups), bar chart (cumulative membership growth), pie (membership type in selected month, 8-colour palette), "Monthly Activity" table (Month, Sign-ups, Memberships, Applications, Enquiries; selected month highlighted). Print stylesheet hides everything except `#report-printable`.
* **Target metrics (approved model):** applications submitted / approved / rejected, activations by category, **free (promotion) vs paid**, payments confirmed (per currency), expiries upcoming, enquiries, members active. Same page structure; month/year filters; printable view. Server-generated PDF is optional (TBC). Chart library and exact charts deferred (`08` D-03). **Type:** Blade (numbers server-side).

## 20. Site settings — `/admin/settings`
* **Reference:** card with `sm:2-col` form: Site name, Site description (textarea), Contact email, Phone, Address (textarea), Logo URL, Favicon URL, Facebook/LinkedIn/Instagram/YouTube URLs; Save.
* **Target:** Site name, description, contact email, phone, address, **logo and favicon upload**, **mail from address/name**; social URL fields removed (Social & footer links). Single form, server validation (email/URL rules — missing in the reference). Audit-logged. **Type:** Blade.

## 21. Email templates — `/admin/email-templates` (new)
List of the 12 keyed templates (M1–M11 + referral invitation): Ref, Name, Active, Updated. Edit: subject + body with a **placeholder reference panel** (available variables per template), plain-text/limited-Markdown editor, **Preview** with sample data. No create/delete. Mandatory templates cannot be deactivated. Edits audit-logged. **Type:** Blade (+ Alpine preview).

## 22. Membership settings — `/admin/membership-settings` (new)
Reapplication cooldown (days), account-setup link lifetime (hours). Could be a section of Site settings. **Type:** Blade.

## 23. Audit log — `/admin/audit-log` (new)
Read-only table: Time, Actor, Event, Subject, IP; filters: event, actor, subject type, date range; row expands to old/new values (redacted). No edit/delete. **Type:** Livewire table.

## 24. Summary

| | Count |
|---|---|
| Reference admin pages | 18 (Overview, Users, Memberships, Membership Applications, Blog, News, Events, Comments, Jobs, Job Applications, Enquiries, Subscribers, Testimonials, FAQs, Team, Hero, Reports, Settings) |
| New pages required by approved architecture | 7 (Promotions, Plans & categories, Bank details, Email templates, Membership settings, Audit log, Social & footer links) |
| Reference pages substantially redesigned | 3 (Membership applications, Memberships, Reports) |
| Livewire 3 components (approved for admin only, `08` C-01) | table base ×1 (reused by ~9 lists) · review screen ×2 (application, membership/payment) · crud-manager ×1 |
