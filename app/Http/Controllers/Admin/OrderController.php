<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Orders\UpdateOrderStatus;
use App\Exceptions\InvalidOrderStatusTransitionException;
use App\Http\Controllers\Controller;
use App\Models\Order;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

/**
 * E-Shop Step 9 — admin order management only: listing/search/filter and a
 * single, validated fulfilment-status transition. No payment gateway, no
 * inventory changes, no editing of historical order-item snapshots —
 * nothing here writes to `order_items`, `payments`, or `Inventory` at all.
 */
class OrderController extends Controller
{
    /**
     * Every order regardless of status — "paid only"/"pending only" is a
     * filter the admin applies, not a default scope.
     */
    public function index(Request $request): View
    {
        Gate::authorize('viewAny', Order::class);

        $search = trim((string) $request->query('search', ''));
        $status = $request->query('status');
        $status = in_array($status, Order::STATUSES, true) ? $status : null;
        $paymentStatus = $request->query('payment_status');

        $orders = Order::query()
            ->select(['id', 'public_id', 'order_number', 'user_id', 'customer_name', 'customer_email', 'status', 'currency', 'total_amount', 'created_at'])
            ->with('payments:id,order_id,status')
            ->when($search !== '', function ($query) use ($search) {
                $query->where(function ($query) use ($search) {
                    $query->where('order_number', 'like', "%{$search}%")
                        ->orWhere('customer_name', 'like', "%{$search}%")
                        ->orWhere('customer_email', 'like', "%{$search}%");
                });
            })
            ->when($status, fn ($query) => $query->where('status', $status))
            ->when($paymentStatus, fn ($query) => $query->whereHas('payments', fn ($query) => $query->where('status', $paymentStatus)))
            ->orderByDesc('created_at')
            ->paginate(15)
            ->withQueryString();

        return view('admin.orders.index', [
            'orders' => $orders,
            'search' => $search,
            'status' => $status,
            'paymentStatus' => $paymentStatus,
            'statuses' => Order::STATUSES,
        ]);
    }

    public function show(Order $order): View
    {
        Gate::authorize('view', $order);

        $order->load(['items', 'payments']);

        return view('admin.orders.show', [
            'order' => $order,
            'availableTransitions' => Order::TRANSITIONS[$order->status] ?? [],
        ]);
    }

    public function updateStatus(Request $request, Order $order, UpdateOrderStatus $updateOrderStatus): RedirectResponse
    {
        Gate::authorize('update', $order);

        $validated = $request->validate([
            'status' => ['required', 'string', 'in:'.implode(',', Order::STATUSES)],
        ]);

        try {
            $updateOrderStatus->handle($order, $validated['status']);
        } catch (InvalidOrderStatusTransitionException $e) {
            return back()->withErrors(['status' => $e->getMessage()]);
        }

        return redirect()->route('admin.orders.show', $order)->with('status', 'Order status updated.');
    }
}
