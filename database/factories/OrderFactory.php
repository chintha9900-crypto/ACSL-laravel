<?php

namespace Database\Factories;

use App\Models\Order;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Order>
 */
class OrderFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'order_number' => 'ORD-'.strtoupper(Str::random(8)),
            'customer_name' => $this->faker->name(),
            'customer_email' => $this->faker->unique()->safeEmail(),
            'customer_phone' => $this->faker->phoneNumber(),
            'status' => Order::STATUS_PENDING_PAYMENT,
            'currency' => 'LKR',
            'total_amount' => $this->faker->randomFloat(2, 10, 500),
        ];
    }

    public function guest(): static
    {
        return $this->state(fn (array $attributes) => ['user_id' => null]);
    }
}
