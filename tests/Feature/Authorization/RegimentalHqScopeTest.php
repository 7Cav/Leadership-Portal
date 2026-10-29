<?php

use App\Authorization\ScopeResolver;
use App\Authorization\ViewAs;
use App\Authorization\ViewAsKind;
use App\Authorization\ViewAsTarget;
use App\Models\AdminBillet;
use App\Models\AdminBilletStatus;
use App\Models\ArtifactType;
use App\Models\AuditLogEntry;
use App\Models\CounselingRecord;
use App\Models\SectionPractice;
use App\Models\TemplateVersion;
use App\Models\Trooper;
use App\Models\TrooperStatus;
use App\Models\Unit;
use App\Models\UnitKind;
use App\Models\UnitType;
use App\Models\User;
use App\Models\WeeklyReport;
use App\Repositories\TemplateVersionRepository;
use App\Repositories\UnitRepository;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;

// Regimental HQ staff read the whole regiment (decided by the S6, 2026-10-28): a serving trooper placed directly on the
// regiment, where the org import puts every Regimental HQ billet (the Chief of Staff, the Adjutants General, the
// Finance Officer), reads everything in it as a staff grant on the regiment does, and writes nothing through it. Only
// while their position is still a Regimental HQ billet (audit 3, 2026-10-29): the import keeps an `admin_billets` row
// for each, and the roster sync leaves the placement as it was when a new billet maps nowhere.
// Fixture tree: 7CAV ─┬─ 1-7 ─ A/1-7 ─ 1/A/1-7 ─ 1/1/A/1-7
//                     ├─ 2-7 ─ B/2-7 ─ 1/B/2-7 ─ 1/1/B/2-7
//                     └─ ELOA, RES, NR, S6 (groups)

function rhqPlaced(?Unit $at, array $attributes = []): Trooper
{
    $trooper = Trooper::factory()->create($attributes);
    $trooper->forceFill(['primary_section_id' => $at?->id])->save();

    return $trooper;
}

/** The `admin_billets` row the org import makes for a Regimental HQ billet, still waiting for an Admin. */
function rhqBilletRow(int $positionId, string $title, string $group = 'Regimental HQ'): AdminBillet
{
    return AdminBillet::query()->create([
        'cav_position_id' => $positionId, 'proposed_title' => $title, 'proposed_group_title' => $group, 'status' => AdminBilletStatus::Pending,
    ]);
}

function rhqScope(User $user): array
{
    return app(ScopeResolver::class)->resolve($user)->all();
}

beforeEach(function () {
    // Thursday of the Cav week that opened Saturday 03Oct26.
    Carbon::setTestNow(CarbonImmutable::parse('2026-10-08 20:00:00', 'UTC'));
    $units = app(UnitRepository::class);
    $this->regiment = $units->create(UnitType::Regiment, '7th Cavalry Regiment', '7CAV', null);
    $this->battalion = $units->create(UnitType::Battalion, '1st Battalion', '1-7', $this->regiment->id);
    $this->company = $units->create(UnitType::Company, 'Alpha Company', 'A/1-7', $this->battalion->id);
    $platoon = $units->create(UnitType::Platoon, '1st Platoon', '1/A/1-7', $this->company->id);
    $this->section = $units->create(UnitType::Section, '1st Section', '1/1/A/1-7', $platoon->id);
    $this->otherBattalion = $units->create(UnitType::Battalion, '2nd Battalion', '2-7', $this->regiment->id);
    $otherCompany = $units->create(UnitType::Company, 'Bravo Company', 'B/2-7', $this->otherBattalion->id);
    $otherPlatoon = $units->create(UnitType::Platoon, '1st Platoon', '1/B/2-7', $otherCompany->id);
    $this->otherSection = $units->create(UnitType::Section, '1st Section', '1/1/B/2-7', $otherPlatoon->id);
    $this->groups = [
        'ELOA' => $units->create(UnitType::Group, 'Extended Leave of Absence', 'ELOA', $this->regiment->id, UnitKind::Eloa),
        'Reservists' => $units->create(UnitType::Group, 'Reservists', 'RES', $this->regiment->id, UnitKind::Reserve),
        'New Recruits' => $units->create(UnitType::Group, 'New Recruits', 'NR', $this->regiment->id, UnitKind::Recruit),
        'S6' => $units->create(UnitType::Group, 'S6', 'S6', $this->regiment->id, UnitKind::Staff),
    ];
    $this->allUnits = $units->allIds()->all();

    // The Chief of Staff: a Regimental HQ billet that commands nothing, placed on the regiment by the org import.
    $this->chiefTrooper = rhqPlaced($this->regiment, ['username' => 'Tharen.R', 'billet' => 'Regimental Chief of Staff', 'cav_position_id' => 9010]);
    $this->chiefBillet = rhqBilletRow(9010, 'Regimental Chief of Staff');
    $this->chief = User::factory()->create(['cav_id' => $this->chiefTrooper->cav_id, 'username' => 'Tharen.R']);

    $this->troopers = [
        'own battalion' => rhqPlaced($this->section, ['username' => 'Xqz.One']),
        'other battalion' => rhqPlaced($this->otherSection, ['username' => 'Xqz.Two']),
        'ELOA' => rhqPlaced($this->groups['ELOA'], ['username' => 'Xqz.Leave', 'status' => TrooperStatus::Eloa]),
        'unplaced' => rhqPlaced(null, ['username' => 'Xqz.Unplaced']),
    ];
});

it('gives Regimental HQ staff read scope over the whole regiment, and no write anywhere', function () {
    $scope = app(ScopeResolver::class);

    expect(rhqScope($this->chief))->toEqualCanonicalizing($this->allUnits)
        ->and($scope->writeScope($this->chief)->all())->toBe([])
        ->and($scope->commandsUnit($this->chief, $this->section))->toBeFalse()
        ->and($scope->leads($this->chief))->toBeFalse()
        ->and($scope->canSee($this->chief, $this->troopers['other battalion']))->toBeTrue()
        // Unplaced troopers stay Admin-only, as for a staff grant.
        ->and($scope->canSee($this->chief, $this->troopers['unplaced']))->toBeFalse();
});

it('gives the same read scope in bulk as one at a time, so the act as picker follows the rule too', function () {
    $scope = app(ScopeResolver::class);
    $eloa = User::factory()->create(['cav_id' => $this->troopers['ELOA']->cav_id]);
    $member = User::factory()->create(['cav_id' => $this->troopers['own battalion']->cav_id]);
    $users = collect([$this->chief, $eloa, $member]);

    $bulk = $scope->realScopes($users);

    foreach ($users as $user) {
        expect($bulk->get($user->id)->all())->toEqualCanonicalizing($scope->realScope($user)->all());
    }
    expect($bulk->get($this->chief->id)->all())->toEqualCanonicalizing($this->allUnits);

    // No longer serving: neither gives the regiment.
    $this->chiefTrooper->forceFill(['status' => TrooperStatus::Inactive])->save();
    expect($scope->realScopes(collect([$this->chief]))->get($this->chief->id)->all())->toBe([])
        ->and($scope->realScope($this->chief)->all())->toBe([]);
});

it('finds troopers across the regiment for Regimental HQ staff, and opens their milpac', function () {
    $names = collect($this->actingAs($this->chief)->getJson(route('troopers.search', ['q' => 'xqz']))->assertOk()->json('troopers'))
        ->pluck('name')->all();

    expect($names)->toEqualCanonicalizing(['Xqz.One', 'Xqz.Two', 'Xqz.Leave']);

    $this->actingAs($this->chief)->get(route('troopers.show', $this->troopers['other battalion']))->assertOk();
    $this->actingAs($this->chief)->get(route('troopers.show', $this->troopers['unplaced']))->assertNotFound();
});

it('offers Regimental HQ staff every unit to view as, and lets them view as one', function () {
    $this->actingAs($this->chief)->getJson('/view-as/options')
        ->assertOk()
        ->assertJsonCount(count($this->allUnits), 'units')
        ->assertJsonPath('people', null);

    $this->actingAs($this->chief)->post('/view-as', ['kind' => 'unit', 'id' => $this->otherBattalion->id])->assertSessionHasNoErrors();

    // A view as narrows: only the other battalion's trooper is left.
    $names = collect($this->getJson(route('troopers.search', ['q' => 'xqz']))->assertOk()->json('troopers'))->pluck('name')->all();
    expect($names)->toBe(['Xqz.Two']);
});

it('shows Regimental HQ staff the roster, the rollcall and the report of any unit, read-only', function () {
    $this->actingAs($this->chief)->get("/roster/{$this->regiment->id}")->assertOk();
    $this->actingAs($this->chief)->get("/roster/{$this->otherSection->id}")->assertOk();

    $this->actingAs($this->chief)->get("/rollcall/{$this->otherSection->id}")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Rollcall')->has('troopers', 1)->where('canWrite', false));

    $this->actingAs($this->chief)->get("/reports/{$this->otherSection->id}/2026-10-03")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Reports/Show')->where('canWrite', false));
});

it('refuses Regimental HQ staff every write, writing nothing', function (string $method, Closure $uri, Closure $body) {
    Queue::fake();
    $template = app(TemplateVersionRepository::class)->author($this->company->id, ArtifactType::RollcallBbcode, 'body', User::factory()->admin()->create()->id)->template;
    $this->template = $template;
    $versions = TemplateVersion::query()->count();

    $this->actingAs($this->chief)->json($method, $uri($this), $body($this))->assertForbidden();

    expect(SectionPractice::query()->count())->toBe(0)
        ->and(WeeklyReport::query()->count())->toBe(0)
        ->and(TemplateVersion::query()->count())->toBe($versions)
        ->and(AuditLogEntry::query()->count())->toBe(0);
    Queue::assertNothingPushed();
})->with([
    'mark attendance' => ['POST', fn ($t) => "/rollcall/{$t->section->id}", fn ($t) => ['week' => '2026-10-03', 'marks' => [['trooper_id' => $t->troopers['own battalion']->id, 'status' => 'present']]]],
    'mark rollcall posted' => ['POST', fn ($t) => "/rollcall/{$t->section->id}/posted", fn () => ['week' => '2026-10-03']],
    'save report draft' => ['PUT', fn ($t) => "/reports/{$t->section->id}/2026-10-03", fn () => ['notes' => 'staff notes']],
    'send report' => ['POST', fn ($t) => "/reports/{$t->section->id}/2026-10-03/send", fn () => ['reason' => 'staff send']],
    'edit a template' => ['POST', fn () => '/settings/templates', fn ($t) => ['template_id' => $t->template->id, 'body' => 'staff body', 'reason' => 'why']],
]);

it('ends with the placement or the service, with no step by an Admin', function () {
    expect(rhqScope($this->chief))->toEqualCanonicalizing($this->allUnits);

    // Left the Cav: the roster sync marks them inactive.
    $this->chiefTrooper->forceFill(['status' => TrooperStatus::Inactive])->save();
    expect(rhqScope($this->chief))->toBe([]);

    // Serving again, but moved off Regimental HQ to a section: a section billet that commands nothing reads nothing.
    $this->chiefTrooper->forceFill(['status' => TrooperStatus::Combat, 'primary_section_id' => $this->section->id])->save();
    expect(rhqScope($this->chief))->toBe([]);

    // Back on the regiment.
    $this->chiefTrooper->forceFill(['primary_section_id' => $this->regiment->id])->save();
    expect(rhqScope($this->chief))->toEqualCanonicalizing($this->allUnits);
});

it('ends when the position is no longer a Regimental HQ billet, though the sync leaves the placement on the regiment', function () {
    $lor = CounselingRecord::factory()->lor()->create(['trooper_id' => $this->troopers['other battalion']->id]);
    $scope = app(ScopeResolver::class);

    // A new billet the import has no mapping for (and so no row): the roster sync updates the position and the title
    // and keeps the placement, as SyncRosterHandler::resolveSections() leaves an unmapped billet out.
    $this->chiefTrooper->forceFill(['cav_position_id' => 4242, 'billet' => 'Rifleman'])->save();

    expect(rhqScope($this->chief))->toBe([])
        ->and($scope->realScopes(collect([$this->chief]))->get($this->chief->id)->all())->toBe([])
        ->and($scope->regimentalHqStaff()->pluck('id')->all())->toBe([]);
    $this->actingAs($this->chief)->get(route('troopers.show', $this->troopers['other battalion']))->assertNotFound();
    $this->actingAs($this->chief)->get(route('counseling.document', $lor->id))->assertNotFound();

    // Back on a Regimental HQ billet: read again.
    $this->chiefTrooper->forceFill(['cav_position_id' => 9010, 'billet' => 'Regimental Chief of Staff'])->save();
    expect(rhqScope($this->chief))->toEqualCanonicalizing($this->allUnits)
        ->and($scope->regimentalHqStaff()->pluck('id')->all())->toBe([$this->chiefTrooper->id]);
});

it('ends when Cav lists the billet under another group, approved or not', function (AdminBilletStatus $status) {
    $this->chiefBillet->forceFill(['status' => $status, 'proposed_group_title' => 'S1 Department'])->save();
    $scope = app(ScopeResolver::class);

    expect(rhqScope($this->chief))->toBe([])
        ->and($scope->realScopes(collect([$this->chief]))->get($this->chief->id)->all())->toBe([])
        ->and($scope->regimentalHqStaff()->all())->toBe([]);
})->with([AdminBilletStatus::Pending, AdminBilletStatus::Rejected]);

it('reads the regiment on a pending or a rejected Regimental HQ billet alike', function (AdminBilletStatus $status) {
    $this->chiefBillet->forceFill(['status' => $status])->save();

    expect(rhqScope($this->chief))->toEqualCanonicalizing($this->allUnits);
})->with([AdminBilletStatus::Pending, AdminBilletStatus::Rejected]);

it('reads the regiment when an older row kept stray spaces around the group name', function () {
    $this->chiefBillet->forceFill(['proposed_group_title' => ' Regimental HQ '])->save();

    expect(rhqScope($this->chief))->toEqualCanonicalizing($this->allUnits)
        ->and(app(ScopeResolver::class)->realScopes(collect([$this->chief]))->get($this->chief->id)->all())->toEqualCanonicalizing($this->allUnits);
});

it('gives nothing to holding groups and staff departments under the regiment', function (string $group) {
    $member = User::factory()->create(['cav_id' => rhqPlaced($this->groups[$group])->cav_id]);

    expect(rhqScope($member))->toBe([]);
})->with(['ELOA', 'Reservists', 'New Recruits', 'S6']);

it('gives nothing to someone without a milpac link', function () {
    expect(rhqScope(User::factory()->create()))->toBe([]);
});

it('leaves the Regimental Commanding Officer their write rights over the regiment', function () {
    $rco = User::factory()->commanding($this->regiment, ['primary_section_id' => $this->regiment->id])->create();
    $scope = app(ScopeResolver::class);

    expect(rhqScope($rco))->toEqualCanonicalizing($this->allUnits)
        ->and($scope->writeScope($rco)->all())->toEqualCanonicalizing($this->allUnits)
        ->and($scope->commandsUnit($rco, $this->otherSection))->toBeTrue();

    $this->actingAs($rco)->postJson("/rollcall/{$this->section->id}/posted", ['week' => '2026-10-03'])->assertOk();
    expect(SectionPractice::query()->pluck('section_id')->all())->toBe([$this->section->id]);
});

it('lets an Admin acting as the Chief of Staff see what they see, and write nothing', function () {
    $admin = User::factory()->admin()->create();
    $this->actingAs($admin)->post('/act-as', ['kind' => 'user', 'id' => $this->chief->id])->assertRedirect('/home');

    $names = collect($this->actingAs($admin)->getJson(route('troopers.search', ['q' => 'xqz']))->assertOk()->json('troopers'))
        ->pluck('name')->all();
    expect($names)->toEqualCanonicalizing(['Xqz.One', 'Xqz.Two', 'Xqz.Leave']);

    $this->actingAs($admin)->getJson('/view-as/options')->assertOk()->assertJsonCount(count($this->allUnits), 'units')->assertJsonPath('people', null);
    $this->actingAs($admin)->postJson("/rollcall/{$this->section->id}/posted", ['week' => '2026-10-03'])->assertForbidden();

    expect(SectionPractice::query()->count())->toBe(0);
});

it('lets an Admin view as the Chief of Staff and see the whole regiment, not as an Admin', function () {
    $admin = User::factory()->admin()->create();
    app(ViewAs::class)->enter($admin, new ViewAsTarget(ViewAsKind::User, $this->chief->id));
    $scope = app(ScopeResolver::class);

    expect($scope->resolve($admin)->all())->toEqualCanonicalizing($this->allUnits)
        ->and($scope->actsAsAdmin($admin))->toBeFalse()
        ->and($scope->canSee($admin, $this->troopers['other battalion']))->toBeTrue()
        ->and($scope->canSee($admin, $this->troopers['unplaced']))->toBeFalse();
});
