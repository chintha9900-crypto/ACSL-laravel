<?php

namespace Database\Factories;

use App\Models\MembershipCategory;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MembershipCategory>
 */
class MembershipCategoryFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'code' => MembershipCategory::CODE_PROFESSIONAL,
            'name' => 'Professional',
            'description' => null,
            'is_active' => true,
            'display_order' => 0,
        ];
    }

    public function student(): static
    {
        return $this->state(fn (array $attributes) => [
            'code' => MembershipCategory::CODE_STUDENT,
            'name' => 'Student',
            'display_order' => 1,
        ]);
    }

    public function professional(): static
    {
        return $this->state(fn (array $attributes) => [
            'code' => MembershipCategory::CODE_PROFESSIONAL,
            'name' => 'Professional',
            'display_order' => 2,
        ]);
    }

    public function veteran(): static
    {
        return $this->state(fn (array $attributes) => [
            'code' => MembershipCategory::CODE_VETERAN,
            'name' => 'Veteran',
            'display_order' => 3,
        ]);
    }

    /**
     * Indicate that the category no longer accepts new applications.
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }
}
