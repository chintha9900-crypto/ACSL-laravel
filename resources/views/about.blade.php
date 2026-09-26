{{--
    About — docs/frontend/03_PUBLIC_PAGES.md §A2, adapted. The reference's
    stats strip (2,500+ members etc.) is placeholder/unverified per that doc
    and is deliberately omitted rather than invented, as is a "team" section
    (no public team data exists yet). Copy is generic and safe — no claims
    beyond what the club and this application actually do.
--}}
<x-layouts.public title="About">
    <x-public.hero>
        <x-slot:badge>About Us</x-slot:badge>
        <x-slot:lead>
            A community built by aviation professionals, for aviation professionals.
        </x-slot:lead>
        Who <span class="text-[#CC001F]">we are.</span>
    </x-public.hero>

    {{-- Who we are --}}
    <section class="border-t border-border">
        <div class="container mx-auto max-w-3xl px-4 py-16 md:py-20 lg:px-8">
            <x-ui.section-heading badge="Who We Are">
                Connecting the Global Aviation Community
            </x-ui.section-heading>

            <div class="mt-6 space-y-4 text-base leading-relaxed text-muted-foreground">
                <p>Aviation Club International brings together people from every sector of the aviation industry under one professional community — students, working professionals and experienced veterans alike.</p>
                <p>We exist to give members a place to connect with others who share the same field, stay informed, and take part in a community built around aviation.</p>
                <p>Membership is open by application. Every application is reviewed before a member is admitted, so the community stays made up of people genuinely connected to aviation.</p>
            </div>
        </div>
    </section>

    {{-- Mission & Vision --}}
    <section class="border-y border-border bg-muted/50">
        <div class="container mx-auto px-4 py-16 md:py-20 lg:px-8">
            <div class="mx-auto grid max-w-4xl gap-5 md:grid-cols-2">
                <div class="ui-card p-6 md:p-8">
                    <x-ui.icon-tile size="sm">
                        <svg class="h-4 w-4" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"/><circle cx="12" cy="12" r="6"/><circle cx="12" cy="12" r="2"/></svg>
                    </x-ui.icon-tile>
                    <h3 class="mt-4 font-display text-lg font-semibold text-primary">Our Mission</h3>
                    <p class="mt-2 text-sm leading-relaxed text-muted-foreground">To give people across the aviation industry a professional community where they can connect, stay informed and support one another's growth.</p>
                </div>

                <div class="ui-card p-6 md:p-8">
                    <x-ui.icon-tile size="sm">
                        <svg class="h-4 w-4" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M2 12s3-7 10-7 10 7 10 7-3 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/></svg>
                    </x-ui.icon-tile>
                    <h3 class="mt-4 font-display text-lg font-semibold text-primary">Our Vision</h3>
                    <p class="mt-2 text-sm leading-relaxed text-muted-foreground">A single, trusted community that people across the aviation world are proud to belong to, whatever stage of their journey they're at.</p>
                </div>
            </div>
        </div>
    </section>

    {{-- Values --}}
    <section>
        <div class="container mx-auto px-4 py-16 md:py-20 lg:px-8">
            <x-ui.section-heading badge="What We Stand For">
                Our Values
            </x-ui.section-heading>

            <div class="mx-auto mt-10 grid max-w-4xl gap-5 sm:grid-cols-3">
                @php
                    $values = [
                        ['title' => 'Community', 'text' => 'A club built around people, not just membership numbers.', 'path' => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>'],
                        ['title' => 'Professionalism', 'text' => 'A respectful, professional space for everyone in the community.', 'path' => '<rect width="20" height="14" x="2" y="7" rx="2"/><path d="M16 21V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v16"/>'],
                        ['title' => 'Growth', 'text' => 'Supporting members at every stage of their aviation journey.', 'path' => '<path d="m22 7-8.5 8.5-5-5L2 17"/><path d="M16 7h6v6"/>'],
                    ];
                @endphp

                @foreach ($values as $value)
                    <div class="ui-card p-6 text-center">
                        <x-ui.icon-tile class="mx-auto">
                            <svg class="h-6 w-6" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">{!! $value['path'] !!}</svg>
                        </x-ui.icon-tile>
                        <h3 class="mt-4 font-display text-lg font-semibold text-primary">{{ $value['title'] }}</h3>
                        <p class="mt-1.5 text-sm leading-relaxed text-muted-foreground">{{ $value['text'] }}</p>
                    </div>
                @endforeach
            </div>
        </div>
    </section>

    {{-- CTA banner --}}
    <section class="border-t border-border bg-muted/50">
        <div class="container mx-auto px-4 py-16 md:py-20 lg:px-8">
            <div class="mx-auto max-w-2xl rounded-xl border border-border bg-background p-8 text-center shadow-card md:p-12">
                <h2 class="font-display text-2xl font-bold text-primary md:text-3xl">Ready to join the community?</h2>
                <p class="mt-3 text-muted-foreground">See membership categories and what's included, or start your application today.</p>
                <div class="mt-6 flex flex-col justify-center gap-3 sm:flex-row">
                    <a href="{{ route('membership.benefits') }}" class="btn btn-lg border border-input bg-background shadow-sm hover:bg-accent hover:text-accent-foreground">View Membership Benefits</a>
                    <a href="{{ route('membership.apply') }}" class="btn btn-lg btn-brand">Become a Member</a>
                </div>
            </div>
        </div>
    </section>
</x-layouts.public>
