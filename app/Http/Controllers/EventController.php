<?php

namespace App\Http\Controllers;

use App\Models\EventListing;
use Illuminate\View\View;

class EventController extends Controller
{
    /**
     * Published events only, soonest first. Past events are not filtered
     * out — whether to list upcoming events only is an unresolved,
     * unconfirmed rule (docs/database/10 §7), not decided here.
     */
    public function index(): View
    {
        $events = EventListing::query()
            ->select(['id', 'title', 'slug', 'excerpt', 'image_path', 'starts_at', 'location', 'published_at'])
            ->published()
            ->orderBy('starts_at')
            ->paginate(9)
            ->withQueryString();

        return view('events.index', ['events' => $events]);
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
