# 15 — Data Integrity Rules

Design only. States which integrity rules the **database** enforces, which the **application** must enforce because MySQL cannot cleanly do so, the transaction/concurrency boundaries, a set of reconciliation queries, and the results of the Phase 3 architecture review.

## 1. Layers

1. **Database** — types, `NOT NULL`, `UNIQUE`, FKs, CHECKs, generated unique keys. Cannot be bypassed by application bugs (given the engine baseline, `01` §2).
2. **Application** — Actions/Policies/Form Requests: transactional multi-step workflows, cross-table and cross-row rules. Each rule below has an ID (`R-nn`) referenced from the schema docs.
3. **Reconciliation** — scheduled/test queries (§5) that must return zero rows; they detect silent loss of DB enforcement (e.g. an engine change that ignores CHECKs) and drift in derived data.

## 2. Rules the database enforces

| Rule | Mechanism |
|---|---|
| Exactly three categories, codes `S/P/V` | `UNIQUE(code)` + `CHECK` |
| One application → at most one membership; a membership always has an application | `memberships.membership_application_id` `NOT NULL UNIQUE` |
| One open application per email | generated `open_email_key` unique |
| An application cannot be approved without proof review | `CHECK (status <> 'approved' OR proof_reviewed_at IS NOT NULL)` |
| Decision timestamp/actor exist exactly when decided | `CHECK` |
| Membership number: 9 chars, `[SPV]\d{8}`, unique, consistent with `number_year`/`number_sequence`; sequence unique per category/year | `CHECK` + `UNIQUE(membership_number)` + `UNIQUE(category, year, sequence)` |
| Number/dates exist only after activation | lifecycle `CHECK` |
| Active/expired membership implies payment settled | `CHECK (status='pending_activation' OR payment_status IN ('payment_not_required','payment_confirmed'))` |
| "Free" ⇔ a promotion was applied; promotion snapshot complete | `CHECK` |
| Only one promotion per membership | single nullable FK |
| Sequence counter never exceeds 9999 | `CHECK` |
| A payment is for exactly one purpose (membership XOR order) | `CHECK ((membership_id IS NULL) <> (order_id IS NULL))` |
| No £0 payment; no non-positive refund | `CHECK (amount > 0)` |
| Payment creation and refund are idempotent; webhook events are unique | `UNIQUE(gateway, idempotency_key)`, `UNIQUE(idempotency_key)`, `UNIQUE(gateway, event_id)` |
| At most one live setup token per (user, purpose); token hashes unique | generated `live_key`; `UNIQUE(token_hash)` |
| A document has exactly one owner matching its kind, is never on the public disk | `CHECK` |
| Stock never negative, reserved never exceeds on-hand | `CHECK` on `product_variants` |
| Reservation/sale/release happen at most once per order line | generated `once_key` unique |
| Order total = subtotal − discount + shipping; line total = price × qty | `CHECK` |
| Singleton settings rows | `CHECK (id = 1)` |
| No orphaned FKs; no accidental cascade into history | FKs, `RESTRICT` policy (`13` §8) |

## 3. Rules the application must enforce

MySQL cannot express these (cross-table, cross-row aggregate, temporal, or workflow-ordered), and this design forbids triggers.

| ID | Rule | Why not the database | Where enforced |
|---|---|---|---|
| **R-01** | The last remaining admin cannot be demoted or suspended; an admin cannot revoke their own role | needs a count over `users` | `UserPolicy` / role Action; blocked attempt audit-logged |
| **R-02** | A setup token never changes the credentials of an already-`active` user; expired/used/unknown tokens give one generic message | workflow semantics | setup Action |
| **R-03** | The first character of `membership_number` equals the membership's category `code`; numbers are produced only by `GenerateMembershipNumber` inside the activation transaction | cross-table (`memberships` ↔ `membership_categories`) | activation Action + reconciliation §5-Q1 |
| **R-04** | Activation is only via the `ActivateMembership` Action, using `UPDATE … WHERE status='pending_activation'` as compare-and-set; free path requires a resolved promotion, paid path requires `payments.status='paid'` for that membership | workflow ordering across tables | Action + CHECKs as backstop |
| **R-05** | ≥ 1 `aviation_proof` document exists before an application may be `submitted`; category-specific application fields present | cross-table / depends on category row | Form Request + submit Action (one transaction: application + documents) |
| **R-06** | Reapplication: no open application (DB) **and** newest `rejected` application by email/mobile/user is older than `reapplication_cooldown_days`; an approved-but-not-active application blocks a new one | temporal + cross-row | eligibility check + Form Request |
| **R-07** | A promotion with no category-pivot rows applies to no category; promotion resolved once, inside the activation transaction, by the documented query; membership snapshot set in the same `UPDATE` | resolution logic | resolver Action |
| **R-08** | Payments are created only by `CreateMembershipPayment` / `CreateOrderPayment`; a membership payment's `amount`/`currency` equal the membership's `fee_amount`/`fee_currency`; an order payment's equal the order's totals | cross-table equality | those Actions + model `saving` guard |
| **R-09** | Σ(`processed` refunds) ≤ `payments.amount`; refund currency equals payment currency | aggregate across rows | refund Action under `SELECT … FOR UPDATE` on the payment row |
| **R-10** | Payment status transitions follow the state machine (`pending→processing→paid|failed`, `failed→processing` **manual gateway only**, `paid→partially_refunded|refunded`, `pending→cancelled`); each is a guarded `UPDATE … WHERE status IN (…)` | transition graph | payment Actions |
| **R-11** | `memberships.payment_status` and `payments.status` change together in one transaction; `payment_rejected` immediately proceeds to `payment_pending` | cross-table | payment Actions |
| **R-12** | `membership_status_history`, `audit_logs`, `inventory_transactions`, `order_status_history`, `email_logs` (except status columns), `referral_invitations` are append-only: no update/delete code path | no triggers | no such Eloquent/admin operations exist; optional restricted DB user |
| **R-13** | A document's `membership_details_request_id` belongs to the same application; `payment_evidence` documents attach only to membership payments; MIME type is server-detected; files are never overwritten (new row per upload); no user-supplied path | cross-table / filesystem | upload Action + Form Request |
| **R-14** | Document downloads are Policy-gated (owner-or-admin; admin-only before an account exists), streamed with the stored MIME type, and audit-logged (`document.viewed`) for aviation proof and payment evidence | authorisation | controller + `DocumentPolicy` |
| **R-15** | A webhook row is inserted only after signature verification (or for a source with no signature); processing claims the row by compare-and-set; every business effect is itself a guarded `UPDATE` | protocol semantics | webhook controller + job |
| **R-16** | An order becomes `paid` only from a `paid` payment for that order with matching amount/currency; `pending_payment` orders hold live reservations; leaving `pending_payment` unpaid emits `release`; order and payment statuses are separate vocabularies | cross-table | checkout/payment Actions |
| **R-17** | Checkout re-derives price, discount, shipping and stock server-side inside one transaction (locks variant rows, then coupon row); nothing client-submitted is trusted | trust boundary | `PlaceOrder` |
| **R-18** | `order_items` are immutable after placement; order snapshots (`customer_*`, addresses, coupon code, shipping name) are never re-derived from live data | history | model rules |
| **R-19** | `product_variants.quantity_*` change only in the same transaction as an `inventory_transactions` insert; recomputed sums equal the projection | derived data | inventory Action + reconciliation §5-Q9 |
| **R-20** | Coupon `usage_limit` and validity are checked under a row lock on the coupon at order placement; usage = count of non-cancelled orders with the coupon | aggregate | `PlaceOrder` |
| **R-21** | Rich text is allow-list sanitised on save; slugs have friendly duplicate errors; `published_at` is stamped on any transition into `published`; comment update whitelists the text field only | not a data-shape rule | Form Requests / Actions |
| **R-22** | Users are anonymised, never deleted; anonymisation keeps the row (and FK integrity) | retention semantics | erasure Action |
| **R-23** | Job-application document limits/requirements (when ACI defines them) are validated in the Form Request; the schema imposes none | undefined by ACI | Form Request |
| **R-24** | Email-template bodies are rendered by allow-listed placeholder substitution, never compiled as Blade/PHP; mandatory templates (e.g. M4) cannot be deactivated | security | render service |
| **R-25** | A `membership_plans` / `payment_bank_accounts` row referenced by any membership/payment is not edited in its commercial/bank fields — create a new row and deactivate the old one | history | admin Action |
| **R-26** | Overlapping membership terms for one user (renewing while an earlier term is still `active`) are not prevented by the database. The rule — refuse, allow inside a renewal window, or supersede the old term — is unresolved (OD-04) and must be added to the eligibility check once decided | undefined by ACI | eligibility check |

## 4. Transaction and concurrency boundaries

Every boundary below is one database transaction. Locks are always acquired in the order **payment/order → membership → sequence → variant → coupon** where more than one applies, to avoid deadlocks.

| Operation | Contents | Concurrency control |
|---|---|---|
| **Submit application** | insert `membership_applications` + `documents` + status history + audit | `open_email_key` unique rejects a concurrent duplicate submission |
| **Admin decision** (approve/reject/more details) | update application, insert history/audit; on approve insert `memberships` (`pending_activation`), then either activate (free) or insert `payments` (paid) | approve is `UPDATE … WHERE status IN ('submitted')` (compare-and-set) — two admins cannot both decide |
| **Activation (free or paid)** | resolve promotion → lock sequence row → increment → CAS-update membership → provision/link user + setup token → history/audit | sequence `SELECT … FOR UPDATE`; CAS on `status='pending_activation'` (see `04` §8.1) |
| **Payment evidence submission** | insert `documents`, update payment (`processing`), update membership `payment_status`, history | payment row updated with a status guard |
| **Payment confirm/reject** | guarded payment update, membership update (then activation on confirm), history/audit | `WHERE status='processing'` compare-and-set |
| **Webhook processing** | claim (CAS) → guarded payment/order update → mark processed | `UNIQUE(gateway,event_id)` + CAS (`06` §5.1) |
| **Refund** | lock payment row → sum refunds → insert refund → update payment status | `SELECT … FOR UPDATE` on `payments` + `UNIQUE(idempotency_key)` |
| **Place order** | lock variant rows → check available → insert order/items/addresses/history → ledger `reservation` rows + projection update → payment row | variant row locks; projection CHECKs as backstop |
| **Order paid** | payment confirmed → order `paid` → ledger `sale` (consuming reservation) → projection update | `once_key` unique |
| **Cancel/expire order** | order `cancelled` → ledger `release` → projection update | `once_key` unique |

**Isolation:** InnoDB `REPEATABLE READ` (default). Locking reads (`FOR UPDATE`) read the latest committed row, which is what the sequence and stock logic need. Deadlock/lock-wait errors (1213/1205) are retried by `DB::transaction(..., attempts: 3)`.

## 5. Reconciliation queries (must return no rows)

Run by a scheduled command and by the test suite against MySQL.

| # | Purpose | Query sketch |
|---|---|---|
| Q1 | Number category letter matches category | `SELECT m.id FROM memberships m JOIN membership_categories c ON c.id = m.membership_category_id WHERE m.membership_number IS NOT NULL AND LEFT(m.membership_number,1) <> c.code` |
| Q2 | Payment purpose XOR | `SELECT id FROM payments WHERE (membership_id IS NULL) = (order_id IS NULL)` |
| Q3 | No non-positive payment | `SELECT id FROM payments WHERE amount <= 0` |
| Q4 | Membership payment amount matches membership | `SELECT p.id FROM payments p JOIN memberships m ON m.id = p.membership_id WHERE p.amount <> m.fee_amount OR p.currency <> m.fee_currency` |
| Q5 | Active/expired membership without settled payment | `SELECT id FROM memberships WHERE status <> 'pending_activation' AND payment_status NOT IN ('payment_not_required','payment_confirmed')` |
| Q6 | Free membership that has a payment row | `SELECT m.id FROM memberships m JOIN payments p ON p.membership_id = m.id WHERE m.payment_status = 'payment_not_required'` |
| Q7 | Sequence counter behind issued numbers | `SELECT s.id FROM membership_number_sequences s JOIN (SELECT membership_category_id c, number_year y, MAX(number_sequence) mx FROM memberships WHERE number_sequence IS NOT NULL GROUP BY 1,2) x ON x.c = s.membership_category_id AND x.y = s.sequence_year WHERE s.last_number < x.mx` |
| Q8 | Documents with zero or several owners | `SELECT id FROM documents WHERE (membership_application_id IS NOT NULL) + (payment_id IS NOT NULL) + (job_application_id IS NOT NULL) <> 1` |
| Q9 | Stock projection ≠ ledger | `SELECT v.id FROM product_variants v LEFT JOIN (SELECT product_variant_id, SUM(on_hand_delta) oh, SUM(reserved_delta) rs FROM inventory_transactions GROUP BY 1) t ON t.product_variant_id = v.id WHERE v.quantity_on_hand <> COALESCE(t.oh,0) OR v.quantity_reserved <> COALESCE(t.rs,0)` |
| Q10 | Paid order without paid payment | `SELECT o.id FROM orders o WHERE o.status NOT IN ('pending_payment','cancelled') AND NOT EXISTS (SELECT 1 FROM payments p WHERE p.order_id = o.id AND p.status IN ('paid','partially_refunded','refunded'))` |
| Q11 | Refunds exceeding payment | `SELECT p.id FROM payments p JOIN payment_refunds r ON r.payment_id = p.id AND r.status = 'processed' GROUP BY p.id, p.amount HAVING SUM(r.amount) > p.amount` |
| Q12 | Stuck webhooks | `SELECT id FROM payment_webhooks WHERE processing_status IN ('received','processing') AND received_at < UTC_TIMESTAMP() - INTERVAL 15 MINUTE` |
| Q13 | Application approved with no membership / membership with unapproved application | `SELECT a.id FROM membership_applications a LEFT JOIN memberships m ON m.membership_application_id = a.id WHERE a.status = 'approved' AND m.id IS NULL` and the converse |
| Q14 | Order totals | `SELECT o.id FROM orders o JOIN (SELECT order_id, SUM(line_total) s FROM order_items GROUP BY 1) i ON i.order_id = o.id WHERE o.subtotal_amount <> i.s` |

## 6. Phase 3 architecture review — results

Each of the 20 checks was applied to the final design.

| # | Check | Finding |
|---|---|---|
| 1 | Orphaned FKs | Every FK target is a designed table (listed in `02`); all parents are created before children in the dependency order: `users` → `membership_categories` → `membership_plans` / `membership_promotions` → `membership_applications` → `membership_details_requests`, `memberships` → `payment_bank_accounts` → `payments` → `documents`/`refunds`/`webhooks`; `orders` chain after `products`/`coupons`/`shipping_methods`. |
| 2 | Circular dependencies | None. The only self-reference is `memberships.renews_membership_id` (nullable, acyclic by lineage). `documents` → `payments` and `documents` → `membership_details_requests` do not loop back. `account_setup_tokens` → `memberships` → `users`, and `memberships.user_id` → `users` — `users` never references either. `inventory_transactions` → `order_items` → `orders`; `orders` never references inventory. |
| 3 | Missing unique constraints | Reviewed table by table (`14` §2). Notably present: number **sequence** uniqueness (independent of the random digits), webhook `(gateway,event_id)`, payment `(gateway,idempotency_key)`, once-only ledger keys, one-open-application. |
| 4 | Missing indexes | Each workflow has a named index (`14` §5); FK columns indexed. |
| 5 | Unsafe deletes | RESTRICT on every path to historical data; CASCADE only on pure link/child rows; users never hard-deleted. `job_applications` legacy CASCADE corrected to RESTRICT. |
| 6 | Duplicated business data | Intentional snapshots only (plan terms, promotion, order lines, addresses, bank account link, `membership_applications.full_name` vs `users`). Derived counters avoided (`times_used`, reading time, milestone timestamps); the one derived projection (`quantity_*`) is CHECK-guarded and reconciled. Payment `amount` vs `memberships.fee_amount` is a deliberate double record, verified by Q4. |
| 7 | Inconsistent status names | Two payment vocabularies are intentionally distinct and never mixed (`06` §1). `payment_rejected` is documented as transient. Job-application statuses reconcile the legacy UI/DB mismatch (`applied…hired`). |
| 8 | Missing historical snapshots | Membership pricing/duration/promotion, order lines, addresses, coupon/shipping labels, bank account per payment, application decisions (never overwritten), status history all snapshotted or append-only. |
| 9 | Membership-number race conditions | Locked counter row per (category, year), CAS on `pending_activation`, layered uniqueness, rollback returns the number (`04` §8.1). |
| 10 | Payment-purpose ambiguity | CHECK XOR + typed FKs + narrow creation Actions + reconciliation Q2. |
| 11 | Webhook duplication risk | `UNIQUE(gateway,event_id)` + claim CAS + guarded transitions; failed-signature requests deliberately not stored (`06` §5.2). |
| 12 | Document privacy | Private disk only (CHECK), server-generated paths, Policy-gated download, view auditing, purge tombstone, no public visibility value. |
| 13 | Notification read-state | `read_at` only; composite index answers unread count over the full set. |
| 14 | Inventory inconsistencies | Signed ledger, once-only keys, CHECK-guarded projection, reconciliation Q9. |
| 15 | Order/payment inconsistencies | XOR payment target; status separation; Q10, Q11; total CHECKs; reservation lifecycle rules (R-16). |
| 16 | Inappropriate polymorphism | Exactly two polymorphic pairs (audit subject — approved; Laravel notifiable — framework, restricted to `user`). Documents use an exclusive arc; payments use two typed FKs; email logs use typed FKs; status history is anchored to the application. |
| 17 | Unnecessary JSON | Four uses only: provider metadata, webhook payload, audit old/new values (+ framework notification data). Category-specific application fields, promotion categories, document owners and variant options are relational. |
| 18 | Unnecessary EAV | None. Membership settings are a typed singleton; no key/value tables. |
| 19 | Unnecessary Supabase tables | `home_sections`, `membership_benefits`, `membership_plans` (as legacy shape), the unused `site_settings` social columns, `blog_comments.parent_comment_id`, System-A `memberships` and `activity_logs` (as-is) were dropped/reworked/deferred with reasons (`10` §1). |
| 20 | TBC requirements becoming mandatory | Guest checkout: nullable columns only. Tax: no column. Event registration/partners/pages: no tables. Job-application documents: no minimum/maximum. Referral contacts: minimal history table only. Category names/prices: data, not schema. Currency: no default anywhere. Renewal number policy: not encoded (`renews_membership_id` is neutral). |

### 6.1 Membership workflow walk-through (application → activation → expiry)

| Step | Schema support |
|---|---|
| Applicant selects category, enters aviation info, uploads proof, submits | `membership_applications` + `documents(kind=aviation_proof)`; `open_email_key` blocks duplicates; status `submitted`; history `application.submitted`; M1 via `email_templates` + `email_logs` |
| Admin reviews; may request details | `proof_reviewed_at`; `membership_details_requests` (one open at a time); status `more_details_required` → applicant responds (documents with `membership_details_request_id`) → back to `submitted` |
| Reject | status `rejected`, `decided_*`, `decision_note`; row retained; cooldown from `decided_at` + `membership_settings` |
| Approve (proof reviewed enforced by CHECK) | `memberships` row `pending_activation`, plan terms snapshotted |
| Free path | promotion resolved at activation; `payment_not_required` ⇔ promotion; **no payment row**; activation immediately |
| Paid path | `payments` (`pending`, `bank_account_id`), `payment_pending`; evidence → `documents(payment_evidence)`, `payment_confirmation_submitted`; admin confirm → `paid` + `payment_confirmed`; reject → `failed` + back to `payment_pending`, same payment row |
| Activation | sequence lock, number issued, `starts_on`/`expires_on`, promotion snapshot, user provisioned (`pending_setup`), `account_setup_tokens`, history + audit |
| Account setup | hashed one-time token; password set; user `active`, `email_verified_at` |
| Card | rendered on demand from `memberships` + `users`; `verification_token` reserved |
| Expiry | `memberships (status, expires_on)`; M10 reminders at 30/7/0 days; job sets `status='expired'`, `expired_at`; history `membership.expired`; M11 |
| Renewal / reapplication | new `membership_applications` row → new `memberships` row with `renews_membership_id`; all earlier rows untouched |

**Result:** the schema supports the entire workflow with no step requiring a table or column that has not been designed. Two workflow *rules* are unresolved and recorded rather than guessed: what happens to numbers/overlap/pricing on renewal (OD-04) and whether a paid-path activation should re-evaluate promotions (OD-10).
