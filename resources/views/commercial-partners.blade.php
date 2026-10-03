{{--
    Commercial Partners — a clean, content-free template for future partner
    information (logos, offers, terms). No real partner names, logos,
    claims or data exist anywhere in this project, so none are invented
    here; this page intentionally has no dynamic data source yet.
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
        <div class="container mx-auto max-w-3xl px-4 py-16 md:py-20 lg:px-8">
            <div class="ui-card p-6 text-center md:p-10">
                <h2 class="font-display text-xl font-bold text-primary">Partner information coming soon</h2>
                <p class="mt-3 text-sm leading-relaxed text-muted-foreground">
                    This page will list the organisations Aviation Club International partners with, along with
                    the benefits available to members through them. Check back soon for details.
                </p>
            </div>

            <div class="mt-10 flex justify-center">
                <a href="{{ route('membership.apply') }}" class="btn btn-lg btn-brand">Become a Member</a>
            </div>
        </div>
    </section>
</x-layouts.public>
