# 11 — Background Jobs & Scheduling Architecture

## 1. Queue driver — hosting-dependent, ASSUMPTION pending confirmation

**ASSUMPTION** (see `16_OPEN_DECISIONS.md`): the **database** queue driver is the default recommendation, because SiteGround's shared/GrowBig-tier hosting typically does not provide Redis, and the database driver requires no additional infrastructure beyond the MySQL database this system already needs. If ACI's actual SiteGround plan (or a future move to SiteGround Cloud/Laravel Cloud) provides Redis, the `redis` queue driver is a drop-in config change with no code impact, since all queued work is written against Laravel's driver-agnostic Queue facade throughout this document set.

- **Why appropriate for ACI**: the database driver is reliable, requires zero extra services, and easily handles ACI's expected volume (membership applications, order placements, and scheduled reminders are not high-frequency workloads for a club-scale system) — matches "avoid premature optimization" and "avoid enterprise complexity ACI does not need."
- **Laravel mechanism**: `config('queue.default') = 'database'`, a `jobs` table (Laravel's standard migration), and a queue worker.
- **Worker execution on SiteGround**: shared hosting generally cannot run a long-lived background process (`php artisan queue:work` as a daemon) the way a VPS with Supervisor can. **ARCHITECTURAL DECISION**: use SiteGround's Cron Jobs panel to run `php artisan queue:work --stop-when-empty --max-time=50` (or similar) once per minute, processing whatever is queued and exiting — a well-established pattern for shared-hosting Laravel deployments, functionally equivalent to a persistent worker for ACI's expected volume. If ACI upgrades to a hosting tier with Supervisor/persistent-process support, the equivalent, more standard `queue:work` daemon (auto-restarted by Supervisor) is a pure ops change, no application-code change.
- **Alternatives considered**: `sync` driver (process jobs immediately, in-request) — rejected as the default because it defeats the entire purpose of queuing (email sends, webhook processing, and reminder jobs must not block the HTTP response, `06_NOTIFICATION_ARCHITECTURE.md` §1); acceptable only as a local-development fallback.

## 2. Laravel Scheduler

**ARCHITECTURAL DECISION**: a single SiteGround cron entry (`* * * * * php artisan schedule:run`) drives every scheduled task via `routes/console.php`'s `Schedule::` definitions — the standard, recommended Laravel pattern, requiring only one cron entry regardless of how many scheduled tasks exist (versus one cron line per task, which is harder to manage and a worse fit for SiteGround's cron panel UI).

## 3. Scheduled jobs (by domain)

| Job | Schedule | Domain | Purpose |
|---|---|---|---|
| `SendMembershipRenewalReminders` | daily | Membership | Queries **current membership terms** whose `expires_on` matches a **configurable** reminder offset (recommended 30/7/0 days) and that have no confirmed or pending renewal; dispatches M10 (`WORKFLOWS.md` §0.12 as amended by OD-10). Never charges or renews anything. |
| `ExpireMembershipTerms` | daily | Membership | Flips `MembershipTerm.status` to `expired` for any term past `expires_on`; dispatches M11 when the member has no confirmed renewal. The membership record and number are unaffected. |
| `ExpireAccountSetupTokens` | hourly (or via a `deleted_at`/`expires_at` check on read) | Identity & Access | Housekeeping for unused setup tokens past their expiry (`04_MEMBERSHIP_ARCHITECTURE.md` §7) |
| `CleanUpOrphanedFiles` | daily/weekly | Files/Documents | Safety-net for any file left behind by a failed/interrupted request (`07_FILE_STORAGE_ARCHITECTURE.md` §4) |
| `ReconcileInventoryBalances` | daily (optional, only if a cached stock-balance column is used) | Commerce | Recomputes cached `ProductVariant` stock from the `InventoryTransaction` ledger as a consistency check (`09_ECOMMERCE_ARCHITECTURE.md` §2) |
| `PruneExpiredPasswordResetTokens` / queue's own `PruneStaleFailedJobs` etc. | as recommended by Laravel defaults | framework housekeeping | Standard Laravel maintenance commands |

## 4. Queued jobs (dispatched from Actions/Events, not scheduled)

| Job | Dispatched by | Idempotency note |
|---|---|---|
| Every Notification (`ShouldQueue`) | Any domain Action/Event Listener | Laravel's queue retries on failure; Notification sends are not required to be idempotent themselves (a rare duplicate email on a retry is an acceptable trade-off vs. blocking the request), but the **business state changes** they might be paired with (e.g. marking a membership activated) are never performed inside the notification job itself — state changes happen in the triggering Action's transaction, notifications are a side effect dispatched after commit |
| `ProcessPaymentWebhookEvent` | Webhook Controller, only for a newly-seen `(gateway, event_id)` | Must be idempotent — re-running it (e.g. after a queue retry) must not double-apply a payment confirmation; implemented by checking `Payment.status` is still in a state the transition is valid from before applying it (`08_PAYMENT_ARCHITECTURE.md` §4) |
| `GenerateMembershipCardPdf` (if caching is ever added, `07_FILE_STORAGE_ARCHITECTURE.md` §5) | On-demand controller request, or not queued at all if generated synchronously (PDF generation is fast enough to be synchronous — likely no queue needed here) | N/A if synchronous |

## 5. Failure handling

- Laravel's `failed_jobs` table + `queue:retry`/`queue:failed` Artisan commands are the standard operational tool for investigating failed queued work (notification sends, webhook processing).
- A **critical** failure class — a webhook that repeatedly fails to process, or a renewal-reminder batch that errors — should be visible to ACI's admin, not just buried in `failed_jobs`; exact alerting mechanism (a Slack/email notification to ACI's own admin on repeated job failure) is `TBC` (`16_OPEN_DECISIONS.md`), not built by default.

*Per the Phase 2 restriction: conceptual design only. No `jobs`/scheduling code exists yet.*
