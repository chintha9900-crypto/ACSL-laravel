<x-layouts.admin title="Edit job">
    <h1 class="font-display text-2xl font-bold text-primary md:text-3xl">Edit job</h1>
    <p class="mt-2 flex flex-wrap items-center gap-2 text-sm text-muted-foreground">
        Current status: @include('admin.jobs._status-badge', ['job' => $job])
        <span>Saving keeps this status. Use Publish or Unpublish to change it.</span>
    </p>

    <form method="POST" action="{{ route('admin.jobs.update', $job) }}" enctype="multipart/form-data" class="ui-card mt-6 space-y-5 p-6 md:p-8">
        @csrf
        @method('PATCH')

        @include('admin.jobs._form', ['job' => $job])

        <div class="flex gap-3">
            <button type="submit" class="btn btn-lg btn-brand">Save changes</button>
            <a href="{{ route('admin.jobs.index') }}" class="btn btn-lg border border-input bg-background shadow-sm hover:bg-accent hover:text-accent-foreground">Cancel</a>
        </div>
    </form>
</x-layouts.admin>
