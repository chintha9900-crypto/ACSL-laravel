<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Catalogue\SaveProduct;
use App\Actions\Catalogue\UpdateProductImage;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreProductRequest;
use App\Http\Requests\Admin\UpdateProductRequest;
use App\Models\Product;
use App\Models\ProductCategory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;

class ProductController extends Controller
{
    public function index(Request $request): View
    {
        Gate::authorize('viewAny', Product::class);

        $categoryId = $request->query('category');
        $categoryId = ctype_digit((string) $categoryId) ? (int) $categoryId : null;

        $products = Product::query()
            ->select(['id', 'product_category_id', 'name', 'slug', 'sku', 'price', 'access_type', 'is_active', 'updated_at'])
            ->with('category:id,name')
            ->when($categoryId, fn ($query) => $query->where('product_category_id', $categoryId))
            ->orderByDesc('updated_at')
            ->paginate(15)
            ->withQueryString();

        return view('admin.products.index', [
            'products' => $products,
            'categories' => ProductCategory::query()->orderBy('name')->get(),
            'categoryId' => $categoryId,
        ]);
    }

    public function create(): View
    {
        Gate::authorize('create', Product::class);

        return view('admin.products.create', [
            'categories' => ProductCategory::query()->orderBy('name')->get(),
        ]);
    }

    public function store(StoreProductRequest $request, SaveProduct $save, UpdateProductImage $updateImage): RedirectResponse
    {
        $product = $save->create($request->safe()->except(['image']));

        if ($request->hasFile('image')) {
            $updateImage->handle($product, $request->file('image'));
        }

        return redirect()->route('admin.products.index')->with('status', 'Product created as inactive.');
    }

    public function show(Product $product): View
    {
        Gate::authorize('view', $product);

        $product->load(['category', 'images', 'inventory']);

        return view('admin.products.show', ['product' => $product]);
    }

    public function edit(Product $product): View
    {
        Gate::authorize('update', $product);

        $product->load('inventory');

        return view('admin.products.edit', [
            'product' => $product,
            'categories' => ProductCategory::query()->orderBy('name')->get(),
        ]);
    }

    public function update(UpdateProductRequest $request, Product $product, SaveProduct $save, UpdateProductImage $updateImage): RedirectResponse
    {
        $save->update($product, $request->safe()->except(['image']));

        if ($request->hasFile('image')) {
            $updateImage->handle($product, $request->file('image'));
        }

        return redirect()->route('admin.products.index')->with('status', 'Product updated.');
    }

    /**
     * `cart_items.product_id` is `RESTRICT` on delete — checked here first,
     * exactly like `ProductCategoryController::destroy()` already does for
     * `product_category_id`, so this is a clear admin-facing message rather
     * than a raw `QueryException` bubbling up from the database.
     */
    public function destroy(Product $product): RedirectResponse
    {
        Gate::authorize('delete', $product);

        if ($product->cartItems()->exists()) {
            return back()->with('warning', 'This product is currently in a customer\'s cart and cannot be deleted.');
        }

        foreach ($product->images as $image) {
            Storage::disk('public')->delete($image->image_path);
        }

        $product->delete();

        return redirect()->route('admin.products.index')->with('status', 'Product deleted.');
    }

    public function activate(Product $product): RedirectResponse
    {
        Gate::authorize('update', $product);

        $product->update(['is_active' => true]);

        return redirect()->route('admin.products.index')->with('status', 'Product activated.');
    }

    public function deactivate(Product $product): RedirectResponse
    {
        Gate::authorize('update', $product);

        $product->update(['is_active' => false]);

        return redirect()->route('admin.products.index')->with('status', 'Product deactivated.');
    }
}
