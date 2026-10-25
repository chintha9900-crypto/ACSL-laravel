<?php

namespace App\Actions\Payments;

use App\Actions\Orders\RecordOrderAudit;
use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Records a cancelled order payment attempt (the customer abandoned it). The
 * order itself stays `pending_payment` with its stock reserved until it
 * expires, so the customer can try again. Idempotent, and never undoes a
 * final state. Lock order: order → payment.
 */
class CancelOrderPayment
{
    public function __construct(private readonly RecordOrderAudit $audit) {}

    /**
     * @param  array<string, mixed>  $attributes  Extra payment columns to record (metadata is merged).
     */
    public function handle(Payment $payment, ?User $actor = null, array $attributes = [], ?string $actorType = null): Payment
    {
        return DB::transaction(function () use ($payment, $actor, $attributes, $actorType): Payment {
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
                'status' => Payment::STATUS_CANCELLED,
            ])->save();

            $this->audit->handle($locked, 'payment.cancelled', $actor, ['gateway' => $locked->gateway], $actorType);

            return $locked;
        }, 3);
    }
}
