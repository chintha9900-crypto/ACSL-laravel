@use('App\Models\MembershipTerm')
@use('Illuminate\Support\Str')

<x-layouts.member :title="'Membership'">
    <h1 class="font-display text-2xl font-bold text-primary md:text-3xl">Your membership</h1>

    @if ($membership === null)
        <section class="ui-card mt-6 p-6" aria-labelledby="membership-heading">
            <h2 id="membership-heading" class="font-display text-lg font-bold text-primary">You are not a member yet</h2>
            <p class="mt-2 text-sm text-muted-foreground">Once your application is approved and activated, your membership will appear here.</p>
            <a href="{{ route('membership.apply') }}" class="btn btn-lg btn-gradient mt-4 inline-flex">Apply now</a>
        </section>
    @else
        <div class="mt-6 grid gap-6 lg:grid-cols-2">
            <section class="ui-card p-6" aria-labelledby="summary-heading">
                <h2 id="summary-heading" class="font-display text-lg font-bold text-primary">Membership summary</h2>

                <dl class="mt-4 grid gap-x-6 gap-y-4 text-sm sm:grid-cols-2">
                    <div>
                        <dt class="text-xs font-medium uppercase tracking-wide text-muted-foreground">Membership number</dt>
                        <dd class="mt-1 font-mono font-semibold">{{ $membership->membership_number }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs font-medium uppercase tracking-wide text-muted-foreground">Category</dt>
                        <dd class="mt-1">{{ $membership->category->name }}</dd>
                    </div>
                    @if ($membership->category->description)
                        <div class="sm:col-span-2">
                            <dt class="text-xs font-medium uppercase tracking-wide text-muted-foreground">About this category</dt>
                            <dd class="mt-1 whitespace-pre-line text-muted-foreground">{{ $membership->category->description }}</dd>
                        </div>
                    @endif
                    <div>
                        <dt class="text-xs font-medium uppercase tracking-wide text-muted-foreground">Activated</dt>
                        <dd class="mt-1">{{ $membership->activated_on->format('j F Y') }}</dd>
                    </div>
                </dl>
            </section>

            <section class="ui-card p-6" aria-labelledby="term-heading">
                <h2 id="term-heading" class="font-display text-lg font-bold text-primary">
                    {{ $isCurrentlyValid ? 'Current term' : 'Most recent term' }}
                </h2>

                @if ($displayTerm)
                    @unless ($isCurrentlyValid)
                        <p class="mt-2 text-sm text-muted-foreground">You do not currently have an active membership term.</p>
                    @endunless

                    <dl class="mt-4 grid gap-x-6 gap-y-4 text-sm sm:grid-cols-2">
                        <div>
                            <dt class="text-xs font-medium uppercase tracking-wide text-muted-foreground">Term</dt>
                            <dd class="mt-1">#{{ $displayTerm->term_no }} &middot; {{ ucfirst($displayTerm->term_kind) }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs font-medium uppercase tracking-wide text-muted-foreground">Term status</dt>
                            <dd class="mt-1">{{ MembershipTerm::STATUS_LABELS[$displayTerm->status] ?? $displayTerm->status }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs font-medium uppercase tracking-wide text-muted-foreground">Term start date</dt>
                            <dd class="mt-1">{{ $displayTerm->starts_on?->format('j F Y') ?? '—' }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs font-medium uppercase tracking-wide text-muted-foreground">Term expiry date</dt>
                            <dd class="mt-1">{{ $displayTerm->expires_on?->format('j F Y') ?? '—' }}</dd>
                        </div>
                    </dl>

                    @if ($displayTerm->term_kind === MembershipTerm::KIND_INTRODUCTORY)
                        <p class="mt-4 rounded-lg bg-secondary/10 px-3 py-2 text-sm text-primary">
                            Your first {{ $displayTerm->duration_months }} {{ Str::plural('month', $displayTerm->duration_months) }} of membership
                            {{ $isCurrentlyValid ? 'are' : 'were' }} free of charge, valid until {{ $displayTerm->expires_on?->format('j F Y') }}.
                        </p>
                    @endif

                    @if ($displayTerm->payment_status === MembershipTerm::PAYMENT_NOT_REQUIRED)
                        <p class="mt-2 text-sm text-muted-foreground">No payment required.</p>
                    @endif
                @else
                    <p class="mt-3 text-sm text-muted-foreground">No membership term on record yet.</p>
                @endif
            </section>
        </div>

        <section class="ui-card mt-6 p-6" aria-labelledby="history-heading">
            <h2 id="history-heading" class="font-display text-lg font-bold text-primary">Term history</h2>

            <div class="mt-4 overflow-x-auto">
                <table class="w-full min-w-[520px] text-left text-sm">
                    <thead>
                        <tr class="border-b border-border text-xs font-medium uppercase tracking-wide text-muted-foreground">
                            <th class="py-2 pr-4">Term</th>
                            <th class="py-2 pr-4">Status</th>
                            <th class="py-2 pr-4">Start</th>
                            <th class="py-2 pr-4">Expiry</th>
                            <th class="py-2">Payment</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($terms as $term)
                            <tr class="border-b border-border/60">
                                <td class="py-2 pr-4">#{{ $term->term_no }} &middot; {{ ucfirst($term->term_kind) }}</td>
                                <td class="py-2 pr-4">{{ MembershipTerm::STATUS_LABELS[$term->status] ?? $term->status }}</td>
                                <td class="py-2 pr-4">{{ $term->starts_on?->format('j M Y') ?? '—' }}</td>
                                <td class="py-2 pr-4">{{ $term->expires_on?->format('j M Y') ?? '—' }}</td>
                                <td class="py-2">
                                    @if ($term->payment_status === MembershipTerm::PAYMENT_NOT_REQUIRED)
                                        No payment required
                                    @elseif ($term->fee_amount !== null)
                                        {{ $term->fee_currency }} {{ number_format((float) $term->fee_amount, 2) }}
                                    @else
                                        —
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>
    @endif
</x-layouts.member>
