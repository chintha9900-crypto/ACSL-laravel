# Storage

All file storage in the reference app is Supabase Storage. Bucket **creation** itself is not in any migration — buckets are referenced only as string literals inside `storage.objects` RLS policies (`supabase/migrations/20260613093408...sql` M3, `20260723154837...sql` M10), implying manual creation via the Supabase dashboard with no recorded config for public/private flags or size/mime limits. Treat the entire disk/bucket design as a fresh decision for Laravel, not a port.

## Buckets

| Bucket | Used by | Public read? | Write access | TTL of signed URL when read |
|---|---|---|---|---|
| `avatars` | Member profile photo (`dashboard.profile.tsx`) | Yes (7-bucket public-read policy) | Owner only, folder-scoped `{user_id}/...` | 1 hour |
| `blogs` | **Shared** by blog post featured images (folder `blog`), news images (folder `news`), shop product images (folder `shop`) — one bucket split only by folder prefix, likely a copy-paste artifact rather than intentional | Yes | Admin only (`ImageCropUpload`, browser-direct) | 1 hour (blog, shop) / 1 hour (news) — blog listing separately uses a **7-day** signed URL for the same bucket in one place (`blog.functions.ts`), an unexplained TTL inconsistency to resolve, not replicate as-is |
| `jobs` | Referenced in bucket policies; no upload UI found for it in the files reviewed (jobs have no image field) | Yes | Admin only | — |
| `hero-banners`, `team`, `testimonials`, `logos` | Referenced in bucket policies, but **none of these entities actually use bucket upload** — hero banners, team members, and testimonials all take a plain URL text field (`photo_url`/`image_url`) in the admin form instead of `ImageCropUpload` | Yes | Admin only | — |
| `documents` | Membership application file uploads (ID/proof-of-study/employment letters etc.) | **No** — excluded from the public-read policy | `anon`/`authenticated` DB row insert is public, but the actual file upload always goes through `supabaseAdmin` (server-side, not the browser) | 1 hour (admin viewing applicant documents) |

## Upload mechanisms (two distinct patterns in the reference app)

### 1. Browser-direct upload via `ImageCropUpload` (admin content images + avatars)

SOURCE: `src/components/admin/ImageCropUpload.tsx:1-189` (blog/news/shop), `dashboard.profile.tsx` (avatar, same underlying pattern by hand).

- File picker restricted client-side by an `accept` prop (default `image/jpeg,image/jpg`) and a regex re-check on the browser-reported `file.type` — **client-side only, not content-sniffed**. No max-file-size check anywhere in this component (avatar page UI claims "max 2MB" but this is **not enforced anywhere**, client or server — a real gap).
- Crop UI (`react-easy-crop`) with a fixed aspect ratio per consumer (blog/news 1200×630 ≈1.91:1, shop 4:3), then rasterized to canvas, scaled to `maxWidth` (default 1200px), exported as a Blob (JPEG q0.9 / PNG lossless).
- Uploaded **directly from the browser** to Supabase Storage: `supabase.storage.from(bucket).upload(path, blob, {contentType, upsert:false})`, `path = "${folder}/${crypto.randomUUID()}.${ext}"`.
- **No server-side validation of the uploaded object at all** — authorization is delegated entirely to Storage RLS bucket policies (exact policy text UNCERTAIN, not present in the migrations reviewed), which is a materially different (and weaker/differently-implemented) security boundary than the rest of the admin panel, where every other admin write goes through `assertAdmin` in a server function. Flag as a conflict to close in Laravel: **route all uploads through a server-side controller/action gated by admin middleware**, with real `image`/`mimes:`/`max:`/`dimensions:` validation before storing — never trust a browser-cropped blob's type/size.
- "Replace image"/"X" clear does **not** delete the underlying storage object — orphaned files accumulate indefinitely on every replace, in every consumer (blog, news, shop, avatar). Consider a cleanup job or delete-on-replace in Laravel.
- Value stored on the entity is the **object path**, not a public URL; display always re-resolves via a fresh signed URL (`createSignedUrl`), implying buckets are private even though a "public read" RLS policy also exists on most of them — an inconsistency (if truly public-read, a stable public URL would be simpler and cheaper than a signed URL; if truly private, the "public read" policy on 7 of 8 buckets is redundant). Resolve deliberately in the Laravel disk design (`public` disk with permanent URLs, or a private disk with `Storage::temporaryUrl`/signed routes — not both).

### 2. Server-side upload (membership application documents)

> **CONFIRMED ACI requirement (final, WORKFLOWS.md §0.1): all three membership categories require an aviation-participation proof document, not just two of them as in the legacy app** — the Professional-equivalent category's Laravel form must gain this upload step, with the same non-public/authorized-access storage pattern documented below. Separately, WORKFLOWS.md §0.9 **resolves** the payment-evidence storage question: ACI's preferred workflow collects **both** a payment reference (text) and a payment confirmation evidence document (upload) for the standard (non-promotional) path — this is a new storage need not present in the legacy app at all, requiring the same class of file-type/size validation, secure/non-public storage, authorization, and audit controls as aviation-proof documents (WORKFLOWS.md §0.5). Exact bucket/disk layout for this is deferred to ARCHITECTURE.

SOURCE: `src/lib/membership.functions.ts:167-179` (`submitPreApplication`).

- Applicant selects files in the browser; each is read into a **base64 data URL** client-side (not a native multipart upload) and included directly in the JSON payload sent to the server function — up to 6 files, each up to ~10,000,000 base64 characters (~7MB after decoding).
- Server decodes each base64 payload, sanitizes the filename (`[^\w.\-]+` → `_`), and uploads via `supabaseAdmin.storage.from("documents").upload(...)` at `membership-applications/{membership_type}/{timestamp}-{uuid}-{safeName}`, `upsert:false`.
- Fully **unauthenticated** endpoint (no `requireSupabaseAuth`) accepting attacker-supplied filenames and base64 payloads with **no content-type/magic-byte validation** beyond trusting the client-supplied `type` string — a real abuse/storage-cost risk (large uploads, no rate limiting/CAPTCHA anywhere in this path). Laravel replacement should use genuine multipart `Storage` uploads (not base64-in-JSON, which bloats request size ~33% and defeats normal upload-size middleware) with server-side MIME/extension validation (`mimes:` rule) and a rate limit.
- Admin views these documents later via 1-hour signed URLs (`adminGetMembershipApplication`, `admin.functions.ts`).

## Per-feature summary

| Feature | Bucket/folder | Stored value | Read mechanism |
|---|---|---|---|
| Avatar | `avatars/{user_id}/avatar-{timestamp}.{ext}` | object path in `profiles.avatar_url` | 1-hour signed URL |
| Blog featured image | `blogs/blog/{uuid}.{ext}` | object path in `blog_posts.featured_image` | 1-hour (or inconsistently 7-day) signed URL |
| News image | `blogs/news/{uuid}.{ext}` | object path in `news_items.image_url` | 1-hour signed URL |
| Shop product image | `blogs/shop/{uuid}.{ext}` | object path in `shop_products.image_url` | 1-hour signed URL |
| Hero banner / team / testimonial photo | none — plain URL field | absolute/external URL string | rendered directly, no signing |
| Site logo/favicon | none — plain URL field on `site_settings` | absolute/external URL string | rendered directly |
| Membership application documents | `documents/membership-applications/{type}/{timestamp}-{uuid}-{name}` | JSON array `{name, path, type}` in `membership_applications.documents` | 1-hour signed URL (admin only, `documents` bucket is not public-readable) |

## Laravel replacement notes

- Two Laravel disks are a reasonable structural starting point: a `public` disk (avatars, blog/news/shop/hero/team/testimonial images) and a `private`/`local` disk (membership application documents), matching the reference's public-vs-`documents` split — but confirm with ACI whether public assets should have stable public URLs (simpler, cheaper) rather than porting the reference's "private-bucket-plus-1-hour-signed-URL-even-for-public-content" pattern, which appears to be an inconsistency rather than a deliberate choice.
- All uploads should go through a server-side controller/Livewire action with real validation (`image`, `mimes:jpeg,png`, `max:<KB>`, `dimensions:` where a fixed aspect matters) — the reference's browser-direct-to-storage pattern for admin content images is a security/consistency gap to close, not preserve.
- Do crop client-side (a JS cropper is fine) but always re-validate/re-process the final image server-side.
- Decide on an orphan-file cleanup strategy (delete-on-replace, or a scheduled job) since the reference app never cleans up superseded avatars/images.
- Standardize signed-URL TTLs (or drop signing entirely for genuinely public assets) rather than the reference's inconsistent 1-hour/7-day mix.

## UNCERTAIN

- Exact Supabase Storage bucket RLS policies (who can write to `blogs`/`avatars` etc.) — not present in the migrations reviewed; the app's behaviour was inferred from client code, not confirmed against policy SQL.
- Whether any max file size is enforced at the Supabase project/infra level (outside application code).
- Whether `jobs`/`hero-banners`/`team`/`testimonials`/`logos` buckets hold any objects at all in practice, given no upload UI targets them directly.

*Per the Approval Gate: analysis only, no Laravel storage/filesystem code has been written.*
