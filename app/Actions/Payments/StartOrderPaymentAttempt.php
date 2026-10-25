<?php

namespace App\Actions\Payments;

use App\Actions\Orders\RecordOrderAudit;
use App\Exceptions\PaymentCannotBeSubmittedException;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentBankAccount;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Starts a new payment attempt for an order still payable — a retry after a
 * failed/cancelled attempt, or a switch to another method. Never a
 * duplicate: under the order's lock (lock order: order → payments) it reuses
 * an open attempt for the same method, refuses while evidence is under
 * review or a payment is settled, and closes an open attempt of another
 * method before creating one.
 *
 * The payment deadline is always measured from the order's creation for the
 * chosen method, so retrying never extends it; a switch is refused once the
 * current deadline has passed.
 *
 * Each attempt has its own idempotency key (the order number, then "-2",
 * "-3", …), which is also the order id sent to PayHere.
 */
class StartOrderPaymentAttempt
{
    public function __construct(private readonly RecordOrderAudit $audit) {}

    /**
     * @throws PaymentCannotBeSubmittedException
     */
    public function handle(Order $order, string $method, ?User $actor): Payment
    {
        if (! array_key_exists($method, Order::METHOD_GATEWAYS)) {
            throw new PaymentCannotBeSubmittedException('Please choose a payment method.');
        }

        return DB::transaction(function () use ($order, $method, $actor): Payment {
            $locked = Order::query()->lockForUpdate()->findOrFail($order->id);

            if (! $locked->isPayable()) {
                throw new PaymentCannotBeSubmittedException('This order is no longer awaiting payment.');
            }

            if ($locked->is_test !== ($method === Order::METHOD_DUMMY)) {
                throw new PaymentCannotBeSubmittedException('This payment method is not available for this order.');
            }

            $attempts = $locked->payments()->orderBy('id')->lockForUpdate()->get();
            $gateway = Order::METHOD_GATEWAYS[$method];

            if ($attempts->contains(fn (Payment $payment): bool => $payment->isSettled() || $payment->status === Payment::STATUS_PROCESSING)) {
                throw new PaymentCannotBeSubmittedException('A payment for this order is already paid or under review.');
            }

            $open = $attempts->firstWhere('status', Payment::STATUS_PENDING);

            if ($open !== null && $open->gateway === $gateway) {
                return $open;
            }

            $deadline = $locked->created_at !== null ? $locked->paymentDeadlineFor($method) : $locked->expires_at;

            if ($deadline !== null && $deadline->isPast()) {
                throw new PaymentCannotBeSubmittedException('The payment window for this method has closed for this order.');
            }

            $bankAccountId = null;

            if ($method === Order::METHOD_BANK_TRANSFER) {
                $bankAccountId = PaymentBankAccount::query()->activeForCurrency($locked->currency)->value('id');

                if ($bankAccountId === null) {
                    throw new PaymentCannotBeSubmittedException('Bank transfer is not available at the moment.');
                }
            }

            if ($open !== null) {
                $open->forceFill(['status' => Payment::STATUS_CANCELLED])->save();
                $this->audit->handle($open, 'payment.cancelled', $actor, ['reason' => 'Customer switched payment method']);
            }

            $payment = Payment::forceCreate([
                'user_id' => $locked->user_id,
                'order_id' => $locked->id,
                'gateway' => $gateway,
                'bank_account_id' => $bankAccountId,
                'idempotency_key' => $locked->order_number.'-'.($attempts->count() + 1),
                'amount' => $locked->total_amount,
                'currency' => $locked->currency,
                'status' => Payment::STATUS_PENDING,
            ]);

            $locked->update(['payment_method' => $method, 'expires_at' => $deadline]);
            $this->audit->handle($payment, 'payment.attempt_started', $actor, ['gateway' => $gateway, 'expires_at' => $deadline?->toIso8601String()]);

            return $payment;
        }, 3);
    }
}
