<x-layouts.admin title="Jobs Management">
    <div class="flex flex-wrap items-center justify-between gap-4">
        <h1 class="font-display text-2xl font-bold text-primary md:text-3xl">Jobs Management</h1>
        @can('create', \App\Models\JobListing::class)
            <a href="{{ route('admin.jobs.create') }}" class="btn btn-lg btn-brand">Add New Job</a>
        @endcan
    </div>

    <div class="mt-4 flex flex-wrap items-center justify-between gap-3">
        <nav class="flex flex-wrap gap-2" aria-label="Filter by status">
            @foreach (['' => 'All', ...\App\Models\JobListing::STATUSES] as $value => $label)
                @php($active = ($status ?? '') === (string) $value)
                <a href="{{ route('admin.jobs.index', array_filter(['status' => $value, 'q' => $search])) }}"
                    @if ($active) aria-current="true" @endif
                    @class([
                        'rounded-md border px-3 py-1.5 text-sm font-medium transition-colors',
                        'border-primary bg-primary text-primary-foreground' => $active,
                        'border-border bg-background text-primary hover:border-primary/60' => ! $active,
                    ])>{{ $label }}</a>
            @endforeach
        </nav>

        <form method="GET" action="{{ route('admin.jobs.index') }}" role="search" class="flex gap-2">
            @if ($status)
                <input type="hidden" name="status" value="{{ $status }}">
            @endif
            <label for="job-search" class="sr-only">Search jobs</label>
            <input id="job-search" type="search" name="q" value="{{ $search }}" placeholder="Search title or company" class="field-control h-9 w-56">
            <button type="submit" class="btn btn-sm h-9 border border-input bg-background px-4 shadow-sm hover:bg-accent hover:text-accent-foreground">Search</button>
        </form>
    </div>

    @include('admin.jobs._errors')

    <div class="ui-card mt-6 overflow-x-auto">
        <table class="w-full min-w-[64rem] text-left text-sm">
            <thead class="border-b border-border text-xs uppercase tracking-wide text-muted-foreground">
                <tr>
                    <th scope="col" class="px-4 py-3 font-medium">Job</th>
                    <th scope="col" class="px-4 py-3 font-medium">Category</th>
                    <th scope="col" class="px-4 py-3 font-medium">Location</th>
                    <th scope="col" class="px-4 py-3 font-medium">Type</th>
                    <th scope="col" class="px-4 py-3 font-medium">Deadline</th>
                    <th scope="col" class="px-4 py-3 font-medium">Status</th>
                    <th scope="col" class="px-4 py-3 font-medium">Posted / created</th>
                    <th scope="col" class="px-4 py-3 font-medium">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-border">
                @forelse ($jobs as $job)
                    <tr>
                        <td class="px-4 py-3">
                            <a href="{{ route('admin.jobs.show', $job) }}" class="font-medium text-primary underline underline-offset-2 hover:text-secondary">{{ $job->title }}</a>
                            <span class="block text-xs text-muted-foreground">{{ $job->company_name }}</span>
                        </td>
                        <td class="px-4 py-3">{{ $job->categoryLabel() }}</td>
                        <td class="px-4 py-3">{{ $job->location }}, {{ $job->country }}</td>
                        <td class="px-4 py-3 whitespace-nowrap">{{ $job->employmentTypeLabel() }}</td>
                        <td class="px-4 py-3 whitespace-nowrap">{{ $job->application_deadline->format('j M Y') }}</td>
                        <td class="px-4 py-3">@include('admin.jobs._status-badge', ['job' => $job])</td>
                        <td class="px-4 py-3 whitespace-nowrap">{{ ($job->date_posted ?? $job->created_at)->format('j M Y') }}</td>
                        <td class="px-4 py-3">@include('admin.jobs._actions', ['job' => $job])</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="8" class="px-4 py-8 text-center text-muted-foreground">No jobs found.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-6">{{ $jobs->links() }}</div>
</x-layouts.admin>
