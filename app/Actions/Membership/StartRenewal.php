<?php

namespace App\Actions\Membership;

use App\Actions\Payments\CreateMembershipPayment;
use App\Exceptions\RenewalCannotBeStartedException;
use App\Models\Membership;
use App\Models\MembershipPlan;
use App\Models\MembershipTerm;
use App\Models\Payment;
use App\Models\PaymentBankAccount;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Request;
use Illuminate\Support\Str;

/**
 * Member-initiated renewal (docs/architecture/04 §6). Creates the next
 * `membership_terms` row (`renewal`, `pending_payment`) with the fee/duration
 * snapshotted from the category's active plan, and the one `payments` row for
 * it. Never touches the `memberships` row — the number never changes.
 *
 * One transaction; on any failure nothing is kept. No automatic renewal, no
 * payment gateway: the member still has to submit evidence and an admin still
 * has to confirm it before this term becomes valid.
 *
 * @throws RenewalCannotBeStartedException
 */
class StartRenewal
{
    public function __construct(private CreateMembershipPayment $createPayment) {}

    public function handle(Membership $membership): MembershipTerm
    {
        try {
            return DB::transaction(fn (): MembershipTerm => $this->start($membership), attempts: 3);
        } catch (UniqueConstraintViolationException $exception) {
            if (str_contains($exception->getMessage(), 'open_renewal_key')) {
                throw new RenewalCannotBeStartedException($this->alreadyPendingMessage(), previous: $exception);
            }

            if (str_contains($exception->getMessage(), 'gateway_idempotency_key')) {
                throw new RenewalCannotBeStartedException($this->alreadyPendingMessage(), previous: $exception);
            }

            throw $exception;
        }
    }

    private function start(Membership $membership): MembershipTerm
    {
        $locked = Membership::query()->whereKey($membership->id)->lockForUpdate()->first();

        if ($locked === null) {
            throw new RenewalCannotBeStartedException('This membership no longer exists.');
        }

        if (MembershipTerm::query()->where('membership_id', $locked->id)->where('status', MembershipTerm::STATUS_PENDING_PAYMENT)->exists()) {
            throw new RenewalCannotBeStartedException($this->alreadyPendingMessage());
        }

        $plan = MembershipPlan::query()->activeForCategory($locked->membership_category_id)->first();

        if ($plan === null) {
            throw new RenewalCannotBeStartedException('No renewal plan is configured for your category yet. Please contact Aviation Club International.');
        }

        $bankAccount = PaymentBankAccount::query()->activeForCurrency($plan->currency)->first();

        if ($bankAccount === null) {
            throw new RenewalCannotBeStartedException('No bank account is configured for this currency yet. Please contact Aviation Club International.');
        }

        $nextTermNo = (int) MembershipTerm::query()->where('membership_id', $locked->id)->max('term_no') + 1;

        $term = MembershipTerm::forceCreate([
            'membership_id' => $locked->id,
            'term_no' => $nextTermNo,
            'term_kind' => MembershipTerm::KIND_RENEWAL,
            'status' => MembershipTerm::STATUS_PENDING_PAYMENT,
            'payment_status' => MembershipTerm::PAYMENT_PENDING,
            'duration_months' => $plan->duration_months,
            'membership_plan_id' => $plan->id,
            'fee_amount' => $plan->fee_amount,
            'fee_currency' => $plan->currency,
        ]);

        $payment = $this->createPayment->handle($locked, $term, $bankAccount);

        $this->record($locked, $term, $payment);

        return $term->setRelation('payment', $payment);
    }

    private function record(Membership $membership, MembershipTerm $term, Payment $payment): void
    {
        $now = now();

        DB::table('membership_status_history')->insert([
            'membership_id' => $membership->id,
            'membership_term_id' => $term->id,
            'event' => 'membership.renewal_started',
            'to_status' => MembershipTerm::STATUS_PENDING_PAYMENT,
            'actor_type' => 'member',
            'actor_user_id' => $membership->user_id,
            'note' => 'Term #'.$term->term_no,
            'created_at' => $now,
        ]);

        DB::table('audit_logs')->insert([
            'user_id' => $membership->user_id,
            'actor_type' => 'user',
            'event' => 'payment.created',
            'subject_type' => 'payment',
            'subject_id' => $payment->id,
            'new_values' => json_encode([
                'membership_term_id' => $term->id,
                'amount' => (string) $payment->amount,
                'currency' => $payment->currency,
            ]),
            'ip_address' => Request::ip(),
            'user_agent' => Str::limit((string) Request::userAgent(), 512, ''),
            'created_at' => $now,
        ]);
    }

    private function alreadyPendingMessage(): string
    {
        return 'A renewal is already awaiting payment. Submit your payment evidence, or wait for it to be reviewed.';
    }
}
