{{--
    E-Shop Step 9 — admin order detail + a single, validated fulfilment-
    status transition. Everything shown here (customer details, items,
    SKU/unit price/quantity/line totals, order total) comes straight from
    `orders`/`order_items`' own stored columns — this page has no form
    field anywhere that could edit any of them, and the status form below
    only ever submits `status`, nothing else. There is deliberately no way
    to mark the order `paid` from here — `pending_payment` has no such
    option in `$availableTransitions` at all, and payment status (shown
    read-only) is never written by this page.
--}}
<x-layouts.admin :title="'Order '.$order->order_number">
    <div class="flex flex-wrap items-center justify-between gap-4">
        <div>
            <h1 class="font-display text-2xl font-bold text-primary md:text-3xl">Order {{ $order->order_number }}</h1>
            <p class="mt-1 text-sm text-muted-foreground">Placed {{ $order->created_at->format('j F Y, g:ia') }}</p>
        </div>
        <a href="{{ route('admin.orders.index') }}" class="btn btn-sm border border-input bg-background shadow-sm hover:bg-accent hover:text-accent-foreground">Back to orders</a>
    </div>

    @if ($errors->any())
        <div class="mt-4 w-full rounded-lg border border-[#CC001F]/50 bg-[#CC001F]/5 px-4 py-3 text-sm text-[#CC001F]" role="alert">
            @foreach ($errors->all() as $message)
                <p>{{ $message }}</p>
            @endforeach
        </div>
    @endif

    <div class="mt-6 grid gap-6 lg:grid-cols-3">
        <div class="ui-card p-6 md:p-7">
            <h2 class="font-display text-lg font-bold text-primary">Customer</h2>
            <dl class="mt-3 space-y-2 text-sm">
                <div><dt class="text-xs font-semibold uppercase tracking-wide text-muted-foreground">Name</dt><dd>{{ $order->customer_name }}</dd></div>
                <div><dt class="text-xs font-semibold uppercase tracking-wide text-muted-foreground">Email</dt><dd>{{ $order->customer_email }}</dd></div>
                @if ($order->customer_phone)
                    <div><dt class="text-xs font-semibold uppercase tracking-wide text-muted-foreground">Phone</dt><dd>{{ $order->customer_phone }}</dd></div>
                @endif
                <div><dt class="text-xs font-semibold uppercase tracking-wide text-muted-foreground">Account</dt><dd>{{ $order->user_id ? 'Member account' : 'Guest checkout' }}</dd></div>
            </dl>
        </div>

        <div class="ui-card p-6 md:p-7">
            <h2 class="font-display text-lg font-bold text-primary">Status</h2>
            <dl class="mt-3 space-y-2 text-sm">
                <div>
                    <dt class="text-xs font-semibold uppercase tracking-wide text-muted-foreground">Order status</dt>
                    <dd>
                        <span class="inline-flex items-center rounded-md border px-2.5 py-0.5 text-xs font-semibold capitalize {{ $order->status === 'paid' ? 'border-transparent bg-primary text-primary-foreground' : 'border-border bg-muted text-foreground' }}">
                            {{ str_replace('_', ' ', $order->status) }}
                        </span>
                    </dd>
                </div>
                <div>
                    <dt class="text-xs font-semibold uppercase tracking-wide text-muted-foreground">Payment status</dt>
                    <dd>
                        @php($latestPayment = $order->payments->sortByDesc('id')->first())
                        <span class="inline-flex items-center rounded-md border border-border bg-muted px-2.5 py-0.5 text-xs font-semibold capitalize text-foreground">
                            {{ $latestPayment?->status ?? 'pending' }}
                        </span>
                        <p class="mt-1 text-xs text-muted-foreground">Payment status is set only by payment confirmation — it cannot be changed here.</p>
                    </dd>
                </div>
            </dl>

            @if ($availableTransitions !== [])
                <form method="POST" action="{{ route('admin.orders.update-status', $order) }}" class="mt-4 flex items-center gap-2">
                    @csrf
                    @method('PATCH')
                    <select name="status" class="field-control h-9">
                        @foreach ($availableTransitions as $next)
                            <option value="{{ $next }}">{{ str_replace('_', ' ', ucfirst($next)) }}</option>
                        @endforeach
                    </select>
                    <button type="submit" class="btn btn-sm btn-brand">Update status</button>
                </form>
            @else
                <p class="mt-4 text-xs text-muted-foreground">This order's status is final — no further transitions are available.</p>
            @endif
        </div>

        <div class="ui-card p-6 md:p-7">
            <h2 class="font-display text-lg font-bold text-primary">Total</h2>
            <p class="mt-3 font-display text-2xl font-bold text-primary">{{ $order->currency }} {{ number_format((float) $order->total_amount, 2) }}</p>
        </div>
    </div>

    <div class="ui-card mt-6 overflow-x-auto">
        <table class="w-full min-w-[40rem] text-left text-sm">
            <thead class="border-b border-border text-xs uppercase tracking-wide text-muted-foreground">
                <tr>
                    <th scope="col" class="px-4 py-3 font-medium">Product</th>
                    <th scope="col" class="px-4 py-3 font-medium">SKU</th>
                    <th scope="col" class="px-4 py-3 font-medium">Quantity</th>
                    <th scope="col" class="px-4 py-3 font-medium">Unit price</th>
                    <th scope="col" class="px-4 py-3 font-medium">Line total</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-border">
                @foreach ($order->items as $item)
                    <tr>
                        <td class="px-4 py-3">{{ $item->product_name }}</td>
                        <td class="px-4 py-3 font-mono text-xs text-muted-foreground">{{ $item->sku }}</td>
                        <td class="px-4 py-3">{{ $item->quantity }}</td>
                        <td class="px-4 py-3">{{ $order->currency }} {{ number_format((float) $item->unit_price, 2) }}</td>
                        <td class="px-4 py-3 font-semibold">{{ $order->currency }} {{ number_format((float) $item->line_total, 2) }}</td>
                    </tr>
                @endforeach
            </tbody>
            <tfoot>
                <tr class="border-t border-border">
                    <td colspan="4" class="px-4 py-3 text-right font-semibold">Order total</td>
                    <td class="px-4 py-3 font-display text-lg font-bold text-primary">{{ $order->currency }} {{ number_format((float) $order->total_amount, 2) }}</td>
                </tr>
            </tfoot>
        </table>
    </div>
</x-layouts.admin>
