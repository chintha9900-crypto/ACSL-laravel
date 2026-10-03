@use('Illuminate\Support\Facades\Storage')
{{--
    CSR project page — reached via the listing's "View More" once there are
    more than 5 projects (`CsrController::show()`). `$project->content` is
    rendered raw ({!! !!}), the same convention as news/show.blade.php:
    documented as sanitised HTML, sanitised at write time (not built yet in
    this stage) rather than at render time.
--}}
<x-layouts.public :title="$project->title">
    <div class="border-b border-border bg-muted/50">
        <div class="container mx-auto max-w-3xl px-4 py-10 lg:px-8">
            <a href="{{ route('csr.index') }}" class="btn btn-sm btn-brand">&larr; Back to CSR</a>

            <h1 class="mt-4 font-display text-3xl font-bold text-primary md:text-4xl">{{ $project->title }}</h1>
        </div>
    </div>

    <div class="container mx-auto max-w-3xl px-4 py-10 lg:px-8">
        @if ($project->image_path)
            <img src="{{ Storage::disk('public')->url($project->image_path) }}" alt="{{ $project->title }}" class="mb-8 aspect-[16/8] w-full rounded-xl object-cover shadow-elegant">
        @endif

        <div class="text-base leading-relaxed text-foreground/85 [&_a]:font-semibold [&_a]:text-[#CC001F] [&_a]:underline [&_blockquote]:border-l-4 [&_blockquote]:border-[#666666]/40 [&_blockquote]:pl-4 [&_blockquote]:italic [&_blockquote]:text-muted-foreground [&_h2]:mt-8 [&_h2]:mb-3 [&_h2]:font-display [&_h2]:text-2xl [&_h2]:font-bold [&_h2]:text-primary [&_h3]:mt-6 [&_h3]:mb-2 [&_h3]:font-display [&_h3]:text-xl [&_h3]:font-bold [&_h3]:text-primary [&_h4]:mt-4 [&_h4]:mb-2 [&_h4]:font-display [&_h4]:text-lg [&_h4]:font-bold [&_h4]:text-primary [&_li]:mb-1 [&_ol]:mb-4 [&_ol]:list-decimal [&_ol]:pl-5 [&_p]:mb-4 [&_ul]:mb-4 [&_ul]:list-disc [&_ul]:pl-5">
            {!! $project->content !!}
        </div>
    </div>
</x-layouts.public>
