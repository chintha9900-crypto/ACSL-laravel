@use('App\Models\MembershipApplication')

<x-layouts.admin title="Membership applications">
    <h1 class="font-display text-2xl font-bold text-primary md:text-3xl">Membership applications</h1>

    <nav class="mt-4 flex flex-wrap gap-2" aria-label="Filter by status">
        @foreach ([null => 'All'] + MembershipApplication::STATUS_LABELS as $value => $label)
            @php($active = ($status ?? null) === ($value ?: null))
            <a href="{{ route('admin.membership-applications.index', $value ? ['status' => $value] : []) }}"
                @if ($active) aria-current="true" @endif
                @class([
                    'rounded-md border px-3 py-1.5 text-sm font-medium transition-colors',
                    'border-primary bg-primary text-primary-foreground' => $active,
                    'border-border bg-background text-primary hover:border-primary/60' => ! $active,
                ])>{{ $label }}</a>
        @endforeach
    </nav>

    <div class="ui-card mt-6 overflow-x-auto">
        <table class="w-full min-w-[40rem] text-left text-sm">
            <thead class="border-b border-border text-xs uppercase tracking-wide text-muted-foreground">
                <tr>
                    <th scope="col" class="px-4 py-3 font-medium">Reference</th>
                    <th scope="col" class="px-4 py-3 font-medium">Applicant</th>
                    <th scope="col" class="px-4 py-3 font-medium">Category</th>
                    <th scope="col" class="px-4 py-3 font-medium">Submitted</th>
                    <th scope="col" class="px-4 py-3 font-medium">Status</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-border">
                @forelse ($applications as $application)
                    <tr>
                        <td class="px-4 py-3 font-mono text-xs">
                            <a href="{{ route('admin.membership-applications.show', $application) }}" class="text-primary underline underline-offset-2 hover:text-secondary">{{ $application->public_id }}</a>
                        </td>
                        <td class="px-4 py-3">{{ $application->full_name }}</td>
                        <td class="px-4 py-3">{{ $application->category->name }}</td>
                        <td class="px-4 py-3 whitespace-nowrap">{{ $application->submitted_at->format('j M Y') }}</td>
                        <td class="px-4 py-3"><x-admin.status-badge :status="$application->status" /></td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5" class="px-4 py-8 text-center text-muted-foreground">No applications found.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-6">{{ $applications->links() }}</div>
</x-layouts.admin>
