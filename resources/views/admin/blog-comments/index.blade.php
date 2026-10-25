<x-layouts.admin title="Pending comments">
    <h1 class="font-display text-2xl font-bold text-primary md:text-3xl">Pending blog comments</h1>
    <p class="mt-1 text-sm text-muted-foreground">Approved comments appear on the article; rejected ones stay hidden.</p>

    @include('admin.blog-comments._list', ['comments' => $comments])

    <div class="mt-4">{{ $comments->links() }}</div>
</x-layouts.admin>
