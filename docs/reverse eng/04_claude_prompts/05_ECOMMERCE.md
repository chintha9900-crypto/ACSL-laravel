# Claude Code — E-Shop Implementation

The ACI e-shop is an approved NEW feature.

Read:
- docs/03_laravel_mapping/ECOMMERCE_ADDENDUM.md

Implement in controlled slices:
1. categories/products
2. images
3. variants/SKUs
4. inventory ledger
5. carts
6. coupons
7. shipping
8. orders
9. checkout
10. fake payment gateway
11. payment webhooks/idempotency
12. refunds
13. admin commerce
14. member order history

Do not connect live payment credentials until fake payment and tests pass.

Never trust browser prices, stock, discount or payment status.
