<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Orders\UpdateOrderStatus;
use App\Exceptions\InvalidOrderStatusTransitionException;
use App\Http\Controllers\Controller;
use App\Models\Order;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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
            ->select(['id', 'public_id', 'order_number', 'user_id', 'customer_name', 'customer_email', 'status', 'currency', 'total_amount', 'is_test', 'payment_method', 'created_at'])
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

        $order->load([
            'items',
            'payments' => fn ($query) => $query->orderBy('id'),
            'payments.evidence',
            'payments.reviewedBy:id,name',
            'payments.refunds.recordedBy:id,name',
            'paymentReviews' => fn ($query) => $query->orderBy('id'),
            'paymentReviews.resolvedBy:id,name',
        ]);

        // The order's audit trail: its own events and those of every payment
        // attempt, review item and recorded refund.
        $history = DB::table('audit_logs')
            ->leftJoin('users', 'users.id', '=', 'audit_logs.user_id')
            ->where(fn ($query) => $query
                ->where(fn ($query) => $query->where('subject_type', 'order')->where('subject_id', $order->id))
                ->orWhere(fn ($query) => $query->where('subject_type', 'payment')->whereIn('subject_id', $order->payments->pluck('id')))
                ->orWhere(fn ($query) => $query->where('subject_type', 'payment_review')->whereIn('subject_id', $order->paymentReviews->pluck('id')))
                ->orWhere(fn ($query) => $query->where('subject_type', 'payment_refund')->whereIn('subject_id', $order->payments->flatMap->refunds->pluck('id'))))
            ->orderBy('audit_logs.id')
            ->get(['audit_logs.event', 'audit_logs.actor_type', 'audit_logs.new_values', 'audit_logs.created_at', 'users.name as actor_name']);

        return view('admin.orders.show', [
            'order' => $order,
            'history' => $history,
            // "Refunded" is offered only once refunds are recorded in full.
            'availableTransitions' => array_values(array_filter(
                Order::TRANSITIONS[$order->status] ?? [],
                fn (string $status): bool => $status !== Order::STATUS_REFUNDED || $order->isFullyRefunded(),
            )),
        ]);
    }

    public function updateStatus(Request $request, Order $order, UpdateOrderStatus $updateOrderStatus): RedirectResponse
    {
        Gate::authorize('update', $order);

        $validated = $request->validate([
            'status' => ['required', 'string', 'in:'.implode(',', Order::STATUSES)],
        ]);

        try {
            $updateOrderStatus->handle($order, $validated['status'], $request->user());
        } catch (InvalidOrderStatusTransitionException $e) {
            return back()->withErrors(['status' => $e->getMessage()]);
        }

        return redirect()->route('admin.orders.show', $order)->with('status', 'Order status updated.');
    }
}
