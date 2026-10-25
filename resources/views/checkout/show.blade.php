{{--
    Checkout: customer and delivery details and the payment method. Every
    price shown here is the *live* product price, same as the cart
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
                                <td class="px-4 py-3 font-semibold">LKR {{ number_format($cart->subtotal(), 2) }}</td>
                            </tr>
                            @if ($deliveryCharge !== null)
                                <tr>
                                    <td colspan="2" class="px-4 py-3 text-right font-semibold">Delivery (Sri Lanka)</td>
                                    <td class="px-4 py-3 font-semibold">LKR {{ $deliveryCharge }}</td>
                                </tr>
                                <tr class="border-t border-border">
                                    <td colspan="2" class="px-4 py-3 text-right font-semibold">Total to pay</td>
                                    <td class="px-4 py-3 font-display text-lg font-bold text-primary">LKR {{ $total }}</td>
                                </tr>
                            @endif
                        </tfoot>
                    </table>
                </div>

                <div class="ui-card p-6 md:p-7">
                    <h2 class="font-display text-lg font-bold text-primary">Your details</h2>

                    <form method="POST" action="{{ route('checkout.store') }}" class="mt-4 space-y-4">
                        @csrf
                        <x-form.input name="name" label="Full name" :value="old('name', $user->name ?? '')" maxlength="150" />
                        <x-form.input name="email" label="Email" type="email" :value="old('email', $user->email ?? '')" maxlength="255" />
                        <x-form.input name="phone" label="Phone" type="tel" :required="false" :value="old('phone')" maxlength="40" autocomplete="tel"
                            hint="International format, e.g. +94 77 123 4567. Required for PayHere." />

                        <fieldset class="space-y-4 border-t border-border pt-4">
                            <legend class="pt-4 font-display text-base font-bold text-primary">Delivery address</legend>
                            <x-form.input name="address_line1" label="Address line 1" :value="old('address_line1')" maxlength="191" autocomplete="address-line1" />
                            <x-form.input name="address_line2" label="Address line 2" :required="false" :value="old('address_line2')" maxlength="191" autocomplete="address-line2" />
                            <x-form.input name="city" label="City / town" :value="old('city')" maxlength="100" autocomplete="address-level2" />
                            <x-form.input name="postcode" label="Postcode" :required="false" :value="old('postcode')" maxlength="20" autocomplete="postal-code" />
                            <div class="space-y-1.5">
                                <label for="country" class="block text-sm font-medium leading-none">Country <span class="text-[#CC001F]" aria-hidden="true">*</span></label>
                                <select id="country" name="country" required autocomplete="country" class="field-control" @error('country') aria-invalid="true" @enderror>
                                    @foreach ($countries as $code => $countryName)
                                        <option value="{{ $code }}" @selected(old('country', array_key_first($countries)) === $code)>{{ $countryName }}</option>
                                    @endforeach
                                </select>
                                @error('country')
                                    <p class="text-xs text-destructive">{{ $message }}</p>
                                @enderror
                            </div>
                            <p class="text-xs text-muted-foreground">We deliver within Sri Lanka only. The delivery charge is included in the total to pay.</p>
                        </fieldset>

                        <fieldset class="space-y-2 border-t border-border pt-4">
                            <legend class="pt-4 font-display text-base font-bold text-primary">Payment method</legend>
                            @forelse ($paymentMethods as $method)
                                <label class="flex items-start gap-2 rounded-md border border-input p-3 text-sm">
                                    <input type="radio" name="payment_method" value="{{ $method }}" class="mt-0.5" required @checked(old('payment_method', count($paymentMethods) === 1 ? $method : null) === $method)>
                                    <span>
                                        @if ($method === 'bank_transfer')
                                            <span class="font-semibold">Bank transfer</span>
                                            <span class="block text-xs text-muted-foreground">Bank details are shown after you place the order. Your order is confirmed once we verify the transfer.</span>
                                        @elseif ($method === 'payhere')
                                            <span class="font-semibold">Card / online payment (PayHere)</span>
                                            <span class="block text-xs text-muted-foreground">You will be taken to PayHere's secure page. A phone number is required.</span>
                                        @else
                                            <span class="font-semibold text-[#CC001F]">Dummy / Test Payment — Development Only</span>
                                            <span class="block text-xs text-muted-foreground">Simulated payment for testing. No money is taken and the order is marked as a test.</span>
                                        @endif
                                    </span>
                                </label>
                            @empty
                                <p class="rounded-md border border-border bg-muted px-3 py-2 text-sm text-muted-foreground" role="status">
                                    @if ($deliveryCharge === null)
                                        Online ordering is not available yet — the delivery charge has not been set. Please try again later.
                                    @else
                                        Online ordering is not available right now — no payment method is configured. Please contact us.
                                    @endif
                                </p>
                            @endforelse
                            @error('payment_method')
                                <p class="text-xs text-destructive">{{ $message }}</p>
                            @enderror
                        </fieldset>

                        <button type="submit" class="btn btn-lg btn-brand w-full" @disabled($paymentMethods === [])>Place order</button>
                        <p class="text-xs text-muted-foreground">Your order is confirmed and a receipt sent only once payment is verified.</p>
                    </form>
                </div>
            </div>
        </div>
    </section>
</x-layouts.public>
