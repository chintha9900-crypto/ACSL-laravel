<?php

namespace Database\Factories;

use App\Models\EducationArticle;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<EducationArticle>
 */
class EducationArticleFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $title = ucfirst($this->faker->unique()->words(3, true));

        return [
            'title' => $title,
            'slug' => Str::slug($title).'-'.$this->faker->unique()->numberBetween(1, 999999),
            'short_description' => $this->faker->sentence(),
            'content' => '<p>'.$this->faker->paragraph().'</p>',
            'featured_image_path' => null,
            'featured_image_alt' => null,
            'display_column' => EducationArticle::COLUMN_LEFT,
            'sort_order' => 100,
            'status' => EducationArticle::STATUS_DRAFT,
            'published_at' => null,
            'created_by_user_id' => null,
            'updated_by_user_id' => null,
        ];
    }

    public function published(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => EducationArticle::STATUS_PUBLISHED,
            'published_at' => now()->subDay(),
        ]);
    }

    public function right(): static
    {
        return $this->state(fn (array $attributes) => ['display_column' => EducationArticle::COLUMN_RIGHT]);
    }
}
