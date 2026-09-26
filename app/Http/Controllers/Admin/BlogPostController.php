<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Content\PublishBlogPost;
use App\Actions\Content\SaveBlogPost;
use App\Actions\Content\UnpublishBlogPost;
use App\Actions\Content\UpdateBlogFeaturedImage;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreBlogPostRequest;
use App\Http\Requests\Admin\UpdateBlogPostRequest;
use App\Models\BlogCategory;
use App\Models\BlogPost;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;

class BlogPostController extends Controller
{
    /**
     * Every post regardless of status — draft/published filtering and the
     * public "published only" rule are separate concerns
     * (BlogPost::scopePublished() is never applied here).
     */
    public function index(Request $request): View
    {
        Gate::authorize('viewAny', BlogPost::class);

        $status = $request->query('status');
        $status = in_array($status, BlogPost::STATUSES, true) ? $status : null;

        $categoryId = $request->query('category');
        $categoryId = ctype_digit((string) $categoryId) ? (int) $categoryId : null;

        $posts = BlogPost::query()
            ->select(['id', 'title', 'slug', 'blog_category_id', 'status', 'published_at', 'updated_at'])
            ->with('category:id,name')
            ->when($status, fn ($query) => $query->where('status', $status))
            ->when($categoryId, fn ($query) => $query->where('blog_category_id', $categoryId))
            ->orderByDesc('updated_at')
            ->paginate(15)
            ->withQueryString();

        return view('admin.blog.index', [
            'posts' => $posts,
            'categories' => BlogCategory::query()->orderBy('name')->get(),
            'status' => $status,
            'categoryId' => $categoryId,
        ]);
    }

    public function create(): View
    {
        Gate::authorize('create', BlogPost::class);

        return view('admin.blog.create', [
            'categories' => BlogCategory::query()->orderBy('name')->get(),
        ]);
    }

    public function store(StoreBlogPostRequest $request, SaveBlogPost $save, UpdateBlogFeaturedImage $updateImage): RedirectResponse
    {
        $post = $save->create($request->safe()->except(['featured_image']), $request->user());

        if ($request->hasFile('featured_image')) {
            $updateImage->handle($post, $request->file('featured_image'));
        }

        return redirect()->route('admin.blog.index')->with('status', 'Post created as a draft.');
    }

    public function edit(BlogPost $post): View
    {
        Gate::authorize('update', $post);

        return view('admin.blog.edit', [
            'post' => $post,
            'categories' => BlogCategory::query()->orderBy('name')->get(),
        ]);
    }

    public function update(UpdateBlogPostRequest $request, BlogPost $post, SaveBlogPost $save, UpdateBlogFeaturedImage $updateImage): RedirectResponse
    {
        $save->update($post, $request->safe()->except(['featured_image']));

        if ($request->hasFile('featured_image')) {
            $updateImage->handle($post, $request->file('featured_image'));
        }

        return redirect()->route('admin.blog.index')->with('status', 'Post updated.');
    }

    public function destroy(BlogPost $post): RedirectResponse
    {
        Gate::authorize('delete', $post);

        if ($post->featured_image_path !== null) {
            Storage::disk('public')->delete($post->featured_image_path);
        }

        $post->delete();

        return redirect()->route('admin.blog.index')->with('status', 'Post deleted.');
    }

    public function publish(BlogPost $post, PublishBlogPost $publish): RedirectResponse
    {
        Gate::authorize('publish', $post);

        $publish->handle($post);

        return redirect()->route('admin.blog.index')->with('status', 'Post published.');
    }

    public function unpublish(BlogPost $post, UnpublishBlogPost $unpublish): RedirectResponse
    {
        Gate::authorize('publish', $post);

        $unpublish->handle($post);

        return redirect()->route('admin.blog.index')->with('status', 'Post unpublished.');
    }
}
