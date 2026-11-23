<?php

namespace App\Http\Controllers;

use App\Models\BlogPost;
use App\Models\EventListing;
use Illuminate\View\View;

/**
 * The homepage. Only the "Featured insights & events" section is dynamic:
 * the next three upcoming published events (EventListing::scopeUpcoming(),
 * soonest first) and the published blog posts in homepage positions 1 and 2
 * (BlogPost::onHomepage()). Two bounded queries plus one eager load for
 * categories — no per-item queries.
 */
class HomeController extends Controller
{
    public function show(): View
    {
        $upcomingEvents = EventListing::query()
            ->select(['id', 'title', 'slug', 'excerpt', 'starts_at', 'published_at'])
            ->upcoming()
            ->orderBy('starts_at')
            ->orderBy('id')
            ->limit(3)
            ->get();

        return view('home', [
            'upcomingEvents' => $upcomingEvents,
            'homepageArticles' => BlogPost::onHomepage(),
        ]);
    }
}
