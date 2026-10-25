{{--
    E-shop bank transfers awaiting verification. Separate from membership
    payment review: approving here confirms an order (and sends its receipt),
    never a membership renewal.
--}}
<x-layouts.admin :title="'Order payments'">
    <h1 class="font-display text-2xl font-bold text-primary md:text-3xl">Order bank transfers awaiting verification</h1>
    <p class="mt-1 text-sm text-muted-foreground">Check the transfer against the bank statement before approving. Approving marks the order paid and emails the receipt. Oldest first. Orders with evidence under review are never expired automatically, even after their payment deadline — decide them here.</p>

    @error('payment')
        <p class="mt-4 rounded-lg border border-destructive/50 px-3 py-2 text-sm text-destructive" role="alert">{{ $message }}</p>
    @enderror

    <div class="ui-card mt-6 overflow-x-auto">
        <table class="w-full min-w-[60rem] text-left text-sm">
            <thead class="border-b border-border text-xs uppercase tracking-wide text-muted-foreground">
                <tr>
                    <th scope="col" class="px-4 py-3 font-medium">Order</th>
                    <th scope="col" class="px-4 py-3 font-medium">Customer</th>
                    <th scope="col" class="px-4 py-3 font-medium">Amount</th>
                    <th scope="col" class="px-4 py-3 font-medium">Reference</th>
                    <th scope="col" class="px-4 py-3 font-medium">Evidence</th>
                    <th scope="col" class="px-4 py-3 font-medium">Submitted</th>
                    <th scope="col" class="px-4 py-3 font-medium">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-border">
                @forelse ($payments as $payment)
                    <tr>
                        <td class="px-4 py-3">
                            @can('view', $payment->order)
                                <a href="{{ route('admin.orders.show', $payment->order) }}" class="font-mono text-xs font-semibold text-primary underline underline-offset-2 hover:text-secondary">{{ $payment->order->order_number }}</a>
                            @else
                                <span class="font-mono text-xs">{{ $payment->order->order_number }}</span>
                            @endcan
                        </td>
                        <td class="px-4 py-3">{{ $payment->order->customer_name }}<br><span class="text-xs text-muted-foreground">{{ $payment->order->customer_email }}</span></td>
                        <td class="px-4 py-3">{{ $payment->currency }} {{ number_format((float) $payment->amount, 2) }}</td>
                        <td class="px-4 py-3">{{ $payment->transaction_reference }}</td>
                        <td class="px-4 py-3">
                            @foreach ($payment->evidence as $document)
                                <a href="{{ route('admin.documents.show', $document) }}" class="block text-primary underline underline-offset-2 hover:text-secondary">{{ $document->original_filename }}</a>
                            @endforeach
                        </td>
                        <td class="px-4 py-3">
                            <span class="whitespace-nowrap">{{ $payment->submitted_at?->format('j M Y, g:ia') }}</span>
                            @if ($payment->submitted_at)
                                <span class="block text-xs text-muted-foreground" data-review-age>Waiting {{ $payment->submitted_at->diffForHumans(null, \Carbon\CarbonInterface::DIFF_ABSOLUTE) }}</span>
                            @endif
                            @if ($payment->order->expires_at?->isPast())
                                <span class="mt-1 inline-block rounded border border-[#CC001F]/50 px-1.5 text-[11px] font-semibold text-[#CC001F]" data-review-overdue>Past payment deadline ({{ $payment->order->expires_at->copy()->setTimezone(config('membership.business_timezone'))->format('j M, g:ia') }}) — not auto-expired while under review</span>
                            @endif
                        </td>
                        <td class="px-4 py-3">
                            <div class="flex flex-col gap-2">
                                <form method="POST" action="{{ route('admin.order-payments.approve', $payment) }}">
                                    @csrf
                                    <button type="submit" class="btn btn-sm btn-gradient w-full">Approve</button>
                                </form>
                                <details>
                                    <summary class="btn btn-sm w-full cursor-pointer border border-destructive/50 text-destructive">Reject</summary>
                                    <form method="POST" action="{{ route('admin.order-payments.reject', $payment) }}" class="mt-2 space-y-2">
                                        @csrf
                                        <textarea name="reason" rows="2" required maxlength="500" class="field-control" placeholder="Reason shown to the customer"></textarea>
                                        <button type="submit" class="btn btn-sm w-full border border-destructive/50 text-destructive">Confirm rejection</button>
                                    </form>
                                </details>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" class="px-4 py-8 text-center text-muted-foreground">No order payments are awaiting verification.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-6">{{ $payments->links() }}</div>
</x-layouts.admin>
