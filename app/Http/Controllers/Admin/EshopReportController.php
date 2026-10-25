<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class EshopReportController extends Controller
{
    /**
     * Read-only e-shop financial summary: order count and total value per order
     * status and currency, for a date range (orders placed, business timezone).
     * Purely a summary of existing order data — no revenue rule is assumed.
     * Same gate as order management (`orders.manage`: admin and editor).
     */
    public function show(Request $request): View
    {
        Gate::authorize('viewAny', Order::class);

        $validated = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);

        $timezone = (string) config('membership.business_timezone');
        $to = isset($validated['to']) ? Carbon::parse($validated['to'], $timezone) : now($timezone);
        $from = isset($validated['from']) ? Carbon::parse($validated['from'], $timezone) : $to->copy()->subDays(29);

        $rows = Order::query()
            ->whereBetween('created_at', [$from->copy()->startOfDay()->utc(), $to->copy()->endOfDay()->utc()])
            ->selectRaw('status, currency, COUNT(*) AS orders_count, SUM(total_amount) AS total_value')
            ->groupBy('status', 'currency')
            ->get()
            ->sortBy(fn (Order $row): int => (int) array_search($row->status, Order::STATUSES, true))
            ->values();

        return view('admin.reports.eshop', [
            'rows' => $rows,
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
        ]);
    }
}
