<?php

namespace Database\Seeders;

use App\Models\Trooper;
use App\Models\TrooperStatus;
use App\Models\Unit;
use App\Models\UnitCommandMapping;
use App\Models\UnitKind;
use App\Models\UnitType;
use Carbon\CarbonImmutable;
use Database\Seeders\Concerns\RefusesOutsideDevEnvironments;
use Illuminate\Database\Seeder;

/**
 * Staff, ELOA and New Recruit placement on the org page (decided 2026-10-24), for its browser test, under its own
 * path prefix (5) and usernames. A DEVCOM platoon labelled `WARDOGS` whose lead billet nobody holds (Lead: vacant),
 * with its Platoon Sergeant placed on the platoon itself and a trooper in its section; an ELOA group holding
 * `Marlowe.E`, who was in that section; a New Recruits group holding `Quill.R`, hidden until asked for.
 */
class OrgPlacementSeeder extends Seeder
{
    use RefusesOutsideDevEnvironments;

    public function run(): void
    {
        $this->refuseOutsideDevEnvironments();

        $tag = CarbonImmutable::now('UTC')->format('His').random_int(100, 999);

        $platoon = Unit::factory()->create([
            'type' => UnitType::Platoon, 'name' => 'DEVCOM Platoon', 'callsign' => '9/D/DEVCOM', 'label' => 'WARDOGS', 'path' => "5{$tag}/",
        ]);
        $section = Unit::factory()->create([
            'type' => UnitType::Section, 'name' => '1st Section', 'callsign' => '1/9/D/DEVCOM', 'parent_id' => $platoon->id, 'path' => "5{$tag}/1/",
        ]);
        // The Platoon Leader billet commands the platoon; nobody serving holds it.
        UnitCommandMapping::factory()->create(['unit_id' => $platoon->id, 'cav_position_id' => (int) "{$tag}91"]);

        $eloa = Unit::factory()->group(UnitKind::Eloa)->create(['name' => 'Extended Leave of Absence', 'callsign' => 'ELOA', 'path' => "5{$tag}0/"]);
        $recruits = Unit::factory()->group(UnitKind::Recruit)->create(['name' => 'New Recruits', 'callsign' => 'NR', 'path' => "5{$tag}1/"]);

        $this->trooper('SSG', 'Hollis.P', (int) "{$tag}1", $platoon, 'Platoon Sergeant 9/D/DEVCOM');
        $this->trooper('PFC', 'Brand.T', (int) "{$tag}2", $section, 'Trooper 1/9/D/DEVCOM');
        $this->trooper('SPC', 'Marlowe.E', (int) "{$tag}3", $eloa, 'ELOA', TrooperStatus::Eloa)
            ->forceFill(['last_line_unit_id' => $section->id])->save();
        $this->trooper('RCT', 'Quill.R', (int) "{$tag}4", $recruits, 'New Recruit');
    }

    private function trooper(string $rank, string $username, int $cavId, Unit $unit, string $billet, TrooperStatus $status = TrooperStatus::Combat): Trooper
    {
        return Trooper::factory()->create([
            'cav_id' => $cavId, 'rank_short' => $rank, 'username' => $username, 'billet' => $billet, 'status' => $status, 'primary_section_id' => $unit->id,
        ]);
    }
}
