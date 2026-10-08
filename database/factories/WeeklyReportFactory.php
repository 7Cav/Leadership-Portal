<?php

namespace Database\Factories;

use App\Models\Unit;
use App\Models\User;
use App\Models\WeeklyReport;
use App\Models\WeeklyReportStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WeeklyReport>
 */
class WeeklyReportFactory extends Factory
{
    /**
     * Defaults to a draft.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'unit_id' => Unit::factory(),
            // 2026-01-03 is a Saturday; whole-week offsets keep every generated week_start on the
            // Saturday 00:00 UTC boundary (AD-11).
            'week_start' => CarbonImmutable::parse('2026-01-03 00:00:00', 'UTC')
                ->addWeeks(fake()->unique()->numberBetween(0, 520)),
            'status' => WeeklyReportStatus::Draft,
            'kpis' => ['strength' => ['value' => fake()->numberBetween(4, 16), 'delta' => 0]],
        ];
    }

    /** A sent report: send metadata set, snapshot frozen. */
    public function sent(): static
    {
        return $this->state(fn () => [
            'status' => WeeklyReportStatus::Sent,
            'sent_at' => CarbonImmutable::now('UTC'),
            'sent_by' => User::factory(),
            'content_digest' => hash('sha256', fake()->uuid()),
        ]);
    }
}
