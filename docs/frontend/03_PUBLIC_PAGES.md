# 03 — Public Pages

Every public-facing route in the reference (18 pages + `sitemap.xml`), then the pages the **approved ACI workflow** adds. Abbreviations: **CMS** = admin-editable data; **Static** = developer-maintained Blade; **Blade** / **Blade+Alpine** / **Livewire** = recommended Laravel implementation (`01` §5). "Hero band" = dark `gradient-hero` band with glass badge, H1 with `text-gradient` word, lead paragraph (`06` `x-layout.page-hero`).

Content rule: the reference contains placeholder numbers, legacy rules, Lovable-era copy and "ACSL/Sri Lanka" wording. **Copy is not carried over as approved content** — it is marked where ACI must confirm it (`08` D-09).

## A. Reference pages

### A1. Home — `/`
* **Purpose:** first impression; explain ACI; funnel to membership.
* **Sections:** (1) Hero, white background, 2-col: eyebrow "International Aviation Community" (gold `#D9A233`), H1 + subtitle from the active hero banner (fallback H1 "One Community. One Passion. Aviation."), illustration right. (2) About: badge, H2 "Connecting the Global Aviation Community", 3 paragraphs. (3) Benefits: muted band, badge + H2 "Everything you need to grow.", 6 feature cards (Networking, Career Development, Industry News, Community Events, Professional Support, Job Board). (4) Latest from the blog: 3 post cards + "All articles →".
* **Components:** `page-hero`(home variant), `section`, `badge`, `feature-card`, `post-card`, `empty-state`.
* **Primary CTA:** **none in the reference** (banner `button_text/button_link` exist in data but are never rendered). Target: banner button, default "Become a Member" → `/membership/benefits`. **Secondary:** "All articles".
* **Forms:** none. **Data:** hero banner + latest posts = CMS; About and Benefits = Static. **Auth:** none. **Type:** Blade.
* **Responsive:** hero 2-col from `lg`, stacked below; benefits `sm:2/lg:3`; blogs `md:2/lg:3`.
* **Notes:** reference also fetched testimonials, latest jobs and plans but rendered none (dead data) — do not recreate; a testimonials strip is optional (`08` D-07). Benefit copy claims (LinkedIn badge, member directory, mock exams) are unverified — ACI to confirm.

### A2. About — `/about`
* **Purpose:** mission, vision, values, credibility.
* **Sections:** hero band ("A community built by aviation professional, *in aviation.*") · "Who We Are" card · Mission + Vision (2 cards) · Values ×3 · "What we stand for" ×3 · Motto quote card · **stats strip (2,500+ Active Members / 850+ Student Aviators / 120+ Partner Schools / 500+ Jobs Placed)** · CTA banner (gradient) "Ready to join the community?" with *View Membership* + *Get in touch*.
* **Components:** `page-hero`, `icon-tile`, `card`, `feature-card`, `stat-card`, `cta-banner`.
* **Primary CTA:** View Membership. **Secondary:** Get in touch. **Forms:** none. **Data:** Static.
* **Notes / conflicts:** the stats are **placeholder numbers, not live counts** — omit or replace with verified figures (TBC). CTA copy "Start with a free account, then upgrade" **contradicts the approved model** (no free account tier; membership is by application) — must be rewritten. Team members (admin-managed) are **not shown anywhere** in the reference; an "Our team" section is optional (`08` D-07). **Type:** Blade.

### A3. Membership — `/membership`
Redirects to `/membership/benefits` (no membership home page). Keep the redirect; "Membership" in the header is a dropdown parent.

### A4. Membership Benefits — `/membership/benefits`
* **Purpose:** compare categories, self-check eligibility, explain the process, drive applications.
* **Sections:** hero band ("Choose your *membership.*") · category cards on a `#004d82` shell in a 2-col layout — **Student** (launch banner, price, benefits, eligibility checker), **Veteran** (price, benefits, checker), **Professional** (3 tiers Core/Premier/Inner-Circle with prices + benefits, checker) · **Application Process** (reference: 6 steps incl. a pre-activation "Payment Confirmation" — re-worded per the note below; horizontal chevron band ≥`lg`, 2-col grid below).
* **Components:** `membership.category-card`, `membership.benefit-list`, `membership.eligibility-check` (Alpine accordion: yes/no radios, profession select, proof yes/no, "Apply Now"), `membership.process-steps`, `membership.introductory-offer`.
* **Primary CTA:** Apply Now (each card) → `/membership/apply`. **Secondary:** Check eligibility. **Forms:** the checkers are non-persisted self-assessment.
* **Data:** all **hard-coded** in the reference (LKR 3,500 student, LKR 3,000 veteran, LKR 5,000/10,000/25,000 professional; benefit lists; "Launch Offer — First 100 eligible students get FREE membership"; study-area and profession lists). **Target: data-driven** — names, fees, currency, duration from `membership_plans`/`membership_categories`; the **first-6-month free introductory period** from `membership_settings.introductory_period_months` (standard for every approved new member — **not a promotion**, shown as "First N months free") and the renewal fee from the active plan — never hard-coded. The "first 100 students" offer is a *different* legacy promotion and must not be conflated with the confirmed introductory promotion.
* **Conflicts with approved architecture:** the legacy three Professional tiers are **not carried over** — exactly three categories, Professional = one active plan (`08` C-04, approved); the process must be re-worded to the approved flow: **submit application with proof → admin review (possible request for more details) → approval and activation → first 6 months free → renewal reminder before expiry → pay renewal fee → renewed (normally 12 months)** — there is **no payment step before activation**; the membership number is issued once and kept for life. Eligibility thresholds in the checkers (≥3 months studying, ≥3 years experience) are legacy assumptions — **TBC with ACI** (aviation-proof types are DB OD-20).
* **Auth:** none. **Type:** Blade + Alpine (checkers). **Responsive:** cards stack `<md`; process band → 2-col grid.

### A5. Club Rules — `/membership/rules`
* **Sections:** hero band ("How we *fly together.*") · 4 rule cards (Eligibility, Code of Conduct, Member Responsibilities, Suspension & Termination) with bullet lists · CTA "I agree — Become a Member".
* **Notes:** static text; the CTA **records no consent** anywhere. Content contains legacy assumptions (16+ or parental consent, a "School Club tier", fees non-refundable after approval) — ACI must approve before publication. Consent capture is not modelled in the database (no column) — a checkbox on the application form is optional (`08` D-09).
* **Data:** Static (developer-maintained, unless a CMS page entity is approved — DB OD-17). **Type:** Blade. **Auth:** none.

### A6. Membership FAQ — `/membership/faq`
* **Sections:** hero band ("Common *questions.*", help icon badge) · accordion (single-open, collapsible) of active FAQs ordered by `display_order`; empty message "No FAQs published yet."
* **Components:** `page-hero`, `content.accordion` (Alpine). **Data:** CMS (`faqs`). **Auth:** none. **Type:** Blade + Alpine. **CTA:** none (add "Still have questions? Contact us" — optional).

### A7. Become a Member — `/membership/apply`
* **Purpose:** the application. **Reference is not authoritative** (three separate forms, base64 uploads, no proof for Professional, no status tracking).
* **Layout:** hero band ("Join the *community.*") · 3 category buttons (`aria-pressed`, active = navy fill) · one form card per category · success card.
* **Approved target:** one page, category selected (pre-selectable via `?category=student|professional|veteran` from the benefits cards). Sections: **1 Category** (3 options fed by `membership_categories`, showing plan fee from `membership_plans`) · **2 Personal details** (Full name, Email, Mobile, Address) · **3 Aviation information** (category-specific, below) · **4 Aviation eligibility proof — mandatory for all three categories** (multi-file, image/PDF, size limit from config; reference limit was 5 MB/file) · **5 Submit**.
* **Category fields → database (`membership_applications`):**

| Category | Reference fields | Maps to |
|---|---|---|
| Student | Course Name · Training Institute · Course Start Date · Expected Completion Date | `aviation_role` · `aviation_organisation` · `study_start_date` · `expected_completion_date` |
| Professional | Occupation · Employer/Organisation · *Select plan (Basic/Intermediate/Inner Circle)* | `aviation_role` · `aviation_organisation` · **plan selector removed** (`08` C-04, approved) |
| Veteran | Previous Employer(s) · Position Held · Years of Experience | `previous_employers` · `aviation_role` · `years_experience` · **+ new required field "Most recent aviation employer" → `aviation_organisation`** (reference had no such field) |

* **Behaviour:** Alpine shows only the selected category's fields; **single multipart POST**; server validates per category; mobile validated server-side (reference used `react-phone-number-input`, default country LK — phone widget deferred, `08` D-11). Success page shows: reference (`public_id`), "we will email you at each status change", a **status link** (`/applications/{public_id}` signed), no "Submit another application" (an open application exists; cooldown rules apply).
* **Blocked states to design:** open application already exists for this email → message + link to its status; recently rejected (within cooldown) → message with the date they may reapply; validation failures inline (no toast-only errors).
* **CTA:** Submit application. **Secondary:** Back to benefits. **Auth:** none (throttled). **Data:** categories/plans CMS; form Static. **Type:** **Blade** multipart (not Livewire: sensitive uploads, `01` §5).

### A8. Blog — `/blog`
* **Sections:** hero band ("The ACSL *Blog.*" → ACI) · toolbar: search (title) + category chips ("All" + categories) · grid `md:2/lg:3` of post cards (image 16:10 or gradient placeholder, category badge, title clamp-2, excerpt clamp-3, date `en-GB`, reading time, "Read more →") · Prev/Next with "Page x of y" (9 per page).
* **Target:** GET form `?q=&cat=&page=`; reading time computed (not stored). Empty state "No articles found."
* **Data:** CMS. **Auth:** none. **Type:** Blade. **Notes:** `SLUG_IMAGES` hard-wired fallbacks are dropped.

### A9. Blog article — `/blog/{slug}`
* **Sections:** muted header band (back link, category badge, H1, date, reading time) · featured image (16:8, overlaps band) · article body (sanitised HTML, `max-w-3xl`) · **Comments** (count, form, list with avatar initials, name, date).
* **Comments:** signed-in members see a textarea (2–2000 chars) + "Post comment"; guests see a dashed card "Sign in to join the conversation" + Sign in. Only moderated/approved comments are shown; the default (`pending` vs `approved`) is configuration (DB `10`). Author display name = profile name.
* **Data:** CMS. **Auth:** none to read; required to comment. **Type:** Blade (comment = POST). **404:** "Article not found — Back to blog".

### A10. News & Events — `/news-events`
* **Sections:** hero band ("News & *Events.*") · two columns: **News** (icon tile + title) and **Events**, each a stack of list cards (image 16:9, title, meta date/location, excerpt clamp-3, outline "Read more →"); empty cards "No news yet." / "No upcoming events."
* **Reference gaps:** 30 items each, no pagination, events **not** filtered to upcoming. Target: events default to upcoming, past events secondary (TBC), pagination if > 30.
* **Data:** CMS. **Auth:** none. **Type:** Blade.

### A11. News article — `/news/{slug}` · A12. Event — `/events/{slug}`
* **Layout:** back link "Back to News & Events", H1, meta (date; events add date-time + location), image, excerpt (lead), body. News body is HTML (prose); **event body is plain preformatted text** in the reference — HTML vs plain undecided (architecture OD #19); use the same rich-text component if HTML is chosen.
* **No CTA** (events have no registration — DB OD-17). **Data:** CMS. **Auth:** none. **Type:** Blade. **404** message per type.

### A13. Jobs — `/jobs`
* **Sections:** hero band ("Aviation *jobs.*") · toolbar: search (title/company) · location chips (All / Local / Overseas) · type chips (from data) · **master-detail**: left list (scrolls, `max-h-[75vh]` on `lg`), right sticky detail card.
* **List item:** icon tile, title, company, location, `local|overseas` badge, employment-type badge. **Detail:** title, company, meta (location, type, salary text, posted date), sections Description / Requirements / How to apply (HTML), **Apply Now** (external link) or "Contact us to apply".
* **Reference gap:** in-app applying is not offered from this page although `job_applications` exists (members see them in the dashboard). Target: if `external_link` → external button; else in-app "Apply" for signed-in members (POST; guests → Sign in) — **confirm** (DB OD-14).
* **Target:** server-side filters via GET (`?q=&location=&type=&job=`), selected job in query string; on `<lg` the detail renders below/opens as its own view (TBC). **Data:** CMS. **Auth:** read none. **Type:** Blade.

### A14. Contact — `/contact`
* **Sections:** hero band ("Get in *touch.*") · left 3 info cards (email, phone, address — **hard-coded** `hello@acsl.lk`, `+94 11 234 5678`, "Colombo, Sri Lanka" in the reference; footer reads settings → inconsistent) · right form card "Send us a message — we respond within 1-2 business days".
* **Form:** Name, Email, Phone (required in the reference UI), Subject, Message (≤ 5000). Blade POST → `contact_enquiries` + admin email; success flash; server-side validation; throttled.
* **Target:** info cards read `site_settings`. Whether phone stays mandatory is TBC (column is nullable). **Data:** settings CMS; form Static. **Auth:** none. **Type:** Blade.

### A15. Privacy Policy — `/privacy` · A16. Terms & Conditions — `/terms`
* **Layout:** hero band + `max-w-3xl` article of headed sections; "Last updated: June 2026".
* **Reference content is Lorem ipsum** (8 privacy sections, 10 terms sections) — **do not port**. Section headings are a usable outline. ACI must supply legal text (`08` D-09). **Data:** Static (or `pages` if approved). **Auth:** none. **Type:** Blade.

### A17. Sign in — `/login` (reference `/auth`)
* **Layout:** split screen. Left (≥`lg`): `gradient-hero` brand panel — logo lock-up, headline "*…premier aviation community.*", supporting copy, ©. Right: "← Back to home", card "Welcome back / Sign in to your account".
* **Form:** Email · Password (show/hide) · "Forgot?" link · **Sign in**. Errors inline (generic "credentials do not match"). Redirect server-side by role. **Removed:** "Create account" tab and its fields (first/last name, country, aviation role) — rejected/removed (`08` C-03, approved; see `01` §3). Copy "Join 2,500+ …" removed (unverified).
* **Auth:** guest only (signed-in users redirect). **Type:** Blade (`layouts.auth`). Throttled.

### A18. Forgot / Reset password — `/forgot-password`, `/reset-password/{token}`
* **Forgot:** email → generic confirmation ("If an account exists…"). **Reset:** New password + Confirm (reference min 6; use ≥ 8 with Laravel rules — dashboard used 8), "Update password", "Back to sign in". `noindex`. **Type:** Blade.

### A19. Not found / error
Centred 404 ("Page not found", "Go home") and generic error ("This page didn't load", Try again / Go home). Target: branded Blade views for 404, 403, 419, 429, 500.

### A20. `sitemap.xml` / `robots.txt`
Non-UI. Reference sitemap is broken (relative `<loc>`, duplicate jobs entries, omits news/events/membership/legal). Target: complete absolute-URL sitemap; robots keeps auth/dashboard/admin out.

## B. Pages required by the approved ACI workflow (not in the reference)

These serve the **pre-account applicant** (secure signed expiring link tied to the application — approved in principle, `08` C-05; no access by guessing IDs, no applicant account created just for this) and the signed-in member alike (same routes). Layout: `layouts.public`, narrow card. Authorization is server-side (signed URL validity **or** owner/admin policy) — never a client check.

### B1. Application status — `/applications/{public_id}`
Status timeline built from `membership_status_history` (Submitted → More details requested ⇄ Submitted → Approved / Rejected; there is no "under review" status), category, submitted date, latest admin message (decision note or details request), next-action card:
* `submitted` — "We're reviewing your application."
* `more_details_required` → **B2**.
* `rejected` — reason (if shared), date they may reapply (cooldown), link to Apply.
* `approved` — for **every** new member: approval is not yet activation. Until the membership is activated the page shows "Approved — your membership is being activated" (no payment is required; the first N months are free). Once activated: the membership number, "your first N months are free", and the account-setup notice (activation trigger open — DB OD-23). There is no payment state before activation.
**Type:** Blade.

### B2. Respond to request — `/applications/{public_id}/details`
Shows the admin's request text; response textarea + optional multipart uploads (aviation proof supplements); Submit. Only valid while an unanswered request exists. **Type:** Blade multipart.

### B3. *(Removed — no pre-activation payment)*
The initial membership is free for the first 6 months for every approved new member (OD-10), so there is **no "pay membership fee" page for applicants**. Payment exists only for **renewals**, which are made by the signed-in member on `/dashboard/membership` (renewal fee from the active plan, bank details from the active bank account, reference + private evidence upload — `04_MEMBER_PAGES.md` §4). The ID B3 is kept so existing references stay stable.

### B4. Account setup — `/account/setup/{token}`
One-time link from the welcome email: "Create your password" (new + confirm), submit, then sign-in. Invalid/expired/used → one generic "This link is no longer valid" page with a way to request help (no reason disclosed). **Type:** Blade (`layouts.auth`).

## C. Summary

| | Count |
|---|---|
| Reference public pages | 18 (+ `sitemap.xml`; 404/error views) |
| Of which target changes materially | Apply, Benefits (data-driven), Auth (sign-in only), About (copy/CTA), Rules/Privacy/Terms (content), Jobs (filters/apply) |
| New pages required by approved workflow | 3 (B1, B2, B4; B3 removed) + forgot-password as its own page |
