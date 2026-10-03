<x-layouts.admin title="Orders">
    <h1 class="font-display text-2xl font-bold text-primary md:text-3xl">Orders</h1>

    <form method="GET" action="{{ route('admin.orders.index') }}" class="mt-4 flex flex-wrap items-end gap-3">
        <div class="space-y-1.5">
            <label for="search" class="block text-sm font-medium leading-none">Search</label>
            <input type="text" id="search" name="search" value="{{ $search }}" placeholder="Order number, name or email" class="field-control h-9 w-64">
        </div>

        <div class="space-y-1.5">
            <label for="status" class="block text-sm font-medium leading-none">Order status</label>
            <select id="status" name="status" class="field-control h-9">
                <option value="">All</option>
                @foreach ($statuses as $value)
                    <option value="{{ $value }}" @selected($status === $value)>{{ str_replace('_', ' ', ucfirst($value)) }}</option>
                @endforeach
            </select>
        </div>

        <div class="space-y-1.5">
            <label for="payment_status" class="block text-sm font-medium leading-none">Payment status</label>
            <select id="payment_status" name="payment_status" class="field-control h-9">
                <option value="">All</option>
                @foreach (['pending', 'processing', 'paid', 'failed', 'cancelled', 'refunded', 'partially_refunded'] as $value)
                    <option value="{{ $value }}" @selected($paymentStatus === $value)>{{ ucfirst($value) }}</option>
                @endforeach
            </select>
        </div>

        <button type="submit" class="btn btn-sm btn-brand">Filter</button>
        @if ($search !== '' || $status || $paymentStatus)
            <a href="{{ route('admin.orders.index') }}" class="btn btn-sm border border-input bg-background shadow-sm hover:bg-accent hover:text-accent-foreground">Clear</a>
        @endif
    </form>

    <div class="ui-card mt-6 overflow-x-auto">
        <table class="w-full min-w-[56rem] text-left text-sm">
            <thead class="border-b border-border text-xs uppercase tracking-wide text-muted-foreground">
                <tr>
                    <th scope="col" class="px-4 py-3 font-medium">Order number</th>
                    <th scope="col" class="px-4 py-3 font-medium">Customer</th>
                    <th scope="col" class="px-4 py-3 font-medium">Date</th>
                    <th scope="col" class="px-4 py-3 font-medium">Total</th>
                    <th scope="col" class="px-4 py-3 font-medium">Order status</th>
                    <th scope="col" class="px-4 py-3 font-medium">Payment status</th>
                    <th scope="col" class="px-4 py-3 font-medium">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-border">
                @forelse ($orders as $order)
                    @php($latestPayment = $order->payments->sortByDesc('id')->first())
                    <tr>
                        <td class="px-4 py-3">
                            <a href="{{ route('admin.orders.show', $order) }}" class="font-mono text-xs font-semibold text-primary underline underline-offset-2 hover:text-secondary">{{ $order->order_number }}</a>
                        </td>
                        <td class="px-4 py-3">
                            <div>{{ $order->customer_name }}</div>
                            <div class="text-xs text-muted-foreground">{{ $order->customer_email }}</div>
                        </td>
                        <td class="px-4 py-3 whitespace-nowrap">{{ $order->created_at->format('j M Y') }}</td>
                        <td class="px-4 py-3">{{ $order->currency }} {{ number_format((float) $order->total_amount, 2) }}</td>
                        <td class="px-4 py-3">
                            <span class="inline-flex items-center rounded-md border px-2.5 py-0.5 text-xs font-semibold capitalize {{ $order->status === 'paid' ? 'border-transparent bg-primary text-primary-foreground' : 'border-border bg-muted text-foreground' }}">
                                {{ str_replace('_', ' ', $order->status) }}
                            </span>
                        </td>
                        <td class="px-4 py-3">
                            <span class="inline-flex items-center rounded-md border border-border bg-muted px-2.5 py-0.5 text-xs font-semibold capitalize text-foreground">
                                {{ $latestPayment?->status ?? 'pending' }}
                            </span>
                        </td>
                        <td class="px-4 py-3">
                            <a href="{{ route('admin.orders.show', $order) }}" class="btn btn-sm border border-input bg-background shadow-sm hover:bg-accent hover:text-accent-foreground">View</a>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" class="px-4 py-8 text-center text-muted-foreground">No orders found.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-6">{{ $orders->links() }}</div>
</x-layouts.admin>
