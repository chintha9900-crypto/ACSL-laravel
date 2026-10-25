@use('Illuminate\Support\Facades\Storage')
{{--
    E-Shop product detail page. `EshopController::show()` already 404s an
    inactive or (for the current viewer) inaccessible product before this
    view ever renders, so everything here can assume the product is
    genuinely visible to whoever is looking at it. "Add to Cart" posts to
    `cart.items.store` (E-Shop Step 5), which revalidates access and stock
    itself server-side regardless of what this page shows.
--}}
<x-layouts.public :title="$product->name">
    <section class="border-t border-border">
        <div class="container mx-auto px-4 py-12 lg:px-8">
            <a href="{{ route('eshop.index') }}" class="text-sm font-semibold text-[#CC001F] hover:underline">&larr; Back to shop</a>

            <div class="mt-6 grid gap-10 lg:grid-cols-2">
                <div>
                    @php($image = $product->images->first())
                    @if ($image)
                        <img data-fallback data-gallery-main src="{{ $product->imageUrl($image) }}" alt="{{ $image->alt_text ?: $product->name }}" class="aspect-square w-full rounded-xl border border-border object-cover">

                        @php($galleryImages = $product->images->take((int) config('uploads.product_image.max_files')))
                        @if ($galleryImages->count() > 1)
                            {{-- Gallery: thumbnails swap the main image; without JavaScript they open the full image. --}}
                            <ul class="mt-3 grid grid-cols-4 gap-2" aria-label="{{ $product->name }} photos" data-gallery-thumbs>
                                @foreach ($galleryImages as $galleryImage)
                                    @php($galleryUrl = $product->imageUrl($galleryImage))
                                    <li>
                                        <a href="{{ $galleryUrl }}" data-gallery-thumb data-full="{{ $galleryUrl }}" data-alt="{{ $galleryImage->alt_text ?: $product->name.' — photo '.$loop->iteration }}"
                                            @if ($loop->first) aria-current="true" @endif
                                            class="block overflow-hidden rounded-lg border-2 border-transparent focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring aria-[current=true]:border-[#CC001F]">
                                            <img data-fallback src="{{ $galleryUrl }}" alt="{{ $product->name }} — photo {{ $loop->iteration }}" loading="lazy" class="aspect-square w-full object-cover">
                                        </a>
                                    </li>
                                @endforeach
                            </ul>
                            <script>
                                (function () {
                                    var main = document.querySelector('[data-gallery-main]');
                                    var thumbs = document.querySelectorAll('[data-gallery-thumb]');
                                    if (!main) { return; }
                                    Array.prototype.forEach.call(thumbs, function (thumb) {
                                        thumb.addEventListener('click', function (event) {
                                            event.preventDefault();
                                            main.src = thumb.getAttribute('data-full');
                                            main.alt = thumb.getAttribute('data-alt');
                                            Array.prototype.forEach.call(thumbs, function (other) { other.removeAttribute('aria-current'); });
                                            thumb.setAttribute('aria-current', 'true');
                                        });
                                    });
                                })();
                            </script>
                        @endif
                    @else
                        <div class="grid aspect-square w-full place-items-center rounded-xl border border-border bg-[#4D4D4D] text-white/40">
                            <svg class="h-16 w-16" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 4h16v16H4z"/><path d="M4 9h16M9 4v16"/></svg>
                        </div>
                    @endif
                </div>

                <div>
                    @if ($product->access_type === 'MEMBER_ONLY')
                        <span class="mb-3 inline-flex w-fit items-center rounded-md border border-border bg-muted px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-muted-foreground">Members only</span>
                    @endif

                    <h1 class="font-display text-3xl font-bold text-primary md:text-4xl">{{ $product->name }}</h1>
                    <p class="mt-1 text-sm text-muted-foreground">{{ $product->category?->name }}</p>

                    <p class="mt-4 font-display text-2xl font-bold text-primary">LKR {{ number_format((float) $product->price, 2) }}</p>

                    @php($quantity = $product->inventory?->quantity ?? 0)
                    <div class="mt-4">
                        @if ($quantity <= 0)
                            <span class="inline-flex items-center rounded-md border border-[#CC001F]/40 bg-[#CC001F]/5 px-2.5 py-0.5 text-xs font-semibold text-[#CC001F]">Out of stock</span>
                        @else
                            <span class="inline-flex items-center rounded-md border border-transparent bg-primary px-2.5 py-0.5 text-xs font-semibold text-primary-foreground">In stock</span>
                        @endif
                    </div>

                    @if ($product->description)
                        <p class="mt-5 whitespace-pre-line text-sm leading-relaxed text-muted-foreground">{{ $product->description }}</p>
                    @endif

                    <div class="mt-6">
                        @if ($quantity > 0)
                            <form method="POST" action="{{ route('cart.items.store') }}" class="flex flex-wrap items-center gap-2">
                                @csrf
                                <input type="hidden" name="product_id" value="{{ $product->id }}">
                                <input type="number" name="quantity" value="1" min="1" max="{{ $quantity }}" class="field-control h-10 w-20">
                                <button type="submit" class="btn btn-lg btn-brand" data-add-to-cart data-product="{{ $product->slug }}">Add to Cart</button>
                            </form>
                            <p class="mt-2 text-xs text-muted-foreground">Add to your cart, then check out from the <a href="{{ route('cart.show') }}" class="font-semibold text-[#CC001F] hover:underline">cart</a>.</p>
                        @else
                            <button type="button" disabled class="btn btn-lg btn-brand w-full cursor-not-allowed opacity-60 sm:w-auto">Out of stock</button>
                        @endif
                    </div>

                    <p class="mt-6 text-xs text-muted-foreground">SKU: {{ $product->sku }}</p>
                </div>
            </div>
        </div>
    </section>
</x-layouts.public>
