{{--
    E-Shop Step 8 — member order history only. `$orders` is already scoped
    to the signed-in member's own `user_id` in the controller — nothing
    here accepts or displays a value that could point at someone else's
    order; row links go to the existing `orders.show`/`orders.receipt`
    pages, which admit an authenticated owner directly, no signature
    needed.
--}}
<x-layouts.member :title="'Your Orders'">
    <h1 class="font-display text-2xl font-bold text-primary md:text-3xl">Your Orders</h1>

    <div class="ui-card mt-6 overflow-x-auto">
        <table class="w-full min-w-[48rem] text-left text-sm">
            <thead class="border-b border-border text-xs uppercase tracking-wide text-muted-foreground">
                <tr>
                    <th scope="col" class="px-4 py-3 font-medium">Order number</th>
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
                        <td class="px-4 py-3 font-mono text-xs">
                            <a href="{{ route('orders.show', $order) }}" class="font-semibold text-primary underline underline-offset-2 hover:text-secondary">{{ $order->order_number }}</a>
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
                            <div class="flex flex-wrap gap-2">
                                <a href="{{ route('orders.show', $order) }}" class="btn btn-sm border border-input bg-background shadow-sm hover:bg-accent hover:text-accent-foreground">View</a>
                                @if ($order->status === 'paid')
                                    <a href="{{ route('orders.receipt', $order) }}" class="btn btn-sm border border-input bg-background shadow-sm hover:bg-accent hover:text-accent-foreground">Receipt</a>
                                @endif
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="px-4 py-8 text-center text-muted-foreground">You haven't placed any orders yet.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-6">{{ $orders->links() }}</div>
</x-layouts.member>
