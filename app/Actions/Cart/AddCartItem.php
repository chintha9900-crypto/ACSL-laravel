<?php

namespace App\Actions\Cart;

use App\Exceptions\CartItemRejectedException;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Inventory;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Access and stock are revalidated here every time, server-side — never
 * trusted from whatever the browser last rendered. The inventory row is
 * locked for the duration of the check-then-write so a concurrent add for
 * the same product can't both pass a stale availability check.
 */
class AddCartItem
{
    /**
     * @throws CartItemRejectedException
     */
    public function handle(Cart $cart, Product $product, ?User $user, int $quantity): CartItem
    {
        return DB::transaction(function () use ($cart, $product, $user, $quantity): CartItem {
            $this->assertAccessible($product, $user);

            $existing = CartItem::query()
                ->where('cart_id', $cart->id)
                ->where('product_id', $product->id)
                ->first();

            $requestedTotal = $quantity + ($existing?->quantity ?? 0);

            $this->assertWithinStock($product, $requestedTotal);

            if ($existing !== null) {
                $existing->update(['quantity' => $requestedTotal]);

                return $existing;
            }

            return CartItem::create([
                'cart_id' => $cart->id,
                'product_id' => $product->id,
                'quantity' => $quantity,
            ]);
        });
    }

    /**
     * @throws CartItemRejectedException
     */
    private function assertAccessible(Product $product, ?User $user): void
    {
        if (! $product->is_active) {
            throw new CartItemRejectedException('This product is no longer available.');
        }

        if (! $product->isAccessibleTo($user)) {
            throw new CartItemRejectedException('This product is only available to active members.');
        }
    }

    /**
     * @throws CartItemRejectedException
     */
    private function assertWithinStock(Product $product, int $requestedQuantity): void
    {
        $available = Inventory::query()->where('product_id', $product->id)->lockForUpdate()->first()?->quantity ?? 0;

        if ($requestedQuantity > $available) {
            throw new CartItemRejectedException("Only {$available} in stock.");
        }
    }
}
