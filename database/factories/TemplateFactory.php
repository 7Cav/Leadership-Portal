<?php

namespace Database\Factories;

use App\Models\ArtifactType;
use App\Models\Template;
use App\Models\Unit;
use App\Models\UnitType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Template>
 */
class TemplateFactory extends Factory
{
    /**
     * A company template with no versions; the repository writes those.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => Unit::factory()->state(['type' => UnitType::Company]),
            'artifact_type' => ArtifactType::RollcallBbcode,
            'name' => 'Company template',
            'enabled' => true,
            // No author, as for the seeded default, so a test making many templates does not make a user for each.
            'created_by' => null,
        ];
    }
}
