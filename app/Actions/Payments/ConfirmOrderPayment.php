<?php

namespace App\Actions\Payments;

use App\Actions\Orders\NotifyCustomer;
use App\Actions\Orders\RecordOrderAudit;
use App\Actions\Orders\ReleaseOrderStock;
use App\Exceptions\PaymentCannotBeReviewedException;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentReview;
use App\Models\User;
use App\Notifications\Orders\OrderReceipt;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * The single internal "payment verified" transition for e-shop orders, used
 * by admin bank-transfer approval, the verified PayHere notification and the
 * development dummy adapter alike. Callers verify the payment first; this
 * only applies the result.
 *
 * Lock order (shared by every order/payment action): order → payments →
 * inventory. The transaction retries on deadlock, and the receipt is sent
 * only after commit, so a retry or rollback can never send one.
 *
 * - Membership payments are refused outright.
 * - Already settled: no change, no second receipt (idempotent).
 * - Order awaiting payment: payment → paid (stale rejection fields cleared),
 *   order → paid, other open attempts closed, receipt sent once.
 * - Order closed another way (cancelled, expired, or paid by another
 *   attempt): with `$strict`, refused; otherwise (a gateway reporting money
 *   already taken) the payment is recorded as paid and put in the manual
 *   review queue — the order is never reopened, stock is not touched and no
 *   receipt is sent.
 */
class ConfirmOrderPayment
{
    public function __construct(
        private readonly NotifyCustomer $notifyCustomer,
        private readonly RecordOrderAudit $audit,
        private readonly FlagPaymentForReview $flag,
        private readonly ReleaseOrderStock $releaseOrderStock,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes  Extra payment columns to record (metadata is merged).
     * @param  list<string>|null  $requireStatuses  Payment statuses this confirmation may start from.
     * @param  bool  $isAdminReview  Record $actor as the reviewer (bank-transfer approval).
     *
     * @throws PaymentCannotBeReviewedException
     */
    public function handle(
        Payment $payment,
        ?User $actor = null,
        array $attributes = [],
        ?array $requireStatuses = null,
        bool $strict = false,
        ?string $actorType = null,
        bool $isAdminReview = false,
    ): Payment {
        return DB::transaction(function () use ($payment, $actor, $attributes, $requireStatuses, $strict, $actorType, $isAdminReview): Payment {
            $purpose = Payment::query()->whereKey($payment->id)->first(['order_id', 'membership_term_id']);

            if ($purpose === null || $purpose->order_id === null || $purpose->membership_term_id !== null) {
                throw new LogicException('Only e-shop order payments can be confirmed here.');
            }

            $order = Order::query()->lockForUpdate()->findOrFail($purpose->order_id);
            $locked = Payment::query()->lockForUpdate()->findOrFail($payment->id);

            if ($locked->isSettled()) {
                return $locked;
            }

            if ($requireStatuses !== null && ! in_array($locked->status, $requireStatuses, true)) {
                throw new PaymentCannotBeReviewedException('This payment is not currently awaiting confirmation.');
            }

            $orderAwaitingPayment = $order->status === Order::STATUS_PENDING_PAYMENT;

            if (! $orderAwaitingPayment && $strict) {
                throw new PaymentCannotBeReviewedException('This order is no longer awaiting payment.');
            }

            $now = now();
            $metadata = [...($locked->metadata ?? []), ...($attributes['metadata'] ?? [])];
            unset($attributes['metadata']);

            $locked->forceFill([
                ...$attributes,
                'status' => Payment::STATUS_PAID,
                'paid_at' => $now,
                'failed_at' => null,
                'rejection_reason' => null,
                'metadata' => $metadata === [] ? null : $metadata,
                ...($isAdminReview && $actor !== null ? ['reviewed_by_user_id' => $actor->id, 'reviewed_at' => $now] : []),
            ])->save();

            $this->audit->handle($locked, 'payment.confirmed', $actor, ['gateway' => $locked->gateway, 'amount' => $locked->amount, 'currency' => $locked->currency], $actorType);

            if (! $orderAwaitingPayment) {
                $this->flag->handle(
                    $order,
                    $locked,
                    PaymentReview::TYPE_LATE_PAYMENT,
                    "Payment received for order {$order->order_number} after it was {$order->status}. The order was not reopened — verify and refund if appropriate.",
                    ['order_status' => $order->status, 'gateway' => $locked->gateway, 'amount' => $locked->amount, 'currency' => $locked->currency],
                    ['late_payment'],
                );

                return $locked;
            }

            $order->update(['status' => Order::STATUS_PAID]);
            $this->audit->handle($order, 'order.paid', $actor, ['payment' => $locked->public_id, 'gateway' => $locked->gateway], $actorType);

            $this->closeOtherAttempts($order, $locked, $actor, $actorType);

            if ($order->is_test) {
                $this->releaseOrderStock->handle($order, $actor, 'Simulated test order — stock returned');
            }

            DB::afterCommit(fn () => $this->notifyCustomer->handle($order, new OrderReceipt($order->fresh('items'))));

            return $locked;
        }, 3);
    }

    /**
     * Once the order is paid, no other attempt may stay open. A bank transfer
     * whose evidence is under review may represent real money, so it goes to
     * manual review instead of being silently dropped.
     */
    private function closeOtherAttempts(Order $order, Payment $paid, ?User $actor, ?string $actorType): void
    {
        $order->payments()
            ->whereKeyNot($paid->id)
            ->whereIn('status', [Payment::STATUS_PENDING, Payment::STATUS_PROCESSING])
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->each(function (Payment $other) use ($order, $actor, $actorType): void {
                $wasUnderReview = $other->status === Payment::STATUS_PROCESSING;
                $other->forceFill(['status' => Payment::STATUS_CANCELLED])->save();
                $this->audit->handle($other, 'payment.cancelled', $actor, ['reason' => 'Order paid by another attempt'], $actorType);

                if ($wasUnderReview) {
                    $this->flag->handle(
                        $order,
                        $other,
                        PaymentReview::TYPE_TRANSFER_CONFLICT,
                        "Bank transfer evidence (reference {$other->transaction_reference}) was under review when order {$order->order_number} was paid by another attempt. Verify whether this transfer was received and refund if so.",
                        ['transaction_reference' => $other->transaction_reference, 'amount' => $other->amount, 'currency' => $other->currency],
                        ['paid_elsewhere'],
                    );
                }
            });
    }
}
