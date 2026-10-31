<x-layouts.admin title="School Club student batches">
    <h1 class="font-display text-2xl font-bold text-primary md:text-3xl">School Club student batches</h1>

    <nav class="mt-4 flex flex-wrap gap-2" aria-label="Filter by status">
        @foreach ([null => 'All'] + $statusLabels as $value => $label)
            @php($active = ($status ?? null) === ($value ?: null))
            <a href="{{ route('admin.school-club-batches.index', $value ? ['status' => $value] : []) }}"
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
                    <th scope="col" class="px-4 py-3 font-medium">School</th>
                    <th scope="col" class="px-4 py-3 font-medium">School Club</th>
                    <th scope="col" class="px-4 py-3 font-medium">School Club number</th>
                    <th scope="col" class="px-4 py-3 font-medium">Students</th>
                    <th scope="col" class="px-4 py-3 font-medium">Submitted</th>
                    <th scope="col" class="px-4 py-3 font-medium">Status</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-border">
                @forelse ($batches as $batch)
                    <tr>
                        <td class="px-4 py-3 font-mono text-xs">
                            <a href="{{ route('admin.school-club-batches.show', $batch) }}" class="text-primary underline underline-offset-2 hover:text-secondary">{{ $batch->public_id }}</a>
                        </td>
                        <td class="px-4 py-3">{{ $batch->membership->application?->company_name ?? '—' }}</td>
                        <td class="px-4 py-3">{{ $batch->membership->application?->aviation_role ?? '—' }}</td>
                        <td class="px-4 py-3 font-mono text-xs">{{ $batch->membership->membership_number }}</td>
                        <td class="px-4 py-3">{{ $batch->students_count }}</td>
                        <td class="px-4 py-3 whitespace-nowrap">{{ $batch->submitted_at->format('j M Y') }}</td>
                        <td class="px-4 py-3"><x-admin.status-badge :status="$batch->status" /></td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" class="px-4 py-6 text-center text-muted-foreground">No student batches.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">{{ $batches->links() }}</div>
</x-layouts.admin>
