{{--
    Receiving bank accounts for bank transfers (membership renewals and
    e-shop). Only the active account for a currency is ever shown to
    customers. Details cannot be edited: add a new account and activate it,
    so past transfers stay linked to the details they were made to.
--}}
<x-layouts.admin title="Bank accounts">
    <div class="flex flex-wrap items-center justify-between gap-4">
        <div>
            <h1 class="font-display text-2xl font-bold text-primary md:text-3xl">Receiving bank accounts</h1>
            <p class="mt-1 text-sm text-muted-foreground">The active LKR account is shown to customers for membership and e-shop bank transfers.</p>
        </div>
        <a href="{{ route('admin.bank-accounts.create') }}" class="btn btn-sm btn-brand">Add account</a>
    </div>

    @if (! $accounts->contains(fn ($account) => $account->is_active && $account->currency === 'LKR'))
        <p class="mt-4 rounded-lg border border-[#CC001F]/50 bg-[#CC001F]/5 px-4 py-3 text-sm text-[#CC001F]" role="alert">No active LKR account — customers cannot pay by bank transfer.</p>
    @endif

    <div class="ui-card mt-6 overflow-x-auto">
        <table class="w-full min-w-[56rem] text-left text-sm">
            <thead class="border-b border-border text-xs uppercase tracking-wide text-muted-foreground">
                <tr>
                    <th scope="col" class="px-4 py-3 font-medium">Account</th>
                    <th scope="col" class="px-4 py-3 font-medium">Bank</th>
                    <th scope="col" class="px-4 py-3 font-medium">Account number</th>
                    <th scope="col" class="px-4 py-3 font-medium">Currency</th>
                    <th scope="col" class="px-4 py-3 font-medium">Status</th>
                    <th scope="col" class="px-4 py-3 font-medium">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-border">
                @forelse ($accounts as $account)
                    <tr class="align-top">
                        <td class="px-4 py-3">{{ $account->label }}<br><span class="text-xs text-muted-foreground">{{ $account->account_name }}</span></td>
                        <td class="px-4 py-3">{{ $account->bank_name }}@if ($account->branch)<br><span class="text-xs text-muted-foreground">{{ $account->branch }}</span>@endif</td>
                        <td class="px-4 py-3 font-mono text-xs">{{ $account->account_number }}</td>
                        <td class="px-4 py-3">{{ $account->currency }}</td>
                        <td class="px-4 py-3">
                            @if ($account->is_active)
                                <span class="rounded-md bg-primary px-2 py-0.5 text-xs font-semibold text-primary-foreground">Active</span>
                            @else
                                <span class="rounded-md border border-border px-2 py-0.5 text-xs">Inactive</span>
                            @endif
                        </td>
                        <td class="px-4 py-3">
                            @if ($account->is_active)
                                <details>
                                    <summary class="btn btn-sm cursor-pointer border border-destructive/50 text-destructive">Deactivate</summary>
                                    <form method="POST" action="{{ route('admin.bank-accounts.deactivate', $account) }}" class="mt-2 space-y-2">
                                        @csrf
                                        <textarea name="reason" rows="2" required maxlength="500" class="field-control" placeholder="Reason (e.g. account closed or compromised)"></textarea>
                                        <button type="submit" class="btn btn-sm border border-destructive/50 text-destructive">Confirm deactivation</button>
                                    </form>
                                </details>
                            @else
                                <form method="POST" action="{{ route('admin.bank-accounts.activate', $account) }}">
                                    @csrf
                                    <button type="submit" class="btn btn-sm border border-input bg-background shadow-sm hover:bg-accent">Make active</button>
                                </form>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="px-4 py-8 text-center text-muted-foreground">No bank accounts yet.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="ui-card mt-6 p-6 md:p-7">
        <h2 class="font-display text-lg font-bold text-primary">Change history</h2>
        @forelse ($history as $entry)
            @php($values = json_decode((string) $entry->new_values, true) ?? [])
            <p class="mt-2 text-sm">
                {{ \Illuminate\Support\Carbon::parse($entry->created_at)->format('j M Y, g:ia') }} — {{ $entry->actor_name ?? 'System' }}:
                {{ str_replace(['bank_account.', '_'], ['', ' '], $entry->event) }} account #{{ $entry->subject_id }} ({{ $values['label'] ?? '' }}, {{ $values['account_number'] ?? '' }})@if (! empty($values['reason'])) — {{ $values['reason'] }}@endif
            </p>
        @empty
            <p class="mt-2 text-sm text-muted-foreground">No changes recorded.</p>
        @endforelse
    </div>
</x-layouts.admin>
