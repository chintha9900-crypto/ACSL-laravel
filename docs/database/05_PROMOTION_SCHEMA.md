# 05 — Promotion Schema

Design only. Tables: `membership_promotions`, `membership_promotion_category`. The six-month introductory offer is **one row of data** in `membership_promotions`; nothing in the schema or (later) the code refers to "six months" or "introductory".

Legend: **N** = `NOT NULL`, **Y** = nullable. All FKs `ON UPDATE RESTRICT`.

## 1. `membership_promotions` — CONFIRMED

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id` | BIGINT UNSIGNED AI | N | — | PK |
| `name` | VARCHAR(150) | N | — | e.g. "Introductory free membership" (data) |
| `description` | TEXT | Y | NULL | Internal/admin description |
| `is_active` | TINYINT(1) | N | 1 | Master on/off switch |
| `starts_on` | DATE | N | — | First day the promotion can be applied |
| `ends_on` | DATE | N | — | Last day the promotion can be applied (inclusive). **Applies to the *activation* date, not to the length of the free period** — see §3. |
| `grants_free_membership` | TINYINT(1) | N | 1 | The "free membership flag" from the confirmed requirement |
| `free_duration_months` | SMALLINT UNSIGNED | Y | NULL | Length of the free term. `NOT NULL` when `grants_free_membership = 1`. |
| `priority` | SMALLINT UNSIGNED | N | — | Lower number = higher priority. Deterministic tie-break: `ORDER BY priority ASC, id ASC`. |
| `created_by_user_id` | BIGINT UNSIGNED | Y | NULL | FK → `users` RESTRICT |
| `updated_by_user_id` | BIGINT UNSIGNED | Y | NULL | FK → `users` RESTRICT |
| `created_at`, `updated_at` | TIMESTAMP | Y | NULL | |

**CHECK constraints**

| Constraint | Meaning |
|---|---|
| `ends_on >= starts_on` | valid window |
| `grants_free_membership = 0 OR free_duration_months > 0` | a free promotion must state how long it is free for |
| `free_duration_months IS NULL OR free_duration_months <= 120` | sanity bound against typos (10 years); adjustable data-quality guard, not a business rule |

**What a promotion may *not* be:** there is no discount-percentage, coupon or stacking field. The confirmed model is "free for N months"; a non-free promotion (`grants_free_membership = 0`) has no defined behaviour today and is reserved for a future rule change (any such column would be additive).

**Foreign keys**

| FK column | Parent | Cardinality | ON DELETE |
|---|---|---|---|
| `created_by_user_id`, `updated_by_user_id` | `users` | many : 0..1 | RESTRICT |

**Indexes**

| Index | Columns | Reason |
|---|---|---|
| `…_resolution_index` | `is_active, starts_on, ends_on, priority` | the activation-time resolution query (active, date-in-window, ordered by priority) |
| FK indexes | the two user FKs | |

**Soft delete: NO.** A promotion is **deactivated** (`is_active = 0`), never deleted, because memberships reference it. **Audit:** every create/update is written to `audit_logs` with old/new values (`promotion.created`, `promotion.updated`) — this is how a later edit of a promotion is still traceable. **Editing after use:** because a membership stores its own snapshot (§3), editing a promotion never rewrites what an existing member was granted.

## 2. `membership_promotion_category` — CONFIRMED (applicable categories, normalised)

Pivot: which categories a promotion applies to. Replaces the architecture's JSON array (`whereJsonContains`) — an array in a column is not normalised, cannot carry an FK, and cannot be indexed usefully.

| Column | Type | Null | Notes |
|---|---|---|---|
| `membership_promotion_id` | BIGINT UNSIGNED | N | FK → `membership_promotions`, **ON DELETE CASCADE** (link rows have no independent value; promotions are not deleted in practice) |
| `membership_category_id` | BIGINT UNSIGNED | N | FK → `membership_categories`, ON DELETE RESTRICT |
| PK | (`membership_promotion_id`, `membership_category_id`) | | prevents duplicates |

Secondary index: `(membership_category_id, membership_promotion_id)` for "which promotions apply to category X". A promotion with **no** pivot rows applies to **no** category (never "all") — an explicit, safe default (application rule R-07).

## 3. Resolution and application (how the schema supports the confirmed rules)

**Resolution query (run once, inside the activation transaction):**

```sql
SELECT p.*
FROM membership_promotions p
JOIN membership_promotion_category pc ON pc.membership_promotion_id = p.id
WHERE pc.membership_category_id = :category
  AND p.is_active = 1
  AND p.grants_free_membership = 1
  AND :activation_date BETWEEN p.starts_on AND p.ends_on
ORDER BY p.priority ASC, p.id ASC
LIMIT 1;
```

| Confirmed rule | Schema mechanism |
|---|---|
| Multiple promotions, several active at once | no uniqueness on window/category overlap |
| Exactly **one** promotion per membership, no stacking | `memberships.membership_promotion_id` is a single nullable FK; `LIMIT 1` |
| Deterministic tie-break by configurable priority | `priority` column + `id` final tie-break |
| Eligibility decided at **activation** | the query is called from the activation action with the activation date; nothing is pre-computed at application/approval time |
| Record which promotion applied | `memberships.membership_promotion_id` **plus** snapshot `promotion_name`, `promotion_free_months` |
| Free period runs its full course even if the campaign ends earlier | `memberships.expires_on` is computed once from `starts_on + promotion_free_months − 1 day` and stored; `ends_on` gates *activation*, never expiry |
| No payment, no fake £0 payment | free ⇒ `payment_status = 'payment_not_required'` ⇔ promotion set (memberships CHECK); **no `payments` row is created**, and `payments.amount > 0` is CHECK-enforced so a £0 payment cannot be inserted even by mistake |
| Not hard-coded to 6 months | `free_duration_months` per promotion |

Worked example (from `WORKFLOWS.md` §0.6): promotion window 2026-10-01 → 2027-03-31, activation 2026-10-15, `free_duration_months = 6` ⇒ `starts_on = 2026-10-15`, `expires_on = 2027-04-14` (inclusive last day). The promotion's own `ends_on` (2027-03-31) is earlier than the expiry, and that is correct.

**Unresolved rules that affect eligibility but not the schema:** whether a returning member may receive a free promotion again (OD-05); whether a paid-path applicant should be re-evaluated for a promotion at post-payment activation (OD-10). The schema can express any answer because `memberships.user_id` + `membership_promotion_id` make "has this person had this promotion" a simple query.

## 4. Audit

`audit_logs` events: `promotion.created`, `promotion.updated`, `promotion.deactivated`, `promotion.applied` (the last also appears in `membership_status_history` as `membership.promotion_applied`).
