<?php

namespace App\Payments;

use App\Actions\Payments\CancelOrderPayment;
use App\Actions\Payments\ConfirmOrderPayment;
use App\Actions\Payments\FailOrderPayment;
use App\Models\Payment;

/**
 * E-Shop Step 7's placeholder provider — development/testing only. There is
 * no real gateway to redirect to, so `PaymentController`'s confirm/fail/
 * cancel actions stand in for what would otherwise be a signed webhook
 * callback from a real provider. Swapping in a real gateway later means
 * writing a new class implementing `PaymentGatewayContract` and changing
 * the binding in `AppServiceProvider` — `PaymentController` and the
 * checkout/order flow do not change.
 */
class ManualPaymentGateway implements PaymentGatewayContract
{
    public function __construct(
        private readonly ConfirmOrderPayment $confirmOrderPayment,
        private readonly FailOrderPayment $failOrderPayment,
        private readonly CancelOrderPayment $cancelOrderPayment,
    ) {}

    public function name(): string
    {
        return 'Manual (development placeholder)';
    }

    public function confirm(Payment $payment): Payment
    {
        return $this->confirmOrderPayment->handle($payment);
    }

    public function fail(Payment $payment, ?string $reason = null): Payment
    {
        return $this->failOrderPayment->handle($payment, $reason);
    }

    public function cancel(Payment $payment): Payment
    {
        return $this->cancelOrderPayment->handle($payment);
    }
}
