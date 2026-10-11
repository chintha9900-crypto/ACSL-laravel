{{--
    Application received. Reachable only through the signed, expiring link
    returned by MembershipApplicationController@store, i.e. only after the
    application has been saved. Shows the confirmation, what happens next and
    the application reference (`public_id`) — nothing else from the
    application (no name, email, phone, documents or status link). The
    Veteran category is displayed with its public name, as on the benefits page.
--}}
@use('App\Models\MembershipCategory')
@php
    $categoryName = $application->category->code === MembershipCategory::CODE_VETERAN
        ? 'Veteran Aviation Professional'
        : $application->category->name;
@endphp
<x-layouts.public title="Application received">
    <section class="container mx-auto max-w-2xl px-4 py-24 text-center md:py-32 lg:px-8">
        <h1 class="font-display text-xl font-semibold text-primary md:text-2xl" role="status">
            Your application for {{ $categoryName }} membership has been successfully submitted. The Aviation Club team will get back to you soon.
        </h1>

        <p class="mt-4 text-sm text-muted-foreground md:text-base">
            Your application is now awaiting review by ACI. We will notify you by email of the outcome, or if we need any further details from you.
        </p>

        <div class="mx-auto mt-8 max-w-md rounded-lg border border-border bg-muted/40 px-4 py-3">
            <p class="text-xs font-medium uppercase tracking-wide text-muted-foreground">Application reference</p>
            <p class="mt-1 break-all font-mono text-sm text-foreground">{{ $application->public_id }}</p>
            <p class="mt-2 text-xs text-muted-foreground">Please quote this reference if you contact ACI about your application.</p>
        </div>
    </section>
</x-layouts.public>
