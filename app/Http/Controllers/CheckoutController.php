<?php

namespace App\Http\Controllers;

use App\Actions\Cart\ResolveCart;
use App\Actions\Checkout\PlaceOrder;
use App\Actions\Orders\NotifyCustomer;
use App\Exceptions\CheckoutFailedException;
use App\Http\Controllers\Concerns\AuthorizesOrderAccess;
use App\Http\Requests\PlaceOrderRequest;
use App\Models\EshopSetting;
use App\Notifications\Orders\OrderPlaced;
use App\Payments\AvailablePaymentMethods;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Checkout, order creation and the hand-off into payment. No auth
 * middleware: guests may check out `PUBLIC` products, exactly as they may
 * add them to a cart. Only payment methods available for this request are
 * offered and accepted (AvailablePaymentMethods), and ordering is blocked
 * until an administrator has configured the delivery charge. The order
 * confirmation email carries the customer's (signed, for guests) link back
 * to the payment page.
 */
class CheckoutController extends Controller
{
    use AuthorizesOrderAccess;

    public function __construct(private readonly ResolveCart $resolveCart) {}

    public function show(Request $request, AvailablePaymentMethods $paymentMethods): View|RedirectResponse
    {
        $cart = $this->resolveCart->resolve($request->user(), $request->cookie(ResolveCart::COOKIE_NAME));
        $cart->load(['items.product.inventory']);

        if ($cart->items->isEmpty()) {
            return redirect()->route('cart.show')->with('status', 'Your cart is empty.');
        }

        $deliveryCharge = EshopSetting::deliveryCharge();
        $subtotalMinor = $cart->items->sum(fn ($item): int => Money::toMinor((string) $item->product->price) * $item->quantity);

        return view('checkout.show', [
            'cart' => $cart,
            'user' => $request->user(),
            'paymentMethods' => $deliveryCharge === null ? [] : $paymentMethods->for($request),
            'countries' => (array) config('payments.delivery_countries'),
            'deliveryCharge' => $deliveryCharge,
            'total' => $deliveryCharge === null ? null : Money::fromMinor($subtotalMinor + Money::toMinor($deliveryCharge)),
        ]);
    }

    public function store(PlaceOrderRequest $request, PlaceOrder $placeOrder, NotifyCustomer $notifyCustomer): RedirectResponse
    {
        $cart = $this->resolveCart->resolve($request->user(), $request->cookie(ResolveCart::COOKIE_NAME));

        try {
            $order = $placeOrder->handle($cart, $request->user(), $request->customer(), $request->validated('payment_method'));
        } catch (CheckoutFailedException $e) {
            return back()->withErrors(['cart' => $e->getMessage()])->withInput();
        }

        $notifyCustomer->handle($order, new OrderPlaced($order));

        return redirect()->to($this->orderRouteUrl($order, 'payment.show'));
    }
}
