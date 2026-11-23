@props(['title' => 'Aviation Club'])

{{-- The approved ACI logo is a controlled brand asset supplied by ACI (docs/frontend/08 C-02).
     Until it is added at public/images/aci-logo.png the name is shown as text; nothing is invented. --}}
@php($hasLogo = file_exists(public_path('images/aci-logo.png')))
{{--
    Every page these link to already exists as a real route — no nav entry
    ever points at an unbuilt page (Jobs and Education live in the red strip,
    not here). The red strip's "Sign Up" link covers "Become a Member" as
    a persistent call to action; it also appears inside the Membership
    dropdown/submenu below, which is a deliberate, explicitly requested
    exception to not repeating it in the flat nav.

    An item with a `children` array (currently "Membership" and "E-Shop")
    is a dropdown/disclosure, not a direct link only: the desktop
    nav renders it as a click-to-open `<details>` panel, the mobile menu as a
    nested `<details>` accordion — one shared source of navigation, not two.
    The footer is deliberately different: it shows Membership as a single
    plain link (`footerRoute`), not its children, and News & Events is one
    combined item everywhere (its own landing page, `news-events`) — the
    separate full lists (`news.index`/`events.index`) are reachable only
    from that landing page's own "View all" links, per the approved design.

    E-Shop Step 10.2 — "E-Shop" is a dropdown item that is *also* a direct
    link (`route` set alongside `children`): unlike Membership's trigger,
    its own label still navigates to `eshop.index`, with "Cart" as its one
    child (`cart.show`) rather than a second, separate top-level nav entry.
    The footer needs no `footerRoute` override here: with `route` already
    set, `route($link['footerRoute'] ?? $link['route'])` already resolves to
    `eshop.index`, the same single-plain-link behaviour Membership gets via
    its own `footerRoute`.

    Main-site nav update — "About" is a top-level item directly before
    "Contact", which is a plain link; CSR is reached from the red strip only,
    so neither is duplicated elsewhere in the nav. "Membership
    Benefits" is relabelled "Membership Types" (same `membership.benefits`
    route — only the displayed text changed), and "Commercial Partners" (a
    new, content-free template page — `commercial-partners`) joins it as a
    fifth Membership child.
--}}
@php($navLinks = [
    ['route' => 'home', 'label' => 'Home'],
    [
        'label' => 'Membership',
        'footerRoute' => 'membership.benefits',
        'children' => [
            ['route' => 'membership.benefits', 'label' => 'Membership Types'],
            ['route' => 'rules', 'label' => 'Club Rules'],
            ['route' => 'membership.apply', 'label' => 'Become a Member'],
            ['route' => 'faq', 'label' => 'FAQ'],
            ['route' => 'commercial-partners', 'label' => 'Commercial Partners'],
        ],
    ],
    [
        'route' => 'eshop.index',
        'label' => 'E-Shop',
        'children' => [
            ['route' => 'cart.show', 'label' => 'Cart'],
        ],
    ],
    ['route' => 'blog.index', 'label' => 'Blog'],
    ['route' => 'news-events', 'label' => 'News & Events'],
    // About sits directly before Contact; CSR lives only in the red strip, so
    // Contact has no dropdown children left and is a plain link. About stays
    // out of the footer, which never listed it.
    ['route' => 'about', 'label' => 'About', 'inFooter' => false],
    ['route' => 'contact', 'label' => 'Contact'],
])
{{-- Privacy/Terms are legal boilerplate, conventionally footer-only links —
     not repeated in the main nav (matches the reference footer's own
     separate "Legal" column). --}}
@php($legalLinks = [
    ['route' => 'privacy', 'label' => 'Privacy Policy'],
    ['route' => 'terms', 'label' => 'Terms & Conditions'],
])

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>{{ $title }} — Aviation Club</title>

        @if (file_exists(public_path('build/manifest.json')) || file_exists(public_path('hot')))
            @vite(['resources/css/app.css', 'resources/js/app.js'])
        @endif
    </head>
    <body class="theme-public flex min-h-screen flex-col bg-background font-sans text-foreground antialiased">
        <a href="#main" class="sr-only focus:not-sr-only focus:absolute focus:left-2 focus:top-2 focus:z-[60] focus:rounded-md focus:bg-background focus:px-3 focus:py-2 focus:text-sm">
            Skip to content
        </a>

        <header id="site-header" class="sticky top-0 z-50 w-full bg-background/95 backdrop-blur-sm transition-shadow data-[scrolled=true]:shadow-card">
            <div class="site-container flex h-16 items-center justify-between lg:h-20">
                <a href="{{ url('/') }}" class="flex items-center gap-2">
                    @if ($hasLogo)
                        <img src="{{ asset('images/aci-logo.png') }}" alt="Aviation Club" width="473" height="155" class="h-11 w-auto lg:h-14">
                    @else
                        <span class="font-display text-lg font-bold text-primary">Aviation Club</span>
                    @endif
                </a>

                <nav class="hidden items-center gap-1 uppercase lg:flex" aria-label="Main">
                    @foreach ($navLinks as $link)
                        @if (isset($link['children']))
                            @php($isActive = request()->routeIs(...array_filter([$link['route'] ?? null, ...array_column($link['children'], 'route')])))
                            {{-- Click-based dropdown via a native `<details>` — the same
                                 mechanism the mobile menu already uses below, not hover:
                                 hover-only dropdowns are unreliable (no keyboard
                                 equivalent, no touch-device equivalent, closes if the
                                 mouse crosses a gap). No JavaScript either way.

                                 When the item also has its own `route` (E-Shop), the
                                 label itself is a real `<a>` so clicking the text still
                                 navigates there; only Membership (no `route`) renders as
                                 plain, unclickable summary text. --}}
                            <details class="group relative">
                                <summary aria-haspopup="true" class="flex list-none cursor-pointer items-center gap-1 rounded-md px-3 py-2 text-sm font-medium text-foreground/75 transition-colors hover:text-primary [&::-webkit-details-marker]:hidden {{ $isActive ? 'font-semibold text-primary' : '' }}">
                                    @if (isset($link['route']))
                                        <a href="{{ route($link['route']) }}" @if (request()->routeIs($link['route'])) aria-current="page" @endif>{{ $link['label'] }}</a>
                                    @else
                                        {{ $link['label'] }}
                                    @endif
                                    <svg class="h-3.5 w-3.5 transition-transform group-open:rotate-180" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg>
                                </summary>

                                <div role="menu" class="absolute left-0 top-full z-20 mt-1 min-w-[220px] rounded-md border border-border bg-background p-1.5 shadow-elegant">
                                    @foreach ($link['children'] as $child)
                                        @php($childActive = request()->routeIs($child['route']))
                                        <a href="{{ route($child['route']) }}" @if ($childActive) aria-current="page" @endif role="menuitem" class="block rounded-md px-3 py-2 text-sm font-medium text-foreground/75 transition-colors hover:bg-muted hover:text-primary {{ $childActive ? 'bg-muted text-primary' : '' }}">{{ $child['label'] }}</a>
                                    @endforeach
                                </div>
                            </details>
                        @else
                            @php($isActive = request()->routeIs($link['route']))
                            <a href="{{ route($link['route']) }}" @if ($isActive) aria-current="page" @endif class="rounded-md px-3 py-2 text-sm font-medium text-foreground/75 transition-colors hover:text-primary {{ $isActive ? 'font-semibold text-primary' : '' }}">{{ $link['label'] }}</a>
                        @endif
                    @endforeach
                </nav>

                {{-- Mobile menu: a native disclosure, so it works without JavaScript. --}}
                <details class="group lg:hidden">
                    <summary class="flex cursor-pointer list-none items-center rounded-md p-2 text-foreground focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring [&::-webkit-details-marker]:hidden" aria-label="Toggle menu">
                        <svg class="h-6 w-6 group-open:hidden" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><line x1="4" x2="20" y1="12" y2="12"/><line x1="4" x2="20" y1="6" y2="6"/><line x1="4" x2="20" y1="18" y2="18"/></svg>
                        <svg class="hidden h-6 w-6 group-open:block" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>
                    </summary>

                    <div class="absolute inset-x-0 top-full border-t border-border bg-background">
                        <div class="site-container flex flex-col gap-1 py-3 uppercase">
                            @foreach ($navLinks as $link)
                                @if (isset($link['children']))
                                    @php($isActive = request()->routeIs(...array_filter([$link['route'] ?? null, ...array_column($link['children'], 'route')])))
                                    {{-- Same disclosure pattern as the outer mobile menu, nested
                                         one level: tap to expand, no JavaScript. When the item
                                         also has its own `route` (E-Shop), the label is a real
                                         `<a>` so tapping the text still navigates there. --}}
                                    <details class="group/submenu">
                                        <summary class="flex cursor-pointer list-none items-center justify-between rounded-md px-3 py-2 text-sm font-medium hover:bg-muted [&::-webkit-details-marker]:hidden {{ $isActive ? 'bg-muted text-primary' : '' }}">
                                            @if (isset($link['route']))
                                                <a href="{{ route($link['route']) }}" @if (request()->routeIs($link['route'])) aria-current="page" @endif>{{ $link['label'] }}</a>
                                            @else
                                                {{ $link['label'] }}
                                            @endif
                                            <svg class="h-4 w-4 transition-transform group-open/submenu:rotate-180" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg>
                                        </summary>
                                        <div class="ml-3 flex flex-col gap-1 border-l border-border py-1 pl-3">
                                            @foreach ($link['children'] as $child)
                                                @php($childActive = request()->routeIs($child['route']))
                                                <a href="{{ route($child['route']) }}" @if ($childActive) aria-current="page" @endif class="rounded-md px-3 py-2 text-sm font-medium hover:bg-muted {{ $childActive ? 'bg-muted text-primary' : '' }}">{{ $child['label'] }}</a>
                                            @endforeach
                                        </div>
                                    </details>
                                @else
                                    @php($isActive = request()->routeIs($link['route']))
                                    <a href="{{ route($link['route']) }}" @if ($isActive) aria-current="page" @endif class="rounded-md px-3 py-2 text-sm font-medium hover:bg-muted {{ $isActive ? 'bg-muted text-primary' : '' }}">{{ $link['label'] }}</a>
                                @endif
                            @endforeach
                        </div>
                    </div>
                </details>
            </div>

            {{-- Secondary red strip: part of the same sticky header, so both rows stick together. --}}
            <nav class="bg-[#CC001F] text-white" aria-label="Secondary">
                <ul class="site-container flex h-10 items-center justify-end gap-x-4 text-xs font-normal uppercase tracking-wide sm:gap-x-8 sm:text-sm">
                    <li><a href="{{ route('jobs') }}" @if (request()->routeIs('jobs', 'jobs.show')) aria-current="page" @endif class="hover:underline aria-[current=page]:font-bold">Jobs</a></li>
                    <li><a href="{{ route('education') }}" @if (request()->routeIs('education')) aria-current="page" @endif class="hover:underline aria-[current=page]:font-bold">Education</a></li>
                    <li><a href="{{ route('csr.index') }}" @if (request()->routeIs('csr.*')) aria-current="page" @endif class="hover:underline aria-[current=page]:font-bold">CSR</a></li>
                    <li><a href="{{ route('login') }}" @if (request()->routeIs('login')) aria-current="page" @endif class="hover:underline aria-[current=page]:font-bold">Login</a></li>
                    <li><a href="{{ route('membership.apply') }}" @if (request()->routeIs('membership.apply')) aria-current="page" @endif class="hover:underline aria-[current=page]:font-bold">Sign Up</a></li>
                </ul>
            </nav>
        </header>

        <main id="main" class="flex-1">
            {{ $slot }}
        </main>

        {{-- Footer background is the approved brand red itself (not the `primary`
             token, which is the dark-gray heading colour elsewhere on the site).
             `primary-foreground` (white) is unaffected, so the existing
             `text-primary-foreground/*` classes below still read correctly. --}}
        <footer class="mt-20 bg-[#CC001F] text-primary-foreground">
            <div class="site-container grid gap-10 py-14 md:grid-cols-2 lg:grid-cols-5">
                <div class="lg:col-span-2">
                    <a href="{{ url('/') }}" class="mb-4 inline-flex items-center gap-2">
                        @if ($hasLogo)
                            {{-- The logo's own text is dark, so it needs a light backing to
                                 read on this dark footer; the asset itself is untouched. --}}
                            <span class="inline-flex items-center rounded-md bg-white px-3 py-2">
                                <img src="{{ asset('images/aci-logo.png') }}" alt="Aviation Club" width="473" height="155" class="h-8 w-auto">
                            </span>
                        @else
                            <span class="grid h-9 w-9 place-items-center rounded-lg bg-secondary text-secondary-foreground">
                                <svg class="h-5 w-5 -rotate-45" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M17.8 19.2 16 11l3.5-3.5C21 6 21.5 4 21 3c-1-.5-3 0-4.5 1.5L13 8 4.8 6.2c-.5-.1-.9.1-1.1.5l-.3.5c-.2.5-.1 1 .3 1.3L9 12l-2 3H4l-1 1 3 2 2 3 1-1v-3l3-2 3.5 5.3c.3.4.8.5 1.3.3l.5-.2c.4-.3.6-.7.5-1.2z"/></svg>
                            </span>
                            <span class="font-display text-lg font-bold">Aviation Club</span>
                        @endif
                    </a>
                    <p class="text-sm leading-relaxed text-primary-foreground/70">
                        For people who work, study or take part in aviation.
                    </p>
                </div>

                <div>
                    <h4 class="mb-4 font-display text-base font-semibold">Explore</h4>
                    {{-- `hover:text-white` (not `hover:text-secondary`, now a mid-gray) keeps
                         the hover state legible against the brand-red background. "Home" is
                         left out here only — the logo above already links there, matching
                         the approved reference footer's own layout. --}}
                    <ul class="space-y-2 text-sm uppercase text-primary-foreground/75">
                        @foreach ($navLinks as $link)
                            @continue(($link['route'] ?? null) === 'home' || ($link['inFooter'] ?? true) === false)
                            {{-- The footer has no dropdown affordance: Membership shows as
                                 one normal link (`footerRoute`), not its four children. --}}
                            <li><a href="{{ route($link['footerRoute'] ?? $link['route']) }}" class="hover:text-white">{{ $link['label'] }}</a></li>
                        @endforeach
                        <li><a href="{{ route('education') }}" class="hover:text-white">Education</a></li>
                        <li><a href="{{ route('jobs') }}" class="hover:text-white">Jobs</a></li>
                        <li><a href="{{ route('login') }}" class="hover:text-white">Sign In</a></li>
                    </ul>
                </div>

                <div>
                    <h4 class="mb-4 font-display text-base font-semibold">Legal</h4>
                    <ul class="space-y-2 text-sm uppercase text-primary-foreground/75">
                        @foreach ($legalLinks as $link)
                            <li><a href="{{ route($link['route']) }}" class="hover:text-white">{{ $link['label'] }}</a></li>
                        @endforeach
                    </ul>
                </div>

                {{-- No real ACI email/phone/address exists anywhere in this project
                     (contact.blade.php's own contact-details cards are the same
                     confirmed placeholder state) — this column links to the real
                     contact form rather than inventing any of those details. --}}
                <div>
                    <h4 class="mb-4 font-display text-base font-semibold">Get in Touch</h4>
                    <ul class="space-y-2 text-sm uppercase text-primary-foreground/75">
                        <li><a href="{{ route('contact') }}" class="hover:text-white">Contact Us</a></li>
                    </ul>
                </div>
            </div>

            <div class="border-t border-white/10">
                <div class="site-container flex flex-col items-center justify-between gap-2 py-5 text-xs text-primary-foreground/60 md:flex-row">
                    <p>&copy; {{ date('Y') }} Aviation Club. All rights reserved.</p>
                    <p>Built for the global aviation community.</p>
                </div>
            </div>
        </footer>

        {{-- Header style change on scroll, as in the reference. --}}
        <script>
            (function () {
                var header = document.getElementById('site-header');
                function update() { header.dataset.scrolled = window.scrollY > 8 ? 'true' : 'false'; }
                update();
                window.addEventListener('scroll', update, { passive: true });
            })();
        </script>
    </body>
</html>
