{{--
    Privacy Policy — docs/frontend/03_PUBLIC_PAGES.md §A15. The reference
    content is Lorem ipsum and explicitly marked "do not port"; ACI has not
    yet supplied approved legal text (doc `08` D-09, a launch-gate item).
    Rather than invent privacy claims/data-processing practices, this page
    keeps only the section headings (a safe, generic outline — not specific
    to any legal jurisdiction or claim) with each section clearly marked as
    pending real content, per "use a placeholder rather than inventing it".
--}}
<x-layouts.public title="Privacy Policy">
    <x-public.hero>
        <x-slot:badge>Legal</x-slot:badge>
        <x-slot:lead>
            How Aviation Club International handles your information.
        </x-slot:lead>
        Privacy <span class="text-[#CC001F]">Policy.</span>
    </x-public.hero>

    <section class="border-t border-border">
        <div class="container mx-auto max-w-3xl px-4 py-16 md:py-20 lg:px-8">
            <p class="rounded-lg border border-[#666666]/30 bg-muted px-4 py-3 text-sm text-[#4D4D4D]" role="status">
                This Privacy Policy is being finalised by Aviation Club International. The sections below outline what it will cover; full content will be published here once approved.
            </p>

            <div class="mt-8 space-y-6">
                @foreach ([
                    'Information We Collect',
                    'How We Use Your Information',
                    'Data Storage & Security',
                    'Your Rights',
                    'Changes to This Policy',
                    'Contact Us',
                ] as $heading)
                    <div class="border-b border-border pb-6 last:border-b-0 last:pb-0">
                        <h2 class="font-display text-lg font-bold text-primary">{{ $heading }}</h2>
                        <p class="mt-2 text-sm italic leading-relaxed text-muted-foreground">Content for this section will be published once approved by Aviation Club International.</p>
                    </div>
                @endforeach
            </div>

            <p class="mt-8 text-sm text-muted-foreground">
                Questions in the meantime? <a href="{{ route('contact') }}" class="font-semibold text-[#CC001F] hover:underline">Get in touch</a>.
            </p>
        </div>
    </section>
</x-layouts.public>
