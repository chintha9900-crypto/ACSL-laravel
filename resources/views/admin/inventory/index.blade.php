<x-layouts.admin title="Inventory">
    <h1 class="font-display text-2xl font-bold text-primary md:text-3xl">Inventory</h1>
    <p class="mt-1 text-muted-foreground">Stock can never go negative — the database rejects any change that would.</p>

    <nav class="mt-4 flex flex-wrap gap-2" aria-label="Filter by stock level">
        @foreach ([null => 'All', 'low' => 'Low stock ('.$lowStockCount.')', 'out' => 'Out of stock ('.$outOfStockCount.')'] as $value => $label)
            @php($active = ($filter ?? null) === ($value ?: null))
            <a href="{{ route('admin.inventory.index', array_filter(['filter' => $value])) }}"
                @if ($active) aria-current="true" @endif
                @class([
                    'rounded-md border px-3 py-1.5 text-sm font-medium transition-colors',
                    'border-primary bg-primary text-primary-foreground' => $active,
                    'border-border bg-background text-primary hover:border-primary/60' => ! $active,
                ])>{{ $label }}</a>
        @endforeach
    </nav>

    <div class="ui-card mt-6 overflow-x-auto">
        <table class="w-full min-w-[56rem] text-left text-sm">
            <thead class="border-b border-border text-xs uppercase tracking-wide text-muted-foreground">
                <tr>
                    <th scope="col" class="px-4 py-3 font-medium">Product</th>
                    <th scope="col" class="px-4 py-3 font-medium">SKU</th>
                    <th scope="col" class="px-4 py-3 font-medium">Current stock</th>
                    <th scope="col" class="px-4 py-3 font-medium">Low-stock threshold</th>
                    <th scope="col" class="px-4 py-3 font-medium">Status</th>
                    <th scope="col" class="px-4 py-3 font-medium">Adjust stock</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-border">
                @forelse ($inventories as $inventory)
                    <tr>
                        <td class="px-4 py-3">{{ $inventory->product?->name ?? '—' }}</td>
                        <td class="px-4 py-3 font-mono text-xs text-muted-foreground">{{ $inventory->product?->sku ?? '—' }}</td>
                        <td class="px-4 py-3 font-semibold">{{ $inventory->quantity }}</td>
                        <td class="px-4 py-3">
                            <form method="POST" action="{{ route('admin.inventory.update-threshold', $inventory) }}" class="flex items-center gap-2">
                                @csrf
                                @method('PATCH')
                                <input type="number" name="low_stock_threshold" value="{{ old('low_stock_threshold', $inventory->low_stock_threshold) }}" min="0" class="field-control h-8 w-20">
                                <button type="submit" class="btn btn-sm border border-input bg-background shadow-sm hover:bg-accent hover:text-accent-foreground">Save</button>
                            </form>
                        </td>
                        <td class="px-4 py-3">
                            @if ($inventory->isOutOfStock())
                                <span class="inline-flex items-center rounded-md border border-[#CC001F]/40 bg-[#CC001F]/5 px-2.5 py-0.5 text-xs font-semibold text-[#CC001F]">Out of stock</span>
                            @elseif ($inventory->isLowStock())
                                <span class="inline-flex items-center rounded-md border border-border bg-muted px-2.5 py-0.5 text-xs font-semibold text-foreground">Low stock</span>
                            @else
                                <span class="inline-flex items-center rounded-md border border-transparent bg-primary px-2.5 py-0.5 text-xs font-semibold text-primary-foreground">In stock</span>
                            @endif
                        </td>
                        <td class="px-4 py-3">
                            <div class="flex flex-wrap gap-2">
                                <form method="POST" action="{{ route('admin.inventory.add-stock', $inventory) }}" class="flex items-center gap-1">
                                    @csrf
                                    <input type="number" name="amount" min="1" placeholder="Qty" required class="field-control h-8 w-16">
                                    <button type="submit" class="btn btn-sm btn-brand">Add</button>
                                </form>

                                <form method="POST" action="{{ route('admin.inventory.remove-stock', $inventory) }}" class="flex items-center gap-1">
                                    @csrf
                                    <input type="number" name="amount" min="1" placeholder="Qty" required class="field-control h-8 w-16">
                                    <button type="submit" class="btn btn-sm border border-input bg-background shadow-sm hover:bg-accent hover:text-accent-foreground">Remove</button>
                                </form>
                            </div>
                            @error('amount')
                                <p class="mt-1 text-xs text-[#CC001F]">{{ $message }}</p>
                            @enderror
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="px-4 py-8 text-center text-muted-foreground">No products found.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-6">{{ $inventories->links() }}</div>
</x-layouts.admin>
