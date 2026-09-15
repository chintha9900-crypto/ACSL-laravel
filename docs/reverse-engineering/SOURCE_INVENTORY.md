# Source Inventory

Phase: REVERSE ENGINEERING ONLY (per `docs/reverse eng/04_claude_prompts/01_REVERSE_ENGINEER_ONLY.md`). No Laravel code, migrations, models, or routes were created as part of this pass.

## Reference source location (correction to CLAUDE.md)

`CLAUDE.md` states the reference application lives at `C:\Projects\aci-reference`. **That path does not exist on this machine.** The actual reference source was located at:

```
C:\Users\hiran\Downloads\aviation-community-hub-main(4).zip   (built 2026-09-12 21:41 — the newest of 6 similarly-named zips/folders in Downloads)
```

This zip's file/route/migration counts match the pre-existing recon notes already committed at `docs/reverse eng/01_source_analysis/SOURCE_INVENTORY.md` (196 files, 19 migrations, 61 route-related files), confirming it is the correct, current reference build. It was extracted read-only to a scratch working copy for this analysis; the original zip and the user's other project files were not modified. **Recommendation:** move/copy this zip (or a fresh export from wherever it is actually maintained — GitHub/Lovable) into `C:\Projects\aci-reference` (or update CLAUDE.md to point at its real location) so future sessions don't have to re-discover it.

## Reference application identity

- Name (package.json): `tanstack_start_ts`
- Platform: Lovable-generated, TanStack Start (React 19 SSR framework) + TanStack Router (file-based routing) + TanStack Query
- Backend: Supabase (Postgres + Auth (GoTrue) + Storage), accessed via `@supabase/supabase-js`
- Deploy target (current): Vercel serverless functions (Nitro `preset: "vercel"`)
- Target rebuild: Laravel (PHP 8.2) + MySQL + Blade + Livewire + Alpine.js + Tailwind CSS, Laravel-native auth, provider-independent payments, SiteGround-compatible deployment

## Top-level structure

```
aviation-community-hub-main/
├── .env, .lovable/ (plan.md, project.json)      — Lovable platform metadata, not app logic
├── package.json, bun.lock, vite.config.ts, vercel.json, tsconfig.json
├── public/                                       — static assets (favicon, robots.txt)
├── src/
│   ├── routes/                                   — 61 file-based route files (see ROUTES.md)
│   ├── lib/                                      — *.functions.ts (server functions / business logic) + *.server.ts
│   ├── integrations/supabase/                    — Supabase client setup, auth middleware/attachers
│   ├── components/{admin,site,ui}/               — admin widgets, site chrome, shadcn/ui primitives
│   ├── hooks/                                    — use-auth.ts, use-mobile.tsx
│   ├── assets/                                   — images
│   ├── server.ts, start.ts, router.tsx, styles.css
└── supabase/
    ├── config.toml                               — only `project_id`, no local buckets/db config
    └── migrations/                                — 19 SQL migrations (2026-06-13 → 2026-09-12), see DATABASE.md
```

File counts (confirmed by extraction): 196 files total, 217 files inside the zip archive listing (includes directory entries), 19 migrations, ~17,900 lines of TypeScript/TSX.

## Dependencies (package.json)

Sole backend/data dependency: `@supabase/supabase-js` (Auth, Postgres via PostgREST/RPC, Storage). Everything else is frontend UI or mail. Full per-package mapping to a Laravel/Blade/Livewire/Alpine equivalent:

| Package | Purpose | Laravel-side note |
|---|---|---|
| `@tanstack/react-start`, `@tanstack/react-router`, `@tanstack/router-plugin` | SSR framework, file routing, `createServerFn` RPC | No direct port — every `createServerFn` is a business rule to re-derive as a controller/Livewire action, not to translate mechanically |
| `@tanstack/react-query` | Client caching/staleness | Cache-worthy business rules (e.g. site data cached 5 min, `__root.tsx:23`) need an explicit `Cache::remember` decision |
| `@tanstack/zod-adapter`, `zod` | Runtime input validation | Laravel Form Requests — see VALIDATION.md |
| `@supabase/supabase-js` | DB/Auth/Storage/RLS | Eloquent + Laravel auth + Policies + Filesystem — no 1:1 mapping |
| `nodemailer` | SMTP transport | Laravel Mail SMTP driver — direct conceptual equivalent |
| `@lovable.dev/email-js` | Lovable-hosted email fallback | **Drop. No Laravel equivalent, not portable.** |
| `@tiptap/*` | Rich text editor | Needs a Livewire-compatible JS editor decision (TipTap client-side, or Trix/Quill) |
| `react-hook-form`, `@hookform/resolvers` | Client form state + zod binding | Replaced by Livewire forms + Form Requests |
| `recharts` | Admin charts | Needs a JS charting lib under Alpine/Blade (Chart.js, ApexCharts) |
| `qrcode` | QR generation (membership card) | PHP equivalent needed (e.g. `simplesoftwareio/simple-qrcode`) |
| `libphonenumber-js`, `react-phone-number-input` | Phone validation/input | Needs a Laravel validation rule (e.g. `propaganistas/laravel-phone`) + JS widget |
| `embla-carousel-react` | Carousels | JS carousel lib under Alpine (Swiper, Splide) |
| `date-fns` | Date formatting | Laravel Carbon server-side; minor JS lib client-side |
| `sonner` | Toasts | JS toast lib or Livewire flash-message pattern |
| `vaul`, `cmdk`, `input-otp`, `react-easy-crop`, `react-resizable-panels`, `react-day-picker` | Misc widgets (drawers, command palette, OTP, image cropper, date picker) | Each a discrete UI decision; `react-easy-crop` signals an image-crop-upload UX to preserve (avatars, blog/news/shop images) |
| `class-variance-authority`, `clsx`, `tailwind-merge`, `tw-animate-css` | Tailwind class composition for shadcn/ui | Tailwind itself is shared; shadcn/ui's React architecture does not port |
| `@radix-ui/react-*` (~25 packages) | Headless accessible UI primitives | No direct equivalent; Alpine.js + Tailwind (or Flowbite/Alpine UI) must reproduce accessibility behavior deliberately |
| `vite-tsconfig-paths`, `nitro`, `@lovable.dev/vite-tanstack-config` | Build tooling, Vercel-preset server bundling | Not applicable — Laravel uses `laravel-vite-plugin`; confirms current serverless deploy assumption (see LEGACY_RISKS.md) |

No test script exists in `package.json` and no test files were found anywhere in the reference source — **the reference app has no automated test suite**. There is nothing to cross-check Laravel test coverage against; all TEST REQUIREMENT entries in this document set are newly derived from observed behaviour, not ported from existing tests.

## Route inventory

61 route-related files under `src/routes/`. Full path → purpose → auth → data mapping is in **ROUTES.md**.

## Supabase-related files (35+)

Enumerated and analyzed in **SUPABASE.md** and **AUTHORIZATION.md**: `src/integrations/supabase/*`, `src/lib/*.functions.ts`, `src/lib/*.server.ts`, and all 19 files under `supabase/migrations/`.

## Documents produced by this reverse-engineering pass

| Document | Covers |
|---|---|
| `SOURCE_INVENTORY.md` (this file) | Structure, dependencies, source-location note |
| `ROUTES.md` | Every route: path, purpose, auth, data |
| `FEATURES.md` | Per-feature SOURCE→BEHAVIOUR→DATA→SUPABASE→BUSINESS/SECURITY RULE→LARAVEL REPLACEMENT→MYSQL→TEST |
| `WORKFLOWS.md` | Multi-step business processes (membership application/review, e-shop checkout, comment moderation) |
| `DATABASE.md` | Full current schema (tables, enums, functions/triggers, storage policies), MySQL migration considerations |
| `SUPABASE.md` | Auth/RLS/Storage/RPC mechanics as a Supabase-dependency inventory, env vars, service-role usage sites |
| `AUTHORIZATION.md` | Roles, gates per app area, security weaknesses |
| `STORAGE.md` | Buckets, upload mechanisms, per-feature file handling |
| `VALIDATION.md` | Every form's field-level validation rules, client vs server |
| `NOTIFICATIONS.md` | Every email/in-app notification, trigger, recipient, delivery mechanism |
| `LEGACY_RISKS.md` | Duplicated/dead code, conflicting data models, security gaps, placeholder content — flagged per the Legacy/Conflict/Uncertainty Rules, not silently reproduced |

Per the project's Approval Gate: this is analysis only. Wait for explicit approval before moving to LARAVEL ARCHITECTURE.
