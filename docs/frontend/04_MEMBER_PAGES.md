# 04 — Member Pages

The reference member area (`/dashboard/*`, 10 pages) is documented for layout and UX. **The reference membership and billing behaviour is not authoritative**: it showed the legacy "System A" membership table with manually entered payment links and free-text statuses. The pages below implement the approved workflow (`docs/architecture/04_MEMBERSHIP_ARCHITECTURE.md`, `docs/database/04_MEMBERSHIP_SCHEMA.md`, `06_PAYMENT_SCHEMA.md`).

Access: `auth` middleware, active user (`users.status = active`); all data scoped to the signed-in user through policies. Implementation types: **Blade** unless stated.

## 1. Shell (reference → target)

| | Reference | Target |
|---|---|---|
| Layout | public header + `grid lg:grid-cols-[240px_1fr] gap-8`, container `py-8`; sidebar sticky `lg:top-24` | Same structure inside `layouts.app`; slim footer (`08` D-06) |
| Sidebar | title "Member Area"; 10 links (icon 16 px + label); active `bg-primary text-primary-foreground`; "Admin Panel" link (server flag); red "Sign out" ghost button | 8 links (`01` §4); "Admin panel" only if the server authorises it; Sign out = POST form |
| Below `lg` | sidebar stacks above content (all links, no drawer) | horizontal scroll pills or drawer — **TBC** (`07`) |
| Page header | `h1 font-display text-3xl font-bold` + muted subtitle | same (`x-app.page-header`) |
| Loading | "Loading…" text | none (server-rendered) |

## 2. Overview — `/dashboard`
* **Reference layout:** greeting "Welcome back, {name}" · 3 stat cards (Membership status, Unread notifications, Job applications) · two cards: Recent notifications (title, 2-line message, "New" badge, "View all →") and Recent job applications (title, company, status badge).
* **Defects to fix:** "unread" counted only the 5 most recent notifications; "Membership" showed the legacy System-A status (or "Not applied").
* **Target functionality:**
  * **Membership card (primary):** current state from `memberships` + `membership_applications` with one **next action** — *Apply* · *Respond to request* · *Pay fee* · *Awaiting confirmation* · *View digital card* · *Renew* (renewal rules pending, DB OD-04).
  * Stat cards: membership status, **true unread count** (`read_at IS NULL`), job applications.
  * Recent notifications (5) and recent job applications (5).
* **Data:** `memberships`, `membership_applications`, `notifications`, `job_applications`. **Type:** Blade.

## 3. Profile — `/dashboard/profile`
* **Reference layout:** cards — *Avatar* (80 px circle + "Upload new avatar", "JPG or PNG, max 2MB" — limit not enforced) · *Personal information* (2-col grid: first name, last name, country, aviation role, other role, phone, occupation, company, LinkedIn URL; bio textarea; Save) · *Change password* (duplicate of the Password page).
* **Target:** fields map to `users` (`first_name`, `last_name`, `country`, `aviation_occupation`, `job_title`, `company`, `phone`, `linkedin_url`, `bio`, `avatar_path`); "Other role" merged into `aviation_occupation`. Avatar upload via multipart to the **public** disk (server-enforced type/size; old file removed). **Duplicate password card removed** (one place: Security). Membership number is **read-only** here (from `memberships`, not an email-matching lookup as in the reference). Email change is not offered (identity/verification rules TBC).
* **Type:** Blade (Form Request validation, `url` rule on LinkedIn).

## 4. Membership — `/dashboard/membership`
* **Reference layout:** list of cards: plan name, status badge (active green / pending yellow / expired muted / rejected red), Price `$x / n mo`, Start, Expires, optional "Pay now" (external payment link). Empty: "You don't have an active membership yet" + Apply now.
* **Target layout (states from the approved workflow):**

| State (data) | Content |
|---|---|
| No application | Empty card + **Apply now** (→ `/membership/apply`) |
| Application `submitted` | Summary (category, submitted date), "Under review", link to status page |
| `more_details_required` | Admin's request, **Respond** button (→ B2 in `03`) |
| `rejected` | Decision note, "You may reapply from {date}" (cooldown from settings) or **Apply again** if eligible |
| `approved` / membership `pending_activation` + `payment_pending` | Fee, bank details, **Pay membership fee** (B3) |
| `payment_confirmation_submitted` | "Payment evidence submitted — awaiting confirmation" (reference shown, evidence list) |
| Payment rejected (back to `payment_pending`) | Reason + resubmit form (application is **not** restarted) |
| Membership `active` | Membership number, category, status badge, **Valid until**, promotion badge ("Free membership — {promotion name}") when applied, **Digital card** (view/download), account/security link |
| Membership `expired` | Expired badge + last term; **Renew** (rules pending — DB OD-04) |

* **History:** table/list of all terms and applications (each with dates, status, promotion, fee snapshot) — "historical membership records" are preserved by design.
* **Removed:** "Pay now" external links; `$` prices (currency from the membership snapshot; currency itself TBC — DB OD-01).
* **Type:** Blade; payment/response forms are multipart POST.

## 5. Digital membership card — `/dashboard/membership/card`
* **New** (no reference UI). **Content (approved):** ACI branding/logo, member name, **membership number**, category, status, valid-until date — nothing else (no address/contact). **Actions:** View on screen, **Download PDF** (generated on demand, `07_FILE_STORAGE_ARCHITECTURE.md` §5). Future QR verification token is reserved (DB) — no UI now.
* **Availability:** only when `status = active`. Visual design of the card is TBC (architecture OD #8) — use design tokens (navy card, gradient accent).
* **Type:** Blade (print/PDF view shared).

## 6. Billing / payments — folded into Membership
The reference *Billing Details* page (payment status + "Pay now") **does not exist as a separate page**. A **Payment** section appears on the Membership page when a payment row exists: fee, currency, status (`pending`, `processing`, `paid`, `failed`), submitted reference, evidence documents (download via policy), rejection reason, paid date. Free (promotional) memberships show "No payment required" — **never** a £0 payment or receipt. Invoice/receipt PDFs: not confirmed (TBC).

## 7. Notifications — `/dashboard/notifications`
* **Reference layout:** header "Notifications" + "{n} unread" + "Mark all read"; list of cards (title, "New" badge, message, timestamp, per-item "Mark read"); unread card `border-primary/40`. Empty: "No notifications."
* **Target:** same layout; unread = `read_at IS NULL` over **all** notifications, paginated (reference capped at 100 with no pagination); items may deep-link to the related page (e.g. Pay membership fee). Mark read / mark all read are POSTs. In-app notifications supplement, not replace, email (M1–M11). **Type:** Blade.

## 8. Security (password) — `/dashboard/security` (reference `/dashboard/password`)
* **Reference layout:** card "New password": New password (show/hide eye) + Confirm + Update; min 8 chars.
* **Target:** add **Current password** (missing in the reference — a session-hijack risk), show/hide toggle, strength rules from Laravel `Password` defaults. Optional: "Sign out other devices" (TBC). **Type:** Blade.

## 9. Job Applications — `/dashboard/applications`
* **Reference layout:** header "Job applications — Track your submitted applications"; list of cards (title, "company · location · type", "Applied {date}", status badge); empty: "No applications yet" + Browse jobs. **Read-only** — no apply/withdraw here.
* **Target:** same; statuses `applied · reviewing · shortlisted · rejected · hired` (provisional, DB OD-14); applicant documents shown if uploaded; rows link to the posting. **Type:** Blade. (Route renamed `/dashboard/job-applications` to avoid confusion with membership applications.)

## 10. My Comments — `/dashboard/comments`
* **Reference layout:** cards: article link, status badge (pending/approved/rejected), comment text, date-time, "Delete". Empty text. Delete is immediate.
* **Target:** same; status vocabulary from DB (`pending/approved/hidden`); delete via confirm modal (POST). Edit not offered (as reference). **Type:** Blade.

## 11. Refer a Friend — `/dashboard/refer`
* **Reference layout:** header "Refer a Friend — Invite as many friends and colleagues…"; card "Send an invitation": single email input (max 255, client regex) + Send (`sm:` row); helper "Your friend receives an email inviting them to review the membership eligibility requirements and submit an application."; card "Invitations sent in this session" (in-memory list).
* **Target:** same single-email form (POST, throttled); the **session list becomes a persisted history** of the member's invitations (`referral_invitations`: invitee email + date) — the confirmed minimum. No codes, tracking, rewards or address book (unresolved, DB OD-15). Success/failure flash (the reference's "sent but not emailed" warning becomes an honest queued-email flash). **Type:** Blade.

## 12. Promotions — **dropped**
The reference page listed the public `membership_benefits` bullets as "Member perks" — not a promotions engine. Real promotions are data (`membership_promotions`) and surface **inside the membership flow** (banner on benefits, badge on the member's membership). No sidebar item (`08` D-07 if ACI wants member perks later).

## 13. Other member routes discovered
None. The member area is exactly the 10 routes above (Overview, Profile, Membership, Billing, Notifications, Promotions, Comments, Job Applications, Refer, Password). `/verify` and shop routes referenced in older notes do not exist in the snapshot.

## 14. Summary

| | Reference | Target |
|---|---|---|
| Pages | 10 | 9 (Overview, Profile, Membership, Digital card, Notifications, Security, Job applications, My comments, Refer) — Billing merged, Promotions dropped, Card added |
| Livewire needed | — | none required; Blade + Alpine suffices (`01` §5) |
| Applicant-only pages (no account) | — | 4 in `03` §B |
