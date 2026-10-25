@use('Illuminate\Support\Facades\Storage')
{{--
    Blog listing — three columns on desktop: the feed in the left two, the
    shared sidebar in the right one; a single column on mobile. Every published
    post is in the feed, six per page, newest first. Feed cards: thumbnail, category, H2 title, excerpt, date and author,
    "View More"; the image and the title also open the article.
--}}
<x-layouts.public title="Blog">
    <x-public.hero>
        <x-slot:badge>Blog</x-slot:badge>
        <x-slot:lead>
            News, stories and updates from Aviation Club.
        </x-slot:lead>
        Insights &amp; <span class="text-[#CC001F]">stories.</span>
    </x-public.hero>

    <section class="border-t border-border">
        <div class="container mx-auto grid gap-8 px-4 py-16 md:py-20 lg:grid-cols-3 lg:px-8">
            <div class="min-w-0 lg:col-span-2">
                @if ($filtering)
                    <div class="mb-6 flex flex-wrap items-center justify-between gap-3 rounded-lg border border-border bg-muted/50 px-4 py-3 text-sm" role="status">
                        <p>
                            @if ($search !== '') Results for “<strong>{{ $search }}</strong>” @else Showing posts @endif
                            @if ($selectedCategory) in <strong>{{ $selectedCategory->name }}</strong> @endif
                            @if ($selectedTag) tagged <strong>{{ $selectedTag->name }}</strong> @endif
                            &middot; {{ $posts->total() }} {{ Str::plural('post', $posts->total()) }}
                        </p>
                        <a href="{{ route('blog.index') }}" class="font-semibold text-[#CC001F] hover:underline">Clear filters</a>
                    </div>
                @endif

                @if ($emptySearch)
                    <p class="mb-6 rounded-lg border border-border bg-muted px-4 py-3 text-sm text-muted-foreground" role="status">Please enter a word to search for.</p>
                @endif

                @if ($posts->isEmpty())
                    <p class="rounded-lg border border-border bg-muted px-4 py-8 text-center text-sm text-muted-foreground" role="status">
                        No articles found.
                        @if ($filtering)
                            Try a different search, category or tag, or <a href="{{ route('blog.index') }}" class="font-semibold text-[#CC001F] hover:underline">view all articles</a>.
                        @endif
                    </p>
                @elseif ($posts->isNotEmpty())
                    <div class="grid gap-6 md:grid-cols-2">
                        @foreach ($posts as $post)
                            <article class="ui-card flex h-full flex-col overflow-hidden">
                                <a href="{{ route('blog.show', $post->slug) }}" class="block aspect-[16/10] w-full overflow-hidden bg-[#4D4D4D]" tabindex="-1" aria-hidden="true">
                                    @if ($post->featured_image_path)
                                        <img src="{{ Storage::disk('public')->url($post->featured_image_path) }}" alt="" class="h-full w-full object-cover">
                                    @else
                                        <span class="grid h-full w-full place-items-center">
                                            <svg class="h-10 w-10 text-white/40" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M22 10v6M2 10l10-5 10 5-10 5z"/><path d="M6 12v5c3 3 9 3 12 0v-5"/></svg>
                                        </span>
                                    @endif
                                </a>

                                <div class="flex flex-1 flex-col p-5">
                                    @if ($post->category)
                                        <span class="inline-flex w-fit items-center rounded-md border border-[#666666]/40 px-2 py-0.5 text-[11px] font-semibold uppercase tracking-wide text-[#4D4D4D]">{{ $post->category->name }}</span>
                                    @endif

                                    <h2 class="mt-3 line-clamp-2 font-display text-lg font-bold text-primary">
                                        <a href="{{ route('blog.show', $post->slug) }}" class="hover:text-[#CC001F]">{{ $post->title }}</a>
                                    </h2>

                                    @if ($post->excerpt)
                                        <p class="mt-2 line-clamp-3 text-sm leading-relaxed text-muted-foreground">{{ $post->excerpt }}</p>
                                    @endif

                                    <p class="mt-3 text-xs text-muted-foreground">
                                        <time datetime="{{ $post->published_at->toDateString() }}">{{ $post->published_at->format('j M Y') }}</time>
                                        @if ($post->author) &middot; {{ $post->author->name }} @endif
                                    </p>

                                    <div class="mt-auto pt-4">
                                        <a href="{{ route('blog.show', $post->slug) }}" class="btn btn-lg btn-brand w-full">View More</a>
                                    </div>
                                </div>
                            </article>
                        @endforeach
                    </div>
                @endif

                <div class="mt-10">{{ $posts->links() }}</div>
            </div>

            @include('blog._sidebar')
        </div>
    </section>
</x-layouts.public>
