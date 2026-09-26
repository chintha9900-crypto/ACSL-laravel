<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One validity term of a membership (docs/database/04 §9). This phase only creates
 * term 1 (introductory, free); nothing is mass assignable.
 */
class MembershipTerm extends Model
{
    public const KIND_INTRODUCTORY = 'introductory';

    public const STATUS_ACTIVE = 'active';

    public const PAYMENT_NOT_REQUIRED = 'payment_not_required';

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
}
