<?php

namespace App\Actions\Payments;

use App\Exceptions\PaymentCannotBeReviewedException;
use App\Models\MembershipTerm;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Request;
use Illuminate\Support\Str;

/**
 * Admin rejects a submitted renewal payment (docs/architecture §0.9). The
 * rejection is transient (docs/database/06 §1, ADR-09): the term's
 * `payment_status` returns immediately to `payment_pending` so the member can
 * resubmit on the same payment row — the renewal is never restarted, and its
 * `status` stays `pending_payment` throughout.
 *
 * @throws PaymentCannotBeReviewedException
 */
class RejectPayment
{
    public function handle(Payment $payment, User $admin, string $reason): MembershipTerm
    {
        return DB::transaction(function () use ($payment, $admin, $reason): MembershipTerm {
            $locked = Payment::query()->whereKey($payment->id)->lockForUpdate()->first();

            if ($locked === null || $locked->status !== Payment::STATUS_PROCESSING) {
                throw new PaymentCannotBeReviewedException('This payment is not currently awaiting confirmation.');
            }

            $term = MembershipTerm::query()->whereKey($locked->membership_term_id)->lockForUpdate()->first();

            if ($term === null || $term->status !== MembershipTerm::STATUS_PENDING_PAYMENT) {
                throw new PaymentCannotBeReviewedException('This renewal is no longer awaiting payment.');
            }

            $now = now();
            $locked->forceFill([
                'status' => Payment::STATUS_FAILED,
                'failed_at' => $now,
                'rejection_reason' => $reason,
                'reviewed_by_user_id' => $admin->id,
                'reviewed_at' => $now,
            ])->save();

            $term->forceFill(['payment_status' => MembershipTerm::PAYMENT_PENDING])->save();

            $this->record($locked, $term, $admin, $reason);

            return $term->refresh();
        });
    }

    private function record(Payment $payment, MembershipTerm $term, User $admin, string $reason): void
    {
        $now = now();

        DB::table('membership_status_history')->insert([
            'membership_id' => $term->membership_id,
            'membership_term_id' => $term->id,
            'event' => 'payment.rejected',
            'to_status' => MembershipTerm::STATUS_PENDING_PAYMENT,
            'actor_type' => 'admin',
            'actor_user_id' => $admin->id,
            'note' => Str::limit($reason, 500, ''),
            'created_at' => $now,
        ]);

        DB::table('audit_logs')->insert([
            'user_id' => $admin->id,
            'actor_type' => 'user',
            'event' => 'payment.rejected',
            'subject_type' => 'payment',
            'subject_id' => $payment->id,
            'new_values' => json_encode(['rejection_reason' => $reason]),
            'ip_address' => Request::ip(),
            'user_agent' => Str::limit((string) Request::userAgent(), 512, ''),
            'created_at' => $now,
        ]);
    }
}
