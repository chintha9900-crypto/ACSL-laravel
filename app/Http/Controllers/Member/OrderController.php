<?php

namespace App\Http\Controllers\Member;

use App\Http\Controllers\Controller;
use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\View\View;

class OrderController extends Controller
{
    /**
     * The signed-in member's own orders only — ownership comes solely from
     * the authenticated user's id; the route accepts no other identifier
     * at all, so there is nothing to change in a URL to reach anyone
     * else's orders. Each row links to the existing `orders.show`/
     * `orders.receipt` pages, which already admit an authenticated owner
     * without needing a signature.
     */
    public function index(Request $request): View
    {
        $orders = Order::query()
            ->where('user_id', $request->user()->id)
            ->with('payments:id,order_id,status')
            ->orderByDesc('created_at')
            ->paginate(15);

        return view('member.orders.index', ['orders' => $orders]);
    }
}
