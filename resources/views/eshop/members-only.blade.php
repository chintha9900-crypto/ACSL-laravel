{{--
    Shown (HTTP 403) instead of a member-only product page to anyone not
    eligible to see it. Deliberately contains no product data at all — not
    its name, price, description, image or stock.
--}}
<x-layouts.public title="Members-only product">
    <section class="border-t border-border">
        <div class="container mx-auto px-4 py-16 lg:px-8">
            <a href="{{ route('eshop.index') }}" class="text-sm font-semibold text-[#CC001F] hover:underline">&larr; Back to shop</a>

            <div class="ui-card mt-6 max-w-xl p-6 md:p-8">
                <h1 class="font-display text-2xl font-bold text-primary">This product is for members only</h1>
                <p class="mt-2 text-sm text-muted-foreground">
                    @auth
                        It can be viewed and purchased only with an eligible Aviation Club membership — an active membership that is currently valid. Your account does not have one at the moment.
                    @else
                        Sign in with an Aviation Club account that has an eligible (current) membership to view it.
                    @endauth
                </p>
                <div class="mt-5 flex flex-wrap gap-3">
                    @guest
                        <a href="{{ route('login') }}" class="btn btn-lg btn-brand">Sign in</a>
                    @endguest
                    <a href="{{ route('eshop.index') }}" class="btn btn-lg border border-input bg-background shadow-sm hover:bg-accent hover:text-accent-foreground">Browse the shop</a>
                </div>
            </div>
        </div>
    </section>

    @include('eshop._members-only-dialog')
</x-layouts.public>
