<?php

namespace App\Actions\Payments;

use App\Exceptions\PaymentCannotBeSubmittedException;
use App\Models\Document;
use App\Models\MembershipTerm;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * The member submits a bank-transfer reference and evidence document for their
 * pending renewal payment (docs/architecture §0.9). Moves the payment to
 * `processing` and the term's `payment_status` to `payment_confirmation_submitted`.
 *
 * Safe to call twice: a genuine double-submit is rejected by the row-locked
 * status check (the second call finds `processing`, not `pending`/`failed`, and
 * throws without writing anything) — the same guard also allows a legitimate
 * resubmission after a rejection (`failed` → `processing`).
 *
 * @throws PaymentCannotBeSubmittedException
 */
class SubmitPaymentEvidence
{
    public function __construct(private StorePaymentEvidence $storeEvidence) {}

    public function handle(Payment $payment, User $member, string $reference, UploadedFile $evidence): Payment
    {
        $storedPaths = [];

        try {
            return DB::transaction(function () use ($payment, $member, $reference, $evidence, &$storedPaths): Payment {
                $locked = Payment::query()->whereKey($payment->id)->lockForUpdate()->first();

                if ($locked === null || $locked->user_id !== $member->id) {
                    throw new PaymentCannotBeSubmittedException('This payment could not be found.');
                }

                if (! in_array($locked->status, [Payment::STATUS_PENDING, Payment::STATUS_FAILED], true)) {
                    throw new PaymentCannotBeSubmittedException('This payment is not currently awaiting evidence.');
                }

                $term = MembershipTerm::query()->whereKey($locked->membership_term_id)->lockForUpdate()->first();

                if ($term === null || $term->status !== MembershipTerm::STATUS_PENDING_PAYMENT) {
                    throw new PaymentCannotBeSubmittedException('This renewal is no longer awaiting payment.');
                }

                $this->storeEvidence->handle($locked, $member, $evidence, $storedPaths);

                $now = now();
                $locked->forceFill([
                    'transaction_reference' => $reference,
                    'status' => Payment::STATUS_PROCESSING,
                    'submitted_at' => $now,
                ])->save();

                $term->forceFill(['payment_status' => MembershipTerm::PAYMENT_CONFIRMATION_SUBMITTED])->save();

                $this->record($locked, $term, $member);

                return $locked->refresh();
            });
        } catch (Throwable $exception) {
            foreach ($storedPaths as $path) {
                Storage::disk(Document::DISK_PRIVATE)->delete($path);
            }

            throw $exception;
        }
    }

    private function record(Payment $payment, MembershipTerm $term, User $member): void
    {
        $now = now();

        DB::table('membership_status_history')->insert([
            'membership_id' => $term->membership_id,
            'membership_term_id' => $term->id,
            'event' => 'payment.evidence_submitted',
            'to_status' => MembershipTerm::STATUS_PENDING_PAYMENT,
            'actor_type' => 'member',
            'actor_user_id' => $member->id,
            'created_at' => $now,
        ]);

        DB::table('audit_logs')->insert([
            'user_id' => $member->id,
            'actor_type' => 'user',
            'event' => 'payment.evidence_submitted',
            'subject_type' => 'payment',
            'subject_id' => $payment->id,
            'new_values' => json_encode(['status' => Payment::STATUS_PROCESSING]),
            'ip_address' => Request::ip(),
            'user_agent' => Str::limit((string) Request::userAgent(), 512, ''),
            'created_at' => $now,
        ]);
    }
}
