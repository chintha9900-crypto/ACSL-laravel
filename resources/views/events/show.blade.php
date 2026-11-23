@use('App\Support\HtmlSanitizer')
@use('Illuminate\Support\Facades\Storage')
{{--
    Event article — docs/frontend/03_PUBLIC_PAGES.md §A12. `$event->content`
    is sanitised rich text; older plain-text events keep their line breaks
    (`HtmlSanitizer::forDisplay()`).
--}}
<x-layouts.public :title="$event->title">
    <div class="bg-[#686868] text-white" data-page-intro>
        <div class="site-container content-reading py-[15px]">
            <a href="{{ route('events.index') }}" class="text-sm font-semibold text-white hover:underline">&larr; Back to Events</a>

            <h1 class="mt-3 font-sans text-3xl font-bold tracking-normal text-white md:text-4xl">{{ $event->title }}</h1>
            <p class="mt-2 text-sm text-white">
                {{ $event->starts_at->format('j M Y, g:ia') }}
                @if ($event->location)
                    &middot; {{ $event->location }}
                @endif
            </p>
            <x-public.breadcrumbs :current="$event->title" class="mt-3" />
        </div>
    </div>

    <div class="site-container content-reading py-10">
        @if ($event->image_path)
            <img data-fallback src="{{ Storage::disk('public')->url($event->image_path) }}" alt="{{ $event->title }}" class="mb-8 aspect-[16/8] w-full rounded-xl object-cover shadow-elegant">
        @endif

        @if ($event->excerpt)
            <p class="mb-6 text-lg font-medium leading-relaxed text-foreground/85">{{ $event->excerpt }}</p>
        @endif

        <div class="blog-content">{!! HtmlSanitizer::forDisplay((string) $event->content) !!}</div>
    </div>
</x-layouts.public>
