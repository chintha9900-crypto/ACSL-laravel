<?php

namespace App\Http\Controllers;

use App\Models\BlogCategory;
use App\Models\BlogComment;
use App\Models\BlogPost;
use App\Models\BlogTag;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;

class BlogController extends Controller
{
    /**
     * Posts per page in the feed.
     */
    private const PER_PAGE = 6;

    /**
     * Only published posts are ever listed — every one of them in the feed,
     * newest first (the admin Featured flag does not change the listing).
     * Search and the category/tag filters are kept across pagination.
     */
    public function index(Request $request): View
    {
        $search = trim((string) $request->query('q', ''));
        $emptySearch = $request->has('q') && $search === '';
        $category = BlogCategory::query()->where('slug', (string) $request->query('category'))->first();
        $tag = BlogTag::query()->where('slug', (string) $request->query('tag'))->first();
        $filtering = $search !== '' || $category !== null || $tag !== null;

        $posts = BlogPost::query()
            ->select(['id', 'title', 'slug', 'excerpt', 'blog_category_id', 'author_id', 'featured_image_path', 'featured_image_alt', 'published_at'])
            ->published()
            ->with(['category:id,name,slug', 'author:id,name'])
            ->when($search !== '', fn (Builder $query) => $query->where(function (Builder $query) use ($search): void {
                $like = '%'.addcslashes($search, '\\%_').'%';
                $query->where('title', 'like', $like)->orWhere('excerpt', 'like', $like)->orWhere('content', 'like', $like);
            }))
            ->when($category, fn (Builder $query) => $query->where('blog_category_id', $category->id))
            ->when($tag, fn (Builder $query) => $query->whereHas('tags', fn (Builder $query) => $query->whereKey($tag->id)))
            ->orderByDesc('published_at')
            ->orderByDesc('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        return view('blog.index', [
            'posts' => $posts,
            'search' => $search,
            'emptySearch' => $emptySearch,
            'selectedCategory' => $category,
            'selectedTag' => $tag,
            'filtering' => $filtering,
            ...$this->sidebar(),
        ]);
    }

    public function show(Request $request, BlogPost $post): View
    {
        if (! $post->isPubliclyVisible()) {
            abort(404);
        }

        $post->load(['category:id,name,slug', 'author:id,name', 'tags:id,name,slug']);
        $user = $request->user();

        return view('blog.show', [
            'post' => $post,
            'related' => $this->related($post),
            'comments' => $post->comments()->approved()->with('user:id,name')->oldest()->paginate(10, ['*'], 'comments_page')->withQueryString(),
            // The member's own not-yet-approved comments: visible only to them.
            'ownComments' => $user === null ? collect() : $post->comments()->where('user_id', $user->id)->where('status', '!=', BlogComment::STATUS_APPROVED)->latest()->get(),
            'canComment' => $user !== null && $user->role === 'member' && $user->status === 'active',
            ...$this->sidebar(),
        ]);
    }

    /**
     * Up to three other published posts sharing this post's category or tags.
     *
     * @return Collection<int, BlogPost>
     */
    private function related(BlogPost $post): Collection
    {
        $tagIds = $post->tags->pluck('id');

        if ($post->blog_category_id === null && $tagIds->isEmpty()) {
            return collect();
        }

        return BlogPost::query()
            ->published()
            ->whereKeyNot($post->id)
            ->where(function (Builder $query) use ($post, $tagIds): void {
                $query->when($post->blog_category_id, fn (Builder $query) => $query->where('blog_category_id', $post->blog_category_id))
                    ->when($tagIds->isNotEmpty(), fn (Builder $query) => $query->orWhereHas('tags', fn (Builder $query) => $query->whereIn('blog_tags.id', $tagIds)));
            })
            ->orderByDesc('published_at')
            ->limit(3)
            ->get(['id', 'title', 'slug', 'featured_image_path', 'featured_image_alt', 'published_at']);
    }

    /**
     * Sidebar data shared by the listing and article pages. Counts cover
     * published posts only. "Popular" has no view-count data to draw on, so it
     * is the published posts with the most approved comments (newest first on
     * a tie) — a documented fallback, not an analytics figure.
     *
     * @return array<string, mixed>
     */
    private function sidebar(): array
    {
        return [
            'categories' => BlogCategory::query()
                ->withCount(['posts' => fn (Builder $query) => $query->published()])
                ->orderBy('name')
                ->get()
                ->filter(fn (BlogCategory $category): bool => $category->posts_count > 0)
                ->values(),
            'tags' => BlogTag::query()
                ->withCount(['posts' => fn (Builder $query) => $query->published()])
                ->orderBy('name')
                ->get()
                ->filter(fn (BlogTag $tag): bool => $tag->posts_count > 0)
                ->values(),
            'popular' => BlogPost::query()
                ->published()
                ->withCount(['comments' => fn (Builder $query) => $query->approved()])
                ->orderByDesc('comments_count')
                ->orderByDesc('published_at')
                ->limit(5)
                ->get(['id', 'title', 'slug', 'published_at']),
        ];
    }
}
