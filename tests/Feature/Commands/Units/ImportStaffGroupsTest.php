<?php

use App\Commands\Units\ImportOrgStructure;
use App\Commands\Units\MoveUnit;
use App\Integration\Cav\CavApi;
use App\Models\Unit;
use App\Models\UnitBilletMapping;
use App\Models\UnitCommandMapping;
use App\Models\UnitKind;
use App\Models\UnitType;
use App\Models\User;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

// The groups off the line the import creates and maps (decided 2026-10-24): HQ placement for non-command HQ billets,
// staff departments under their regimental portfolio, ELOA, Reservists and New Recruits, and DEVCOM Lead's command.
// Cav is faked with the trimmed live fixture plus the staff billets the ADR lists.

function isgGroups(): array
{
    $groups = json_decode(file_get_contents(base_path('tests/Fixtures/cav/position-groups.json')), true, flags: JSON_THROW_ON_ERROR)['groups'];
    $add = [
        'Regimental HQ' => [
            ['Regimental Chief of Staff', '9010'],
            ['Regimental Security Operations Officer', '9011'],
            ['Regimental Information Management Officer', '9013'],
            ['Regimental Technical Aide', '9012'],
            ['Aide to the SecOps', '9014'],
            ['Regimental Recruiting Oversight Officer', '9015'],
        ],
        'Support Attachment' => [
            ['S2 Lead Investigator', '9020'],
            ['MP 1IC', '9021'],
            ['RRD Recruiter - Squad', '9022'],
            ['DEVCOM Lead', '9023'],
            ['FCC Analyst', '9024'],
            ['ODS Lead Instructor', '9025'],
            ['Cavalry Times Clerk', '9026'],
            ['RDC Commander', '9027'],
            ['DEVCOM Support Clerk', '9028'],
        ],
        'ACD Command' => [
            ['Reserve Commander', '9030'],
        ],
    ];

    foreach ($groups as &$group) {
        foreach ($add[$group['groupTitle']] ?? [] as $i => [$title, $id]) {
            $group['positions'][] = ['positionTitle' => $title, 'positionId' => $id, 'positionDisplayOrder' => 900 + $i, 'positionPossibleSecondary' => false];
        }
    }

    $groups[] = [
        'groupId' => '990', 'groupTitle' => 'JAG', 'groupDisplayOrder' => 990,
        'positions' => [['positionTitle' => 'Judge Advocate', 'positionId' => '9040', 'positionDisplayOrder' => 1, 'positionPossibleSecondary' => false]],
    ];

    return ['groups' => $groups];
}

function isgImport(): void
{
    MockClient::destroyGlobal();
    MockClient::global(['api.7cav.us/api/v1/milpacs/position/groups' => MockResponse::make(isgGroups())]);
    app(ImportOrgStructure::class)->handle(User::factory()->admin()->create());
}

function isgGroup(string $callsign): Unit
{
    return Unit::query()->where('type', UnitType::Group)->where('callsign', $callsign)->sole();
}

/** @return array<int, string> position id => type|callsign */
function isgPlacements(): array
{
    return UnitBilletMapping::query()->with('unit')->get()
        ->mapWithKeys(fn ($m) => [$m->cav_position_id => "{$m->unit->type->value}|{$m->unit->callsign}"])->all();
}

beforeEach(function () {
    app()->instance(CavApi::class, new CavApi(['base_url' => 'https://api.7cav.us', 'api_key' => 'cav7_test-key']));
});

it('builds the default portfolio tree, nesting portfolios, with each group of its kind', function () {
    isgImport();
    $regiment = Unit::query()->where('type', UnitType::Regiment)->sole();

    foreach (['ELOA' => UnitKind::Eloa, 'RES' => UnitKind::Reserve, 'NR' => UnitKind::Recruit] as $callsign => $kind) {
        expect(isgGroup($callsign)->kind)->toBe($kind);
    }

    expect(isgGroup('ELOA')->parent_id)->toBe($regiment->id)
        ->and(isgGroup('NR')->parent_id)->toBe($regiment->id)
        // Executive Officer → Reserve & Development Command → DEVCOM staff, Reservists.
        ->and(isgGroup('XO')->parent_id)->toBe($regiment->id)
        ->and(isgGroup('RDC')->parent_id)->toBe(isgGroup('XO')->id)
        ->and(isgGroup('DEVCOM STAFF')->parent_id)->toBe(isgGroup('RDC')->id)
        ->and(isgGroup('RES')->parent_id)->toBe(isgGroup('RDC')->id)
        // Recruiting Oversight → RRD, RTC, S5, directly under the regiment; the Chief of Staff has no portfolio.
        ->and(Unit::query()->where('callsign', 'COS')->exists())->toBeFalse()
        ->and(isgGroup('RROO')->parent_id)->toBe($regiment->id)
        ->and(isgGroup('RRD')->parent_id)->toBe(isgGroup('RROO')->id)
        ->and(isgGroup('S5')->parent_id)->toBe(isgGroup('RROO')->id)
        // Security Operations → S2, MP, JAG; Information Management → S6.
        ->and(isgGroup('S2')->parent_id)->toBe(isgGroup('SECOPS')->id)
        ->and(isgGroup('MP')->parent_id)->toBe(isgGroup('SECOPS')->id)
        ->and(isgGroup('JAG')->parent_id)->toBe(isgGroup('SECOPS')->id)
        ->and(isgGroup('S6')->parent_id)->toBe(isgGroup('IMO')->id)
        ->and(isgGroup('IMO')->parent_id)->toBe($regiment->id)
        // Pending the product owner: directly under the regiment.
        ->and(isgGroup('S1')->parent_id)->toBe($regiment->id)
        ->and(isgGroup('S7')->parent_id)->toBe($regiment->id)
        // ACD stays a line battalion under the regiment.
        ->and(Unit::query()->where('type', UnitType::Battalion)->where('callsign', 'ACD')->sole()->parent_id)->toBe($regiment->id)
        ->and(isgGroup('S1')->kind)->toBe(UnitKind::Staff)
        ->and(isgGroup('SECOPS')->kind)->toBe(UnitKind::Staff);
});

it('names each portfolio\'s lead billet', function () {
    isgImport();
    $fixture = collect(isgGroups()['groups'])->flatMap(fn ($g) => $g['positions'])->pluck('positionId', 'positionTitle');

    expect(isgGroup('XO')->lead_position_id)->toBe((int) $fixture['Regimental Executive Officer'])
        ->and(isgGroup('RDC')->lead_position_id)->toBe(9027)
        ->and(isgGroup('RROO')->lead_position_id)->toBe(9015)
        ->and(isgGroup('SECOPS')->lead_position_id)->toBe(9011)
        ->and(isgGroup('IMO')->lead_position_id)->toBe(9013)
        ->and(isgGroup('S1')->lead_position_id)->toBeNull();
});

it('maps every staff billet to where it places its holder', function () {
    isgImport();
    $placements = isgPlacements();
    $commands = UnitCommandMapping::query()->with('unit')->get()
        ->mapWithKeys(fn ($m) => [$m->cav_position_id => "{$m->unit->type->value}|{$m->unit->callsign}"])->all();
    $fixture = collect(isgGroups()['groups'])->flatMap(fn ($g) => $g['positions'])->pluck('positionId', 'positionTitle');

    expect($placements[9010])->toBe('regiment|7CAV')
        ->and($placements[9014])->toBe('regiment|7CAV')
        // A portfolio officer is placed on the regiment and commands their portfolio; the XO keeps the regiment.
        ->and($placements[9011])->toBe('regiment|7CAV')
        ->and($commands[9011])->toBe('group|SECOPS')
        ->and($commands[9013])->toBe('group|IMO')
        // The Chief of Staff is Regimental HQ staff on the regiment, commanding nothing.
        ->and($commands)->not->toHaveKey(9010)
        ->and($commands[(int) $fixture['Regimental Executive Officer']])->toBe('regiment|7CAV')
        // The RDC Commander sits on, and commands, their portfolio.
        ->and($placements[9027])->toBe('group|RDC')
        ->and($commands[9027])->toBe('group|RDC')
        ->and($placements[9040])->toBe('group|JAG')
        ->and($placements[9012])->toBe('group|S6')
        ->and($placements[9020])->toBe('group|S2')
        ->and($placements[9021])->toBe('group|MP')
        ->and($placements[9022])->toBe('group|RRD')
        ->and($placements[9024])->toBe('group|DEVCOM STAFF')
        ->and($placements[9025])->toBe('group|S7')
        ->and($placements[9026])->toBe('group|S5')
        ->and($placements[(int) $fixture['S1 1IC']])->toBe('group|S1')
        ->and($placements[(int) $fixture['ELOA']])->toBe('group|ELOA')
        ->and($placements[(int) $fixture['Reservist']])->toBe('group|RES')
        ->and($placements[(int) $fixture['New Recruit']])->toBe('group|NR')
        // ACD's reserve command team sits on the battalion HQ.
        ->and($placements[9030])->toBe('battalion|ACD')
        // DEVCOM Lead: placed with DEVCOM staff, commanding nothing by billet (acting leads are assigned by hand).
        ->and($placements[9023])->toBe('group|DEVCOM STAFF')
        ->and($commands)->not->toHaveKey(9023)
        // Command billets keep their command mapping and get no placement row: the sync places them by it.
        ->and($placements)->not->toHaveKey((int) $fixture['Regimental Commanding Officer'])
        ->and($commands[(int) $fixture['Regimental Commanding Officer']])->toBe('regiment|7CAV');
});

it('is idempotent: a re-run creates no group and no mapping', function () {
    isgImport();
    $units = Unit::query()->orderBy('id')->get()->map->getAttributes()->all();
    $placements = UnitBilletMapping::query()->orderBy('id')->get()->map->getAttributes()->all();

    isgImport();

    expect(Unit::query()->orderBy('id')->get()->map->getAttributes()->all())->toBe($units)
        ->and(UnitBilletMapping::query()->orderBy('id')->get()->map->getAttributes()->all())->toBe($placements);
});

it('keeps an Admin\'s move of a department to another portfolio on the next import', function () {
    isgImport();
    $admin = User::factory()->admin()->create();
    app(MoveUnit::class)->handle($admin, isgGroup('MP'), isgGroup('IMO')->id);

    isgImport();

    expect(isgGroup('MP')->parent_id)->toBe(isgGroup('IMO')->id)
        ->and(Unit::query()->where('callsign', 'MP')->count())->toBe(1);
});
