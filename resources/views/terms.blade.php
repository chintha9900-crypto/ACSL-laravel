{{--
    Terms & Conditions — docs/frontend/03_PUBLIC_PAGES.md §A16. Same basis as
    privacy.blade.php: the reference content is Lorem ipsum ("do not port"),
    ACI has not supplied approved legal text (doc `08` D-09), so no
    contractual/legal provisions are invented — headings only (a generic,
    safe outline), each clearly marked pending real content.
--}}
<x-layouts.public title="Terms & Conditions">
    <x-public.hero>
        <x-slot:badge>Legal</x-slot:badge>
        <x-slot:lead>
            The terms that apply to using this website and Aviation Club International membership.
        </x-slot:lead>
        Terms &amp; <span class="text-[#CC001F]">Conditions.</span>
    </x-public.hero>

    <section class="border-t border-border">
        <div class="container mx-auto max-w-3xl px-4 py-16 md:py-20 lg:px-8">
            <p class="rounded-lg border border-[#666666]/30 bg-muted px-4 py-3 text-sm text-[#4D4D4D]" role="status">
                These Terms &amp; Conditions are being finalised by Aviation Club International. The sections below outline what they will cover; full content will be published here once approved.
            </p>

            <div class="mt-8 space-y-6">
                @foreach ([
                    'Acceptance of Terms',
                    'Membership Eligibility',
                    'Use of This Website',
                    'Intellectual Property',
                    'Limitation of Liability',
                    'Changes to These Terms',
                    'Governing Law',
                    'Contact Us',
                ] as $heading)
                    <div class="border-b border-border pb-6 last:border-b-0 last:pb-0">
                        <h2 class="font-display text-lg font-bold text-primary">{{ $heading }}</h2>
                        <p class="mt-2 text-sm italic leading-relaxed text-muted-foreground">Content for this section will be published once approved by Aviation Club International.</p>
                    </div>
                @endforeach
            </div>

            <p class="mt-8 text-sm text-muted-foreground">
                See also our <a href="{{ route('rules') }}" class="font-semibold text-[#CC001F] hover:underline">Club Rules</a>, or <a href="{{ route('contact') }}" class="font-semibold text-[#CC001F] hover:underline">get in touch</a> with questions.
            </p>
        </div>
    </section>
</x-layouts.public>
