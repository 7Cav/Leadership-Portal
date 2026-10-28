<?php

namespace Database\Factories;

use App\Models\PageVisit;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PageVisit>
 */
class PageVisitFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'url' => '/home',
            'acting_as' => null,
            'viewing_as' => null,
            'visited_at' => now(),
        ];
    }
}
