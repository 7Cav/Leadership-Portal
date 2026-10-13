<?php

namespace Database\Factories;

use App\Models\Loa;
use App\Models\LoaParseStatus;
use App\Models\LoaType;
use App\Models\Trooper;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Loa>
 */
class LoaFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $start = CarbonImmutable::parse('2026-10-01')->addDays(fake()->numberBetween(0, 120));
        $threadId = fake()->unique()->numberBetween(1, 900000);

        return [
            'trooper_id' => Trooper::factory(),
            'forum_thread_id' => $threadId,
            'forum_node_id' => 180,
            'type' => LoaType::Loa,
            'starts_at' => $start,
            'ends_at' => $start->addDays(fake()->numberBetween(3, 30)),
            'parse_status' => LoaParseStatus::Ok,
            'source_thread' => "https://7cav.us/threads/loa-request.{$threadId}/",
            'raw_username' => null,
            'raw_start_date' => null,
            'raw_end_date' => null,
            'raw_excerpt' => null,
        ];
    }

    /** A thread whose dates could not be read: no dates asserted. */
    public function needsReview(): static
    {
        return $this->state(fn () => [
            'starts_at' => null,
            'ends_at' => null,
            'parse_status' => LoaParseStatus::NeedsReview,
        ]);
    }

    /** An open-ended thread: a start and no end. */
    public function openEnded(): static
    {
        return $this->state(fn () => ['ends_at' => null]);
    }
}
