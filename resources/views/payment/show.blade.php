{{--
    E-Shop Step 7 — payment page only (no real gateway/webhooks/email
    receipts yet). `$payment->amount` is read from the stored payment row,
    which was itself calculated server-side at checkout from the order's
    own total — nothing on this page lets a visitor change it.

    Confirm/Fail/Cancel below stand in for a real gateway's hosted payment
    page and its callback: in a real integration, the customer would be
    redirected to the gateway and these buttons would not exist here at
    all — the gateway's own signed webhook would call
    `PaymentGatewayContract` instead.
--}}
<x-layouts.public title="Payment">
    <section class="border-t border-border">
        <div class="container mx-auto max-w-2xl px-4 py-12 lg:px-8">
            <h1 class="font-display text-3xl font-bold text-primary md:text-4xl">Payment</h1>
            <p class="mt-2 text-muted-foreground">Order <span class="font-mono font-semibold text-foreground">{{ $order->order_number }}</span></p>

            @if (session('status'))
                <p class="mt-4 rounded-lg border border-secondary/40 bg-secondary/10 px-4 py-3 text-sm text-primary" role="status">{{ session('status') }}</p>
            @endif

            <div class="ui-card mt-6 p-6 md:p-8">
                <dl class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <dt class="text-xs font-semibold uppercase tracking-wide text-muted-foreground">Amount due</dt>
                        <dd class="mt-1 font-display text-2xl font-bold text-primary">LKR {{ number_format((float) $order->total_amount, 2) }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs font-semibold uppercase tracking-wide text-muted-foreground">Payment status</dt>
                        <dd class="mt-1">
                            <span class="inline-flex items-center rounded-md border border-border bg-muted px-2.5 py-0.5 text-xs font-semibold capitalize text-foreground">{{ $payment?->status ?? 'pending' }}</span>
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs font-semibold uppercase tracking-wide text-muted-foreground">Provider</dt>
                        <dd class="mt-1">{{ $gatewayName }}</dd>
                    </div>
                </dl>
            </div>

            @if ($payment && $payment->status === 'pending')
                {{--
                    E-Shop Step 10.2 — the simulator itself is still a
                    development-only placeholder (the backend already fails
                    every confirm/fail/cancel outside local/testing — see
                    `AppServiceProvider::register()`); this just stops the
                    now-nonfunctional controls from being shown anywhere they
                    could never work. Checked the same way the gateway
                    binding is (`config('app.env')`, not `app()->environment()`)
                    so both reflect one single source of truth.
                --}}
                @if (in_array(config('app.env'), ['local', 'testing'], true))
                    <div class="ui-card mt-6 p-6 md:p-8">
                        <h2 class="font-display text-lg font-bold text-primary">Development payment simulator</h2>
                        <p class="mt-1 text-sm text-muted-foreground">No real payment gateway is connected yet. Use these to simulate what the gateway would report.</p>

                        <div class="mt-4 flex flex-wrap gap-3">
                            <form method="POST" action="{{ route('payment.confirm', $order) }}">
                                @csrf
                                <button type="submit" class="btn btn-lg btn-brand">Simulate Successful Payment</button>
                            </form>
                            <form method="POST" action="{{ route('payment.fail', $order) }}">
                                @csrf
                                <button type="submit" class="btn btn-lg border border-input bg-background shadow-sm hover:bg-accent hover:text-accent-foreground">Simulate Failed Payment</button>
                            </form>
                            <form method="POST" action="{{ route('payment.cancel', $order) }}">
                                @csrf
                                <button type="submit" class="btn btn-lg border border-input bg-background shadow-sm hover:bg-accent hover:text-accent-foreground">Cancel</button>
                            </form>
                        </div>
                    </div>
                @endif
            @elseif ($payment && $payment->status === 'paid')
                <p class="mt-6 text-sm text-muted-foreground">This order has been paid.</p>
                @if ($receiptUrl)
                    <a href="{{ $receiptUrl }}" class="btn btn-lg btn-brand mt-4">View Receipt</a>
                @endif
            @elseif ($payment)
                <p class="mt-6 text-sm text-muted-foreground">This payment attempt did not succeed. You can try again from your cart.</p>
            @endif
        </div>
    </section>
</x-layouts.public>
