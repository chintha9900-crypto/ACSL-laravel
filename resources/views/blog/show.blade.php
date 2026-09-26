@use('Illuminate\Support\Facades\Storage')
{{--
    Blog article — docs/frontend/03_PUBLIC_PAGES.md §A9, scoped to what this
    task asks for (no comments — not requested this round). `$post->content`
    is rendered raw ({!! !!}) because it was already allow-list-sanitised on
    save (App\Support\HtmlSanitizer, docs/architecture/10 §5) — sanitising at
    write time, not just at render time, is the documented approach.
--}}
<x-layouts.public :title="$post->title">
    <div class="border-b border-border bg-muted/50">
        <div class="container mx-auto max-w-3xl px-4 py-10 lg:px-8">
            <a href="{{ route('blog.index') }}" class="text-sm font-semibold text-[#CC001F] hover:underline">&larr; Back to blog</a>

            @if ($post->category)
                <span class="mt-4 inline-flex w-fit items-center rounded-md border border-[#666666]/40 px-2 py-0.5 text-[11px] font-semibold uppercase tracking-wide text-[#4D4D4D]">{{ $post->category->name }}</span>
            @endif

            <h1 class="mt-3 font-display text-3xl font-bold text-primary md:text-4xl">{{ $post->title }}</h1>
            <p class="mt-2 text-sm text-muted-foreground">{{ $post->published_at->format('j M Y') }}</p>
        </div>
    </div>

    <div class="container mx-auto max-w-3xl px-4 py-10 lg:px-8">
        @if ($post->featured_image_path)
            <img src="{{ Storage::disk('public')->url($post->featured_image_path) }}" alt="{{ $post->featured_image_alt ?? '' }}" class="mb-8 aspect-[16/8] w-full rounded-xl object-cover shadow-elegant">
        @endif

        <div class="text-base leading-relaxed text-foreground/85 [&_a]:font-semibold [&_a]:text-[#CC001F] [&_a]:underline [&_blockquote]:border-l-4 [&_blockquote]:border-[#666666]/40 [&_blockquote]:pl-4 [&_blockquote]:italic [&_blockquote]:text-muted-foreground [&_h2]:mt-8 [&_h2]:mb-3 [&_h2]:font-display [&_h2]:text-2xl [&_h2]:font-bold [&_h2]:text-primary [&_h3]:mt-6 [&_h3]:mb-2 [&_h3]:font-display [&_h3]:text-xl [&_h3]:font-bold [&_h3]:text-primary [&_h4]:mt-4 [&_h4]:mb-2 [&_h4]:font-display [&_h4]:text-lg [&_h4]:font-bold [&_h4]:text-primary [&_li]:mb-1 [&_ol]:mb-4 [&_ol]:list-decimal [&_ol]:pl-5 [&_p]:mb-4 [&_ul]:mb-4 [&_ul]:list-disc [&_ul]:pl-5">
            {!! $post->content !!}
        </div>
    </div>
</x-layouts.public>
