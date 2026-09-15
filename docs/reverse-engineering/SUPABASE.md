# Supabase Dependency Inventory

This documents *how* the reference app depends on Supabase as a platform (clients, RLS-as-a-mechanism, Storage, RPC, env vars) — i.e. what must be replaced and how, feature by feature. For the schema itself see DATABASE.md; for the auth/role model see AUTHORIZATION.md; for bucket-by-bucket file handling see STORAGE.md.

## Client instances

| Client | File | Key | Behaviour |
|---|---|---|---|
| Browser client | `src/integrations/supabase/client.ts:6-29` | anon/publishable | Lazy Proxy, custom storage adapter (`previewAuthStorage.ts` — Lovable-preview-only, drop entirely), `persistSession:true`, `autoRefreshToken:true`. Used by every page component for `signInWithPassword`, `signUp`, `signOut`, `getUser`, `getSession`, `onAuthStateChange`, `updateUser`, `resetPasswordForEmail`, `rpc(...)` |
| Server/admin client (`supabaseAdmin`) | `src/integrations/supabase/client.server.ts:8-42` | **service role** — bypasses RLS entirely | Imported only via dynamic `await import(...)` inside `.server.ts`-suffixed files, by convention (not compiler-enforced) never at module scope, to avoid leaking the key into the client bundle. Used for essentially all privileged admin writes and several "public but convenient" reads |
| `publicClient()` | `src/lib/news-events.functions.ts:5-11` | anon/publishable, built ad hoc | Used **only** by `news_items`/`events` public reads — inconsistent with `blog.functions.ts`/`jobs.functions.ts` which use the privileged `supabaseAdmin` for equivalent public reads. This client's queries genuinely go through RLS; the `supabaseAdmin`-based ones do not |

**Key architectural risk to flag for Laravel**: several "public" reads run through `supabaseAdmin` (RLS bypassed) and depend entirely on hand-written `.eq()`/`.select()` filters in the handler to avoid over-exposing data — there is no database-level backstop. Laravel structurally removes this class of bug (no service-role-equivalent exists), but only if every controller/Resource explicitly whitelists fields and scopes queries — this must be done deliberately, not assumed to be automatic.

## Row Level Security (RLS) as a mechanism

RLS is the reference app's only real per-row authorization layer for anything read/written directly via the anon/authenticated Postgrest key. Full per-table policy text is in DATABASE.md. As a *pattern* to port:

- **Ownership policies** (`auth.uid() = user_id`) → Laravel Policy methods (`view`, `update`, `delete`) or scoped Eloquent queries off `Auth::id()`.
- **Admin-blanket policies** (`has_role(auth.uid(),'admin')`) → a Gate/`before()` hook checking the user's role.
- **Visibility policies** (`status='published' OR has_role(admin)`) → **must be applied at every query site via an explicit scope** (e.g. a `PublishedScope` global scope) since Eloquent has no automatic per-query row filtering the way RLS does. This is the single most important structural gap: forgetting the scope in one new controller silently leaks draft/admin-only rows, whereas Postgres RLS would have caught it at the database layer regardless of application code.
- **SECURITY DEFINER functions used to avoid recursive RLS** (`has_role`) → a plain PHP method (`$user->hasRole('admin')`), which has no equivalent "recursion" trap.

RLS-related gaps found (see LEGACY_RISKS.md / AUTHORIZATION.md for full detail): `has_role` is currently callable by anonymous users (information disclosure), several UPDATE policies have no `WITH CHECK` (latent field-tampering gap), and `shop_orders` guest-checkout appears schema-intended but RLS/GRANT-blocked in practice.

## Authentication (Supabase Auth / GoTrue)

Full detail in AUTHORIZATION.md. Supabase-specific mechanics that have **no Laravel equivalent and must not be ported**:
- `auth.users` / GoTrue-issued JWTs — replaced entirely by Laravel's session-based auth and its own `users` table.
- `requireSupabaseAuth` middleware validating a `Bearer` JWT via `getClaims()` — replaced by Laravel's `auth` middleware + session cookie (no bearer-token relay needed since Blade/Livewire don't do client→server RPC the way TanStack Start does).
- Two client-side "attach bearer token to every RPC call" middlewares (`src/integrations/supabase/auth-attacher.ts`, dead; `src/lib/supabase-auth-attacher.ts`, live) — this entire concern disappears with cookie-based sessions.
- `user_metadata.must_change_password` (a JWT-embedded flag, not a table column) → must become a real `users.must_change_password` boolean column, enforced by server-side middleware, not a client-route `beforeLoad` (the reference app's enforcement is UX-only — a real gap, see AUTHORIZATION.md §2).
- `supabaseAdmin.auth.admin.createUser()` / `.updateUserById()` / `.listUsers()` (Auth Admin API, used by the membership-approval flow to provision accounts) → Laravel's own `User::create()` / password-reset flow; the `listUsers({perPage:1000})` email-matching fallback pattern does not scale and should not be reproduced (see AUTHORIZATION.md and LEGACY_RISKS.md).

## Storage

Supabase Storage is used for 8 buckets (see STORAGE.md for full detail). Mechanically: browser-direct uploads authorized by Storage RLS policies (not by a `createServerFn`, unlike almost everything else in the app) for `ImageCropUpload`-driven uploads (blog/news/shop images, avatars), vs. server-side (`supabaseAdmin`) uploads for membership-application documents. Object paths (not public URLs) are stored on the owning row; display uses time-limited signed URLs (`createSignedUrl`, TTL varies 1 hour–7 days across features — inconsistent, see LEGACY_RISKS.md). None of this maps 1:1 to Laravel Filesystem — disks, visibility, and signed/temporary URLs must be redesigned per STORAGE.md.

## RPC functions called from application code

| RPC | Called from | Client used |
|---|---|---|
| `has_role` | `src/lib/admin.functions.ts` (×2), `src/routes/auth.tsx` (post-login redirect decision) | both browser (`auth.tsx`) and server (admin.functions.ts) — the browser call is itself a security concern (public info disclosure), see AUTHORIZATION.md |
| `next_membership_number` | `src/lib/admin.functions.ts:628` | `supabaseAdmin` (service-role) only — superseded by the now-**authoritative** 9-character membership number format and generation rules in WORKFLOWS.md §0.11 (generated only at activation, per-category sequenced, transactional), to be implemented as a plain Laravel service, not a Postgres RPC |
| `verify_membership` | `src/lib/verify.functions.ts:12` | `supabaseAdmin` (service-role) only — the *concept* (public membership verification) is carried forward as a future-facing requirement in WORKFLOWS.md §0.15, exact mechanism TBD at ARCHITECTURE |

## Environment variables (names only, no values reproduced)

| Variable | Purpose |
|---|---|
| `SUPABASE_PROJECT_ID`, `SUPABASE_URL`, `SUPABASE_PUBLISHABLE_KEY` | Server-side Supabase project identity/anon key |
| `SUPABASE_SERVICE_ROLE_KEY` | **Highly sensitive** — bypasses RLS; used to construct `supabaseAdmin` |
| `VITE_SUPABASE_PROJECT_ID`, `VITE_SUPABASE_URL`, `VITE_SUPABASE_PUBLISHABLE_KEY` | Client-exposed (browser-bundled) duplicates of the above three, per the project's `VITE_`-prefix-is-public convention |
| `SMTP_HOST`, `SMTP_PORT`, `SMTP_USER`, `SMTP_PASSWORD` | SMTP mail transport (referenced in `email.server.ts`, not present in the committed `.env`, so presumably deployment-only) |
| `SENDER_DOMAIN`, `LOVABLE_API_KEY` | Lovable-platform email fallback — **drop entirely, not portable** |

None of the Supabase env vars survive the rebuild — MySQL uses Laravel's own `DB_*` config, and Laravel does not expose any database credential to the browser (unlike the anon-key-in-browser Supabase model), which removes an entire class of "did we leak the wrong key" risk by construction.

## Net effect for the Laravel rebuild

Every Supabase mechanic above is a **business rule or access-control rule to re-derive**, not a library to swap 1:1. There is no drop-in "Supabase for Laravel" package that preserves this architecture — Eloquent + Laravel Auth + Policies/Gates + Laravel Filesystem replace client/server-client-split, RLS, GoTrue, and Storage respectively, each requiring its own deliberate design pass during LARAVEL ARCHITECTURE.
