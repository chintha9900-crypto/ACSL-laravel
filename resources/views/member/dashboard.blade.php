@use('App\Models\MembershipTerm')

<x-layouts.member :title="'Dashboard'">
    <h1 class="font-display text-2xl font-bold text-primary md:text-3xl">Welcome, {{ $user->name }}</h1>

    {{-- School Club identity: the representative also sees their position. --}}
    @if ($schoolClub)
        <dl class="mt-2 flex flex-wrap gap-x-6 gap-y-1 text-sm text-muted-foreground" data-school-club-identity>
            @if ($membership->isSchoolClubMain())
                <div><dt class="inline font-medium text-foreground">Position:</dt> <dd class="inline">{{ $schoolClub['position'] ?? '—' }}</dd></div>
            @endif
            <div><dt class="inline font-medium text-foreground">School Club:</dt> <dd class="inline">{{ $schoolClub['club'] ?? '—' }}</dd></div>
            <div><dt class="inline font-medium text-foreground">School:</dt> <dd class="inline">{{ $schoolClub['school'] ?? '—' }}</dd></div>
        </dl>
    @endif

    {{-- Main School Club only: live figures for this club's students. --}}
    @if ($studentStatistics)
        <section class="mt-6 grid grid-cols-2 gap-4 lg:grid-cols-4" aria-label="Student membership statistics">
            @foreach ([
                'total' => ['Total Membership Count', 'Student memberships created'],
                'active' => ['Active Memberships', 'Set up and currently valid'],
                'notActivated' => ['Not Activated', 'Approved, account setup pending'],
                'requests' => ['Total Requests', 'Students submitted for review'],
            ] as $key => [$label, $hint])
                <div class="ui-card p-4" data-statistic="{{ $key }}">
                    <p class="text-xs font-medium uppercase tracking-wide text-muted-foreground">{{ $label }}</p>
                    <p class="mt-2 font-display text-3xl font-bold text-primary">{{ number_format($studentStatistics[$key]) }}</p>
                    <p class="mt-1 text-xs text-muted-foreground">{{ $hint }}</p>
                </div>
            @endforeach
        </section>
    @endif

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
                @if ($schoolClub)
                    <div>
                        <dt class="text-xs font-medium uppercase tracking-wide text-muted-foreground">School</dt>
                        <dd class="mt-1">{{ $schoolClub['school'] ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs font-medium uppercase tracking-wide text-muted-foreground">School Club</dt>
                        <dd class="mt-1">{{ $schoolClub['club'] ?? '—' }}</dd>
                    </div>
                    @if ($membership->isSchoolClubMain())
                        <div>
                            <dt class="text-xs font-medium uppercase tracking-wide text-muted-foreground">Representative</dt>
                            <dd class="mt-1">{{ $schoolClub['representative'] ?? '—' }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs font-medium uppercase tracking-wide text-muted-foreground">Position</dt>
                            <dd class="mt-1">{{ $schoolClub['position'] ?? '—' }}</dd>
                        </div>
                    @endif
                @endif
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

            <a href="{{ route('member.membership.card') }}" class="btn btn-lg btn-gradient mt-6 inline-flex">View Digital Membership Card</a>

            {{-- Main School Club membership only — never for its students or other members. --}}
            @if ($membership->isSchoolClubMain())
                <a href="{{ route('member.school-club.students.index') }}" class="btn btn-lg border border-input bg-background shadow-sm hover:bg-accent hover:text-accent-foreground mt-6 ml-2 inline-flex">Manage Student Members</a>
            @endif
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
