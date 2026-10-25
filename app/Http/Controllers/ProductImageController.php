<?php

namespace App\Http\Controllers;

use App\Actions\Catalogue\ProductImageFiles;
use App\Models\Product;
use App\Models\ProductImage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Delivers members-only product images, which are stored on the private disk
 * (no public link, no other route). Every request is authorised server-side:
 *
 * - an eligible member (Product::isAccessibleTo(), i.e. a currently valid
 *   membership) for an active product, or
 * - staff who may manage the product (ProductPolicy `update` — commerce
 *   managers and admins), so admin pages can preview its images.
 *
 * Anyone else gets a 404, identical to a missing image, so the response never
 * confirms that a protected image exists. Responses are marked private so
 * shared caches never store them. A public product's image is simply
 * redirected to its ordinary public file.
 */
class ProductImageController extends Controller
{
    public function show(Request $request, ProductImage $productImage, ProductImageFiles $files): StreamedResponse|RedirectResponse
    {
        /** @var Product|null $product */
        $product = $productImage->product;

        abort_if($product === null, 404);

        if ($product->access_type !== Product::ACCESS_MEMBER_ONLY) {
            return redirect()->away($product->imageUrl($productImage));
        }

        $user = $request->user();
        $allowed = ($product->is_active && $product->isAccessibleTo($user))
            || ($user !== null && Gate::forUser($user)->allows('update', $product));

        abort_unless($allowed, 404);

        // The private disk also holds payment evidence and other documents:
        // only ever serve the generated `products/<name>.<ext>` image paths.
        abort_unless(preg_match('#^products/[a-z0-9]+\.(jpg|png)$#D', $productImage->image_path) === 1, 404);

        $disk = Storage::disk($files->diskFor($product));

        abort_unless($disk->exists($productImage->image_path), 404);

        return $disk->response($productImage->image_path, null, [
            'Cache-Control' => 'private, max-age=300',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Disposition' => 'inline',
        ]);
    }
}
