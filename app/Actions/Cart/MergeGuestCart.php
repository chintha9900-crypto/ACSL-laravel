<?php

namespace App\Actions\Cart;

use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Inventory;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Runs once, right after a guest with an existing cart authenticates
 * (`App\Listeners\MergeGuestCartOnLogin`). Each guest line is revalidated
 * exactly like an ordinary cart mutation (access, active status, stock) —
 * an item that still qualifies is merged into the member's own cart,
 * combined with any existing line for that product and capped at available
 * stock (never dropped just for being capped); an item that no longer
 * qualifies (inactive, now member-only and the account isn't active, or no
 * stock left at all) is the only kind that is dropped. The guest cart is
 * deleted only after a successful merge.
 */
class MergeGuestCart
{
    public function handle(User $user, string $guestToken): void
    {
        $guestCart = Cart::query()->where('guest_token', $guestToken)->first();

        if ($guestCart === null) {
            return;
        }

        DB::transaction(function () use ($user, $guestCart): void {
            $memberCart = Cart::query()->firstOrCreate(['user_id' => $user->id]);

            $guestItems = CartItem::query()->where('cart_id', $guestCart->id)->lockForUpdate()->get();

            foreach ($guestItems as $guestItem) {
                $this->mergeOne($memberCart, $guestItem, $user);
            }

            $guestCart->items()->delete();
            $guestCart->delete();
        });
    }

    private function mergeOne(Cart $memberCart, CartItem $guestItem, User $user): void
    {
        $product = Product::query()->lockForUpdate()->find($guestItem->product_id);

        if ($product === null || ! $product->is_active || ! $product->isAccessibleTo($user)) {
            return;
        }

        $available = Inventory::query()->where('product_id', $product->id)->lockForUpdate()->first()?->quantity ?? 0;

        $existing = CartItem::query()
            ->where('cart_id', $memberCart->id)
            ->where('product_id', $product->id)
            ->first();

        $combined = $guestItem->quantity + ($existing?->quantity ?? 0);
        $finalQuantity = min($combined, $available);

        if ($finalQuantity <= 0) {
            return;
        }

        if ($existing !== null) {
            $existing->update(['quantity' => $finalQuantity]);
        } else {
            CartItem::create([
                'cart_id' => $memberCart->id,
                'product_id' => $product->id,
                'quantity' => $finalQuantity,
            ]);
        }
    }
}
