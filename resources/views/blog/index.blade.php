@use('Illuminate\Support\Facades\Storage')
{{--
    Blog listing — docs/frontend/03_PUBLIC_PAGES.md §A8, scoped to what this
    task asks for. Card content/order is fixed by explicit instruction:
    featured image, category, title, 4-line excerpt, "View More" button —
    the publish date is intentionally not part of the card (still shown on
    the article page). Category filtering reuses the same `?category=slug`
    query-string convention already used by membership.apply/benefits.
--}}
<x-layouts.public title="Blog">
    <x-public.hero>
        <x-slot:badge>Blog</x-slot:badge>
        <x-slot:lead>
            News, stories and updates from Aviation Club International.
        </x-slot:lead>
        Insights &amp; <span class="text-[#CC001F]">stories.</span>
    </x-public.hero>

    <section class="border-t border-border">
        <div class="container mx-auto px-4 py-16 md:py-20 lg:px-8">
            @if ($categories->isNotEmpty())
                <nav class="flex flex-wrap gap-2" aria-label="Filter by category">
                    <a href="{{ route('blog.index') }}"
                        @class([
                            'rounded-md border px-3 py-1.5 text-sm font-medium transition-colors',
                            'border-[#CC001F] bg-[#CC001F] text-white' => ! $selectedCategory,
                            'border-border bg-background text-primary hover:border-[#CC001F]/60' => $selectedCategory,
                        ])>All</a>
                    @foreach ($categories as $category)
                        <a href="{{ route('blog.index', ['category' => $category->slug]) }}"
                            @class([
                                'rounded-md border px-3 py-1.5 text-sm font-medium transition-colors',
                                'border-[#CC001F] bg-[#CC001F] text-white' => $selectedCategory?->is($category),
                                'border-border bg-background text-primary hover:border-[#CC001F]/60' => ! $selectedCategory?->is($category),
                            ])>{{ $category->name }}</a>
                    @endforeach
                </nav>
            @endif

            @if ($posts->isEmpty())
                <p class="mt-10 rounded-lg border border-border bg-muted px-4 py-8 text-center text-sm text-muted-foreground" role="status">
                    No articles found.
                </p>
            @else
                <div class="mt-10 grid gap-6 md:grid-cols-2 lg:grid-cols-3">
                    @foreach ($posts as $post)
                        {{-- `h-full` on every card + `mt-auto` on the button row keeps
                             card heights consistent and the button aligned across a
                             row, whatever an individual excerpt's length is. --}}
                        <div class="ui-card flex h-full flex-col overflow-hidden">
                            {{-- 1. Featured image --}}
                            <div class="aspect-[16/10] w-full overflow-hidden bg-[#4D4D4D]">
                                @if ($post->featured_image_path)
                                    <img src="{{ Storage::disk('public')->url($post->featured_image_path) }}" alt="{{ $post->featured_image_alt ?? '' }}" class="h-full w-full object-cover">
                                @else
                                    <div class="grid h-full w-full place-items-center">
                                        <svg class="h-10 w-10 text-white/40" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M22 10v6M2 10l10-5 10 5-10 5z"/><path d="M6 12v5c3 3 9 3 12 0v-5"/></svg>
                                    </div>
                                @endif
                            </div>

                            <div class="flex flex-1 flex-col p-5">
                                {{-- 2. Category --}}
                                @if ($post->category)
                                    <span class="inline-flex w-fit items-center rounded-md border border-[#666666]/40 px-2 py-0.5 text-[11px] font-semibold uppercase tracking-wide text-[#4D4D4D]">{{ $post->category->name }}</span>
                                @endif

                                {{-- 3. Title --}}
                                <h2 class="mt-3 line-clamp-2 font-display text-lg font-bold text-primary">
                                    <a href="{{ route('blog.show', $post->slug) }}" class="hover:text-[#CC001F]">{{ $post->title }}</a>
                                </h2>

                                {{-- 4. Excerpt, clamped to 4 lines --}}
                                @if ($post->excerpt)
                                    <p class="mt-2 line-clamp-4 text-sm leading-relaxed text-muted-foreground">{{ $post->excerpt }}</p>
                                @endif

                                {{-- 5. View More — `mt-auto` pins it to the card's bottom
                                     regardless of how long the title/excerpt above are. --}}
                                <div class="mt-auto pt-4">
                                    <a href="{{ route('blog.show', $post->slug) }}" class="btn btn-lg btn-brand w-full">View More</a>
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>

                <div class="mt-10">{{ $posts->links() }}</div>
            @endif
        </div>
    </section>
</x-layouts.public>
