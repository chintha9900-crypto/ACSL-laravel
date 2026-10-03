<?php

namespace App\Http\Controllers;

use App\Actions\Cart\ResolveCart;
use App\Actions\Checkout\PlaceOrder;
use App\Exceptions\CheckoutFailedException;
use App\Http\Controllers\Concerns\AuthorizesOrderAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * E-Shop Step 6/7 — checkout, order creation and the hand-off into payment
 * (no real payment gateway, no payment callbacks/webhooks, no email
 * receipts yet). No auth middleware: guests may check out `PUBLIC`
 * products, exactly as they may add them to a cart.
 */
class CheckoutController extends Controller
{
    use AuthorizesOrderAccess;

    public function __construct(private readonly ResolveCart $resolveCart) {}

    public function show(Request $request): View|RedirectResponse
    {
        $cart = $this->resolveCart->resolve($request->user(), $request->cookie(ResolveCart::COOKIE_NAME));
        $cart->load(['items.product.inventory']);

        if ($cart->items->isEmpty()) {
            return redirect()->route('cart.show')->with('status', 'Your cart is empty.');
        }

        return view('checkout.show', [
            'cart' => $cart,
            'user' => $request->user(),
        ]);
    }

    public function store(Request $request, PlaceOrder $placeOrder): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'email' => ['required', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:40'],
        ]);

        $cart = $this->resolveCart->resolve($request->user(), $request->cookie(ResolveCart::COOKIE_NAME));

        try {
            $order = $placeOrder->handle($cart, $request->user(), $validated);
        } catch (CheckoutFailedException $e) {
            return back()->withErrors(['cart' => $e->getMessage()])->withInput();
        }

        return redirect()->to($this->orderRouteUrl($order, 'payment.show'));
    }
}
