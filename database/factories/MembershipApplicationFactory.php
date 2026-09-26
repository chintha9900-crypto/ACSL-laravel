<?php

namespace Database\Factories;

use App\Models\MembershipApplication;
use App\Models\MembershipCategory;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MembershipApplication>
 */
class MembershipApplicationFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'membership_category_id' => MembershipCategory::factory(),
            'full_name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'mobile' => '+94 77 123 4567',
            'address' => fake()->address(),
            'aviation_role' => 'First Officer',
            'aviation_organisation' => 'Example Airlines',
            'submitted_at' => now(),
        ];
    }

    /**
     * Indicate that an admin has asked the applicant for more details.
     */
    public function moreDetailsRequired(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => MembershipApplication::STATUS_MORE_DETAILS_REQUIRED,
        ]);
    }

    /**
     * Indicate that the application was approved (a decision by an admin).
     */
    public function approved(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => MembershipApplication::STATUS_APPROVED,
            'proof_reviewed_at' => now(),
            'decided_at' => now(),
            'decided_by_user_id' => User::factory()->active(),
        ]);
    }

    /**
     * Indicate that the application was rejected (a decision by an admin).
     */
    public function rejected(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => MembershipApplication::STATUS_REJECTED,
            'proof_reviewed_at' => now(),
            'decided_at' => now(),
            'decided_by_user_id' => User::factory()->active(),
        ]);
    }
}
