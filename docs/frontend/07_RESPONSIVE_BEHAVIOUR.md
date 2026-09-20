# 07 — Responsive Behaviour

How the reference behaves (read from its Tailwind classes — **not observed in a browser**, so anything not explicit in the code is **TBC**) and what the Laravel build should do. Reference approach: mobile-first Tailwind defaults; content is fluid inside `container mx-auto px-4 lg:px-8`.

## 1. Breakpoints

| Name | Width | Reference use |
|---|---|---|
| (base) | < 640 | single column, `px-4` |
| `sm` | ≥ 640 | 2-col card grids and form pairs, dashboard stat rows |
| `md` | ≥ 768 | larger type (`md:text-5xl/6xl`), 2–4 column grids, blog/news cards, footer 2-col, `use-mobile` hook threshold (< 768 = mobile) |
| `lg` | ≥ 1024 | **the layout breakpoint**: desktop nav replaces hamburger, member/admin sidebar becomes a left column, jobs master-detail, auth split screen, hero 2-col, footer 4-col, `px-8` |
| `xl`/`2xl` | ≥ 1280 / 1536 | no reference styles (fluid inside container; container max-widths per Tailwind default) |

Target: keep these exact breakpoints. No custom breakpoints.

## 2. By element

| Element | Mobile (< 768) | Tablet (768–1023) | Desktop (≥ 1024) | Notes / TBC |
|---|---|---|---|---|
| **Header** | logo + hamburger; menu panel below header (accordion sub-menu, Sign in / Join Now side by side) | **still mobile header** (nav appears at `lg`) | horizontal nav, hover dropdown, Sign in + Join Now right | Hover-only dropdown is unreliable on touch devices ≥ 1024 (iPad landscape) — target opens on click/focus too. Menu closes on navigation ✓ |
| **Hero band** | H1 `text-4xl`, centred, `py-20` | `md:text-6xl` | same, `max-w-2xl` lead | About hero is left-aligned |
| **Home hero** | text then image stacked; H1 `4xl` | `5xl` | 2-col grid `lg:grid-cols-2`, H1 `6xl`; image `w-full h-auto object-contain` | No hero CTA in reference (`03` A1) |
| **Feature/benefit cards** | 1 col | `sm:2` | `lg:3` | `gap-5` |
| **Blog / news cards** | 1 col | `md:2` | `lg:3` (news-events: two columns from `lg`) | Blog toolbar wraps (`flex-wrap`, search `min-w-[220px]`) |
| **About** | values/stand-for stack | `md:3` cols; stats `md:4` cols (tight at 768 — TBC) | Mission/Vision `lg:2` | |
| **Membership benefits** | category cards stack; process steps 1-col grid | `sm:2` steps grid; category cards `md:2` | steps become one horizontal chevron band | Left column stacks Student + Veteran, right column Professional (3 tiers) |
| **Application form** | category buttons stack (`md:3`); fields 1 col; file row wraps | fields `sm:2` | `max-w-5xl` | Inputs use `text-base` on mobile (16 px, avoids iOS zoom) and `md:text-sm` |
| **Jobs** | list then detail stacked (detail always shows selected item under the list — long page) | same | `grid-cols-[1fr_1.4fr]`; list `max-h-[75vh]` scrolls; detail `sticky top-20` | Mobile master-detail UX **TBC** — recommend detail as its own view/anchor |
| **Contact** | info cards then form | same | `lg:grid-cols-5` (2 + 3) | |
| **Auth** | form only (brand panel hidden `<lg`), `p-6` | form only `md:p-12` | split 50/50 | "Back to home" link on the form side |
| **Footer** | 1 col | `md:2` | `lg:4` | bottom bar stacks → row at `md` |
| **Blog article** | `max-w-3xl`, image `aspect-[16/8]` (very short on narrow screens — **TBC**) | same | same | Comments form full width |
| **Member / admin shell** | **sidebar stacks above content** as a full vertical list (10 or 18 links), not sticky | same | 240 px sticky column + fluid content, `gap-8` | Long list before content on phones — target: horizontal scroll pills or drawer (**TBC**, `08` D-06) |
| **Dashboards / stat cards** | 1 col | `sm:3` (member) / `sm:2` (admin) | admin `lg:4`; two-card rows `lg:2` | |
| **Tables (admin)** | `overflow-auto` wrapper → **horizontal scroll**; some action columns are fixed wide (`w-[20rem]`, `w-[26rem]`, `w-56`) so the scroll is long | same | full width | No card-view fallback in the reference. Target: keep horizontal scroll, prioritise columns, move secondary actions into a row menu; card list for Applications/Memberships on phones is optional (**TBC**) |
| **Forms (admin/settings)** | 1 col | `sm:2` | `sm:2` (`col-span-2` for textareas) | Filter bars `flex-wrap`, fixed widths (`w-48`) |
| **Modals** | centred, `max-w-lg` (2xl/3xl for large forms), edge-to-edge width on narrow screens, corners square below `sm`, `max-h-[90vh]` scroll; footer buttons stack (reverse order) | rounded from `sm` | same | Full-screen sheets on mobile **TBC** |
| **Reports** | charts full width (`ResponsiveContainer`, height 260); summary `grid-cols-2` | `md:4` | charts `lg:2` | Table inside `overflow-x-auto`; print stylesheet hides chrome |
| **Toasts** | top-right | top-right | top-right | Mobile placement TBC |
| **Images** | `w-full`, `object-cover` in aspect boxes (16:10, 16:9, 16:8), lazy loading on lists | same | same | Hero illustration `object-contain` (no crop); srcset/responsive sizes not used (**TBC** — recommend `srcset` for uploaded images) |
| **Typography** | H1 4xl → md 5xl/6xl; H2 3xl → md 4xl/5xl; H3 lg; body sm/base | — | — | Line-height `leading-relaxed` on prose; `-0.02em` heading tracking |
| **Touch targets** | buttons `h-9` (36 px), icon buttons 36 px, nav links `py-2` | — | — | Below the common 44 px guidance — target raises mobile tap height (**TBC**) |

## 3. Behaviours to keep

* Sticky header with glass effect and scroll-triggered border/shadow.
* Container gutters `px-4` (mobile) / `px-8` (`lg`).
* Section rhythm `py-20 md:py-28` and card grids `gap-5`.
* Inputs at 16 px on mobile.
* Dialog scroll containment (`max-h-[90vh] overflow-y-auto`).
* Lazy-loaded list images with fixed aspect ratios (no layout shift).
* Print stylesheet for reports (only `#report-printable` visible).

## 4. Behaviours to change

| Reference | Change |
|---|---|
| Sidebar stacks as a long list on mobile | compact mobile nav (scroll pills or drawer) |
| Hover-only header dropdown | hover + click + keyboard |
| Client-side filtering of jobs/lists | server-side filtering/pagination (also fixes mobile payload) |
| Wide fixed-width admin action columns | row action menu |
| Jobs detail always under list on mobile | separate view/anchor (TBC) |
| Tiny touch targets | ≥ 44 px on mobile for primary actions (TBC) |

## 5. TBC summary

No screenshots or device testing were performed for this task; verify: stats grid at 768 px, blog hero image height on phones, tablet header (menu at 1024), table scroll usability on Applications/Memberships, modal fit on 360 px width, colour contrast of `secondary` text on white.
