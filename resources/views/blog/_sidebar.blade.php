{{--
    Blog sidebar — shared by the listing and article pages: search,
    categories, tags and popular posts (most approved comments; see
    BlogController::sidebar()).
--}}
<aside class="space-y-6" aria-label="Blog sidebar">
    <section class="ui-card p-5">
        <h2 class="font-display text-base font-bold text-primary">Search</h2>
        <form method="GET" action="{{ route('blog.index') }}" role="search" class="mt-3 flex gap-2">
            <label for="blog-search" class="sr-only">Search articles</label>
            <input id="blog-search" name="q" type="search" value="{{ $search ?? '' }}" maxlength="100" placeholder="Search articles" class="field-control">
            @if (! empty($selectedCategory))
                <input type="hidden" name="category" value="{{ $selectedCategory->slug }}">
            @endif
            @if (! empty($selectedTag))
                <input type="hidden" name="tag" value="{{ $selectedTag->slug }}">
            @endif
            <button type="submit" class="btn btn-sm btn-brand shrink-0">Search</button>
        </form>
    </section>

    @if ($categories->isNotEmpty())
        <section class="ui-card p-5">
            <h2 class="font-display text-base font-bold text-primary">Categories</h2>
            <ul class="mt-3 space-y-1.5 text-sm">
                @foreach ($categories as $category)
                    <li>
                        <a href="{{ route('blog.index', ['category' => $category->slug]) }}"
                            @if (($selectedCategory ?? null)?->is($category)) aria-current="true" @endif
                            @class(['flex justify-between gap-2 hover:text-[#CC001F]', 'font-semibold text-[#CC001F]' => ($selectedCategory ?? null)?->is($category), 'text-foreground' => ! ($selectedCategory ?? null)?->is($category)])>
                            <span>{{ $category->name }}</span>
                            <span class="text-muted-foreground">{{ $category->posts_count }}</span>
                        </a>
                    </li>
                @endforeach
            </ul>
        </section>
    @endif

    @if ($tags->isNotEmpty())
        <section class="ui-card p-5">
            <h2 class="font-display text-base font-bold text-primary">Tags</h2>
            <div class="mt-3 flex flex-wrap gap-2">
                @foreach ($tags as $tag)
                    <a href="{{ route('blog.index', ['tag' => $tag->slug]) }}"
                        @if (($selectedTag ?? null)?->is($tag)) aria-current="true" @endif
                        @class(['rounded-md border px-2.5 py-1 text-xs font-medium transition-colors', 'border-[#CC001F] bg-[#CC001F] text-white' => ($selectedTag ?? null)?->is($tag), 'border-border bg-background text-primary hover:border-[#CC001F]/60' => ! ($selectedTag ?? null)?->is($tag)])>{{ $tag->name }}</a>
                @endforeach
            </div>
        </section>
    @endif

    @if ($popular->isNotEmpty())
        <section class="ui-card p-5">
            <h2 class="font-display text-base font-bold text-primary">Popular posts</h2>
            <ol class="mt-3 space-y-3 text-sm">
                @foreach ($popular as $item)
                    <li>
                        <a href="{{ route('blog.show', $item->slug) }}" class="font-semibold text-foreground hover:text-[#CC001F]">{{ $item->title }}</a>
                        <p class="text-xs text-muted-foreground">{{ $item->published_at->format('j M Y') }}</p>
                    </li>
                @endforeach
            </ol>
        </section>
    @endif
</aside>
