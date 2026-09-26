@use('Illuminate\Support\Facades\Storage')
{{--
    News listing — docs/frontend/03_PUBLIC_PAGES.md §A10, adapted to its own
    page per the approved routing (`/news`, separate from `/events`). Card
    layout mirrors blog/index.blade.php exactly for visual consistency:
    image, title, excerpt clamp, a link button — the published date is
    additionally shown here per the documented "meta date" card content.
--}}
<x-layouts.public title="News">
    <x-public.hero>
        <x-slot:badge>News</x-slot:badge>
        <x-slot:lead>
            Announcements and updates from Aviation Club International.
        </x-slot:lead>
        Club <span class="text-[#CC001F]">news.</span>
    </x-public.hero>

    <section class="border-t border-border">
        <div class="container mx-auto px-4 py-16 md:py-20 lg:px-8">
            @if ($items->isEmpty())
                <p class="rounded-lg border border-border bg-muted px-4 py-8 text-center text-sm text-muted-foreground" role="status">
                    No news yet.
                </p>
            @else
                <div class="grid gap-6 md:grid-cols-2 lg:grid-cols-3">
                    @foreach ($items as $item)
                        <div class="ui-card flex h-full flex-col overflow-hidden">
                            <div class="aspect-[16/10] w-full overflow-hidden bg-[#4D4D4D]">
                                @if ($item->image_path)
                                    <img src="{{ Storage::disk('public')->url($item->image_path) }}" alt="" class="h-full w-full object-cover">
                                @else
                                    <div class="grid h-full w-full place-items-center">
                                        <svg class="h-10 w-10 text-white/40" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 4h16v16H4z"/><path d="M4 9h16M9 4v16"/></svg>
                                    </div>
                                @endif
                            </div>

                            <div class="flex flex-1 flex-col p-5">
                                <p class="text-xs font-medium uppercase tracking-wide text-[#666666]">{{ $item->published_at->format('j M Y') }}</p>

                                <h2 class="mt-2 line-clamp-2 font-display text-lg font-bold text-primary">
                                    <a href="{{ route('news.show', $item->slug) }}" class="hover:text-[#CC001F]">{{ $item->title }}</a>
                                </h2>

                                @if ($item->excerpt)
                                    <p class="mt-2 line-clamp-3 text-sm leading-relaxed text-muted-foreground">{{ $item->excerpt }}</p>
                                @endif

                                <div class="mt-auto pt-4">
                                    <a href="{{ route('news.show', $item->slug) }}" class="inline-flex items-center gap-1 rounded-md border border-[#CC001F] px-4 py-2 text-sm font-semibold text-[#CC001F] transition-colors hover:bg-[#CC001F] hover:text-white">Read more &rarr;</a>
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>

                <div class="mt-10">{{ $items->links() }}</div>
            @endif
        </div>
    </section>
</x-layouts.public>
