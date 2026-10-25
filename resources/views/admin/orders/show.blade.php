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
            @if ($order->is_test)
                <p class="mt-2 inline-flex rounded-md border border-[#CC001F]/50 bg-[#CC001F]/5 px-2.5 py-0.5 text-xs font-semibold text-[#CC001F]">TEST ORDER — simulated payment, no money taken</p>
            @endif
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
                <div>
                    <dt class="text-xs font-semibold uppercase tracking-wide text-muted-foreground">Delivery address</dt>
                    <dd>
                        @forelse ($order->deliveryAddressLines() as $line)
                            {{ $line }}<br>
                        @empty
                            <span class="text-muted-foreground">Not collected (order placed before delivery addresses were recorded)</span>
                        @endforelse
                    </dd>
                </div>
                <div><dt class="text-xs font-semibold uppercase tracking-wide text-muted-foreground">Payment method</dt><dd>{{ $order->paymentMethodLabel() }}</dd></div>
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
            <p class="mt-3 font-display text-2xl font-bold text-primary">{{ $order->currency }} {{ $order->total_amount }}</p>
            @if ($order->delivery_charge_amount !== null)
                <p class="mt-1 text-xs text-muted-foreground">Items {{ $order->currency }} {{ $order->subtotal_amount }} + delivery {{ $order->currency }} {{ $order->delivery_charge_amount }}</p>
            @endif
            @if ($order->expires_at && $order->status === 'pending_payment')
                <p class="mt-2 text-xs text-muted-foreground">Payment deadline: {{ $order->expires_at->copy()->setTimezone(config('membership.business_timezone'))->format('j M Y, g:ia') }} (Sri Lanka time)</p>
                @if ($order->expires_at->isPast() && $order->payments->contains('status', 'processing'))
                    <p class="mt-1 text-xs font-semibold text-[#CC001F]">Past deadline — not expired automatically because transfer evidence is under review.</p>
                @endif
            @endif
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

    <div class="ui-card mt-6 overflow-x-auto">
        <h2 class="px-4 pt-4 font-display text-lg font-bold text-primary">Payment attempts</h2>
        <table class="mt-2 w-full min-w-[48rem] text-left text-sm">
            <thead class="border-b border-border text-xs uppercase tracking-wide text-muted-foreground">
                <tr>
                    <th scope="col" class="px-4 py-3 font-medium">Attempt</th>
                    <th scope="col" class="px-4 py-3 font-medium">Method</th>
                    <th scope="col" class="px-4 py-3 font-medium">Status</th>
                    <th scope="col" class="px-4 py-3 font-medium">Reference</th>
                    <th scope="col" class="px-4 py-3 font-medium">Review</th>
                    <th scope="col" class="px-4 py-3 font-medium">Evidence</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-border">
                @forelse ($order->payments as $attempt)
                    <tr class="align-top">
                        <td class="px-4 py-3 font-mono text-xs">{{ $attempt->idempotency_key }}<br><span class="text-muted-foreground">{{ $attempt->created_at->format('j M Y, g:ia') }}</span></td>
                        <td class="px-4 py-3">{{ str_replace('_', ' ', $attempt->gateway) }}</td>
                        <td class="px-4 py-3 capitalize">{{ str_replace('_', ' ', $attempt->status) }}</td>
                        <td class="px-4 py-3 font-mono text-xs">{{ $attempt->transaction_reference ?? '—' }}</td>
                        <td class="px-4 py-3 text-xs">
                            @if ($attempt->reviewed_at)
                                {{ $attempt->reviewedBy?->name ?? 'Admin' }}, {{ $attempt->reviewed_at->format('j M Y, g:ia') }}
                                @if ($attempt->rejection_reason)<br>Reason: {{ $attempt->rejection_reason }}@endif
                            @else
                                —
                            @endif
                        </td>
                        <td class="px-4 py-3 text-xs">
                            @forelse ($attempt->evidence as $document)
                                @can('view', $document)
                                    <a href="{{ route('admin.documents.show', $document) }}" class="font-semibold text-[#CC001F] hover:underline">{{ $document->original_filename }}</a><br>
                                @else
                                    {{ $document->original_filename }}<br>
                                @endcan
                            @empty
                                —
                            @endforelse
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="px-4 py-3 text-muted-foreground">No payment attempts.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    @if ($order->paymentReviews->isNotEmpty())
        <div class="ui-card mt-6 p-6 md:p-7">
            <h2 class="font-display text-lg font-bold text-primary">Payment review items</h2>
            <ul class="mt-3 space-y-2 text-sm">
                @foreach ($order->paymentReviews as $review)
                    <li class="border-l-2 {{ $review->status === 'open' ? 'border-[#CC001F]' : 'border-border' }} pl-3">
                        <span class="font-semibold">{{ $review->typeLabel() }}</span> — {{ $review->status === 'open' ? 'open' : 'resolved by '.($review->resolvedBy?->name ?? 'unknown') }}
                        <span class="block text-muted-foreground">{{ $review->summary }}</span>
                        @if ($review->resolution_note)<span class="block text-xs">Decision: {{ $review->resolution_note }}</span>@endif
                    </li>
                @endforeach
            </ul>
            <a href="{{ route('admin.payment-reviews.index') }}" class="mt-3 inline-block text-sm font-semibold text-[#CC001F] hover:underline">Open the review queue</a>
        </div>
    @endif

    @php($settledAttempts = $order->payments->filter(fn ($attempt) => in_array($attempt->status, ['paid', 'partially_refunded', 'refunded'], true)))
    @if ($settledAttempts->isNotEmpty())
        <div class="ui-card mt-6 p-6 md:p-7">
            <h2 class="font-display text-lg font-bold text-primary">Refunds</h2>
            <p class="mt-1 text-xs text-muted-foreground">Make the refund in the PayHere merchant portal (or by bank) first, then record it here. Recording a refund never returns stock automatically.</p>
            @error('refund')
                <p class="mt-2 rounded-lg border border-destructive/50 px-3 py-2 text-sm text-destructive" role="alert">{{ $message }}</p>
            @enderror

            @foreach ($settledAttempts as $attempt)
                <div class="mt-4 border-t border-border pt-4">
                    <p class="text-sm font-semibold">Attempt <span class="font-mono">{{ $attempt->idempotency_key }}</span> — {{ $attempt->currency }} {{ $attempt->amount }} ({{ str_replace('_', ' ', $attempt->status) }})</p>
                    @foreach ($attempt->refunds as $refund)
                        <p class="mt-1 text-xs">Refunded {{ $refund->currency }} {{ $refund->amount }} on {{ $refund->refunded_on->format('j M Y') }} — reference <span class="font-mono">{{ $refund->refund_reference }}</span>, recorded by {{ $refund->recordedBy?->name ?? 'unknown' }}: {{ $refund->reason }}</p>
                    @endforeach

                    @if (in_array($attempt->status, ['paid', 'partially_refunded'], true))
                        @can('recordRefund', $attempt)
                            <details class="mt-2">
                                <summary class="btn btn-sm cursor-pointer border border-input bg-background shadow-sm">Record a refund</summary>
                                <form method="POST" action="{{ route('admin.payment-refunds.store', $attempt) }}" class="mt-3 grid gap-3 sm:grid-cols-2">
                                    @csrf
                                    <x-form.input name="amount" label="Amount refunded ({{ $attempt->currency }})" inputmode="decimal" maxlength="15" />
                                    <x-form.input name="refund_reference" label="PayHere / bank refund reference" maxlength="191" />
                                    <x-form.input name="refunded_on" label="Refunded on" type="date" :value="now(config('membership.business_timezone'))->toDateString()" />
                                    <x-form.input name="reason" label="Reason" maxlength="500" />
                                    <div class="sm:col-span-2"><button type="submit" class="btn btn-sm btn-brand">Record refund</button></div>
                                </form>
                            </details>
                        @endcan
                    @endif
                </div>
            @endforeach
        </div>
    @endif

    <div class="ui-card mt-6 p-6 md:p-7">
        <h2 class="font-display text-lg font-bold text-primary">History</h2>
        @if ($history->isEmpty())
            <p class="mt-2 text-sm text-muted-foreground">No recorded events.</p>
        @else
            <ol class="mt-3 space-y-2 text-sm">
                @foreach ($history as $entry)
                    <li class="border-l-2 border-border pl-3">
                        <span class="font-semibold">{{ str_replace(['.', '_'], ' ', $entry->event) }}</span>
                        <span class="text-muted-foreground">— {{ $entry->actor_name ?? ucfirst($entry->actor_type) }}, {{ \Illuminate\Support\Carbon::parse($entry->created_at)->format('j M Y, g:ia') }}</span>
                        @if ($entry->new_values)
                            <span class="block break-all font-mono text-xs text-muted-foreground">{{ $entry->new_values }}</span>
                        @endif
                    </li>
                @endforeach
            </ol>
        @endif
    </div>
</x-layouts.admin>
