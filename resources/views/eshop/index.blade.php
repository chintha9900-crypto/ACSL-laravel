@use('Illuminate\Support\Facades\Storage')
{{--
    E-Shop catalogue — visual redesign only (sidebar + 4-column grid on
    desktop, 2 on tablet, 1 on mobile).
    `Product::scopeVisibleTo()` (applied in EshopController::index()) is
    still the only access rule: guests and non-active members only ever
    receive `PUBLIC` products in `$products`; this view never re-applies or
    second-guesses that filtering — it only presents what the controller
    already decided the viewer may see. No controller, model, or route was
    touched for this redesign.

    The database currently has zero products/categories, so this view falls
    back to realistic example cards/categories purely to preview the
    layout — see eshop/_filters.blade.php and the `$placeholderProducts`
    block below. Every placeholder is visually inert (no real link, no
    "View Product" action) so it can never be mistaken for a real,
    purchasable item; a clearly worded notice says so above the grid.
--}}
<x-layouts.public title="E-Shop">
    <x-public.hero>
        <x-slot:badge>E-Shop</x-slot:badge>
        <x-slot:lead>
            Aviation Club International merchandise and member benefits — browse what's available to you.
        </x-slot:lead>
        Club <span class="text-[#CC001F]">shop.</span>
    </x-public.hero>

    <section class="border-t border-border">
        <div class="container mx-auto px-4 py-16 md:py-20 lg:px-8">
            <div class="lg:grid lg:grid-cols-[260px_minmax(0,1fr)] lg:items-start lg:gap-10">
                {{-- Mobile/tablet: filters collapse into one disclosure button, same
                     native <details> pattern (no JavaScript) the site nav already uses. --}}
                <details class="group mb-8 lg:hidden">
                    <summary class="flex cursor-pointer list-none items-center justify-between rounded-md border border-border bg-background px-4 py-3 text-sm font-semibold text-primary [&::-webkit-details-marker]:hidden">
                        <span class="flex items-center gap-2">
                            <svg class="h-4 w-4" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M22 3H2l8 9.46V19l4 2v-8.54L22 3z"/></svg>
                            Filters
                        </span>
                        <svg class="h-4 w-4 transition-transform group-open:rotate-180" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg>
                    </summary>
                    <div class="mt-3 rounded-md border border-border bg-background p-4">
                        @include('eshop._filters')
                    </div>
                </details>

                {{-- Desktop: a persistent sidebar, never collapsed. --}}
                <aside class="hidden lg:block lg:border-r lg:border-border lg:pr-8">
                    @include('eshop._filters')
                </aside>

                <div>
                    <div class="flex flex-wrap items-end justify-between gap-4">
                        <div>
                            <h2 class="font-display text-2xl font-bold text-primary md:text-3xl">E-Shop</h2>
                            <p class="mt-1 text-sm text-muted-foreground">Official Aviation Club International merchandise and member benefits.</p>
                        </div>
                        <p class="text-sm font-medium text-muted-foreground">
                            @if ($products->isNotEmpty())
                                {{ $products->total() }} {{ Str::plural('product', $products->total()) }}
                            @else
                                Example preview
                            @endif
                        </p>
                    </div>

                    @if ($products->isNotEmpty())
                        <div class="mt-8 grid gap-6 sm:grid-cols-2 lg:grid-cols-4">
                            @foreach ($products as $product)
                                @php($image = $product->images->first())
                                <div class="ui-card flex h-full flex-col overflow-hidden">
                                    <a href="{{ route('eshop.show', $product) }}" class="aspect-[16/10] w-full overflow-hidden bg-[#4D4D4D]">
                                        @if ($image)
                                            <img src="{{ Storage::disk('public')->url($image->image_path) }}" alt="{{ $product->name }}" class="h-full w-full object-cover">
                                        @else
                                            <div class="grid h-full w-full place-items-center">
                                                <svg class="h-10 w-10 text-white/40" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 4h16v16H4z"/><path d="M4 9h16M9 4v16"/></svg>
                                            </div>
                                        @endif
                                    </a>

                                    <div class="flex flex-1 flex-col p-5">
                                        @if ($product->access_type === 'MEMBER_ONLY')
                                            <span class="mb-2 inline-flex w-fit items-center rounded-md border border-border bg-muted px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-muted-foreground">Members only</span>
                                        @endif

                                        <h3 class="line-clamp-2 font-display text-lg font-bold text-primary">
                                            <a href="{{ route('eshop.show', $product) }}" class="hover:text-[#CC001F]">{{ $product->name }}</a>
                                        </h3>

                                        @if ($product->description)
                                            <p class="mt-1 line-clamp-2 text-sm text-muted-foreground">{{ $product->description }}</p>
                                        @endif

                                        <p class="mt-3 font-display text-xl font-bold text-primary">LKR {{ number_format((float) $product->price, 2) }}</p>

                                        <div class="mt-auto pt-4">
                                            @if (($product->inventory?->quantity ?? 0) <= 0)
                                                <span class="inline-flex items-center rounded-md border border-[#CC001F]/40 bg-[#CC001F]/5 px-2.5 py-0.5 text-xs font-semibold text-[#CC001F]">Out of stock</span>
                                            @else
                                                <a href="{{ route('eshop.show', $product) }}" class="btn btn-lg btn-brand w-full">View Product</a>
                                            @endif
                                        </div>
                                    </div>
                                </div>
                            @endforeach
                        </div>

                        <div class="mt-10">{{ $products->links() }}</div>
                    @else
                        @php($placeholderProducts = [
                            ['name' => 'ACI Pilot Logbook', 'description' => 'Hardbound logbook for recording flight hours, endorsed by Aviation Club International.', 'price' => 2500, 'access' => 'PUBLIC', 'outOfStock' => false],
                            ['name' => 'Club Polo Shirt', 'description' => 'Breathable, embroidered polo shirt in club colours. Available in multiple sizes.', 'price' => 4500, 'access' => 'PUBLIC', 'outOfStock' => false],
                            ['name' => 'Member Enamel Pin Set', 'description' => 'Limited-edition enamel pin set, exclusive to active ACI members.', 'price' => 1800, 'access' => 'MEMBER_ONLY', 'outOfStock' => false],
                            ['name' => 'Flight Planning Kit', 'description' => 'Compact kit with plotter, chinagraph pencils and a quick-reference card.', 'price' => 3200, 'access' => 'PUBLIC', 'outOfStock' => true],
                            ['name' => 'Aviation Safety Cap', 'description' => 'Adjustable cap with the club crest, suited for hangar and ramp wear.', 'price' => 2200, 'access' => 'PUBLIC', 'outOfStock' => false],
                            ['name' => 'Member Lanyard & Card Holder', 'description' => 'Durable lanyard and card holder for your ACI membership card.', 'price' => 1200, 'access' => 'MEMBER_ONLY', 'outOfStock' => false],
                        ])

                        <p class="mt-6 rounded-lg border border-border bg-muted px-4 py-3 text-sm text-muted-foreground" role="status">
                            No products are available right now. The example cards below preview how the catalogue will look once products are added in Admin → Products.
                        </p>

                        <div class="mt-6 grid gap-6 sm:grid-cols-2 lg:grid-cols-4">
                            @foreach ($placeholderProducts as $placeholder)
                                <div class="ui-card flex h-full flex-col overflow-hidden opacity-95">
                                    <span class="flex aspect-[16/10] w-full items-center justify-center overflow-hidden bg-[#4D4D4D]">
                                        <svg class="h-10 w-10 text-white/40" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 4h16v16H4z"/><path d="M4 9h16M9 4v16"/></svg>
                                    </span>

                                    <div class="flex flex-1 flex-col p-5">
                                        @if ($placeholder['access'] === 'MEMBER_ONLY')
                                            <span class="mb-2 inline-flex w-fit items-center rounded-md border border-border bg-muted px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-muted-foreground">Members only</span>
                                        @endif

                                        <h3 class="line-clamp-2 font-display text-lg font-bold text-primary">{{ $placeholder['name'] }}</h3>
                                        <p class="mt-1 line-clamp-2 text-sm text-muted-foreground">{{ $placeholder['description'] }}</p>
                                        <p class="mt-3 font-display text-xl font-bold text-primary">LKR {{ number_format($placeholder['price'], 2) }}</p>

                                        <div class="mt-auto pt-4">
                                            @if ($placeholder['outOfStock'])
                                                <span class="inline-flex items-center rounded-md border border-[#CC001F]/40 bg-[#CC001F]/5 px-2.5 py-0.5 text-xs font-semibold text-[#CC001F]">Out of stock</span>
                                            @else
                                                <span class="btn btn-lg btn-brand w-full cursor-default opacity-90" aria-disabled="true">View Product</span>
                                            @endif
                                        </div>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    @endif
                </div>
            </div>
        </div>
    </section>
</x-layouts.public>
