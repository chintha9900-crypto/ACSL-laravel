<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\AuthorizesOrderAccess;
use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\View\View;

class OrderController extends Controller
{
    use AuthorizesOrderAccess;

    public function show(Request $request, Order $order): View
    {
        $this->authorizeOrderAccess($request, $order);

        $order->load('items');

        return view('orders.show', [
            'order' => $order,
            'paymentUrl' => $this->orderRouteUrl($order, 'payment.show'),
            'receiptUrl' => $order->status === Order::STATUS_PAID ? $this->orderRouteUrl($order, 'orders.receipt') : null,
        ]);
    }
}
