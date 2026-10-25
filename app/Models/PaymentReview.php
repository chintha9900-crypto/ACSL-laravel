<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One item in the manual payment-review queue (see the migration). Created
 * only by App\Actions\Payments\FlagPaymentForReview; resolved by an admin.
 */
class PaymentReview extends Model
{
    use HasUlids;

    public const TYPE_DUPLICATE_NOTIFICATION = 'duplicate_notification';

    public const TYPE_LATE_PAYMENT = 'late_payment';

    public const TYPE_CHARGEBACK = 'chargeback';

    public const TYPE_AMOUNT_MISMATCH = 'amount_mismatch';

    public const TYPE_TRANSFER_CONFLICT = 'transfer_conflict';

    public const STATUS_OPEN = 'open';

    public const STATUS_RESOLVED = 'resolved';

    public const TYPE_LABELS = [
        self::TYPE_DUPLICATE_NOTIFICATION => 'Repeated success notification',
        self::TYPE_LATE_PAYMENT => 'Payment after order closed',
        self::TYPE_CHARGEBACK => 'Chargeback',
        self::TYPE_AMOUNT_MISMATCH => 'Amount or currency mismatch',
        self::TYPE_TRANSFER_CONFLICT => 'Bank transfer conflict',
    ];

    protected function casts(): array
    {
        return [
            'details' => 'array',
            'resolved_at' => 'datetime',
        ];
    }

    public function uniqueIds(): array
    {
        return ['public_id'];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    public function resolvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by_user_id');
    }

    public function typeLabel(): string
    {
        return self::TYPE_LABELS[$this->type] ?? $this->type;
    }
}
