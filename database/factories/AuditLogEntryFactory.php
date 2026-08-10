<?php

namespace Database\Factories;

use App\Models\AuditLogEntry;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AuditLogEntry>
 */
class AuditLogEntryFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'actor_id' => User::factory(),
            'action' => 'unit.created',
            'target_type' => 'Unit',
            'target_id' => (string) fake()->unique()->numberBetween(1, 999999),
            'reason' => null,
            'content_digest' => hash('sha256', fake()->unique()->uuid()),
            'created_at' => now(),
        ];
    }
}
