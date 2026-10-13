<?php

namespace Database\Factories;

use App\Models\ArtifactType;
use App\Models\TemplateVersion;
use App\Models\Unit;
use App\Models\UnitType;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TemplateVersion>
 */
class TemplateVersionFactory extends Factory
{
    /**
     * Makes a version row only; it does not move the active pointer, which is the repository's job.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => Unit::factory()->state(['type' => UnitType::Company]),
            'artifact_type' => ArtifactType::RollcallBbcode,
            'version' => 1,
            'body' => '[CENTER][B]{{unit}} ROLL CALL | {{week}}[/B][/CENTER]',
            'created_by' => User::factory(),
        ];
    }
}
