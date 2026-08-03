<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * A password-less SSO identity row (AD-6) — keyed on a forum user id, never a
     * password. `cav_id` is left null (the un-enriched state before Story 1.6).
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'forum_user_id' => fake()->unique()->numberBetween(1, 999999),
            'cav_id' => null,
            'username' => fake()->userName(),
            'email' => fake()->unique()->safeEmail(),
            'is_admin' => false,
        ];
    }

    /**
     * Indicate that the identity has portal admin rights (AD-7).
     */
    public function admin(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_admin' => true,
        ]);
    }
}
