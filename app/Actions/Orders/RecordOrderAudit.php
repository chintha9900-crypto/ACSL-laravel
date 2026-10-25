<?php

namespace App\Actions\Orders;

use App\Models\EshopSetting;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentBankAccount;
use App\Models\PaymentRefund;
use App\Models\PaymentReview;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Request;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Writes one e-shop event (order, payment, review, refund, bank account or
 * e-shop setting) to the shared `audit_logs` table — the admin's history.
 * Values passed in must never include secrets or card data.
 */
class RecordOrderAudit
{
    public const ACTOR_USER = 'user';

    public const ACTOR_GUEST = 'guest';

    public const ACTOR_SYSTEM = 'system';

    /**
     * @param  array<string, mixed>  $values
     * @param  array<string, mixed>  $oldValues
     */
    public function handle(
        Order|Payment|PaymentReview|PaymentRefund|PaymentBankAccount|EshopSetting $subject,
        string $event,
        ?User $actor,
        array $values = [],
        ?string $actorType = null,
        array $oldValues = [],
    ): void {
        DB::table('audit_logs')->insert([
            'user_id' => $actor?->id,
            'actor_type' => $actorType ?? ($actor === null ? self::ACTOR_GUEST : self::ACTOR_USER),
            'event' => $event,
            'subject_type' => $this->subjectType($subject),
            'subject_id' => $subject->getKey(),
            'old_values' => $oldValues === [] ? null : json_encode($oldValues),
            'new_values' => $values === [] ? null : json_encode($values),
            'ip_address' => Request::ip(),
            'user_agent' => Str::limit((string) Request::userAgent(), 512, ''),
            'created_at' => now(),
        ]);
    }

    private function subjectType(object $subject): string
    {
        return match (true) {
            $subject instanceof Order => 'order',
            $subject instanceof Payment => 'payment',
            $subject instanceof PaymentReview => 'payment_review',
            $subject instanceof PaymentRefund => 'payment_refund',
            $subject instanceof PaymentBankAccount => 'payment_bank_account',
            $subject instanceof EshopSetting => 'eshop_setting',
            default => throw new InvalidArgumentException('Unsupported audit subject.'),
        };
    }
}
