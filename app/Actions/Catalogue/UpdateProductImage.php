<?php

namespace App\Actions\Catalogue;

use App\Models\Product;
use App\Models\ProductImage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * One image slot per product for this step (the task asks for "Product
 * image", singular) — uploading a new one replaces whichever image
 * currently has the lowest `display_order`, the same "store new, then
 * delete old" sequencing `UpdateBlogFeaturedImage` uses for its single
 * `featured_image_path` column, just applied across `product_images` rows
 * instead of overwriting one column.
 */
class UpdateProductImage
{
    /**
     * Public disk — product images are public catalogue content.
     */
    private const DISK = 'public';

    /**
     * Extensions a stored image may have, chosen from its detected content —
     * never from the client's filename or reported MIME type.
     *
     * @var list<string>
     */
    private const STORED_EXTENSIONS = ['jpg', 'png'];

    public function handle(Product $product, UploadedFile $file): ProductImage
    {
        $extension = $file->guessExtension();
        $extension = $extension === 'jpeg' ? 'jpg' : $extension;

        if (! in_array($extension, self::STORED_EXTENSIONS, true)) {
            throw new RuntimeException('Unsupported product image type.');
        }

        $path = 'products/'.Str::lower((string) Str::ulid()).'.'.$extension;

        $stream = fopen($file->getRealPath(), 'rb');

        try {
            $written = Storage::disk(self::DISK)->put($path, $stream, 'public');
        } finally {
            fclose($stream);
        }

        if ($written === false) {
            throw new RuntimeException('The product image could not be stored.');
        }

        $previous = $product->images()->orderBy('display_order')->first();

        $image = $product->images()->create([
            'image_path' => $path,
            'display_order' => 0,
        ]);

        if ($previous !== null) {
            Storage::disk(self::DISK)->delete($previous->image_path);
            $previous->delete();
        }

        return $image;
    }
}
