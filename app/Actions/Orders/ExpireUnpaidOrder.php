<?php

namespace App\Actions\Orders;

use App\Models\Order;
use App\Models\Payment;
use App\Notifications\Orders\OrderExpired;
use Illuminate\Support\Facades\DB;

/**
 * Expires one unpaid order whose payment deadline has passed: the order moves
 * to `expired`, its open attempts are cancelled, its stock is returned once,
 * and the customer is told after commit.
 *
 * Idempotent and race-safe: everything is re-checked under the order lock
 * (lock order: order → payments → inventory), so a repeated run, a
 * concurrent cancellation or a payment confirmed a moment earlier all leave
 * it untouched. An order whose bank-transfer evidence is under review is not
 * expired — the customer may already have paid; an admin decides.
 */
class ExpireUnpaidOrder
{
    public function __construct(
        private readonly ReleaseOrderStock $releaseOrderStock,
        private readonly RecordOrderAudit $audit,
        private readonly NotifyCustomer $notifyCustomer,
    ) {}

    /**
     * @return bool Whether this call expired the order.
     */
    public function handle(Order $order): bool
    {
        return DB::transaction(function () use ($order): bool {
            $locked = Order::query()->lockForUpdate()->find($order->id);

            if ($locked === null
                || $locked->status !== Order::STATUS_PENDING_PAYMENT
                || $locked->expires_at === null
                || $locked->expires_at->isFuture()) {
                return false;
            }

            $attempts = $locked->payments()->orderBy('id')->lockForUpdate()->get();

            if ($attempts->contains(fn (Payment $payment): bool => $payment->status === Payment::STATUS_PROCESSING || $payment->isSettled())) {
                return false;
            }

            $locked->update(['status' => Order::STATUS_EXPIRED]);
            $this->audit->handle($locked, 'order.expired', null, ['expires_at' => $locked->expires_at->toIso8601String()], RecordOrderAudit::ACTOR_SYSTEM);

            $attempts->where('status', Payment::STATUS_PENDING)->each(function (Payment $payment): void {
                $payment->forceFill(['status' => Payment::STATUS_CANCELLED])->save();
                $this->audit->handle($payment, 'payment.cancelled', null, ['reason' => 'Order expired'], RecordOrderAudit::ACTOR_SYSTEM);
            });

            $this->releaseOrderStock->handle($locked, null, 'Unpaid order expired');

            DB::afterCommit(fn () => $this->notifyCustomer->handle($locked, new OrderExpired($locked)));

            return true;
        }, 3);
    }
}
