{{--
    E-Shop Step 6 — checkout only (no real payment gateway/receipts yet).
    Every price shown here is the *live* product price, same as the cart
    page; nothing submitted by this form can change what
    `App\Actions\Checkout\PlaceOrder` actually charges, since it re-reads
    everything from the database itself.
--}}
<x-layouts.public title="Checkout">
    <section class="border-t border-border">
        <div class="container mx-auto px-4 py-12 lg:px-8">
            <h1 class="font-display text-3xl font-bold text-primary md:text-4xl">Checkout</h1>

            @if ($errors->any())
                <div class="mt-4 w-full rounded-lg border border-[#CC001F]/50 bg-[#CC001F]/5 px-4 py-3 text-sm text-[#CC001F]" role="alert">
                    @foreach ($errors->all() as $message)
                        <p>{{ $message }}</p>
                    @endforeach
                </div>
            @endif

            <div class="mt-8 grid gap-8 lg:grid-cols-3">
                <div class="ui-card lg:col-span-2 overflow-x-auto">
                    <table class="w-full min-w-[32rem] text-left text-sm">
                        <thead class="border-b border-border text-xs uppercase tracking-wide text-muted-foreground">
                            <tr>
                                <th scope="col" class="px-4 py-3 font-medium">Product</th>
                                <th scope="col" class="px-4 py-3 font-medium">Quantity</th>
                                <th scope="col" class="px-4 py-3 font-medium">Line total</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-border">
                            @foreach ($cart->items as $item)
                                <tr>
                                    <td class="px-4 py-3">{{ $item->product->name }}</td>
                                    <td class="px-4 py-3">{{ $item->quantity }}</td>
                                    <td class="px-4 py-3">LKR {{ number_format($item->quantity * (float) $item->product->price, 2) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                        <tfoot>
                            <tr class="border-t border-border">
                                <td colspan="2" class="px-4 py-3 text-right font-semibold">Subtotal</td>
                                <td class="px-4 py-3 font-display text-lg font-bold text-primary">LKR {{ number_format($cart->subtotal(), 2) }}</td>
                            </tr>
                        </tfoot>
                    </table>
                </div>

                <div class="ui-card p-6 md:p-7">
                    <h2 class="font-display text-lg font-bold text-primary">Your details</h2>

                    <form method="POST" action="{{ route('checkout.store') }}" class="mt-4 space-y-4">
                        @csrf
                        <x-form.input name="name" label="Full name" :value="old('name', $user->name ?? '')" maxlength="150" />
                        <x-form.input name="email" label="Email" type="email" :value="old('email', $user->email ?? '')" maxlength="255" />
                        <x-form.input name="phone" label="Phone" :required="false" :value="old('phone')" maxlength="40" />

                        <button type="submit" class="btn btn-lg btn-brand w-full">Place order</button>
                        <p class="text-xs text-muted-foreground">Payment instructions follow once your order is placed. Checkout does not process a real payment yet.</p>
                    </form>
                </div>
            </div>
        </div>
    </section>
</x-layouts.public>
