<?php

use App\Authorization\ScopeResolver;
use App\Commands\Templates\CreateTemplate;
use App\Commands\Templates\SetCompanyTemplatePolicy;
use App\Commands\Units\ImportOrgStructure;
use App\Integration\Cav\CavApi;
use App\Models\ArtifactType;
use App\Models\Trooper;
use App\Models\Unit;
use App\Models\UnitBilletMapping;
use App\Models\UnitCommandMapping;
use App\Models\UnitType;
use App\Models\User;
use App\Repositories\TemplateVersionRepository;
use App\Repositories\UnitLeadRepository;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

// #377: `DEVCOM 1IC` and `DEVCOM 2IC` command the DEVCOM battalion, as its commander and XO. DEVCOM sits at battalion
// level with D/DEVCOM under it. Cav's fixture lists neither billet (only D/DEVCOM's section billets), so the group
// they sit in is not known: they are tested in a DEVCOM staff group and in the D/DEVCOM line group.

const DEVCOM_URL = 'api.7cav.us/api/v1/milpacs/position/groups';
const DEVCOM_1IC = 9901;
const DEVCOM_2IC = 9902;
const DEVCOM_CLERK = 9903;

/** The live fixture plus DEVCOM's command team, in `$group` (a new staff group, or an existing group's title). */
function devcomFixture(string $group): array
{
    $fixture = json_decode(file_get_contents(base_path('tests/Fixtures/cav/position-groups.json')), true, flags: JSON_THROW_ON_ERROR);
    $positions = [
        ['positionTitle' => 'DEVCOM 1IC', 'positionId' => (string) DEVCOM_1IC, 'positionDisplayOrder' => 1, 'positionPossibleSecondary' => false],
        ['positionTitle' => 'DEVCOM 2IC', 'positionId' => (string) DEVCOM_2IC, 'positionDisplayOrder' => 2, 'positionPossibleSecondary' => false],
        ['positionTitle' => 'DEVCOM Support Clerk', 'positionId' => (string) DEVCOM_CLERK, 'positionDisplayOrder' => 3, 'positionPossibleSecondary' => false],
    ];

    foreach ($fixture['groups'] as $index => $row) {
        if ($row['groupTitle'] === $group) {
            $fixture['groups'][$index]['positions'] = [...$row['positions'], ...$positions];

            return $fixture;
        }
    }

    $fixture['groups'][] = ['groupId' => '9900', 'groupTitle' => $group, 'groupDisplayOrder' => 999, 'positions' => $positions];

    return $fixture;
}

function devcomImport(string $group): void
{
    app()->instance(CavApi::class, new CavApi(['base_url' => 'https://api.7cav.us', 'api_key' => 'cav7_test-key']));
    MockClient::destroyGlobal();
    MockClient::global([DEVCOM_URL => MockResponse::make(devcomFixture($group))]);

    app(ImportOrgStructure::class)->handle(User::factory()->admin()->create());
}

function devcomUnit(UnitType $type, string $callsign): Unit
{
    return Unit::query()->where('type', $type)->where('callsign', $callsign)->firstOrFail();
}

/** A serving user holding the billet. */
function devcomHolder(int $positionId): User
{
    $trooper = Trooper::factory()->create(['cav_position_id' => $positionId]);

    return User::factory()->create(['cav_id' => $trooper->cav_id]);
}

it('maps DEVCOM 1IC and 2IC to command of the DEVCOM battalion, from a staff group or the D/DEVCOM line group', function (string $group) {
    devcomImport($group);
    $battalion = devcomUnit(UnitType::Battalion, 'DEVCOM');

    expect(UnitCommandMapping::query()->where('cav_position_id', DEVCOM_1IC)->value('unit_id'))->toBe($battalion->id)
        ->and(UnitCommandMapping::query()->where('cav_position_id', DEVCOM_2IC)->value('unit_id'))->toBe($battalion->id)
        // The 1IC's billet names the lead; neither is placed in the DEVCOM staff group their title would name.
        ->and($battalion->refresh()->lead_position_id)->toBe(DEVCOM_1IC)
        ->and(UnitBilletMapping::query()->whereIn('cav_position_id', [DEVCOM_1IC, DEVCOM_2IC])->count())->toBe(0)
        // Any other DEVCOM billet commands nothing, as before.
        ->and(UnitCommandMapping::query()->where('cav_position_id', DEVCOM_CLERK)->exists())->toBeFalse()
        ->and(devcomUnit(UnitType::Company, 'D/DEVCOM')->parent_id)->toBe($battalion->id);
})->with(['a DEVCOM staff group' => ['DEVCOM STAFF'], 'the D/DEVCOM line group' => ['D/DEVCOM']]);

it('is idempotent: a second import changes no mapping and keeps the lead billet', function () {
    devcomImport('DEVCOM STAFF');
    $before = UnitCommandMapping::query()->orderBy('id')->get()->map->getAttributes()->all();

    devcomImport('DEVCOM STAFF');

    expect(UnitCommandMapping::query()->orderBy('id')->get()->map->getAttributes()->all())->toBe($before)
        ->and(devcomUnit(UnitType::Battalion, 'DEVCOM')->lead_position_id)->toBe(DEVCOM_1IC);
});

describe('the DEVCOM command team', function () {
    beforeEach(function () {
        devcomImport('DEVCOM STAFF');
        $this->battalion = devcomUnit(UnitType::Battalion, 'DEVCOM');
        $this->company = devcomUnit(UnitType::Company, 'D/DEVCOM');
        $this->section = devcomUnit(UnitType::Section, '1/1/D/DEVCOM');
        $this->xo = devcomHolder(DEVCOM_2IC);
    });

    it('shows the 1IC as the DEVCOM lead, and the lead as vacant when the 1IC billet is, though the 2IC serves', function () {
        $leads = app(UnitLeadRepository::class);

        expect($leads->leadsOf([$this->battalion->id])->has($this->battalion->id))->toBeFalse()
            ->and($leads->leadableUnitIds()->contains($this->battalion->id))->toBeTrue();

        $commander = Trooper::factory()->create(['cav_position_id' => DEVCOM_1IC, 'username' => 'Lead.D']);

        expect($leads->leadsOf([$this->battalion->id])->get($this->battalion->id)->trooper->id)->toBe($commander->id);
    });

    it('keeps the 2IC their XO rights while the 1IC billet is vacant', function () {
        $scope = app(ScopeResolver::class);

        expect($scope->commandsUnit($this->xo, $this->battalion))->toBeTrue()
            ->and($scope->writeScope($this->xo)->contains($this->section->id))->toBeTrue();
    });

    it('lets the 1IC do what a battalion commander can for DEVCOM, D/DEVCOM and its sections: write, templates and the template switch', function () {
        $commander = devcomHolder(DEVCOM_1IC);
        $scope = app(ScopeResolver::class);

        expect($scope->writeScope($commander)->contains($this->section->id))->toBeTrue()
            ->and($scope->commandsUnit($commander, $this->company))->toBeTrue();

        app(SetCompanyTemplatePolicy::class)->handle($commander, $this->company, false);
        $template = app(CreateTemplate::class)->handle($commander, $this->company, ArtifactType::RollcallBbcode, 'DEVCOM layout', 'body', 'why');

        expect(app(TemplateVersionRepository::class)->companyEditsTemplates($this->company->id))->toBeFalse()
            ->and($template->company_id)->toBe($this->company->id);
    });

    it('gives the 1IC nothing outside DEVCOM', function () {
        $commander = devcomHolder(DEVCOM_1IC);
        $alpha = devcomUnit(UnitType::Company, 'A/1-7');

        expect(app(ScopeResolver::class)->commandsUnit($commander, $alpha))->toBeFalse();
    });
});
