<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\AuthorizesOrderAccess;
use App\Models\Order;
use App\Payments\PaymentGatewayContract;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * E-Shop Step 7 — payment structure and confirmation only (no real gateway,
 * no webhooks, no email receipts). Confirm/fail/cancel here are the
 * placeholder provider's stand-in for a real gateway's callback — the
 * amount and order are always read from the database, never from the
 * request, and nothing here ever sets a payment or order status from
 * client input directly; only `PaymentGatewayContract` (bound to
 * `ManualPaymentGateway` today) decides outcomes, and it accepts no
 * client-supplied amount or status at all.
 */
class PaymentController extends Controller
{
    use AuthorizesOrderAccess;

    public function __construct(private readonly PaymentGatewayContract $gateway) {}

    public function show(Request $request, Order $order): View
    {
        $this->authorizeOrderAccess($request, $order);

        $order->load('items');
        $payment = $order->payments()->latest('id')->first();

        return view('payment.show', [
            'order' => $order,
            'payment' => $payment,
            'gatewayName' => $this->gateway->name(),
            'receiptUrl' => $order->status === Order::STATUS_PAID ? $this->orderRouteUrl($order, 'orders.receipt') : null,
        ]);
    }

    public function confirm(Request $request, Order $order): RedirectResponse
    {
        $this->authorizeOrderAccess($request, $order);

        $payment = $order->payments()->latest('id')->firstOrFail();
        $this->gateway->confirm($payment);

        return redirect()->to($this->orderRouteUrl($order, 'payment.show'))->with('status', 'Payment confirmed.');
    }

    public function fail(Request $request, Order $order): RedirectResponse
    {
        $this->authorizeOrderAccess($request, $order);

        $payment = $order->payments()->latest('id')->firstOrFail();
        $this->gateway->fail($payment, 'Simulated failure from the development payment placeholder.');

        return redirect()->to($this->orderRouteUrl($order, 'payment.show'))->with('status', 'Payment failed.');
    }

    public function cancel(Request $request, Order $order): RedirectResponse
    {
        $this->authorizeOrderAccess($request, $order);

        $payment = $order->payments()->latest('id')->firstOrFail();
        $this->gateway->cancel($payment);

        return redirect()->to($this->orderRouteUrl($order, 'payment.show'))->with('status', 'Payment cancelled.');
    }
}
