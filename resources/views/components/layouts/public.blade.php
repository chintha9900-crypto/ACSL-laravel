@props(['title' => 'Aviation Club International'])

{{-- The approved ACI logo is a controlled brand asset supplied by ACI (docs/frontend/08 C-02).
     Until it is added at public/images/aci-logo.png the name is shown as text; nothing is invented. --}}
@php($hasLogo = file_exists(public_path('images/aci-logo.png')))
{{--
    Every page these link to already exists as a real route — no nav entry
    ever points at an unbuilt page (News/Jobs aren't linked because they
    don't exist yet). "Become a Member" isn't repeated here: the red "Join
    Now"/"Become a Member" button already covers it, so the text nav doesn't
    duplicate it.
--}}
@php($navLinks = [
    ['route' => 'home', 'label' => 'Home'],
    ['route' => 'about', 'label' => 'About'],
    ['route' => 'membership.benefits', 'label' => 'Membership'],
    ['route' => 'rules', 'label' => 'Rules'],
    ['route' => 'faq', 'label' => 'FAQ'],
    ['route' => 'blog.index', 'label' => 'Blog'],
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
        <title>{{ $title }} — Aviation Club International</title>

        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link href="https://fonts.googleapis.com/css2?family=Sora:wght@400;500;600;700;800&family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">

        @if (file_exists(public_path('build/manifest.json')) || file_exists(public_path('hot')))
            @vite(['resources/css/app.css', 'resources/js/app.js'])
        @endif
    </head>
    <body class="theme-public flex min-h-screen flex-col bg-background font-sans text-foreground antialiased">
        <a href="#main" class="sr-only focus:not-sr-only focus:absolute focus:left-2 focus:top-2 focus:z-[60] focus:rounded-md focus:bg-background focus:px-3 focus:py-2 focus:text-sm">
            Skip to content
        </a>

        <header id="site-header" class="sticky top-0 z-50 w-full border-b border-border bg-background/95 backdrop-blur-sm transition-shadow data-[scrolled=true]:shadow-card">
            <div class="container mx-auto flex h-16 items-center justify-between px-4 lg:h-20 lg:px-8">
                <a href="{{ url('/') }}" class="flex items-center gap-2">
                    @if ($hasLogo)
                        <img src="{{ asset('images/aci-logo.png') }}" alt="Aviation Club International" width="473" height="155" class="h-11 w-auto lg:h-14">
                    @else
                        <span class="font-display text-lg font-bold text-primary">Aviation Club International</span>
                    @endif
                </a>

                <nav class="hidden items-center gap-1 lg:flex" aria-label="Main">
                    @foreach ($navLinks as $link)
                        @php($isActive = request()->routeIs($link['route']))
                        <a href="{{ route($link['route']) }}" @if ($isActive) aria-current="page" @endif class="rounded-md px-3 py-2 text-sm font-medium text-foreground/75 transition-colors hover:text-primary {{ $isActive ? 'font-semibold text-primary' : '' }}">{{ $link['label'] }}</a>
                    @endforeach
                </nav>

                <div class="hidden items-center gap-3 lg:flex">
                    <a href="{{ route('login') }}" class="btn btn-sm btn-ghost">Sign In</a>
                    <a href="{{ route('membership.apply') }}" class="btn btn-sm btn-brand">Join Now</a>
                </div>

                {{-- Mobile menu: a native disclosure, so it works without JavaScript. --}}
                <details class="group lg:hidden">
                    <summary class="flex cursor-pointer list-none items-center rounded-md p-2 text-foreground focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring [&::-webkit-details-marker]:hidden" aria-label="Toggle menu">
                        <svg class="h-6 w-6 group-open:hidden" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><line x1="4" x2="20" y1="12" y2="12"/><line x1="4" x2="20" y1="6" y2="6"/><line x1="4" x2="20" y1="18" y2="18"/></svg>
                        <svg class="hidden h-6 w-6 group-open:block" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>
                    </summary>

                    <div class="absolute inset-x-0 top-16 border-t border-border bg-background">
                        <div class="container mx-auto flex flex-col gap-1 px-4 py-3">
                            @foreach ($navLinks as $link)
                                @php($isActive = request()->routeIs($link['route']))
                                <a href="{{ route($link['route']) }}" @if ($isActive) aria-current="page" @endif class="rounded-md px-3 py-2 text-sm font-medium hover:bg-muted {{ $isActive ? 'bg-muted text-primary' : '' }}">{{ $link['label'] }}</a>
                            @endforeach
                            <div class="mt-2 flex gap-2 border-t border-border pt-2">
                                <a href="{{ route('login') }}" class="btn btn-sm flex-1 border border-input bg-background shadow-sm hover:bg-accent hover:text-accent-foreground">Sign In</a>
                                <a href="{{ route('membership.apply') }}" class="btn btn-sm btn-brand flex-1">Join Now</a>
                            </div>
                        </div>
                    </div>
                </details>
            </div>
        </header>

        <main id="main" class="flex-1">
            {{ $slot }}
        </main>

        {{-- Footer background is the approved brand red itself (not the `primary`
             token, which is the dark-gray heading colour elsewhere on the site).
             `primary-foreground` (white) is unaffected, so the existing
             `text-primary-foreground/*` classes below still read correctly. --}}
        <footer class="mt-20 bg-[#CC001F] text-primary-foreground">
            <div class="container mx-auto grid gap-10 px-4 py-14 md:grid-cols-2 lg:grid-cols-4 lg:px-8">
                <div class="lg:col-span-2">
                    <a href="{{ url('/') }}" class="mb-4 inline-flex items-center gap-2">
                        @if ($hasLogo)
                            {{-- The logo's own text is dark, so it needs a light backing to
                                 read on this dark footer; the asset itself is untouched. --}}
                            <span class="inline-flex items-center rounded-md bg-white px-3 py-2">
                                <img src="{{ asset('images/aci-logo.png') }}" alt="Aviation Club International" width="473" height="155" class="h-8 w-auto">
                            </span>
                        @else
                            <span class="grid h-9 w-9 place-items-center rounded-lg bg-secondary text-secondary-foreground">
                                <svg class="h-5 w-5 -rotate-45" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M17.8 19.2 16 11l3.5-3.5C21 6 21.5 4 21 3c-1-.5-3 0-4.5 1.5L13 8 4.8 6.2c-.5-.1-.9.1-1.1.5l-.3.5c-.2.5-.1 1 .3 1.3L9 12l-2 3H4l-1 1 3 2 2 3 1-1v-3l3-2 3.5 5.3c.3.4.8.5 1.3.3l.5-.2c.4-.3.6-.7.5-1.2z"/></svg>
                            </span>
                            <span class="font-display text-lg font-bold">Aviation Club International</span>
                        @endif
                    </a>
                    <p class="text-sm leading-relaxed text-primary-foreground/70">
                        For people who work, study or take part in aviation.
                    </p>
                </div>

                <div>
                    <h4 class="mb-4 font-display text-base font-semibold">Explore</h4>
                    {{-- `hover:text-white` (not `hover:text-secondary`, now a mid-gray) keeps
                         the hover state legible against the brand-red background. --}}
                    <ul class="space-y-2 text-sm text-primary-foreground/75">
                        @foreach ($navLinks as $link)
                            <li><a href="{{ route($link['route']) }}" class="hover:text-white">{{ $link['label'] }}</a></li>
                        @endforeach
                        <li><a href="{{ route('membership.apply') }}" class="hover:text-white">Become a Member</a></li>
                        <li><a href="{{ route('login') }}" class="hover:text-white">Sign In</a></li>
                    </ul>
                </div>

                <div>
                    <h4 class="mb-4 font-display text-base font-semibold">Legal</h4>
                    <ul class="space-y-2 text-sm text-primary-foreground/75">
                        @foreach ($legalLinks as $link)
                            <li><a href="{{ route($link['route']) }}" class="hover:text-white">{{ $link['label'] }}</a></li>
                        @endforeach
                    </ul>
                </div>
            </div>

            <div class="border-t border-white/10">
                <div class="container mx-auto flex flex-col items-center justify-between gap-2 px-4 py-5 text-xs text-primary-foreground/60 md:flex-row lg:px-8">
                    <p>&copy; {{ date('Y') }} Aviation Club International. All rights reserved.</p>
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
