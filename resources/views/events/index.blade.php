{{--
    Events listing — docs/frontend/03_PUBLIC_PAGES.md §A10, adapted to its
    own page per the approved routing (`/events`, separate from `/news`).
    Card layout mirrors blog/index.blade.php for visual consistency, with a
    "date · location" meta line in place of the category badge.

    Upcoming events first (soonest first, `?page=`), then the Past Events
    Archive (newest first, `?past_page=`). An event moves to the archive after
    its scheduled day ends in Asia/Colombo (App\Models\EventListing).
--}}
<x-layouts.public title="Events">
    <x-public.hero>
        <x-slot:badge>Events</x-slot:badge>
        <x-slot:lead>
            Meetups, workshops and gatherings from Aviation Club.
        </x-slot:lead>
        Upcoming <span class="text-[#CC001F]">events.</span>
    </x-public.hero>

    <section class="border-t border-border" aria-label="Upcoming events">
        <div class="site-container py-16 md:py-20">
            @if ($events->isEmpty())
                <p class="rounded-lg border border-border bg-muted px-4 py-8 text-center text-sm text-muted-foreground" role="status">
                    No upcoming events.
                </p>
            @else
                <div class="grid gap-6 md:grid-cols-2 lg:grid-cols-3" data-events-upcoming>
                    @foreach ($events as $event)
                        @include('events._card', ['event' => $event])
                    @endforeach
                </div>

                <div class="mt-10">{{ $events->links() }}</div>
            @endif
        </div>
    </section>

    @if ($pastEvents->total() > 0)
        <section id="past-events" class="border-t border-border bg-muted/40" aria-labelledby="past-events-heading">
            <div class="site-container py-16 md:py-20">
                <h2 id="past-events-heading" class="font-display text-2xl font-bold text-primary md:text-3xl">Past Events Archive</h2>
                <p class="mt-2 text-sm text-muted-foreground">Events that have already taken place, most recent first.</p>

                <div class="mt-8 grid gap-6 md:grid-cols-2 lg:grid-cols-3" data-events-past>
                    @foreach ($pastEvents as $event)
                        @include('events._card', ['event' => $event])
                    @endforeach
                </div>

                <div class="mt-10">{{ $pastEvents->fragment('past-events')->links() }}</div>
            </div>
        </section>
    @endif
</x-layouts.public>
