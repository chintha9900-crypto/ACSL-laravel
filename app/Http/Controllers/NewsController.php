<?php

namespace App\Http\Controllers;

use App\Models\NewsItem;
use Illuminate\View\View;

class NewsController extends Controller
{
    /**
     * Published news items only, newest first.
     */
    public function index(): View
    {
        $items = NewsItem::query()
            ->select(['id', 'title', 'slug', 'excerpt', 'image_path', 'published_at'])
            ->published()
            ->orderByDesc('published_at')
            ->paginate(9)
            ->withQueryString();

        return view('news.index', ['items' => $items]);
    }

    /**
     * A draft (or not-yet-due) item 404s exactly like a non-existent slug —
     * never a different message that would confirm it exists.
     */
    public function show(NewsItem $news): View
    {
        if (! $news->isPubliclyVisible()) {
            abort(404);
        }

        return view('news.show', ['item' => $news]);
    }
}
