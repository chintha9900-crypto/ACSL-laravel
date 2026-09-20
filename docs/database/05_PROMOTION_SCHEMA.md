# 05 — Introductory Period and Promotions

**Revised by the confirmed decision OD-10.** This document now distinguishes two different concepts that earlier revisions conflated:

| Concept | What it is | Status in the schema |
|---|---|---|
| **Introductory free period** | The first **6 months** of membership are **free for every approved new member**. A standard, mandatory, membership-lifecycle rule — *not* optional, *not* a promotion, coupon or eligibility calculation, nothing is re-evaluated after approval. | **Implemented** — settings-driven introductory term (§1). No promotion tables involved. |
| **Marketing promotions** | Possible *future* time-limited offers (e.g. campaigns). Not defined by any confirmed requirement. | **DEFERRED** — retained as a design reservation only (§3); **no tables in the initial migration set**. |

**Rule of separation:** promotions never control, shorten, extend, replace or gate the introductory free period. Nothing in `memberships` or `membership_terms` references a promotion.

Legend: **N** = `NOT NULL`, **Y** = nullable.

## 1. The introductory free period (authoritative)

Workflow: **Application submitted → Admin review → Admin approval → Payment/Free decision (initial membership: payment not required) → Membership activated → first 6 months free → before expiry, renewal/payment notifications → member pays the renewal fee → renewed for another (normally) 12 months.**

| Aspect | Rule | Where it lives |
|---|---|---|
| Who gets it | **Every** approved new member, all three categories, no eligibility calculation | Applied unconditionally: the payment/free decision after approval confirms payment is not required, then the activation action creates term 1 |
| Length | 6 months (confirmed). Configurable data, never hard-coded | `membership_settings.introductory_period_months` (default 6; `04` §5) |
| Snapshot | Each member's first term stores the length actually granted | `membership_terms.duration_months` (term 1) |
| Starts | Actual activation date; `expires_on = starts_on + N months − 1 day` (inclusive last day). Example: activated 15 Oct 2026 → expires 14 Apr 2027 | `membership_terms.starts_on/expires_on` |
| Payment | **None.** `payment_status = payment_not_required`. **No £0 payment row is ever created** — `payments.amount > 0` is CHECK-enforced, `membership_terms.fee_amount` is NULL for term 1 | `04` §9, `06` §3 |
| Not re-evaluated | The payment/free decision for an initial membership is fixed (always "not required"), not evaluated per member; there is no resolution query, priority, campaign window or category applicability | — |
| Once per member | Only term 1 can be `introductory` (`term_no = 1` ⇔ introductory); a second membership identity for the same person is blocked by `UNIQUE(user_id)` and rule R-06 | `04` §8–9 |
| After the period | Member is notified (configurable offsets), pays the **renewal fee** from the database (`membership_plans.fee_amount`), and a renewal term (normally 12 months) starts once payment is admin-confirmed. **No automatic charge; no automatic renewal without payment.** | `04` §9, `06` |
| Changing the setting | Affects future activations only; existing terms keep their own snapshot | `membership_terms.duration_months` |
| Email | M4 tells the applicant they are approved and their first N months are free (N read from the term/settings — never typed into the template) | `08` |

The renewal **fee amount is not fixed by any document** — it is entered by ACI in `membership_plans` (OD-02); renewal notification offsets are configuration (`config/membership.php`).

**Legacy note:** the reference app's "first 100 students free" launch offer is a different, unconfirmed legacy idea; it is not carried over and is not the introductory period. If ACI ever wants it, it would be a *marketing promotion* (§3).

## 2. What was removed from the previous design

| Removed | Why |
|---|---|
| Promotion resolution "at activation" / "at the approval decision" (old OD-10) | The free period is unconditional; there is nothing to resolve |
| `membership_promotions` and `membership_promotion_category` tables (from the initial set) | They existed to *decide* the free period; that decision no longer exists |
| `memberships.membership_promotion_id`, `promotion_name`, `promotion_free_months` | Replaced by the term snapshot (`term_kind`, `duration_months`) |
| Constraint "free ⇔ promotion applied" | Replaced by "free ⇔ introductory term" (`04` §9) |
| Priority/tie-break, applicable-categories pivot, promotion audit events for the free period | Not applicable to a mandatory rule |
| Old OD-05 "may a person receive a promotion more than once?" | Obsolete: the introductory term is once per member (`17`) |

## 3. Marketing promotions — DEFERRED (design reservation)

If ACI later defines optional marketing promotions, they are a **separate capability** with their own tables and behaviour and must not touch the introductory rule. Nothing about them is confirmed, so **no tables are created now and no behaviour is invented** (for example, whether a promotion could discount a renewal fee is undefined).

Reserved shape (reference only, not scheduled, not in the migration set):

| Table | Purpose |
|---|---|
| `membership_promotions` | name, description, `is_active`, `starts_on`, `ends_on`, `priority`, created/updated by, timestamps; *what the promotion does* (discount, extra months, …) is deliberately **undefined** |
| `membership_promotion_category` | pivot: which categories a promotion applies to (normalised; no JSON array) |

Constraints on any future design: (1) never referenced by `membership_terms`' introductory logic; (2) if a promotion ever affects a renewal, the term must **snapshot** the applied promotion (name and effect) exactly as plans are snapshotted; (3) a promotion that grants free time would still need the "no £0 payment" rule (`payments.amount > 0`); (4) audit-logged. The earlier column design (`free_duration_months`, `grants_free_membership`, `priority`, windows) may be reused but must be re-confirmed against the then-defined behaviour.

## 4. Audit

Introductory-term events use `membership_status_history` (`membership.introductory_term_started`) and `audit_logs` (`membership.activated`); there are no `promotion.*` audit events until marketing promotions exist.
