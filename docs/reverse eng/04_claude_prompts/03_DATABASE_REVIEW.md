# Claude Code — Database Review

Do not create migrations yet.

Produce the proposed final MySQL schema from the approved reverse-engineering reports.

For every table provide:
- purpose
- columns/types
- PK
- FK
- unique constraints
- indexes
- nullable/default rules
- status
- timestamps
- important business constraints

Pay special attention to:
membership
payments
products
variants
inventory
carts
orders
order snapshots
shipping
coupons
refunds
roles/permissions
audit logs

Avoid:
- excessive JSON
- EAV
- unnecessary polymorphic FKs
- weak payment relationships
- stock-only inventory

For normal payments use explicit nullable membership_id/order_id with an XOR business rule.

STOP for human approval.
