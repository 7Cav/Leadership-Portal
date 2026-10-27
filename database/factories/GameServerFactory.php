<?php

namespace Database\Factories;

use App\Models\GameServer;
use App\Models\GameServerType;
use App\Models\TelemetryInterface;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * A fetched BattleMetrics server, not yet selected or assigned to a game.
 *
 * @extends Factory<GameServer>
 */
class GameServerFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'interface' => TelemetryInterface::BattleMetrics,
            'external_id' => (string) fake()->unique()->numberBetween(1000, 9999999),
            'name' => ucwords(fake()->words(3, true)),
            'game_id' => null,
            'type' => GameServerType::Public,
            'selected' => false,
            'fallback_server_id' => null,
            'missing_since' => null,
        ];
    }

    /** Selected, assigned to the game and marked training: read for its attendance. */
    public function trainingFor(int $gameId): static
    {
        return $this->state(['game_id' => $gameId, 'type' => GameServerType::Training, 'selected' => true]);
    }
}
