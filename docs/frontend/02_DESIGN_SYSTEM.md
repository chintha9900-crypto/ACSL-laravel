# 02 — Design System

Extracted from the reference (`src/styles.css`, `components/ui/*`, page/component classes). Values are exact unless marked **TBC**. The reference tokens are Tailwind 4 `@theme inline` + CSS variables in `oklch`; the Laravel project already runs Tailwind 4, so they can be used verbatim in `resources/css/app.css`.

Reference design-system header: *"Navy + Sky Blue + White. Headings: Sora | Body: Plus Jakarta Sans"*.

**Brand status (`08` C-02, approved):** the tokens below are the **starting point** only. Final colours and the logo are **controlled brand assets** supplied by ACI; the logo is never invented or redesigned. The application is branded "Aviation Club International" / "ACI" — the legacy ACSL name is not used.

## 1. Colour tokens (light theme)

| Token | oklch | Reference hex (source comment) | Use |
|---|---|---|---|
| `background` | `1 0 0` | #FFFFFF | page background |
| `foreground` | `0.27 0.04 257` | #1F2937 (dark slate) | body text |
| `card` / `popover` | `1 0 0` | — | card and popover surface |
| `primary` | `0.22 0.08 257` | #0A2342 (navy) | headings, primary buttons, footer, active nav |
| `primary-foreground` | `1 0 0` | — | text on primary |
| `primary-glow` | `0.65 0.16 230` | — (sky) | gradient end, glow shadow |
| `secondary` | `0.65 0.16 230` | #4EA8DE (sky) | badges, links, accents, hover |
| `secondary-foreground` | `1 0 0` | — | |
| `muted` | `0.97 0.005 250` | #F5F7FA | alternate section background, table hover |
| `muted-foreground` | `0.5 0.02 257` | — | secondary text |
| `accent` | `0.95 0.025 230` | — | hover backgrounds (ghost/outline/select) |
| `accent-foreground` | `0.22 0.08 257` | — | |
| `destructive` | `0.6 0.22 25` | — | errors, delete, "Action" badge |
| `success` | `0.62 0.15 155` | — | defined, barely used |
| `warning` | `0.78 0.15 75` | — | defined, barely used |
| `border` / `input` | `0.92 0.01 250` | — | borders and input outlines |
| `ring` | `0.65 0.16 230` | — | focus ring (1 px) |
| sidebar set | `sidebar 0.22 0.08 257`, `sidebar-primary 0.65 0.16 230`, `sidebar-accent 0.28 0.07 257`, `sidebar-border 0.32 0.06 257` | — | **defined but unused**: the reference app sidebars use `bg-primary`/`bg-muted`, not these tokens |

Hex for tokens without a source comment: **TBC** (not needed — use the `oklch` values).

### Off-token colours found in the reference (must be resolved, not copied blindly)

| Value | Where | Recommendation |
|---|---|---|
| `#D9A233` (gold) | home hero eyebrow text | gold accent — unresolved until ACI supplies the final palette (controlled brand asset, `08` C-02) |
| `#004d82` (`CARD_BG`) | membership benefit cards, process-steps band, benefits hero | a *second* blue, brighter than `primary`; tokenise as `brand-blue` **or** replace with `primary` |
| Tailwind `yellow-400/300` | benefit tick marks, "Check eligibility" buttons, launch-offer banner, card left border | a second accent (gold/yellow) used only on the benefits page; tokenise as `accent-gold` |
| `#004d82, #f5b301, #0e7490, #7c3aed, #dc2626, #16a34a, #ea580c, #0891b2` | Recharts series/pie colours | chart palette — deferred (`08` D-03) |
| `bg-green-500/15 text-green-700`, `bg-yellow-500/15 text-yellow-700` | member membership status badge | replace by `success`/`warning` token badges |
| `text-black`, `text-neutral-600` | home hero heading/subtitle | use `foreground`/`muted-foreground` unless the white hero is deliberate |

### Dark theme

`.dark` token overrides exist (`background 0.18 0.05 257`, `primary 0.65 0.16 230`, `card 0.22 0.06 257`, …) with a `@custom-variant dark`, but **no toggle, `next-themes`, or `class="dark"` is ever applied**. Dark mode is effectively unused — not implemented (`08` D-04).

## 2. Gradients and effects (utilities)

| Utility | Definition |
|---|---|
| `gradient-hero` | `135deg, oklch(0.22 0.08 257) 0%, oklch(0.32 0.1 250) 50%, oklch(0.45 0.14 235) 100%` |
| `gradient-primary` | `135deg, primary 0%, primary-glow 100%` — primary CTA fill, icon tiles |
| `text-gradient` | `135deg, primary-glow → oklch(0.78 0.12 220)`, `background-clip:text`, transparent text — the highlighted word in dark hero headings |
| `shadow-card` | `0 1px 3px 0 oklch(0 0 0/0.05), 0 4px 16px -4px oklch(0.18 0.08 250/0.08)` |
| `shadow-elegant` | `0 10px 40px -10px oklch(0.18 0.08 250/0.25)` — hover cards, dropdown, dialogs, key cards |
| `shadow-glow` | `0 0 60px -10px oklch(0.65 0.16 230/0.4)` — primary CTA, icon tiles |
| Header glass | `bg-background/60 backdrop-blur-sm`; after scroll > 8 px `bg-background/85 backdrop-blur-md border-b shadow-card` |
| Card hover | `hover:shadow-elegant hover:-translate-y-1 transition-all` |

## 3. Typography

| Item | Value |
|---|---|
| Body font | **Plus Jakarta Sans** (`--font-sans`), weights 400–800 loaded, `-webkit-font-smoothing: antialiased`, fallback `system-ui, sans-serif` |
| Display font | **Sora** (`--font-display`), weights 400–800; applied to `h1–h6` with `letter-spacing: -0.02em` |
| Source | Google Fonts `css2` (preconnect). Self-hosting: deferred (`08` D-05). |
| Base size | browser default 16 px; body text `text-sm`/`text-base` |

| Role | Classes (reference) |
|---|---|
| Page hero H1 (dark band) | `font-display text-4xl md:text-6xl font-bold` (+ highlighted `text-gradient` word) |
| Home hero H1 | `font-display text-4xl md:text-5xl lg:text-6xl font-extrabold tracking-tight leading-[1.08]` |
| Section H2 | `font-display text-3xl md:text-5xl font-bold tracking-tight text-primary` (compact: `md:text-4xl`) |
| Card/page H2 | `font-display text-2xl (md:text-3xl) font-bold text-primary` |
| Card H3 | `font-display text-lg font-semibold text-primary` |
| Member/admin page title | `font-display text-3xl font-bold` + `text-muted-foreground mt-1` subtitle |
| Eyebrow / kicker | `text-xs uppercase tracking-wider text-muted-foreground` (labels) · `text-sm font-semibold uppercase tracking-wider text-secondary` (job sections) |
| Lead paragraph | `text-lg text-muted-foreground leading-relaxed` (section) · `text-primary-foreground/85` (hero) |
| Body | `text-sm text-muted-foreground leading-relaxed` (cards) · `text-foreground/85` (rich content) |
| Rich content | Classes `prose prose-slate prose-headings:font-display prose-headings:text-primary prose-a:text-secondary` — **note:** `@tailwindcss/typography` is in neither the reference `package.json` nor `styles.css`, so these classes were likely inert there (TBC); it is not installed in the Laravel project either. Rich content needs its own small `.rich-text` stylesheet or the plugin (`08` D-01) |
| Numbers/IDs | `font-mono text-xs` for application IDs |
| Type scale beyond the above | **TBC** |

## 4. Components — visual specification

### Buttons (`cva`)
Base: `inline-flex items-center justify-center gap-2 rounded-md text-sm font-medium`, focus `ring-1 ring-ring`, disabled `opacity-50`, icons 16 px.

| Variant | Style |
|---|---|
| `default` | `bg-primary text-primary-foreground shadow hover:bg-primary/90` |
| **CTA (gradient)** | `default` + `gradient-primary shadow-glow hover:opacity-95` — the site's primary call-to-action look |
| `secondary` | `bg-secondary text-secondary-foreground` |
| `outline` | `border border-input bg-background hover:bg-accent` |
| `ghost` | `hover:bg-accent` |
| `link` | `text-primary underline-offset-4 hover:underline` |
| `destructive` | `bg-destructive text-destructive-foreground` |
| On dark band | `bg-secondary text-secondary-foreground` (primary) · `border-white/30 bg-white/10 text-primary-foreground hover:bg-white/20` (secondary) |
| Benefits page | `bg-yellow-400 text-primary hover:bg-yellow-300` (accent-gold) |

Sizes: `default h-9 px-4` · `sm h-8 px-3 text-xs` · `lg h-10 px-8` · `icon h-9 w-9`. Filter chips reuse `default`/`outline` (blog categories, jobs location) and `secondary`/`ghost` (jobs type).

### Links
Inline: `text-secondary hover:underline` (`text-primary hover:underline` in member/admin). Nav: `text-foreground/75 hover:text-primary`, active `text-primary font-semibold`. Footer: `text-primary-foreground/75 hover:text-secondary`. "Read more →": `text-sm font-semibold text-secondary` with arrow icon.

### Badges
Shape: `rounded-md border px-2.5 py-0.5 text-xs font-semibold`. Variants `default` (primary), `secondary`, `destructive`, `outline`. Project patterns: **section eyebrow** `outline` + `border-secondary text-secondary`; **hero glass** `bg-white/10 border-white/20`; category badge `outline` secondary; status badges use variants (see `06`). Small badge `text-[10px] py-0` on job cards.

### Cards
`rounded-xl border bg-card shadow`; header `p-6`, content `p-6 pt-0` (marketing cards override to `p-6/p-7/p-8 shadow-card border-border/60`). Dashed empty card: `border-dashed` (`border-2` on home). Dark benefit card: `rounded-2xl` background `#004d82` with `text-primary-foreground`.

### Forms
Label `text-sm` (Radix Label); field stack `space-y-1.5`; hint `text-xs text-muted-foreground`; error `text-xs text-destructive`; required marker `text-destructive *`. **Input** `h-9 w-full rounded-md border border-input bg-transparent px-3 py-1 shadow-sm text-base md:text-sm`, focus `ring-1 ring-ring`. Textarea same styling (`rows` 3–8). Select: shadcn/Radix (trigger styled like input, popover list). Switch (Radix) with "Visible/Hidden" caption. Radio group (Yes/No) on benefits self-check. Phone: custom phone-input style (reference class `.acsl-phone-input` — rename: the legacy ACSL name is not used in the new UI) matching input (flex, `h-2.25rem`, `radius-md`, focus ring) with default country **LK**. File dropzone: `rounded-lg border border-dashed p-4` + Upload button + removable file rows (`border bg-muted/40`). Password: input with eye toggle (`absolute right`). Two-column field grid `sm:grid-cols-2 gap-4`.

### Tables
shadcn `Table` inside `border rounded-md`; wrapper `overflow-auto`; header `h-10 px-2 text-left font-medium text-muted-foreground`; row `border-b hover:bg-muted/50`; cell padding **TBC** (shadcn default `p-2`). Empty: centred muted text in a bordered box. Primary + secondary line cell pattern (`font-medium` name over `text-xs text-muted-foreground` email/slug).

### Alerts
`alert` component exists (default, destructive) but is **not used** in pages; feedback is toasts. Inline destructive text for validation. Target adds success/warning/info variants (`06`).

### Modals
Radix Dialog: overlay `bg-black/80`; content centred `max-w-lg` (`max-w-2xl/3xl` for forms/details), `p-6 gap-4 border bg-background shadow-lg sm:rounded-lg`, `max-h-[90vh] overflow-y-auto`; close "×" top-right; footer `flex-col-reverse sm:flex-row sm:justify-end`. Open/close: `tw-animate-css` fade + zoom-95. Confirmations use `window.confirm` (not a component).

### Navigation
Header `h-16` sticky, container `px-4 lg:px-8`. Dropdown panel `rounded-lg border bg-background shadow-elegant p-1.5 min-w-56`, items `px-3 py-2 text-sm rounded-md hover:bg-muted hover:text-primary`. Sidebar link `flex items-center gap-2 px-3 py-2 rounded-md text-sm font-medium`; active `bg-primary text-primary-foreground`; idle `text-foreground/75 hover:bg-muted`; icon 16 px. Mobile menu: full-width panel under header, accordion sub-menu with left border.

### Toasts
Sonner, `position="top-right"`, `richColors`.

## 5. Spacing, radius, shadows

| Aspect | Value |
|---|---|
| Container | `container mx-auto px-4 lg:px-8` (Tailwind 4 `container`; add `mx-auto` explicitly) |
| Section rhythm | marketing `py-20 md:py-28`; dark hero band `py-20 md:py-24` (about `py-24 md:py-32`); page body `py-16 md:py-24` or `py-10 md:py-14`; member/admin shell `py-8` |
| Grid gaps | `gap-5` (cards), `gap-4` (stats), `gap-6/8` (two-column layouts), `gap-3` (filters) |
| Reading widths | `max-w-3xl` (articles, FAQ), `max-w-5xl` (rules, apply), `max-w-md` (auth, reset), `max-w-xl` (password page) |
| Radius | `--radius: 0.75rem`. `sm = radius−4px (0.5rem)`, `md = radius−2px (0.625rem)`, `lg = radius (0.75rem)`, `xl = +4px (1rem)`, `2xl = +8px (1.25rem)`. Inputs/buttons/badges `rounded-md`; cards `rounded-xl`; icon tiles `rounded-xl` (`rounded-lg` small); avatars/social `rounded-full`. |
| Footer margin | `mt-20` |

## 6. Icons and imagery

* **Icons:** lucide (outline, 1.5–2 px stroke). Sizes: `h-3/3.5` inline meta, `h-4` buttons/nav, `h-5` tiles/small, `h-6/7` feature tiles. Replacement in Laravel: an inline-SVG Blade icon set of the ~45 lucide icons used (e.g. `blade-lucide`-style or copied SVGs) — package choice not made (no installs in this task).
* **Icon tile:** `grid h-12 w-12 place-items-center rounded-xl gradient-primary text-primary-foreground` (+`group-hover:shadow-glow`); compact `h-9 w-9 rounded-lg` and `h-11 w-11` on contact cards; plane glyph rotated `-rotate-45` for brand accents.
* **Photography/aspect:** blog/news cards `aspect-[16/10]` (news list `16/9`) `object-cover`, lazy; blog detail hero `aspect-[16/8] rounded-xl shadow-elegant` overlapping the header band (`-mt-4`); news/event detail image `w-full rounded-lg`; home hero image `w-full h-auto object-contain` (illustration of pilots/engineers/cabin crew/ATC, 1600×1008). Placeholder when no image: gradient tile with faded plane icon. Admin featured-image standard: **1200 × 630 px, JPEG, 1.91:1**.
* **Avatars:** circle with initials fallback.
* **Logo:** `h-10 w-auto` (292×95 source); **file not in the reference** — the ACI-approved logo assets supplied by ACI are authoritative and the logo must not be recreated or redesigned (`08` C-02). Footer/auth currently use a plane-in-rounded-square + text lock-up instead of the logo image. **Favicon:** `public/favicon.png` exists in the reference.

## 7. Breakpoints and motion

Tailwind defaults (`sm 640`, `md 768`, `lg 1024`, `xl 1280`, `2xl 1536`). Reference switches: header nav at **`lg`**; grids at `sm`/`md`/`lg`; a `use-mobile` hook fixes mobile at **< 768**. No custom breakpoints. Motion: `transition-colors` on links/buttons, `transition-all` cards, chevron rotation `transition-transform`; dialogs fade/zoom via `tw-animate-css`. No page transitions.

## 8. Design-system elements identified (112)

| Group | Count |
|---|---|
| Light colour tokens (background/foreground, card, popover, primary+glow, secondary, muted, accent, destructive, success, warning, border, input, ring — with their `-foreground` pairs) | 24 |
| Sidebar tokens (defined, unused) | 8 |
| Off-token colours to resolve | 6 |
| Gradients / shadows / radius steps | 3 / 3 / 5 |
| Font families / type roles in §3 | 2 / 10 |
| Button variants (6 + 3 contextual) / sizes | 9 / 4 |
| Badge variants (4 + 3 patterns) | 7 |
| Form control types (text, textarea, select, switch, radio, phone, file, password) | 8 |
| Modal sizes / icon sizes / image treatments / breakpoints | 3 / 5 / 4 / 5 |
| Other component styles (link, card, table, alert, nav, toast) | 6 |
| **Total** | **112** |

## 9. TBC list

Cell padding of tables; type scale beyond section 3; contrast audit of `secondary` (#4EA8DE) as text on white (likely < 4.5:1 for small text — **TBC/verify**); focus-ring visibility on dark bands; dark theme; exact hex for non-commented tokens; motion durations.
