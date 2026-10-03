<x-layouts.admin title="Edit news item">
    <h1 class="font-display text-2xl font-bold text-primary md:text-3xl">Edit news item</h1>

    <form method="POST" action="{{ route('admin.news.update', $news) }}" enctype="multipart/form-data" class="ui-card mt-6 space-y-5 p-6 md:p-8">
        @csrf
        @method('PATCH')

        @include('admin.news._form', ['news' => $news])

        <div class="flex gap-3">
            <button type="submit" class="btn btn-lg btn-brand">Save changes</button>
            <a href="{{ route('admin.news.index') }}" class="btn btn-lg border border-input bg-background shadow-sm hover:bg-accent hover:text-accent-foreground">Cancel</a>
        </div>
    </form>
</x-layouts.admin>
