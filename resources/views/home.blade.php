{{--
    Home — docs/frontend/03_PUBLIC_PAGES.md §A1. Sections: (1) Hero (2) About
    (3) Benefits (4) Latest from the club. Copy notes:
    - Hero H1 is the documented fallback ("no active hero banner" — there is no
      hero-banner CMS yet, so this is always what renders).
    - About/Benefits copy is adapted from the live production site
      (test.aviationclub.lk), with the unverified claims doc `03` flags
      (mock exams, member directory certifications) and the legacy "ACSL" name
      (rule C-02: not used anywhere in the new UI) deliberately dropped —
      see the implementation report for this decision.
    - The primary CTA points at `membership.apply` (the only membership entry
      point that exists today), not the documented `/membership/benefits`,
      which has not been built yet.
    - No blog exists yet, so "Latest from the blog" is the same empty state
      already live in production, with no link (no `/blog` route exists).
--}}
<x-layouts.public title="Home">
    {{-- 1. Hero --}}
    <section class="bg-background">
        <div class="container mx-auto grid gap-10 px-4 py-16 md:py-20 lg:grid-cols-2 lg:items-center lg:gap-16 lg:px-8 lg:py-28">
            <div class="text-center lg:text-left">
                <span class="inline-flex items-center rounded-md border border-[#666666]/40 px-2.5 py-0.5 text-xs font-semibold uppercase tracking-wider text-[#4D4D4D]">
                    International Aviation Community
                </span>

                <h1 class="mt-5 font-display text-4xl font-extrabold leading-[1.08] tracking-tight text-primary md:text-5xl lg:text-6xl">
                    One Community. One Passion. <span class="text-[#CC001F]">Aviation.</span>
                </h1>

                <p class="mx-auto mt-5 max-w-xl text-lg text-muted-foreground lg:mx-0">
                    For people who work, study or take part in aviation.
                </p>

                <div class="mt-8 flex justify-center lg:justify-start">
                    <a href="{{ route('membership.apply') }}" class="btn btn-lg btn-brand">Become a Member</a>
                </div>
            </div>

            {{-- Decorative brand panel — the reference's photographic illustration
                 (pilots/engineers/cabin crew/ATC) is not an asset ACI has supplied;
                 nothing is invented here beyond the plane glyph already used as a
                 brand accent in the footer (docs/frontend/06 §6). --}}
            <div class="mx-auto flex aspect-[4/3] w-full max-w-md items-center justify-center rounded-3xl bg-gradient-to-br from-[#4D4D4D] to-[#666666] shadow-lg lg:aspect-auto lg:h-full lg:max-w-none lg:min-h-[22rem]" role="img" aria-label="Aviation Club International">
                <svg class="h-24 w-24 -rotate-45 text-white/90 md:h-32 md:w-32" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M17.8 19.2 16 11l3.5-3.5C21 6 21.5 4 21 3c-1-.5-3 0-4.5 1.5L13 8 4.8 6.2c-.5-.1-.9.1-1.1.5l-.3.5c-.2.5-.1 1 .3 1.3L9 12l-2 3H4l-1 1 3 2 2 3 1-1v-3l3-2 3.5 5.3c.3.4.8.5 1.3.3l.5-.2c.4-.3.6-.7.5-1.2z"/></svg>
            </div>
        </div>
    </section>

    {{-- 2. About --}}
    <section class="border-t border-border">
        <div class="container mx-auto max-w-3xl px-4 py-16 md:py-20 lg:px-8">
            <x-ui.section-heading badge="About ACI">
                Connecting the Global Aviation Community
            </x-ui.section-heading>

            <div class="mt-6 space-y-4 text-base leading-relaxed text-muted-foreground">
                <p>Aviation Club International brings together people from every sector of the aviation industry under one professional community — built on connection, knowledge, experience and continuous learning.</p>
                <p>Members can build professional networks, access aviation resources, and connect with experienced people from across the industry.</p>
                <p>From aspiring aviation professionals to experienced specialists, Aviation Club International supports every stage of the aviation journey.</p>
            </div>
        </div>
    </section>

    {{-- 3. Benefits --}}
    <section class="border-y border-border bg-muted/50">
        <div class="container mx-auto px-4 py-16 md:py-20 lg:px-8">
            <x-ui.section-heading badge="Membership Benefits">
                Everything you need to grow.
            </x-ui.section-heading>

            <div class="mx-auto mt-10 grid max-w-5xl gap-5 sm:grid-cols-2 lg:grid-cols-3">
                @php
                    $benefits = [
                        ['title' => 'Networking', 'text' => 'Connect with pilots, engineers and other professionals across the aviation industry.', 'path' => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>'],
                        ['title' => 'Career Development', 'text' => 'Career guidance and mentorship from experienced aviation professionals.', 'path' => '<path d="m22 7-8.5 8.5-5-5L2 17"/><path d="M16 7h6v6"/>'],
                        ['title' => 'Industry News', 'text' => 'Stay up to date with aviation news, regulations and industry updates.', 'path' => '<path d="m3 11 18-5v12L3 14v-3z"/><path d="M11.6 16.8a3 3 0 1 1-5.8-1.6"/>'],
                        ['title' => 'Community Events', 'text' => 'Workshops, meetups and events for the aviation community.', 'path' => '<rect width="18" height="18" x="3" y="4" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/>'],
                        ['title' => 'Professional Support', 'text' => 'A member directory and support to help you grow professionally.', 'path' => '<path d="M20 13c0 5-3.5 7.5-7.35 8.95a1 1 0 0 1-1.3 0C7.5 20.5 4 18 4 13V6a1 1 0 0 1 1-1c2 0 4.5-1.2 6.24-2.72a1.17 1.17 0 0 1 1.52 0C14.5 3.8 17 5 19 5a1 1 0 0 1 1 1z"/><path d="m9 12 2 2 4-4"/>'],
                        ['title' => 'Job Board', 'text' => 'Curated aviation job listings from across the industry.', 'path' => '<rect width="20" height="14" x="2" y="7" rx="2"/><path d="M16 21V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v16"/>'],
                    ];
                @endphp

                @foreach ($benefits as $benefit)
                    <div class="ui-card p-6 md:p-7">
                        <x-ui.icon-tile>
                            <svg class="h-6 w-6" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">{!! $benefit['path'] !!}</svg>
                        </x-ui.icon-tile>

                        <h3 class="mt-4 font-display text-lg font-semibold text-primary">{{ $benefit['title'] }}</h3>
                        <p class="mt-1.5 text-sm leading-relaxed text-muted-foreground">{{ $benefit['text'] }}</p>
                    </div>
                @endforeach
            </div>
        </div>
    </section>

    {{-- 4. Latest from the club (blog module does not exist yet — empty state only) --}}
    <section>
        <div class="container mx-auto max-w-3xl px-4 py-16 text-center md:py-20 lg:px-8">
            <x-ui.section-heading badge="From the Club">
                Insights &amp; stories
            </x-ui.section-heading>

            <div class="mt-8 rounded-xl border border-dashed border-border bg-background px-6 py-10 text-sm text-muted-foreground">
                New articles are landing soon.
            </div>
        </div>
    </section>
</x-layouts.public>
