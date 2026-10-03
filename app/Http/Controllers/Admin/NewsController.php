<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Content\PublishNewsItem;
use App\Actions\Content\SaveNewsItem;
use App\Actions\Content\UnpublishNewsItem;
use App\Actions\Content\UpdateNewsImage;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreNewsItemRequest;
use App\Http\Requests\Admin\UpdateNewsItemRequest;
use App\Models\NewsItem;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;

/**
 * Admin News CRUD — mirrors Admin\BlogPostController, minus the category
 * relation (NewsItem has none).
 */
class NewsController extends Controller
{
    /**
     * Every item regardless of status — draft/published filtering and the
     * public "published only" rule are separate concerns
     * (NewsItem::scopePublished() is never applied here).
     */
    public function index(Request $request): View
    {
        Gate::authorize('viewAny', NewsItem::class);

        $status = $request->query('status');
        $status = in_array($status, NewsItem::STATUSES, true) ? $status : null;

        $items = NewsItem::query()
            ->select(['id', 'title', 'slug', 'status', 'published_at', 'updated_at'])
            ->when($status, fn ($query) => $query->where('status', $status))
            ->orderByDesc('updated_at')
            ->paginate(15)
            ->withQueryString();

        return view('admin.news.index', [
            'items' => $items,
            'status' => $status,
        ]);
    }

    public function create(): View
    {
        Gate::authorize('create', NewsItem::class);

        return view('admin.news.create');
    }

    public function store(StoreNewsItemRequest $request, SaveNewsItem $save, UpdateNewsImage $updateImage): RedirectResponse
    {
        $news = $save->create($request->safe()->except(['image']), $request->user());

        if ($request->hasFile('image')) {
            $updateImage->handle($news, $request->file('image'));
        }

        return redirect()->route('admin.news.index')->with('status', 'News item created as a draft.');
    }

    public function show(NewsItem $news): View
    {
        Gate::authorize('view', $news);

        return view('admin.news.show', ['news' => $news]);
    }

    public function edit(NewsItem $news): View
    {
        Gate::authorize('update', $news);

        return view('admin.news.edit', ['news' => $news]);
    }

    public function update(UpdateNewsItemRequest $request, NewsItem $news, SaveNewsItem $save, UpdateNewsImage $updateImage): RedirectResponse
    {
        $save->update($news, $request->safe()->except(['image']));

        if ($request->hasFile('image')) {
            $updateImage->handle($news, $request->file('image'));
        }

        return redirect()->route('admin.news.index')->with('status', 'News item updated.');
    }

    public function destroy(NewsItem $news): RedirectResponse
    {
        Gate::authorize('delete', $news);

        if ($news->image_path !== null) {
            Storage::disk('public')->delete($news->image_path);
        }

        $news->delete();

        return redirect()->route('admin.news.index')->with('status', 'News item deleted.');
    }

    public function publish(NewsItem $news, PublishNewsItem $publish): RedirectResponse
    {
        Gate::authorize('publish', $news);

        $publish->handle($news);

        return redirect()->route('admin.news.index')->with('status', 'News item published.');
    }

    public function unpublish(NewsItem $news, UnpublishNewsItem $unpublish): RedirectResponse
    {
        Gate::authorize('publish', $news);

        $unpublish->handle($news);

        return redirect()->route('admin.news.index')->with('status', 'News item unpublished.');
    }
}
