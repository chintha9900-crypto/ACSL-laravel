<?php

namespace App\Console\Commands;

use App\Actions\Catalogue\ProductImageFiles;
use App\Models\Product;
use Illuminate\Console\Command;

/**
 * Puts every product's image files on the disk its access type requires:
 * members-only images off the public disk (onto the private one), public
 * images onto the public disk. Needed once for images stored before
 * members-only images were protected; safe to run again at any time — it
 * copies before removing, never deletes a file another product still needs
 * on that disk, and skips files that are missing everywhere.
 */
class SecureProductImages extends Command
{
    protected $signature = 'products:secure-images';

    protected $description = 'Move members-only product images to private storage (and public ones to public storage)';

    public function handle(ProductImageFiles $files): int
    {
        $moved = 0;

        Product::query()->orderBy('id')->each(function (Product $product) use ($files, &$moved): void {
            $moved += $files->relocate($product);
        });

        $this->info("Moved {$moved} product image file(s) to the correct storage.");

        return self::SUCCESS;
    }
}
