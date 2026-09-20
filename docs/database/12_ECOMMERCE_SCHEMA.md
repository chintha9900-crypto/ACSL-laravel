# 12 — E-commerce Schema

Design only. Tables (13): `product_categories`, `products`, `product_variants`, `product_images`, `inventory_transactions`, `carts`, `cart_items`, `coupons`, `shipping_methods`, `orders`, `order_items`, `order_addresses`, `order_status_history`. Payments and refunds are the shared tables in `06`.

**Scope guard.** An organisation/pilot shop. Not an accounting system (no ledger of accounts, cost of goods, tax filing), not a marketplace (single seller), not warehouse management (single stock location, no bins/transfers/purchase orders). The whole domain is **future/optional** (architecture OD #13): the schema is designed so Commerce can be built correctly, and nothing else depends on it. Implementation priority is the lowest.

Legend: **N** = `NOT NULL`, **Y** = nullable. All FKs `ON UPDATE RESTRICT`.

## 1. Catalogue

### 1.1 `product_categories` (legacy `shop_categories`) — KEEP

`id`, `name VARCHAR(150) N`, `slug VARCHAR(150) N UNIQUE`, `description TEXT Y`, `display_order SMALLINT UNSIGNED N 0`, `is_active TINYINT(1) N 1`, timestamps. Flat (no parent) as in the legacy; nesting is not confirmed. Soft delete NO.

### 1.2 `products` — REWORK (legacy `shop_products`)

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id` | BIGINT UNSIGNED AI | N | — | PK |
| `product_category_id` | BIGINT UNSIGNED | Y | NULL | FK → `product_categories`, **SET NULL** |
| `name` | VARCHAR(255) | N | — | |
| `slug` | VARCHAR(255) | N | — | **UNIQUE** (a soft-deleted product keeps its slug reserved) |
| `short_description` | VARCHAR(500) | Y | NULL | |
| `description` | TEXT | Y | NULL | plain vs rich text undecided (legacy plain) |
| `visibility` | VARCHAR(20) | N | `'public'` | `public`, `members` — `CHECK IN ('public','members')` (legacy set; members = any authenticated user, no tier logic) |
| `is_active` | TINYINT(1) | N | 1 | |
| `display_order` | SMALLINT UNSIGNED | N | 0 | |
| `created_at`, `updated_at` | TIMESTAMP | Y | NULL | |
| `deleted_at` | TIMESTAMP | Y | NULL | **soft delete** |

**Price is not on the product** — it is per variant (SKU-level), and a "simple" product has exactly one default variant. Indexes: `UNIQUE(slug)`, `(is_active, visibility, display_order)` (catalogue), FK index. **Soft delete YES:** rows are referenced by variants, the stock ledger and order history; removal from the catalogue must not break them.

### 1.3 `product_variants` — CONFIRMED (SKU-level pricing and stock)

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id` | BIGINT UNSIGNED AI | N | — | PK |
| `product_id` | BIGINT UNSIGNED | N | — | FK → `products`, **RESTRICT** |
| `sku` | VARCHAR(64) | N | — | **UNIQUE** |
| `name` | VARCHAR(150) | N | — | Variant label shown to buyers ("Large / Navy", or "Default") |
| `price` | DECIMAL(12,2) | N | — | `CHECK (price >= 0)`. Currency = the single shop currency on the order (OD-01); prices carry no per-row currency. |
| `quantity_on_hand` | INT | N | 0 | **Projection** of the ledger (`SUM(on_hand_delta)`) |
| `quantity_reserved` | INT | N | 0 | **Projection** of the ledger (`SUM(reserved_delta)`) |
| `is_active` | TINYINT(1) | N | 1 | |
| `display_order` | SMALLINT UNSIGNED | N | 0 | |
| `created_at`, `updated_at` | TIMESTAMP | Y | NULL | |
| `deleted_at` | TIMESTAMP | Y | NULL | **soft delete** |

**Stock can never go negative — enforced by the database:** `CHECK (quantity_on_hand >= 0)`, `CHECK (quantity_reserved >= 0)`, `CHECK (quantity_reserved <= quantity_on_hand)`. A reservation that would oversell makes the `UPDATE` violate the CHECK and the whole checkout transaction rolls back — even if application code forgets to check availability. Available stock = `quantity_on_hand − quantity_reserved`.

**Why the ledger is authoritative but a projection exists:** the ledger (§2) answers "why is stock what it is". The two cached integers exist because a CHECK cannot see other rows, so overselling could otherwise only be caught by application logic; the projection gives the database a row it can guard. It is maintained **only** in the same transaction that inserts the ledger row, never edited directly, and reconciled by a scheduled `SUM()` comparison (`15` R-19). Options rejected: bare mutable `stock_quantity` (no audit trail — forbidden by ADR-12); pure `SUM()` with no projection (works at this scale but leaves no DB-level oversell guard).

Variant *options* (size/colour matrices) are **DEFER** — `name` is a label; there is no options table and no EAV/JSON. Indexes: `UNIQUE(sku)`, `(product_id, is_active, display_order)`. Soft delete YES (as above).

### 1.4 `product_images`

`id`, `product_id` FK → `products` **CASCADE** (presentational child; products are soft-deleted so this never fires in normal operation), `image_path VARCHAR(255) N` (`public` disk), `alt_text VARCHAR(255) Y`, `display_order SMALLINT UNSIGNED N 0`, timestamps. Index `(product_id, display_order)`; the first image by order is the primary image (no `is_primary` flag). Per-variant images: DEFER. Orphaned-file cleanup by observer + scheduled safety net (`07` §4).

## 2. `inventory_transactions` — the ledger (CONFIRMED)

Append-only signed ledger. **No `updated_at`; no update/delete path.**

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id` | BIGINT UNSIGNED AI | N | — | PK / ordering |
| `product_variant_id` | BIGINT UNSIGNED | N | — | FK → `product_variants` RESTRICT |
| `type` | VARCHAR(20) | N | — | `CHECK IN ('purchase','sale','return','adjustment','damage','loss','reservation','release')` — the ACI-confirmed set |
| `on_hand_delta` | INT | N | 0 | signed change to physical stock |
| `reserved_delta` | INT | N | 0 | signed change to reserved stock |
| `order_item_id` | BIGINT UNSIGNED | Y | NULL | FK → `order_items` RESTRICT — typed link (no polymorphism) |
| `reason` | VARCHAR(255) | Y | NULL | required by the application for `adjustment`/`damage`/`loss` |
| `reference` | VARCHAR(100) | Y | NULL | free-text supplier/document reference for `purchase` (no purchase-order module) |
| `created_by_user_id` | BIGINT UNSIGNED | Y | NULL | FK → `users` RESTRICT. NULL = system (checkout, payment webhook, expiry job) |
| `once_key` | VARCHAR(40) | Y | generated | `VIRTUAL` = `IF(type IN ('reservation','sale','release'), CONCAT(order_item_id,':',type), NULL)`; **UNIQUE** |
| `created_at` | TIMESTAMP | N | CURRENT_TIMESTAMP | |

**Type semantics (each row is one CHECK disjunct):**

| type | `on_hand_delta` | `reserved_delta` | Meaning |
|---|---|---|---|
| `purchase` | `> 0` | `= 0` | stock received |
| `sale` | `< 0` | `<= 0` | stock leaves; `reserved_delta = on_hand_delta` when consuming a reservation (the normal paid-order path), `0` for a direct sale |
| `return` | `> 0` | `= 0` | customer return restocked |
| `adjustment` | `<> 0` | `= 0` | stock-take correction (either sign) |
| `damage` | `< 0` | `= 0` | write-off |
| `loss` | `< 0` | `= 0` | write-off |
| `reservation` | `= 0` | `> 0` | held for a `pending_payment` order |
| `release` | `= 0` | `< 0` | reservation freed (cancelled/expired order) |

Additional CHECKs: `type NOT IN ('sale','reservation','release') OR order_item_id IS NOT NULL` (order-driven events must name their order line); `on_hand_delta <> 0 OR reserved_delta <> 0`.

**Inconsistency guards**

* `UNIQUE(once_key)`: one `reservation`, one `sale`, one `release` per order item — a replayed payment webhook or a double-fired expiry job cannot deduct or free stock twice. (Order lines are immutable after placement, so whole-line events are correct; `return` is excluded to allow partial returns.)
* Every insert updates `product_variants.quantity_*` **in the same transaction**, with the variant row locked (`SELECT … FOR UPDATE`) — concurrent checkouts for one variant serialise; different variants do not contend.
* `SUM(on_hand_delta)` / `SUM(reserved_delta)` per variant must equal the projection (reconciliation query, `15` R-19).

Indexes: `(product_variant_id, id)` (ledger per variant, balance recompute), `UNIQUE(once_key)`, `(order_item_id)` (FK), `(type, created_at)` (reports), `(created_by_user_id)` (FK). Not modelled (accounting/warehouse scope): unit cost, valuation, locations/bins, lots/serials, stock transfers, supplier tables.

## 3. Carts

### 3.1 `carts` — transient, no price snapshot

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id` | BIGINT UNSIGNED AI | N | — | PK |
| `user_id` | BIGINT UNSIGNED | Y | NULL | FK → `users` **RESTRICT** (users are never deleted; RESTRICT also keeps the CHECK below legal) |
| `guest_token` | CHAR(40) `ascii_bin` | Y | NULL | **UNIQUE**. Exists only if guest checkout is approved (OD-18); never set otherwise. |
| `coupon_id` | BIGINT UNSIGNED | Y | NULL | FK → `coupons` **SET NULL** |
| `created_at`, `updated_at` | TIMESTAMP | Y | NULL | |

`CHECK (user_id IS NOT NULL OR guest_token IS NOT NULL)`. `UNIQUE(user_id)` — one cart per member (`NULL`s allowed for guests). A cart holds **no prices**: prices, discounts, shipping and stock are re-derived server-side at checkout (architecture `09` §4); the cart is intent, not a record. Carts are **deleted** at order placement and by a stale-cart cleanup job (updated_at index). Soft delete NO.

### 3.2 `cart_items`

`id`, `cart_id` FK → `carts` **CASCADE**, `product_variant_id` FK → `product_variants` RESTRICT, `quantity SMALLINT UNSIGNED N` with `CHECK (quantity >= 1)`, timestamps. `UNIQUE(cart_id, product_variant_id)` (one line per variant), FK index on `product_variant_id`. No maximum in the DB (legacy UI clamp 1–99 is a validation rule).

## 4. Discounts and shipping

### 4.1 `coupons` — deliberately minimal

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id` | BIGINT UNSIGNED AI | N | — | PK |
| `code` | VARCHAR(50) | N | — | **UNIQUE**, case-insensitive collation |
| `description` | VARCHAR(255) | Y | NULL | |
| `discount_type` | VARCHAR(10) | N | — | `CHECK IN ('percentage','fixed')` |
| `discount_value` | DECIMAL(12,2) | N | — | `CHECK (discount_value > 0)`; `CHECK (discount_type <> 'percentage' OR discount_value <= 100)` |
| `minimum_order_amount` | DECIMAL(12,2) | Y | NULL | `CHECK (minimum_order_amount IS NULL OR minimum_order_amount >= 0)` |
| `starts_at`, `ends_at` | TIMESTAMP | Y | NULL | `CHECK (ends_at IS NULL OR starts_at IS NULL OR ends_at >= starts_at)` |
| `usage_limit` | INT UNSIGNED | Y | NULL | NULL = unlimited |
| `is_active` | TINYINT(1) | N | 1 | |
| `created_at`, `updated_at` | TIMESTAMP | Y | NULL | |

No `times_used` counter (derived data): usage = count of non-cancelled `orders.coupon_id`. Limit enforcement runs under `SELECT … FOR UPDATE` on the coupon row at order placement so two concurrent checkouts cannot both take the last use. No tiering/stacking/customer targeting (architecture `09` §7). Soft delete NO (`is_active`; `orders.coupon_id` is RESTRICT).

### 4.2 `shipping_methods`

`id`, `name VARCHAR(100) N`, `description VARCHAR(255) Y`, `price DECIMAL(12,2) N CHECK (>= 0)`, `is_active`, `display_order`, timestamps. Zones/carrier rate tables: DEFER.

## 5. Orders

### 5.1 `orders` — CONFIRMED

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id` | BIGINT UNSIGNED AI | N | — | PK |
| `public_id` | CHAR(26) `ascii_bin` | N | — | **UNIQUE** ULID for URLs (confirmation page is Policy-gated, never "anyone with the id") |
| `order_number` | VARCHAR(20) `ascii_bin` | N | — | **UNIQUE** business identifier shown to customers. Never a key. Format is an implementation choice: recommended a prefix + random unambiguous code with insert-retry on the unique constraint. |
| `user_id` | BIGINT UNSIGNED | Y | NULL | FK → `users` RESTRICT. NULL only if guest checkout is approved (OD-18). |
| `customer_name` | VARCHAR(150) | N | — | Snapshot |
| `customer_email` | VARCHAR(255) | N | — | Snapshot |
| `customer_phone` | VARCHAR(40) | Y | NULL | Snapshot |
| `status` | VARCHAR(30) | N | `'pending_payment'` | `CHECK IN ('pending_payment','paid','processing','packed','shipped','delivered','cancelled','refunded')` |
| `currency` | CHAR(3) | N | — | No default (OD-01); one currency per order |
| `subtotal_amount` | DECIMAL(12,2) | N | — | = Σ `order_items.line_total` |
| `discount_amount` | DECIMAL(12,2) | N | 0 | |
| `shipping_amount` | DECIMAL(12,2) | N | 0 | |
| `total_amount` | DECIMAL(12,2) | N | — | `CHECK (total_amount = subtotal_amount - discount_amount + shipping_amount)` |
| `coupon_id` | BIGINT UNSIGNED | Y | NULL | FK → `coupons` RESTRICT |
| `coupon_code` | VARCHAR(50) | Y | NULL | Snapshot |
| `shipping_method_id` | BIGINT UNSIGNED | Y | NULL | FK → `shipping_methods` RESTRICT |
| `shipping_method_name` | VARCHAR(100) | Y | NULL | Snapshot |
| `tracking_reference` | VARCHAR(150) | Y | NULL | Carrier tracking text; no shipments table |
| `customer_notes` | TEXT | Y | NULL | |
| `reservation_expires_at` | TIMESTAMP | Y | NULL | When unpaid stock reservations are released by the expiry job |
| `created_at`, `updated_at` | TIMESTAMP | Y | NULL | `created_at` = placed time |

CHECKs: `subtotal_amount >= 0`, `discount_amount >= 0`, `discount_amount <= subtotal_amount`, `shipping_amount >= 0`, `total_amount >= 0`, `(coupon_id IS NULL) = (coupon_code IS NULL)`, `(shipping_method_id IS NULL) = (shipping_method_name IS NULL)`, status vocabulary. **Tax is not modelled** (OD-19) — adding `tax_amount` later is an additive column plus a revised total CHECK, and an explicit non-default so it cannot silently become mandatory now.

**Milestone timestamps** (`paid_at`, `shipped_at`, …) are **not** columns: `order_status_history` is the single source of "when", avoiding two copies of the same fact.

| FK | Parent | Cardinality | ON DELETE |
|---|---|---|---|
| `user_id` | `users` | many orders : 0..1 | RESTRICT |
| `coupon_id` | `coupons` | many : 0..1 | RESTRICT |
| `shipping_method_id` | `shipping_methods` | many : 0..1 | RESTRICT |

Indexes: `UNIQUE(public_id)`, `UNIQUE(order_number)`, `(user_id, created_at)` (my orders), `(status, created_at)` (admin queue), `(status, reservation_expires_at)` (expiry sweep), `(customer_email)` (guest lookup, only if OD-18), FK indexes. Soft delete **NO** — orders are never deleted or hidden; cancellation is a status.

**Order ↔ payment consistency** (application rules, `15` R-16): an order becomes `paid` only from a confirmed `payments` row whose `order_id` = this order and `amount`/`currency` = `total_amount`/`currency`; `pending_payment` orders always hold live `reservation` ledger rows; leaving `pending_payment` without paying triggers `release`. Order status and payment status are **separate** vocabularies (an order can be `paid` while its payment later becomes `partially_refunded`).

### 5.2 `order_items` — historical price preserved

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id` | BIGINT UNSIGNED AI | N | — | PK |
| `order_id` | BIGINT UNSIGNED | N | — | FK → `orders` RESTRICT |
| `product_variant_id` | BIGINT UNSIGNED | N | — | FK → `product_variants` RESTRICT (the variant is soft-deleted, never removed, so the link always resolves) |
| `product_name` | VARCHAR(255) | N | — | **Snapshot** |
| `variant_name` | VARCHAR(150) | N | — | **Snapshot** |
| `sku` | VARCHAR(64) | N | — | **Snapshot** |
| `unit_price` | DECIMAL(12,2) | N | — | **Snapshot** of the price charged; `CHECK (unit_price >= 0)` |
| `quantity` | SMALLINT UNSIGNED | N | — | `CHECK (quantity >= 1)` |
| `line_total` | DECIMAL(12,2) | N | — | `CHECK (line_total = unit_price * quantity)` |
| `created_at`, `updated_at` | TIMESTAMP | Y | NULL | |

`UNIQUE(order_id, product_variant_id)`; FK index on `product_variant_id`. Later price/name/SKU changes on the product or variant never touch these rows. Line items are **immutable** after placement (application rule) — which is also what makes the ledger's once-only keys correct. Soft delete NO.

### 5.3 `order_addresses` — historical addresses as snapshots

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id` | BIGINT UNSIGNED AI | N | — | PK |
| `order_id` | BIGINT UNSIGNED | N | — | FK → `orders` RESTRICT |
| `type` | VARCHAR(20) | N | `'shipping'` | `CHECK IN ('shipping','billing')`. Only `shipping` is required; `billing` is supported but not mandated. |
| `recipient_name` | VARCHAR(150) | N | — | |
| `phone` | VARCHAR(40) | Y | NULL | |
| `line1` | VARCHAR(255) | N | — | |
| `line2` | VARCHAR(255) | Y | NULL | |
| `city` | VARCHAR(100) | N | — | |
| `region` | VARCHAR(100) | Y | NULL | |
| `postal_code` | VARCHAR(20) | Y | NULL | |
| `country` | CHAR(2) | N | — | ISO 3166-1 alpha-2 |
| `created_at`, `updated_at` | TIMESTAMP | Y | NULL | |

`UNIQUE(order_id, type)`. This is a **copy** made at placement — there is no live FK to an address book, and no address-book table exists (DEFER). A separate table (rather than columns on `orders`) keeps `orders` narrow and allows a billing address without repeating columns. Soft delete NO.

### 5.4 `order_status_history` — append-only

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id` | BIGINT UNSIGNED AI | N | — | PK |
| `order_id` | BIGINT UNSIGNED | N | — | FK → `orders` RESTRICT |
| `from_status` | VARCHAR(30) | Y | NULL | NULL for the initial `pending_payment` row |
| `to_status` | VARCHAR(30) | N | — | same CHECK vocabulary as `orders.status` |
| `actor_user_id` | BIGINT UNSIGNED | Y | NULL | FK → `users` RESTRICT. NULL = system (webhook/expiry). |
| `note` | VARCHAR(500) | Y | NULL | e.g. cancellation reason, tracking note |
| `created_at` | TIMESTAMP | N | CURRENT_TIMESTAMP | |

Index `(order_id, id)`, FK index on `actor_user_id`. Also audit-logged for admin transitions.

## 6. Refunds

There is **no commerce refund table**. An order refund is a `payment_refunds` row against the order's `payments` row (`06` §4). Returned goods are restocked by an explicit `return` ledger entry — a returns/RMA workflow is DEFER (not confirmed).

## 7. Historical-data summary

| Fact | Where it is frozen |
|---|---|
| Price charged, product/variant name, SKU | `order_items` |
| Shipping cost, method name, coupon code, discount | `orders` |
| Delivery/billing address | `order_addresses` |
| Customer contact | `orders.customer_*` |
| Currency | `orders.currency` |
| Who changed status and when | `order_status_history` |
| Why stock changed | `inventory_transactions` |
