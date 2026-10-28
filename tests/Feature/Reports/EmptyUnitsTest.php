<?php

use App\Kpi\Headcount;
use App\Kpi\Ratio;
use App\Kpi\ReportSnapshot;
use App\Kpi\UnitKpis;
use App\Models\Loa;
use App\Models\PortalRoleGrant;
use App\Models\Trooper;
use App\Models\TrooperStatus;
use App\Models\UnitType;
use App\Models\User;
use App\Models\WeeklyReport;
use App\Repositories\UnitRepository;
use App\Repositories\WeeklyReportRepository;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia;

// #442: a line unit with nobody serving in it or under it is left out of the report chain, the roster and Home until
// someone is placed in it. "Has troopers" is one definition, a headcount above zero (`Headcount`). "Now" is Thu 08Oct26,
// so the running week opens Sat 03Oct26. Fixture tree:
//   2-7 ─┬─ A/2-7 ─ 1/A/2-7 ─┬─ 1/1/A/2-7  (2 troopers)
//        │                   └─ 2/1/A/2-7  (empty)
//        └─ E/2-7 ─ 1/E/2-7 ─── 1/1/E/2-7  (empty, all the way down)

beforeEach(function () {
    CarbonImmutable::setTestNow('2026-10-08 16:20:30');
    $units = app(UnitRepository::class);
    $this->battalion = $units->create(UnitType::Battalion, '2nd Battalion', '2-7', null);
    $this->alpha = $units->create(UnitType::Company, 'Alpha Company', 'A/2-7', $this->battalion->id);
    $this->platoon = $units->create(UnitType::Platoon, '1st Platoon', '1/A/2-7', $this->alpha->id);
    $this->manned = $units->create(UnitType::Section, '1st Section', '1/1/A/2-7', $this->platoon->id);
    $this->emptySection = $units->create(UnitType::Section, '2nd Section', '2/1/A/2-7', $this->platoon->id);
    $this->echo = $units->create(UnitType::Company, 'Echo Company', 'E/2-7', $this->battalion->id);
    $this->echoPlatoon = $units->create(UnitType::Platoon, '1st Platoon', '1/E/2-7', $this->echo->id);
    $this->echoSection = $units->create(UnitType::Section, '1st Section', '1/1/E/2-7', $this->echoPlatoon->id);

    $this->troopers = Trooper::factory()->count(2)->create(['primary_section_id' => $this->manned->id]);
    $this->week = '2026-10-03';
    $this->weekStart = CarbonImmutable::parse('2026-10-03 00:00:00', 'UTC');
    $this->admin = User::factory()->admin()->create();
    $this->empty = fn () => [$this->emptySection->id, $this->echo->id, $this->echoPlatoon->id, $this->echoSection->id];
});

afterEach(fn () => CarbonImmutable::setTestNow());

function sendEmptyUnitsReport(int $unitId, CarbonImmutable $week, int $strength = 2): void
{
    $snapshot = (new ReportSnapshot(new UnitKpis($strength, new Ratio(1, 2), new Ratio(1, 2), 0, 0, 0), null))->toArray();
    app(WeeklyReportRepository::class)->send($unitId, $week, $snapshot, null, User::factory()->create()->id, 'digest', null);
}

/** @return list<int> the ids of every unit in a roll-up tree prop, depth-first */
function rollupTreeIds(array $nodes): array
{
    return collect($nodes)->flatMap(fn (array $node) => [$node['id'], ...rollupTreeIds($node['children'])])->all();
}

it('keeps an empty unit out of "Every unit has sent", "Sent without" and "Not in at send"', function () {
    $this->actingAs($this->admin)->get("/reports/{$this->battalion->id}/{$this->week}")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('children', 1)
            ->where('children.0.id', $this->alpha->id)
            ->where('missingChildren', [$this->alpha->id]));

    $this->actingAs($this->admin)->get("/reports/{$this->platoon->id}/{$this->week}")
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('children', 1)
            ->where('children.0.id', $this->manned->id)
            ->where('missingChildren', [$this->manned->id]));

    // Alpha sends: the battalion is waiting on nobody, so it sends complete, with nobody missing.
    sendEmptyUnitsReport($this->alpha->id, $this->weekStart);
    $this->actingAs($this->admin)->get("/reports/{$this->battalion->id}/{$this->week}")
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('children.0.status', 'sent')
            ->where('missingChildren', []));

    $this->actingAs($this->admin)->post("/reports/{$this->battalion->id}/{$this->week}/send", ['reason' => 'Weekly report'])
        ->assertSessionHasNoErrors();

    $sent = WeeklyReport::query()->where('unit_id', $this->battalion->id)->sole();
    expect(ReportSnapshot::fromArray($sent->kpis)->missingChildren)->toBe([]);

    $this->actingAs($this->admin)->get("/reports/{$this->battalion->id}/{$this->week}")
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('children', 1)
            ->where('children.0.status', 'included')
            ->where('missingChildren', []));
});

it('leaves an empty unit out of the roll-up workspace, so it holds nobody up', function () {
    sendEmptyUnitsReport($this->manned->id, $this->weekStart);
    sendEmptyUnitsReport($this->platoon->id, $this->weekStart);

    $this->actingAs($this->admin)->get('/reports')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('roots.0.id', $this->battalion->id)
            ->where('roots.0.coverage', ['sent' => 0, 'of' => 1, 'missing' => [$this->alpha->id]])
            ->where('roots.0.children.0.holding', false)
            ->where('roots.0.children.0.coverage', ['sent' => 1, 'of' => 1, 'missing' => []])
            ->where('roots.0', fn ($root) => array_intersect(rollupTreeIds([$root->toArray()]), ($this->empty)()) === []));
});

it('sends a leader of a unit whose units are all empty straight to its own report', function () {
    $grantee = User::factory()->serving()->create();
    PortalRoleGrant::factory()->create(['user_id' => $grantee->id, 'unit_id' => $this->echoPlatoon->id]);
    manUnits($this->echoPlatoon);

    $this->actingAs($grantee)->get('/reports')
        ->assertRedirect("/reports/{$this->echoPlatoon->id}/{$this->week}");
});

it('hides an empty unit from the roster\'s order of battle and from the unit picker', function () {
    $this->actingAs($this->admin)->get("/roster/{$this->battalion->id}")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('units', fn ($units) => collect($units)->pluck('id')->all() === [$this->battalion->id, $this->alpha->id, $this->platoon->id, $this->manned->id])
            ->where('scopeOptions', fn ($options) => collect($options)->pluck('id')->all() === [$this->battalion->id, $this->alpha->id, $this->platoon->id, $this->manned->id]));
});

it('still opens an empty unit on the roster when it is asked for by id', function () {
    $this->actingAs($this->admin)->get("/roster/{$this->echo->id}")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('unit.id', $this->echo->id)
            ->where('units', fn ($units) => collect($units)->pluck('id')->all() === [$this->echo->id]));
});

it('leaves an empty unit out of Home\'s progress, unit table and "Needs attention"', function () {
    $this->actingAs($this->admin)->get('/home')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('steps', fn ($steps) => collect($steps)->pluck('id')->all() === [$this->manned->id])
            ->where('units', fn ($units) => collect($units)->pluck('id')->all() === [$this->alpha->id])
            ->where('attention.items', fn ($items) => collect($items)->where('kind', 'holding')->pluck('unit.id')->all() === [$this->manned->id]));
});

it('draws a past week on Home\'s sparkline once every section with troopers sent it', function () {
    sendEmptyUnitsReport($this->manned->id, $this->weekStart->subWeek());

    $this->actingAs($this->admin)->get('/home')
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('kpis.history.3.week', '2026-09-26')
            ->whereNot('kpis.history.3.kpis', null));
});

it('shows and counts a unit again as soon as a trooper is placed in it by hand', function () {
    $recruit = Trooper::factory()->create(['primary_section_id' => null]);

    $this->actingAs($this->admin)
        ->post("/settings/org-structure/troopers/{$recruit->id}/placement", ['section_id' => $this->emptySection->id, 'reason' => 'Joined 2nd Section'])
        ->assertSessionHasNoErrors();

    $this->actingAs($this->admin)->get("/reports/{$this->platoon->id}/{$this->week}")
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('children', fn ($children) => collect($children)->pluck('id')->all() === [$this->manned->id, $this->emptySection->id])
            ->where('missingChildren', [$this->manned->id, $this->emptySection->id]));
    $this->actingAs($this->admin)->get("/roster/{$this->battalion->id}")
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('units', fn ($units) => collect($units)->pluck('id')->contains($this->emptySection->id)));
    $this->actingAs($this->admin)->get('/home')
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('steps', fn ($steps) => collect($steps)->pluck('id')->all() === [$this->manned->id, $this->emptySection->id]));
});

it('shows and counts a unit again as soon as the roster sync places a trooper anywhere under it', function () {
    // What the sync writes for a trooper whose billet maps to the section: their placement, nothing else.
    Trooper::factory()->create(['primary_section_id' => $this->echoSection->id]);

    $this->actingAs($this->admin)->get("/reports/{$this->battalion->id}/{$this->week}")
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('children', fn ($children) => collect($children)->pluck('id')->all() === [$this->alpha->id, $this->echo->id]));
    $this->actingAs($this->admin)->get("/roster/{$this->battalion->id}")
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('scopeOptions', fn ($options) => collect($options)->pluck('id')->contains($this->echoSection->id)));
});

it('keeps a unit listed as missing on a report sent before it was emptied', function () {
    $member = Trooper::factory()->create(['primary_section_id' => $this->emptySection->id]);
    $this->actingAs($this->admin)->post("/reports/{$this->platoon->id}/{$this->week}/send", ['reason' => 'Weekly report'])
        ->assertSessionHasNoErrors();

    // The section's only member leaves the Cav: it is empty from now on.
    $member->forceFill(['status' => TrooperStatus::Inactive])->save();

    $this->actingAs($this->admin)->get("/reports/{$this->platoon->id}/{$this->week}")
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('report.status', 'sent')
            ->where('missingChildren', [$this->manned->id, $this->emptySection->id])
            ->where('children.1.id', $this->emptySection->id)
            ->where('children.1.status', 'missing'));

    // A draft for the next week no longer waits on it.
    CarbonImmutable::setTestNow('2026-10-15 16:20:30');
    $this->actingAs($this->admin)->get("/reports/{$this->platoon->id}/2026-10-10")
        ->assertInertia(fn (AssertableInertia $page) => $page->has('children', 1)->where('missingChildren', [$this->manned->id]));
});

it('still counts a unit whose only members are on leave', function () {
    $onLeave = Trooper::factory()->create(['primary_section_id' => $this->emptySection->id]);
    Loa::factory()->create(['trooper_id' => $onLeave->id, 'starts_at' => '2026-10-01', 'ends_at' => '2026-10-20']);

    $this->actingAs($this->admin)->get("/reports/{$this->platoon->id}/{$this->week}")
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('children', fn ($children) => collect($children)->pluck('id')->all() === [$this->manned->id, $this->emptySection->id]));
    $this->actingAs($this->admin)->get("/roster/{$this->battalion->id}")
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('units', fn ($units) => collect($units)->pluck('id')->contains($this->emptySection->id)));
    $this->actingAs($this->admin)->get('/home')
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('steps', fn ($steps) => collect($steps)->pluck('id')->contains($this->emptySection->id)));
});

it('keeps listing every unit on Org structure, empty ones included', function () {
    $this->actingAs($this->admin)->get('/settings/org-structure')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('units', fn ($units) => collect($units)->pluck('id')->sort()->values()->all() === collect([
                $this->battalion, $this->alpha, $this->platoon, $this->manned, $this->emptySection, $this->echo, $this->echoPlatoon, $this->echoSection,
            ])->pluck('id')->sort()->values()->all()));
});

it('defines "has troopers" once: anyone serving in the unit or under it, HQ staff included, departed troopers not', function () {
    $headcount = app(Headcount::class);
    Trooper::factory()->create(['primary_section_id' => $this->echoSection->id, 'status' => TrooperStatus::Inactive]);
    // Echo's commander, placed on the company's HQ node.
    Trooper::factory()->create(['primary_section_id' => $this->echo->id]);

    expect($headcount->withoutEmptyLineUnits(app(UnitRepository::class)->tree())->pluck('id')->all())->toEqualCanonicalizing([
        $this->battalion->id, $this->alpha->id, $this->platoon->id, $this->manned->id, $this->echo->id,
    ]);
});
