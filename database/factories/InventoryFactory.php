<?php

namespace Database\Factories;

use App\Models\Inventory;
use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Inventory>
 */
class InventoryFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'product_id' => Product::factory(),
            'quantity' => $this->faker->numberBetween(10, 100),
            'low_stock_threshold' => 5,
        ];
    }

    public function outOfStock(): static
    {
        return $this->state(fn (array $attributes) => ['quantity' => 0]);
    }

    public function lowStock(): static
    {
        return $this->state(fn (array $attributes) => ['quantity' => 2, 'low_stock_threshold' => 5]);
    }
}
