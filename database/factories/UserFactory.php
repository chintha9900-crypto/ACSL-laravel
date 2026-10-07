<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
            // Explicit, not left to the column's own default: the database
            // default is only ever applied to the row, never backfilled onto
            // this in-memory model after `create()`, so a test that reads
            // `$user->role` immediately afterwards (e.g. RBAC route tests)
            // would otherwise see `null` rather than `member`.
            'role' => 'member',
        ];
    }

    /**
     * Indicate that the account is active and able to sign in.
     */
    public function active(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'active',
        ]);
    }

    /**
     * Indicate that the account was provisioned but the member has not yet set a password.
     */
    public function pendingSetup(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'pending_setup',
            'password' => null,
            'email_verified_at' => null,
            'remember_token' => null,
        ]);
    }

    /**
     * Indicate that the account is suspended.
     */
    public function suspended(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'suspended',
        ]);
    }

    /**
     * RBAC foundation — the admin role.
     */
    public function admin(): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => 'admin',
        ]);
    }

    /**
     * RBAC foundation — the editor role.
     */
    public function editor(): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => 'editor',
        ]);
    }

    /**
     * RBAC foundation — the dev role.
     */
    public function dev(): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => 'dev',
        ]);
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }
}
