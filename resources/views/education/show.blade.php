@use('App\Models\EducationArticle')
@use('App\Support\HtmlSanitizer')
@use('Illuminate\Support\Facades\Storage')
{{--
    One Education article, at the site's 48rem reading width. Public visitors
    only ever reach published articles (EducationController@show); the admin
    preview renders this same view for drafts, with a banner, behind the
    management permission. Content is sanitised again for display, keeping
    only images from the Education upload folder.

    @param  \App\Models\EducationArticle  $article
    @param  bool  $preview
--}}
<x-layouts.public :title="$article->title.' — Education'">
    @if ($preview)
        <div class="border-b border-[#CC001F]/30 bg-[#CC001F]/5" role="status">
            <div class="site-container flex flex-wrap items-center justify-between gap-3 py-3 text-sm text-[#CC001F]">
                <p><strong>Preview</strong> — {{ $article->isPublished() ? 'this article is published.' : 'this draft is not visible to the public.' }}</p>
                <a href="{{ route('admin.education.edit', $article) }}" class="font-semibold underline">Back to editing</a>
            </div>
        </div>
    @endif

    <x-public.hero>
        <x-slot:badge>Education</x-slot:badge>
        <x-slot:lead>{{ $article->short_description }}</x-slot:lead>
        {{ $article->title }}
    </x-public.hero>

    <section class="border-t border-border">
        <div class="site-container content-reading py-12 md:py-16">
            <div>
                <a href="{{ route('education') }}" class="inline-flex items-center gap-1.5 text-sm font-semibold text-[#CC001F] hover:underline">
                    <svg class="h-4 w-4" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m15 18-6-6 6-6"/></svg>
                    Back to Education
                </a>

                <article class="mt-6">
                    @if ($article->featured_image_path)
                        <img src="{{ Storage::disk('public')->url($article->featured_image_path) }}" alt="{{ $article->featured_image_alt ?? '' }}" class="mb-8 w-full rounded-xl object-cover">
                    @endif

                    <div class="blog-content [&_img]:my-6 [&_img]:h-auto [&_img]:max-w-full [&_img]:rounded-lg">
                        {!! HtmlSanitizer::forDisplay((string) $article->content, EducationArticle::inlineImageUrlPrefix()) !!}
                    </div>

                    @php($buttons = array_values(array_filter([
                        $article->button_label ? [$article->button_label, $article->button_url] : null,
                        $article->secondary_button_label ? [$article->secondary_button_label, $article->secondary_button_url] : null,
                    ])))
                    @if ($buttons !== [])
                        <div class="mt-8 flex flex-wrap gap-2">
                            @foreach ($buttons as $index => [$label, $url])
                                <a href="{{ $url }}" @class(['btn btn-sm', 'btn-brand' => $index === 0, 'btn-ghost border border-border' => $index === 1])>{{ $label }}</a>
                            @endforeach
                        </div>
                    @endif
                </article>
            </div>
        </div>
    </section>
</x-layouts.public>
