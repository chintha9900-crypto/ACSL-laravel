# Authentication & Authorization

Stack: TanStack Start (SSR + server functions) + TanStack Router + Supabase Auth (GoTrue) + Postgres RLS. All file:line citations relative to `aviation-community-hub-main/`.

## 1. Core plumbing

| Piece | File | Role |
|---|---|---|
| Browser client | `src/integrations/supabase/client.ts:6-29` | anon key; session is a UX convenience, never trusted for authorization by itself |
| Server/admin client | `src/integrations/supabase/client.server.ts:8-42` | **service role**, bypasses RLS; dynamic-import-only convention (not compiler-enforced) to avoid client-bundle leakage |
| `requireSupabaseAuth` middleware | `src/integrations/supabase/auth-middleware.ts:9-80` | **The real server-side auth boundary.** Validates `Authorization: Bearer <token>` via `supabase.auth.getClaims(token)`, injects `{supabase, userId, claims}`. Applied consistently to every data-mutating admin/member function found (`admin.functions.ts`, `dashboard.functions.ts`, `account.functions.ts`) |
| Client bearer-token attachers | `src/integrations/supabase/auth-attacher.ts` (dead), `src/lib/supabase-auth-attacher.ts` (live, wired in `src/start.ts:4`) | Attach the session token to outgoing RPC calls; only forward a token the browser already holds, no security function themselves |
| `previewAuthStorage.ts` | `src/integrations/supabase/previewAuthStorage.ts:5-88` | Lovable-preview-iframe session broker — **entirely inapplicable, do not port** |

SECURITY NOTE: `client.server.ts`'s service-role key is a full data-breach primitive if it ever reached a client bundle or an unauthenticated path — the file-suffix convention is a lint safeguard only, not compiler-enforced. Laravel has no equivalent secret to manage (queries always run server-side with the app's own DB credentials).

## 2. Sign-up / sign-in / sign-out / password reset / forced password change

| Flow | Source | Behaviour |
|---|---|---|
| Sign-in | `src/routes/auth.tsx:65-121` | `signInWithPassword`, then a **browser-side RPC call to `has_role`** decides `/admin` vs `/dashboard` redirect — UX only, real access is re-checked server-side |
| Sign-up | `auth.tsx:123-192` | `signUp({data: {...}})` → becomes `raw_user_meta_data` → copied to `profiles` by `handle_new_user()` trigger. UI treats the user as logged in immediately, regardless of whether Supabase's project-level "confirm email" setting is enabled (UNKNOWN — not visible in source; a real contradiction risk if enabled) |
| Forgot password | `auth.tsx:194-228` | `resetPasswordForEmail` — same generic success message regardless of whether the email exists (good, avoids enumeration in the UI) |
| Reset password | `src/routes/reset-password.tsx:22-64` | `auth.updateUser({password})`, relying on Supabase's magic-link-established recovery session; no token/expiry check in **app** code (opaque to Supabase's SDK) |
| Sign-out | `dashboard.tsx:34-37` | `auth.signOut()` + full reload |
| Forced password change | `_authenticated/route.tsx:6-14`, `dashboard.password.tsx:21-34` | `user_metadata.must_change_password` set `true` on admin-issued temp-password approval; **enforced only by a client-side route `beforeLoad` redirect — not server-side.** Any protected server function called directly still succeeds even if the flag is set. **This is a real gap to close in Laravel** via a real `must_change_password` column + server-side middleware on the whole authenticated route group, not a client redirect. **CONFIRMED ACI requirement (WORKFLOWS.md §0.13) explicitly forbids reproducing the plaintext-temp-password provisioning pattern this flag exists to patch over** — the confirmed membership-activation workflow specifies a secure one-time account-setup token/link (securely generated, time-limited, single-use, invalidated after use), which would replace this whole mechanism rather than just closing its enforcement gap. Exact implementation is deferred to LARAVEL ARCHITECTURE |
| Email verification | not found as an app-level flow | `signUp` sets `emailRedirectTo` but no route handles the confirmation callback; admin-created accounts are `email_confirm:true` (pre-verified). Whether "Confirm email" is enabled at the Supabase project level is **UNKNOWN** — a deliberate decision point for Laravel (`MustVerifyEmail` + `verified` middleware), not something to infer |

Password minimum length is enforced only client-side (`minLength=6` sign-up, `minLength=8` change-password); no app-level rate limiting, CAPTCHA, or lockout exists anywhere — see Security Weaknesses below.

## 3. Role model

- Two flat roles only: Postgres enum `app_role` = `admin` | `member`. No hierarchy, no per-resource permissions.
- Roles live in a **separate table** (`user_roles`), never a column on `profiles`, never a JWT claim — a deliberately correct separation-of-concerns pattern (confirmed by `.lovable/plan.md`'s own stated intent) worth preserving in Laravel (a `roles`/`role_user` table or Spatie Permission, not a denormalized display-only field).
- `has_role(_user_id, _role)` is `SECURITY DEFINER`, used by nearly every RLS policy and by both `src/lib/admin.functions.ts` (server) and `src/routes/auth.tsx` (**browser**, directly).
- New sign-ups always get `member` via the `handle_new_user()` trigger. The only ways to become admin: (a) two hardcoded one-off data-migration grants (throwaway bootstrap, do not replicate — see LEGACY_RISKS.md), or (b) an existing admin using `adminSetUserRole`.
- **`has_role`'s EXECUTE grant was revoked then re-granted to `anon, authenticated`** across migrations (see DATABASE.md legacy signal #1) — net effect: **anyone, including anonymous visitors, can currently call `has_role(<any-uuid>, 'admin')`** and learn whether an arbitrary user is an admin. Low-severity information disclosure (doesn't grant access), but must not be ported — never expose a "does user X have role Y" endpoint to unauthenticated or arbitrary-target callers in Laravel.

## 4. Authorization gates per app area

| Area | Enforcement | Notes |
|---|---|---|
| **Public** (marketing, blog, jobs, membership info, contact, shop) | No `beforeLoad` guard; data scoped by handler-written filters, sometimes via `supabaseAdmin` (RLS bypassed) | Several public reads use the service-role client and rely entirely on hand-filtered `.eq()`/`.select()` — no DB backstop if a filter is ever widened by mistake |
| **Member dashboard** (`/dashboard/**`) | `requireSupabaseAuth` on every handler + `.eq("user_id", context.userId)` + RLS ownership policies (belt-and-braces, genuinely server-enforced) | Requires only "is logged in" (any role); gap: `must_change_password` not enforced here (§2) |
| **Admin panel** (`/admin/**`) | `assertAdmin(context)` (`admin.functions.ts:4-13`) on every single admin server function — calls `has_role` via the **caller's own RLS-scoped client**, then returns `supabaseAdmin` for the actual privileged operation | The strongest gate in the app. Client-side `AdminLayout` check (`admin.tsx:39-58`) is UX-only. All-or-nothing admin — no finer-grained permission (e.g. "blog editor") exists |
| **Public membership verification** (`/verify/$token`, QR endpoint) | `verify_membership`/`next_membership_number` are `SECURITY DEFINER`, EXECUTE restricted to `service_role` only — public endpoints can only reach them through the app's privileged backend, never a direct client RPC | Token: `crypto.randomUUID()` stripped of dashes, 32 hex chars (122 bits entropy), unguessable; validity re-derived live from `status`+`issued_on`+`expires_on` on every call, no batch expiry job needed |

## 5. Public membership verification — detail

`/verify/$token` (`src/routes/verify.$token.tsx:6-32`, `src/lib/verify.functions.ts:6-25`) validates token shape with Zod (`min(8).max(80)` — looser than the actual 32-char format, a cosmetic inconsistency not a security hole since the DB lookup is the real gate), then calls `verify_membership` via `supabaseAdmin`. `/api/public/membership-qr/$token.png` (`src/routes/api/public/membership-qr.$token.ts:5-29`) validates against `^[A-Za-z0-9_-]{16,80}$` (also looser than needed) and renders a QR PNG encoding **only the verification URL, never raw personal data or a database id** — a deliberate, good privacy design. Full name + membership number + expiry are exposed to anyone holding the token, by design (third parties verifying a physical/digital card) — reconfirm this is an approved ACI requirement, since it does expose member PII to the internet with no additional auth.

## 6. Security weaknesses (cross-cutting, sourced)

1. **Publicly-callable `has_role` RPC** — see §3. Do not port.
2. **`must_change_password` is a client-route-only gate** — see §2. Enforce server-side in Laravel.
3. **Two near-duplicate bearer-token-attacher files**, only one live — dead code, don't port both (see LEGACY_RISKS.md).
4. **Hardcoded seed-admin grants** in two migrations (`supabase/migrations/20260623175414...sql:9`, `20260623181245...sql`) — throwaway bootstrap steps, not an ACI business rule. Laravel should seed its first admin via a guarded seeder/artisan command, never a permanent migration.
5. **Public read paths trust hand-filtered service-role queries, not RLS** — see SUPABASE.md.
6. **No app-level rate limiting anywhere** — not on login, sign-up, password-reset, membership verification, or the QR endpoint. Recommend Laravel's `throttle` middleware on all of these as a hardening **recommendation** (not present in reference, so not a silent port, but a reasonable addition to flag for approval).
7. **Temp passwords never persisted** (good) but **no expiry on the "must change password" state**, and re-running approval on an already-approved application is not guarded against re-issuing a fresh temp password (UNKNOWN whether intentional). Moot in the target design since the CONFIRMED secure one-time setup-token mechanism (WORKFLOWS.md §0.13) replaces temp passwords with a signed link entirely — but the same "does re-approving cause a problem" question should be re-asked of whatever mechanism is chosen.
8. **Membership application document uploads accept arbitrary filenames/base64 payloads up to ~7MB ×6, fully unauthenticated**, with no content-type/magic-byte validation beyond trusting the client-supplied `type` field — validate real MIME/extension server-side in Laravel (`mimes:` rule). **CONFIRMED requirement extends this upload step to all three membership categories** (previously only 2 of 3 legacy categories required a file), so this validation must be applied uniformly across all of them.
9. **Membership-approval account-takeover risk** (surfaced independently by the admin-panel review): if the applicant's email already has a Supabase Auth account, `adminReviewMembershipApplication`'s accept path silently resets that account's password based only on an email-string match, with no ownership/identity verification. Flag for explicit redesign — do not reproduce. The CONFIRMED activation workflow's secure one-time setup-token mechanism (WORKFLOWS.md §0.13) is an opportunity to design this away entirely (e.g. a setup link tied to the applicant's verified email rather than a password reset on an arbitrary matching account).
10. **No safeguard against an admin revoking their own admin role, or revoking the last remaining admin** (`admin.users.tsx` / `adminSetUserRole`) — could lock everyone out of `/admin/*`, recoverable only via direct DB access. Add an explicit guard in Laravel (count remaining admins before allowing revoke).

## 7. UNKNOWN / needs product or platform confirmation

- Whether the Supabase project has "Confirm email before sign-in" enabled (not in source, a dashboard setting) — determines whether the immediate-login UX in `auth.tsx` is even consistent with the platform's own behaviour today.
- Exact TTL of Supabase's password-recovery magic link, and any GoTrue-level rate limiting on auth endpoints — platform defaults, not app config.
- Whether re-approving an already-approved membership application is guarded against issuing a second temp password.
- Actual consumers of `src/hooks/use-auth.ts` (`useAuth()`) — no import site found in the files reviewed.
- Whether any infra-level (Cloudflare/hosting) rate limiting sits in front of the public verify/QR endpoints.
- Whether `profiles.is_active = false` (suspended) actually blocks authentication at login — not observed in the admin files reviewed; cross-reference against the full auth flow before relying on it.

*Per the Approval Gate: analysis only, no Laravel authorization code has been written.*
