@use('Illuminate\Support\Facades\Storage')
{{--
    Events listing — docs/frontend/03_PUBLIC_PAGES.md §A10, adapted to its
    own page per the approved routing (`/events`, separate from `/news`).
    Card layout mirrors blog/index.blade.php for visual consistency, with a
    "date · location" meta line in place of the category badge.

    Past events are not filtered out (see App\Models\EventListing's
    docblock) — the empty state only shows when there are no events at all.
--}}
<x-layouts.public title="Events">
    <x-public.hero>
        <x-slot:badge>Events</x-slot:badge>
        <x-slot:lead>
            Meetups, workshops and gatherings from Aviation Club International.
        </x-slot:lead>
        Upcoming <span class="text-[#CC001F]">events.</span>
    </x-public.hero>

    <section class="border-t border-border">
        <div class="container mx-auto px-4 py-16 md:py-20 lg:px-8">
            @if ($events->isEmpty())
                <p class="rounded-lg border border-border bg-muted px-4 py-8 text-center text-sm text-muted-foreground" role="status">
                    No upcoming events.
                </p>
            @else
                <div class="grid gap-6 md:grid-cols-2 lg:grid-cols-3">
                    @foreach ($events as $event)
                        <div class="ui-card flex h-full flex-col overflow-hidden">
                            <div class="aspect-[16/10] w-full overflow-hidden bg-[#4D4D4D]">
                                @if ($event->image_path)
                                    <img src="{{ Storage::disk('public')->url($event->image_path) }}" alt="" class="h-full w-full object-cover">
                                @else
                                    <div class="grid h-full w-full place-items-center">
                                        <svg class="h-10 w-10 text-white/40" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="4" width="18" height="17" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/></svg>
                                    </div>
                                @endif
                            </div>

                            <div class="flex flex-1 flex-col p-5">
                                <p class="text-xs font-medium uppercase tracking-wide text-[#666666]">
                                    {{ $event->starts_at->format('j M Y, g:ia') }}
                                    @if ($event->location)
                                        &middot; {{ $event->location }}
                                    @endif
                                </p>

                                <h2 class="mt-2 line-clamp-2 font-display text-lg font-bold text-primary">
                                    <a href="{{ route('events.show', $event->slug) }}" class="hover:text-[#CC001F]">{{ $event->title }}</a>
                                </h2>

                                @if ($event->excerpt)
                                    <p class="mt-2 line-clamp-3 text-sm leading-relaxed text-muted-foreground">{{ $event->excerpt }}</p>
                                @endif

                                <div class="mt-auto pt-4">
                                    <a href="{{ route('events.show', $event->slug) }}" class="inline-flex items-center gap-1 rounded-md border border-[#CC001F] px-4 py-2 text-sm font-semibold text-[#CC001F] transition-colors hover:bg-[#CC001F] hover:text-white">Read more &rarr;</a>
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>

                <div class="mt-10">{{ $events->links() }}</div>
            @endif
        </div>
    </section>
</x-layouts.public>
