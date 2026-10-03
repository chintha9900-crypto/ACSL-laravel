<?php

namespace App\Actions\Payments;

use App\Models\Payment;
use Illuminate\Support\Facades\DB;

/**
 * The customer cancelled this payment attempt (e.g. closed the gateway's
 * page) rather than it failing outright. Leaves the order at
 * `pending_payment` so the customer can retry. Idempotent, the same way as
 * `FailOrderPayment`.
 */
class CancelOrderPayment
{
    public function handle(Payment $payment): Payment
    {
        return DB::transaction(function () use ($payment): Payment {
            $locked = Payment::query()->lockForUpdate()->findOrFail($payment->id);

            if ($this->isFinal($locked)) {
                return $locked;
            }

            $locked->forceFill([
                'status' => Payment::STATUS_CANCELLED,
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
