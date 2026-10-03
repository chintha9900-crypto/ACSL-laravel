@use('Illuminate\Support\Facades\Storage')
<x-layouts.admin :title="$news->title">
    <div class="flex flex-wrap items-center justify-between gap-4">
        <h1 class="font-display text-2xl font-bold text-primary md:text-3xl">{{ $news->title }}</h1>
        <div class="flex gap-2">
            <a href="{{ route('admin.news.edit', $news) }}" class="btn btn-sm border border-input bg-background shadow-sm hover:bg-accent hover:text-accent-foreground">Edit</a>
            <a href="{{ route('admin.news.index') }}" class="btn btn-sm border border-input bg-background shadow-sm hover:bg-accent hover:text-accent-foreground">Back to news</a>
        </div>
    </div>

    <div class="ui-card mt-6 grid gap-6 p-6 md:grid-cols-2 md:p-8">
        <div>
            @if ($news->image_path)
                <img src="{{ Storage::disk('public')->url($news->image_path) }}" alt="" class="w-full rounded-lg border border-border object-cover">
            @else
                <div class="grid aspect-video w-full place-items-center rounded-lg border border-border bg-muted text-sm text-muted-foreground">
                    No image uploaded
                </div>
            @endif
        </div>

        <dl class="space-y-4 text-sm">
            <div>
                <dt class="font-medium text-muted-foreground">Slug</dt>
                <dd class="font-mono">{{ $news->slug }}</dd>
            </div>
            <div>
                <dt class="font-medium text-muted-foreground">Status</dt>
                <dd>
                    <span class="inline-flex items-center rounded-md border px-2.5 py-0.5 text-xs font-semibold {{ $news->status === 'published' ? 'border-transparent bg-primary text-primary-foreground' : 'border-border bg-muted text-foreground' }}">
                        {{ ucfirst($news->status) }}
                    </span>
                </dd>
            </div>
            <div>
                <dt class="font-medium text-muted-foreground">Published at</dt>
                <dd>{{ $news->published_at?->format('j F Y, g:ia') ?? '—' }}</dd>
            </div>
            @if ($news->excerpt)
                <div>
                    <dt class="font-medium text-muted-foreground">Excerpt</dt>
                    <dd class="whitespace-pre-line">{{ $news->excerpt }}</dd>
                </div>
            @endif
            <div>
                <dt class="font-medium text-muted-foreground">Content</dt>
                <dd class="text-sm leading-relaxed text-foreground/85 [&_a]:font-semibold [&_a]:text-[#CC001F] [&_a]:underline [&_h2]:mt-4 [&_h2]:mb-2 [&_h2]:font-display [&_h2]:text-lg [&_h2]:font-bold [&_h2]:text-primary [&_li]:mb-1 [&_ol]:mb-3 [&_ol]:list-decimal [&_ol]:pl-5 [&_p]:mb-3 [&_ul]:mb-3 [&_ul]:list-disc [&_ul]:pl-5">{!! $news->content !!}</dd>
            </div>
        </dl>
    </div>
</x-layouts.admin>
