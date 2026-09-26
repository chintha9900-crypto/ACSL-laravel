@use('Illuminate\Support\Facades\Storage')
{{--
    Event article — docs/frontend/03_PUBLIC_PAGES.md §A12. `$event->content`
    is rendered as plain preformatted text ({{ }}, auto-escaped, with
    `whitespace-pre-line` to keep line breaks) — the reference app rendered
    event content as plain text and HTML-vs-plain is an open decision
    (docs/architecture/10 §4, OD #19), so this stage follows the reference
    literally instead of guessing at HTML support.
--}}
<x-layouts.public :title="$event->title">
    <div class="border-b border-border bg-muted/50">
        <div class="container mx-auto max-w-3xl px-4 py-10 lg:px-8">
            <a href="{{ route('events.index') }}" class="text-sm font-semibold text-[#CC001F] hover:underline">&larr; Back to Events</a>

            <h1 class="mt-3 font-display text-3xl font-bold text-primary md:text-4xl">{{ $event->title }}</h1>
            <p class="mt-2 text-sm text-muted-foreground">
                {{ $event->starts_at->format('j M Y, g:ia') }}
                @if ($event->location)
                    &middot; {{ $event->location }}
                @endif
            </p>
        </div>
    </div>

    <div class="container mx-auto max-w-3xl px-4 py-10 lg:px-8">
        @if ($event->image_path)
            <img src="{{ Storage::disk('public')->url($event->image_path) }}" alt="" class="mb-8 aspect-[16/8] w-full rounded-xl object-cover shadow-elegant">
        @endif

        @if ($event->excerpt)
            <p class="mb-6 text-lg font-medium leading-relaxed text-foreground/85">{{ $event->excerpt }}</p>
        @endif

        <div class="whitespace-pre-line text-base leading-relaxed text-foreground/85">{{ $event->content }}</div>
    </div>
</x-layouts.public>
