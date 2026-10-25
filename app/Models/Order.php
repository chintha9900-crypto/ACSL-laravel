<?php

namespace App\Models;

use Database\Factories\OrderFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

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
     * Set only by the scheduled expiry (Actions/Orders/ExpireUnpaidOrder),
     * never by an admin status change.
     */
    public const STATUS_EXPIRED = 'expired';

    /**
     * Minutes from order creation until an unpaid order expires, per method.
     * Always measured from `created_at`, so retrying never extends it.
     */
    public const PAYMENT_WINDOW_MINUTES = [
        'bank_transfer' => 72 * 60,
        'payhere' => 30,
        'dummy' => 30,
    ];

    public const METHOD_BANK_TRANSFER = 'bank_transfer';

    public const METHOD_PAYHERE = 'payhere';

    public const METHOD_DUMMY = 'dummy';

    /**
     * Payment method => the `payments.gateway` value its attempts are recorded under.
     */
    public const METHOD_GATEWAYS = [
        self::METHOD_BANK_TRANSFER => Payment::GATEWAY_MANUAL_BANK_TRANSFER,
        self::METHOD_PAYHERE => Payment::GATEWAY_PAYHERE,
        self::METHOD_DUMMY => Payment::GATEWAY_DUMMY,
    ];

    /**
     * Statuses after payment was verified, for which a receipt exists.
     */
    public const PAID_STATUSES = [
        self::STATUS_PAID,
        self::STATUS_PROCESSING,
        self::STATUS_PACKED,
        self::STATUS_SHIPPED,
        self::STATUS_DELIVERED,
    ];

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
        self::STATUS_EXPIRED,
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
        self::STATUS_EXPIRED => [],
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
        'payment_method',
        'delivery_address_line1',
        'delivery_address_line2',
        'delivery_city',
        'delivery_postcode',
        'delivery_country',
        'subtotal_amount',
        'delivery_charge_amount',
        'expires_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'total_amount' => 'decimal:2',
            'subtotal_amount' => 'decimal:2',
            'delivery_charge_amount' => 'decimal:2',
            'expires_at' => 'datetime',
            'is_test' => 'boolean',
            'stock_released_at' => 'datetime',
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

    /**
     * @return HasMany<PaymentReview, $this>
     */
    public function paymentReviews(): HasMany
    {
        return $this->hasMany(PaymentReview::class);
    }

    /**
     * @return HasMany<PaymentRefund, $this>
     */
    public function refunds(): HasMany
    {
        return $this->hasMany(PaymentRefund::class);
    }

    public function canTransitionTo(string $status): bool
    {
        return in_array($status, self::TRANSITIONS[$this->status] ?? [], true);
    }

    public function latestPayment(): ?Payment
    {
        return $this->payments()->latest('id')->first();
    }

    /**
     * Whether the customer may still start or complete a payment: awaiting
     * payment and not past its deadline (even before the scheduled expiry
     * has run).
     */
    public function isPayable(): bool
    {
        return $this->status === self::STATUS_PENDING_PAYMENT
            && ($this->expires_at === null || $this->expires_at->isFuture());
    }

    /**
     * The payment deadline for a method, measured from the order's creation.
     */
    public function paymentDeadlineFor(string $method): Carbon
    {
        return $this->created_at->copy()->addMinutes(self::PAYMENT_WINDOW_MINUTES[$method]);
    }

    /**
     * Whether refunds are recorded and no received payment remains unrefunded.
     */
    public function isFullyRefunded(): bool
    {
        return $this->refunds()->exists()
            && ! $this->payments()->whereIn('status', [Payment::STATUS_PAID, Payment::STATUS_PARTIALLY_REFUNDED])->exists();
    }

    public function hasReceipt(): bool
    {
        return in_array($this->status, self::PAID_STATUSES, true);
    }

    public function paymentMethodLabel(): string
    {
        return match ($this->payment_method) {
            self::METHOD_BANK_TRANSFER => 'Bank transfer',
            self::METHOD_PAYHERE => 'PayHere (card / online)',
            self::METHOD_DUMMY => 'Dummy / Test Payment — Development Only',
            default => 'Not recorded',
        };
    }

    /**
     * The delivery address as display lines, or an empty list for orders
     * placed before addresses were collected.
     *
     * @return list<string>
     */
    public function deliveryAddressLines(): array
    {
        if ($this->delivery_address_line1 === null) {
            return [];
        }

        return array_values(array_filter([
            $this->delivery_address_line1,
            $this->delivery_address_line2,
            trim($this->delivery_city.' '.$this->delivery_postcode),
            config('payments.delivery_countries.'.$this->delivery_country, $this->delivery_country),
        ], fn (?string $line): bool => $line !== null && $line !== ''));
    }
}
