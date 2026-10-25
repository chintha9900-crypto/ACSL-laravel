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

    public const GATEWAY_PAYHERE = 'payhere';

    public const GATEWAY_DUMMY = 'dummy';

    public const STATUS_PENDING = 'pending';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_PAID = 'paid';

    public const STATUS_FAILED = 'failed';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_REFUNDED = 'refunded';

    public const STATUS_PARTIALLY_REFUNDED = 'partially_refunded';

    /**
     * Money was received for these (refunds are recorded on top). A payment
     * in any of them can never be confirmed, failed or cancelled again.
     */
    public const SETTLED_STATUSES = [
        self::STATUS_PAID,
        self::STATUS_PARTIALLY_REFUNDED,
        self::STATUS_REFUNDED,
    ];

    /**
     * Final states: no gateway or admin action changes these any more.
     */
    public const FINAL_STATUSES = [
        ...self::SETTLED_STATUSES,
        self::STATUS_FAILED,
        self::STATUS_CANCELLED,
    ];

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
     * The e-shop counterpart of `membershipTerm()` — exactly one of the two
     * is ever set (`payments_exactly_one_purpose` CHECK).
     *
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
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
    public function refunds(): HasMany
    {
        return $this->hasMany(PaymentRefund::class);
    }

    public function isSettled(): bool
    {
        return in_array($this->status, self::SETTLED_STATUSES, true);
    }

    /**
     * Where to send money now: the account recorded on this payment only
     * while it is still active, otherwise the currently active account for
     * the currency. A deactivated account is never shown as a destination.
     */
    public function currentBankAccount(): ?PaymentBankAccount
    {
        if ($this->bankAccount?->is_active) {
            return $this->bankAccount;
        }

        return PaymentBankAccount::query()->activeForCurrency($this->currency)->first();
    }

    public function evidence(): HasMany
    {
        return $this->hasMany(Document::class);
    }
}
