<?php

namespace App\Actions\Payments;

use App\Actions\Orders\RecordOrderAudit;
use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Records a failed order payment attempt. Leaves the order at
 * `pending_payment` (stock stays reserved until the order expires or is
 * cancelled) so the customer can retry. Idempotent: a payment already in a
 * final state is left untouched, so a late or duplicate failure never undoes
 * a success. Lock order: order → payment.
 */
class FailOrderPayment
{
    public function __construct(private readonly RecordOrderAudit $audit) {}

    /**
     * @param  array<string, mixed>  $attributes  Extra payment columns to record (metadata is merged).
     */
    public function handle(Payment $payment, ?string $reason = null, ?User $actor = null, array $attributes = [], ?string $actorType = null): Payment
    {
        return DB::transaction(function () use ($payment, $reason, $actor, $attributes, $actorType): Payment {
            Order::query()->lockForUpdate()->find(Payment::query()->whereKey($payment->id)->value('order_id'));
            $locked = Payment::query()->lockForUpdate()->findOrFail($payment->id);

            if (in_array($locked->status, Payment::FINAL_STATUSES, true)) {
                return $locked;
            }

            if (isset($attributes['metadata'])) {
                $attributes['metadata'] = [...($locked->metadata ?? []), ...$attributes['metadata']];
            }

            $locked->forceFill([
                ...$attributes,
                'status' => Payment::STATUS_FAILED,
                'failed_at' => now(),
                'rejection_reason' => $reason,
            ])->save();

            $this->audit->handle($locked, 'payment.failed', $actor, ['gateway' => $locked->gateway, 'reason' => $reason], $actorType);

            return $locked;
        }, 3);
    }
}
