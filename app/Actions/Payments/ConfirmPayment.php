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
 * Admin confirms a submitted renewal payment (docs/architecture §0.9/§0.10).
 * The renewal term becomes `active`; the membership row — and its number — is
 * never touched.
 *
 * Term start date (docs/database/04 §9, OD-21 recommended default, schema-
 * neutral): contiguous with the previous term when renewing while it is still
 * current (starts the day after its `expires_on`), otherwise starts today (the
 * confirmation date) — e.g. renewing after the membership has already lapsed.
 *
 * @throws PaymentCannotBeReviewedException
 */
class ConfirmPayment
{
    public function handle(Payment $payment, User $admin): MembershipTerm
    {
        return DB::transaction(function () use ($payment, $admin): MembershipTerm {
            $locked = Payment::query()->whereKey($payment->id)->lockForUpdate()->first();

            if ($locked === null || $locked->status !== Payment::STATUS_PROCESSING) {
                throw new PaymentCannotBeReviewedException('This payment is not currently awaiting confirmation.');
            }

            $term = MembershipTerm::query()->whereKey($locked->membership_term_id)->lockForUpdate()->first();

            if ($term === null || $term->status !== MembershipTerm::STATUS_PENDING_PAYMENT) {
                throw new PaymentCannotBeReviewedException('This renewal is no longer awaiting payment.');
            }

            $timezone = config('membership.business_timezone');
            $today = now()->copy()->setTimezone($timezone)->startOfDay();

            $previous = MembershipTerm::query()
                ->where('membership_id', $term->membership_id)
                ->where('term_no', $term->term_no - 1)
                ->first();

            $startsOn = ($previous?->status === MembershipTerm::STATUS_ACTIVE && $previous->expires_on !== null && $previous->expires_on->greaterThanOrEqualTo($today))
                ? $previous->expires_on->copy()->addDay()
                : $today->copy();

            $expiresOn = $startsOn->copy()->addMonthsNoOverflow((int) $term->duration_months)->subDay();

            $now = now();
            $term->forceFill([
                'status' => MembershipTerm::STATUS_ACTIVE,
                'payment_status' => MembershipTerm::PAYMENT_CONFIRMED,
                'starts_on' => $startsOn->toDateString(),
                'expires_on' => $expiresOn->toDateString(),
                'activated_at' => $now,
            ])->save();

            $locked->forceFill([
                'status' => Payment::STATUS_PAID,
                'paid_at' => $now,
                'reviewed_by_user_id' => $admin->id,
                'reviewed_at' => $now,
            ])->save();

            $this->record($locked, $term, $admin);

            return $term->refresh();
        });
    }

    private function record(Payment $payment, MembershipTerm $term, User $admin): void
    {
        $now = now();

        DB::table('membership_status_history')->insert([
            'membership_id' => $term->membership_id,
            'membership_term_id' => $term->id,
            'event' => 'membership.renewal_confirmed',
            'to_status' => MembershipTerm::STATUS_ACTIVE,
            'actor_type' => 'admin',
            'actor_user_id' => $admin->id,
            'note' => $term->starts_on->toDateString().' to '.$term->expires_on->toDateString(),
            'created_at' => $now,
        ]);

        DB::table('audit_logs')->insert([
            'user_id' => $admin->id,
            'actor_type' => 'user',
            'event' => 'payment.confirmed',
            'subject_type' => 'payment',
            'subject_id' => $payment->id,
            'new_values' => json_encode([
                'membership_term_id' => $term->id,
                'starts_on' => $term->starts_on->toDateString(),
                'expires_on' => $term->expires_on->toDateString(),
            ]),
            'ip_address' => Request::ip(),
            'user_agent' => Str::limit((string) Request::userAgent(), 512, ''),
            'created_at' => $now,
        ]);
    }
}
