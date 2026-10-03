@use('Illuminate\Support\Facades\Storage')
<x-layouts.admin :title="$event->title">
    <div class="flex flex-wrap items-center justify-between gap-4">
        <h1 class="font-display text-2xl font-bold text-primary md:text-3xl">{{ $event->title }}</h1>
        <div class="flex gap-2">
            <a href="{{ route('admin.events.edit', $event) }}" class="btn btn-sm border border-input bg-background shadow-sm hover:bg-accent hover:text-accent-foreground">Edit</a>
            <a href="{{ route('admin.events.index') }}" class="btn btn-sm border border-input bg-background shadow-sm hover:bg-accent hover:text-accent-foreground">Back to events</a>
        </div>
    </div>

    <div class="ui-card mt-6 grid gap-6 p-6 md:grid-cols-2 md:p-8">
        <div>
            @if ($event->image_path)
                <img src="{{ Storage::disk('public')->url($event->image_path) }}" alt="" class="w-full rounded-lg border border-border object-cover">
            @else
                <div class="grid aspect-video w-full place-items-center rounded-lg border border-border bg-muted text-sm text-muted-foreground">
                    No image uploaded
                </div>
            @endif
        </div>

        <dl class="space-y-4 text-sm">
            <div>
                <dt class="font-medium text-muted-foreground">Slug</dt>
                <dd class="font-mono">{{ $event->slug }}</dd>
            </div>
            <div>
                <dt class="font-medium text-muted-foreground">Starts at</dt>
                <dd>{{ $event->starts_at->format('j F Y, g:ia') }}</dd>
            </div>
            <div>
                <dt class="font-medium text-muted-foreground">Location</dt>
                <dd>{{ $event->location ?? '—' }}</dd>
            </div>
            <div>
                <dt class="font-medium text-muted-foreground">Status</dt>
                <dd>
                    <span class="inline-flex items-center rounded-md border px-2.5 py-0.5 text-xs font-semibold {{ $event->status === 'published' ? 'border-transparent bg-primary text-primary-foreground' : 'border-border bg-muted text-foreground' }}">
                        {{ ucfirst($event->status) }}
                    </span>
                </dd>
            </div>
            <div>
                <dt class="font-medium text-muted-foreground">Published at</dt>
                <dd>{{ $event->published_at?->format('j F Y, g:ia') ?? '—' }}</dd>
            </div>
            @if ($event->excerpt)
                <div>
                    <dt class="font-medium text-muted-foreground">Excerpt</dt>
                    <dd class="whitespace-pre-line">{{ $event->excerpt }}</dd>
                </div>
            @endif
            <div>
                <dt class="font-medium text-muted-foreground">Content</dt>
                <dd class="whitespace-pre-line">{{ $event->content }}</dd>
            </div>
        </dl>
    </div>
</x-layouts.admin>
