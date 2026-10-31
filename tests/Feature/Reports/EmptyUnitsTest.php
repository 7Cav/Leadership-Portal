<?php

use App\Kpi\ChainNode;
use App\Kpi\Headcount;
use App\Kpi\Ratio;
use App\Kpi\ReportChain;
use App\Kpi\ReportSnapshot;
use App\Kpi\RollupKpis;
use App\Kpi\UnitKpis;
use App\Models\Loa;
use App\Models\PlacementSource;
use App\Models\PortalRoleGrant;
use App\Models\Trooper;
use App\Models\TrooperStatus;
use App\Models\UnitType;
use App\Models\User;
use App\Models\WeeklyReport;
use App\Models\WeeklyReportStatus;
use App\Repositories\TrooperPlacementRepository;
use App\Repositories\TrooperRepository;
use App\Repositories\UnitRepository;
use App\Repositories\WeeklyReportRepository;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia;

// #442: a line unit with nobody serving in it or under it is left out of the report chain, the roster and Home until
// someone is placed in it. "Has troopers" is one definition, a headcount above zero (`Headcount`). "Now" is Thu 08OCT26,
// so the running week opens Sat 03OCT26. Fixture tree:
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

/** @return list<int> the ids of every unit in a report chain, depth-first */
function emptyUnitsChainIds(ChainNode $node): array
{
    return [$node->unit->id, ...collect($node->children)->flatMap(fn (ChainNode $child) => emptyUnitsChainIds($child))->all()];
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

it('keeps a section emptied by a move in its platoon\'s roll-up for the weeks it had troopers, and only those', function () {
    // The section sent last week's report, then the sync moved both its troopers out on Tuesday of this week.
    sendEmptyUnitsReport($this->manned->id, CarbonImmutable::parse('2026-09-26 00:00:00', 'UTC'));
    $changes = $this->troopers->mapWithKeys(fn (Trooper $t) => [$t->id => [$this->manned->id, null]])->all();
    app(TrooperPlacementRepository::class)->record($changes, PlacementSource::Sync, CarbonImmutable::parse('2026-10-06 12:00:00', 'UTC'));
    $this->troopers->each(fn (Trooper $t) => $t->forceFill(['primary_section_id' => null])->save());

    // Last week it sent, and this week it still had troopers until Tuesday: the platoon's drafts wait on it.
    foreach (['2026-09-26', $this->week] as $week) {
        $this->actingAs($this->admin)->get("/reports/{$this->platoon->id}/{$week}")
            ->assertInertia(fn (AssertableInertia $page) => $page->where('children.0.id', $this->manned->id));
    }

    // Next week it is empty from the start, so it is left out.
    CarbonImmutable::setTestNow('2026-10-15 16:20:30');
    $this->actingAs($this->admin)->get("/reports/{$this->platoon->id}/2026-10-10")
        ->assertInertia(fn (AssertableInertia $page) => $page->where('children', []));
});

it('keeps a section that sent its report in that week\'s roll-up after its last trooper leaves the Cav', function () {
    sendEmptyUnitsReport($this->manned->id, $this->weekStart);
    $this->troopers->each(fn (Trooper $t) => $t->forceFill(['status' => TrooperStatus::Inactive])->save());

    $this->actingAs($this->admin)->get("/reports/{$this->platoon->id}/{$this->week}")
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('children.0.id', $this->manned->id)
            ->where('children.0.status', 'sent'));
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

// #478: HQ staff are on no rollcall, so a unit whose only troopers are its HQ has no report to roll up. The roster and
// Home still show it; the report chain leaves it out like an empty unit.
it('leaves a platoon whose only troopers are its HQ out of its company\'s roll-up, so nobody waits on it', function () {
    $units = app(UnitRepository::class);
    $hqOnly = $units->create(UnitType::Platoon, '2nd Platoon', '2/A/2-7', $this->alpha->id);
    $units->create(UnitType::Section, '1st Section', '1/2/A/2-7', $hqOnly->id);
    // Its PL and PSG, placed on the platoon; its section is empty.
    manUnits($hqOnly, $hqOnly);

    expect(app(RollupKpis::class)->children($this->alpha, $this->weekStart)->modelKeys())->toBe([$this->platoon->id])
        ->and(app(RollupKpis::class)->children($hqOnly, $this->weekStart)->all())->toBe([])
        ->and(emptyUnitsChainIds(app(ReportChain::class)->build($this->battalion, $this->weekStart)))->not->toContain($hqOnly->id);

    $this->actingAs($this->admin)->get("/reports/{$this->alpha->id}/{$this->week}")
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('children', fn ($children) => collect($children)->pluck('id')->all() === [$this->platoon->id])
            ->where('missingChildren', [$this->platoon->id]));

    // Its own report has nothing to roll up, and cannot be sent.
    $this->actingAs($this->admin)->get("/reports/{$hqOnly->id}/{$this->week}")
        ->assertInertia(fn (AssertableInertia $page) => $page->where('kpiError', 'No unit under this one has troopers in it, so there are no reports to roll up.'));
    $this->actingAs($this->admin)->post("/reports/{$hqOnly->id}/{$this->week}/send", ['reason' => 'Weekly report'])
        ->assertSessionHasErrors('report');

    // The company sends complete once its one reporting platoon has.
    sendEmptyUnitsReport($this->platoon->id, $this->weekStart);
    $this->actingAs($this->admin)->post("/reports/{$this->alpha->id}/{$this->week}/send", ['reason' => 'Weekly report'])
        ->assertSessionHasNoErrors();
    expect(ReportSnapshot::fromArray(WeeklyReport::query()->where('unit_id', $this->alpha->id)->sole()->kpis)->missingChildren)->toBe([]);
});

it('keeps a platoon whose only troopers are its HQ out of Home\'s "Needs attention", and on the roster', function () {
    $units = app(UnitRepository::class);
    $hqOnly = $units->create(UnitType::Platoon, '2nd Platoon', '2/A/2-7', $this->alpha->id);
    $hqSection = $units->create(UnitType::Section, '1st Section', '1/2/A/2-7', $hqOnly->id);
    manUnits($hqOnly);

    $this->actingAs($this->admin)->get('/home')
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('attention.items', fn ($items) => collect($items)->where('kind', 'holding')->pluck('unit.id')->all() === [$this->manned->id]));

    $this->actingAs($this->admin)->get("/roster/{$this->battalion->id}")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('units', fn ($shown) => collect($shown)->pluck('id')->contains($hqOnly->id) && ! collect($shown)->pluck('id')->contains($hqSection->id))
            ->where('scopeOptions', fn ($options) => collect($options)->pluck('id')->contains($hqOnly->id)));
});

it('still rolls up a platoon with one manned section, its HQ on it or not', function () {
    manUnits($this->platoon);

    expect(app(RollupKpis::class)->children($this->platoon, $this->weekStart)->modelKeys())->toBe([$this->manned->id])
        ->and(app(RollupKpis::class)->children($this->alpha, $this->weekStart)->modelKeys())->toBe([$this->platoon->id])
        ->and(app(RollupKpis::class)->children($this->battalion, $this->weekStart)->modelKeys())->toBe([$this->alpha->id]);

    sendEmptyUnitsReport($this->manned->id, $this->weekStart);
    $this->actingAs($this->admin)->post("/reports/{$this->platoon->id}/{$this->week}/send", ['reason' => 'Weekly report'])
        ->assertSessionHasNoErrors();
    expect(WeeklyReport::query()->where('unit_id', $this->platoon->id)->sole()->status)->toBe(WeeklyReportStatus::Sent);
});

it('leaves a company whose only troopers are its HQ out of the battalion\'s roll-up, and keeps it on the roster', function () {
    // Echo's commander and first sergeant, placed on the company; every unit under it is empty.
    manUnits($this->echo, $this->echo);

    expect(app(RollupKpis::class)->children($this->battalion, $this->weekStart)->modelKeys())->toBe([$this->alpha->id])
        ->and(emptyUnitsChainIds(app(ReportChain::class)->build($this->battalion, $this->weekStart)))->not->toContain($this->echo->id);

    $this->actingAs($this->admin)->get("/reports/{$this->battalion->id}/{$this->week}")
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('children', fn ($children) => collect($children)->pluck('id')->all() === [$this->alpha->id])
            ->where('missingChildren', [$this->alpha->id]));
    $this->actingAs($this->admin)->get("/roster/{$this->battalion->id}")
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('units', fn ($units) => collect($units)->pluck('id')->contains($this->echo->id)));
});

it('keeps the platoon and company of a section that sent in that week\'s chain after everyone in them leaves', function () {
    sendEmptyUnitsReport($this->manned->id, $this->weekStart);
    // Every trooper under the battalion leaves the Cav: nobody is placed anywhere under it now.
    $this->troopers->each(fn (Trooper $t) => $t->forceFill(['status' => TrooperStatus::Inactive])->save());

    expect(emptyUnitsChainIds(app(ReportChain::class)->build($this->battalion, $this->weekStart)))
        ->toBe([$this->battalion->id, $this->alpha->id, $this->platoon->id, $this->manned->id])
        ->and(app(RollupKpis::class)->children($this->battalion, $this->weekStart)->modelKeys())->toBe([$this->alpha->id])
        ->and(app(RollupKpis::class)->children($this->alpha, $this->weekStart)->modelKeys())->toBe([$this->platoon->id])
        ->and(app(RollupKpis::class)->forUnit($this->platoon, $this->weekStart)->isComplete())->toBeTrue();

    // The week after, nothing under the battalion reports.
    expect(app(RollupKpis::class)->children($this->battalion, $this->weekStart->addWeek())->all())->toBe([]);
});

// Audit 2 (M2, M1, L4): a past week is judged by who was placed during it (the placement history) or sent its report,
// never by who is placed now. Only the running week also counts who is placed now.
it('leaves a section stood up after a past week out of that week\'s roll-up, and counts it from its first week', function () {
    $standUp = app(UnitRepository::class)->create(UnitType::Section, '3rd Section', '3/1/A/2-7', $this->platoon->id);
    $joined = Trooper::factory()->create(['primary_section_id' => null]);
    // The roster sync fills it now (Thu 08OCT26), writing the placement history as it does.
    app(TrooperRepository::class)->updatePrimarySections([$joined->cav_id => $standUp->id]);
    $lastWeek = $this->weekStart->subWeek();

    expect(app(RollupKpis::class)->children($this->platoon, $lastWeek)->modelKeys())->toBe([$this->manned->id])
        ->and(emptyUnitsChainIds(app(ReportChain::class)->build($this->battalion, $lastWeek)))->not->toContain($standUp->id)
        ->and(app(RollupKpis::class)->children($this->platoon, $this->weekStart)->modelKeys())->toBe([$this->manned->id, $standUp->id]);

    $this->actingAs($this->admin)->get("/reports/{$this->platoon->id}/2026-09-26")
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('children', fn ($children) => collect($children)->pluck('id')->all() === [$this->manned->id])
            ->where('missingChildren', [$this->manned->id]));
});

it('keeps a past week on Home\'s sparkline when a section was stood up since', function () {
    sendEmptyUnitsReport($this->manned->id, $this->weekStart->subWeek());
    $standUp = app(UnitRepository::class)->create(UnitType::Section, '3rd Section', '3/1/A/2-7', $this->platoon->id);
    $joined = Trooper::factory()->create(['primary_section_id' => null]);
    app(TrooperRepository::class)->updatePrimarySections([$joined->cav_id => $standUp->id]);

    $this->actingAs($this->admin)->get('/home')
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('kpis.history.3.week', '2026-09-26')
            ->where('kpis.history.3.kpis.strength', 2));
});

it('adds a section emptied since to the past weeks it sent on Home\'s sparkline', function () {
    $member = Trooper::factory()->create(['primary_section_id' => $this->emptySection->id]);
    sendEmptyUnitsReport($this->manned->id, $this->weekStart->subWeek());
    sendEmptyUnitsReport($this->emptySection->id, $this->weekStart->subWeek(), strength: 1);
    // Its only member moved out of every unit on Tuesday of this week.
    app(TrooperPlacementRepository::class)->record([$member->id => [$this->emptySection->id, null]], PlacementSource::Sync, CarbonImmutable::parse('2026-10-06 12:00:00', 'UTC'));
    $member->forceFill(['primary_section_id' => null])->save();

    $this->actingAs($this->admin)->get('/home')
        ->assertInertia(fn (AssertableInertia $page) => $page->where('kpis.history.3.kpis.strength', 3));
});

it('opens a past week for a section leader whose section has been emptied since, and lists it in that week\'s steps', function () {
    $leader = User::factory()->serving()->create();
    PortalRoleGrant::factory()->create(['user_id' => $leader->id, 'unit_id' => $this->manned->id]);
    sendEmptyUnitsReport($this->manned->id, $this->weekStart->subWeek());
    // The sync moved both its troopers out on Tuesday of the running week.
    $changes = $this->troopers->mapWithKeys(fn (Trooper $t) => [$t->id => [$this->manned->id, null]])->all();
    app(TrooperPlacementRepository::class)->record($changes, PlacementSource::Sync, CarbonImmutable::parse('2026-10-06 12:00:00', 'UTC'));
    $this->troopers->each(fn (Trooper $t) => $t->forceFill(['primary_section_id' => null])->save());

    // The roll-up workspace starts from the section for the week it sent, and lands on its report.
    $this->actingAs($leader)->get('/reports?week=2026-09-26')
        ->assertRedirect("/reports/{$this->manned->id}/2026-09-26");

    // It had troopers until Tuesday, so the running week's step matrix lists it, as Needs attention does.
    $this->actingAs($this->admin)->get('/home')
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('steps', fn ($steps) => collect($steps)->pluck('id')->all() === [$this->manned->id])
            ->where('attention.items', fn ($items) => collect($items)->where('kind', 'holding')->pluck('unit.id')->all() === [$this->manned->id]));
});
