<?php

namespace App\Actions\Catalogue;

use App\Models\Product;
use App\Models\ProductImage;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Where a product's image files live, and keeping them there.
 *
 * - PUBLIC products: the `public` disk (served as plain /storage/... files).
 * - MEMBER_ONLY products: the `private` disk, which has no public link and no
 *   route of its own; files are delivered only by ProductImageController after
 *   a membership check.
 *
 * The disk is derived from `products.access_type`, so no schema change is
 * needed; `relocate()` keeps the files in line when the access type changes
 * (and, run for every product, moves any files stored before this rule).
 *
 * A file path can be shared by several product_images rows. A file is only
 * removed from a disk when no row whose product maps to that disk still uses
 * the path; when products on both disks share it, it is copied, not moved.
 */
class ProductImageFiles
{
    public const PUBLIC_DISK = 'public';

    public const PRIVATE_DISK = 'private';

    public function diskFor(Product $product): string
    {
        return $product->access_type === Product::ACCESS_MEMBER_ONLY ? self::PRIVATE_DISK : self::PUBLIC_DISK;
    }

    /**
     * Whether any image row (other than those of $exceptProductId) still needs
     * `$path` on `$disk`.
     */
    public function stillNeeded(string $path, string $disk, ?int $exceptProductId = null): bool
    {
        return ProductImage::query()
            ->where('image_path', $path)
            ->when($exceptProductId !== null, fn ($query) => $query->where('product_id', '!=', $exceptProductId))
            ->whereHas('product', fn ($query) => $disk === self::PRIVATE_DISK
                ? $query->where('access_type', Product::ACCESS_MEMBER_ONLY)
                : $query->where('access_type', '!=', Product::ACCESS_MEMBER_ONLY))
            ->exists();
    }

    /**
     * Deletes `$path` from `$disk` unless another product still needs it there.
     */
    public function deleteIfUnused(string $path, string $disk, ?int $exceptProductId = null): void
    {
        if (! $this->stillNeeded($path, $disk, $exceptProductId)) {
            Storage::disk($disk)->delete($path);
        }
    }

    /**
     * Puts every image file of `$product` on the disk its access type
     * requires. Copy first, then remove the old copy only when nothing else
     * needs it — so a failure part-way never loses a file. Idempotent.
     *
     * @return int Number of files copied to the correct disk.
     */
    public function relocate(Product $product): int
    {
        $target = $this->diskFor($product);
        $other = $target === self::PRIVATE_DISK ? self::PUBLIC_DISK : self::PRIVATE_DISK;
        $copied = 0;

        foreach ($product->images()->get() as $image) {
            $path = $image->image_path;

            if (! Storage::disk($target)->exists($path)) {
                if (! Storage::disk($other)->exists($path)) {
                    continue; // Missing everywhere: the page shows its fallback.
                }

                $stream = Storage::disk($other)->readStream($path);

                try {
                    if (Storage::disk($target)->writeStream($path, $stream, ['visibility' => $target === self::PUBLIC_DISK ? 'public' : 'private']) === false) {
                        throw new RuntimeException('A product image could not be moved.');
                    }
                } finally {
                    if (is_resource($stream)) {
                        fclose($stream);
                    }
                }

                $copied++;
            }

            if (Storage::disk($other)->exists($path)) {
                $this->deleteIfUnused($path, $other);
            }
        }

        return $copied;
    }
}
