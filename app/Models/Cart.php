<?php

namespace App\Models;

use Database\Factories\CartFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Belongs to exactly one registered member (`user_id`) or one guest
 * (`guest_token`) — never neither, enforced by the `carts_user_or_guest`
 * CHECK constraint. Holds no prices: those are re-derived at checkout, which
 * this step does not build.
 */
class Cart extends Model
{
    /** @use HasFactory<CartFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'guest_token',
    ];

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasMany<CartItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(CartItem::class);
    }

    /**
     * Computed from each item's *live* product price — never a price stored
     * on the cart or supplied by a client — so it always reflects the
     * product's current price, the same "re-derived, never trusted" rule
     * documented for carts. Callers should eager-load `items.product` first
     * to avoid an N+1 query per item.
     */
    public function subtotal(): float
    {
        return (float) $this->items->sum(
            fn (CartItem $item) => $item->quantity * (float) ($item->product?->price ?? 0)
        );
    }
}
