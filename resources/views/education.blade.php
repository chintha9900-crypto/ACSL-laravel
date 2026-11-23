@use('Illuminate\Support\Facades\Storage')
{{--
    Education — the topic cards come from EducationArticle (published only),
    placed in the left (main) or right (sidebar) column by `display_column`
    and ordered by `sort_order`; each opens its full article
    (education.show). The hero, the left column's heading and its intro stay
    fixed here. Card markup is unchanged from the original static page.

    Card buttons (sidebar cards): one button renders as the original red text
    link, two render as the original primary + outline button pair.

    @param  \Illuminate\Support\Collection<int, \App\Models\EducationArticle>  $leftArticles
    @param  \Illuminate\Support\Collection<int, \App\Models\EducationArticle>  $rightArticles
--}}
<x-layouts.public title="Education">
    <x-public.hero>
        <x-slot:badge>Education</x-slot:badge>
        <x-slot:lead>
            Aviation depends on people who keep learning. Good education and continuous professional development build the knowledge, skills and safety culture the industry relies on, whether you are just starting out or growing an established career.
        </x-slot:lead>
        Education
    </x-public.hero>

    <section class="border-t border-border">
        <div class="site-container grid gap-10 py-16 md:grid-cols-[2fr_1fr] md:py-20 lg:grid-cols-[7fr_3fr] lg:gap-12">
            <div class="min-w-0 space-y-6">
                <div>
                    <h2 class="font-display text-2xl font-bold text-primary">Aviation education and training pathways</h2>
                    <p class="mt-3 text-sm leading-relaxed text-muted-foreground">
                        There are many ways into aviation. These are some of the main areas of study and training.
                    </p>
                </div>

                @foreach ($leftArticles as $article)
                    <div class="ui-card p-6 md:p-8" data-education-card="left">
                        @if ($article->featured_image_path)
                            <img src="{{ Storage::disk('public')->url($article->featured_image_path) }}" alt="{{ $article->featured_image_alt ?? '' }}" class="mb-5 aspect-[16/9] w-full rounded-lg object-cover">
                        @endif
                        <h3 class="font-display text-xl font-bold text-primary">
                            <a href="{{ route('education.show', $article) }}" class="hover:underline">{{ $article->title }}</a>
                        </h3>
                        <p class="mt-3 text-sm leading-relaxed text-muted-foreground">{{ $article->short_description }}</p>
                        <a href="{{ route('education.show', $article) }}" class="mt-4 inline-block text-sm font-semibold text-[#CC001F] hover:underline">Read more<span class="sr-only"> about {{ $article->title }}</span></a>
                    </div>
                @endforeach
            </div>

            <aside class="space-y-6">
                @foreach ($rightArticles as $article)
                    @php($buttons = array_values(array_filter([
                        $article->button_label ? [$article->button_label, $article->button_url] : null,
                        $article->secondary_button_label ? [$article->secondary_button_label, $article->secondary_button_url] : null,
                    ])))
                    <div class="ui-card p-6" data-education-card="right">
                        @if ($article->featured_image_path)
                            <img src="{{ Storage::disk('public')->url($article->featured_image_path) }}" alt="{{ $article->featured_image_alt ?? '' }}" class="mb-4 aspect-[16/9] w-full rounded-lg object-cover">
                        @endif
                        <h2 class="font-display text-lg font-bold text-primary">
                            <a href="{{ route('education.show', $article) }}" class="hover:underline">{{ $article->title }}</a>
                        </h2>
                        <p class="mt-3 text-sm leading-relaxed text-muted-foreground">{{ $article->short_description }}</p>

                        @if (count($buttons) === 2)
                            <div class="mt-4 flex flex-wrap gap-2">
                                <a href="{{ $buttons[0][1] }}" class="btn btn-sm btn-brand">{{ $buttons[0][0] }}</a>
                                <a href="{{ $buttons[1][1] }}" class="btn btn-sm btn-ghost border border-border">{{ $buttons[1][0] }}</a>
                            </div>
                        @elseif (count($buttons) === 1)
                            <a href="{{ $buttons[0][1] }}" class="mt-4 inline-block text-sm font-semibold text-[#CC001F] hover:underline">{{ $buttons[0][0] }}</a>
                        @else
                            <a href="{{ route('education.show', $article) }}" class="mt-4 inline-block text-sm font-semibold text-[#CC001F] hover:underline">Read more<span class="sr-only"> about {{ $article->title }}</span></a>
                        @endif
                    </div>
                @endforeach
            </aside>
        </div>
    </section>
</x-layouts.public>
