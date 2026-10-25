{{--
    E-shop payment conflicts that are never resolved automatically. Values
    marked "unverified" came from fields PayHere does not sign — confirm them
    in the PayHere merchant portal before acting. Record any refund on the
    order page; resolving an item here only records the decision.
--}}
<x-layouts.admin title="Payment reviews">
    <h1 class="font-display text-2xl font-bold text-primary md:text-3xl">Payment reviews</h1>
    <p class="mt-1 text-sm text-muted-foreground">Duplicates, chargebacks, payments after an order closed, amount mismatches and bank-transfer conflicts.</p>

    <nav class="mt-4 flex gap-2" aria-label="Filter">
        @foreach (['open' => 'Open', 'resolved' => 'Resolved'] as $value => $label)
            <a href="{{ route('admin.payment-reviews.index', ['status' => $value]) }}" @if ($status === $value) aria-current="true" @endif
                @class(['rounded-md border px-3 py-1.5 text-sm font-medium', 'border-primary bg-primary text-primary-foreground' => $status === $value, 'border-border bg-background text-primary' => $status !== $value])>{{ $label }}</a>
        @endforeach
    </nav>

    <div class="mt-6 space-y-4">
        @forelse ($reviews as $review)
            <div class="ui-card p-5">
                <div class="flex flex-wrap items-baseline justify-between gap-2">
                    <h2 class="font-semibold text-primary">{{ $review->typeLabel() }}</h2>
                    <span class="text-xs text-muted-foreground">{{ $review->created_at->format('j M Y, g:ia') }}</span>
                </div>
                <p class="mt-1 text-sm">{{ $review->summary }}</p>
                <dl class="mt-3 grid gap-2 text-xs sm:grid-cols-3">
                    <div><dt class="font-semibold uppercase text-muted-foreground">Order</dt><dd><a href="{{ route('admin.orders.show', $review->order) }}" class="font-mono text-primary underline">{{ $review->order->order_number }}</a> ({{ str_replace('_', ' ', $review->order->status) }})</dd></div>
                    @if ($review->payment)
                        <div><dt class="font-semibold uppercase text-muted-foreground">Payment attempt</dt><dd class="font-mono">{{ $review->payment->idempotency_key }} — {{ str_replace('_', ' ', $review->payment->gateway) }}, {{ str_replace('_', ' ', $review->payment->status) }}, {{ $review->payment->currency }} {{ $review->payment->amount }}</dd></div>
                        <div><dt class="font-semibold uppercase text-muted-foreground">Recorded reference</dt><dd class="font-mono">{{ $review->payment->transaction_reference ?? '—' }}</dd></div>
                    @endif
                    <div><dt class="font-semibold uppercase text-muted-foreground">Times reported</dt><dd>{{ $review->details['repeat_count'] ?? 1 }}</dd></div>
                    @if (! empty($review->details['unverified']['reported_references']))
                        <div class="sm:col-span-2"><dt class="font-semibold uppercase text-muted-foreground">Reported gateway references (unverified)</dt><dd class="font-mono">{{ implode(', ', $review->details['unverified']['reported_references']) }}</dd></div>
                    @endif
                </dl>

                @if ($review->status === 'open')
                    <form method="POST" action="{{ route('admin.payment-reviews.resolve', $review) }}" class="mt-4 space-y-2">
                        @csrf
                        <textarea name="resolution_note" rows="2" required maxlength="1000" class="field-control" placeholder="Decision and what was done (e.g. verified in PayHere portal; refund recorded on the order)"></textarea>
                        <button type="submit" class="btn btn-sm btn-brand">Mark resolved</button>
                    </form>
                @else
                    <p class="mt-3 text-xs text-muted-foreground">Resolved by {{ $review->resolvedBy?->name ?? 'unknown' }} on {{ $review->resolved_at->format('j M Y, g:ia') }}: {{ $review->resolution_note }}</p>
                @endif
            </div>
        @empty
            <p class="rounded-lg border border-border bg-muted px-4 py-8 text-center text-sm text-muted-foreground">No {{ $status }} review items.</p>
        @endforelse
    </div>

    <div class="mt-6">{{ $reviews->links() }}</div>
</x-layouts.admin>
