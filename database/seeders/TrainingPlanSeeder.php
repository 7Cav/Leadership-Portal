<?php

namespace Database\Seeders;

use App\Models\Trooper;
use App\Models\TrooperStatus;
use App\Models\Unit;
use App\Models\UnitCommandMapping;
use App\Models\UnitType;
use App\Models\User;
use App\Repositories\UnitRepository;
use Carbon\CarbonImmutable;
use Database\Seeders\Concerns\RefusesOutsideDevEnvironments;
use Illuminate\Database\Seeder;

/**
 * A platoon and its company for the training plan browser test (#380), under their own company and usernames.
 * `Navarro.D` leads 1st Platoon of Foxtrot Company (1/F/1-7) and writes its plan; `Kerr.A` commands Foxtrot Company and
 * reviews it. Refuses to run outside local, testing and e2e: its users lead units by command billet.
 */
class TrainingPlanSeeder extends Seeder
{
    use RefusesOutsideDevEnvironments;

    public function run(): void
    {
        $this->refuseOutsideDevEnvironments();

        $tag = CarbonImmutable::now('UTC')->format('His').random_int(100, 999);

        // Made through the repository, so each path holds the real ids above the unit: the review reads the platoon's
        // company from it (`UnitRepository::owningCompany()`).
        $units = app(UnitRepository::class);
        $company = $units->create(UnitType::Company, 'Foxtrot Company', 'F/1-7', null);
        $platoon = $units->create(UnitType::Platoon, '1st Platoon', '1/F/1-7', $company->id);
        $first = $units->create(UnitType::Section, '1st Section', '1/1/F/1-7', $platoon->id);
        $second = $units->create(UnitType::Section, '2nd Section', '2/1/F/1-7', $platoon->id);

        $this->leader('SFC', 'Navarro.D', (int) "{$tag}0", (int) "{$tag}91", $platoon, $first);
        $this->leader('CPT', 'Kerr.A', (int) "{$tag}1", (int) "{$tag}92", $company, $first);
        $this->trooper('PFC', 'Lund.E', (int) "{$tag}2", $second);
    }

    /** A serving trooper whose billet commands `$unit`, and the portal user who signs in as them. */
    private function leader(string $rank, string $username, int $cavId, int $positionId, Unit $unit, Unit $section): void
    {
        UnitCommandMapping::factory()->create(['unit_id' => $unit->id, 'cav_position_id' => $positionId]);
        $trooper = $this->trooper($rank, $username, $cavId, $section);
        $trooper->forceFill(['cav_position_id' => $positionId])->save();
        User::factory()->create(['username' => $username, 'cav_id' => $trooper->cav_id]);
    }

    private function trooper(string $rank, string $username, int $cavId, Unit $section): Trooper
    {
        return Trooper::factory()->create([
            'cav_id' => $cavId, 'rank_short' => $rank, 'username' => $username, 'status' => TrooperStatus::Combat, 'primary_section_id' => $section->id,
        ]);
    }
}
