{{-- Row/page actions for one job: Publish for draft and expired jobs, Unpublish for published ones. --}}
<div class="flex flex-wrap gap-2">
    @can('update', $job)
        <a href="{{ route('admin.jobs.edit', $job) }}" class="btn btn-sm border border-input bg-background shadow-sm hover:bg-accent hover:text-accent-foreground">Edit</a>
    @endcan

    @can('publish', $job)
        @if ($job->status === \App\Models\JobListing::STATUS_PUBLISHED)
            <form method="POST" action="{{ route('admin.jobs.unpublish', $job) }}">
                @csrf
                <button type="submit" class="btn btn-sm border border-input bg-background shadow-sm hover:bg-accent hover:text-accent-foreground">Unpublish</button>
            </form>
        @else
            <form method="POST" action="{{ route('admin.jobs.publish', $job) }}">
                @csrf
                <button type="submit" class="btn btn-sm btn-brand">{{ $job->status === \App\Models\JobListing::STATUS_EXPIRED ? 'Republish' : 'Publish' }}</button>
            </form>
        @endif
    @endcan

    @can('delete', $job)
        <form method="POST" action="{{ route('admin.jobs.destroy', $job) }}" onsubmit="return confirm('Delete this job? This cannot be undone.');">
            @csrf
            @method('DELETE')
            <button type="submit" class="btn btn-sm border border-[#CC001F]/40 text-[#CC001F] hover:bg-[#CC001F]/5">Delete</button>
        </form>
    @endcan
</div>
