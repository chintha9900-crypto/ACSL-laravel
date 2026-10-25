<?php

namespace App\Actions\Payments;

use App\Actions\Orders\NotifyCustomer;
use App\Actions\Orders\RecordOrderAudit;
use App\Exceptions\PaymentCannotBeReviewedException;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentReview;
use App\Models\User;
use App\Notifications\Orders\OrderPaymentRejected;
use Illuminate\Support\Facades\DB;

/**
 * Admin verification of an e-shop bank transfer. Only a `processing`
 * (evidence submitted) bank-transfer attempt can be decided.
 *
 * Approval goes through ConfirmOrderPayment (the same transition every
 * method uses; it clears stale rejection fields and sends the receipt once).
 * If the order was meanwhile paid, cancelled or expired another way, the
 * transfer is not approved: it is closed and placed in the manual review
 * queue, because the money may have to be returned. Rejection records the
 * reason and notifies the customer, who may resubmit.
 *
 * Never calls the membership ConfirmPayment/RejectPayment actions. Lock
 * order: order → payment.
 */
class ReviewOrderBankTransfer
{
    public const APPROVED = 'approved';

    public const ALREADY_APPROVED = 'already_approved';

    public const SENT_TO_REVIEW = 'sent_to_review';

    public function __construct(
        private readonly ConfirmOrderPayment $confirmOrderPayment,
        private readonly NotifyCustomer $notifyCustomer,
        private readonly RecordOrderAudit $audit,
        private readonly FlagPaymentForReview $flag,
    ) {}

    /**
     * @return self::APPROVED|self::ALREADY_APPROVED|self::SENT_TO_REVIEW
     *
     * @throws PaymentCannotBeReviewedException
     */
    public function approve(Payment $payment, User $admin): string
    {
        $this->assertReviewable($payment);

        return DB::transaction(function () use ($payment, $admin): string {
            [$order, $locked] = $this->lock($payment);

            if ($locked->isSettled()) {
                return self::ALREADY_APPROVED;
            }

            if ($locked->status !== Payment::STATUS_PROCESSING) {
                throw new PaymentCannotBeReviewedException('This payment is not currently awaiting confirmation.');
            }

            if ($order->status !== Order::STATUS_PENDING_PAYMENT) {
                $locked->forceFill(['status' => Payment::STATUS_CANCELLED])->save();
                $this->audit->handle($locked, 'payment.cancelled', $admin, ['reason' => "Approval attempted after the order was {$order->status}"]);
                $this->flag->handle(
                    $order,
                    $locked,
                    PaymentReview::TYPE_TRANSFER_CONFLICT,
                    "Approval of bank transfer (reference {$locked->transaction_reference}) was attempted after order {$order->order_number} was {$order->status}. Verify whether the transfer was received and refund if so.",
                    ['order_status' => $order->status, 'transaction_reference' => $locked->transaction_reference, 'amount' => $locked->amount, 'currency' => $locked->currency],
                    ['approval_after_close'],
                    actor: $admin,
                );

                return self::SENT_TO_REVIEW;
            }

            $this->confirmOrderPayment->handle(
                $locked,
                $admin,
                requireStatuses: [Payment::STATUS_PROCESSING],
                strict: true,
                isAdminReview: true,
            );

            return self::APPROVED;
        }, 3);
    }

    /**
     * @throws PaymentCannotBeReviewedException
     */
    public function reject(Payment $payment, User $admin, string $reason): Payment
    {
        $this->assertReviewable($payment);

        return DB::transaction(function () use ($payment, $admin, $reason): Payment {
            [$order, $locked] = $this->lock($payment);

            if ($locked->status !== Payment::STATUS_PROCESSING) {
                throw new PaymentCannotBeReviewedException('This payment is not currently awaiting confirmation.');
            }

            if ($order->status !== Order::STATUS_PENDING_PAYMENT) {
                throw new PaymentCannotBeReviewedException('This order is no longer awaiting payment.');
            }

            $now = now();

            $locked->forceFill([
                'status' => Payment::STATUS_FAILED,
                'failed_at' => $now,
                'rejection_reason' => $reason,
                'reviewed_by_user_id' => $admin->id,
                'reviewed_at' => $now,
            ])->save();

            $this->audit->handle($locked, 'payment.rejected', $admin, ['rejection_reason' => $reason, 'transaction_reference' => $locked->transaction_reference]);

            DB::afterCommit(fn () => $this->notifyCustomer->handle($order, new OrderPaymentRejected($order, $reason)));

            return $locked;
        }, 3);
    }

    /**
     * @return array{0: Order, 1: Payment}
     */
    private function lock(Payment $payment): array
    {
        $order = Order::query()->lockForUpdate()->findOrFail($payment->order_id);
        $locked = Payment::query()->lockForUpdate()->findOrFail($payment->id);

        return [$order, $locked];
    }

    private function assertReviewable(Payment $payment): void
    {
        if ($payment->order_id === null || $payment->membership_term_id !== null || $payment->gateway !== Payment::GATEWAY_MANUAL_BANK_TRANSFER) {
            throw new PaymentCannotBeReviewedException('This is not an e-shop bank transfer.');
        }
    }
}
