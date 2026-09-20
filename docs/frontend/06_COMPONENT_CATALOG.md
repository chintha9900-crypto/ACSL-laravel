# 06 — Component Catalog

83 reusable components (80 for the initial build, 3 deferred). One component per concept — the reference repeated the gradient icon tile, dark hero band, section heading, dashed empty state and status badges across many files; each appears **once** here. Naming: Blade anonymous components `<x-group.name>` under `resources/views/components/`. **Type:** `B` = Blade, `B+A` = Blade + Alpine, `LW` = Livewire 3 (approved for server-interactive admin functionality only, `08` C-01; never for private document uploads), `CSS` = utility class not a component.

Tokens/classes referenced (`02`): `gradient-primary`, `gradient-hero`, `shadow-card/elegant/glow`, `primary/secondary/muted/accent/destructive`.

## 1. Layout (7)

| Component | Purpose | Visual | Variants | Used in | Type | Depends on |
|---|---|---|---|---|---|---|
| `layouts.public` | Marketing shell | sticky header · `<main class="flex-1">` · footer; `min-h-screen flex-col` | — | all public + applicant pages | B | site.header, site.footer, ui.toast, x-seo |
| `layouts.auth` | Sign-in/reset shell | `min-h-[calc(100vh-4rem)] lg:grid-cols-2`; left `gradient-hero` panel (≥lg), right centred `max-w-md` | with/without brand panel | login, forgot, reset, account setup | B | logo, ui.toast |
| `layouts.app` | Member/admin shell | header + `lg:grid-cols-[240px_1fr] gap-8 py-8`, sticky sidebar, `min-w-0` main | member / admin nav set | `/dashboard/*`, `/admin/*` | B | site.header, app.sidebar |
| `layout.page-hero` | Dark hero band | `bg-primary` + `gradient-hero opacity-95`, `py-20 md:py-24`, centred badge + H1 (+`text-gradient` word) + lead | left-aligned (about), centred; home hero (white, 2-col with image) | every public page | B | ui.badge, `.text-gradient` |
| `layout.section` | Section wrapper | `container mx-auto px-4 lg:px-8`, vertical rhythm | `default`, `muted` (`bg-muted/50 border-y`), `narrow` (max-w-3xl/5xl) | all pages | B | — |
| `layout.cta-banner` | Closing call-to-action | `gradient-hero` card, centred H2 + text + 2 buttons | — | about, (benefits) | B | ui.card, ui.button |
| `app.page-header` | Page title row | H1 `font-display text-3xl font-bold` + muted description; right action slot | with/without action | all member/admin pages | B | ui.button |

## 2. Navigation (7)

| Component | Purpose | Visual | Variants | Used in | Type | Depends on |
|---|---|---|---|---|---|---|
| `site.header` | Public/app top bar | `h-16` sticky glass, logo left, nav centre, actions right; scroll adds border+shadow | guest / signed-in ("Dashboard") | layouts | B+A | nav.*, ui.button |
| `nav.dropdown` | Desktop sub-menu | panel `rounded-lg border shadow-elegant p-1.5 min-w-56`; opens on hover **and** click/keyboard (reference: hover only) | — | Membership menu | B+A | nav.link |
| `nav.mobile-menu` | <lg menu | full-width panel; accordion sub-menu with left border; Sign in + Join Now row | — | header | B+A | nav.link |
| `nav.link` | Nav item | `text-sm font-medium text-foreground/75 hover:text-primary`; active `text-primary font-semibold` | desktop / mobile (bg-muted active) | header | B | — |
| `app.sidebar` | Section nav | title + links (`px-3 py-2 rounded-md`, icon 16 px), active navy fill; grouped headings (admin); footer links | member / admin | layouts.app | B | `app.sidebar-link`, x-icon |
| `site.footer` | Site footer | `bg-primary`, 4 cols (brand+social, Explore, Legal, Contact) + bottom bar | full / slim (app areas) | layouts | B | content.social-links |
| `ui.back-link` | "← Back to …" | ghost small button with left arrow, `-ml-3` | — | blog, news, event, auth | B | ui.button |

## 3. Typography (4)

| Component | Purpose | Visual | Variants | Used in | Type | Depends on |
|---|---|---|---|---|---|---|
| `ui.section-heading` | Badge + H2 + lead | outline badge, `font-display text-3xl md:text-5xl font-bold text-primary`, muted lead | left / centred (`max-w-2xl mx-auto`), compact | home, about, benefits, blog list | B | ui.badge |
| `ui.meta-item` | Icon + short text | `text-xs text-muted-foreground`, 12–16 px icon | date, clock, pin | blog, news, events, jobs | B | x-icon |
| `content.rich-text` | Sanitised HTML output | prose styling (`font-display` headings, `text-secondary` links, lists, quotes) | article, compact | blog, news, jobs | B | — (typography plugin or `.rich-text` CSS, `08` D-01) |
| `x-icon` | Inline SVG icon | 16/20/24 px, `currentColor` | size prop | everywhere | B | icon set (TBC) |
| *(CSS)* `.text-gradient` | Highlighted heading word | gradient text | — | page-hero | CSS | tokens |

## 4. Buttons (3)

| Component | Purpose | Visual | Variants | Used in | Type | Depends on |
|---|---|---|---|---|---|---|
| `ui.button` | All buttons and button-links (`href` renders `<a>`) | `h-9 px-4 rounded-md text-sm font-medium`; focus ring 1 px | `primary`, **`cta`** (gradient + glow), `secondary`, `outline`, `ghost`, `link`, `destructive`, `on-dark`, `gold`; sizes `sm`/`default`/`lg`/`icon`; `loading` state | everywhere | B | — |
| `ui.icon-button` | Icon-only action | `h-9 w-9` ghost, tooltip/`aria-label` required | default, destructive icon | admin rows, modals, password eye | B | ui.button |
| `ui.filter-chip` | Toggle filter | `sm` button; selected = filled, unselected = outline | primary, secondary/ghost | blog categories, jobs filters | B | ui.button |

## 5. Forms (12)

| Component | Purpose | Visual | Variants | Used in | Type | Depends on |
|---|---|---|---|---|---|---|
| `form.field` | Label + control + hint + error | `space-y-1.5`; label `text-sm`; hint/error `text-xs` | required marker, inline | all forms | B | — |
| `form.input` | Text-like input | `h-9 rounded-md border-input shadow-sm text-base md:text-sm`, focus ring | text, email, url, number, date, datetime-local, tel | all forms | B | form.field |
| `form.textarea` | Multi-line | same styling, `rows` prop | — | contact, comments, admin | B | form.field |
| `form.select` | Native select styled as input | trigger like input | with placeholder | admin filters/forms, benefits checker | B | form.field |
| `form.switch` | Boolean | Radix-style switch + "Visible/Hidden" caption | — | CRUD forms | B+A | form.field |
| `form.radio-group` | Yes/No or few options | inline radios | on-dark variant | eligibility checker | B | form.field |
| `form.file-upload` | Multi-file dropzone list | `border-dashed rounded-lg p-4`, Upload button, file rows with remove | proof (image/PDF), evidence, single image | apply, payment, respond | B+A | ui.button |
| `form.phone-input` | Phone entry | matches input; default country LK; **deferred implementation** (`08` D-11) | — | apply, contact | B+A | form.input |
| `form.password-input` | Password + reveal | input + eye icon-button | — | login, reset, setup, security | B+A | ui.icon-button |
| `form.search-input` | Search box | input with left search icon | GET form / Livewire live | blog, jobs, admin lists | B | form.input |
| `form.rich-text-editor` | HTML editor | bordered box, toolbar (bold, italic, strike, P, H1–H3, lists, quote, undo/redo) | compact | blog, news, jobs, email templates (plain variant) | B+A | editor lib (`08` D-01) |
| `form.image-upload` | Image + crop | 1200×630 JPEG crop with zoom, preview, remove | featured (16:8), avatar (1:1), photo | blog, news, events, hero, team, testimonials, profile | B+A | cropper lib (`08` D-02) |

## 6. Cards (10)

| Component | Purpose | Visual | Variants | Used in | Type | Depends on |
|---|---|---|---|---|---|---|
| `ui.card` | Surface | `rounded-xl border bg-card shadow`; slots header/title/content/footer | `marketing` (`shadow-card border-border/60`), `hover` (lift + elegant), `dashed`, `dark` (`#004d82`, `rounded-2xl`) | everywhere | B | — |
| `ui.icon-tile` | Icon on gradient | `h-12 w-12 rounded-xl gradient-primary` | sizes 9/11/12/14, glow on hover | features, about, contact, jobs, lists | B | x-icon |
| `content.feature-card` | Icon + title + text | card `p-6/7`, tile, H3, `text-sm` text | — | home, about | B | ui.card, ui.icon-tile |
| `content.post-card` | Blog teaser | image 16:10 or gradient placeholder, category badge, H3 clamp-2, excerpt clamp-3, date + read time, "Read more" | link-wrapped | home, blog list | B | ui.card, ui.badge, ui.meta-item |
| `content.list-card` | News/event row | image 16:9, title, meta, excerpt clamp-3, outline button | news / event | news-events | B | ui.card |
| `content.job-item` | Job list entry | tile, title, company, location, badges; selected = `border-secondary bg-secondary/5 shadow-elegant` | selected | jobs | B | ui.icon-tile, ui.badge |
| `content.job-detail` | Job detail card | large tile, title, meta, three labelled rich sections, Apply | — | jobs | B | content.rich-text, ui.button |
| `ui.stat-card` | Number + label | `font-display text-2xl/3xl font-bold`, uppercase muted label | icon (about), plain (dashboards) | about, member, admin | B | ui.card |
| `ui.info-card` | Icon + label + value | tile + uppercase label + value | — | contact | B | ui.card, ui.icon-tile |
| `content.comment` | Comment entry | avatar initials, name, date, pre-wrap text | with delete (member) | blog, my comments | B | ui.avatar |

*(Sub-element)* `ui.avatar` (B): circle with image or initials — used by comment and profile; counted under Cards.

## 7. Tables (4)

| Component | Purpose | Visual | Variants | Used in | Type | Depends on |
|---|---|---|---|---|---|---|
| `admin.data-table` | List table | bordered `rounded-md`, `overflow-x-auto`, header muted `h-10`, row hover, empty text; columns declared as config | sortable header, row-click, sticky actions column | all admin lists | LW base (B fallback) | ui.pagination |
| `admin.table-toolbar` | Search + filters row | `flex flex-wrap gap-3`; search, selects, chips; counts | — | admin lists | LW | form.search-input, form.select |
| `admin.row-actions` | Trailing action cell | right-aligned icon buttons / small buttons | edit-delete, workflow buttons | admin lists | B | ui.icon-button, ui.confirm-dialog |
| `admin.status-select` | Inline status change | small `h-8` select posting on change | — | job applications, enquiries | LW | form.select |

## 8. Modals (2)

| Component | Purpose | Visual | Variants | Used in | Type | Depends on |
|---|---|---|---|---|---|---|
| `ui.modal` | Dialog | overlay `bg-black/80`, centred panel `p-6 gap-4 border shadow-lg sm:rounded-lg`, scroll `max-h-[90vh]`, close ×, footer actions stack on mobile; focus trap + Esc | `md` (max-w-lg), `lg` (2xl), `xl` (3xl) | CRUD forms, request-details, reject, enquiry view | B+A | ui.button |
| `ui.confirm-dialog` | Confirm destructive/lifecycle action | modal with message + form POST + destructive/primary confirm | destructive, primary | deletes, approve/reject, suspend, role change | B+A | ui.modal |

## 9. Alerts (2)

| Component | Purpose | Visual | Variants | Used in | Type | Depends on |
|---|---|---|---|---|---|---|
| `ui.alert` | Inline notice | `rounded-lg border px-4 py-3 text-sm` with icon | info, success, warning, destructive | payment states, cooldown, more-details request | B | x-icon |
| `ui.toast` | Flash message | top-right stack, auto-dismiss, coloured by type | success, error, warning | all layouts | B+A | session flash |

## 10. Badges (2)

| Component | Purpose | Visual | Variants | Used in | Type | Depends on |
|---|---|---|---|---|---|---|
| `ui.badge` | Label chip | `rounded-md border px-2.5 py-0.5 text-xs font-semibold` | `default`, `secondary`, `destructive`, `outline`, `eyebrow` (outline + `border-secondary text-secondary`), `glass` (white/10 on dark), `small` | everywhere | B | — |
| `ui.status-badge` | Status → badge mapping | wraps `ui.badge`; one central map: application (`submitted`=secondary, `more_details_required`=outline, `approved`=default, `rejected`=destructive), membership term (`pending_payment`=warning, `active`=success, `expired`=muted; introductory term labelled "Free introductory period"), payment status (five + gateway statuses), job/enquiry/comment statuses | by domain | member + admin | B | ui.badge |

## 11. Pagination (1)

| Component | Purpose | Visual | Variants | Used in | Type | Depends on |
|---|---|---|---|---|---|---|
| `ui.pagination` | Page control | outline small "‹ Previous" / "Next ›" with "Page **x** of y" (reference); Laravel paginator view | simple; numbered (admin) | blog, jobs, news, admin lists, notifications | B | Laravel paginator |

## 12. Empty (1), Loading (1), Error (2)

| Component | Purpose | Visual | Variants | Used in | Type | Depends on |
|---|---|---|---|---|---|---|
| `ui.empty-state` | "Nothing here" | dashed card, centred muted text, optional CTA (e.g. "Browse jobs") | card, inline | every list | B | ui.card |
| `ui.loading` | Pending indicator | spinner / skeleton bar; button `loading` state | Livewire `wire:loading` only (reference used plain "Loading…" text; its skeleton component was unused) | admin tables, review actions | LW | — |
| `ui.error-page` | Branded error view | centred large code (404) or title, message, "Go home"/"Try again" | 404, 403, 419, 429, 500 | `errors.*` | B | ui.button |
| `form.error` | Inline validation message | `text-xs text-destructive` under field; summary at top for long forms | field, summary | all forms | B | form.field |

## 13. Membership (12)

| Component | Purpose | Visual | Variants | Used in | Type | Depends on |
|---|---|---|---|---|---|---|
| `membership.category-card` | Category summary | dark `#004d82`-style card (`rounded-2xl`), icon tile, name, price+period, benefits, checker slot | student / professional / veteran; **data from plans** | benefits, (apply selector) | B | ui.card, membership.benefit-list |
| `membership.benefit-list` | Tick list | check icon (gold) + text, dashed dividers | on-dark | benefits | B | x-icon |
| `membership.process-steps` | Application process | ≥lg horizontal chevron band; <lg 2-col grid; numbered steps with icons; step 5 conditional | — | benefits | B | x-icon |
| `membership.eligibility-check` | Self-assessment | collapsible panel: radios, select, "Apply Now"; non-persisted | student / professional / veteran | benefits | B+A | form.radio-group, form.select |
| `membership.introductory-offer` | Introductory free-period notice | tinted alert-like bar with sparkle icon, "First N months free for every approved new member" — N from `membership_settings` (not a promotion) | on-dark, on-light | benefits, apply, member membership | B | ui.alert |
| `membership.status-timeline` | Lifecycle history | vertical list of events (actor, note, time) | applicant view / admin view | status page, admin detail | B | ui.badge |
| `membership.digital-card` | Membership card | branded card: logo, name, **number**, category, status, valid-until; print/PDF stylesheet | screen / PDF | member card | B | logo |
| `membership.payment-instructions` | Bank details + reference | labelled rows from active bank account, copy buttons | — | member renewal payment | B+A | ui.card |
| `membership.details-request` | Admin request + response | request text card + response form/history | applicant / admin | respond page, admin detail | B | form.textarea, form.file-upload |
| `membership.application-summary` | Read-only application facts | labelled rows by category | applicant / admin | status page, admin detail | B | admin.info-grid |
| `membership.next-action-card` | "What to do next" | primary card with one CTA driven by state | per state | dashboard overview, status page | B | ui.card, ui.button |
| `membership.application-fields` | Category-specific field sets | partials rendered per category (Alpine show/hide + server validation) | student / professional / veteran | apply | B+A | form.* |

## 14. Admin (8)

| Component | Purpose | Visual | Variants | Used in | Type | Depends on |
|---|---|---|---|---|---|---|
| `admin.crud-manager` | Generic list + modal form | table (Item, Order, Active, actions) + `New` + config-driven form | per entity config | testimonials, FAQs, team, hero, social/footer links, bank accounts? | LW | data-table, ui.modal, form.* |
| `admin.review-panel` | Application decision | action buttons with guard states (Approve disabled until proof reviewed) + modals | — | application detail | LW | ui.confirm-dialog, ui.modal |
| `admin.payment-panel` | Payment review | evidence list, reference, Confirm / Reject | — | membership detail | LW | admin.document-list, ui.modal |
| `admin.document-list` | Secure file list | rows (name, type, size, uploaded, **Open** via policy route) | proof, evidence, job docs | review + payment + job app | B | ui.icon-button |
| `admin.info-grid` | Label/value grid | `grid-cols-2 gap-3`, muted label, `font-medium` value (mono variant) | 1–3 columns | detail pages, dialogs | B | — |
| `admin.action-card` | Dashboard queue tile | link card: label, count, "Action" badge when > 0 | tone warning/info | admin dashboard | B | ui.card, ui.badge |
| `admin.template-editor` | Email template edit | subject + body + placeholder panel + preview | — | email templates | B+A | form.*, ui.alert |
| `admin.chart` | Report chart | line / bar / pie in card | **deferred** (`08` D-03) | reports | — | chart lib |

## 15. Content (5)

| Component | Purpose | Visual | Variants | Used in | Type | Depends on |
|---|---|---|---|---|---|---|
| `content.accordion` | Q&A list | single-open collapsible, bold left-aligned trigger, muted answer, chevron | single / multiple | FAQ | B+A | — |
| `content.social-links` | Social icon row | `h-9 w-9 rounded-full bg-white/10 hover:bg-secondary` icons | footer | footer | B | x-icon |
| `x-seo` | Meta/OG/canonical | head partial: title, description, canonical, `og:*`, `robots` | public / noindex | layouts | B | seo_pages |
| `content.testimonial-card` | Member quote | photo, name, designation, quote | **deferred** (no public surface, `08` D-07) | — | B | ui.card, ui.avatar |
| `content.team-card` | Team member | photo, name, position, bio | **deferred** (`08` D-07) | — | B | ui.card, ui.avatar |

## 16. Totals

| Group | Count |
|---|---|
| Layout 7 · Navigation 7 · Typography 4 · Buttons 3 · Forms 12 · Cards 10 (+ avatar sub-element) · Tables 4 · Modals 2 · Alerts 2 · Badges 2 · Pagination 1 · Empty 1 · Loading 1 · Error 2 · Membership 12 · Admin 8 · Content 5 | **83** |
| Deferred (no build in first pass) | 3 (`admin.chart`, `content.testimonial-card`, `content.team-card`) |
| Livewire 3 (approved, admin only — `08` C-01) | 7 (`admin.data-table`, `admin.table-toolbar`, `admin.status-select`, `admin.crud-manager`, `admin.review-panel`, `admin.payment-panel`, `ui.loading`) |

Reference `components/ui/*` files **not** needed (unused or replaced by native/Alpine): breadcrumb, calendar, carousel, chart wrapper, command, context-menu, drawer, hover-card, input-otp, menubar, navigation-menu, popover, progress, resizable, scroll-area, sheet, sidebar (shadcn), skeleton, slider (crop only), tabs (auth only), toggle, toggle-group, tooltip, collapsible.
