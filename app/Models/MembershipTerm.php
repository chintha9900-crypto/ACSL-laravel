<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * One validity term of a membership (docs/database/04 §9). This phase only creates
 * term 1 (introductory, free); nothing is mass assignable.
 */
class MembershipTerm extends Model
{
    public const KIND_INTRODUCTORY = 'introductory';

    public const KIND_RENEWAL = 'renewal';

    public const STATUS_PENDING_PAYMENT = 'pending_payment';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_EXPIRED = 'expired';

    public const PAYMENT_NOT_REQUIRED = 'payment_not_required';

    public const PAYMENT_PENDING = 'payment_pending';

    public const PAYMENT_CONFIRMATION_SUBMITTED = 'payment_confirmation_submitted';

    public const PAYMENT_CONFIRMED = 'payment_confirmed';

    public const PAYMENT_REJECTED = 'payment_rejected';

    /**
     * The three allowed term statuses (docs/database/04 §9) and how a member sees them.
     *
     * @var array<string, string>
     */
    public const STATUS_LABELS = [
        self::STATUS_PENDING_PAYMENT => 'Renewal pending',
        self::STATUS_ACTIVE => 'Active',
        self::STATUS_EXPIRED => 'Expired',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'starts_on' => 'date',
            'expires_on' => 'date',
            'activated_at' => 'datetime',
            'expired_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Membership, $this>
     */
    public function membership(): BelongsTo
    {
        return $this->belongsTo(Membership::class);
    }

    /**
     * @return BelongsTo<MembershipPlan, $this>
     */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(MembershipPlan::class, 'membership_plan_id');
    }

    /**
     * The one payment for this term (renewal terms only — the introductory term
     * never has one).
     *
     * @return HasOne<Payment, $this>
     */
    public function payment(): HasOne
    {
        return $this->hasOne(Payment::class);
    }
}
