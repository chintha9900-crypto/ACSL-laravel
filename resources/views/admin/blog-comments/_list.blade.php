{{-- Pending comments with Approve / Reject — used on the admin dashboard and the full list. Text is escaped. --}}
@if ($comments->isEmpty())
    <p class="mt-4 rounded-lg border border-border bg-muted/40 px-4 py-3 text-sm text-muted-foreground">No comments are waiting for approval.</p>
@else
    <ul class="mt-4 space-y-3">
        @foreach ($comments as $comment)
            <li class="ui-card p-4 text-sm">
                <p class="text-xs text-muted-foreground">
                    <span class="font-semibold text-primary">{{ $comment->user?->name }}</span> on
                    <a href="{{ route('blog.show', $comment->post->slug) }}" class="underline underline-offset-2 hover:text-[#CC001F]" target="_blank" rel="noopener">{{ $comment->post->title }}</a>
                    &middot; {{ $comment->created_at->format('j M Y, H:i') }} UTC
                </p>
                <p class="mt-2 whitespace-pre-line break-words">{{ $comment->body }}</p>
                <div class="mt-3 flex gap-2">
                    <form method="POST" action="{{ route('admin.blog-comments.approve', $comment) }}">
                        @csrf
                        <button type="submit" class="btn btn-sm btn-brand">Approve</button>
                    </form>
                    <form method="POST" action="{{ route('admin.blog-comments.reject', $comment) }}">
                        @csrf
                        <button type="submit" class="btn btn-sm border border-input bg-background shadow-sm hover:bg-accent hover:text-accent-foreground">Reject</button>
                    </form>
                </div>
            </li>
        @endforeach
    </ul>
@endif
