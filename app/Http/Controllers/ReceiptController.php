<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\AuthorizesOrderAccess;
use App\Models\Order;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The server-side receipt for a successfully paid order (E-Shop Step 7).
 * Every value rendered here comes from the order's own stored columns and
 * its `order_items` snapshots — never a live product lookup — so the
 * receipt reads correctly even if the underlying product is later renamed,
 * repriced or removed.
 */
class ReceiptController extends Controller
{
    use AuthorizesOrderAccess;

    public function show(Request $request, Order $order): View|RedirectResponse
    {
        $this->authorizeOrderAccess($request, $order);

        if ($order->status !== Order::STATUS_PAID) {
            return redirect()->to($this->orderRouteUrl($order, 'payment.show'))
                ->with('status', 'This order is not paid yet — there is no receipt until payment is confirmed.');
        }

        $order->load('items');
        $payment = $order->payments()->where('status', 'paid')->latest('paid_at')->first();

        return view('receipts.show', ['order' => $order, 'payment' => $payment]);
    }
}
