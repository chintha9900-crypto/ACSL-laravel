<x-layouts.public title="Application received">
    <x-public.hero>
        <x-slot:badge>
            <svg class="mr-1 h-3 w-3" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><line x1="19" x2="19" y1="8" y2="14"/><line x1="22" x2="16" y1="11" y2="11"/></svg>
            Become a Member
        </x-slot:badge>
        <x-slot:lead>
            Choose your membership type below and complete the application. Our team will review and get back to you.
        </x-slot:lead>
        Join the <span class="text-gradient">community.</span>
    </x-public.hero>

    <section class="container mx-auto max-w-5xl px-4 py-16 md:py-20 lg:px-8">
        <div class="ui-card border-secondary/40 p-10 text-center shadow-elegant">
            <div class="mx-auto mb-4 grid h-14 w-14 place-items-center rounded-full bg-secondary/10 text-secondary">
                <svg class="h-7 w-7" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21.801 10A10 10 0 1 1 17 3.335"/><path d="m9 11 3 3L22 4"/></svg>
            </div>

            <h2 class="font-display text-2xl font-bold text-primary">Application is successfully submitted.</h2>
            <p class="mx-auto mt-2 max-w-xl text-muted-foreground">
                Your application and aviation eligibility proof will be reviewed by ACI.
            </p>

            <dl class="mx-auto mt-6 max-w-md rounded-lg border border-border bg-muted px-4 py-3">
                <dt class="text-xs font-medium uppercase tracking-wide text-muted-foreground">Your reference</dt>
                <dd class="mt-1 break-all font-mono text-sm">{{ $application->public_id }}</dd>
            </dl>
            <p class="mt-2 text-xs text-muted-foreground">Please keep this reference.</p>

            <ul class="mx-auto mt-6 max-w-xl list-disc space-y-1.5 pl-5 text-left text-sm text-muted-foreground">
                <li>Submitting an application does not create a member account.</li>
                <li>Approval does not immediately activate membership. Approval and activation are separate steps.</li>
            </ul>

            <div class="mt-6 flex flex-wrap justify-center gap-3">
                <a href="{{ url('/') }}" class="btn btn-lg btn-gradient">
                    Go home
                    <svg class="h-4 w-4" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12h14"/><path d="m12 5 7 7-7 7"/></svg>
                </a>
            </div>
        </div>
    </section>
</x-layouts.public>
