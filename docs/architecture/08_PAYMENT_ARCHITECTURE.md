# 08 — Payment Architecture

A single, provider-independent payment ledger shared by Membership **renewals** (manual bank transfer today) and future Commerce. **The initial 6-month membership is free for every approved new member (OD-10): it has no `Payment` row, not even a zero-amount one.** Payments in the Membership domain exist only for renewal terms. Field list and entity names in this document are largely **CONFIRMED REQUIREMENT** — specified directly by ACI in the Phase 2 instructions — with the surrounding design (gateway abstraction, idempotency mechanics, status reconciliation) as ARCHITECTURAL DECISION.

## 1. Entities

### `Payment` (CONFIRMED REQUIREMENT — fields as specified)

| Field | Notes |
|---|---|
| `user_id` | nullable — the paying party, where known (may be null for a guest checkout, if ACI approves that for Commerce) |
| `order_id` | nullable — set only for a Commerce payment |
| `membership_term_id` | nullable — set only for a membership **renewal** term payment (the free introductory term is never paid for) |
| `gateway` | which payment mechanism handled this (`manual_bank_transfer` today; `stripe`, etc. in future — see §2) |
| `transaction_reference` | the gateway's or the applicant's own reference for this payment (for manual transfer: the bank reference the applicant supplies, per `WORKFLOWS.md` §0.9's "payment reference") |
| `idempotency_key` | a value unique per attempted payment operation, used to make retried requests (e.g. a webhook redelivery, or a double-submitted confirmation) safe — see §4 |
| `amount` | **DECIMAL**, never float (CONFIRMED REQUIREMENT, Phase 2 architecture principles) |
| `currency` | ISO 4217 code — see `16_OPEN_DECISIONS.md` for the currency question (the reference app priced in LKR; the Phase 2 instructions use "£" as an example figure — these may not be the same currency, flagged as an open decision, not assumed) |
| `status` | the **gateway/transaction-facing** lifecycle (see §3 — deliberately distinct from Membership's own business-facing `payment_status`) |
| `payment_url` | where applicable, a link the payer was sent to / shown (a gateway checkout URL in future; not used by the manual-transfer gateway) |
| `paid_at` | timestamp, set only once confirmed |
| `failed_at` | timestamp, set only if the payment fails/is rejected |
| `metadata` | JSON — gateway-specific extra data (e.g. the uploaded evidence document's reference for manual transfer, or a future gateway's raw response payload); deliberately the **one** place this domain uses JSON, because its contents are genuinely gateway-specific and not meaningfully normalizable, consistent with "do not use JSON columns when a normal relational structure is more appropriate" — everything that *is* normalizable (amount, currency, status, references) is its own column, not buried in this JSON |
| timestamps | `created_at`/`updated_at` |

**Business rule (CONFIRMED REQUIREMENT)**: a `Payment` relates to exactly one business purpose — `membership_term_id` **XOR** `order_id`. Enforced at the application layer (a model-level validation/guard in the `Payment` model's creation path, e.g. a `boot()` "saving" check, or — preferably — enforced structurally by never constructing a `Payment` anywhere except through two narrow factory-style Actions, `Actions/Payments/CreateMembershipPayment` and `Actions/Payments/CreateOrderPayment`, each of which sets exactly one of the two FKs and leaves the other null). MySQL cannot express "exactly one of two nullable columns is set" as a single `CHECK` constraint as cleanly as Postgres can, so this is treated as an **application-enforced invariant with defence in depth** (the guard above) rather than solely a database constraint — noted here explicitly so it is not silently assumed to be DB-enforced.

### `PaymentWebhookEvent` (CONFIRMED REQUIREMENT — concept; ARCHITECTURAL DECISION — mechanics)

Records every inbound gateway webhook call, keyed uniquely per `(gateway, event_id)`, with a processing-state column (`received`/`processed`/`failed`/`ignored`) and the raw payload (JSON, for audit/replay). See §4 for idempotency mechanics.

### `PaymentRefund` (CONFIRMED REQUIREMENT — concept)

One row per refund attempt against a `Payment`: amount (DECIMAL, may be partial), reason, status, gateway reference, timestamps. A `Payment` may have zero, one, or several `PaymentRefund`s (supports partial refunds — relevant to Commerce's `partially_refunded` order/payment states, §06).

## 2. Provider-independent gateway abstraction

**ARCHITECTURAL DECISION**: a `PaymentGatewayContract` interface with one concrete implementation today (`ManualBankTransferGateway`) and room for future implementations (`StripeGateway`, etc.) bound via `config/payments.php` and Laravel's service container — never instantiated directly by calling code.

```php
interface PaymentGatewayContract {
    public function initiate(PaymentIntent $intent): Payment;      // create a Payment row, return any redirect/instructions
    public function verify(Payment $payment): PaymentVerificationResult; // for manual transfer: N/A, admin-driven instead
    public function handleWebhook(Request $request): void;          // no-op for manual transfer
    public function refund(Payment $payment, ?int $amountMinor = null): PaymentRefund;
}
```

- **What is being proposed**: every payment-touching Action (Membership's payment-confirmation flow, Commerce's future checkout) talks only to this interface, never to a gateway SDK or manual-transfer-specific logic directly.
- **Why appropriate for ACI**: `CLAUDE.md` and the reverse-engineering findings (`WORKFLOWS.md` §0.8–§0.9, `SUPABASE.md`) are explicit that ACI wants a **provider-independent payment architecture**, and the current confirmed workflow (bank transfer + evidence + admin confirm) is itself just one implementation of "a payment was made and verified" — designing to the interface now means adopting a real gateway later is additive (a new class + config change), not a rewrite of Membership or Commerce.
- **Laravel mechanism**: interface bound in a service provider (`config('payments.default_gateway')` selects the bound implementation), consistent with how Laravel itself binds mailers/queue drivers/filesystem disks.
- **Security considerations**: the manual gateway's "verification" is inherently human (an admin reviewing uploaded evidence) — the interface's `verify()` method is a no-op for it, and the actual state change happens through `Actions/Payments/ConfirmPayment` / `RejectPayment`, called only from an admin-authorized action, never automatically.
- **Data integrity considerations**: `initiate()` always creates the `Payment` row inside the same transaction as whatever business action triggered it (e.g. a member starting a membership renewal), so a `Payment` row is never orphaned from its business purpose.
- **Alternatives considered**: hard-coding "bank transfer" logic directly into the Membership domain (no interface) — rejected: this is exactly the kind of tight coupling that would make adding a real gateway later a breaking change to Membership's code, contradicting the explicit provider-independence requirement; a package like Laravel Cashier — rejected for now because Cashier is Stripe/Paddle-subscription-shaped (recurring billing, not needed here) and would be premature before ACI even confirms a gateway choice — revisit if/when a specific gateway is approved (`16_OPEN_DECISIONS.md`).

## 3. Two distinct "payment status" concepts — do not conflate

**ARCHITECTURAL DECISION** (clarifying a naming collision in the raw requirements, since the Phase 2 instructions list two different five/seven-value status sets under the same name "payment status"):

1. **`Payment.status`** (this domain) — the **gateway/transaction-facing** lifecycle of one payment attempt: `pending`, `processing`, `paid`, `failed`, `cancelled`, `refunded`, `partially_refunded` (the set given for Commerce in the Phase 2 instructions — genuinely gateway-shaped language, appropriate once a real gateway exists).
2. **`MembershipTerm`'s own `payment_status`** (Membership domain, `04_MEMBERSHIP_ARCHITECTURE.md` §6) — the **business-workflow-facing** state confirmed in `WORKFLOWS.md` §0.9: `payment_not_required`, `payment_pending`, `payment_confirmation_submitted`, `payment_confirmed`, `payment_rejected`.

These are kept as **two separate enums on two separate models**, synchronized by the application layer (an event listener on `PaymentConfirmed`/`PaymentFailed` updates the owning `MembershipTerm`'s business status), rather than one shared enum trying to serve both purposes. A `payment_not_required` introductory (free) term never has a `Payment` row at all, so it structurally cannot be expressed in `Payment.status`'s vocabulary — which is exactly why the two must stay separate: forcing one enum to cover both would either invent a meaningless `Payment.status` for free memberships or lose the business-specific `payment_confirmation_submitted`/`payment_rejected` semantics that only make sense for the manual-evidence workflow. This same reasoning extends to Commerce (`09_ECOMMERCE_ARCHITECTURE.md`), which has its own order-lifecycle status separate from `Payment.status` too.

## 4. Webhook idempotency

**CONFIRMED REQUIREMENT**: webhook events must be uniquely identifiable per gateway/event ID; processing must be idempotent; never trust a browser redirect as proof of payment.

- **Laravel mechanism**: `PaymentWebhookEvent` has a `UNIQUE(gateway, event_id)` constraint. The webhook Controller's only job is to verify the request's authenticity (signature check, gateway-specific), `firstOrCreate` the `PaymentWebhookEvent` row, and — only if it was just created (not a duplicate delivery) — dispatch a **queued Job** to actually process the event (update `Payment.status`, fire `PaymentConfirmed`/`PaymentFailed`, etc.). If the row already existed, the request is acknowledged (200 OK, so the gateway stops retrying) and nothing else happens — this is the idempotency guarantee.
- **Never trust a redirect**: any "thank you, payment received" page the payer is redirected to after a gateway checkout is purely informational; it never itself flips `Payment.status` — only a verified webhook (or, for the manual gateway, an explicit admin confirmation action) does that. This directly corrects the reference app's shop-order-confirmation pattern, which had no payment step at all to get this wrong, but is exactly the trap a naive future gateway integration could fall into if not designed against from the start.
- **Security considerations**: webhook Controllers verify gateway signatures before any processing (exact mechanism is gateway-specific, deferred until a real gateway is chosen — `16_OPEN_DECISIONS.md`); the webhook route is excluded from CSRF protection (standard Laravel practice for webhook endpoints) but is rate-limited and logs every inbound call (processed or not) for audit.
- **Data integrity considerations**: processing a webhook (updating `Payment.status`, creating a `PaymentRefund`, etc.) happens inside a transaction alongside marking the `PaymentWebhookEvent` as `processed`, so a crash mid-processing is retried safely rather than silently marked done.

## 5. Manual bank-transfer gateway (today's implementation)

Implements `PaymentGatewayContract` for the current confirmed workflow (`WORKFLOWS.md` §0.8–§0.9):

- `initiate()`: creates a `Payment` row (`gateway = 'manual_bank_transfer'`, `status = pending`), no external call.
- The member later submits a reference + evidence document (Files/Documents domain) via `Actions/Payments/SubmitPaymentEvidence`, moving `Payment.status` to `processing` and the owning renewal `MembershipTerm`'s business status to `payment_confirmation_submitted`.
- An admin calls `Actions/Payments/ConfirmPayment` or `RejectPayment` (never an automated verification) — `ConfirmPayment` sets `Payment.status = paid`, `paid_at = now()`, fires `PaymentConfirmed`; `RejectPayment` sets `Payment.status = failed`, `failed_at = now()`, and — per the CONFIRMED rejection loop — the owning renewal term's business status returns to `payment_pending` (the renewal is not restarted), allowing `SubmitPaymentEvidence` to be called again against the **same** `Payment` row (a payment attempt can be resubmitted; a brand-new `Payment` row is not created for a resubmission, matching "do not require the member to restart").
- **Bank/payment details source**: a dedicated `PaymentInstructions`/`BankAccountDetails` configuration entity (not folded into `SiteSetting`, not hard-coded into email templates — CONFIRMED REQUIREMENT, `WORKFLOWS.md` §0.9), admin-managed: account name, bank name, account number, sort code/IBAN/SWIFT where applicable, instructions text. Read by the M5 renewal-payment-instructions and M10 renewal-reminder notifications (`06_NOTIFICATION_ARCHITECTURE.md`) at send time, so a later change to bank details doesn't retroactively alter an already-sent email but does apply to the next one.

## 6. Summary diagram

```
                         ┌────────────────────────────┐
Member starts renewal ──▶│  PaymentGatewayContract     │◀──── future Commerce checkout
(renewal term created)   │  ::initiate()                │
                         └──────────────┬──────────────┘
                                        ▼
                                  Payment (pending)
                          gateway=manual_bank_transfer
                          membership_term_id=X, order_id=null
                                        │
                     member submits reference+evidence
                                        ▼
                                  Payment (processing)
                          MembershipTerm.payment_status =
                            payment_confirmation_submitted
                                        │
                          admin reviews evidence
                       ┌────────────────┴────────────────┐
                       ▼                                  ▼
                RejectPayment                        ConfirmPayment
        Payment.status=failed                  Payment.status=paid, paid_at=now()
        MembershipTerm.payment_status          fires PaymentConfirmed
          = payment_pending (resubmit)                     │
                                                             ▼
                                        renewal MembershipTerm becomes active
                                        (membership number unchanged)

(The free introductory term has no payment flow at all.)
```

*Per the Phase 2 restriction: conceptual design only. No `Payment`/`PaymentWebhookEvent`/`PaymentRefund` tables, models, or gateway classes exist yet.*
