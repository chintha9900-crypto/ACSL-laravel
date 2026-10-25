<?php

namespace App\Http\Requests\Admin;

use App\Models\Product;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\File;
use Illuminate\Validation\Validator;

/**
 * Product gallery fields shared by StoreProductRequest and
 * UpdateProductRequest: several images per upload (content-checked as real
 * JPG/PNG images, size-limited), plus — when editing — removal, ordering
 * and the primary image, all limited to this product's own images. The
 * gallery never exceeds `uploads.product_image.max_files`.
 */
trait ValidatesProductImages
{
    /**
     * @return array<string, list<mixed>>
     */
    protected function imageRules(?Product $product): array
    {
        $image = config('uploads.product_image');
        $ownImage = Rule::exists('product_images', 'id')->where('product_id', $product?->id ?? 0);

        return [
            'images' => ['nullable', 'array', 'max:'.$image['max_files']],
            'images.*' => ['required', File::image()->types($image['extensions'])->max($image['max_kb'])],
            'remove_images' => ['nullable', 'array'],
            'remove_images.*' => ['integer', $ownImage],
            'image_order' => ['nullable', 'array'],
            'image_order.*' => ['integer', 'min:0', 'max:999'],
            'primary_image' => ['nullable', 'integer', $ownImage],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function imageMessages(): array
    {
        $image = config('uploads.product_image');
        $maxMb = round($image['max_kb'] / 1024, 1);

        return [
            'images.max' => 'A product can have at most '.$image['max_files'].' images, including the main image.',
            'images.*.image' => 'Each product image must be a JPG or PNG image.',
            'images.*.mimes' => 'Each product image must be a JPG or PNG image.',
            'images.*.max' => "Each product image must be {$maxMb} MB or smaller.",
            'primary_image.exists' => 'Choose the main image from this product\'s images.',
            'remove_images.*.exists' => 'Only this product\'s own images can be removed.',
        ];
    }

    /**
     * When images are added, the total after this request (kept + new) must
     * stay within the limit, the primary image included. A request that adds
     * nothing is never blocked, so a product holding more images than the
     * limit (from before it was lowered) can still be edited and trimmed —
     * existing images are never deleted automatically.
     */
    protected function validateImageCount(Validator $validator, ?Product $product): void
    {
        $max = (int) config('uploads.product_image.max_files');
        $adding = count(array_filter((array) $this->file('images', []), fn ($file) => $file instanceof UploadedFile));

        if ($adding === 0) {
            return;
        }

        $existing = $product?->images()->count() ?? 0;
        $removing = $product === null ? 0 : $product->images()
            ->whereKey(array_filter((array) $this->input('remove_images', []), 'is_numeric'))
            ->count();
        $total = $existing - $removing + $adding;

        if ($total > $max) {
            $validator->errors()->add('images', "A product can have at most {$max} images, including the main image. This would make {$total} — remove an image before adding another.");
        }
    }

    /**
     * @return list<UploadedFile>
     */
    public function imageUploads(): array
    {
        return array_values(array_filter((array) $this->file('images', []), fn ($file) => $file instanceof UploadedFile));
    }
}
