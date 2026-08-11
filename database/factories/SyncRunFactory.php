<?php

namespace Database\Factories;

use App\Models\SyncRun;
use App\Models\SyncRunStatus;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SyncRun>
 */
class SyncRunFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'source' => 'roster',
            'started_at' => now(),
            'finished_at' => now(),
            'status' => SyncRunStatus::Success,
            'inserted' => 0,
            'updated' => 0,
            'inactivated' => 0,
            'errored' => 0,
            'error' => null,
            'triggered_by' => 'system',
        ];
    }
}
