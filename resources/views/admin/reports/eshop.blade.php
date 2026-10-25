@use('Illuminate\Support\Str')

<x-layouts.admin title="E-shop financial summary">
    <h1 class="font-display text-2xl font-bold text-primary md:text-3xl">E-shop financial summary</h1>
    <p class="mt-1 text-sm text-muted-foreground">Orders placed from {{ $from }} to {{ $to }}, grouped by status and currency.</p>

    <form method="GET" action="{{ route('admin.reports.eshop') }}" class="mt-6 flex flex-wrap items-end gap-4">
        <div class="space-y-1.5">
            <label for="from" class="block text-sm font-medium leading-none">From</label>
            <input id="from" name="from" type="date" value="{{ $from }}" class="field-control">
        </div>
        <div class="space-y-1.5">
            <label for="to" class="block text-sm font-medium leading-none">To</label>
            <input id="to" name="to" type="date" value="{{ $to }}" class="field-control">
        </div>
        <button type="submit" class="btn btn-lg border border-input bg-background shadow-sm hover:bg-accent hover:text-accent-foreground">Update</button>
    </form>
    @error('from') <p class="mt-2 text-xs text-destructive">{{ $message }}</p> @enderror
    @error('to') <p class="mt-2 text-xs text-destructive">{{ $message }}</p> @enderror

    <section class="ui-card mt-6 p-6">
        @if ($rows->isEmpty())
            <p class="text-sm text-muted-foreground">No orders in this period.</p>
        @else
            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead class="text-xs uppercase tracking-wide text-muted-foreground">
                        <tr>
                            <th class="py-2 pr-4 font-medium">Status</th>
                            <th class="py-2 pr-4 font-medium">Currency</th>
                            <th class="py-2 pr-4 text-right font-medium">Orders</th>
                            <th class="py-2 text-right font-medium">Total value</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border">
                        @foreach ($rows as $row)
                            <tr>
                                <td class="py-2 pr-4">{{ Str::headline($row->status) }}</td>
                                <td class="py-2 pr-4">{{ $row->currency }}</td>
                                <td class="py-2 pr-4 text-right">{{ number_format((int) $row->orders_count) }}</td>
                                <td class="py-2 text-right font-mono">{{ number_format((float) $row->total_value, 2) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>
</x-layouts.admin>
