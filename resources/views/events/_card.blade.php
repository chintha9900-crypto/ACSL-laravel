@use('Illuminate\Support\Facades\Storage')
{{-- One event card, shared by the upcoming list and the Past Events Archive (markup unchanged from the original listing). --}}
<div class="ui-card flex h-full flex-col overflow-hidden">
    <div class="aspect-[16/10] w-full overflow-hidden bg-[#4D4D4D]" data-listing-image>
        @if ($event->image_path)
            <img data-fallback src="{{ Storage::disk('public')->url($event->image_path) }}" alt="{{ $event->title }}" class="h-full w-full object-cover">
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
