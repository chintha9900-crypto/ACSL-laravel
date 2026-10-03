{{--
    E-Shop Step 7 — server-side receipt for a paid order (no email receipts
    yet). Every value below comes from `orders`/`order_items`' own stored
    columns, never a live `Product` lookup, so this renders identically
    even if the underlying product is later renamed, repriced or deleted.
--}}
<x-layouts.public title="Receipt">
    <section class="border-t border-border">
        <div class="container mx-auto max-w-2xl px-4 py-12 lg:px-8">
            <h1 class="font-display text-3xl font-bold text-primary md:text-4xl">Receipt</h1>
            <p class="mt-2 text-muted-foreground">Order <span class="font-mono font-semibold text-foreground">{{ $order->order_number }}</span></p>

            <div class="ui-card mt-6 p-6 md:p-8">
                <dl class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <dt class="text-xs font-semibold uppercase tracking-wide text-muted-foreground">Order date</dt>
                        <dd class="mt-1">{{ $order->created_at->format('j M Y, g:ia') }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs font-semibold uppercase tracking-wide text-muted-foreground">Payment status</dt>
                        <dd class="mt-1">
                            <span class="inline-flex items-center rounded-md border border-transparent bg-primary px-2.5 py-0.5 text-xs font-semibold capitalize text-primary-foreground">{{ $payment?->status ?? 'paid' }}</span>
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs font-semibold uppercase tracking-wide text-muted-foreground">Customer name</dt>
                        <dd class="mt-1">{{ $order->customer_name }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs font-semibold uppercase tracking-wide text-muted-foreground">Customer email</dt>
                        <dd class="mt-1">{{ $order->customer_email }}</dd>
                    </div>
                    @if ($order->customer_phone)
                        <div>
                            <dt class="text-xs font-semibold uppercase tracking-wide text-muted-foreground">Customer phone</dt>
                            <dd class="mt-1">{{ $order->customer_phone }}</dd>
                        </div>
                    @endif
                </dl>
            </div>

            <div class="ui-card mt-6 overflow-x-auto">
                <table class="w-full min-w-[32rem] text-left text-sm">
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
                                <td class="px-4 py-3">LKR {{ number_format((float) $item->unit_price, 2) }}</td>
                                <td class="px-4 py-3 font-semibold">LKR {{ number_format((float) $item->line_total, 2) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                    <tfoot>
                        <tr class="border-t border-border">
                            <td colspan="4" class="px-4 py-3 text-right font-semibold">Order total</td>
                            <td class="px-4 py-3 font-display text-lg font-bold text-primary">LKR {{ number_format((float) $order->total_amount, 2) }}</td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
    </section>
</x-layouts.public>
