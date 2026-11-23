<x-layouts.admin title="Edit Education article">
    <div class="flex flex-wrap items-center justify-between gap-4">
        <h1 class="font-display text-2xl font-bold text-primary md:text-3xl">Edit Education article</h1>
        <a href="{{ route('admin.education.show', $article) }}" class="btn btn-sm border border-input bg-background shadow-sm hover:bg-accent hover:text-accent-foreground">Preview</a>
    </div>
    <p class="mt-1 text-sm text-muted-foreground">Status: {{ $article->statusLabel() }}. Saving keeps this status; use Publish or Unpublish to change it.</p>

    <form method="POST" action="{{ route('admin.education.update', $article) }}" enctype="multipart/form-data" class="ui-card mt-6 space-y-5 p-6 md:p-8">
        @csrf
        @method('PATCH')

        @include('admin.education._form', ['article' => $article])

        <div class="flex gap-3">
            <button type="submit" class="btn btn-lg btn-brand">Save changes</button>
            <a href="{{ route('admin.education.index') }}" class="btn btn-lg border border-input bg-background shadow-sm hover:bg-accent hover:text-accent-foreground">Cancel</a>
        </div>
    </form>
</x-layouts.admin>
