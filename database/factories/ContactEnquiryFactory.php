<?php

namespace Database\Factories;

use App\Models\ContactEnquiry;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ContactEnquiry>
 */
class ContactEnquiryFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => $this->faker->name(),
            'email' => $this->faker->safeEmail(),
            'phone' => $this->faker->optional()->numerify('+94 7# ### ####'),
            'subject' => $this->faker->sentence(4),
            'message' => $this->faker->paragraph(),
            'status' => ContactEnquiry::STATUS_NEW,
        ];
    }
}
