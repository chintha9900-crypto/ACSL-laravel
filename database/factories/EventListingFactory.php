<?php

namespace Database\Factories;

use App\Models\EventListing;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<EventListing>
 */
class EventListingFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $title = ucfirst($this->faker->unique()->sentence(4));

        return [
            'created_by_user_id' => null,
            'title' => $title,
            'slug' => Str::slug($title).'-'.$this->faker->unique()->numberBetween(1, 999999),
            'excerpt' => $this->faker->sentence(12),
            'content' => $this->faker->paragraph(),
            'image_path' => null,
            'starts_at' => now()->addWeek(),
            'location' => $this->faker->city(),
            'status' => EventListing::STATUS_DRAFT,
            'published_at' => null,
        ];
    }

    public function published(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => EventListing::STATUS_PUBLISHED,
            'published_at' => now()->subDay(),
        ]);
    }
}
