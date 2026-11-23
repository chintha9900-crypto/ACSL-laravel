<?php

namespace Database\Factories;

use App\Models\JobListing;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<JobListing>
 */
class JobListingFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $title = ucfirst($this->faker->unique()->words(3, true));

        return [
            'created_by_user_id' => null,
            'title' => $title,
            'slug' => Str::slug($title).'-'.$this->faker->unique()->numberBetween(1, 999999),
            'company_name' => $this->faker->company(),
            'company_logo' => null,
            'category' => $this->faker->randomElement(array_keys(JobListing::CATEGORIES)),
            'employment_type' => $this->faker->randomElement(array_keys(JobListing::EMPLOYMENT_TYPES)),
            'location_type' => JobListing::LOCATION_LOCAL,
            'location' => $this->faker->city(),
            'country' => $this->faker->country(),
            'short_description' => $this->faker->sentence(),
            'description' => '<p>'.$this->faker->paragraph().'</p>',
            'responsibilities' => null,
            'qualifications' => null,
            'experience' => null,
            'additional_requirements' => null,
            'salary' => null,
            'date_posted' => null,
            'application_deadline' => CarbonImmutable::now(JobListing::DEADLINE_TIMEZONE)->addDays(30)->toDateString(),
            'application_url' => 'https://example.com/careers/'.$this->faker->unique()->numberBetween(1, 999999),
            'status' => JobListing::STATUS_DRAFT,
            'published_at' => null,
        ];
    }

    public function published(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => JobListing::STATUS_PUBLISHED,
            'published_at' => now()->subDay(),
            'date_posted' => CarbonImmutable::now(JobListing::DEADLINE_TIMEZONE)->subDay()->toDateString(),
        ]);
    }

    public function expired(): static
    {
        return $this->published()->state(fn (array $attributes) => [
            'status' => JobListing::STATUS_EXPIRED,
            'application_deadline' => CarbonImmutable::now(JobListing::DEADLINE_TIMEZONE)->subDays(5)->toDateString(),
        ]);
    }

    public function overseas(): static
    {
        return $this->state(fn (array $attributes) => ['location_type' => JobListing::LOCATION_OVERSEAS]);
    }

    /**
     * @param  string  $date  `Y-m-d`, a calendar date in Asia/Colombo.
     */
    public function deadline(string $date): static
    {
        return $this->state(fn (array $attributes) => ['application_deadline' => $date]);
    }
}
