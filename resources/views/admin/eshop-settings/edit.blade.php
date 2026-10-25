{{--
    Sri Lanka delivery charge. Until it is saved here, checkout is blocked:
    an unset charge never means free delivery. Zero is allowed only when
    entered and confirmed deliberately. Existing orders keep their snapshot.
--}}
<x-layouts.admin title="E-shop delivery charge">
    <h1 class="font-display text-2xl font-bold text-primary md:text-3xl">Delivery charge</h1>
    <p class="mt-1 text-muted-foreground">Charged on every e-shop order. Delivery is within Sri Lanka only.</p>

    <div class="ui-card mt-6 max-w-xl p-6 md:p-7">
        <p class="text-sm">
            Current setting:
            @if ($settings->delivery_charge_configured)
                <strong>LKR {{ $settings->delivery_charge_amount }}</strong>
            @else
                <strong class="text-[#CC001F]">Not set — customers cannot place orders yet.</strong>
            @endif
        </p>

        <form method="POST" action="{{ route('admin.eshop-settings.update') }}" class="mt-5 space-y-4">
            @csrf
            @method('PUT')
            <x-form.input name="delivery_charge" label="Delivery charge (LKR)" inputmode="decimal" maxlength="15"
                :value="old('delivery_charge', $settings->delivery_charge_amount)"
                hint="Use 0 only if delivery is deliberately free." />

            <label class="flex items-start gap-2 text-sm">
                <input type="checkbox" name="confirm" value="1" class="mt-0.5" required>
                <span>I confirm this is the approved delivery charge. New orders will include it in the amount customers pay.</span>
            </label>
            @error('confirm')
                <p class="text-xs text-destructive">{{ $message }}</p>
            @enderror

            <button type="submit" class="btn btn-lg btn-brand">Save delivery charge</button>
        </form>
    </div>

    <div class="ui-card mt-6 max-w-xl p-6 md:p-7">
        <h2 class="font-display text-lg font-bold text-primary">Change history</h2>
        @forelse ($history as $entry)
            @php($old = json_decode((string) $entry->old_values, true) ?? [])
            @php($new = json_decode((string) $entry->new_values, true) ?? [])
            <p class="mt-2 text-sm">
                {{ \Illuminate\Support\Carbon::parse($entry->created_at)->format('j M Y, g:ia') }} — {{ $entry->actor_name ?? 'System' }}:
                {{ ($old['delivery_charge_configured'] ?? false) ? 'LKR '.$old['delivery_charge_amount'] : 'not set' }} → LKR {{ $new['delivery_charge_amount'] ?? '?' }}
            </p>
        @empty
            <p class="mt-2 text-sm text-muted-foreground">No changes recorded.</p>
        @endforelse
    </div>
</x-layouts.admin>
