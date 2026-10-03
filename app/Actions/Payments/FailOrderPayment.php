<?php

namespace App\Actions\Payments;

use App\Models\Payment;
use Illuminate\Support\Facades\DB;

/**
 * Leaves the order at `pending_payment` so the customer can retry — only a
 * confirmed payment (`ConfirmOrderPayment`) is allowed to move it out of
 * that state. Idempotent: a payment that already reached a final state
 * (`paid`, `failed` or `cancelled`) is left untouched.
 */
class FailOrderPayment
{
    public function handle(Payment $payment, ?string $reason = null): Payment
    {
        return DB::transaction(function () use ($payment, $reason): Payment {
            $locked = Payment::query()->lockForUpdate()->findOrFail($payment->id);

            if ($this->isFinal($locked)) {
                return $locked;
            }

            $locked->forceFill([
                'status' => Payment::STATUS_FAILED,
                'failed_at' => now(),
                'rejection_reason' => $reason,
            ])->save();

            return $locked;
        });
    }

    private function isFinal(Payment $payment): bool
    {
        return in_array($payment->status, [
            Payment::STATUS_PAID,
            Payment::STATUS_FAILED,
            Payment::STATUS_CANCELLED,
        ], true);
    }
}
