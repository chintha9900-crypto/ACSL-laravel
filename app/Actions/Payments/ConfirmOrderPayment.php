<?php

namespace App\Actions\Payments;

use App\Actions\Orders\NotifyCustomer;
use App\Models\Order;
use App\Models\Payment;
use App\Notifications\Orders\OrderReceipt;
use Illuminate\Support\Facades\DB;

/**
 * The single place an order payment is ever marked `paid` — the stand-in
 * today's `ManualPaymentGateway` calls, and exactly what a real gateway's
 * webhook controller would call once one exists, so that controller never
 * needs to know how to update a `Payment`/`Order` itself.
 *
 * Idempotent: locks the payment row first, and if it is already `paid`
 * returns immediately without touching anything else — a duplicated
 * webhook delivery or a double click can never pay twice, create a second
 * order-status transition, deduct stock again (this never references
 * `Inventory` at all; stock was already deducted once, at order placement
 * in E-Shop Step 6), or send a second receipt email: the email is sent only
 * from inside the branch that actually performs the pending→paid write, so
 * a replayed confirmation — which hits the early return above instead —
 * can never trigger it again.
 *
 * The email is sent only after the transaction commits, never from inside
 * it: a transient mail failure must not roll back a payment that genuinely
 * succeeded.
 */
class ConfirmOrderPayment
{
    public function __construct(private readonly NotifyCustomer $notifyCustomer) {}

    public function handle(Payment $payment): Payment
    {
        [$confirmed, $justConfirmed] = DB::transaction(function () use ($payment): array {
            $locked = Payment::query()->lockForUpdate()->findOrFail($payment->id);

            if ($locked->status === Payment::STATUS_PAID) {
                return [$locked, false];
            }

            $locked->forceFill([
                'status' => Payment::STATUS_PAID,
                'paid_at' => now(),
            ])->save();

            if ($locked->order_id !== null) {
                $order = Order::query()->lockForUpdate()->find($locked->order_id);

                if ($order !== null && $order->status === Order::STATUS_PENDING_PAYMENT) {
                    $order->update(['status' => Order::STATUS_PAID]);
                }
            }

            return [$locked, true];
        });

        if ($justConfirmed) {
            $this->sendReceipt($confirmed);
        }

        return $confirmed;
    }

    private function sendReceipt(Payment $payment): void
    {
        if ($payment->order_id === null) {
            return;
        }

        $order = Order::query()->with('items')->find($payment->order_id);

        if ($order === null) {
            return;
        }

        $this->notifyCustomer->handle($order, new OrderReceipt($order));
    }
}
