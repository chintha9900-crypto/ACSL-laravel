<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreProductCategoryRequest;
use App\Http\Requests\Admin\UpdateProductCategoryRequest;
use App\Models\ProductCategory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class ProductCategoryController extends Controller
{
    public function index(): View
    {
        Gate::authorize('viewAny', ProductCategory::class);

        return view('admin.products.categories', [
            'categories' => ProductCategory::query()->withCount('products')->orderBy('name')->get(),
        ]);
    }

    public function store(StoreProductCategoryRequest $request): RedirectResponse
    {
        ProductCategory::create($request->validated());

        return redirect()->route('admin.product-categories.index')->with('status', 'Category added.');
    }

    public function update(UpdateProductCategoryRequest $request, ProductCategory $category): RedirectResponse
    {
        $category->update($request->validated());

        return redirect()->route('admin.product-categories.index')->with('status', 'Category updated.');
    }

    /**
     * `product_category_id` is `RESTRICT` on delete (unlike blog categories'
     * `SET NULL`), so a category with products assigned must be rejected
     * here rather than letting the database throw.
     */
    public function destroy(ProductCategory $category): RedirectResponse
    {
        Gate::authorize('delete', $category);

        if ($category->products()->exists()) {
            return back()->with('warning', 'This category still has products assigned. Reassign or remove them before deleting it.');
        }

        $category->delete();

        return redirect()->route('admin.product-categories.index')->with('status', 'Category deleted.');
    }

    public function activate(ProductCategory $category): RedirectResponse
    {
        Gate::authorize('update', $category);

        $category->update(['is_active' => true]);

        return redirect()->route('admin.product-categories.index')->with('status', 'Category activated.');
    }

    public function deactivate(ProductCategory $category): RedirectResponse
    {
        Gate::authorize('update', $category);

        $category->update(['is_active' => false]);

        return redirect()->route('admin.product-categories.index')->with('status', 'Category deactivated.');
    }
}
