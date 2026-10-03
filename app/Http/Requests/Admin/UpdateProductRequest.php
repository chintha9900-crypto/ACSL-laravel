<?php

namespace App\Http\Requests\Admin;

use App\Models\Product;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\File;

class UpdateProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('product')) ?? false;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        $image = config('uploads.product_image');

        return [
            'product_category_id' => ['required', Rule::exists('product_categories', 'id')],
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['required', 'string', 'max:255', 'alpha_dash', Rule::unique('products', 'slug')->ignore($this->route('product'))],
            'sku' => ['required', 'string', 'max:64', Rule::unique('products', 'sku')->ignore($this->route('product'))],
            'description' => ['nullable', 'string'],
            'price' => ['required', 'numeric', 'min:0'],
            'access_type' => ['required', Rule::in(Product::ACCESS_TYPES)],
            'quantity' => ['required', 'integer', 'min:0'],
            'image' => ['nullable', File::types($image['extensions'])->max($image['max_kb'])],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'slug.unique' => 'This slug is already used by another product. Please choose a different one.',
            'slug.alpha_dash' => 'The slug may only contain letters, numbers, dashes and underscores.',
            'sku.unique' => 'This SKU is already used by another product. Please choose a different one.',
        ];
    }
}
