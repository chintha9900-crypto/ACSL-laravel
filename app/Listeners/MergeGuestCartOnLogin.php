<?php

namespace App\Listeners;

use App\Actions\Cart\MergeGuestCart;
use App\Actions\Cart\ResolveCart;
use Illuminate\Auth\Events\Login;
use Illuminate\Support\Facades\Cookie;

/**
 * Fires on every successful authentication (`Auth::attempt()`/`Auth::login()`
 * dispatch `Login` regardless of which controller called them), so this
 * never needs the login controller itself to know anything about carts.
 */
class MergeGuestCartOnLogin
{
    public function __construct(private readonly MergeGuestCart $mergeGuestCart) {}

    public function handle(Login $event): void
    {
        $token = request()->cookie(ResolveCart::COOKIE_NAME);

        if ($token === null) {
            return;
        }

        $this->mergeGuestCart->handle($event->user, $token);

        Cookie::queue(Cookie::forget(ResolveCart::COOKIE_NAME));
    }
}
