@use('Illuminate\Support\Facades\Storage')
{{--
    Commercial Partners — public listing (Phase 1.4C). `$partners` is
    already filtered to `is_active = true` and ordered by `display_order`
    then `name` by `CommercialPartnerController::show()` — this view never
    re-applies or second-guesses that query, only presents what the
    controller already decided is visible, same convention as every other
    public listing in this app.

    Name and description are plain, unsanitised strings (no rich-text
    editor exists for this entity, unlike Blog/News/CSR), so they are
    always rendered with Blade's escaping `{{ }}` — never `{!! !!}`.

    Each partner's `url` is re-validated here, at render time, before ever
    being used as a link target — not merely trusted because it already
    passed the `url` validation rule when an admin saved it. If it isn't a
    well-formed absolute http(s) URL, no link is rendered at all; nothing
    else on the page changes.
--}}
<x-layouts.public title="Commercial Partners">
    <x-public.hero>
        <x-slot:badge>Membership</x-slot:badge>
        <x-slot:lead>
            Aviation Club International works with a growing network of commercial partners to bring added value to our members.
        </x-slot:lead>
        Our commercial <span class="text-[#CC001F]">partners.</span>
    </x-public.hero>

    <section class="border-t border-border">
        <div class="container mx-auto px-4 py-16 md:py-20 lg:px-8">
            @if ($partners->isEmpty())
                <p class="rounded-lg border border-border bg-muted px-4 py-8 text-center text-sm text-muted-foreground" role="status">
                    No commercial partners are listed right now. Please check back soon.
                </p>
            @else
                <div class="grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
                    @foreach ($partners as $partner)
                        @php
                            $partnerUrl = null;
                            $scheme = $partner->url ? strtolower((string) parse_url($partner->url, PHP_URL_SCHEME)) : '';
                            if ($partner->url && filter_var($partner->url, FILTER_VALIDATE_URL) !== false && in_array($scheme, ['http', 'https'], true)) {
                                $partnerUrl = $partner->url;
                            }
                        @endphp
                        <div class="ui-card flex h-full flex-col overflow-hidden">
                            <div class="flex aspect-[16/9] w-full items-center justify-center overflow-hidden bg-muted p-6">
                                @if ($partner->logo_path)
                                    <img src="{{ Storage::disk('public')->url($partner->logo_path) }}" alt="{{ $partner->name }}" class="max-h-full max-w-full object-contain">
                                @else
                                    <svg class="h-10 w-10 text-muted-foreground/40" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 4h16v16H4z"/><path d="M4 9h16M9 4v16"/></svg>
                                @endif
                            </div>

                            <div class="flex flex-1 flex-col p-5">
                                <h2 class="font-display text-lg font-bold text-primary">{{ $partner->name }}</h2>

                                @if ($partner->description)
                                    <p class="mt-2 flex-1 text-sm text-muted-foreground">{{ $partner->description }}</p>
                                @endif

                                @if ($partnerUrl)
                                    <a href="{{ $partnerUrl }}" target="_blank" rel="noopener noreferrer" class="mt-4 inline-flex items-center gap-1 text-sm font-semibold text-primary hover:text-[#CC001F]">
                                        Visit website
                                        <svg class="h-3.5 w-3.5" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M7 17 17 7M7 7h10v10"/></svg>
                                    </a>
                                @endif
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif

            <div class="mt-10 flex justify-center">
                <a href="{{ route('membership.apply') }}" class="btn btn-lg btn-brand">Become a Member</a>
            </div>
        </div>
    </section>
</x-layouts.public>
