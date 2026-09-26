<?php

namespace App\Http\Controllers;

use App\Models\EventListing;
use App\Models\NewsItem;
use Illuminate\View\View;

class NewsEventsController extends Controller
{
    /**
     * The combined "News & Events" landing page (docs/frontend/03
     * §A10) — a short preview of each, not the full lists. The full lists
     * stay at their own existing routes (`news.index`, `events.index`);
     * this page only links out to them, it does not replace them.
     */
    public function show(): View
    {
        $newsItems = NewsItem::query()
            ->select(['id', 'title', 'slug', 'excerpt', 'image_path', 'published_at'])
            ->published()
            ->orderByDesc('published_at')
            ->limit(3)
            ->get();

        $events = EventListing::query()
            ->select(['id', 'title', 'slug', 'excerpt', 'image_path', 'starts_at', 'location', 'published_at'])
            ->published()
            ->orderBy('starts_at')
            ->limit(3)
            ->get();

        return view('news-events', [
            'newsItems' => $newsItems,
            'events' => $events,
        ]);
    }
}
