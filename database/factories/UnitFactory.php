<?php

namespace Database\Factories;

use App\Models\Unit;
use App\Models\UnitKind;
use App\Models\UnitType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Unit>
 */
class UnitFactory extends Factory
{
    /**
     * Defaults to a rootless section — `path` is set here rather than left for the repository,
     * so a factory-made unit is queryable on its own in a test that isn't exercising
     * `UnitRepository::create()` itself. Tests that verify path computation build through the
     * repository instead.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'parent_id' => null,
            'type' => UnitType::Section,
            'kind' => UnitKind::Line,
            'name' => fake()->unique()->words(2, true),
            'callsign' => fake()->unique()->bothify('?/?/?'),
            'path' => fake()->unique()->numerify('###/'),
        ];
    }

    /**
     * A group off the line (2026-10-24): a staff department, ELOA, Reservists or New Recruits.
     */
    public function group(UnitKind $kind = UnitKind::Staff): static
    {
        return $this->state(['type' => UnitType::Group, 'kind' => $kind]);
    }
}
