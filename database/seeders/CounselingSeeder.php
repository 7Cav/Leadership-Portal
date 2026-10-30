<?php

namespace Database\Seeders;

use App\Models\CounselingKind;
use App\Models\CounselingRecord;
use App\Models\CounselingType;
use App\Models\DriveUploadStatus;
use App\Models\Trooper;
use App\Models\TrooperStatus;
use App\Models\Unit;
use App\Models\UnitCommandMapping;
use App\Models\UnitType;
use App\Models\User;
use App\Repositories\CounselingDocuments;
use Carbon\CarbonImmutable;
use Database\Seeders\Concerns\RefusesOutsideDevEnvironments;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Counseling records for the browser test (#381), under their own company and usernames. `Lindqvist.P` leads 1st
 * Platoon of Foxtrot Company (1/F/1-7) and `Sato.K` its 1st Section (1/1/F/1-7), both by billet. `Novak.E`, a rifleman
 * in that section, already has a DCS from Sato.K and an LOR from Lindqvist.P, each 20 days ago, with their documents
 * on the private disk. Refuses to run outside local, testing and e2e: its users lead units by command billet.
 */
class CounselingSeeder extends Seeder
{
    use RefusesOutsideDevEnvironments;

    public function run(): void
    {
        $this->refuseOutsideDevEnvironments();

        $tag = CarbonImmutable::now('UTC')->format('His').random_int(100, 999);

        // The org's own 1/F/1-7 and its 1st Section when the tree already has them, so the portal never shows two units
        // with one callsign (the staff report's picker listed the platoon twice). Otherwise its own path prefix (4),
        // apart from the other seeders' (5 to 9).
        $platoon = Unit::query()->where('type', UnitType::Platoon)->where('callsign', '1/F/1-7')->first();
        $section = $platoon === null ? null : Unit::query()->where('parent_id', $platoon->id)->where('callsign', '1/1/F/1-7')->first();
        if ($platoon === null || $section === null) {
            $company = Unit::factory()->create(['type' => UnitType::Company, 'name' => 'Foxtrot Company', 'callsign' => 'F/1-7', 'path' => "4{$tag}/"]);
            $platoon = Unit::factory()->create(['type' => UnitType::Platoon, 'name' => '1st Platoon', 'callsign' => '1/F/1-7', 'parent_id' => $company->id, 'path' => "4{$tag}/1/"]);
            $section = Unit::factory()->create(['type' => UnitType::Section, 'name' => '1st Section', 'callsign' => '1/1/F/1-7', 'parent_id' => $platoon->id, 'path' => "4{$tag}/1/1/"]);
        }

        $pl = $this->leader('1LT', 'Lindqvist.P', (int) "{$tag}1", $platoon, (int) "{$tag}91");
        $sl = $this->leader('SGT', 'Sato.K', (int) "{$tag}2", $section, (int) "{$tag}92");
        $rifleman = Trooper::factory()->create([
            'cav_id' => (int) "{$tag}3", 'rank_short' => 'PFC', 'username' => 'Novak.E', 'status' => TrooperStatus::Combat, 'primary_section_id' => $section->id,
        ]);

        $this->record($rifleman, CounselingType::Dcs, CounselingKind::MissedPractice, $sl, $section, 'Missed two section practices without notice.');
        $this->record($rifleman, CounselingType::Lor, CounselingKind::Conduct, $pl, $platoon, 'Disrespect toward an NCO during practice.');
    }

    private function leader(string $rank, string $username, int $cavId, Unit $unit, int $positionId): User
    {
        UnitCommandMapping::factory()->create(['unit_id' => $unit->id, 'cav_position_id' => $positionId]);
        Trooper::factory()->create([
            'cav_id' => $cavId, 'rank_short' => $rank, 'username' => $username, 'status' => TrooperStatus::Combat,
            'primary_section_id' => $unit->id, 'cav_position_id' => $positionId,
        ]);

        return User::factory()->create(['username' => $username, 'cav_id' => $cavId]);
    }

    private function record(Trooper $trooper, CounselingType $type, CounselingKind $kind, User $issuer, Unit $through, string $reason): void
    {
        $bytes = "%PDF-1.4\n% seeded {$type->short()} for {$trooper->username}\n%%EOF\n";
        $path = Str::uuid()->toString().'.pdf';
        Storage::disk(CounselingDocuments::DISK)->put($path, $bytes);

        CounselingRecord::factory()->create([
            'trooper_id' => $trooper->id,
            'type' => $type,
            'kind' => $kind,
            'counseled_on' => CarbonImmutable::now('UTC')->subDays(20)->toDateString(),
            'reason' => $reason,
            'issued_by' => $issuer->id,
            'issuer_unit_id' => $through->id,
            'document_path' => $path,
            'document_name' => strtolower($type->short()).'-signed.pdf',
            'document_size' => strlen($bytes),
            'document_sha256' => hash('sha256', $bytes),
            'drive_status' => DriveUploadStatus::Skipped,
        ]);
    }
}
