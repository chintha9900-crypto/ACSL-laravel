<?php

namespace App\Http\Controllers;

use App\Actions\Cart\AddCartItem;
use App\Actions\Cart\ResolveCart;
use App\Actions\Cart\UpdateCartItemQuantity;
use App\Exceptions\CartItemRejectedException;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Product;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * E-Shop Step 5 — cart only (no checkout/orders/payment). No auth
 * middleware: guests may have and use a cart, identified by
 * `ResolveCart::COOKIE_NAME` instead of a session login.
 */
class CartController extends Controller
{
    public function __construct(private readonly ResolveCart $resolveCart) {}

    public function show(Request $request): View
    {
        $cart = $this->resolveCart->resolve($request->user(), $request->cookie(ResolveCart::COOKIE_NAME));
        $cart->load(['items.product.inventory']);

        return view('cart.show', ['cart' => $cart]);
    }

    public function store(Request $request, AddCartItem $addItem): RedirectResponse
    {
        $validated = $request->validate([
            'product_id' => ['required', 'integer', 'exists:products,id'],
            'quantity' => ['required', 'integer', 'min:1'],
        ]);

        $cart = $this->resolveCart->resolve($request->user(), $request->cookie(ResolveCart::COOKIE_NAME));
        $product = Product::query()->findOrFail($validated['product_id']);

        try {
            $addItem->handle($cart, $product, $request->user(), $validated['quantity']);
        } catch (CartItemRejectedException $e) {
            return back()->withErrors(['product_id' => $e->getMessage()]);
        }

        return redirect()->route('cart.show')->with('status', 'Added to cart.');
    }

    public function update(Request $request, CartItem $item, UpdateCartItemQuantity $updateQuantity): RedirectResponse
    {
        $cart = $this->resolveCart->resolve($request->user(), $request->cookie(ResolveCart::COOKIE_NAME));
        $this->ensureOwnership($cart, $item);

        $validated = $request->validate([
            'quantity' => ['required', 'integer', 'min:1'],
        ]);

        try {
            $updateQuantity->handle($item, $validated['quantity'], $request->user());
        } catch (CartItemRejectedException $e) {
            return back()->withErrors(['quantity' => $e->getMessage()]);
        }

        return redirect()->route('cart.show')->with('status', 'Cart updated.');
    }

    public function destroy(Request $request, CartItem $item): RedirectResponse
    {
        $cart = $this->resolveCart->resolve($request->user(), $request->cookie(ResolveCart::COOKIE_NAME));
        $this->ensureOwnership($cart, $item);

        $item->delete();

        return redirect()->route('cart.show')->with('status', 'Item removed.');
    }

    public function clear(Request $request): RedirectResponse
    {
        $cart = $this->resolveCart->resolve($request->user(), $request->cookie(ResolveCart::COOKIE_NAME));
        $cart->items()->delete();

        return redirect()->route('cart.show')->with('status', 'Cart emptied.');
    }

    /**
     * A cart item never resolves across carts — attempting to touch someone
     * else's item 404s exactly like a non-existent one, never a different
     * response that would confirm it exists under another cart.
     */
    private function ensureOwnership(Cart $cart, CartItem $item): void
    {
        if ($item->cart_id !== $cart->id) {
            abort(404);
        }
    }
}
