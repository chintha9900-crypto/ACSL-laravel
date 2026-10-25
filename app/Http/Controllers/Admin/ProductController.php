<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Catalogue\ProductImageFiles;
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
use Illuminate\View\View;
use RuntimeException;

class ProductController extends Controller
{
    /**
     * Request fields handled by UpdateProductImage, not by SaveProduct.
     */
    private const GALLERY_FIELDS = ['images', 'remove_images', 'image_order', 'primary_image'];

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
        $product = $save->create($request->safe()->except(self::GALLERY_FIELDS));

        try {
            $updateImage->sync($product, $request->imageUploads());
        } catch (RuntimeException) {
            return redirect()->route('admin.products.edit', $product)
                ->withErrors(['images' => 'The product was saved, but its images could not be stored. Please upload them again.']);
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

        $product->load(['inventory', 'images']);

        return view('admin.products.edit', [
            'product' => $product,
            'categories' => ProductCategory::query()->orderBy('name')->get(),
        ]);
    }

    public function update(UpdateProductRequest $request, Product $product, SaveProduct $save, UpdateProductImage $updateImage, ProductImageFiles $files): RedirectResponse
    {
        $previousAccessType = $product->access_type;
        $save->update($product, $request->safe()->except(self::GALLERY_FIELDS));

        try {
            // Switching between PUBLIC and MEMBER_ONLY moves the image files to
            // the matching disk (members-only images are never public files).
            if ($product->access_type !== $previousAccessType) {
                $files->relocate($product);
            }

            $updateImage->sync(
                $product,
                $request->imageUploads(),
                array_map('intval', (array) $request->validated('remove_images', [])),
                (array) $request->validated('image_order', []),
                $request->validated('primary_image') !== null ? (int) $request->validated('primary_image') : null,
            );
        } catch (RuntimeException) {
            return back()->withInput()
                ->withErrors(['images' => 'The images could not be stored. The product details were saved and its existing images are unchanged.']);
        }

        return redirect()->route('admin.products.index')->with('status', 'Product updated.');
    }

    /**
     * `cart_items.product_id` is `RESTRICT` on delete — checked here first,
     * exactly like `ProductCategoryController::destroy()` already does for
     * `product_category_id`, so this is a clear admin-facing message rather
     * than a raw `QueryException` bubbling up from the database.
     */
    public function destroy(Product $product, ProductImageFiles $files): RedirectResponse
    {
        Gate::authorize('delete', $product);

        if ($product->cartItems()->exists()) {
            return back()->with('warning', 'This product is currently in a customer\'s cart and cannot be deleted.');
        }

        $disk = $files->diskFor($product);

        foreach ($product->images as $image) {
            // Never delete a file another product's image row still uses.
            $files->deleteIfUnused($image->image_path, $disk, $product->id);
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
