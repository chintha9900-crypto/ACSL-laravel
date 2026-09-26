<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreBlogCategoryRequest;
use App\Http\Requests\Admin\UpdateBlogCategoryRequest;
use App\Models\BlogCategory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class BlogCategoryController extends Controller
{
    public function index(): View
    {
        Gate::authorize('viewAny', BlogCategory::class);

        return view('admin.blog.categories', [
            'categories' => BlogCategory::query()->withCount('posts')->orderBy('name')->get(),
        ]);
    }

    public function store(StoreBlogCategoryRequest $request): RedirectResponse
    {
        BlogCategory::create($request->validated());

        return redirect()->route('admin.blog-categories.index')->with('status', 'Category added.');
    }

    public function update(UpdateBlogCategoryRequest $request, BlogCategory $category): RedirectResponse
    {
        $category->update($request->validated());

        return redirect()->route('admin.blog-categories.index')->with('status', 'Category updated.');
    }

    /**
     * Deleting a category never deletes its posts — the FK is SET NULL
     * (docs/database/10 §2), so existing posts simply become uncategorised.
     */
    public function destroy(BlogCategory $category): RedirectResponse
    {
        Gate::authorize('delete', $category);

        $category->delete();

        return redirect()->route('admin.blog-categories.index')->with('status', 'Category deleted.');
    }
}
