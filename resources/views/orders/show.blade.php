{{--
    Order confirmation. Every value here comes from the
    order's own stored snapshot (`order_items.product_name`/`sku`/
    `unit_price`), never a live product lookup — this page must still read
    correctly even if the underlying product is later renamed or removed.
--}}
<x-layouts.public title="Order Confirmation">
    <section class="border-t border-border">
        <div class="container mx-auto px-4 py-12 lg:px-8">
            <h1 class="font-display text-3xl font-bold text-primary md:text-4xl">Thank you for your order</h1>
            <p class="mt-2 text-muted-foreground">Order <span class="font-mono font-semibold text-foreground">{{ $order->order_number }}</span></p>

            @if ($order->is_test)
                <p class="mt-4 rounded-lg border border-[#CC001F]/50 bg-[#CC001F]/5 px-4 py-3 text-sm font-semibold text-[#CC001F]" role="note">TEST ORDER — Dummy / Test Payment, Development Only. No real payment is taken.</p>
            @endif

            <div class="ui-card mt-8 p-6 md:p-8">
                <dl class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <dt class="text-xs font-semibold uppercase tracking-wide text-muted-foreground">Status</dt>
                        <dd class="mt-1">
                            @if ($order->hasReceipt())
                                <span class="inline-flex items-center rounded-md border border-transparent bg-primary px-2.5 py-0.5 text-xs font-semibold capitalize text-primary-foreground">{{ $order->status === 'paid' ? 'Paid' : $order->status }}</span>
                            @elseif (in_array($order->status, ['cancelled', 'expired', 'refunded'], true))
                                <span class="inline-flex items-center rounded-md border border-border bg-muted px-2.5 py-0.5 text-xs font-semibold capitalize text-foreground">{{ $order->status }}</span>
                            @else
                                <span class="inline-flex items-center rounded-md border border-border bg-muted px-2.5 py-0.5 text-xs font-semibold text-foreground">Awaiting payment</span>
                            @endif
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs font-semibold uppercase tracking-wide text-muted-foreground">Total</dt>
                        <dd class="mt-1 font-display text-lg font-bold text-primary">LKR {{ number_format((float) $order->total_amount, 2) }}</dd>
                        @if ($order->delivery_charge_amount !== null)
                            <dd class="mt-1 text-xs text-muted-foreground">Includes delivery (Sri Lanka) LKR {{ $order->delivery_charge_amount }}</dd>
                        @endif
                    </div>
                    <div>
                        <dt class="text-xs font-semibold uppercase tracking-wide text-muted-foreground">Name</dt>
                        <dd class="mt-1">{{ $order->customer_name }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs font-semibold uppercase tracking-wide text-muted-foreground">Email</dt>
                        <dd class="mt-1">{{ $order->customer_email }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs font-semibold uppercase tracking-wide text-muted-foreground">Payment method</dt>
                        <dd class="mt-1">{{ $order->paymentMethodLabel() }}</dd>
                    </div>
                    @if ($order->deliveryAddressLines() !== [])
                        <div>
                            <dt class="text-xs font-semibold uppercase tracking-wide text-muted-foreground">Delivery address</dt>
                            <dd class="mt-1">
                                @foreach ($order->deliveryAddressLines() as $line)
                                    {{ $line }}<br>
                                @endforeach
                            </dd>
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
                </table>
            </div>

            @if ($order->hasReceipt())
                <p class="mt-6 text-sm text-muted-foreground">This order has been paid.</p>
                @if ($receiptUrl)
                    <a href="{{ $receiptUrl }}" class="btn btn-lg btn-brand mt-4">View Receipt</a>
                @endif
            @elseif ($order->status === 'cancelled')
                <p class="mt-6 text-sm text-muted-foreground">This order has been cancelled.</p>
            @elseif ($order->status === 'expired')
                <p class="mt-6 text-sm text-muted-foreground">This order expired because payment was not received before its deadline.</p>
            @elseif ($order->status === 'refunded')
                <p class="mt-6 text-sm text-muted-foreground">This order has been refunded.</p>
            @else
                <p class="mt-6 text-sm text-muted-foreground">This order is not paid yet.</p>
                <a href="{{ $paymentUrl }}" class="btn btn-lg btn-brand mt-4">Pay Now</a>
            @endif
        </div>
    </section>
</x-layouts.public>
