<?php

namespace App\Http\Controllers;

use App\Models\BlogCategory;
use App\Models\BlogPost;
use Illuminate\Http\Request;
use Illuminate\View\View;

class BlogController extends Controller
{
    /**
     * Published posts only, newest first, optionally filtered by category
     * slug (?category=). No search/reading-time — not asked for; only the
     * fields this task named are shown.
     */
    public function index(Request $request): View
    {
        $categories = BlogCategory::query()->orderBy('name')->get();
        $category = $categories->firstWhere('slug', $request->query('category'));

        $posts = BlogPost::query()
            ->select(['id', 'title', 'slug', 'excerpt', 'blog_category_id', 'featured_image_path', 'published_at'])
            ->published()
            ->with('category:id,name,slug')
            ->when($category, fn ($query) => $query->where('blog_category_id', $category->id))
            ->orderByDesc('published_at')
            ->paginate(9)
            ->withQueryString();

        return view('blog.index', [
            'posts' => $posts,
            'categories' => $categories,
            'selectedCategory' => $category,
        ]);
    }

    /**
     * A draft (or not-yet-due) post 404s exactly like a non-existent slug —
     * never a different message that would confirm it exists.
     */
    public function show(BlogPost $post): View
    {
        if (! $post->isPubliclyVisible()) {
            abort(404);
        }

        $post->load('category:id,name,slug');

        return view('blog.show', ['post' => $post]);
    }
}
