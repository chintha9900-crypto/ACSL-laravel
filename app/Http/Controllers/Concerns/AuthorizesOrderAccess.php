<?php

namespace App\Http\Controllers\Concerns;

use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;

/**
 * Shared by every controller that lets a customer reach their own order
 * (`OrderController`, `PaymentController`, `ReceiptController`): an
 * authenticated owner may access it directly; a guest order has no account
 * to check ownership against, so a guest's copy of the link must carry a
 * valid signature instead (`CheckoutController::confirmationUrl()`). A bare,
 * guessed `public_id` is never enough either way, so one customer can never
 * reach another's order, payment or receipt.
 */
trait AuthorizesOrderAccess
{
    protected function authorizeOrderAccess(Request $request, Order $order): void
    {
        $user = $request->user();

        if ($order->user_id !== null) {
            if ($user === null || $user->id !== $order->user_id) {
                abort(403);
            }

            return;
        }

        if (! $request->hasValidSignature()) {
            abort(403);
        }
    }

    /**
     * A link to another order-scoped page (payment, receipt, the order
     * itself) that stays valid for whoever is viewing this one — a signed
     * URL for a guest order (each route needs its own signature; one
     * route's signature does not validate another's), a plain route for an
     * authenticated owner.
     */
    protected function orderRouteUrl(Order $order, string $routeName): string
    {
        if ($order->user_id === null) {
            return URL::signedRoute($routeName, $order);
        }

        return route($routeName, $order);
    }
}
