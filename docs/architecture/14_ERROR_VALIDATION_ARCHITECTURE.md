# 14 — Error Handling & Validation Architecture

## 1. Exception handling

**ARCHITECTURAL DECISION**: Laravel 12's `bootstrap/app.php` `->withExceptions()` centralized exception configuration, with custom Blade error views (`resources/views/errors/{404,403,419,429,500}.blade.php`).

- **Preserves the one good legacy principle**: never leak internals to the browser (`LEGACY_RISKS.md`/reverse-engineering `error handling` notes: "every unexpected server error is deliberately hidden behind one generic message... no stack traces, error messages, or exception details are exposed to the browser in any surface"). `APP_DEBUG=false` in production + generic error views achieves the same outcome natively, with none of the legacy's bespoke workarounds needed:
  - The legacy's `error-capture.ts` global-listener workaround existed specifically because the underlying Nitro/h3 server framework sometimes swallowed the real error into a generic response before the app's own try/catch could see it — this is a quirk of that specific serverless framework and **has no equivalent problem in Laravel's request lifecycle**, so no equivalent workaround is needed.
  - The legacy's `lovable-error-reporting.ts` (client-side JS error boundary reporting to a Lovable-platform telemetry global) is dropped entirely — not portable, and no replacement is assumed; if ACI wants client-side JS error monitoring (e.g. Sentry), that is a separate, explicitly-approved future integration (`13_INTEGRATION_ARCHITECTURE.md` §10), not built by default.
- **Domain exceptions**: business-rule violations that aren't simple field validation (e.g. attempting to approve an application whose proof hasn't been reviewed, `04_MEMBERSHIP_ARCHITECTURE.md` §2; attempting to activate a membership twice) are modelled as small custom exception classes (`MembershipApplicationCannotBeApprovedException`, etc.) caught by the exception handler and rendered as a friendly flash message / 422 response — kept distinct from Laravel's own `ValidationException` (field-level) so the two failure classes (bad input vs. invalid business state) are never conflated in the UI or in logs.

## 2. Validation strategy

**ARCHITECTURAL DECISION**: every user input boundary is validated by a **Form Request** class (`app/Http/Requests/{Domain}/...`), never inline `$request->validate()` calls scattered through controllers, and never trusted from the client alone — directly correcting the pervasive legacy pattern flagged throughout `VALIDATION.md` (hand-rolled, inconsistent, sometimes entirely-absent server-side validation; client/server rule mismatches; several admin CRUD entities with **zero** server-side validation at all).

Concrete fixes carried from `VALIDATION.md`'s findings, applied uniformly rather than ad hoc:

| Legacy gap | Fix |
|---|---|
| CrudManager entities (FAQs, testimonials, team, hero banners) had **no server-side validation at all** | A real Form Request per entity, with `required`/length/type rules |
| Blog/news/job/event admin forms only validated required fields on **create**, not update | Form Request rules apply identically to create and update (a single Request class, or two Requests sharing a base rule set) |
| `status`-like fields (comments, enquiries, job applications, memberships) almost never allow-list-checked server-side | Every status transition validated against its backed PHP enum (`03_LARAVEL_ARCHITECTURE.md` §4) — an invalid value is a validation error, not silently accepted |
| Email/phone validation inconsistent across forms (different regexes, or client-only real validation) | One shared validation approach: Laravel's `email` rule + `propaganistas/laravel-phone` (`13_INTEGRATION_ARCHITECTURE.md` §9) used everywhere a form collects these |
| Membership application's `form_data` sub-fields validated client-side only | Each category's Form Request validates its own type-specific fields explicitly (course info for Student, plan for Professional, experience for Veteran) rather than accepting an unvalidated free-form blob |
| Checkout validation hand-rolled, not schema-based | A Form Request per checkout step, server-side price/stock/discount/shipping revalidation regardless of what's submitted (`09_ECOMMERCE_ARCHITECTURE.md` §4) |
| No file content-type/magic-byte validation anywhere | Laravel's content-inspecting `mimes:`/`image` rules on every upload (`07_FILE_STORAGE_ARCHITECTURE.md` §3) |

## 3. Validation vs. authorization — kept distinct

A Form Request's `authorize()` method handles **can this user attempt this action at all** (delegating to a Policy); its `rules()` method handles **is the submitted data well-formed**. These are answered in that order (authorization first) so an unauthorized user never learns anything about validation rules for an action they can't perform anyway — a minor but real information-disclosure discipline.

## 4. Error response formats

- Web (Blade/Livewire) surfaces: standard Laravel validation-error bag rendering (inline field errors, matching the legacy's toast-based UX where a Livewire component re-renders with errors — no full-page reload needed for most forms per `03_LARAVEL_ARCHITECTURE.md` §3).
- Any JSON/API surface (only if one is later confirmed as needed, `16_OPEN_DECISIONS.md`) would use Laravel's standard JSON validation-error shape (422 with a structured error bag) — not designed further here since no API surface is confirmed.

## 5. Logging channels

`config/logging.php` — a `stack` channel writing to a daily-rotated file by default; a separate channel (or a minimum-level filter) for anything ACI wants surfaced more urgently (e.g. repeated webhook processing failures, `11_BACKGROUND_JOBS_ARCHITECTURE.md` §5) — exact alerting integration (email-to-admin, Slack) is `TBC`, not built by default.

*Per the Phase 2 restriction: conceptual design only. No exception handler configuration, Form Requests, or error views exist yet.*
