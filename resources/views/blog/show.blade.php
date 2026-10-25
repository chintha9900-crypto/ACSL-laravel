@use('Illuminate\Support\Facades\Storage')
@use('App\Models\BlogComment')
@use('App\Support\HtmlSanitizer')
{{--
    Blog article — the article (title, image, author, date, formatted
    content, category, tags), related posts and member comments, with the
    same sidebar as the listing. Content was allow-list sanitised on save and
    is sanitised again here before raw output. Comment text is plain text,
    always escaped.
--}}
<x-layouts.public :title="$post->title">
    <div class="border-b border-border bg-muted/50">
        <div class="container mx-auto px-4 py-10 lg:px-8">
            <a href="{{ route('blog.index') }}" class="text-sm font-semibold text-[#CC001F] hover:underline">&larr; Back to blog</a>

            @if ($post->category)
                <a href="{{ route('blog.index', ['category' => $post->category->slug]) }}" class="mt-4 inline-flex w-fit items-center rounded-md border border-[#666666]/40 px-2 py-0.5 text-[11px] font-semibold uppercase tracking-wide text-[#4D4D4D] hover:border-[#CC001F]/60">{{ $post->category->name }}</a>
            @endif

            <h1 class="mt-3 max-w-4xl font-display text-3xl font-bold text-primary md:text-4xl">{{ $post->title }}</h1>
            <p class="mt-2 text-sm text-muted-foreground">
                <time datetime="{{ $post->published_at->toDateString() }}">{{ $post->published_at->format('j M Y') }}</time>
                @if ($post->author) &middot; By {{ $post->author->name }} @endif
            </p>
        </div>
    </div>

    <div class="container mx-auto grid gap-8 px-4 py-10 lg:grid-cols-3 lg:px-8">
        <div class="min-w-0 lg:col-span-2">
            <article>
                @if ($post->featured_image_path)
                    <img src="{{ Storage::disk('public')->url($post->featured_image_path) }}" alt="{{ $post->featured_image_alt ?? '' }}" class="mb-8 aspect-[16/8] w-full rounded-xl object-cover shadow-elegant">
                @endif

                <div class="blog-content">
                    {!! HtmlSanitizer::clean((string) $post->content) !!}
                </div>

                @if ($post->tags->isNotEmpty())
                    <div class="mt-8 flex flex-wrap items-center gap-2 border-t border-border pt-6">
                        <span class="text-sm font-semibold text-primary">Tags:</span>
                        @foreach ($post->tags as $tag)
                            <a href="{{ route('blog.index', ['tag' => $tag->slug]) }}" class="rounded-md border border-border px-2.5 py-1 text-xs font-medium text-primary hover:border-[#CC001F]/60">{{ $tag->name }}</a>
                        @endforeach
                    </div>
                @endif
            </article>

            @if ($related->isNotEmpty())
                <section class="mt-12" aria-labelledby="related-heading">
                    <h2 id="related-heading" class="font-display text-xl font-bold text-primary">Related posts</h2>
                    <div class="mt-4 grid gap-4 sm:grid-cols-3">
                        @foreach ($related as $item)
                            <a href="{{ route('blog.show', $item->slug) }}" class="ui-card block overflow-hidden transition-colors hover:border-[#CC001F]/60">
                                <span class="block aspect-[16/10] w-full overflow-hidden bg-[#4D4D4D]">
                                    @if ($item->featured_image_path)
                                        <img src="{{ Storage::disk('public')->url($item->featured_image_path) }}" alt="" class="h-full w-full object-cover">
                                    @endif
                                </span>
                                <span class="block p-3">
                                    <span class="block text-sm font-semibold text-primary">{{ $item->title }}</span>
                                    <span class="block text-xs text-muted-foreground">{{ $item->published_at->format('j M Y') }}</span>
                                </span>
                            </a>
                        @endforeach
                    </div>
                </section>
            @endif

            <section id="comments" class="mt-12" aria-labelledby="comments-heading">
                <h2 id="comments-heading" class="font-display text-xl font-bold text-primary">Comments ({{ $comments->total() }})</h2>

                @if (session('status'))
                    <p class="mt-3 rounded-lg border border-secondary/40 bg-secondary/10 px-4 py-3 text-sm text-primary" role="status">{{ session('status') }}</p>
                @endif

                @if ($ownComments->isNotEmpty())
                    <ul class="mt-4 space-y-3">
                        @foreach ($ownComments as $comment)
                            <li class="rounded-lg border border-dashed border-border bg-muted/40 p-4 text-sm">
                                <p class="text-xs font-semibold text-muted-foreground">Your comment &middot; {{ BlogComment::STATUS_LABELS[$comment->status] }}</p>
                                <p class="mt-2 whitespace-pre-line break-words text-foreground">{{ $comment->body }}</p>
                            </li>
                        @endforeach
                    </ul>
                @endif

                @forelse ($comments as $comment)
                    <article class="mt-4 rounded-lg border border-border p-4 text-sm">
                        <p class="text-xs text-muted-foreground"><span class="font-semibold text-primary">{{ $comment->user?->name }}</span> &middot; <time datetime="{{ $comment->moderated_at?->toDateString() ?? $comment->created_at->toDateString() }}">{{ ($comment->moderated_at ?? $comment->created_at)->format('j M Y') }}</time></p>
                        <p class="mt-2 whitespace-pre-line break-words text-foreground">{{ $comment->body }}</p>
                    </article>
                @empty
                    <p class="mt-4 text-sm text-muted-foreground">No comments yet.</p>
                @endforelse

                <div class="mt-4">{{ $comments->fragment('comments')->links() }}</div>

                @if ($canComment)
                    <form method="POST" action="{{ route('blog.comments.store', $post->slug) }}" class="mt-6 space-y-3">
                        @csrf
                        <label for="comment-body" class="block text-sm font-medium">Add a comment</label>
                        <textarea id="comment-body" name="body" rows="4" required maxlength="2000" class="field-control" @error('body') aria-invalid="true" @enderror>{{ old('body') }}</textarea>
                        @error('body')
                            <p class="text-xs text-[#CC001F]">{{ $message }}</p>
                        @enderror
                        <p class="text-xs text-muted-foreground">Comments appear once approved by an admin.</p>
                        <button type="submit" class="btn btn-lg btn-brand">Post comment</button>
                    </form>
                @elseif (auth()->guest())
                    <p class="mt-6 rounded-lg border border-border bg-muted/40 px-4 py-3 text-sm">
                        <a href="{{ route('login') }}" class="font-semibold text-[#CC001F] hover:underline">Sign in</a> as a member to join the discussion.
                    </p>
                @endif
            </section>
        </div>

        @include('blog._sidebar')
    </div>
</x-layouts.public>
