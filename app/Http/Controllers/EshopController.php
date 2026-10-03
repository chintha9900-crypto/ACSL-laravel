<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\ProductCategory;
use Illuminate\Http\Request;
use Illuminate\View\View;

class EshopController extends Controller
{
    /**
     * `Product::scopeVisibleTo()` is the single source of the access rule
     * here — guests and non-active members only ever see `PUBLIC` products;
     * an authenticated active member sees the complete catalogue.
     */
    public function index(Request $request): View
    {
        $categories = ProductCategory::query()->where('is_active', true)->orderBy('name')->get();
        $category = $categories->firstWhere('slug', $request->query('category'));

        $products = Product::query()
            ->select(['id', 'product_category_id', 'name', 'slug', 'sku', 'description', 'price', 'access_type'])
            ->visibleTo($request->user())
            ->with(['category:id,name', 'inventory', 'images'])
            ->when($category, fn ($query) => $query->where('product_category_id', $category->id))
            ->orderBy('name')
            ->paginate(12)
            ->withQueryString();

        return view('eshop.index', [
            'products' => $products,
            'categories' => $categories,
            'selectedCategory' => $category,
        ]);
    }

    /**
     * An inactive product, or a `MEMBER_ONLY` product viewed by a guest or a
     * non-active member, 404s exactly like a non-existent slug — enforced
     * here server-side, never left to the view to merely hide the button.
     */
    public function show(Request $request, Product $product): View
    {
        if (! $product->is_active || ! $product->isAccessibleTo($request->user())) {
            abort(404);
        }

        $product->load(['category', 'images', 'inventory']);

        return view('eshop.show', ['product' => $product]);
    }
}
