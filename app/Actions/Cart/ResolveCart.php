<?php

namespace App\Actions\Cart;

use App\Models\Cart;
use App\Models\User;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Str;

/**
 * The current cart for this request: an authenticated user's own cart
 * (`UNIQUE(user_id)` — one per member), or a guest's cart identified by a
 * long-lived `guest_token` cookie (docs/database/12_ECOMMERCE_SCHEMA.md
 * §3.1's "guest checkout" cart architecture). A guest with no cookie yet
 * gets a brand new token, queued onto the response so it persists across
 * requests — this step does not merge a pre-login guest cart into an
 * account's cart on sign-in.
 */
class ResolveCart
{
    public const COOKIE_NAME = 'cart_guest_token';

    private const COOKIE_MINUTES = 60 * 24 * 30;

    public function resolve(?User $user, ?string $guestToken): Cart
    {
        if ($user !== null) {
            return Cart::query()->firstOrCreate(['user_id' => $user->id]);
        }

        if ($guestToken !== null) {
            $cart = Cart::query()->where('guest_token', $guestToken)->first();

            if ($cart !== null) {
                return $cart;
            }
        }

        $newToken = Str::random(40);
        Cookie::queue(self::COOKIE_NAME, $newToken, self::COOKIE_MINUTES);

        return Cart::create(['guest_token' => $newToken]);
    }
}
