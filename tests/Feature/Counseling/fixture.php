<?php

use App\Models\PortalRoleGrant;
use App\Models\Trooper;
use App\Models\UnitType;
use App\Models\User;
use App\Repositories\UnitRepository;
use Illuminate\Http\UploadedFile;

/**
 * The counseling tests' shared tree (#381): 7CAV ─ 1-7 ─ A/1-7 ─ 1/A/1-7 ─┬─ 1/1/A/1-7 (SL `sl`, rifleman Brandt.L)
 *                                                                        └─ 2/1/A/1-7 (SL `otherSl`)
 * with a Platoon Leader `pl`, a company commander `co`, a staff grant on the company `grant` and an Admin.
 *
 * @return array{units: array<string, mixed>, users: array<string, User>, rifleman: Trooper}
 */
function counselingTree(): array
{
    $units = app(UnitRepository::class);
    $regiment = $units->create(UnitType::Regiment, '7th Cavalry Regiment', '7CAV', null);
    $battalion = $units->create(UnitType::Battalion, '1st Battalion', '1-7', $regiment->id);
    $company = $units->create(UnitType::Company, 'Alpha Company', 'A/1-7', $battalion->id);
    $platoon = $units->create(UnitType::Platoon, '1st Platoon', '1/A/1-7', $company->id);
    $section = $units->create(UnitType::Section, '1st Section', '1/1/A/1-7', $platoon->id);
    $other = $units->create(UnitType::Section, '2nd Section', '2/1/A/1-7', $platoon->id);

    $users = [
        'admin' => User::factory()->admin()->create(['username' => 'Hale.S']),
        'sl' => User::factory()->commanding($section, ['primary_section_id' => $section->id, 'username' => 'Okafor.B'])->create(['username' => 'Okafor.B']),
        'otherSl' => User::factory()->commanding($other, ['primary_section_id' => $other->id])->create(),
        'pl' => User::factory()->commanding($platoon, ['primary_section_id' => $platoon->id, 'username' => 'Mercer.J'])->create(['username' => 'Mercer.J']),
        'co' => User::factory()->commanding($company, ['primary_section_id' => $company->id])->create(),
        'grant' => User::factory()->serving()->create(),
    ];
    PortalRoleGrant::factory()->create(['user_id' => $users['grant']->id, 'unit_id' => $company->id]);

    return [
        'units' => compact('regiment', 'battalion', 'company', 'platoon', 'section', 'other'),
        'users' => $users,
        'rifleman' => Trooper::factory()->create(['primary_section_id' => $section->id, 'username' => 'Brandt.L']),
    ];
}

/** A small but real PDF: its bytes start with the PDF signature, so the content sniffer agrees. */
function counselingPdfBytes(string $marker = 'signed-dcs-body'): string
{
    return "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\n% {$marker}\ntrailer << /Root 1 0 R >>\n%%EOF\n";
}

function counselingPdf(string $name = 'Brandt DCS.pdf', string $marker = 'signed-dcs-body'): UploadedFile
{
    return UploadedFile::fake()->createWithContent($name, counselingPdfBytes($marker));
}
