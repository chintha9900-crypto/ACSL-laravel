<?php

namespace App\Actions\Orders;

use App\Actions\Payments\FlagPaymentForReview;
use App\Exceptions\InvalidOrderStatusTransitionException;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentReview;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * The only place an admin's fulfilment-status change is written. It never
 * marks an order paid (`pending_payment` → `paid` is not a transition here —
 * only verified payment confirmation does that), never sets `expired` (only
 * the scheduled expiry does), and never edits the `order_items` snapshots.
 *
 * Cancelling an unpaid order closes its open payment attempts and returns its
 * reserved stock exactly once (ReleaseOrderStock). A bank transfer whose
 * evidence was under review may be real money, so it also goes to the manual
 * review queue. Cancelling or refunding a *paid* order changes the status
 * only: stock is not restored automatically.
 *
 * Lock order: order → payments → inventory.
 */
class UpdateOrderStatus
{
    public function __construct(
        private readonly ReleaseOrderStock $releaseOrderStock,
        private readonly RecordOrderAudit $audit,
        private readonly FlagPaymentForReview $flag,
    ) {}

    /**
     * @throws InvalidOrderStatusTransitionException
     */
    public function handle(Order $order, string $status, ?User $admin = null): Order
    {
        return DB::transaction(function () use ($order, $status, $admin): Order {
            $locked = Order::query()->lockForUpdate()->findOrFail($order->id);

            if (! $locked->canTransitionTo($status)) {
                throw new InvalidOrderStatusTransitionException(
                    "Cannot move an order from \"{$locked->status}\" to \"{$status}\"."
                );
            }

            // "Refunded" must reflect money actually returned: only once every
            // payment received for the order has a recorded full refund
            // (RecordManualRefund, which also moves the order automatically).
            if ($status === Order::STATUS_REFUNDED && ! $locked->isFullyRefunded()) {
                throw new InvalidOrderStatusTransitionException(
                    'Record the refund for every payment on this order before marking it refunded.'
                );
            }

            $from = $locked->status;
            $locked->update(['status' => $status]);

            $actorType = $admin === null ? RecordOrderAudit::ACTOR_SYSTEM : null;
            $this->audit->handle($locked, 'order.status_changed', $admin, ['from' => $from, 'to' => $status], $actorType);

            if ($from === Order::STATUS_PENDING_PAYMENT && $status === Order::STATUS_CANCELLED) {
                $locked->payments()
                    ->whereIn('status', [Payment::STATUS_PENDING, Payment::STATUS_PROCESSING])
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get()
                    ->each(function (Payment $payment) use ($locked, $admin, $actorType): void {
                        $wasUnderReview = $payment->status === Payment::STATUS_PROCESSING;
                        $payment->forceFill(['status' => Payment::STATUS_CANCELLED])->save();
                        $this->audit->handle($payment, 'payment.cancelled', $admin, ['reason' => 'Order cancelled'], $actorType);

                        if ($wasUnderReview) {
                            $this->flag->handle(
                                $locked,
                                $payment,
                                PaymentReview::TYPE_TRANSFER_CONFLICT,
                                "Order {$locked->order_number} was cancelled while bank transfer evidence (reference {$payment->transaction_reference}) was under review. Verify whether the transfer was received and refund if so.",
                                ['transaction_reference' => $payment->transaction_reference, 'amount' => $payment->amount, 'currency' => $payment->currency],
                                ['cancelled_under_review'],
                                actor: $admin,
                            );
                        }
                    });

                $this->releaseOrderStock->handle($locked, $admin, 'Unpaid order cancelled');
            }

            return $locked->refresh();
        }, 3);
    }
}
