# 09 — Commerce / E-commerce Architecture

The legacy e-shop (`docs/reverse-engineering/FEATURES.md` §B, `WORKFLOWS.md` §2) had no payment integration, no inventory concept, and a broken/unused order-status lifecycle. This document designs the **future** commerce capability per the Phase 2 instructions' explicit field/lifecycle lists (CONFIRMED REQUIREMENT for the listed entities and states), while deliberately not exceeding what ACI has actually asked for ("do not over-engineer the commerce system beyond what ACI actually needs").

**Scope note**: Commerce is not currently an active build priority (no confirmed launch date or catalog scope was given) — this document exists so the data model and Payments integration are designed correctly *if/when* ACI proceeds, consistent with `01_ARCHITECTURE_OVERVIEW.md`'s "suitable for future e-commerce" quality requirement, not because Commerce is being built in the next phase.

## 1. Entities (conceptual, CONFIRMED REQUIREMENT list)

| Entity | Purpose | Key notes |
|---|---|---|
| `Product` | Sellable item | price as **DECIMAL** (never float — CONFIRMED, corrects the legacy's bare `numeric` with no declared precision) |
| `ProductCategory` | Taxonomy | |
| `ProductImage` | One-to-many images per product | stored via Files/Documents domain conventions (`07_FILE_STORAGE_ARCHITECTURE.md`) |
| `ProductVariant` | SKU-level variation (size/colour/etc.) | **ARCHITECTURAL DECISION**: variants are a real table, not a JSON blob of options, so stock/price can differ per SKU with proper relational integrity — avoids the "JSON column where a relational structure is more appropriate" anti-pattern the principles warn against |
| `InventoryTransaction` | Ledger entry against a `ProductVariant` (see §2) | never a bare mutable "stock" integer |
| `Cart`, `CartItem` | Pre-checkout basket | may belong to a `User` or a guest session key — guest checkout support is `TBC`, see `16_OPEN_DECISIONS.md` |
| `Order`, `OrderItem` | Placed order | `OrderItem` **snapshots** product name/price/variant at order time (continuing the one correct legacy pattern already identified in `DATABASE.md`) |
| `Address` | Snapshotted per order | never a live FK to a mutable "current address" row — an order's shipping address must not silently change if the customer later edits their saved address |
| `ShippingMethod` | Available shipping options + cost | |
| `Coupon` | Discount codes | |
| `Refund` | Reuses the Payments domain's `PaymentRefund` (§05) rather than a separate Commerce-specific refund table — an order refund **is** a payment refund, scoped via `Payment.order_id` |

## 2. Inventory ledger (CONFIRMED REQUIREMENT: ledger, not a bare stock number)

**What is being proposed**: `InventoryTransaction` rows, each with a `type` (`purchase`, `sale`, `return`, `adjustment`, `damage`, `loss`, `reservation`, `release` — the exact set given in the Phase 2 instructions), a signed `quantity_change`, a reference to the triggering event (e.g. an `Order`/`OrderItem` for `sale`/`reservation`/`release`), and a timestamp. A `ProductVariant`'s current available stock is a **derived value** (`SUM(quantity_change)` for that variant, optionally cached/materialized for read performance — see below), never a column that's directly incremented/decremented by application code.

- **Why appropriate for ACI**: a ledger makes "why is stock at this number" always answerable (every change has a row explaining it), supports the `reservation`/`release` pair needed for a correct checkout flow (reserve stock when an order is placed, release it if payment fails/is cancelled, convert to a permanent `sale` on payment confirmation), and avoids the classic race condition of two checkouts both reading and decrementing the same mutable integer without a lock.
- **Laravel mechanism**: `InventoryTransaction` rows are only ever created inside a `DB::transaction()` alongside the event that caused them (placing an order reserves stock; confirming payment converts the reservation to a sale; a cancelled/expired reservation releases it). A cached "current available quantity" column on `ProductVariant` is acceptable as a read-performance optimization **only if** it is always derived from and reconciled against the ledger (never the other way around) — e.g. recomputed by a listener on every `InventoryTransaction` write, with a scheduled reconciliation job as a safety net. This is presented as an optional optimization, not a requirement, per "avoid premature optimization" — a small catalog can compute `SUM()` live without a cache.
- **Security considerations**: none specific beyond standard admin-only write authorization on manual adjustment types (`adjustment`, `damage`, `loss`).
- **Data integrity considerations**: stock can never go negative *silently* — a checkout that would over-sell is rejected at the point of attempting a `reservation` transaction (checked against the derived current balance within the same lock/transaction), not discovered after the fact.
- **Alternatives considered**: a single mutable `stock_quantity` column on `ProductVariant`, decremented directly on sale — explicitly rejected by the Phase 2 instructions ("inventory must use a ledger approach rather than simply maintaining an unexplained stock number") and by general data-integrity practice (no audit trail of *why* stock changed, and race-prone under concurrent checkouts without careful locking that a ledger's insert-only nature avoids more naturally).

## 3. Order lifecycle & payment lifecycle (CONFIRMED REQUIREMENT — the exact state sets)

**Order lifecycle** (`Order.status`): `pending_payment`, `paid`, `processing`, `packed`, `shipped`, `delivered`, `cancelled`, `refunded`.

**Payment lifecycle** (`Payment.status`, shared with Membership per `08_PAYMENT_ARCHITECTURE.md`): `pending`, `processing`, `paid`, `failed`, `cancelled`, `refunded`, `partially_refunded`.

- **Laravel mechanism**: both as backed PHP enums. `Order.status` and its `Payment.status` are related but distinct (an order can be `paid` while later independently becoming `refunded` at the order level once a `PaymentRefund` is processed against its payment) — transitions are only performed through Actions (`Actions/Commerce/PlaceOrder`, `Actions/Commerce/MarkOrderPacked`, etc.), each authorized and, where it touches money or stock, transactional.
- **Data integrity considerations**: `Order.status` moves to `paid` only in reaction to a `PaymentConfirmed` event (never optimistically on checkout submission) — mirrors the Payments domain's "never trust a redirect" rule (§05 §4). Stock reservation (§2) is created at `pending_payment` and converted to a `sale` transaction on the transition to `paid`; if an order is `cancelled` before payment, its reservation is `release`d.

## 4. Checkout revalidation (CONFIRMED REQUIREMENT: server-side, always)

**What is being proposed**: `Actions/Commerce/PlaceOrder` never trusts any price, discount, shipping cost, tax amount, or stock availability submitted by the client — it re-derives every one of these server-side from the current `Product`/`ProductVariant`/`Coupon`/`ShippingMethod` rows at the moment of order placement, exactly as the legacy checkout already did correctly for price (`WORKFLOWS.md` §2, `shop.functions.ts` re-fetching real product price/name) — this design extends that one correct legacy pattern to *every* revalidated field (stock, discount, shipping, tax), which the legacy app did not have to worry about because it had none of those concepts yet.

- **Why appropriate for ACI**: this is standard, necessary e-commerce practice — a client-manipulated price/discount/shipping figure must never be trusted, and stock must be checked at the instant of order placement (not merely at "add to cart" time) to avoid overselling.
- **Laravel mechanism**: a single Form Request validates shape (item ids, quantities); `PlaceOrder` then, inside one `DB::transaction()`, re-fetches each `ProductVariant`, checks/reserves stock (§2), re-validates any `Coupon` code against its own rules (active, not expired, applicable to the items in cart), recomputes shipping cost from the selected `ShippingMethod` and recomputes tax (if/when ACI needs tax — currently not confirmed as a requirement, see `16_OPEN_DECISIONS.md`), and only then creates the `Order` + `OrderItem` rows with the **recomputed, snapshotted** values — never the client-submitted ones.
- **Data integrity considerations**: the whole revalidate-and-create sequence is one transaction — if stock cannot be reserved for any line, the entire order placement fails and nothing is created (this also fixes the legacy's flagged non-atomic order+items insert gap, `WORKFLOWS.md` §2 step 6e).

## 5. Address snapshotting (cross-cutting principle, applied here)

An `Order`'s shipping/billing address is copied onto the order at placement time (either as denormalized columns on `Order` or a dedicated `OrderAddress` row referencing the order, not the customer's mutable saved-address book) — matches the same snapshotting principle already applied to `OrderItem` pricing, and the general architectural principle stated in `01_ARCHITECTURE_OVERVIEW.md` §6.

## 6. Guest checkout, currency, and tax — not assumed

The legacy schema appeared to intend guest checkout (`shop_orders.user_id` nullable) but its RLS/GRANT setup made it impossible in practice (`DATABASE.md`, `WORKFLOWS.md` §2 §5 legacy notes) — an unresolved ambiguity, not a confirmed requirement either way. This architecture supports guest checkout structurally (`Cart`/`Order.user_id` nullable, `Order` carries its own `customer_name`/`customer_email` regardless of account) but **does not assume ACI wants it enabled** — this is recorded as `TBC` in `16_OPEN_DECISIONS.md`, along with currency (see `08_PAYMENT_ARCHITECTURE.md`) and whether tax calculation is needed at all for ACI's merchandise (Sri Lankan low-value club-branded goods may not need one — not assumed either way).

## 7. Coupons (kept deliberately simple)

**ARCHITECTURAL DECISION**: `Coupon` supports the common minimum (code, percentage-or-fixed discount, active window, usage limit, minimum order value) and nothing more elaborate (no tiered/stacking-rule engine, no per-customer-segment targeting) unless ACI asks for it — matches "do not over-engineer the commerce system beyond what ACI actually needs."

## 8. Relationship to Payments

Every `Order` that requires payment gets exactly one `Payment` row (via `Actions/Payments/CreateOrderPayment`, `order_id` set, `membership_id` null — enforcing the "exactly one business purpose" rule from `08_PAYMENT_ARCHITECTURE.md` §1) through the same `PaymentGatewayContract` abstraction Membership uses — so adding a real payment gateway later benefits both domains simultaneously, not just one.

*Per the Phase 2 restriction: conceptual design only, describing a future capability. No Commerce tables, models, or code exist yet, and none are scheduled for the next implementation phase without further confirmation.*
