@use('App\Support\HtmlSanitizer')
@use('Illuminate\Support\Facades\Storage')
{{--
    News article — docs/frontend/03_PUBLIC_PAGES.md §A11. `$item->content` is
    sanitised rich text; older plain-text items keep their line breaks
    (`HtmlSanitizer::forDisplay()`).
--}}
<x-layouts.public :title="$item->title">
    <div class="bg-[#686868] text-white" data-page-intro>
        <div class="site-container content-reading py-[15px]">
            <a href="{{ route('news.index') }}" class="text-sm font-semibold text-white hover:underline">&larr; Back to News</a>

            <h1 class="mt-3 font-sans text-3xl font-bold tracking-normal text-white md:text-4xl">{{ $item->title }}</h1>
            <p class="mt-2 text-sm text-white">{{ $item->published_at->format('j M Y') }}</p>
            <x-public.breadcrumbs :current="$item->title" class="mt-3" />
        </div>
    </div>

    <div class="site-container content-reading py-10">
        @if ($item->image_path)
            <img data-fallback src="{{ Storage::disk('public')->url($item->image_path) }}" alt="{{ $item->title }}" class="mb-8 aspect-[16/8] w-full rounded-xl object-cover shadow-elegant">
        @endif

        @if ($item->excerpt)
            <p class="mb-6 text-lg font-medium leading-relaxed text-foreground/85">{{ $item->excerpt }}</p>
        @endif

        <div class="text-base leading-relaxed text-foreground/85 [&_a]:font-semibold [&_a]:text-[#CC001F] [&_a]:underline [&_blockquote]:border-l-4 [&_blockquote]:border-[#666666]/40 [&_blockquote]:pl-4 [&_blockquote]:italic [&_blockquote]:text-muted-foreground [&_h2]:mt-8 [&_h2]:mb-3 [&_h2]:font-display [&_h2]:text-2xl [&_h2]:font-bold [&_h2]:text-primary [&_h3]:mt-6 [&_h3]:mb-2 [&_h3]:font-display [&_h3]:text-xl [&_h3]:font-bold [&_h3]:text-primary [&_h4]:mt-4 [&_h4]:mb-2 [&_h4]:font-display [&_h4]:text-lg [&_h4]:font-bold [&_h4]:text-primary [&_li]:mb-1 [&_ol]:mb-4 [&_ol]:list-decimal [&_ol]:pl-5 [&_p]:mb-4 [&_ul]:mb-4 [&_ul]:list-disc [&_ul]:pl-5">
            {!! HtmlSanitizer::forDisplay((string) $item->content) !!}
        </div>
    </div>
</x-layouts.public>
