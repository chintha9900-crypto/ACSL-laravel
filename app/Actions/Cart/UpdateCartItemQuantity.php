<?php

namespace App\Actions\Cart;

use App\Exceptions\CartItemRejectedException;
use App\Models\CartItem;
use App\Models\Inventory;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Covers increase, decrease and "set to an exact value" alike — all three
 * are just a new absolute quantity from the caller's point of view. Access
 * and stock are revalidated here too: a product that became inactive,
 * member-only, or short on stock after it was first added must not be
 * increasable, even though removing it always stays allowed.
 */
class UpdateCartItemQuantity
{
    /**
     * @throws CartItemRejectedException
     */
    public function handle(CartItem $item, int $quantity, ?User $user): CartItem
    {
        return DB::transaction(function () use ($item, $quantity, $user): CartItem {
            $locked = CartItem::query()->lockForUpdate()->findOrFail($item->id);
            $product = $locked->product;

            if (! $product->is_active) {
                throw new CartItemRejectedException('This product is no longer available.');
            }

            if (! $product->isAccessibleTo($user)) {
                throw new CartItemRejectedException('This product is only available to active members.');
            }

            $available = Inventory::query()->where('product_id', $product->id)->lockForUpdate()->first()?->quantity ?? 0;

            if ($quantity > $available) {
                throw new CartItemRejectedException("Only {$available} in stock.");
            }

            $locked->update(['quantity' => $quantity]);

            return $locked;
        });
    }
}
