<?php

namespace Database\Seeders;

use App\Models\User;
use Database\Seeders\Concerns\RefusesOutsideDevEnvironments;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

/**
 * The default `db:seed` target. It only makes a throwaway user, so it refuses to run outside
 * local, testing and e2e: a bare `db:seed --force` against production must not add an account.
 */
class DatabaseSeeder extends Seeder
{
    use RefusesOutsideDevEnvironments;
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->refuseOutsideDevEnvironments();

        // User::factory(10)->create();

        User::factory()->create([
            'name' => 'Test User',
            'email' => 'test@example.com',
        ]);
    }
}
