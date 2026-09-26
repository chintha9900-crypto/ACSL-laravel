<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One provider-independent payment ledger row (docs/database/06 §3). Today the
 * only gateway is manual bank transfer; a membership payment always targets a
 * renewal term (`membership_term_id`) — the introductory term never has one.
 * Written only by `Actions/Payments/*`, never mass-assigned from a request.
 */
class Payment extends Model
{
    use HasUlids;

    public const GATEWAY_MANUAL_BANK_TRANSFER = 'manual_bank_transfer';

    public const STATUS_PENDING = 'pending';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_PAID = 'paid';

    public const STATUS_FAILED = 'failed';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'submitted_at' => 'datetime',
            'reviewed_at' => 'datetime',
            'paid_at' => 'datetime',
            'failed_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    /**
     * The public identifier is the ULID column; the auto-increment `id` stays internal.
     *
     * @return list<string>
     */
    public function uniqueIds(): array
    {
        return ['public_id'];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<MembershipTerm, $this>
     */
    public function membershipTerm(): BelongsTo
    {
        return $this->belongsTo(MembershipTerm::class);
    }

    /**
     * @return BelongsTo<PaymentBankAccount, $this>
     */
    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(PaymentBankAccount::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by_user_id');
    }

    /**
     * @return HasMany<Document, $this>
     */
    public function evidence(): HasMany
    {
        return $this->hasMany(Document::class);
    }
}
