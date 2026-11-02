<?php

use App\Integration\Cav\CavApi;
use App\Models\ArtifactType;
use App\Models\AuditLogEntry;
use App\Models\PortalRoleGrant;
use App\Models\Unit;
use App\Models\UnitBilletMapping;
use App\Models\UnitType;
use App\Models\User;
use App\Repositories\SectionPracticeRepository;
use App\Repositories\TemplateVersionRepository;
use App\Repositories\UnitRepository;
use App\Repositories\UnitRepository\Eloquent as EloquentUnitRepository;
use App\Repositories\WeeklyReportRepository;
use App\Support\Time\WeekOf;
use Carbon\CarbonImmutable;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

it('renders the org tree for any authenticated user', function () {
    app(UnitRepository::class)->create(UnitType::Regiment, '7th Cavalry Regiment', null, null);
    $user = User::factory()->serving()->create();

    $this->actingAs($user)->get('/settings/org-structure')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Settings/OrgStructure')
            ->has('units', 1)
            ->where('isAdmin', false));
});

it('lets an Admin create a node under a chosen parent, deriving its name and callsign (AC #2)', function () {
    $admin = User::factory()->admin()->create();
    $regiment = app(UnitRepository::class)->create(UnitType::Regiment, '7th Cavalry Regiment', '7CAV', null);

    // A name and callsign in the request are not read: the server derives both from the ordinal.
    $this->actingAs($admin)->post('/settings/org-structure', [
        'type' => 'battalion',
        'ordinal' => '2',
        'name' => 'Anything',
        'callsign' => 'X-99',
        'parent_id' => $regiment->id,
    ])->assertRedirect();

    $created = Unit::where('callsign', '2-7')->first();
    expect($created)->not->toBeNull()
        ->and($created->name)->toBe('2nd Battalion')
        ->and($created->parent_id)->toBe($regiment->id)
        ->and($created->path)->toBe("{$regiment->path}{$created->id}/")
        ->and(Unit::where('callsign', 'X-99')->exists())->toBeFalse();
});

it('422s an ordinal already used under the parent, as a form error on ordinal', function () {
    $admin = User::factory()->admin()->create();
    $repo = app(UnitRepository::class);
    $regiment = $repo->create(UnitType::Regiment, '7th Cavalry Regiment', '7CAV', null);
    $repo->create(UnitType::Battalion, '1st Battalion', '1-7', $regiment->id);

    $this->actingAs($admin)->post('/settings/org-structure', [
        'type' => 'battalion',
        'ordinal' => '1',
        'parent_id' => $regiment->id,
    ])->assertInvalid(['ordinal' => '1st Battalion already exists here.']);

    expect(Unit::where('callsign', '1-7')->count())->toBe(1);
});

it('422s an ordinal that is not one number or letter', function () {
    $admin = User::factory()->admin()->create();
    $regiment = app(UnitRepository::class)->create(UnitType::Regiment, '7th Cavalry Regiment', '7CAV', null);

    $this->actingAs($admin)->post('/settings/org-structure', [
        'type' => 'battalion',
        'ordinal' => '1-7',
        'parent_id' => $regiment->id,
    ])->assertInvalid('ordinal');

    expect(Unit::count())->toBe(1);
});

it('refuses a non-Admin server-side, even though the UI hides the control (AC #5, AD-6)', function () {
    $user = User::factory()->serving()->create();

    $this->actingAs($user)->post('/settings/org-structure', [
        'type' => 'regiment',
        'parent_id' => null,
    ])->assertForbidden();

    expect(Unit::count())->toBe(0);
});

it('requires authentication to view or author the tree', function () {
    $this->get('/settings/org-structure')->assertRedirect(route('login'));

    $this->post('/settings/org-structure', [
        'type' => 'regiment',
        'parent_id' => null,
    ])->assertRedirect(route('login'));
});

it('422s an unrecognized type value', function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)->post('/settings/org-structure', [
        'type' => 'squad', // not a UnitType member
        'parent_id' => null,
    ])->assertInvalid('type');

    expect(Unit::count())->toBe(0);
});

it('creates the regiment with its fixed name and callsign', function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)->post('/settings/org-structure', [
        'type' => 'regiment',
        'parent_id' => null,
    ])->assertRedirect();

    expect(Unit::sole()->only('name', 'callsign'))->toBe(['name' => '7th Cavalry Regiment', 'callsign' => '7CAV']);
});

it('422s a parent_id that does not exist', function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)->post('/settings/org-structure', [
        'type' => 'battalion',
        'ordinal' => '2',
        'parent_id' => 999999,
    ])->assertInvalid('parent_id');

    expect(Unit::count())->toBe(0);
});

it('422s a node placed at the wrong echelon rank, as a form error on type (AC #6 counterpart)', function () {
    $admin = User::factory()->admin()->create();
    $regiment = app(UnitRepository::class)->create(UnitType::Regiment, '7th Cavalry Regiment', '7CAV', null);

    // Skips battalion: a company cannot be placed directly under a regiment.
    $this->actingAs($admin)->post('/settings/org-structure', [
        'type' => 'company',
        'ordinal' => 'E',
        'parent_id' => $regiment->id,
    ])->assertInvalid('type');

    expect(Unit::count())->toBe(1);
});

it('422s a non-Regiment root node', function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)->post('/settings/org-structure', [
        'type' => 'battalion',
        'ordinal' => '2',
        'parent_id' => null,
    ])->assertInvalid('type');

    expect(Unit::count())->toBe(0);
});

it('lets an Admin rename a unit (AC #1)', function () {
    $admin = User::factory()->admin()->create();
    $regiment = app(UnitRepository::class)->create(UnitType::Regiment, '7th Cavalry Regiment', null, null);

    $this->actingAs($admin)->patch("/settings/org-structure/{$regiment->id}", [
        'name' => '7th Cav Regiment',
        'callsign' => '7CAV',
        'parent_id' => null,
    ])->assertRedirect();

    expect(Unit::find($regiment->id)->name)->toBe('7th Cav Regiment')
        ->and(Unit::find($regiment->id)->callsign)->toBe('7CAV');
});

it('lets an Admin re-parent a unit, recomputing the subtree path (AC #2)', function () {
    $admin = User::factory()->admin()->create();
    $repo = app(UnitRepository::class);
    $regiment = $repo->create(UnitType::Regiment, '7th Cavalry Regiment', null, null);
    $battalionA = $repo->create(UnitType::Battalion, '1st Battalion', '1-7', $regiment->id);
    $battalionB = $repo->create(UnitType::Battalion, '2nd Battalion', '2-7', $regiment->id);
    $company = $repo->create(UnitType::Company, 'Alpha Company', 'A/1-7', $battalionA->id);

    $this->actingAs($admin)->patch("/settings/org-structure/{$company->id}", [
        'name' => $company->name,
        'callsign' => $company->callsign,
        'parent_id' => $battalionB->id,
    ])->assertRedirect();

    $moved = Unit::find($company->id);
    expect($moved->parent_id)->toBe($battalionB->id)
        ->and($moved->path)->toBe("{$battalionB->path}{$company->id}/");
});

it('logs only the move, not a rename, when a re-parent request carries the unchanged name/callsign', function () {
    // The request always resubmits name/callsign alongside parent_id — a pure re-parent must
    // not also log a "unit.renamed" entry asserting a rename that never happened.
    $admin = User::factory()->admin()->create();
    $repo = app(UnitRepository::class);
    $regiment = $repo->create(UnitType::Regiment, '7th Cavalry Regiment', null, null);
    $battalionA = $repo->create(UnitType::Battalion, '1st Battalion', '1-7', $regiment->id);
    $battalionB = $repo->create(UnitType::Battalion, '2nd Battalion', '2-7', $regiment->id);
    $company = $repo->create(UnitType::Company, 'Alpha Company', 'A/1-7', $battalionA->id);

    $this->actingAs($admin)->patch("/settings/org-structure/{$company->id}", [
        'name' => $company->name,
        'callsign' => $company->callsign,
        'parent_id' => $battalionB->id,
    ])->assertRedirect();

    expect(AuditLogEntry::count())->toBe(1)
        ->and(AuditLogEntry::first()->action)->toBe('unit.moved');
});

it('never persists the rename half of a request whose re-parent is refused', function () {
    // A rejected re-parent must not leave the accompanying rename committed behind the 422 —
    // that would look to the Admin like the whole request was refused when half of it landed.
    $admin = User::factory()->admin()->create();
    $repo = app(UnitRepository::class);
    $regiment = $repo->create(UnitType::Regiment, '7th Cavalry Regiment', null, null);
    $battalion = $repo->create(UnitType::Battalion, '1st Battalion', '1-7', $regiment->id);
    $company = $repo->create(UnitType::Company, 'Alpha Company', 'A/1-7', $battalion->id);

    $this->actingAs($admin)->patch("/settings/org-structure/{$battalion->id}", [
        'name' => 'Hijacked Battalion Name',
        'callsign' => $battalion->callsign,
        'parent_id' => $company->id, // cyclic: battalion under its own descendant
    ])->assertInvalid('parent_id');

    expect(Unit::find($battalion->id)->name)->toBe('1st Battalion');
});

it('logs both halves of a move and rename, and rolls the move back when the rename fails', function () {
    $admin = User::factory()->admin()->create();
    $repo = app(UnitRepository::class);
    $regiment = $repo->create(UnitType::Regiment, '7th Cavalry Regiment', null, null);
    $battalionA = $repo->create(UnitType::Battalion, '1st Battalion', '1-7', $regiment->id);
    $battalionB = $repo->create(UnitType::Battalion, '2nd Battalion', '2-7', $regiment->id);
    $company = $repo->create(UnitType::Company, 'Alpha Company', 'A/1-7', $battalionA->id);
    $platoon = $repo->create(UnitType::Platoon, '1st Platoon', '1/A/1-7', $company->id);

    // The rename hits a database fault after the move has written.
    $failing = Mockery::mock(EloquentUnitRepository::class)->makePartial();
    $failing->shouldReceive('rename')->andThrow(new RuntimeException('database went away'));
    app()->instance(UnitRepository::class, $failing);

    $this->actingAs($admin)->patch("/settings/org-structure/{$company->id}", [
        'name' => 'Bravo Company',
        'callsign' => 'B/2-7',
        'parent_id' => $battalionB->id,
    ])->assertServerError();

    expect(Unit::find($company->id)->only('parent_id', 'path', 'name'))->toBe(['parent_id' => $battalionA->id, 'path' => $company->path, 'name' => 'Alpha Company'])
        ->and(Unit::find($platoon->id)->path)->toBe($platoon->path)
        ->and(AuditLogEntry::count())->toBe(0);

    app()->forgetInstance(UnitRepository::class);
    $this->actingAs($admin)->patch("/settings/org-structure/{$company->id}", [
        'name' => 'Bravo Company',
        'callsign' => 'B/2-7',
        'parent_id' => $battalionB->id,
    ])->assertRedirect();

    expect(Unit::find($company->id)->only('parent_id', 'name'))->toBe(['parent_id' => $battalionB->id, 'name' => 'Bravo Company'])
        ->and(AuditLogEntry::query()->orderBy('id')->pluck('action')->all())->toBe(['unit.moved', 'unit.renamed']);
});

it('422s a cyclic re-parent as a form error on parent_id (AC #3)', function () {
    $admin = User::factory()->admin()->create();
    $repo = app(UnitRepository::class);
    $regiment = $repo->create(UnitType::Regiment, '7th Cavalry Regiment', null, null);
    $battalion = $repo->create(UnitType::Battalion, '1st Battalion', '1-7', $regiment->id);
    $company = $repo->create(UnitType::Company, 'Alpha Company', 'A/1-7', $battalion->id);

    $this->actingAs($admin)->patch("/settings/org-structure/{$battalion->id}", [
        'name' => $battalion->name,
        'callsign' => $battalion->callsign,
        'parent_id' => $company->id,
    ])->assertInvalid('parent_id');

    expect(Unit::find($battalion->id)->parent_id)->toBe($regiment->id);
});

it('refuses a non-Admin server-side on rename/re-parent, even with the UI control hidden (AC #5)', function () {
    $user = User::factory()->serving()->create();
    $regiment = app(UnitRepository::class)->create(UnitType::Regiment, '7th Cavalry Regiment', null, null);

    $this->actingAs($user)->patch("/settings/org-structure/{$regiment->id}", [
        'name' => 'Hijacked Name',
        'callsign' => null,
        'parent_id' => null,
    ])->assertForbidden();

    expect(Unit::find($regiment->id)->name)->toBe('7th Cavalry Regiment');
});

it('422s a missing name on update', function () {
    $admin = User::factory()->admin()->create();
    $regiment = app(UnitRepository::class)->create(UnitType::Regiment, '7th Cavalry Regiment', null, null);

    $this->actingAs($admin)->patch("/settings/org-structure/{$regiment->id}", [
        'callsign' => null,
        'parent_id' => null,
    ])->assertInvalid('name');

    expect(Unit::find($regiment->id)->name)->toBe('7th Cavalry Regiment');
});

it('lets an Admin delete a childless unit (AC #4)', function () {
    $admin = User::factory()->admin()->create();
    $unit = app(UnitRepository::class)->create(UnitType::Section, 'Nightriders', '1/1/E/2-7', null);

    $this->actingAs($admin)->delete("/settings/org-structure/{$unit->id}")->assertRedirect();

    expect(Unit::find($unit->id))->toBeNull();
});

it('422s a delete blocked by remaining children, with a clear reason (AC #4)', function () {
    $admin = User::factory()->admin()->create();
    $repo = app(UnitRepository::class);
    $regiment = $repo->create(UnitType::Regiment, '7th Cavalry Regiment', null, null);
    $repo->create(UnitType::Battalion, '1st Battalion', '1-7', $regiment->id);

    $this->actingAs($admin)->delete("/settings/org-structure/{$regiment->id}")->assertInvalid('delete');

    expect(Unit::find($regiment->id))->not->toBeNull();
});

it('refuses a non-Admin delete server-side, even with the UI control hidden (AC #5)', function () {
    $user = User::factory()->serving()->create();
    $unit = app(UnitRepository::class)->create(UnitType::Section, 'Nightriders', '1/1/E/2-7', null);

    $this->actingAs($user)->delete("/settings/org-structure/{$unit->id}")->assertForbidden();

    expect(Unit::find($unit->id))->not->toBeNull();
});

// ── Story 2.9: import from the Cav position groups ──

function oscFakeCav(MockResponse $response): void
{
    app()->instance(CavApi::class, new CavApi(['base_url' => 'https://api.7cav.us', 'api_key' => 'cav7_test-key']));
    MockClient::destroyGlobal();
    MockClient::global(['api.7cav.us/api/v1/milpacs/position/groups' => $response]);
}

it('lets an Admin import the tree and reports what was created (2.9)', function () {
    oscFakeCav(MockResponse::make(
        json_decode(file_get_contents(base_path('tests/Fixtures/cav/position-groups.json')), true),
    ));
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)->post('/settings/org-structure/import')
        ->assertRedirect(route('settings.org-structure'))
        ->assertSessionHas('org_import', [
            'unitsCreated' => 37,
            'mappingsCreated' => 35,
            'mappingsDiffering' => 0,
            'mappingsRepointed' => 0,
            'commandMappingsCreated' => 40,
            'commandMappingsRepointed' => 0,
            'commandMappingsRemoved' => 0,
            'skippedTitles' => ['Section Leader 3/2/C/1-7'],
        ]);

    expect(Unit::count())->toBe(37)
        ->and(AuditLogEntry::query()->where('action', 'org_structure.imported')->where('actor_id', $admin->id)->count())->toBe(1);

    // The flashed result reaches the page as a prop for the next render.
    $this->actingAs($admin)
        ->withSession(['org_import' => ['unitsCreated' => 31, 'mappingsCreated' => 29, 'skippedTitles' => []]])
        ->get('/settings/org-structure')
        ->assertInertia(fn ($page) => $page->where('importResult.unitsCreated', 31)->where('importResult.mappingsCreated', 29));
});

it('passes a null importResult when no import just ran', function () {
    $this->actingAs(User::factory()->serving()->create())->get('/settings/org-structure')
        ->assertInertia(fn ($page) => $page->where('importResult', null));
});

it('refuses a non-Admin import with 403 and calls Cav not at all (2.9, AD-6)', function () {
    oscFakeCav(MockResponse::make(['groups' => []]));

    $this->actingAs(User::factory()->serving()->create())->post('/settings/org-structure/import')->assertForbidden();

    MockClient::getGlobal()->assertNothingSent();
    expect(Unit::count())->toBe(0);
});

it('shows the Admin a clear error and writes nothing when Cav is down (2.9)', function () {
    oscFakeCav(MockResponse::make(['message' => 'down'], 502));

    $this->actingAs(User::factory()->admin()->create())
        ->from('/settings/org-structure')
        ->post('/settings/org-structure/import')
        ->assertRedirect('/settings/org-structure')
        ->assertSessionHasErrors(['import' => 'Could not read the Cav position groups: Cav position groups fetch failed (HTTP 502).']);

    expect(Unit::count())->toBe(0)->and(AuditLogEntry::count())->toBe(0);
});

it('requires authentication to import', function () {
    $this->post('/settings/org-structure/import')->assertRedirect(route('login'));
});

it('turns a blocked delete of a mapped section into errors.delete, not a 500 (2.9)', function () {
    $section = app(UnitRepository::class)->create(UnitType::Section, '1st Section', '1/1/A/1-7', null);
    UnitBilletMapping::factory()->create(['unit_id' => $section->id]);

    $this->actingAs(User::factory()->admin()->create())
        ->from('/settings/org-structure')
        ->delete("/settings/org-structure/{$section->id}")
        ->assertRedirect('/settings/org-structure')
        ->assertSessionHasErrors('delete');

    expect(Unit::find($section->id))->not->toBeNull();
});

it('turns a delete blocked by a staff grant or report history into errors.delete, not a 500', function (Closure $attach, string $reason) {
    $section = app(UnitRepository::class)->create(UnitType::Section, '1st Section', '1/1/A/1-7', null);
    $attach($section);

    $this->actingAs(User::factory()->admin()->create())
        ->from('/settings/org-structure')
        ->delete("/settings/org-structure/{$section->id}")
        ->assertRedirect('/settings/org-structure')
        ->assertSessionHasErrors(['delete' => "Cannot delete \"1st Section\": {$reason}"]);

    expect(Unit::find($section->id))->not->toBeNull();
})->with([
    'a staff grant' => [
        fn (Unit $unit) => PortalRoleGrant::factory()->create(['unit_id' => $unit->id]),
        'staff grants still give users scope over it. Revoke them in Staff grants first.',
    ],
    'a recorded practice' => [
        fn (Unit $unit) => app(SectionPracticeRepository::class)->findOrCreate($unit->id, WeekOf::start(CarbonImmutable::now())),
        'it has recorded attendance, and that history must be kept.',
    ],
    'a weekly report' => [
        fn (Unit $unit) => app(WeeklyReportRepository::class)->saveDraft($unit->id, WeekOf::start(CarbonImmutable::now()), [], null),
        'it has weekly reports, and that report history must be kept.',
    ],
]);

it('turns a delete of a company holding template versions into errors.delete, not a 500 (3.5)', function () {
    $admin = User::factory()->admin()->create();
    $company = app(UnitRepository::class)->create(UnitType::Company, 'Alpha Company', 'A/1-7', null);
    app(TemplateVersionRepository::class)->author($company->id, ArtifactType::RollcallBbcode, '[b]{section}[/b]', $admin->id);

    $this->actingAs($admin)
        ->from('/settings/org-structure')
        ->delete("/settings/org-structure/{$company->id}")
        ->assertRedirect('/settings/org-structure')
        ->assertSessionHasErrors(['delete' => 'Cannot delete "Alpha Company": it has template versions, and that template history must be kept.']);

    expect(Unit::find($company->id))->not->toBeNull();
});
