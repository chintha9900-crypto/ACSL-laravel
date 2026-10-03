<?php

namespace App\Payments;

use App\Models\Payment;

/**
 * E-Shop Step 10.1 — bound instead of `ManualPaymentGateway` in every
 * environment except `local`/`testing` (see `AppServiceProvider::register()`).
 * `ManualPaymentGateway` is a development-only simulator with no real
 * payment check behind it at all, so outside local/testing there is no safe
 * implementation to fall back to: every method here fails closed with a 404
 * rather than performing (or appearing to perform) a payment transition.
 * Replacing both this and `ManualPaymentGateway` with a real gateway
 * implementation, bound unconditionally, is the intended long-term fix.
 */
class UnavailablePaymentGateway implements PaymentGatewayContract
{
    public function name(): string
    {
        return 'Payment processing is currently unavailable';
    }

    public function confirm(Payment $payment): Payment
    {
        abort(404);
    }

    public function fail(Payment $payment, ?string $reason = null): Payment
    {
        abort(404);
    }

    public function cancel(Payment $payment): Payment
    {
        abort(404);
    }
}
