{{--
    Club Rules — docs/frontend/03_PUBLIC_PAGES.md §A5, rewritten to drop the
    reference's legacy assumptions (age/parental-consent wording, a "School
    Club tier", non-refundable-fee language) and to avoid stating anything as
    a binding legal obligation this application doesn't actually enforce —
    there is no suspension/termination feature built, so "Membership
    Standing" is phrased as a general expectation, not a legal process.
    Consent is not captured anywhere (no column exists for it), so there is
    no "I agree" checkbox or CTA implying one.
--}}
<x-layouts.public title="Club Rules">
    <x-public.hero>
        <x-slot:badge>Community Guidelines</x-slot:badge>
        <x-slot:lead>
            A short set of expectations that keep Aviation Club International a professional, welcoming community.
        </x-slot:lead>
        How we <span class="text-[#CC001F]">fly together.</span>
    </x-public.hero>

    <section class="border-t border-border">
        <div class="container mx-auto max-w-3xl px-4 py-16 md:py-20 lg:px-8">
            <div class="space-y-6">
                <div class="ui-card p-6 md:p-8">
                    <h2 class="font-display text-xl font-bold text-primary">Eligibility</h2>
                    <ul class="mt-3 list-disc space-y-1.5 pl-5 text-sm leading-relaxed text-muted-foreground">
                        <li>Membership is open to people who genuinely work, study or take part in the aviation field.</li>
                        <li>Applicants apply under the category that best matches their situation: Student, Professional or Veteran.</li>
                        <li>Every application is reviewed, and proof of your connection to aviation is required.</li>
                    </ul>
                </div>

                <div class="ui-card p-6 md:p-8">
                    <h2 class="font-display text-xl font-bold text-primary">Code of Conduct</h2>
                    <ul class="mt-3 list-disc space-y-1.5 pl-5 text-sm leading-relaxed text-muted-foreground">
                        <li>Treat other members, applicants and ACI staff with respect and professionalism.</li>
                        <li>No harassment, discrimination or abusive behaviour of any kind, in any club communication.</li>
                        <li>Represent yourself honestly — the information you provide should be accurate.</li>
                    </ul>
                </div>

                <div class="ui-card p-6 md:p-8">
                    <h2 class="font-display text-xl font-bold text-primary">Member Responsibilities</h2>
                    <ul class="mt-3 list-disc space-y-1.5 pl-5 text-sm leading-relaxed text-muted-foreground">
                        <li>Keep your account details accurate and your password secure.</li>
                        <li>Renew before your membership term ends if you wish to remain a member — membership does not renew automatically.</li>
                        <li>Use your digital membership card and its QR verification honestly, for yourself only.</li>
                    </ul>
                </div>

                <div class="ui-card p-6 md:p-8">
                    <h2 class="font-display text-xl font-bold text-primary">Membership Standing</h2>
                    <ul class="mt-3 list-disc space-y-1.5 pl-5 text-sm leading-relaxed text-muted-foreground">
                        <li>Your membership is current whenever you have an active introductory or renewed term, and lapses if it isn't renewed in time.</li>
                        <li>Your membership number stays the same for as long as you remain part of the community, including across renewals.</li>
                        <li>ACI may review a member's standing if these guidelines are not followed.</li>
                    </ul>
                </div>
            </div>

            <div class="mt-10 flex justify-center">
                <a href="{{ route('membership.apply') }}" class="btn btn-lg btn-brand">Become a Member</a>
            </div>
        </div>
    </section>
</x-layouts.public>
