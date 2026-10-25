<?php

namespace App\Actions\Payments;

use App\Actions\Orders\RecordOrderAudit;
use App\Models\PaymentReview;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Closes a payment-review item with the reviewer's decision note. Records the
 * decision only — any refund is recorded separately (RecordManualRefund) and
 * nothing here changes a payment, order or stock.
 */
class ResolvePaymentReview
{
    public function __construct(private readonly RecordOrderAudit $audit) {}

    public function handle(PaymentReview $review, User $reviewer, string $note): PaymentReview
    {
        return DB::transaction(function () use ($review, $reviewer, $note): PaymentReview {
            $locked = PaymentReview::query()->lockForUpdate()->findOrFail($review->id);

            if ($locked->status === PaymentReview::STATUS_RESOLVED) {
                return $locked;
            }

            $locked->forceFill([
                'status' => PaymentReview::STATUS_RESOLVED,
                'resolved_by_user_id' => $reviewer->id,
                'resolved_at' => now(),
                'resolution_note' => $note,
            ])->save();

            $this->audit->handle($locked, 'payment_review.resolved', $reviewer, ['type' => $locked->type, 'note' => $note]);

            return $locked;
        }, 3);
    }
}
