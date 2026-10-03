<?php

namespace App\Actions\Catalogue;

use App\Models\Product;

class SaveProduct
{
    /**
     * Created inactive, the same workflow `SaveBlogPost::create()` uses for
     * a new post ("created as a draft") — going live is the separate
     * activate action, never something this form can set directly.
     *
     * @param  array<string, mixed>  $data  Validated: product_category_id, name, slug, sku, description, price, access_type, quantity.
     */
    public function create(array $data): Product
    {
        $quantity = (int) ($data['quantity'] ?? 0);
        unset($data['quantity']);

        $data['is_active'] = false;

        $product = Product::create($data);
        $product->inventory()->create(['quantity' => $quantity]);

        return $product;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Product $product, array $data): Product
    {
        $quantity = $data['quantity'] ?? null;
        unset($data['quantity']);

        $product->fill($data)->save();

        if ($quantity !== null) {
            $inventory = $product->inventory;

            if ($inventory !== null) {
                $inventory->update(['quantity' => (int) $quantity]);
            } else {
                $product->inventory()->create(['quantity' => (int) $quantity]);
            }
        }

        return $product;
    }
}
