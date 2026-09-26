<?php

namespace App\Actions\Payments;

use App\Models\Membership;
use App\Models\MembershipTerm;
use App\Models\Payment;
use App\Models\PaymentBankAccount;

/**
 * Create the one `pending` payment row for a renewal term (docs/database/06 §3).
 * Called only by `StartRenewal`, inside its transaction. The idempotency key is
 * deterministic per term, so a genuine double-submit collides on the database's
 * own unique index rather than creating a second payment for the same renewal.
 */
class CreateMembershipPayment
{
    public function handle(Membership $membership, MembershipTerm $term, PaymentBankAccount $bankAccount): Payment
    {
        return Payment::forceCreate([
            'user_id' => $membership->user_id,
            'membership_term_id' => $term->id,
            'bank_account_id' => $bankAccount->id,
            'gateway' => Payment::GATEWAY_MANUAL_BANK_TRANSFER,
            'idempotency_key' => 'membership-term-'.$term->id,
            'amount' => $term->fee_amount,
            'currency' => $term->fee_currency,
            'status' => Payment::STATUS_PENDING,
        ]);
    }
}
