# 12 — E-commerce Schema

## Status

**E-Shop Step 1 (database foundation) is implemented.** This document now
describes the schema as it actually exists, not a proposal. Tables (8):
`product_categories`, `products`, `product_images`, `inventories`, `carts`,
`cart_items`, `orders`, `order_items`. Payments reuse the shared `payments`
table (`06_PAYMENT_SCHEMA.md`), linked through `payments.order_id`.

No storefront, cart UI, checkout flow, payment gateway, admin UI or emails
exist yet — this step is the database layer only. A larger architecture was
proposed earlier for this domain; §7 below records exactly what was deferred
and why, so later steps can build on top of what exists rather than
rediscovering it.

**Scope guard.** An organisation/pilot shop. Not an accounting system (no
ledger of accounts, cost of goods, tax filing), not a marketplace (single
seller), not warehouse management (single stock location, no bins/transfers/
purchase orders). Implementation priority is low relative to membership.

Legend: **N** = `NOT NULL`, **Y** = nullable. Money columns are
`DECIMAL(12,2)`, matching `payments.amount`.

## 1. Catalogue

### 1.1 `product_categories`

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id` | BIGINT UNSIGNED AI | N | — | PK |
| `name` | VARCHAR(150) | N | — | |
| `slug` | VARCHAR(150) | N | — | **UNIQUE** |
| `description` | TEXT | Y | NULL | |
| `is_active` | TINYINT(1) | N | 1 | |
| `created_at`, `updated_at` | TIMESTAMP | Y | NULL | |

Flat (no parent/nesting). No soft delete. A category cannot be deleted while
it still has products assigned — see 1.2.

### 1.2 `products`

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id` | BIGINT UNSIGNED AI | N | — | PK |
| `product_category_id` | BIGINT UNSIGNED | N | — | FK → `product_categories`, **RESTRICT** |
| `name` | VARCHAR(255) | N | — | |
| `slug` | VARCHAR(255) | N | — | **UNIQUE** |
| `sku` | VARCHAR(64) | N | — | **UNIQUE** |
| `description` | TEXT | Y | NULL | |
| `price` | DECIMAL(12,2) | N | — | `CHECK (price >= 0)`. No per-product currency — see §4. |
| `access_type` | VARCHAR(20) | N | `'PUBLIC'` | `CHECK IN ('PUBLIC','MEMBER_ONLY')` — see §4 |
| `is_active` | TINYINT(1) | N | 1 | |
| `created_at`, `updated_at` | TIMESTAMP | Y | NULL | |

**Price and SKU live directly on the product** — there is no
`product_variants` indirection (§7.1). "Products belong to categories" is a
required relationship, not optional: `product_category_id` is `NOT NULL`
with `RESTRICT` on delete, so a category with products assigned cannot be
deleted until they are reassigned or removed. No soft delete: a product can
be hard-deleted, and `order_items.product_id` (§3.2) is nullable with
`SET NULL` specifically so that is safe.

Indexes: `UNIQUE(slug)`, `UNIQUE(sku)`, `(is_active, access_type)`, FK index.

### 1.3 `product_images`

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id` | BIGINT UNSIGNED AI | N | — | PK |
| `product_id` | BIGINT UNSIGNED | N | — | FK → `products`, **CASCADE** |
| `image_path` | VARCHAR(255) | N | — | `public` disk, same convention as `news_items.image_path` |
| `alt_text` | VARCHAR(255) | Y | NULL | |
| `display_order` | SMALLINT UNSIGNED | N | 0 | |
| `created_at`, `updated_at` | TIMESTAMP | Y | NULL | |

Presentational child of `products` — `CASCADE` on delete. The first image by
`display_order` is the primary image; no separate `is_primary` flag. Index
`(product_id, display_order)`.

## 2. Inventory

### 2.1 `inventories`

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id` | BIGINT UNSIGNED AI | N | — | PK |
| `product_id` | BIGINT UNSIGNED | N | — | FK → `products`, **CASCADE**, **UNIQUE** (one row per product) |
| `quantity` | INT | N | 0 | `CHECK (quantity >= 0)` |
| `created_at`, `updated_at` | TIMESTAMP | Y | NULL | |

A single on-hand count per product — **not** a transaction ledger. "Must
never become negative" is enforced directly by the CHECK constraint at the
database layer (an `UPDATE` that would make it negative is rejected, not
just application logic). There is no reservation concept yet: nothing holds
stock for an unpaid order, because checkout does not exist yet. See §7.2 for
what a future ledger-based design would add and why it was deferred.

## 3. Carts and orders

### 3.1 `carts`

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id` | BIGINT UNSIGNED AI | N | — | PK |
| `user_id` | BIGINT UNSIGNED | Y | NULL | FK → `users`, **RESTRICT**, **UNIQUE** (one cart per member) |
| `guest_token` | CHAR(40) `ascii_bin` | Y | NULL | **UNIQUE** |
| `created_at`, `updated_at` | TIMESTAMP | Y | NULL | |

`CHECK (user_id IS NOT NULL OR guest_token IS NOT NULL)` — a cart belongs to
exactly one registered member or one guest, never neither. `RESTRICT` on
`user_id`: users are never hard-deleted in this app, so this never actually
fires, but it keeps the "user or guest" CHECK meaningful rather than relying
on a `SET NULL` side effect. A cart holds no prices or coupon — those are
re-derived at checkout, which this step does not build.

### 3.2 `cart_items`

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id` | BIGINT UNSIGNED AI | N | — | PK |
| `cart_id` | BIGINT UNSIGNED | N | — | FK → `carts`, **CASCADE** |
| `product_id` | BIGINT UNSIGNED | N | — | FK → `products`, **RESTRICT** |
| `quantity` | SMALLINT UNSIGNED | N | — | `CHECK (quantity >= 1)` |
| `created_at`, `updated_at` | TIMESTAMP | Y | NULL | |

`UNIQUE(cart_id, product_id)` — one line per product per cart; adding the
same product again is an application-level quantity update (a later step),
not a second row. `RESTRICT` on `product_id`: a product cannot be deleted
while it still sits in someone's cart.

### 3.3 `orders`

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id` | BIGINT UNSIGNED AI | N | — | PK |
| `public_id` | CHAR(26) `ascii_bin` | N | — | **UNIQUE** ULID, the same link-safe-identifier convention as `payments.public_id` |
| `order_number` | VARCHAR(20) `ascii_bin` | N | — | **UNIQUE** human-readable identifier shown to customers. Generation logic (e.g. retry-on-collision) is a later, checkout-stage concern; this migration only guarantees uniqueness. |
| `user_id` | BIGINT UNSIGNED | Y | NULL | FK → `users`, **RESTRICT**. NULL for a guest order. |
| `customer_name` | VARCHAR(150) | N | — | Snapshot, captured regardless of whether an account exists |
| `customer_email` | VARCHAR(255) | N | — | Snapshot |
| `customer_phone` | VARCHAR(40) | Y | NULL | Snapshot |
| `status` | VARCHAR(30) | N | `'pending_payment'` | `CHECK IN ('pending_payment','paid','processing','packed','shipped','delivered','cancelled','refunded')` |
| `currency` | CHAR(3) | N | — | No default — one currency per order |
| `total_amount` | DECIMAL(12,2) | N | — | `CHECK (total_amount >= 0)` |
| `created_at`, `updated_at` | TIMESTAMP | Y | NULL | `created_at` = placed time |

No `subtotal_amount`/`discount_amount`/`shipping_amount`/`coupon_id` columns
— with no coupons or shipping methods built yet (§7), subtotal and total
would always be identical, so only `total_amount` exists. Adding those later
is an additive migration, not a breaking one. No soft delete: orders are
never deleted or hidden.

Indexes: `UNIQUE(public_id)`, `UNIQUE(order_number)`, `(user_id, created_at)`,
`(status, created_at)`, `(customer_email)`.

### 3.4 `order_items`

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id` | BIGINT UNSIGNED AI | N | — | PK |
| `order_id` | BIGINT UNSIGNED | N | — | FK → `orders`, **RESTRICT** |
| `product_id` | BIGINT UNSIGNED | Y | NULL | FK → `products`, **SET NULL** |
| `product_name` | VARCHAR(255) | N | — | **Snapshot** |
| `sku` | VARCHAR(64) | N | — | **Snapshot** |
| `unit_price` | DECIMAL(12,2) | N | — | **Snapshot**. `CHECK (unit_price >= 0)` |
| `quantity` | SMALLINT UNSIGNED | N | — | `CHECK (quantity >= 1)` |
| `line_total` | DECIMAL(12,2) | N | — | `CHECK (line_total = unit_price * quantity)` |
| `created_at`, `updated_at` | TIMESTAMP | Y | NULL | |

`product_name`, `sku` and `unit_price` are **snapshots** taken at order
placement — never read live from `products`. Later edits to the product (or
its outright deletion) never change an existing order: `product_id` is
nullable with `SET NULL` on delete specifically so the row survives.
`UNIQUE(order_id, product_id)` (NULLs excepted, as MySQL treats each NULL as
distinct) prevents two active lines for the same product on one order.

## 4. Access control

`products.access_type` is the only visibility/purchase rule for this step:

- `PUBLIC` — viewable and purchasable by everyone, including guests.
- `MEMBER_ONLY` — viewable and purchasable only by an authenticated member
  whose account `status` is `active`.

`Product::isAccessibleTo(?User $user)` implements this rule. There is no
tier logic (e.g. a "members" vs. a paid-tier distinction) — any active member
account qualifies for every `MEMBER_ONLY` product.

## 5. Payments

There is no separate e-shop payments table. `payments` (`06_PAYMENT_SCHEMA.md`
§3) is a single, provider-independent ledger shared by membership renewals
and orders: `payments.order_id` is now a real foreign key to `orders.id`
(`RESTRICT` on delete), completing the column that table's own migration
deliberately left unlinked until `orders` existed. The existing
`payments_exactly_one_purpose` CHECK (`(membership_term_id IS NULL) <>
(order_id IS NULL)`) already guarantees a payment targets exactly one of the
two domains, and the existing status vocabulary (`pending`, `processing`,
`paid`, `failed`, `cancelled`, `refunded`, `partially_refunded`) already
works for either.

`orders.status` (order lifecycle) and `payments.status` (payment lifecycle)
are deliberately separate vocabularies — an order can be `paid` while a
later refund moves its payment to `partially_refunded` without the order
itself needing a matching status.

## 6. Historical-data summary

| Fact | Where it is frozen |
|---|---|
| Price charged, product name, SKU | `order_items` |
| Customer contact | `orders.customer_*` |
| Currency | `orders.currency` |

## 7. Deferred (not built in Step 1)

The following was proposed in an earlier draft of this document and remains
a reasonable direction for a later step, but none of it exists yet. Nothing
in Step 1 depends on it, and adding any of it later is additive (new tables
and columns), not a rework of what's already built.

### 7.1 `product_variants` — SKU-level pricing and stock

A separate table so a product could have priced/stocked variants (e.g.
size/colour), with the product itself holding no price. Step 1 puts price
and SKU directly on `products` instead, matching a "simple products only"
scope. Revisit if the shop ever needs more than one priced option per
product.

### 7.2 `inventory_transactions` — the append-only stock ledger

A signed, append-only ledger (`purchase`/`sale`/`return`/`adjustment`/
`damage`/`loss`/`reservation`/`release` rows) that would make
`quantity_on_hand`/`quantity_reserved` on `product_variants` a maintained
projection rather than a bare counter, with a full audit trail of *why*
stock changed. Step 1's `inventories.quantity` is that bare counter (CHECK
`>= 0` only) — correct for "never negative" but with no history and no
reservation concept.

### 7.3 Reservations

The mechanism (via `inventory_transactions`) that would hold stock for a
`pending_payment` order so two concurrent checkouts can't both buy the last
unit, with an expiry job releasing stock from abandoned orders. Requires
7.2. Not needed until checkout exists.

### 7.4 `coupons`

Discount codes (percentage or fixed, with usage limits and a validity
window). Requires `orders.discount_amount`/`coupon_id` columns, deferred
per §3.3.

### 7.5 `shipping_methods`

Named, priced shipping options. Requires `orders.shipping_amount`/
`shipping_method_id` columns, deferred per §3.3.

### 7.6 `order_addresses`

Snapshotted shipping/billing addresses per order, separate from `orders`
itself so a billing address doesn't repeat shipping columns. Not needed
until there's a checkout flow that collects an address.

### 7.7 `order_status_history`

An append-only log of order status transitions (who changed it, when, and
why), the same pattern as `membership_status_history`. Step 1's `orders`
table has no milestone timestamp columns (`paid_at`, `shipped_at`, …)
precisely because this table — not yet built — would be the single source
of "when" once it exists.
