<?php

namespace App\Payments;

use App\Models\Payment;

/**
 * E-Shop Step 7 — the one seam a real gateway integration (Stripe, PayHere,
 * a bank API, …) plugs into later: bind a different implementation of this
 * contract in `AppServiceProvider` and nothing in `PaymentController` or
 * the checkout/order flow needs to change. Every method is idempotent —
 * calling `confirm()` twice (a duplicated webhook, a double click) must
 * never create a second payment, a second receipt or deduct stock again.
 */
interface PaymentGatewayContract
{
    /**
     * A short, human-readable identifier for whichever implementation is
     * currently bound (shown on the payment page, never a secret).
     */
    public function name(): string;

    /**
     * Record that this payment succeeded. Transitions the payment to
     * `paid` and — the only payment outcome allowed to do so — moves the
     * order out of `pending_payment`. A no-op if already `paid`.
     */
    public function confirm(Payment $payment): Payment;

    /**
     * Record that this payment attempt failed. Leaves the order at
     * `pending_payment` so the customer can retry. A no-op once the
     * payment has already reached a final state.
     */
    public function fail(Payment $payment, ?string $reason = null): Payment;

    /**
     * Record that the customer cancelled this payment attempt (e.g. closed
     * the gateway's page). Leaves the order at `pending_payment`. A no-op
     * once the payment has already reached a final state.
     */
    public function cancel(Payment $payment): Payment;
}
