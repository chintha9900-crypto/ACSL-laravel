@use('App\Models\MembershipTerm')

<x-layouts.member :title="'Dashboard'">
    <h1 class="font-display text-2xl font-bold text-primary md:text-3xl">Welcome, {{ $user->name }}</h1>

    <div class="mt-6 grid gap-6 lg:grid-cols-2">
        <section class="ui-card p-6" aria-labelledby="membership-heading">
            <h2 id="membership-heading" class="font-display text-lg font-bold text-primary">Your membership</h2>

            <dl class="mt-4 grid gap-x-6 gap-y-4 text-sm sm:grid-cols-2">
                <div>
                    <dt class="text-xs font-medium uppercase tracking-wide text-muted-foreground">Member name</dt>
                    <dd class="mt-1">{{ $user->name }}</dd>
                </div>
                <div>
                    <dt class="text-xs font-medium uppercase tracking-wide text-muted-foreground">Membership number</dt>
                    <dd class="mt-1 font-mono font-semibold">{{ $membership->membership_number }}</dd>
                </div>
                <div>
                    <dt class="text-xs font-medium uppercase tracking-wide text-muted-foreground">Category</dt>
                    <dd class="mt-1">{{ $membership->category->name }}</dd>
                </div>
                <div>
                    <dt class="text-xs font-medium uppercase tracking-wide text-muted-foreground">Membership status</dt>
                    <dd class="mt-1">
                        @if ($currentTerm && $currentTerm->status === MembershipTerm::STATUS_ACTIVE)
                            <span class="inline-flex items-center rounded-full bg-secondary/15 px-2.5 py-0.5 text-xs font-semibold text-primary">Active</span>
                        @else
                            <span class="inline-flex items-center rounded-full bg-muted px-2.5 py-0.5 text-xs font-semibold text-muted-foreground">Inactive</span>
                        @endif
                    </dd>
                </div>
                <div>
                    <dt class="text-xs font-medium uppercase tracking-wide text-muted-foreground">Account status</dt>
                    <dd class="mt-1 capitalize">{{ str_replace('_', ' ', $user->status) }}</dd>
                </div>
            </dl>
        </section>

        <section class="ui-card p-6" aria-labelledby="term-heading">
            <h2 id="term-heading" class="font-display text-lg font-bold text-primary">Current term</h2>

            @if ($currentTerm)
                <dl class="mt-4 grid gap-x-6 gap-y-4 text-sm sm:grid-cols-2">
                    <div>
                        <dt class="text-xs font-medium uppercase tracking-wide text-muted-foreground">Term status</dt>
                        <dd class="mt-1">{{ MembershipTerm::STATUS_LABELS[$currentTerm->status] ?? $currentTerm->status }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs font-medium uppercase tracking-wide text-muted-foreground">Introductory (free) period</dt>
                        <dd class="mt-1">{{ $currentTerm->term_kind === MembershipTerm::KIND_INTRODUCTORY ? 'Yes — no payment required' : 'No' }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs font-medium uppercase tracking-wide text-muted-foreground">Term start date</dt>
                        <dd class="mt-1">{{ $currentTerm->starts_on?->format('j F Y') ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs font-medium uppercase tracking-wide text-muted-foreground">Term expiry date</dt>
                        <dd class="mt-1">{{ $currentTerm->expires_on?->format('j F Y') ?? '—' }}</dd>
                    </div>
                </dl>
            @else
                <p class="mt-3 text-sm text-muted-foreground">No membership term on record yet.</p>
            @endif
        </section>
    </div>
</x-layouts.member>
