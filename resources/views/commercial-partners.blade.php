@use('Illuminate\Support\Facades\Storage')
{{--
    Commercial Partners — public listing (Phase 1.4C). `$partners` is
    already filtered to `is_active = true` and ordered by `display_order`
    then `name` by `CommercialPartnerController::show()` — this view never
    re-applies or second-guesses that query, only presents what the
    controller already decided is visible, same convention as every other
    public listing in this app.

    Logos only: the partner name is used solely as escaped alt text / link
    label for screen readers; description and other fields are not shown.

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
            Aviation Club works with a growing network of commercial partners to bring added value to our members.
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
                {{-- Logos only, on clean borderless square tiles: the name is
                     never shown, only used as the logo's alt text / link label.
                     Each logo sits in a 2:1 box inside its square tile and keeps
                     its proportions (object-contain). Columns (1 centred / 3 / 4)
                     and padding are sized so logos render at about 1.5× their
                     previous size at each breakpoint. --}}
                <ul class="grid grid-cols-1 justify-items-center gap-4 sm:grid-cols-3 sm:justify-items-stretch lg:grid-cols-4" role="list" aria-label="Commercial partners">
                    @foreach ($partners as $partner)
                        @php
                            $partnerUrl = null;
                            $scheme = $partner->url ? strtolower((string) parse_url($partner->url, PHP_URL_SCHEME)) : '';
                            if ($partner->url && filter_var($partner->url, FILTER_VALIDATE_URL) !== false && in_array($scheme, ['http', 'https'], true)) {
                                $partnerUrl = $partner->url;
                            }
                        @endphp
                        <li class="w-full max-w-[15rem] sm:max-w-none" data-partner-logo>
                            @if ($partnerUrl)
                                <a href="{{ $partnerUrl }}" target="_blank" rel="noopener noreferrer" aria-label="{{ $partner->name }} (opens in a new tab)"
                                    class="flex aspect-square w-full items-center justify-center rounded-md p-3 transition-opacity hover:opacity-80 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring lg:p-6">
                            @else
                                <div class="flex aspect-square w-full items-center justify-center p-3 lg:p-6">
                            @endif
                                <span class="flex aspect-[2/1] w-full items-center justify-center">
                                    @if ($partner->logo_path)
                                        <img data-fallback src="{{ Storage::disk('public')->url($partner->logo_path) }}" alt="{{ $partner->name }}" loading="lazy" class="max-h-full max-w-full object-contain">
                                    @else
                                        <svg class="h-12 w-12 text-muted-foreground/40" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 4h16v16H4z"/><path d="M4 9h16M9 4v16"/></svg>
                                        <span class="sr-only">{{ $partner->name }}</span>
                                    @endif
                                </span>
                            @if ($partnerUrl)
                                </a>
                            @else
                                </div>
                            @endif
                        </li>
                    @endforeach
                </ul>
            @endif

            <div class="mt-10 flex justify-center">
                <a href="{{ route('membership.apply') }}" class="btn btn-lg btn-brand">Become a Member</a>
            </div>
        </div>
    </section>
</x-layouts.public>
