@use('Illuminate\Support\Facades\Storage')
{{--
    News & Events landing — docs/frontend/03_PUBLIC_PAGES.md §A10: a hero
    band plus two clearly separated columns, News on the left and Events on
    the right, each a short stack of preview cards with a link to its own
    full list. `news.index`/`events.index` remain the full, paginated lists
    — this page only previews and links to them, per the approved routing.
    Card style/classes match news/index.blade.php and events/index.blade.php
    for visual consistency (not extracted into a shared component, since
    those two existing pages are left untouched).
--}}
<x-layouts.public title="News &amp; Events">
    <x-public.hero>
        <x-slot:badge>News &amp; Events</x-slot:badge>
        <x-slot:lead>
            What's happening at Aviation Club International.
        </x-slot:lead>
        News &amp; <span class="text-[#CC001F]">Events.</span>
    </x-public.hero>

    <section class="border-t border-border">
        <div class="container mx-auto px-4 py-16 md:py-20 lg:px-8">
            <div class="grid gap-12 lg:grid-cols-2 lg:gap-8">
                {{-- News column --}}
                <div class="lg:border-r lg:border-border lg:pr-8">
                    <div class="flex items-center justify-between gap-4">
                        <h2 class="flex items-center gap-2 font-display text-2xl font-bold text-primary">
                            <span class="grid h-9 w-9 place-items-center rounded-lg bg-[#CC001F]/10 text-[#CC001F]">
                                <svg class="h-5 w-5" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 4h16v16H4z"/><path d="M4 9h16M9 4v16"/></svg>
                            </span>
                            News
                        </h2>
                        <a href="{{ route('news.index') }}" class="text-sm font-semibold text-[#CC001F] hover:underline">View all &rarr;</a>
                    </div>

                    <div class="mt-6 space-y-6">
                        @forelse ($newsItems as $item)
                            <div class="ui-card flex gap-4 overflow-hidden p-4">
                                <div class="aspect-[16/10] w-32 flex-shrink-0 overflow-hidden rounded-md bg-[#4D4D4D] sm:w-40">
                                    @if ($item->image_path)
                                        <img src="{{ Storage::disk('public')->url($item->image_path) }}" alt="" class="h-full w-full object-cover">
                                    @else
                                        <div class="grid h-full w-full place-items-center">
                                            <svg class="h-6 w-6 text-white/40" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 4h16v16H4z"/><path d="M4 9h16M9 4v16"/></svg>
                                        </div>
                                    @endif
                                </div>

                                <div class="flex flex-1 flex-col">
                                    <p class="text-xs font-medium uppercase tracking-wide text-[#666666]">{{ $item->published_at->format('j M Y') }}</p>
                                    <h3 class="mt-1 line-clamp-2 font-display text-base font-bold text-primary">
                                        <a href="{{ route('news.show', $item->slug) }}" class="hover:text-[#CC001F]">{{ $item->title }}</a>
                                    </h3>
                                    @if ($item->excerpt)
                                        <p class="mt-1 line-clamp-3 text-sm leading-relaxed text-muted-foreground">{{ $item->excerpt }}</p>
                                    @endif
                                    <a href="{{ route('news.show', $item->slug) }}" class="mt-auto inline-flex items-center gap-1 pt-2 text-sm font-semibold text-[#CC001F] hover:underline">Read more &rarr;</a>
                                </div>
                            </div>
                        @empty
                            <p class="rounded-lg border border-border bg-muted px-4 py-8 text-center text-sm text-muted-foreground" role="status">
                                No news yet.
                            </p>
                        @endforelse
                    </div>
                </div>

                {{-- Events column --}}
                <div>
                    <div class="flex items-center justify-between gap-4">
                        <h2 class="flex items-center gap-2 font-display text-2xl font-bold text-primary">
                            <span class="grid h-9 w-9 place-items-center rounded-lg bg-[#CC001F]/10 text-[#CC001F]">
                                <svg class="h-5 w-5" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="4" width="18" height="17" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/></svg>
                            </span>
                            Events
                        </h2>
                        <a href="{{ route('events.index') }}" class="text-sm font-semibold text-[#CC001F] hover:underline">View all &rarr;</a>
                    </div>

                    <div class="mt-6 space-y-6">
                        @forelse ($events as $event)
                            <div class="ui-card flex gap-4 overflow-hidden p-4">
                                <div class="aspect-[16/10] w-32 flex-shrink-0 overflow-hidden rounded-md bg-[#4D4D4D] sm:w-40">
                                    @if ($event->image_path)
                                        <img src="{{ Storage::disk('public')->url($event->image_path) }}" alt="" class="h-full w-full object-cover">
                                    @else
                                        <div class="grid h-full w-full place-items-center">
                                            <svg class="h-6 w-6 text-white/40" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="4" width="18" height="17" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/></svg>
                                        </div>
                                    @endif
                                </div>

                                <div class="flex flex-1 flex-col">
                                    <p class="text-xs font-medium uppercase tracking-wide text-[#666666]">
                                        {{ $event->starts_at->format('j M Y, g:ia') }}
                                        @if ($event->location)
                                            &middot; {{ $event->location }}
                                        @endif
                                    </p>
                                    <h3 class="mt-1 line-clamp-2 font-display text-base font-bold text-primary">
                                        <a href="{{ route('events.show', $event->slug) }}" class="hover:text-[#CC001F]">{{ $event->title }}</a>
                                    </h3>
                                    @if ($event->excerpt)
                                        <p class="mt-1 line-clamp-3 text-sm leading-relaxed text-muted-foreground">{{ $event->excerpt }}</p>
                                    @endif
                                    <a href="{{ route('events.show', $event->slug) }}" class="mt-auto inline-flex items-center gap-1 pt-2 text-sm font-semibold text-[#CC001F] hover:underline">Read more &rarr;</a>
                                </div>
                            </div>
                        @empty
                            <p class="rounded-lg border border-border bg-muted px-4 py-8 text-center text-sm text-muted-foreground" role="status">
                                No upcoming events.
                            </p>
                        @endforelse
                    </div>
                </div>
            </div>
        </div>
    </section>
</x-layouts.public>
