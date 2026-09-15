# Supabase Reference Map

The following source files contain Supabase-related references:

- `bun.lock`
- `package.json`
- `.env`
- `package-lock.json`
- `src/start.ts`
- `.lovable/plan.md`
- `src/lib/blog.functions.ts`
- `src/lib/dashboard.functions.ts`
- `src/lib/news-events.functions.ts`
- `src/lib/referral.functions.ts`
- `src/lib/shop.ts`
- `src/lib/shop.functions.ts`
- `src/lib/admin.functions.ts`
- `src/lib/verify.functions.ts`
- `src/lib/jobs.functions.ts`
- `src/lib/site.functions.ts`
- `src/lib/membership.functions.ts`
- `src/lib/account.functions.ts`
- `src/lib/supabase-auth-attacher.ts`
- `src/routes/auth.tsx`
- `src/routes/reset-password.tsx`
- `src/routes/shop.index.tsx`
- `src/routes/shop.$slug.tsx`
- `src/hooks/use-auth.ts`
- `src/integrations/supabase/client.ts`
- `src/integrations/supabase/types.ts`
- `src/integrations/supabase/client.server.ts`
- `src/integrations/supabase/auth-attacher.ts`
- `src/integrations/supabase/auth-middleware.ts`
- `src/components/admin/ImageCropUpload.tsx`
- `src/routes/_authenticated/dashboard.tsx`
- `src/routes/_authenticated/dashboard.password.tsx`
- `src/routes/_authenticated/dashboard.profile.tsx`
- `src/routes/_authenticated/route.tsx`
- `src/lib/api/example.functions.ts`

Claude must classify each reference as:
- authentication
- database read/write
- RPC/function
- storage
- realtime
- authorization/RLS
- server-side secret
- external integration

Target mapping:
- Supabase Auth → Laravel authentication
- Supabase PostgreSQL → MySQL
- Supabase RLS → Policies/Gates/Middleware
- Supabase RPC/Edge functions → Laravel Services/Actions
- Supabase Storage → Laravel Filesystem
- Supabase queries → Eloquent/query builder
