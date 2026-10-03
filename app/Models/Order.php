<?php

namespace App\Models;

use Database\Factories\OrderFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * E-Shop Step 1 — database foundation only. `order_number` is the
 * human-readable identifier shown to customers; `public_id` (ULID) is the
 * link-safe identifier, the same convention `Payment` already uses.
 * `user_id` is nullable: guests may place an order for `PUBLIC` products, so
 * `customer_*` is always captured as its own snapshot regardless of whether
 * an account exists.
 */
class Order extends Model
{
    /** @use HasFactory<OrderFactory> */
    use HasFactory;

    use HasUlids;

    public const STATUS_PENDING_PAYMENT = 'pending_payment';

    public const STATUS_PAID = 'paid';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_PACKED = 'packed';

    public const STATUS_SHIPPED = 'shipped';

    public const STATUS_DELIVERED = 'delivered';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_REFUNDED = 'refunded';

    /**
     * @var list<string>
     */
    public const STATUSES = [
        self::STATUS_PENDING_PAYMENT,
        self::STATUS_PAID,
        self::STATUS_PROCESSING,
        self::STATUS_PACKED,
        self::STATUS_SHIPPED,
        self::STATUS_DELIVERED,
        self::STATUS_CANCELLED,
        self::STATUS_REFUNDED,
    ];

    /**
     * E-Shop Step 9 — the only server-side source of truth for which
     * fulfilment-status changes an admin may make. `pending_payment` has no
     * outgoing edge to `paid` on purpose: only a confirmed payment
     * (`Actions\Payments\ConfirmOrderPayment`) may do that — this map is
     * never consulted by that Action, and this Action never writes
     * `payments.status`, keeping the two vocabularies logically separate.
     * `cancelled`/`refunded` have no outgoing edges at all (terminal), so a
     * repeated attempt to cancel/refund an already-cancelled/refunded order
     * is rejected by this map, not by any extra duplicate-detection code.
     *
     * @var array<string, list<string>>
     */
    public const TRANSITIONS = [
        self::STATUS_PENDING_PAYMENT => [self::STATUS_CANCELLED],
        self::STATUS_PAID => [self::STATUS_PROCESSING, self::STATUS_CANCELLED, self::STATUS_REFUNDED],
        self::STATUS_PROCESSING => [self::STATUS_PACKED, self::STATUS_CANCELLED, self::STATUS_REFUNDED],
        self::STATUS_PACKED => [self::STATUS_SHIPPED, self::STATUS_CANCELLED, self::STATUS_REFUNDED],
        self::STATUS_SHIPPED => [self::STATUS_DELIVERED, self::STATUS_REFUNDED],
        self::STATUS_DELIVERED => [self::STATUS_REFUNDED],
        self::STATUS_CANCELLED => [],
        self::STATUS_REFUNDED => [],
    ];

    /**
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'order_number',
        'customer_name',
        'customer_email',
        'customer_phone',
        'status',
        'currency',
        'total_amount',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'total_amount' => 'decimal:2',
        ];
    }

    /**
     * The public identifier is the ULID column; the auto-increment `id`
     * stays internal.
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
     * @return HasMany<OrderItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    /**
     * @return HasMany<Payment, $this>
     */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function canTransitionTo(string $status): bool
    {
        return in_array($status, self::TRANSITIONS[$this->status] ?? [], true);
    }
}
