# 16 — Open Decisions

Every item here is genuinely undefined — commercially, operationally, or as a judgment call this architecture made conservatively rather than by inventing a business rule. None of these block the architecture design itself (each has a documented default/assumption in its domain document so the design is internally consistent), but each should be confirmed by ACI/the project owner before or during DATABASE DESIGN / IMPLEMENTATION. Tagged by type: **[COMMERCIAL]** (a business/pricing decision only ACI can make), **[OPERATIONAL]** (depends on ACI's actual hosting/ops choices), **[JUDGMENT CALL]** (this architecture made a conservative default; flagging for confirmation, not silently deciding), **[FUTURE SCOPE]** (not needed now, noted so it isn't forgotten).

## Membership

1. **[COMMERCIAL]** Final commercial names, descriptions, pricing, and standard duration for the three membership categories (Student/`S`, Professional/`P`, Veteran/`V`). — `04_MEMBERSHIP_ARCHITECTURE.md` §1, `WORKFLOWS.md` §0.1.
2. **[COMMERCIAL]** Exact acceptable aviation-proof document types/formats per category (the requirement that proof is mandatory and admin-reviewed is settled; the accepted formats are not). — `04_MEMBERSHIP_ARCHITECTURE.md` §2, `WORKFLOWS.md` §0.5.
3. **[OBSOLETE — resolved by OD-10]** ~~Priority/order values once more than one promotion exists.~~ The mandatory first-6-month free membership is a standard new-member rule, **not** a promotion, so promotion priority/eligibility resolution no longer applies. The Promotions domain is deferred (future marketing promotions only). — `04_MEMBERSHIP_ARCHITECTURE.md` §5, `docs/database/17_DATABASE_OPEN_DECISIONS.md`.
4. **[OPERATIONAL]** Exact account-setup-token expiry window (proposed range 24–72 hours; not fixed). — `04_MEMBERSHIP_ARCHITECTURE.md` §7.
5. **[JUDGMENT CALL]** How to handle an activation whose applicant email already matches an existing `User` account. This architecture proposes requiring the existing account's owner to confirm/link via the same secure setup-token flow rather than silently resetting credentials (closing the legacy's account-takeover risk) — but this specific mechanism is an architectural judgment call, not a restated ACI requirement, and should be confirmed. — `04_MEMBERSHIP_ARCHITECTURE.md` §8.
6. **[PARTLY RESOLVED — OD-10 / OD-04]** Renewal: **confirmed** — the first 6 months are free; before expiry the member is notified (configurable timing), pays the renewal fee (price from the database, **the amount itself is not fixed by any document**), and is renewed for another (normally) 12 months; no auto-charge, no auto-renewal; the membership number never changes. **Still open (schema-neutral, `docs/database/17` OD-21):** the renewal start-date rule, renewal after a long lapse, and category change at renewal; **also open:** the non-payment grace period before a membership is deactivated (`docs/database/17` OD-22) and whether activation follows approval automatically or by an explicit admin action (OD-23). — `04_MEMBERSHIP_ARCHITECTURE.md` §6/§9.
7. **[FUTURE SCOPE]** Public QR-based membership verification — architecture leaves room for it (a `verification_token` column, unused) but it is not being built now. — `04_MEMBERSHIP_ARCHITECTURE.md` §10, `WORKFLOWS.md` §0.15.
8. **[COMMERCIAL]** Exact digital membership card visual design (colours, layout beyond the confirmed required fields) — deferred to UI/architecture design per ACI's own instruction. — `04_MEMBERSHIP_ARCHITECTURE.md` §10.
9. **[COMMERCIAL]** Exact bank/payment-details field set (the recommended minimum list is given; final field set can be adjusted). — `08_PAYMENT_ARCHITECTURE.md` §5.
10. **[JUDGMENT CALL]** Whether the M1 "application submitted" notification should still also copy the admin mailbox (legacy pattern) or notify the applicant only. — `06_NOTIFICATION_ARCHITECTURE.md` §2.

## Payments

11. **[COMMERCIAL]** Which real payment gateway (if any) ACI wants to add in future, and when. No gateway is chosen or integrated in this phase. — `08_PAYMENT_ARCHITECTURE.md` §2, `13_INTEGRATION_ARCHITECTURE.md` §3.
12. **[COMMERCIAL]** System currency. The reference application priced membership/shop items in LKR; the Phase 2 instructions' own worked examples used "£" (GBP). **These may not be the same currency and this has not been reconciled** — do not assume either without confirmation. — `08_PAYMENT_ARCHITECTURE.md` §1.

## Commerce (future)

13. **[FUTURE SCOPE]** Whether/when Commerce is actually built — no launch scope or date confirmed; this document set designs for it being built correctly *if* pursued, not as a near-term commitment. — `09_ECOMMERCE_ARCHITECTURE.md` (scope note).
14. **[COMMERCIAL]** Whether guest checkout should be enabled (the legacy schema hinted at it but never actually enabled it) — architecture supports either without assuming one. — `09_ECOMMERCE_ARCHITECTURE.md` §6.
15. **[COMMERCIAL]** Whether tax calculation is needed at all for ACI's merchandise. — `09_ECOMMERCE_ARCHITECTURE.md` §6.
16. **[JUDGMENT CALL]** Order confirmation page access rule for a guest checkout (kept as unauthenticated-by-ID for UX parity, vs. restricted to owner/admin + a short-lived signed URL — a genuine security-posture choice, not silently picked). — `05_AUTHORIZATION_ARCHITECTURE.md` §2 (`OrderPolicy`).

## Content & Community

17. **[JUDGMENT CALL]** Blog comment moderation default: `pending` (moderate-by-default, matching what the legacy schema implies) vs. `approved` (matching what the legacy app actually did in practice). Not confirmed either way — architecture supports both via one config value. — `10_CONTENT_ARCHITECTURE.md` §8.
18. **[JUDGMENT CALL]** Whether News and Blog should be unified into one "articles" concept — kept separate by default (matches legacy structure), not merged without confirmation. — `10_CONTENT_ARCHITECTURE.md` §3.
19. **[JUDGMENT CALL]** Whether event content should render as HTML (like blog/news) or plain text (as the legacy app happened to do, with no evidence it was deliberate). — `10_CONTENT_ARCHITECTURE.md` §4.
20. **[FUTURE SCOPE]** A real tracked referral program (unique codes, attribution, rewards) — current scope is a plain invite email only; building tracking is new scope requiring its own confirmation. — `17_JOBS_AND_COMMUNITY_ARCHITECTURE.md` §3.
21. **[COMMERCIAL]** Whether to add customer/enquirer-facing confirmation emails the legacy app never sent (contact form acknowledgement to the enquirer; e-shop order confirmation to the customer). Not added by default, not assumed unwanted either. — `06_NOTIFICATION_ARCHITECTURE.md` §3.
22. **[FUTURE SCOPE]** A real DB-backed, admin-editable email template system (`email_templates` existed in the legacy schema but was never actually used). Not built — every email is a Blade view. — `06_NOTIFICATION_ARCHITECTURE.md` §5.
23. **[FUTURE SCOPE]** Structured data (JSON-LD) for SEO (Article/JobPosting/Event schema) — a gap/opportunity `FEATURES.md` noted in the legacy app; not built unless ACI wants it.

## Security & Access

24. **[OPERATIONAL]** Whether ACI ever needs finer-grained admin permissions beyond the current two flat roles (e.g. "content editor" vs. "membership approver"). Simple role column is sufficient today; `Spatie\Permission` is the noted upgrade path if this changes. — `05_AUTHORIZATION_ARCHITECTURE.md` §1.
25. **[JUDGMENT CALL]** Whether any open self-registration surface will exist at all (Membership accounts are provisioned only through the confirmed activation flow, not open sign-up) — if one is later added, email verification (`MustVerifyEmail`) needs a decision. — `05_AUTHORIZATION_ARCHITECTURE.md` §1.
26. **[OPERATIONAL]** Exact rate-limit thresholds (login, application submission, contact form, etc.) — mechanism is confirmed (`throttle` middleware everywhere it's needed), specific numbers are an implementation-time tuning choice. — `05_AUTHORIZATION_ARCHITECTURE.md` §5.
27. **[FUTURE SCOPE]** Client-side JS error monitoring (e.g. Sentry) to replace the dropped Lovable-platform telemetry — not assumed wanted, not built by default. — `13_INTEGRATION_ARCHITECTURE.md` §10, `14_ERROR_VALIDATION_ARCHITECTURE.md` §1.

## Audit & Data Retention

28. **[OPERATIONAL]** `spatie/laravel-activitylog` package vs. a small custom `AuditLog` implementation — both achieve the same design; a package-vs-build choice for implementation time, not a design question. — `12_AUDIT_LOGGING_ARCHITECTURE.md` §1.
29. **[COMMERCIAL/OPERATIONAL]** Audit log and status-history retention/deletion policy — none confirmed; no data is deleted by default in this design. — `12_AUDIT_LOGGING_ARCHITECTURE.md` §4.
30. **[OPERATIONAL]** Alerting mechanism for repeated critical job failures (webhook processing, renewal reminders) — not built by default; `failed_jobs`/logs are the baseline, an active alert (email/Slack to ACI admin) is a possible enhancement. — `11_BACKGROUND_JOBS_ARCHITECTURE.md` §5.

## Hosting & Infrastructure

31. **[OPERATIONAL — database engine RESOLVED]** ACI's actual SiteGround hosting tier/plan, which determines: whether Redis is available (affects queue-driver choice, default assumed = database queue driver), whether a persistent queue worker (Supervisor) or cron-triggered `queue:work --stop-when-empty` is used, and general resource limits. **Confirmed since this list was written:** production is PHP 8.2.33, Apache, **MySQL 8.4.6** (utf8mb4) — the database-engine part of this item is closed (`docs/database/17` OD-08). The Redis/queue-worker questions remain open. — `11_BACKGROUND_JOBS_ARCHITECTURE.md` §1.
32. **[OPERATIONAL]** Whether/when the package-based modular-monolith alternative (`nwidart/laravel-modules` or similar) should be revisited — not needed now, noted only as a future threshold if the team or codebase grows substantially. — `01_ARCHITECTURE_OVERVIEW.md` §3.
33. **[OPERATIONAL]** Whether any JSON/API surface is ever needed (mobile app, third-party integration) — none is confirmed as needed; this design is web-only (Blade/Livewire). — `14_ERROR_VALIDATION_ARCHITECTURE.md` §4.

## Jobs & Community — entities with no basis in the reverse-engineering record

These need a business definition from ACI before any real design work, not an inferred one — see `17_JOBS_AND_COMMUNITY_ARCHITECTURE.md` for why each is flagged this way.

34. **[COMMERCIAL — definition needed]** What a "partner" is for ACI (a sponsoring organization? a member-discount partner directory? a flying-school/training partner list?) — no such concept exists anywhere in the legacy app or reverse-engineering record. — `17_JOBS_AND_COMMUNITY_ARCHITECTURE.md` §5.
35. **[COMMERCIAL — definition needed]** What an "advisory member" is — a `TeamMember` display variant (this document's working assumption) vs. a fourth membership category (which would contradict the confirmed "exactly three categories" rule and needs explicit reconciliation if actually intended). — `17_JOBS_AND_COMMUNITY_ARCHITECTURE.md` §6.
36. **[COMMERCIAL — definition needed]** What a "referral contact" is meant to be — a history of sent invitations vs. an editable address-book of contacts to invite — neither is confirmed. — `17_JOBS_AND_COMMUNITY_ARCHITECTURE.md` §4.
37. **[COMMERCIAL]** Exact rules for job application documents (how many, required vs. optional, accepted formats) — the *capability* is requested, the rules governing it are not specified. — `17_JOBS_AND_COMMUNITY_ARCHITECTURE.md` §2.
38. **[COMMERCIAL]** Whether "event registration" should exist at all, and if so its rules (capacity, waitlisting, payment-for-events, cancellation) — no such feature exists in the legacy app; only a minimal placeholder entity shape is reserved. — `10_CONTENT_ARCHITECTURE.md` §7.
39. **[JUDGMENT CALL]** Whether the new `Page` CMS entity should actually replace the legacy's static Blade-equivalent pages (About/Privacy/Terms/Rules), or whether those should stay static/developer-maintained (arguably more appropriate for legal text) — proposed as available infrastructure, not a decision to convert existing static content. — `10_CONTENT_ARCHITECTURE.md` §6.

## Frontend & Visual Design

40. **[OPERATIONAL]** Exact design-token extraction from the reference application (specific colour/font/spacing values to carry into `tailwind.config.js`) and whether ACI wants any visual refresh alongside the technology migration, or an exact visual match — inspection-driven implementation-time work, not fixed in this architecture. — `03_LARAVEL_ARCHITECTURE.md` §7.

## How to use this list

Each item should be either (a) confirmed by ACI with a concrete answer, which then becomes a CONFIRMED REQUIREMENT recorded back into the relevant domain document before that part of DATABASE DESIGN/IMPLEMENTATION proceeds, or (b) explicitly deferred with ACI's agreement that the documented default/assumption stands for the initial build. **No item on this list should be silently resolved by an implementer without one of those two outcomes.**

*Per the Phase 2 restriction: this is a documentation artifact only. Resolving any item here does not itself constitute implementation — it informs the DATABASE DESIGN and IMPLEMENTATION phases that follow human review and approval.*
