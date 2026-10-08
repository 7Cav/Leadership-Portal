<?php

use App\Commands\Units\ImportOrgStructure;
use App\Commands\Units\OrgStructureImportFailedException;
use App\Integration\Cav\CavApi;
use App\Models\AuditLogEntry;
use App\Models\Unit;
use App\Models\UnitBilletMapping;
use App\Models\UnitType;
use App\Models\User;
use App\Repositories\UnitBilletMappingRepository;
use App\Repositories\UnitRepository;
use Illuminate\Support\Collection;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

/**
 * Story 2.9 — build the chain of command and the billet → section mapping from the Cav position
 * groups. Cav is faked at the AD-3 seam with the trimmed live fixture (tests/Fixtures/cav):
 * 1-7 (A, B, D companies), 2-7, 3-7, ACD and DEVCOM line groups plus every kind of non-line
 * group, and the one live stray (`Section Leader 3/2/C/1-7` listed under D/1-7).
 */
const IOS_URL = 'api.7cav.us/api/v1/milpacs/position/groups';

function iosFixture(): array
{
    return json_decode(file_get_contents(base_path('tests/Fixtures/cav/position-groups.json')), true, flags: JSON_THROW_ON_ERROR);
}

function iosFake(array|MockResponse $payload): void
{
    MockClient::destroyGlobal();
    MockClient::global([IOS_URL => $payload instanceof MockResponse ? $payload : MockResponse::make($payload)]);
}

function iosImport(?User $actor = null)
{
    return app(ImportOrgStructure::class)->handle($actor ?? User::factory()->admin()->create());
}

function iosUnit(UnitType $type, string $callsign): ?Unit
{
    return Unit::query()->where('type', $type)->where('callsign', $callsign)->first();
}

/** @return array<int, string> position id => mapped section callsign */
function iosMappings(): array
{
    return UnitBilletMapping::query()->with('unit')->get()
        ->mapWithKeys(fn ($m) => [$m->cav_position_id => $m->unit->callsign])->all();
}

/** A snapshot of every unit and mapping row, timestamps included, to prove a re-run wrote nothing. */
function iosSnapshot(): array
{
    return [
        Unit::query()->orderBy('id')->get()->map->getAttributes()->all(),
        UnitBilletMapping::query()->orderBy('id')->get()->map->getAttributes()->all(),
    ];
}

beforeEach(function () {
    app()->instance(CavApi::class, new CavApi(['base_url' => 'https://api.7cav.us', 'api_key' => 'cav7_test-key']));
    iosFake(iosFixture());
});

it('builds Regiment → Battalion → Company → Platoon → Section from the line groups (AC #2)', function () {
    $result = iosImport();

    $root = iosUnit(UnitType::Regiment, '7CAV');
    expect(Unit::query()->whereNull('parent_id')->count())->toBe(1)
        ->and($root->name)->toBe('7th Cavalry Regiment')
        ->and($root->path)->toBe("{$root->id}/");

    $battalions = Unit::query()->where('type', UnitType::Battalion)->orderBy('id')->pluck('name', 'callsign')->all();
    expect($battalions)->toBe([
        '1-7' => '1st Battalion',
        '2-7' => '2nd Battalion',
        '3-7' => '3rd Battalion',
        'ACD' => 'Auxiliary Combat Detachment',
        'DEVCOM' => 'DEVCOM',
    ]);

    expect(Unit::query()->where('type', UnitType::Company)->orderBy('id')->pluck('callsign')->all())
        ->toBe(['A/1-7', 'B/1-7', 'D/1-7', 'A/2-7', 'A/3-7', 'B/ACD', 'D/DEVCOM'])
        ->and(iosUnit(UnitType::Company, 'A/1-7')->name)->toBe('Alpha Company')
        ->and(iosUnit(UnitType::Company, 'D/DEVCOM')->name)->toBe('Delta Company');

    expect(Unit::query()->where('type', UnitType::Platoon)->orderBy('id')->pluck('callsign')->all())
        ->toBe(['1/A/1-7', '2/A/1-7', '1/B/1-7', '2/D/1-7', '1/A/2-7', '1/A/3-7', '1/B/ACD', '1/D/DEVCOM']);

    expect(Unit::query()->where('type', UnitType::Section)->orderBy('id')->pluck('callsign')->all())
        ->toBe(['1/1/A/1-7', '2/1/A/1-7', '1/2/A/1-7', '1/1/B/1-7', '1/2/D/1-7', '3/2/D/1-7', '1/1/A/2-7', '1/1/A/3-7', '1/1/B/ACD', '1/1/D/DEVCOM']);

    $section = iosUnit(UnitType::Section, '2/1/A/1-7');
    $platoon = iosUnit(UnitType::Platoon, '1/A/1-7');
    $company = iosUnit(UnitType::Company, 'A/1-7');
    $battalion = iosUnit(UnitType::Battalion, '1-7');

    // Each node hangs one rank below its parent and carries the materialized path (AD-18).
    expect($section->name)->toBe('2nd Section')
        ->and($platoon->name)->toBe('1st Platoon')
        ->and($section->parent_id)->toBe($platoon->id)
        ->and($platoon->parent_id)->toBe($company->id)
        ->and($company->parent_id)->toBe($battalion->id)
        ->and($battalion->parent_id)->toBe($root->id)
        ->and($section->path)->toBe("{$root->id}/{$battalion->id}/{$company->id}/{$platoon->id}/{$section->id}/");

    expect($result->unitsCreated)->toBe(31)
        ->and(Unit::count())->toBe(31);
});

it('maps every section-level billet to its section by cav_position_id (AC #3)', function () {
    $result = iosImport();
    $mappings = iosMappings();

    // A/1-7 positions as Cav lists them: Section Leader / ASL / Trooper of 1/1, 2/1 and 1/2.
    expect($mappings[201])->toBe('1/1/A/1-7')
        ->and($mappings[202])->toBe('1/1/A/1-7')
        ->and($mappings[203])->toBe('1/1/A/1-7')
        ->and($result->mappingsCreated)->toBe(29)
        ->and(count($mappings))->toBe(29);

    // Company- and platoon-level billets are not section billets — never mapped.
    expect($mappings)->not->toHaveKey(196)  // Commander A/1-7
        ->and($mappings)->not->toHaveKey(199); // Platoon Leader 1/A/1-7

    // Every mapping lands on a section — the only kind 2.5's resolver accepts.
    expect(app(UnitBilletMappingRepository::class)->sectionIdsByPositionId())->toHaveCount(29);
});

it('creates nothing for non-line groups (AC #4)', function () {
    iosImport();

    $callsigns = Unit::query()->pluck('callsign')->all();

    foreach (['Regimental HQ', 'Support Attachment', '1-7 Command', 'ACD Command', 'Start-Up Games', 'Retired'] as $group) {
        expect($callsigns)->not->toContain($group);
    }

    // `Section Leader Star Citizen Start-Up` looks like a section billet but sits in a non-line
    // group: no node, no mapping.
    $startUpIds = collect(iosFixture()['groups'])->firstWhere('groupTitle', 'Start-Up Games')['positions'];
    foreach ($startUpIds as $position) {
        expect(iosMappings())->not->toHaveKey((int) $position['positionId']);
    }
});

it('skips and counts a title naming another company instead of guessing (edge case)', function () {
    $result = iosImport();

    // Live data lists `Section Leader 3/2/C/1-7` (id 1086) under D/1-7.
    expect($result->skippedTitles)->toBe(['Section Leader 3/2/C/1-7'])
        ->and(iosMappings())->not->toHaveKey(1086)
        ->and(iosUnit(UnitType::Company, 'C/1-7'))->toBeNull();
});

it('skips and counts an unparseable title in a line group', function () {
    iosFake(['groups' => [
        ['groupId' => '5', 'groupTitle' => 'A/1-7', 'groupDisplayOrder' => 40, 'positions' => [
            ['positionTitle' => 'Trooper 1/1/A/1-7', 'positionId' => '203', 'positionDisplayOrder' => 10],
            ['positionTitle' => 'Company Clerk', 'positionId' => '900', 'positionDisplayOrder' => 20],
            ['positionTitle' => 'Trooper x/1/A/1-7', 'positionId' => '901', 'positionDisplayOrder' => 30],
        ]],
    ]]);

    $result = iosImport();

    expect($result->skippedTitles)->toBe(['Company Clerk', 'Trooper x/1/A/1-7'])
        ->and(array_keys(iosMappings()))->toBe([203]);
});

it('writes nothing on an unchanged re-run, and still audits it (AC #5)', function () {
    $admin = User::factory()->admin()->create();
    iosImport($admin);
    $before = iosSnapshot();
    $this->travel(5)->minutes();

    $result = iosImport($admin);

    expect(iosSnapshot())->toBe($before)
        ->and($result->unitsCreated)->toBe(0)
        ->and($result->mappingsCreated)->toBe(0);

    $entries = AuditLogEntry::query()->where('action', 'org_structure.imported')->get();
    $root = iosUnit(UnitType::Regiment, '7CAV');
    expect($entries)->toHaveCount(2)
        ->and($entries->pluck('actor_id')->unique()->all())->toBe([$admin->id])
        ->and($entries->pluck('target_type')->unique()->all())->toBe(['Unit'])
        ->and($entries->pluck('target_id')->unique()->all())->toBe([(string) $root->id]);
});

it('reuses an existing node matched on type + callsign and keeps its portal name (AC #6)', function () {
    $repo = app(UnitRepository::class);
    $regiment = $repo->create(UnitType::Regiment, '7th Cav', null, null);
    $battalion = $repo->create(UnitType::Battalion, 'First Battalion (renamed)', '1-7', $regiment->id);
    $company = $repo->create(UnitType::Company, 'Apache', 'A/1-7', $battalion->id);

    iosImport();

    // The hand-authored, callsign-less root is reused rather than a second root started.
    expect(Unit::query()->whereNull('parent_id')->count())->toBe(1)
        ->and(Unit::query()->where('type', UnitType::Battalion)->where('callsign', '1-7')->count())->toBe(1)
        ->and(Unit::query()->where('type', UnitType::Company)->where('callsign', 'A/1-7')->count())->toBe(1)
        ->and($battalion->fresh()->name)->toBe('First Battalion (renamed)')
        ->and($company->fresh()->name)->toBe('Apache')
        ->and($regiment->fresh()->name)->toBe('7th Cav')
        ->and($regiment->fresh()->callsign)->toBeNull()
        ->and(iosUnit(UnitType::Platoon, '1/A/1-7')->parent_id)->toBe($company->id)
        ->and(iosUnit(UnitType::Battalion, '2-7')->parent_id)->toBe($regiment->id);
});

it('never deletes nodes or repoints mappings that are gone or changed upstream (AC #7)', function () {
    $repo = app(UnitRepository::class);
    iosImport();

    // An Admin-authored node Cav has never heard of, and a hand-corrected mapping.
    $extra = $repo->create(UnitType::Section, 'Range Section', '9/1/A/1-7', iosUnit(UnitType::Platoon, '1/A/1-7')->id);
    UnitBilletMapping::query()->where('cav_position_id', 201)->update(['unit_id' => $extra->id]);

    // Upstream shrinks to one company.
    iosFake(['groups' => [collect(iosFixture()['groups'])->firstWhere('groupTitle', 'B/1-7')]]);
    $result = iosImport();

    expect($result->unitsCreated)->toBe(0)
        ->and(Unit::count())->toBe(32)
        ->and(iosUnit(UnitType::Section, '9/1/A/1-7'))->not->toBeNull()
        ->and(iosMappings()[201])->toBe('9/1/A/1-7')
        ->and(UnitBilletMapping::count())->toBe(29);
});

it('makes no write when Cav is unavailable (edge case)', function () {
    iosFake(MockResponse::make(['message' => 'down'], 503));

    expect(fn () => iosImport())->toThrow(OrgStructureImportFailedException::class, 'HTTP 503');

    expect(Unit::count())->toBe(0)
        ->and(UnitBilletMapping::count())->toBe(0)
        ->and(AuditLogEntry::count())->toBe(0);
});

it('makes no write when Cav returns a malformed payload (edge case)', function () {
    iosFake(['groups' => [['groupId' => '5', 'groupTitle' => 'A/1-7', 'positions' => [['positionTitle' => 'Trooper 1/1/A/1-7']]]]]);

    expect(fn () => iosImport())->toThrow(OrgStructureImportFailedException::class, 'positionId');

    expect(Unit::count())->toBe(0)->and(AuditLogEntry::count())->toBe(0);
});

it('refuses a payload with no line companies rather than import a bare root', function () {
    iosFake(['groups' => [collect(iosFixture()['groups'])->firstWhere('groupTitle', 'Retired')]]);

    expect(fn () => iosImport())->toThrow(OrgStructureImportFailedException::class, 'no line companies');

    expect(Unit::count())->toBe(0)->and(AuditLogEntry::count())->toBe(0);
});

it('rolls every write back when the import fails part-way (single transaction)', function () {
    // The tree writes succeed, then the first mapping write blows up.
    app()->instance(UnitBilletMappingRepository::class, new class implements UnitBilletMappingRepository
    {
        public function sectionIdsByPositionId(): Collection
        {
            return collect();
        }

        public function mappedPositionIds(): Collection
        {
            return collect();
        }

        public function create(int $unitId, int $cavPositionId): UnitBilletMapping
        {
            throw new RuntimeException('disk full');
        }
    });

    expect(fn () => iosImport())->toThrow(RuntimeException::class, 'disk full');

    expect(Unit::count())->toBe(0)
        ->and(AuditLogEntry::count())->toBe(0);
});
