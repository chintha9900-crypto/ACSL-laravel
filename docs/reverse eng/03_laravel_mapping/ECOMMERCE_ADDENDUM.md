# New E-Shop Requirement

The e-shop is NEW functionality. It must not be falsely attributed to the reference application.

## Entities
- product_categories
- products
- product_category_product
- product_images
- product_variants
- inventory_transactions
- carts
- cart_items
- orders
- order_items
- order_addresses
- shipping_methods
- order_shipments
- coupons
- coupon_usages
- payments
- payment_webhooks
- payment_refunds

## Rules
- Recalculate all prices, stock, discounts and shipping server-side.
- Inventory is ledger based.
- Use transactions/locking where required.
- Order items snapshot commercial data.
- Order addresses are snapshots.
- Payment success requires server-side verification.
- Webhooks are idempotent.
- Never store raw card/CVV data.
- Protect member orders with ownership authorization.
