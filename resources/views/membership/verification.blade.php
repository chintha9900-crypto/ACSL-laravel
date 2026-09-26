<x-layouts.public title="Membership verification">
    <div class="container mx-auto max-w-md px-4 py-16 text-center">
        @if ($isValid)
            <div class="ui-card p-8">
                <div class="mx-auto mb-4 grid h-14 w-14 place-items-center rounded-full bg-secondary/15 text-secondary">
                    <svg class="h-7 w-7" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 6 9 17l-5-5"/></svg>
                </div>
                <h1 class="font-display text-xl font-bold text-primary">You are a member of Aviation Club International</h1>
                <p class="mt-4 text-lg font-semibold">{{ $name }}</p>
                <p class="mt-1 font-mono text-sm text-muted-foreground">{{ $membershipNumber }}</p>

                <a href="{{ $vendorUrl }}" class="btn btn-lg btn-gradient mt-6 inline-flex">Continue</a>
            </div>
        @else
            <div class="ui-card p-8">
                <div class="mx-auto mb-4 grid h-14 w-14 place-items-center rounded-full bg-destructive/15 text-destructive">
                    <svg class="h-7 w-7" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>
                </div>
                <h1 class="font-display text-xl font-bold text-primary">This is not a currently valid Aviation Club International membership</h1>
                <p class="mt-2 text-sm text-muted-foreground">The membership this code refers to is not currently active.</p>
            </div>
        @endif
    </div>
</x-layouts.public>
