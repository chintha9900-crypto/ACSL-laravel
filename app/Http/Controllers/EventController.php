<?php

namespace App\Http\Controllers;

use App\Models\EventListing;
use Illuminate\View\View;

class EventController extends Controller
{
    /**
     * Upcoming events (soonest first, `?page=`) and the Past Events Archive
     * (newest first, `?past_page=`), paginated independently. The split is
     * EventListing::scopeUpcoming()/scopePast(), so an event is in exactly
     * one list and moves to the archive after its day ends in Colombo.
     */
    public function index(): View
    {
        $columns = ['id', 'title', 'slug', 'excerpt', 'image_path', 'starts_at', 'location', 'published_at'];

        $events = EventListing::query()
            ->select($columns)
            ->upcoming()
            ->orderBy('starts_at')
            ->orderBy('id')
            ->paginate(9, pageName: 'page')
            ->withQueryString();

        $pastEvents = EventListing::query()
            ->select($columns)
            ->past()
            ->orderByDesc('starts_at')
            ->orderByDesc('id')
            ->paginate(9, pageName: 'past_page')
            ->withQueryString();

        return view('events.index', ['events' => $events, 'pastEvents' => $pastEvents]);
    }

    /**
     * A draft (or not-yet-due) event 404s exactly like a non-existent slug —
     * never a different message that would confirm it exists.
     */
    public function show(EventListing $event): View
    {
        if (! $event->isPubliclyVisible()) {
            abort(404);
        }

        return view('events.show', ['event' => $event]);
    }
}
