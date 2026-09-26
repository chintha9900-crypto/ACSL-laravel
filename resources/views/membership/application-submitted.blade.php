{{--
    Application received — reduced to only the requested confirmation
    message, per explicit instruction (previously showed a hero band, icon,
    reference number, bullet list and follow-up buttons). The redirect that
    lands here (a signed link from MembershipApplicationController@store)
    and the {application} route-model binding are unchanged; this view just
    no longer displays anything from it.
--}}
<x-layouts.public title="Application received">
    <section class="container mx-auto max-w-2xl px-4 py-24 text-center md:py-32 lg:px-8">
        <p class="font-display text-xl font-semibold text-primary md:text-2xl">
            Your application was successfully submitted. We will get back to you soon.
        </p>
    </section>
</x-layouts.public>
