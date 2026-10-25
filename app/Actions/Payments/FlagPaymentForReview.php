<?php

namespace App\Actions\Payments;

use App\Actions\Orders\RecordOrderAudit;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentReview;
use App\Models\User;

/**
 * Puts a payment conflict in the manual-review queue. Never changes the
 * payment's or order's state itself. Call it inside the caller's transaction,
 * after the order row is locked (lock order: order → payments → reviews).
 *
 * Idempotent: the fingerprint identifies the event from trusted values only,
 * so a replay adds no new row; it only increments the repeat count. Values
 * PayHere does not sign (payment_id) are kept under `unverified` and never
 * decide anything.
 */
class FlagPaymentForReview
{
    private const MAX_UNVERIFIED_REFERENCES = 10;

    public function __construct(private readonly RecordOrderAudit $audit) {}

    /**
     * @param  array<string, mixed>  $details  Trusted details.
     * @param  list<string>  $fingerprintParts  Trusted values identifying this event.
     */
    public function handle(
        Order $order,
        ?Payment $payment,
        string $type,
        string $summary,
        array $details,
        array $fingerprintParts,
        ?string $unverifiedReference = null,
        ?User $actor = null,
    ): PaymentReview {
        $fingerprint = hash('sha256', implode('|', [$type, $order->id, $payment?->id, ...$fingerprintParts]));

        $review = PaymentReview::query()->where('fingerprint', $fingerprint)->lockForUpdate()->first();

        if ($review !== null) {
            $existing = $review->details ?? [];
            $existing['repeat_count'] = ($existing['repeat_count'] ?? 1) + 1;
            $existing['unverified']['reported_references'] = $this->appendReference($existing['unverified']['reported_references'] ?? [], $unverifiedReference);
            $review->forceFill(['details' => $existing])->save();

            return $review;
        }

        $review = new PaymentReview;
        $review->forceFill([
            'type' => $type,
            'status' => PaymentReview::STATUS_OPEN,
            'order_id' => $order->id,
            'payment_id' => $payment?->id,
            'fingerprint' => $fingerprint,
            'summary' => mb_substr($summary, 0, 500),
            'details' => [
                ...$details,
                'repeat_count' => 1,
                'unverified' => ['reported_references' => $this->appendReference([], $unverifiedReference)],
            ],
        ])->save();

        $this->audit->handle($payment ?? $order, 'payment.flagged_for_review', $actor, [
            'review' => $review->public_id,
            'type' => $type,
        ], $actor === null ? RecordOrderAudit::ACTOR_SYSTEM : null);

        return $review;
    }

    /**
     * @param  list<string>  $references
     * @return list<string>
     */
    private function appendReference(array $references, ?string $reference): array
    {
        if ($reference === null || $reference === '' || in_array($reference, $references, true) || count($references) >= self::MAX_UNVERIFIED_REFERENCES) {
            return $references;
        }

        $references[] = mb_substr($reference, 0, 191);

        return $references;
    }
}
