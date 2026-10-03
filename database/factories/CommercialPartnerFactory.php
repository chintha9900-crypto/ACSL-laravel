<?php

namespace Database\Factories;

use App\Models\CommercialPartner;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CommercialPartner>
 */
class CommercialPartnerFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => $this->faker->unique()->company(),
            'logo_path' => null,
            'description' => $this->faker->sentence(),
            'url' => $this->faker->url(),
            'display_order' => 0,
            'is_active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => ['is_active' => false]);
    }
}
