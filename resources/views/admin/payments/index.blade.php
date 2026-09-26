<x-layouts.admin :title="'Payments'">
    <h1 class="font-display text-2xl font-bold text-primary md:text-3xl">Payments awaiting confirmation</h1>

    @error('payment')
        <p class="mt-4 rounded-lg border border-destructive/50 px-3 py-2 text-sm text-destructive" role="alert">{{ $message }}</p>
    @enderror

    <div class="ui-card mt-6 overflow-x-auto">
        <table class="w-full min-w-[60rem] text-left text-sm">
            <thead class="border-b border-border text-xs uppercase tracking-wide text-muted-foreground">
                <tr>
                    <th scope="col" class="px-4 py-3 font-medium">Member</th>
                    <th scope="col" class="px-4 py-3 font-medium">Membership number</th>
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
                        <td class="px-4 py-3">{{ $payment->user->name }}<br><span class="text-xs text-muted-foreground">{{ $payment->user->email }}</span></td>
                        <td class="px-4 py-3 font-mono text-xs">{{ $payment->membershipTerm->membership->membership_number }}</td>
                        <td class="px-4 py-3">{{ $payment->currency }} {{ number_format((float) $payment->amount, 2) }}</td>
                        <td class="px-4 py-3">{{ $payment->transaction_reference }}</td>
                        <td class="px-4 py-3">
                            @foreach ($payment->evidence as $document)
                                <a href="{{ route('admin.documents.show', $document) }}" class="block text-primary underline underline-offset-2 hover:text-secondary">{{ $document->original_filename }}</a>
                            @endforeach
                        </td>
                        <td class="px-4 py-3 whitespace-nowrap">{{ $payment->submitted_at?->format('j M Y') }}</td>
                        <td class="px-4 py-3">
                            <div class="flex flex-col gap-2">
                                <form method="POST" action="{{ route('admin.payments.confirm', $payment) }}">
                                    @csrf
                                    <button type="submit" class="btn btn-sm btn-gradient w-full">Confirm</button>
                                </form>
                                <details>
                                    <summary class="btn btn-sm w-full cursor-pointer border border-destructive/50 text-destructive">Reject</summary>
                                    <form method="POST" action="{{ route('admin.payments.reject', $payment) }}" class="mt-2 space-y-2">
                                        @csrf
                                        <textarea name="reason" rows="2" required maxlength="500" class="field-control" placeholder="Reason shown to the member"></textarea>
                                        <button type="submit" class="btn btn-sm w-full border border-destructive/50 text-destructive">Confirm rejection</button>
                                    </form>
                                </details>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" class="px-4 py-8 text-center text-muted-foreground">No payments are awaiting confirmation.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-6">{{ $payments->links() }}</div>
</x-layouts.admin>
