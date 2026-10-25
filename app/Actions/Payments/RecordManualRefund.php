<?php

namespace App\Actions\Payments;

use App\Actions\Orders\NotifyCustomer;
use App\Actions\Orders\RecordOrderAudit;
use App\Exceptions\PaymentCannotBeReviewedException;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentRefund;
use App\Models\User;
use App\Notifications\Orders\OrderRefundRecorded;
use App\Support\Money;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Records a refund that an administrator already made manually (PayHere
 * merchant portal or bank). No gateway call. Append-only: the original
 * payment keeps its amount, paid_at and reference; only its status becomes
 * `partially_refunded` or `refunded`. When every settled payment of the order
 * is fully refunded, an order that allows it moves to `refunded`. Stock is
 * never restored automatically. The customer is emailed once per recorded
 * refund, after commit. Admin-only (PaymentPolicy::recordRefund). Lock
 * order: order → payment.
 */
class RecordManualRefund
{
    public function __construct(
        private readonly RecordOrderAudit $audit,
        private readonly NotifyCustomer $notifyCustomer,
    ) {}

    /**
     * @throws PaymentCannotBeReviewedException
     */
    public function handle(Payment $payment, User $admin, string $amount, string $reason, string $reference, Carbon $refundedOn): PaymentRefund
    {
        if ($payment->order_id === null || $payment->membership_term_id !== null) {
            throw new PaymentCannotBeReviewedException('Refunds can only be recorded for e-shop payments.');
        }

        try {
            return DB::transaction(function () use ($payment, $admin, $amount, $reason, $reference, $refundedOn): PaymentRefund {
                $order = Order::query()->lockForUpdate()->findOrFail($payment->order_id);
                $locked = Payment::query()->lockForUpdate()->findOrFail($payment->id);

                if (! in_array($locked->status, [Payment::STATUS_PAID, Payment::STATUS_PARTIALLY_REFUNDED], true)) {
                    throw new PaymentCannotBeReviewedException('Only a paid payment can have a refund recorded.');
                }

                $alreadyRefundedMinor = $locked->refunds()->get()->sum(fn (PaymentRefund $refund): int => Money::toMinor((string) $refund->amount));
                $refundMinor = Money::toMinor($amount);
                $remainingMinor = Money::toMinor((string) $locked->amount) - $alreadyRefundedMinor;

                if ($refundMinor <= 0 || $refundMinor > $remainingMinor) {
                    throw new PaymentCannotBeReviewedException('The refund amount must be more than zero and no more than '.$locked->currency.' '.Money::fromMinor($remainingMinor).'.');
                }

                $refund = new PaymentRefund;
                $refund->forceFill([
                    'payment_id' => $locked->id,
                    'order_id' => $order->id,
                    'amount' => Money::fromMinor($refundMinor),
                    'currency' => $locked->currency,
                    'reason' => $reason,
                    'refund_reference' => $reference,
                    'refunded_on' => $refundedOn->toDateString(),
                    'recorded_by_user_id' => $admin->id,
                ])->save();

                $fullyRefunded = $refundMinor === $remainingMinor;
                $previousStatus = $locked->status;
                $locked->forceFill(['status' => $fullyRefunded ? Payment::STATUS_REFUNDED : Payment::STATUS_PARTIALLY_REFUNDED])->save();

                $this->audit->handle($refund, 'payment.refund_recorded', $admin, [
                    'payment' => $locked->public_id,
                    'amount' => $refund->amount,
                    'currency' => $refund->currency,
                    'refund_reference' => $reference,
                    'reason' => $reason,
                    'refunded_on' => $refund->refunded_on->toDateString(),
                ]);
                $this->audit->handle($locked, 'payment.status_changed', $admin, ['to' => $locked->status], oldValues: ['from' => $previousStatus]);

                $orderFullyRefunded = ! $order->payments()
                    ->whereIn('status', [Payment::STATUS_PAID, Payment::STATUS_PARTIALLY_REFUNDED])
                    ->exists();

                if ($orderFullyRefunded && $order->canTransitionTo(Order::STATUS_REFUNDED)) {
                    $from = $order->status;
                    $order->update(['status' => Order::STATUS_REFUNDED]);
                    $this->audit->handle($order, 'order.status_changed', $admin, ['from' => $from, 'to' => Order::STATUS_REFUNDED, 'reason' => 'All payments refunded']);
                }

                // Once per refund record, only after it is committed.
                DB::afterCommit(fn () => $this->notifyCustomer->handle($order, new OrderRefundRecorded($order->fresh(), $refund)));

                return $refund;
            }, 3);
        } catch (UniqueConstraintViolationException) {
            throw new PaymentCannotBeReviewedException('A refund with this reference is already recorded for this payment.');
        }
    }
}
