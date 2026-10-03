<x-layouts.admin title="Products">
    <div class="flex flex-wrap items-center justify-between gap-4">
        <h1 class="font-display text-2xl font-bold text-primary md:text-3xl">Products</h1>
        <a href="{{ route('admin.products.create') }}" class="btn btn-lg btn-brand">New product</a>
    </div>

    @if ($categories->isNotEmpty())
        <nav class="mt-4 flex flex-wrap gap-2" aria-label="Filter by category">
            @foreach ([null => 'All categories'] + $categories->pluck('name', 'id')->all() as $value => $label)
                @php($active = ($categoryId ?? null) === ($value ?: null))
                <a href="{{ route('admin.products.index', array_filter(['category' => $value])) }}"
                    @if ($active) aria-current="true" @endif
                    @class([
                        'rounded-md border px-3 py-1.5 text-sm font-medium transition-colors',
                        'border-primary bg-primary text-primary-foreground' => $active,
                        'border-border bg-background text-primary hover:border-primary/60' => ! $active,
                    ])>{{ $label }}</a>
            @endforeach
        </nav>
    @endif

    <div class="ui-card mt-6 overflow-x-auto">
        <table class="w-full min-w-[56rem] text-left text-sm">
            <thead class="border-b border-border text-xs uppercase tracking-wide text-muted-foreground">
                <tr>
                    <th scope="col" class="px-4 py-3 font-medium">Name</th>
                    <th scope="col" class="px-4 py-3 font-medium">SKU</th>
                    <th scope="col" class="px-4 py-3 font-medium">Category</th>
                    <th scope="col" class="px-4 py-3 font-medium">Price</th>
                    <th scope="col" class="px-4 py-3 font-medium">Access</th>
                    <th scope="col" class="px-4 py-3 font-medium">Status</th>
                    <th scope="col" class="px-4 py-3 font-medium">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-border">
                @forelse ($products as $product)
                    <tr>
                        <td class="px-4 py-3">
                            <a href="{{ route('admin.products.show', $product) }}" class="font-medium text-primary underline underline-offset-2 hover:text-secondary">{{ $product->name }}</a>
                        </td>
                        <td class="px-4 py-3 font-mono text-xs text-muted-foreground">{{ $product->sku }}</td>
                        <td class="px-4 py-3">{{ $product->category?->name ?? '—' }}</td>
                        <td class="px-4 py-3">{{ number_format((float) $product->price, 2) }}</td>
                        <td class="px-4 py-3">
                            <span class="inline-flex items-center rounded-md border border-border bg-muted px-2.5 py-0.5 text-xs font-semibold text-foreground">
                                {{ $product->access_type === 'MEMBER_ONLY' ? 'Member only' : 'Public' }}
                            </span>
                        </td>
                        <td class="px-4 py-3">
                            <span class="inline-flex items-center rounded-md border px-2.5 py-0.5 text-xs font-semibold {{ $product->is_active ? 'border-transparent bg-primary text-primary-foreground' : 'border-border bg-muted text-foreground' }}">
                                {{ $product->is_active ? 'Active' : 'Inactive' }}
                            </span>
                        </td>
                        <td class="px-4 py-3">
                            <div class="flex flex-wrap gap-2">
                                <a href="{{ route('admin.products.edit', $product) }}" class="btn btn-sm border border-input bg-background shadow-sm hover:bg-accent hover:text-accent-foreground">Edit</a>

                                @if ($product->is_active)
                                    <form method="POST" action="{{ route('admin.products.deactivate', $product) }}">
                                        @csrf
                                        <button type="submit" class="btn btn-sm border border-input bg-background shadow-sm hover:bg-accent hover:text-accent-foreground">Deactivate</button>
                                    </form>
                                @else
                                    <form method="POST" action="{{ route('admin.products.activate', $product) }}">
                                        @csrf
                                        <button type="submit" class="btn btn-sm btn-brand">Activate</button>
                                    </form>
                                @endif

                                <form method="POST" action="{{ route('admin.products.destroy', $product) }}" onsubmit="return confirm('Delete this product? This cannot be undone.');">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="btn btn-sm border border-[#CC001F]/40 text-[#CC001F] hover:bg-[#CC001F]/5">Delete</button>
                                </form>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" class="px-4 py-8 text-center text-muted-foreground">No products found.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-6">{{ $products->links() }}</div>
</x-layouts.admin>
