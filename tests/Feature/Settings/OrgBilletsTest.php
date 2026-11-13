<?php

use App\Commands\Units\AcknowledgeBilletConflict;
use App\Commands\Units\AssignBillet;
use App\Commands\Units\ConfirmBilletName;
use App\Commands\Units\ImportOrgStructure;
use App\Commands\Units\LeaveBilletUnplaced;
use App\Commands\Units\UnassignBillet;
use App\Commands\Units\UndoBilletAcknowledgement;
use App\Integration\Cav\CavApi;
use App\Models\AuditLogEntry;
use App\Models\CavBillet;
use App\Models\Trooper;
use App\Models\TrooperStatus;
use App\Models\Unit;
use App\Models\UnitBilletMapping;
use App\Models\UnitCommandMapping;
use App\Models\User;
use App\Sync\SyncRosterHandler;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

// The billets on Org structure (2026-11-13): each unit's billets, a placed billet the Cav renamed, and new billets
// nothing places, with the Admin's assign, move, confirm and leave-unplaced writes. Cav is faked with the trimmed live
// fixture of position groups.

/** The fixture's groups, with `$rename` position ids given a new title. */
function obGroups(array $rename = []): array
{
    $groups = json_decode(file_get_contents(base_path('tests/Fixtures/cav/position-groups.json')), true, flags: JSON_THROW_ON_ERROR);

    foreach ($groups['groups'] as &$group) {
        foreach ($group['positions'] as &$position) {
            $position['positionTitle'] = $rename[(int) $position['positionId']] ?? $position['positionTitle'];
        }
    }

    return $groups;
}

function obImport(array $rename = []): void
{
    MockClient::destroyGlobal();
    MockClient::global(['api.7cav.us/api/v1/milpacs/position/groups' => MockResponse::make(obGroups($rename))]);
    app(ImportOrgStructure::class)->handle(User::factory()->admin()->create());
}

/** A serving trooper's roster row, holding `$positionId`. */
function obRosterRow(int $positionId, string $title): array
{
    return [
        'user' => ['userId' => '3', 'username' => 'Jarvis.A'],
        'rank' => ['rankShort' => 'CPT', 'rankFull' => 'Captain', 'rankImageUrl' => '', 'rankId' => '4'],
        'realName' => 'Adam Jarvis', 'uniformUrl' => '', 'roster' => 'ROSTER_TYPE_COMBAT',
        'primary' => ['positionTitle' => $title, 'positionId' => (string) $positionId], 'secondaries' => [],
        'joinDate' => '2020-01-01', 'promotionDate' => '2022-01-01', 'discordId' => '', 'lastForumPostDate' => '',
        'mos' => '11B', 'consoleGamertag' => '',
    ];
}

function obUnit(string $callsign): Unit
{
    return Unit::query()->where('callsign', $callsign)->orderBy('id')->firstOrFail();
}

/** The billets prop of the Org structure page as the user sees it. */
function obBillets(User $user): ?array
{
    $props = test()->actingAs($user)->get('/settings/org-structure')->assertOk()->viewData('page')['props'];

    return $props['billets'] === null ? null : json_decode(json_encode($props['billets']), true);
}

beforeEach(function () {
    app()->instance(CavApi::class, new CavApi(['base_url' => 'https://api.7cav.us', 'api_key' => 'cav7_test-key']));
});

it('lists each unit\'s billets, with how many hold them now and why they land there, to an Admin only', function () {
    obImport();
    Trooper::factory()->create(['cav_position_id' => 201, 'status' => TrooperStatus::Combat]);
    Trooper::factory()->create(['cav_position_id' => 201, 'status' => TrooperStatus::Inactive]);

    $billets = obBillets(User::factory()->admin()->create());
    $section = collect($billets['byUnit'][obUnit('1/1/A/1-7')->id])->keyBy('positionId');
    $company = collect($billets['byUnit'][obUnit('A/1-7')->id])->keyBy('positionId');

    expect($section[201])->toMatchArray(['title' => 'Section Leader 1/1/A/1-7', 'holders' => 1, 'source' => 'rule', 'rule' => 'line billet in A/1-7', 'movable' => true, 'renamedFrom' => null])
        // A company command billet has no placement of its own: its holder lands in the company it commands.
        ->and($company[196])->toMatchArray(['title' => 'Commander A/1-7', 'source' => 'command', 'movable' => true, 'removable' => false])
        ->and(obBillets(User::factory()->serving()->create()))->toBeNull();
});

it('flags a placed billet the Cav renamed with its old and new name, keeps it placed, and lets an Admin confirm it', function () {
    obImport();
    $admin = User::factory()->admin()->create();
    $section = obUnit('1/1/A/1-7');
    $before = test()->actingAs($admin)->get('/settings/org-structure')->viewData('page')['props']['orgBilletsPending'];

    obImport([202 => 'Assistant Section Leader 1/1/A/1-7 (Acting)']);

    $billets = obBillets($admin);
    expect($billets['renamed'])->toHaveCount(1)
        ->and($billets['renamed'][0])->toMatchArray([
            'positionId' => 202, 'title' => 'Assistant Section Leader 1/1/A/1-7 (Acting)',
            'renamedFrom' => 'Assistant Section Leader 1/1/A/1-7', 'unitId' => $section->id, 'unitLabel' => '1/1/A/1-7',
        ])
        // The placement stays where it was: a rename never drops or moves it.
        ->and(UnitBilletMapping::query()->where('cav_position_id', 202)->sole()->unit_id)->toBe($section->id)
        ->and(test()->actingAs($admin)->get('/settings/org-structure')->viewData('page')['props']['orgBilletsPending'])->toBe($before + 1);

    test()->actingAs($admin)->post('/settings/org-structure/billets/202/confirm-name')->assertRedirect(route('settings.org-structure'));

    expect(obBillets($admin)['renamed'])->toBe([])
        ->and(CavBillet::query()->where('cav_position_id', 202)->sole()->confirmed_title)->toBe('Assistant Section Leader 1/1/A/1-7 (Acting)')
        ->and(AuditLogEntry::query()->where('action', ConfirmBilletName::ACTION)->where('actor_id', $admin->id)->count())->toBe(1);

    test()->actingAs($admin)->post('/settings/org-structure/billets/202/confirm-name')->assertSessionHasErrors('billet');
});

it('lists new billets nothing places, leaving out command billets and groups of troopers who no longer serve', function () {
    obImport();
    $new = collect(obBillets(User::factory()->admin()->create())['new'])->keyBy('positionId');

    // The Start-Up Games billets: no rule places them.
    expect($new->keys()->all())->toContain(952, 780, 781)
        ->and($new[952])->toMatchArray(['title' => 'War of Rights Start-Up', 'group' => 'Start-Up Games', 'holders' => 0])
        // Retired, and a command billet, and a placed one, are not new.
        ->and($new->has(525))->toBeFalse()
        ->and($new->has(196))->toBeFalse()
        ->and($new->has(201))->toBeFalse();
});

it('lets an Admin assign a new billet to a unit, audited, and keeps it there on the next import', function () {
    obImport();
    $admin = User::factory()->admin()->create();
    $company = obUnit('A/1-7');

    test()->actingAs($admin)->post('/settings/org-structure/billets/952/assign', ['unit_id' => $company->id, 'reason' => 'War of Rights start-up runs out of Alpha'])
        ->assertRedirect(route('settings.org-structure'));

    $mapping = UnitBilletMapping::query()->where('cav_position_id', 952)->sole();
    $entry = AuditLogEntry::query()->where('action', AssignBillet::ACTION)->sole();
    expect([$mapping->unit_id, $mapping->imported_unit_id])->toBe([$company->id, null])
        ->and(collect(obBillets($admin)['new'])->pluck('positionId'))->not->toContain(952)
        ->and(collect(obBillets($admin)['byUnit'][$company->id])->firstWhere('positionId', 952))->toMatchArray(['source' => 'admin', 'rule' => null])
        ->and($entry->reason)->toBe('War of Rights start-up runs out of Alpha')
        ->and($entry->details['subject'])->toBe('Billet "War of Rights Start-Up" in A/1-7')
        ->and($entry->details['changes'])->toBe([['field' => 'Unit', 'from' => 'none', 'to' => 'A/1-7']]);

    obImport();

    expect(UnitBilletMapping::query()->where('cav_position_id', 952)->sole()->unit_id)->toBe($company->id);
});

it('moves a placed billet to another unit, which the next import keeps as set by hand', function () {
    obImport();
    $admin = User::factory()->admin()->create();
    $from = obUnit('1/1/A/1-7');
    $to = obUnit('2/1/A/1-7');

    test()->actingAs($admin)->post('/settings/org-structure/billets/203/assign', ['unit_id' => $to->id, 'reason' => 'Cross-trained'])
        ->assertRedirect(route('settings.org-structure'));

    expect(UnitBilletMapping::query()->where('cav_position_id', 203)->sole()->only(['unit_id', 'imported_unit_id']))->toBe(['unit_id' => $to->id, 'imported_unit_id' => $from->id])
        ->and(AuditLogEntry::query()->where('action', AssignBillet::ACTION)->sole()->details['changes'])->toBe([['field' => 'Unit', 'from' => '1/1/A/1-7', 'to' => '2/1/A/1-7']]);

    obImport();

    expect(UnitBilletMapping::query()->where('cav_position_id', 203)->sole()->unit_id)->toBe($to->id);
});

it('places a command billet by an Admin\'s explicit placement, which beats its command in the roster sync and the import', function () {
    obImport();
    $admin = User::factory()->admin()->create();
    $company = obUnit('A/1-7');
    $elsewhere = obUnit('B/1-7');

    test()->actingAs($admin)->post('/settings/org-structure/billets/196/assign', ['unit_id' => $elsewhere->id, 'reason' => 'Runs Bravo for now'])
        ->assertRedirect(route('settings.org-structure'));

    MockClient::destroyGlobal();
    MockClient::global([
        'api.7cav.us/api/v1/roster/ROSTER_TYPE_COMBAT/lite' => MockResponse::make(['profiles' => ['1' => obRosterRow(196, 'Commander A/1-7')]]),
        'api.7cav.us/api/v1/roster/ROSTER_TYPE_RESERVE/lite' => MockResponse::make(['profiles' => []]),
        'api.7cav.us/api/v1/roster/ROSTER_TYPE_ELOA/lite' => MockResponse::make(['profiles' => []]),
    ]);
    app(SyncRosterHandler::class)->handle();

    expect(Trooper::query()->where('cav_id', 1)->sole()->primary_section_id)->toBe($elsewhere->id);

    // The import keeps the explicit placement, and the command still names the company it commands.
    obImport();
    expect(UnitBilletMapping::query()->where('cav_position_id', 196)->sole()->unit_id)->toBe($elsewhere->id)
        ->and(UnitCommandMapping::query()->where('cav_position_id', 196)->sole()->unit_id)->toBe($company->id);

    // Both units warn about it, and say which one the sync uses.
    $billets = obBillets($admin);
    $conflict = collect($billets['conflicts'])->firstWhere('kind', 'rule');
    expect($conflict['title'])->toBe('Commander A/1-7')
        ->and(collect($conflict['units'])->map(fn ($at) => "{$at['label']}:{$at['source']}")->all())->toBe(['B/1-7:admin', 'A/1-7:command'])
        ->and($conflict['uses'])->toBe('The sync uses B/1-7, where an Admin placed it.')
        ->and($billets['conflictsByUnit'][$company->id])->toHaveCount(1)
        ->and($billets['conflictsByUnit'][$elsewhere->id])->toHaveCount(1);
});

it('warns when billets of one name land in two units, and counts it for Admins', function () {
    obImport();
    $admin = User::factory()->admin()->create();
    $count = fn () => test()->actingAs($admin)->get('/settings/org-structure')->viewData('page')['props']['orgBilletsPending'];
    $before = $count();

    // Two billets the Cav names alike (as the two Deputy Chiefs of Staff), each placed in its own unit.
    CavBillet::query()->where('cav_position_id', 204)->update(['title' => 'Section Leader 1/1/A/1-7']);

    $conflict = collect(obBillets($admin)['conflicts'])->firstWhere('kind', 'title');
    expect($conflict['title'])->toBe('Section Leader 1/1/A/1-7')
        ->and(collect($conflict['units'])->pluck('positionId')->all())->toBe([201, 204])
        ->and($count())->toBe($before + 2);
});

it('asks before moving a billet an Admin placed in another unit, then moves every billet of that name', function () {
    obImport();
    $admin = User::factory()->admin()->create();
    $first = obUnit('A/1-7');
    $second = obUnit('B/1-7');

    test()->actingAs($admin)->post('/settings/org-structure/billets/952/assign', ['unit_id' => $first->id, 'reason' => 'x'])->assertSessionHasNoErrors();
    test()->actingAs($admin)->post('/settings/org-structure/billets/952/assign', ['unit_id' => $second->id, 'reason' => 'x'])
        ->assertSessionHasErrors(['billet_move' => 'War of Rights Start-Up is already placed in A/1-7. Move it here instead?']);

    expect(UnitBilletMapping::query()->where('cav_position_id', 952)->sole()->unit_id)->toBe($first->id);

    test()->actingAs($admin)->post('/settings/org-structure/billets/952/assign', ['unit_id' => $second->id, 'reason' => 'x', 'confirm_move' => true])
        ->assertSessionHasNoErrors();

    expect(UnitBilletMapping::query()->where('cav_position_id', 952)->sole()->unit_id)->toBe($second->id)
        ->and(AuditLogEntry::query()->where('action', AssignBillet::ACTION)->count())->toBe(2);
});

it('asks before placing a billet in a unit of another level than its title names, and places it once confirmed', function () {
    obImport();
    $admin = User::factory()->admin()->create();
    $section = obUnit('1/1/A/1-7');
    $company = obUnit('A/1-7');

    test()->actingAs($admin)->post('/settings/org-structure/billets/203/assign', ['unit_id' => $company->id, 'reason' => 'Company runner'])
        ->assertSessionHasErrors(['billet_level' => 'Trooper 1/1/A/1-7 is a section billet. Place it in A/1-7, a company, anyway?']);

    expect(UnitBilletMapping::query()->where('cav_position_id', 203)->sole()->unit_id)->toBe($section->id)
        ->and(AuditLogEntry::query()->where('action', AssignBillet::ACTION)->exists())->toBeFalse();

    test()->actingAs($admin)->post('/settings/org-structure/billets/203/assign', ['unit_id' => $company->id, 'reason' => 'Company runner', 'confirm_level' => true])
        ->assertSessionHasNoErrors();

    expect(UnitBilletMapping::query()->where('cav_position_id', 203)->sole()->unit_id)->toBe($company->id)
        ->and(AuditLogEntry::query()->where('action', AssignBillet::ACTION)->sole()->reason)->toBe('Company runner');
});

it('asks for each billet level placed higher or lower than its title names', function (int $positionId, string $unit, string $message) {
    obImport();

    test()->actingAs(User::factory()->admin()->create())->post("/settings/org-structure/billets/{$positionId}/assign", ['unit_id' => obUnit($unit)->id, 'reason' => 'x'])
        ->assertSessionHasErrors(['billet_level' => $message]);

    expect(AuditLogEntry::query()->where('action', AssignBillet::ACTION)->exists())->toBeFalse();
})->with([
    'a section billet in a platoon' => [201, '1/A/1-7', 'Section Leader 1/1/A/1-7 is a section billet. Place it in 1/A/1-7, a platoon, anyway?'],
    'a section billet in a battalion' => [201, '1-7', 'Section Leader 1/1/A/1-7 is a section billet. Place it in 1-7, a battalion, anyway?'],
    'a platoon billet in a section' => [199, '1/1/A/1-7', 'Platoon Leader 1/A/1-7 is a platoon billet. Place it in 1/1/A/1-7, a section, anyway?'],
    'a platoon billet in a company' => [200, 'A/1-7', 'Platoon Sergeant 1/A/1-7 is a platoon billet. Place it in A/1-7, a company, anyway?'],
    'a company billet in a platoon' => [198, '1/A/1-7', 'First Sergeant A/1-7 is a company billet. Place it in 1/A/1-7, a platoon, anyway?'],
    'a company billet in a battalion' => [196, '1-7', 'Commander A/1-7 is a company billet. Place it in 1-7, a battalion, anyway?'],
    'a battalion billet in a company' => [178, 'A/1-7', '1-7 Commanding Officer is a battalion billet. Place it in A/1-7, a company, anyway?'],
    'a battalion billet in the regiment' => [180, '7CAV', '1-7 Sergeant Major is a battalion billet. Place it in 7CAV, the regiment, anyway?'],
]);

it('places a billet in a unit of the level its title names, and a billet that names no level anywhere, without asking', function () {
    obImport();
    $admin = User::factory()->admin()->create();

    test()->actingAs($admin)->post('/settings/org-structure/billets/203/assign', ['unit_id' => obUnit('2/1/A/1-7')->id, 'reason' => 'x'])->assertSessionHasNoErrors();
    test()->actingAs($admin)->post('/settings/org-structure/billets/199/assign', ['unit_id' => obUnit('2/A/1-7')->id, 'reason' => 'x'])->assertSessionHasNoErrors();
    test()->actingAs($admin)->post('/settings/org-structure/billets/197/assign', ['unit_id' => obUnit('B/1-7')->id, 'reason' => 'x'])->assertSessionHasNoErrors();
    test()->actingAs($admin)->post('/settings/org-structure/billets/179/assign', ['unit_id' => obUnit('2-7')->id, 'reason' => 'x'])->assertSessionHasNoErrors();
    // Its words say Section Leader, but its title names no section.
    test()->actingAs($admin)->post('/settings/org-structure/billets/780/assign', ['unit_id' => obUnit('A/1-7')->id, 'reason' => 'x'])->assertSessionHasNoErrors();
    // Shaped like `1-7 Executive Officer`, but the regiment is no numbered battalion.
    test()->actingAs($admin)->post('/settings/org-structure/billets/2/assign', ['unit_id' => obUnit('1-7')->id, 'reason' => 'x'])->assertSessionHasNoErrors();

    expect(AuditLogEntry::query()->where('action', AssignBillet::ACTION)->count())->toBe(6);
});

it('still takes confirm_section, the older name of confirm_level', function () {
    obImport();
    $company = obUnit('A/1-7');

    test()->actingAs(User::factory()->admin()->create())->post('/settings/org-structure/billets/203/assign', ['unit_id' => $company->id, 'reason' => 'x', 'confirm_section' => true])
        ->assertSessionHasNoErrors();

    expect(UnitBilletMapping::query()->where('cav_position_id', 203)->sole()->unit_id)->toBe($company->id);
});

it('asks for the move and the billet level in turn, keeping what was confirmed', function () {
    obImport();
    $admin = User::factory()->admin()->create();
    $other = obUnit('2/1/A/1-7');
    $company = obUnit('B/1-7');

    test()->actingAs($admin)->post('/settings/org-structure/billets/203/assign', ['unit_id' => $other->id, 'reason' => 'x'])->assertSessionHasNoErrors();
    // Placed by an Admin in 2/1/A/1-7, so going to B/1-7 is both a move and a section billet in a company.
    test()->actingAs($admin)->post('/settings/org-structure/billets/203/assign', ['unit_id' => $company->id, 'reason' => 'x'])
        ->assertSessionHasErrors('billet_level');
    test()->actingAs($admin)->post('/settings/org-structure/billets/203/assign', ['unit_id' => $company->id, 'reason' => 'x', 'confirm_level' => true])
        ->assertSessionHasErrors(['billet_move' => 'Trooper 1/1/A/1-7 is already placed in 2/1/A/1-7. Move it here instead?']);
    test()->actingAs($admin)->post('/settings/org-structure/billets/203/assign', ['unit_id' => $company->id, 'reason' => 'x', 'confirm_level' => true, 'confirm_move' => true])
        ->assertSessionHasNoErrors();

    expect(UnitBilletMapping::query()->where('cav_position_id', 203)->sole()->unit_id)->toBe($company->id);
});

it('removes an Admin\'s placement: back to the rule, or no placement for a billet no rule places', function () {
    obImport();
    $admin = User::factory()->admin()->create();
    $section = obUnit('1/1/A/1-7');

    test()->actingAs($admin)->post('/settings/org-structure/billets/203/assign', ['unit_id' => obUnit('2/1/A/1-7')->id, 'reason' => 'x']);
    test()->actingAs($admin)->post('/settings/org-structure/billets/952/assign', ['unit_id' => obUnit('A/1-7')->id, 'reason' => 'x']);

    test()->actingAs($admin)->post('/settings/org-structure/billets/203/unassign', ['reason' => 'Back to the Cav'])->assertSessionHasNoErrors();
    test()->actingAs($admin)->post('/settings/org-structure/billets/952/unassign', ['reason' => 'Not Alpha after all'])->assertSessionHasNoErrors();

    $entries = AuditLogEntry::query()->where('action', UnassignBillet::ACTION)->orderBy('id')->get();
    expect(UnitBilletMapping::query()->where('cav_position_id', 203)->sole()->unit_id)->toBe($section->id)
        ->and(UnitBilletMapping::query()->where('cav_position_id', 952)->exists())->toBeFalse()
        ->and(collect(obBillets($admin)['new'])->pluck('positionId'))->toContain(952)
        ->and($entries->map(fn ($entry) => $entry->details['changes'])->all())->toBe([
            [['field' => 'Unit', 'from' => '2/1/A/1-7', 'to' => '1/1/A/1-7']],
            [['field' => 'Unit', 'from' => 'A/1-7', 'to' => 'none']],
        ]);

    // A rule's placement is not removed by hand.
    test()->actingAs($admin)->post('/settings/org-structure/billets/201/unassign', ['reason' => 'x'])->assertSessionHasErrors('billet');
});

it('refuses to place a billet in the unit it is in already, or without a reason', function () {
    obImport();
    $admin = User::factory()->admin()->create();

    test()->actingAs($admin)->post('/settings/org-structure/billets/201/assign', ['unit_id' => obUnit('1/1/A/1-7')->id, 'reason' => 'x'])
        ->assertSessionHasErrors(['billet' => 'This billet is already placed in 1/1/A/1-7.']);
    test()->actingAs($admin)->post('/settings/org-structure/billets/952/assign', ['unit_id' => obUnit('A/1-7')->id])
        ->assertSessionHasErrors('reason');

    expect(AuditLogEntry::query()->where('action', AssignBillet::ACTION)->exists())->toBeFalse();
});

it('leaves a new billet unplaced until the Cav renames it', function () {
    obImport();
    $admin = User::factory()->admin()->create();

    test()->actingAs($admin)->post('/settings/org-structure/billets/952/leave-unplaced', ['reason' => 'Not a unit billet'])
        ->assertRedirect(route('settings.org-structure'));

    expect(collect(obBillets($admin)['new'])->pluck('positionId'))->not->toContain(952)
        ->and(AuditLogEntry::query()->where('action', LeaveBilletUnplaced::ACTION)->sole()->reason)->toBe('Not a unit billet');

    obImport([952 => 'War of Rights Start-Up Lead']);

    expect(collect(obBillets($admin)['new'])->pluck('positionId'))->toContain(952);

    test()->actingAs($admin)->post('/settings/org-structure/billets/201/leave-unplaced', ['reason' => 'x'])->assertSessionHasErrors('billet');
});

it('forgets a billet the Cav no longer lists', function () {
    obImport();
    $groups = obGroups();
    $groups['groups'] = array_values(array_filter($groups['groups'], fn (array $group) => $group['groupTitle'] !== 'Start-Up Games'));
    MockClient::destroyGlobal();
    MockClient::global(['api.7cav.us/api/v1/milpacs/position/groups' => MockResponse::make($groups)]);
    app(ImportOrgStructure::class)->handle(User::factory()->admin()->create());

    expect(CavBillet::query()->whereIn('cav_position_id', [952, 780, 781])->exists())->toBeFalse()
        ->and(CavBillet::query()->where('cav_position_id', 201)->exists())->toBeTrue();
});

it('refuses every billet write to anyone but an Admin', function (string $path) {
    obImport();

    test()->actingAs(User::factory()->serving()->create())->post($path, ['unit_id' => obUnit('A/1-7')->id, 'reason' => 'x'])->assertForbidden();
})->with([
    'assign' => ['/settings/org-structure/billets/952/assign'],
    'confirm a name' => ['/settings/org-structure/billets/202/confirm-name'],
    'leave unplaced' => ['/settings/org-structure/billets/952/leave-unplaced'],
    'remove a placement' => ['/settings/org-structure/billets/952/unassign'],
    'acknowledge a billet in two units' => ['/settings/org-structure/billets/201/acknowledge'],
    'undo an acknowledgement' => ['/settings/org-structure/billets/201/unacknowledge'],
]);

/** Name billet 204 like 201, so one name sits in two sections at once, as the two Deputy Chiefs of Staff do. */
function obDoubled(): void
{
    CavBillet::query()->where('cav_position_id', 204)->update(['title' => 'Section Leader 1/1/A/1-7']);
}

function obPending(User $admin): int
{
    return test()->actingAs($admin)->get('/settings/org-structure')->viewData('page')['props']['orgBilletsPending'];
}

it('lets an Admin acknowledge a billet in two units, which leaves the notices and the count but stays marked on the units', function () {
    obImport();
    $admin = User::factory()->admin()->create();
    obDoubled();
    $before = obPending($admin);

    test()->actingAs($admin)->post('/settings/org-structure/billets/204/acknowledge', ['kind' => 'title', 'reason' => 'Both Deputy Chiefs of Staff hold this title'])
        ->assertRedirect(route('settings.org-structure'))
        ->assertSessionHasNoErrors();

    $billets = obBillets($admin);
    $marked = collect($billets['conflictsByUnit'][obUnit('1/1/A/1-7')->id])->firstWhere('kind', 'title');
    $entry = AuditLogEntry::query()->where('action', AcknowledgeBilletConflict::ACTION)->sole();
    expect(collect($billets['conflicts'])->where('kind', 'title'))->toBeEmpty()
        ->and($marked)->toMatchArray(['acknowledged' => true, 'positionId' => 201])
        ->and(obPending($admin))->toBe($before - 1)
        ->and($entry->actor_id)->toBe($admin->id)
        ->and($entry->reason)->toBe('Both Deputy Chiefs of Staff hold this title')
        ->and($entry->details['subject'])->toBe('Billet "Section Leader 1/1/A/1-7" in two units at once')
        ->and($entry->details['changes'])->toBe([['field' => 'Acknowledged', 'from' => 'no', 'to' => 'yes']]);

    test()->actingAs($admin)->post('/settings/org-structure/billets/201/acknowledge', ['kind' => 'title', 'reason' => 'x'])
        ->assertSessionHasErrors(['billet' => 'This billet in two units is acknowledged already.']);
});

it('shows an acknowledged billet in two units again once where it sits changes', function () {
    obImport();
    $admin = User::factory()->admin()->create();
    obDoubled();
    $before = obPending($admin);

    test()->actingAs($admin)->post('/settings/org-structure/billets/204/acknowledge', ['kind' => 'title', 'reason' => 'Meant to be'])->assertSessionHasNoErrors();
    expect(obPending($admin))->toBe($before - 1);

    // One of the two moves to a third unit: not what was acknowledged.
    test()->actingAs($admin)->post('/settings/org-structure/billets/204/assign', ['unit_id' => obUnit('B/1-7')->id, 'reason' => 'Moved', 'confirm_level' => true])->assertSessionHasNoErrors();

    $conflict = collect(obBillets($admin)['conflicts'])->firstWhere('kind', 'title');
    expect($conflict)->not->toBeNull()
        ->and($conflict['acknowledged'])->toBeFalse()
        ->and(collect($conflict['units'])->pluck('label')->all())->toBe(['1/1/A/1-7', 'B/1-7']);

    // Acknowledged again as it sits now, then a third billet takes the name (in any case): back again.
    test()->actingAs($admin)->post('/settings/org-structure/billets/204/acknowledge', ['kind' => 'title', 'reason' => 'Still meant to be'])->assertSessionHasNoErrors();
    expect(collect(obBillets($admin)['conflicts'])->where('kind', 'title'))->toBeEmpty();

    CavBillet::query()->where('cav_position_id', 203)->update(['title' => 'section leader 1/1/a/1-7 ']);

    $back = collect(obBillets($admin)['conflicts'])->firstWhere('kind', 'title');
    expect($back['acknowledged'])->toBeFalse()
        ->and(collect($back['units'])->pluck('positionId')->all())->toBe([201, 203, 204]);
});

it('lets an Admin undo an acknowledgement, with a reason, which brings the warning back', function () {
    obImport();
    $admin = User::factory()->admin()->create();
    obDoubled();
    $before = obPending($admin);

    test()->actingAs($admin)->post('/settings/org-structure/billets/201/unacknowledge', ['kind' => 'title', 'reason' => 'x'])
        ->assertSessionHasErrors(['billet' => 'This billet in two units has no acknowledgement to undo. Reload the page.']);

    test()->actingAs($admin)->post('/settings/org-structure/billets/204/acknowledge', ['kind' => 'title', 'reason' => 'Meant to be'])->assertSessionHasNoErrors();
    test()->actingAs($admin)->post('/settings/org-structure/billets/201/unacknowledge', ['kind' => 'title', 'reason' => 'One of them is moving'])
        ->assertRedirect(route('settings.org-structure'))
        ->assertSessionHasNoErrors();

    $entry = AuditLogEntry::query()->where('action', UndoBilletAcknowledgement::ACTION)->sole();
    expect(collect(obBillets($admin)['conflicts'])->firstWhere('kind', 'title')['acknowledged'])->toBeFalse()
        ->and(obPending($admin))->toBe($before)
        ->and($entry->reason)->toBe('One of them is moving')
        ->and($entry->details['changes'])->toBe([['field' => 'Acknowledged', 'from' => 'yes', 'to' => 'no']]);
});

it('refuses to acknowledge without a reason, or a billet in no conflict', function () {
    obImport();
    $admin = User::factory()->admin()->create();
    obDoubled();

    test()->actingAs($admin)->post('/settings/org-structure/billets/204/acknowledge', ['kind' => 'title'])->assertSessionHasErrors('reason');
    test()->actingAs($admin)->post('/settings/org-structure/billets/204/acknowledge', ['kind' => 'title', 'reason' => '   '])->assertSessionHasErrors('reason');
    test()->actingAs($admin)->post('/settings/org-structure/billets/204/unacknowledge', ['kind' => 'title'])->assertSessionHasErrors('reason');
    test()->actingAs($admin)->post('/settings/org-structure/billets/204/acknowledge', ['kind' => 'other', 'reason' => 'x'])->assertSessionHasErrors('kind');
    test()->actingAs($admin)->post('/settings/org-structure/billets/202/acknowledge', ['kind' => 'title', 'reason' => 'x'])
        ->assertSessionHasErrors(['billet' => 'This billet is not in two units at once any more. Reload the page.']);

    expect(AuditLogEntry::query()->where('action', AcknowledgeBilletConflict::ACTION)->exists())->toBeFalse();
});

it('counts the billets waiting on Org structure for an Admin only', function () {
    obImport();

    $count = fn (User $user) => test()->actingAs($user)->get('/settings/org-structure')->viewData('page')['props']['orgBilletsPending'];

    expect($count(User::factory()->admin()->create()))->toBe(count(obBillets(User::factory()->admin()->create())['new']))
        ->and($count(User::factory()->admin()->create()))->toBeGreaterThan(0)
        ->and($count(User::factory()->serving()->create()))->toBe(0);
});

it('records, in words, the rule that places each billet', function () {
    obImport();

    expect(CavBillet::query()->where('cav_position_id', 8)->sole()->rule)->toBe('staff billet of S1')
        ->and(CavBillet::query()->where('cav_position_id', 2)->sole()->rule)->toBe('portfolio officer in Regimental HQ')
        ->and(CavBillet::query()->where('cav_position_id', 201)->sole()->rule)->toBe('line billet in A/1-7')
        ->and(CavBillet::query()->where('cav_position_id', 952)->sole()->rule)->toBeNull();
});
