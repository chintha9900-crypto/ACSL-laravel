{{--
    E-Shop Step 5 — cart only (no checkout yet). The subtotal and every line
    total here come from `Cart::subtotal()`/`$item->product->price` — the
    product's *live* price — never anything submitted by a form; there is no
    price field anywhere on this page for a client to tamper with.
--}}
<x-layouts.public title="Your Cart">
    <section class="border-t border-border">
        <div class="container mx-auto px-4 py-12 lg:px-8">
            <h1 class="font-display text-3xl font-bold text-primary md:text-4xl">Your Cart</h1>

            @if (session('status'))
                <p class="mt-4 rounded-lg border border-secondary/40 bg-secondary/10 px-4 py-3 text-sm text-primary" role="status">{{ session('status') }}</p>
            @endif

            @if ($errors->any())
                <div class="mt-4 w-full rounded-lg border border-[#CC001F]/50 bg-[#CC001F]/5 px-4 py-3 text-sm text-[#CC001F]" role="alert">
                    @foreach ($errors->all() as $message)
                        <p>{{ $message }}</p>
                    @endforeach
                </div>
            @endif

            @if ($cart->items->isEmpty())
                <p class="mt-8 rounded-lg border border-border bg-muted px-4 py-8 text-center text-sm text-muted-foreground" role="status">
                    Your cart is empty. <a href="{{ route('eshop.index') }}" class="font-semibold text-[#CC001F] hover:underline">Browse the shop</a>.
                </p>
            @else
                <div class="ui-card mt-8 overflow-x-auto">
                    <table class="w-full min-w-[48rem] text-left text-sm">
                        <thead class="border-b border-border text-xs uppercase tracking-wide text-muted-foreground">
                            <tr>
                                <th scope="col" class="px-4 py-3 font-medium">Product</th>
                                <th scope="col" class="px-4 py-3 font-medium">Price</th>
                                <th scope="col" class="px-4 py-3 font-medium">Quantity</th>
                                <th scope="col" class="px-4 py-3 font-medium">Line total</th>
                                <th scope="col" class="px-4 py-3 font-medium">Stock</th>
                                <th scope="col" class="px-4 py-3 font-medium">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-border">
                            @foreach ($cart->items as $item)
                                @php($available = $item->product->inventory?->quantity ?? 0)
                                <tr>
                                    <td class="px-4 py-3">
                                        <a href="{{ route('eshop.show', $item->product) }}" class="font-medium text-primary hover:text-[#CC001F]">{{ $item->product->name }}</a>
                                    </td>
                                    <td class="px-4 py-3">LKR {{ number_format((float) $item->product->price, 2) }}</td>
                                    <td class="px-4 py-3">
                                        <div class="flex items-center gap-1">
                                            <form method="POST" action="{{ route('cart.items.update', $item) }}">
                                                @csrf
                                                @method('PATCH')
                                                <input type="hidden" name="quantity" value="{{ max(1, $item->quantity - 1) }}">
                                                <button type="submit" class="btn btn-sm border border-input bg-background shadow-sm hover:bg-accent hover:text-accent-foreground" aria-label="Decrease quantity">&minus;</button>
                                            </form>

                                            <form method="POST" action="{{ route('cart.items.update', $item) }}" class="flex items-center gap-1">
                                                @csrf
                                                @method('PATCH')
                                                <input type="number" name="quantity" value="{{ $item->quantity }}" min="1" class="field-control h-8 w-16 text-center">
                                                <button type="submit" class="btn btn-sm border border-input bg-background shadow-sm hover:bg-accent hover:text-accent-foreground">Update</button>
                                            </form>

                                            <form method="POST" action="{{ route('cart.items.update', $item) }}">
                                                @csrf
                                                @method('PATCH')
                                                <input type="hidden" name="quantity" value="{{ $item->quantity + 1 }}">
                                                <button type="submit" class="btn btn-sm border border-input bg-background shadow-sm hover:bg-accent hover:text-accent-foreground" aria-label="Increase quantity">&plus;</button>
                                            </form>
                                        </div>
                                    </td>
                                    <td class="px-4 py-3 font-semibold">LKR {{ number_format($item->quantity * (float) $item->product->price, 2) }}</td>
                                    <td class="px-4 py-3">
                                        @if ($item->quantity > $available)
                                            <span class="inline-flex items-center rounded-md border border-[#CC001F]/40 bg-[#CC001F]/5 px-2.5 py-0.5 text-xs font-semibold text-[#CC001F]">Only {{ $available }} left</span>
                                        @else
                                            <span class="inline-flex items-center rounded-md border border-transparent bg-primary px-2.5 py-0.5 text-xs font-semibold text-primary-foreground">{{ $available }} available</span>
                                        @endif
                                    </td>
                                    <td class="px-4 py-3">
                                        <form method="POST" action="{{ route('cart.items.destroy', $item) }}">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="btn btn-sm border border-[#CC001F]/40 text-[#CC001F] hover:bg-[#CC001F]/5">Remove</button>
                                        </form>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <div class="mt-6 flex flex-wrap items-center justify-between gap-4">
                    <form method="POST" action="{{ route('cart.clear') }}" onsubmit="return confirm('Empty your cart?');">
                        @csrf
                        @method('DELETE')
                        <button type="submit" class="btn btn-sm border border-input bg-background shadow-sm hover:bg-accent hover:text-accent-foreground">Empty cart</button>
                    </form>

                    <p class="font-display text-xl font-bold text-primary">Subtotal: LKR {{ number_format($cart->subtotal(), 2) }}</p>
                </div>

                <div class="mt-6">
                    <a href="{{ route('checkout.show') }}" class="btn btn-lg btn-brand">Proceed to Checkout</a>
                </div>
            @endif
        </div>
    </section>
</x-layouts.public>
