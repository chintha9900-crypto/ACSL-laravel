<?php

namespace App\Actions\Catalogue;

use App\Models\Product;
use App\Models\ProductImage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * A product's image gallery on the existing `product_images` rows: add new
 * uploads, remove selected images, reorder, and choose the primary image
 * (the one with the lowest `display_order`, shown in the catalogue).
 *
 * New files are written first, under generated names on the public disk;
 * the rows then change in one transaction. If anything fails, the new files
 * are deleted and the gallery is left exactly as it was. A removed image's
 * file is deleted only after commit, and only if no other row still
 * references the same path.
 *
 * Files go on the disk the product's access type requires
 * (ProductImageFiles): public for PUBLIC products, private — reachable only
 * through the membership-checked image route — for MEMBER_ONLY ones.
 */
class UpdateProductImage
{
    public function __construct(private readonly ProductImageFiles $files) {}

    /**
     * Extensions a stored image may have, chosen from its detected content —
     * never from the client's filename or reported MIME type.
     *
     * @var list<string>
     */
    private const STORED_EXTENSIONS = ['jpg', 'png'];

    /**
     * @param  list<UploadedFile>  $uploads  New images, added after the existing ones.
     * @param  list<int>  $removeIds  Existing image ids (of this product) to remove.
     * @param  array<int|string, int|string>  $order  Existing image id => position.
     * @param  int|null  $primaryId  Existing image id to show first.
     */
    public function sync(Product $product, array $uploads = [], array $removeIds = [], array $order = [], ?int $primaryId = null): void
    {
        $storedPaths = [];
        $disk = $this->files->diskFor($product);

        try {
            foreach ($uploads as $upload) {
                $storedPaths[] = $this->store($upload, $disk);
            }

            $removedPaths = DB::transaction(function () use ($product, $storedPaths, $removeIds, $order, $primaryId): array {
                // Eloquent collections match only()/except() by model key.
                $existing = $product->images()->lockForUpdate()->get();
                $removed = $existing->only(array_map('intval', $removeIds));
                $kept = $existing->except($removed->modelKeys());

                ProductImage::query()->whereKey($removed->modelKeys())->delete();

                // Existing images in their requested order (unspecified ones keep
                // their current relative place), then the new uploads in the
                // order they were chosen; the primary, if chosen, goes first.
                $ordered = $kept->sortBy(fn (ProductImage $image): array => [
                    (int) ($order[$image->id] ?? $image->display_order),
                    $image->display_order,
                    $image->id,
                ])->values();

                foreach ($storedPaths as $path) {
                    $ordered->push($product->images()->create(['image_path' => $path, 'display_order' => 0]));
                }

                if ($primaryId !== null && ($primary = $ordered->firstWhere('id', $primaryId)) !== null) {
                    $ordered = $ordered->reject(fn (ProductImage $image): bool => $image->id === $primaryId)->prepend($primary)->values();
                }

                foreach ($ordered as $position => $image) {
                    if ($image->display_order !== $position) {
                        $image->forceFill(['display_order' => $position])->save();
                    }
                }

                return $removed->pluck('image_path')->all();
            });
        } catch (Throwable $exception) {
            foreach ($storedPaths as $path) {
                Storage::disk($disk)->delete($path);
            }

            throw $exception;
        }

        foreach ($removedPaths as $path) {
            $this->files->deleteIfUnused($path, $disk);
        }
    }

    private function store(UploadedFile $file, string $disk): string
    {
        $extension = $file->guessExtension();
        $extension = $extension === 'jpeg' ? 'jpg' : $extension;

        if (! in_array($extension, self::STORED_EXTENSIONS, true)) {
            throw new RuntimeException('Unsupported product image type.');
        }

        $path = 'products/'.Str::lower((string) Str::ulid()).'.'.$extension;

        $stream = fopen($file->getRealPath(), 'rb');

        try {
            $written = Storage::disk($disk)->put($path, $stream, $disk === ProductImageFiles::PUBLIC_DISK ? 'public' : 'private');
        } finally {
            fclose($stream);
        }

        if ($written === false) {
            throw new RuntimeException('The product image could not be stored.');
        }

        return $path;
    }
}
