@use('Illuminate\Support\Facades\Storage')
<x-layouts.admin :title="$product->name">
    <div class="flex flex-wrap items-center justify-between gap-4">
        <h1 class="font-display text-2xl font-bold text-primary md:text-3xl">{{ $product->name }}</h1>
        <div class="flex gap-2">
            <a href="{{ route('admin.products.edit', $product) }}" class="btn btn-sm border border-input bg-background shadow-sm hover:bg-accent hover:text-accent-foreground">Edit</a>
            <a href="{{ route('admin.products.index') }}" class="btn btn-sm border border-input bg-background shadow-sm hover:bg-accent hover:text-accent-foreground">Back to products</a>
        </div>
    </div>

    <div class="ui-card mt-6 grid gap-6 p-6 md:grid-cols-2 md:p-8">
        <div>
            @php($image = $product->images->sortBy('display_order')->first())
            @if ($image)
                <img src="{{ Storage::disk('public')->url($image->image_path) }}" alt="{{ $image->alt_text ?? $product->name }}" class="w-full rounded-lg border border-border object-cover">
            @else
                <div class="grid aspect-square w-full place-items-center rounded-lg border border-border bg-muted text-sm text-muted-foreground">
                    No image uploaded
                </div>
            @endif
        </div>

        <dl class="space-y-4 text-sm">
            <div>
                <dt class="font-medium text-muted-foreground">SKU</dt>
                <dd class="font-mono">{{ $product->sku }}</dd>
            </div>
            <div>
                <dt class="font-medium text-muted-foreground">Category</dt>
                <dd>{{ $product->category?->name ?? '—' }}</dd>
            </div>
            <div>
                <dt class="font-medium text-muted-foreground">Price</dt>
                <dd>LKR {{ number_format((float) $product->price, 2) }}</dd>
            </div>
            <div>
                <dt class="font-medium text-muted-foreground">Access type</dt>
                <dd>{{ $product->access_type === 'MEMBER_ONLY' ? 'Member only' : 'Public' }}</dd>
            </div>
            <div>
                <dt class="font-medium text-muted-foreground">Stock quantity</dt>
                <dd>{{ $product->inventory?->quantity ?? 0 }}</dd>
            </div>
            <div>
                <dt class="font-medium text-muted-foreground">Status</dt>
                <dd>
                    <span class="inline-flex items-center rounded-md border px-2.5 py-0.5 text-xs font-semibold {{ $product->is_active ? 'border-transparent bg-primary text-primary-foreground' : 'border-border bg-muted text-foreground' }}">
                        {{ $product->is_active ? 'Active' : 'Inactive' }}
                    </span>
                </dd>
            </div>
            @if ($product->description)
                <div>
                    <dt class="font-medium text-muted-foreground">Description</dt>
                    <dd class="whitespace-pre-line">{{ $product->description }}</dd>
                </div>
            @endif
        </dl>
    </div>
</x-layouts.admin>
