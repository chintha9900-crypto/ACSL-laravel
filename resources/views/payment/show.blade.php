{{--
    The customer's payment page for one order. Amounts come from the stored
    payment/order rows (calculated server-side at checkout); nothing here can
    mark the order paid — bank transfers are verified by an admin, PayHere by
    its verified notify callback, and the dummy method is development-only.
--}}
@php
    $statusLabels = [
        'pending' => 'Awaiting payment',
        'processing' => 'Under review',
        'paid' => 'Paid',
        'failed' => $payment?->gateway === 'manual_bank_transfer' ? 'Not verified' : 'Failed',
        'cancelled' => 'Cancelled',
    ];
    $methodLabels = [
        'bank_transfer' => 'Bank transfer',
        'payhere' => 'Card / online payment (PayHere)',
        'dummy' => 'Dummy / Test Payment',
    ];
@endphp
<x-layouts.public title="Payment">
    <section class="border-t border-border">
        <div class="container mx-auto max-w-2xl px-4 py-12 lg:px-8">
            <h1 class="font-display text-3xl font-bold text-primary md:text-4xl">Payment</h1>
            <p class="mt-2 text-muted-foreground">Order <span class="font-mono font-semibold text-foreground">{{ $order->order_number }}</span></p>

            @if ($order->is_test)
                <p class="mt-4 rounded-lg border border-[#CC001F]/50 bg-[#CC001F]/5 px-4 py-3 text-sm font-semibold text-[#CC001F]" role="note">TEST ORDER — Dummy / Test Payment, Development Only. No real payment is taken.</p>
            @endif

            @if (session('status'))
                <p class="mt-4 rounded-lg border border-secondary/40 bg-secondary/10 px-4 py-3 text-sm text-primary" role="status">{{ session('status') }}</p>
            @endif

            @if ($errors->any())
                <div class="mt-4 rounded-lg border border-[#CC001F]/50 bg-[#CC001F]/5 px-4 py-3 text-sm text-[#CC001F]" role="alert">
                    @foreach ($errors->all() as $message)
                        <p>{{ $message }}</p>
                    @endforeach
                </div>
            @endif

            <div class="ui-card mt-6 p-6 md:p-8">
                <dl class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <dt class="text-xs font-semibold uppercase tracking-wide text-muted-foreground">Amount due</dt>
                        <dd class="mt-1 font-display text-2xl font-bold text-primary">{{ $order->currency }} {{ $order->total_amount }}</dd>
                        @if ($order->delivery_charge_amount !== null)
                            <dd class="mt-1 text-xs text-muted-foreground">Items {{ $order->currency }} {{ $order->subtotal_amount }} + delivery (Sri Lanka) {{ $order->currency }} {{ $order->delivery_charge_amount }}</dd>
                        @endif
                    </div>
                    <div>
                        <dt class="text-xs font-semibold uppercase tracking-wide text-muted-foreground">Payment status</dt>
                        <dd class="mt-1">
                            <span class="inline-flex items-center rounded-md border border-border bg-muted px-2.5 py-0.5 text-xs font-semibold text-foreground">{{ $statusLabels[$payment?->status ?? 'pending'] ?? ucfirst($payment->status) }}</span>
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs font-semibold uppercase tracking-wide text-muted-foreground">Payment method</dt>
                        <dd class="mt-1">{{ $order->paymentMethodLabel() }}</dd>
                    </div>
                    @if ($awaitingPayment && $order->expires_at)
                        <div>
                            <dt class="text-xs font-semibold uppercase tracking-wide text-muted-foreground">Pay by</dt>
                            <dd class="mt-1">{{ $order->expires_at->copy()->setTimezone(config('membership.business_timezone'))->format('j M Y, g:ia') }} (Sri Lanka time)</dd>
                        </div>
                    @endif
                </dl>
            </div>

            @if ($order->status === 'cancelled')
                <p class="mt-6 text-sm text-muted-foreground">This order has been cancelled.</p>
            @elseif ($deadlinePassed)
                <p class="mt-6 text-sm text-muted-foreground" role="status">The payment deadline for this order has passed, so it has expired and the items were released. If you have already paid, please contact us with your payment reference.</p>
            @elseif ($order->hasReceipt() || $order->status === 'refunded')
                <p class="mt-6 text-sm text-muted-foreground">This order has been paid.</p>
                @if ($receiptUrl)
                    <a href="{{ $receiptUrl }}" class="btn btn-lg btn-brand mt-4">View Receipt</a>
                @endif
            @elseif ($payment)
                {{-- Bank transfer --}}
                @if ($payment->gateway === 'manual_bank_transfer')
                    @if ($payment->status === 'processing')
                        <div class="ui-card mt-6 p-6 md:p-8">
                            <h2 class="font-display text-lg font-bold text-primary">Transfer under review</h2>
                            <p class="mt-1 text-sm text-muted-foreground">We received your transfer details (reference <span class="font-mono">{{ $payment->transaction_reference }}</span>) and will confirm your order once the payment is verified. Your receipt is sent after verification.</p>
                        </div>
                    @elseif (in_array($payment->status, ['pending', 'failed'], true))
                        @if ($payment->status === 'failed')
                            <div class="mt-6 rounded-lg border border-[#CC001F]/50 bg-[#CC001F]/5 px-4 py-3 text-sm text-[#CC001F]" role="alert">
                                <p class="font-semibold">We could not verify your previous transfer details.</p>
                                @if ($payment->rejection_reason)
                                    <p class="mt-1">Reason: {{ $payment->rejection_reason }}</p>
                                @endif
                                <p class="mt-1">Please submit corrected details below.</p>
                            </div>
                        @endif

                        <div class="ui-card mt-6 p-6 md:p-8">
                            <h2 class="font-display text-lg font-bold text-primary">Pay by bank transfer</h2>
                            @if ($bankAccount)
                                <p class="mt-1 text-sm text-muted-foreground">Transfer exactly <strong class="text-foreground">{{ $order->currency }} {{ $payment->amount }}</strong> and use <strong class="font-mono text-foreground">{{ $order->order_number }}</strong> as the payment reference.</p>
                                <dl class="mt-4 grid gap-3 text-sm sm:grid-cols-2">
                                    <div><dt class="text-xs font-semibold uppercase tracking-wide text-muted-foreground">Bank</dt><dd>{{ $bankAccount->bank_name }}</dd></div>
                                    <div><dt class="text-xs font-semibold uppercase tracking-wide text-muted-foreground">Account name</dt><dd>{{ $bankAccount->account_name }}</dd></div>
                                    <div><dt class="text-xs font-semibold uppercase tracking-wide text-muted-foreground">Account number</dt><dd class="font-mono">{{ $bankAccount->account_number }}</dd></div>
                                    @if ($bankAccount->branch)
                                        <div><dt class="text-xs font-semibold uppercase tracking-wide text-muted-foreground">Branch</dt><dd>{{ $bankAccount->branch }}</dd></div>
                                    @endif
                                    @if ($bankAccount->swift_bic)
                                        <div><dt class="text-xs font-semibold uppercase tracking-wide text-muted-foreground">SWIFT / BIC</dt><dd class="font-mono">{{ $bankAccount->swift_bic }}</dd></div>
                                    @endif
                                    @if ($bankAccount->iban)
                                        <div><dt class="text-xs font-semibold uppercase tracking-wide text-muted-foreground">IBAN</dt><dd class="font-mono">{{ $bankAccount->iban }}</dd></div>
                                    @endif
                                </dl>
                                @if ($bankAccount->instructions)
                                    <p class="mt-3 whitespace-pre-line text-sm text-muted-foreground">{{ $bankAccount->instructions }}</p>
                                @endif

                                <form method="POST" action="{{ $urls['bankTransfer'] }}" enctype="multipart/form-data" class="mt-6 space-y-4 border-t border-border pt-6">
                                    @csrf
                                    <h3 class="font-semibold text-primary">After you have transferred</h3>
                                    <x-form.input name="reference" label="Your bank's transaction reference" maxlength="191" />
                                    @php($evidenceMaxMb = round(config('uploads.payment_evidence.max_kb') / 1024, 1))
                                    <div class="space-y-1.5">
                                        <label for="evidence" class="block text-sm font-medium leading-none">Transfer evidence <span class="text-[#CC001F]" aria-hidden="true">*</span></label>
                                        <input id="evidence" name="evidence[]" type="file" multiple required accept=".pdf,.jpg,.jpeg,.png,application/pdf,image/jpeg,image/png"
                                            class="min-w-0 max-w-full cursor-pointer text-sm text-muted-foreground file:mr-3 file:h-8 file:cursor-pointer file:rounded-md file:border file:border-input file:bg-background file:px-3 file:text-xs file:font-medium file:text-foreground file:shadow-sm hover:file:bg-accent">
                                        <p class="text-xs text-muted-foreground">PDF, JPG or PNG, up to {{ $evidenceMaxMb }} MB each, at most {{ config('payments.bank_transfer.max_evidence_files') }} files. Kept private — only our payments team can view it.</p>
                                        @foreach (['evidence', 'evidence.0', 'evidence.1', 'evidence.2'] as $evidenceKey)
                                            @error($evidenceKey)
                                                <p class="text-xs text-destructive">{{ $message }}</p>
                                            @enderror
                                        @endforeach
                                    </div>
                                    <button type="submit" class="btn btn-lg btn-brand">Submit transfer details</button>
                                </form>
                            @else
                                <p class="mt-1 text-sm text-muted-foreground" role="status">Bank details are not available at the moment. Please contact us to complete your payment.</p>
                            @endif
                        </div>
                    @endif

                {{-- PayHere --}}
                @elseif ($payment->gateway === 'payhere')
                    @if ($awaitingPayHereConfirmation)
                        <div class="mt-6 rounded-lg border border-secondary/40 bg-secondary/10 px-4 py-3 text-sm text-primary" role="status">
                            <p class="font-semibold">Waiting for PayHere to confirm your payment.</p>
                            <p class="mt-1">Refresh this page in a moment. If you completed the payment, please do not pay again.</p>
                        </div>
                    @endif
                    @if ($payHereForm)
                        <div class="ui-card mt-6 p-6 md:p-8">
                            <h2 class="font-display text-lg font-bold text-primary">Pay online with PayHere</h2>
                            <p class="mt-1 text-sm text-muted-foreground">You will be taken to PayHere's secure payment page (sandbox — test mode). Your order is confirmed once PayHere notifies us of a successful payment.</p>
                            <form method="POST" action="{{ $payHereForm['action'] }}" class="mt-4">
                                @foreach ($payHereForm['fields'] as $field => $value)
                                    <input type="hidden" name="{{ $field }}" value="{{ $value }}">
                                @endforeach
                                <button type="submit" class="btn btn-lg btn-brand">Pay {{ $order->currency }} {{ $payment->amount }} with PayHere</button>
                            </form>
                        </div>
                    @elseif ($payment->status === 'pending')
                        <p class="mt-6 text-sm text-muted-foreground" role="status">Online payment is not available at the moment. You can choose another payment method below, or contact us.</p>
                    @else
                        <p class="mt-6 text-sm text-muted-foreground">This payment attempt did not succeed. Your order is still open — you can try again below.</p>
                    @endif

                {{-- Dummy (development only) --}}
                @elseif ($payment->gateway === 'dummy')
                    @if ($dummyAvailable)
                        <div class="ui-card mt-6 border-[#CC001F]/50 p-6 md:p-8">
                            <h2 class="font-display text-lg font-bold text-[#CC001F]">Dummy / Test Payment — Development Only</h2>
                            <p class="mt-1 text-sm text-muted-foreground">Choose the outcome to simulate. No money is taken.</p>
                            <div class="mt-4 flex flex-wrap gap-3">
                                @foreach (['success' => 'Simulate success', 'failure' => 'Simulate failure', 'cancel' => 'Simulate cancellation'] as $outcome => $label)
                                    <form method="POST" action="{{ route('payment.dummy', [$order, $outcome]) }}">
                                        @csrf
                                        <button type="submit" class="btn btn-lg {{ $outcome === 'success' ? 'btn-brand' : 'border border-input bg-background shadow-sm hover:bg-accent hover:text-accent-foreground' }}">{{ $label }}</button>
                                    </form>
                                @endforeach
                            </div>
                        </div>
                    @elseif ($payment->status === 'pending')
                        <p class="mt-6 text-sm text-muted-foreground" role="status">Test payments are not available here.</p>
                    @else
                        <p class="mt-6 text-sm text-muted-foreground">This test payment did not succeed. You can try again below.</p>
                    @endif
                @endif

                @if ($retryMethods !== [])
                    <div class="mt-6">
                        <h2 class="text-sm font-semibold text-primary">{{ in_array($payment->status, ['failed', 'cancelled'], true) ? 'Try again' : 'Prefer another way to pay?' }}</h2>
                        <div class="mt-2 flex flex-wrap gap-3">
                            @foreach ($retryMethods as $method)
                                <form method="POST" action="{{ $urls['retry'] }}">
                                    @csrf
                                    <input type="hidden" name="payment_method" value="{{ $method }}">
                                    <button type="submit" class="btn btn-sm border border-input bg-background shadow-sm hover:bg-accent hover:text-accent-foreground">{{ $method === $order->payment_method ? 'Try again: ' : 'Pay by ' }}{{ $methodLabels[$method] }}</button>
                                </form>
                            @endforeach
                        </div>
                    </div>
                @endif
            @endif
        </div>
    </section>
</x-layouts.public>
