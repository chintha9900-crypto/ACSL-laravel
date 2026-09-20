# 13 — Integration Architecture

External integrations only. Confirms explicitly what is **excluded** (Supabase, Vercel, Lovable) and proposes Laravel-side choices for the capabilities `docs/reverse-engineering/SOURCE_INVENTORY.md`'s dependency table identified as needing a fresh decision.

## 1. Explicitly excluded (CONFIRMED REQUIREMENT)

No Supabase (Postgres-as-a-service, Auth/GoTrue, Storage, RLS), no Vercel/Nitro serverless packaging, no `@lovable.dev/*` packages or `window.__lovableEvents` telemetry, and no hybrid arrangement combining any of these with Laravel. Every mechanic these provided in the reference app is replaced by a native Laravel/MySQL equivalent documented elsewhere in this package (`SUPABASE.md` already concluded none of them map 1:1 — this is the Laravel-side confirmation that the replacement is complete, not partial).

## 2. Email (SMTP)

Laravel Mail, SMTP driver, against a SiteGround mailbox — see `06_NOTIFICATION_ARCHITECTURE.md` §5. This is the one legacy integration that was already shaped correctly for this target and needs no architectural change, only a native Laravel configuration.

## 3. Future payment gateways

The `PaymentGatewayContract` abstraction (`08_PAYMENT_ARCHITECTURE.md` §2) is the integration point for any future real gateway (Stripe, PayHere, a Sri Lanka-specific processor, etc.). **No specific gateway is chosen or integrated in this phase** — ACI has not confirmed one, and the current confirmed workflow is manual bank transfer. When a gateway is chosen, it is added as a new class implementing the existing interface plus its own webhook Controller (`08_PAYMENT_ARCHITECTURE.md` §4), without changing Membership or Commerce code. Recorded as `TBC` in `16_OPEN_DECISIONS.md`.

## 4. PDF generation (digital membership card)

**ARCHITECTURAL DECISION**: `barryvdh/laravel-dompdf` (a well-established, actively-maintained Laravel wrapper around dompdf) for rendering the Blade card view to a downloadable PDF (`04_MEMBERSHIP_ARCHITECTURE.md` §10).

- **Why**: pure-PHP (no external binary/service dependency, important for SiteGround shared hosting where installing system packages may not be possible), directly renders Blade/HTML+CSS, well-suited to a simple one-page card layout.
- **Alternatives considered**: `spatie/browsershot` (renders via headless Chrome) — rejected: requires a Node/Chromium runtime on the server, unlikely to be available or maintainable on typical SiteGround shared hosting; a client-side print-to-PDF (`window.print()`, as the legacy app used for its admin reports, `FEATURES.md` §D) — rejected for the membership card specifically because "downloadable" is a confirmed requirement, not just "printable from the currently-open page."

## 5. QR code generation (future membership verification)

**ARCHITECTURAL DECISION (forward-looking, not built now)**: `endroid/qr-code` (a maintained, dependency-light PHP QR library; `simplesoftwareio/simple-qrcode` is a viable alternative wrapping the same underlying concept) is the proposed choice **when** the future QR verification feature (`04_MEMBERSHIP_ARCHITECTURE.md` §10, `WORKFLOWS.md` §0.15) is actually built. Not installed or integrated in this phase.

## 6. Rich text editing (admin content authoring)

**ARCHITECTURAL DECISION**: Laravel's own bundled **Trix** editor (ships with Laravel, integrates cleanly with a Livewire component via a small Alpine wrapper) as the default choice for `BlogPost.content`/`JobPosting.*` fields, rather than reproducing the legacy's TipTap dependency.

- **Why**: zero additional JS dependency to manage (Trix ships with Laravel's own front-end scaffolding conventions), sufficient formatting capability for club blog/job content (headings, lists, bold/italic, links) which is all the legacy editor actually exposed (`FEATURES.md` §D: "bold/italic/strike, paragraph/H1-H3, bullet/ordered list, blockquote").
- **Every rich-text field is sanitized server-side regardless of editor choice** — `10_CONTENT_ARCHITECTURE.md` §5. Sanitizer package: `mews/purifier` (an HTMLPurifier wrapper for Laravel) — ARCHITECTURAL DECISION, a widely-used, allow-list-based, actively maintained choice.
- **Alternatives considered**: keeping TipTap (via Alpine, no React) — viable but adds a JS dependency Trix avoids for equivalent capability; rejected as unnecessary given the content's actual formatting needs.

## 7. Image cropping (avatar/content image upload)

**ARCHITECTURAL DECISION**: a lightweight JS cropper (e.g. `cropperjs`, framework-agnostic, usable from an Alpine component) reproducing the legacy's crop-before-upload UX (`react-easy-crop`), **but** — per `07_FILE_STORAGE_ARCHITECTURE.md` §3 — the server always re-validates/re-processes the final image (via PHP's GD/Imagick, through Laravel's `Intervention/Image` package if server-side resizing/re-encoding is wanted) rather than trusting the browser-cropped blob's dimensions/type, closing the legacy's flagged "no server-side validation of the uploaded object at all" gap (`STORAGE.md` §0.3 equivalent).

## 8. Charts (admin reports)

**ARCHITECTURAL DECISION**: Chart.js (via a thin Alpine/Livewire wrapper) reproducing the legacy's `recharts`-based admin monthly-report visualizations (`FEATURES.md` §D "Monthly Reports") — a mature, dependency-light, no-build-step-required charting library appropriate for a server-rendered Laravel admin panel.

## 9. Phone number validation/formatting

**ARCHITECTURAL DECISION**: `propaganistas/laravel-phone` (a maintained Laravel validation-rule package wrapping `giggsey/libphonenumber-for-php`, the PHP port of the same `libphonenumber` library the legacy app used client-side via `react-phone-number-input`) for server-side phone validation on the membership application and contact forms — closing the legacy's flagged client/server validation mismatch (`VALIDATION.md`: client used real per-country validity checking, server used a much weaker shape-only regex).

## 10. Integrations identified but not implemented (documented as boundaries only, per the Phase 2 instructions)

The Phase 2 instructions ask this document to identify external integrations that "may eventually be required," explicitly without implementing any of them now. Beyond the payment gateway (§3) and email (§2), already covered:

- **SMS provider**: no SMS capability exists anywhere in the legacy app or the confirmed requirements (`NOTIFICATIONS.md` has no SMS entries). If ACI ever wants SMS (e.g. a renewal-reminder text alongside the email), the integration boundary is Laravel's Notification system's `toSms`-equivalent channel via a package such as `laravel-notification-channels/*` for a chosen provider (Twilio, Vonage, or a Sri Lanka-specific SMS gateway) — added as a new channel on existing Notification classes (`06_NOTIFICATION_ARCHITECTURE.md`), not a structural change. No provider is chosen; this is a boundary only.
- **Analytics**: the legacy app had no analytics integration in any file reviewed during reverse-engineering. If ACI wants site analytics (Google Analytics, Plausible, Fathom, etc.), the integration boundary is a single tracking-script include in the shared layout (`03_LARAVEL_ARCHITECTURE.md` §7) — a front-end-only concern with no backend architecture impact. Not added by default (privacy-by-default; adding third-party tracking is a decision for ACI to make explicitly).
- **Social platforms**: the legacy app only ever *linked out* to social platforms (`SocialLink` entity, `10_CONTENT_ARCHITECTURE.md`) — it never integrated with a social platform's API (no social login, no auto-posting, no embedded feeds). This design carries forward exactly that same "links only" scope. A future social-login option (e.g. "Sign in with Google/Facebook") would use Laravel Socialite as its integration boundary — not built, not assumed wanted.
- **Cloud/S3-compatible storage**: noted in `07_FILE_STORAGE_ARCHITECTURE.md` §1 as a future option if SiteGround local disk ever becomes a constraint (e.g. multi-server deployment) — Laravel's Filesystem abstraction makes this a config change (`config/filesystems.php` disk swap), not a code change, when/if needed.

None of the above are chosen, scheduled, or implemented — they are recorded so a future integration has an obvious, low-friction entry point in this architecture rather than needing to be retrofitted.

## 11. Summary table

| Capability | Legacy dependency | Laravel-side choice | Status |
|---|---|---|---|
| Backend/data/auth/storage | Supabase | Eloquent/MySQL, native auth, Laravel Filesystem | Replaced entirely (not an "integration," it's the core stack) |
| Deployment packaging | Vercel/Nitro | Standard Laravel on SiteGround | Replaced entirely |
| Error telemetry | Lovable platform | Laravel `Log` facade (+ optional Sentry/Flare if ACI wants client JS error tracking later) | Dropped; replacement optional, `TBC` |
| Email | `nodemailer` + Lovable fallback | Laravel Mail (SMTP) | Direct equivalent, fallback dropped |
| Payment gateway | none | `PaymentGatewayContract`, manual transfer today | No gateway chosen yet — `TBC` |
| PDF | none (client `window.print()`) | `barryvdh/laravel-dompdf` | New capability |
| QR | `qrcode` (npm) | `endroid/qr-code` (future) | Not built yet |
| Rich text | TipTap | Trix (bundled with Laravel) + `mews/purifier` | Simplified |
| Image crop | `react-easy-crop` | `cropperjs` + server-side re-validation | Direct equivalent + fix |
| Charts | `recharts` | Chart.js | Direct equivalent |
| Phone validation | `libphonenumber-js` (client only) | `propaganistas/laravel-phone` (server + client) | Fixes legacy client/server mismatch |

*Per the Phase 2 restriction: these are proposed package choices for future implementation phases — no packages have been installed, no code written.*
