<?php

namespace Database\Factories;

use App\Models\Trooper;
use App\Models\Unit;
use App\Models\UnitCommandMapping;
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

    /**
     * Link the identity to a serving trooper: a fresh cached `combat` trooper whose `cav_id` the
     * user carries. That link is what lets a non-admin sign in at all and what keeps their staff
     * grants counting in scope, so any test that hands a non-admin a grant needs it.
     */
    public function serving(): static
    {
        return $this->state(fn (array $attributes) => [
            'cav_id' => Trooper::factory()->create()->cav_id,
        ]);
    }

    /**
     * Link the identity to a serving trooper whose synced billet commands `$unit` (the roster-derived
     * scope, AD-7): a fresh command mapping for the unit and a trooper holding that billet, with any
     * `$trooper` attributes. Unlike a staff grant, which only reads, this is write authority over the
     * unit's subtree.
     *
     * @param  array<string, mixed>  $trooper
     */
    public function commanding(Unit $unit, array $trooper = []): static
    {
        return $this->state(fn (array $attributes) => [
            'cav_id' => Trooper::factory()->create([
                ...$trooper,
                'cav_position_id' => UnitCommandMapping::factory()->create(['unit_id' => $unit->id])->cav_position_id,
            ])->cav_id,
        ]);
    }
}
