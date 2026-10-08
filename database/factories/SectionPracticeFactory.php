<?php

namespace Database\Factories;

use App\Models\SectionPractice;
use App\Models\Unit;
use App\Models\UnitType;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SectionPractice>
 */
class SectionPracticeFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'section_id' => Unit::factory()->state(['type' => UnitType::Section]),
            // 2026-01-03 is a Saturday; whole-week offsets keep every generated week_start on the
            // Saturday 00:00 UTC boundary (AD-11).
            'week_start' => CarbonImmutable::parse('2026-01-03 00:00:00', 'UTC')
                ->addWeeks(fake()->unique()->numberBetween(0, 520)),
        ];
    }
}
