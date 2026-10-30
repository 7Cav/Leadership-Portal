<?php

use App\Kpi\KpiService;
use App\Kpi\Ratio;
use App\Models\AttendanceSource;
use App\Models\AttendanceStatus;
use App\Models\Loa;
use App\Models\RollcallSignature;
use App\Models\Trooper;
use App\Models\Unit;
use App\Models\UnitType;
use App\Models\User;
use App\Repositories\AttendanceRepository;
use App\Repositories\SectionPracticeRepository;
use App\Repositories\TrooperRepository;
use App\Repositories\UnitRepository;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

// After a transfer the new leadership sees the trooper's rollcall signing,
// section practice attendance and LOAs from before the move. The KPIs read each week against the section the trooper
// was placed in that week, so their old marks still count and the new section's earlier practices never do.
// Fixture tree: regiment ─ battalion ─┬─ 1st platoon ─ section A (2-1/A/1-7)
//                                     └─ 2nd platoon ─ section B (1-2/A/1-7)
// The window ends 2026-06-27: weeks 06-06, 06-13, 06-20, 06-27. "weeks back" 3..0.

function xferWeek(int $weeksBack = 0): CarbonImmutable
{
    return CarbonImmutable::parse('2026-06-27 00:00:00', 'UTC')->subWeeks($weeksBack);
}

/** A trooper placed in `$section` before the placement history began (as every trooper placed on deploy). */
function xferTrooper(Unit $section): Trooper
{
    $trooper = Trooper::factory()->create();
    $trooper->forceFill(['primary_section_id' => $section->id])->save();

    return $trooper;
}

/** The roster sync moves `$trooper` to `$section` on the Wednesday of the week `$weeksBack` back. */
function xferMove($test, Trooper $trooper, Unit $section, int $weeksBack): void
{
    $test->travelTo(xferWeek($weeksBack)->addDays(4)->setTime(19, 0));
    app(TrooperRepository::class)->updatePrimarySections([$trooper->cav_id => $section->id]);
    $test->travelBack();
}

function xferMark(Trooper $trooper, Unit $section, int $weeksBack, ?AttendanceStatus $status = null, ?RollcallSignature $rollcall = null): void
{
    $practice = app(SectionPracticeRepository::class)->findOrCreate($section->id, xferWeek($weeksBack));

    if ($status !== null) {
        app(AttendanceRepository::class)->markPractice($practice, $trooper->id, $status, AttendanceSource::Manual);
    }

    if ($rollcall !== null) {
        app(AttendanceRepository::class)->signRollcall($practice, $trooper->id, $rollcall);
    }
}

beforeEach(function () {
    $units = app(UnitRepository::class);

    $this->regiment = $units->create(UnitType::Regiment, '7th Cavalry Regiment', null, null);
    $battalion = $units->create(UnitType::Battalion, '1st Battalion', '1-7', $this->regiment->id);
    $platoon1 = $units->create(UnitType::Platoon, '1st Platoon', '1/A/1-7', $battalion->id);
    $platoon2 = $units->create(UnitType::Platoon, '2nd Platoon', '2/A/1-7', $battalion->id);
    $this->sectionA = $units->create(UnitType::Section, 'Section A', '2-1/A/1-7', $platoon1->id);
    $this->sectionB = $units->create(UnitType::Section, 'Section B', '1-2/A/1-7', $platoon2->id);

    // A member of B who stays, so B's practices are held every week.
    $this->stayer = xferTrooper($this->sectionB);
    foreach (range(0, 3) as $back) {
        xferMark($this->stayer, $this->sectionB, $back, AttendanceStatus::Present, RollcallSignature::WillAttend);
    }

    $this->kpis = app(KpiService::class);
});

it('keeps counting the old section\'s marks after a transfer, and never the new section\'s earlier practices', function () {
    $mover = xferTrooper($this->sectionA);
    xferMark($mover, $this->sectionA, 3, AttendanceStatus::Present, RollcallSignature::WillAttend);
    xferMark($mover, $this->sectionA, 2, AttendanceStatus::Present, RollcallSignature::WillAttend);
    xferMove($this, $mover, $this->sectionB, 1);
    xferMark($mover, $this->sectionB, 1, AttendanceStatus::Present, RollcallSignature::WillAttend);
    xferMark($mover, $this->sectionB, 0, AttendanceStatus::Present, RollcallSignature::WillAttend);

    $row = $this->kpis->trooperAttendance($this->sectionB, xferWeek())[$mover->id];

    expect($row->practice)->toEqual(new Ratio(4, 4))
        ->and($row->rollcall)->toEqual(new Ratio(4, 4))
        ->and($row->missed)->toBe(0);
});

it('does not hold the new section\'s practices from before the move against the trooper', function () {
    // Section A held nothing those weeks; B held all four. Before the history, B's two earlier weeks counted as
    // unattended and unsigned.
    $mover = xferTrooper($this->sectionA);
    xferMove($this, $mover, $this->sectionB, 1);
    xferMark($mover, $this->sectionB, 1, AttendanceStatus::Present, RollcallSignature::WillAttend);
    xferMark($mover, $this->sectionB, 0, AttendanceStatus::Present, RollcallSignature::WillAttend);

    $row = $this->kpis->trooperAttendance($this->sectionB, xferWeek())[$mover->id];

    expect($row->practice)->toEqual(new Ratio(2, 2))
        ->and($row->missed)->toBe(0)
        ->and($row->atRisk())->toBeFalse();
});

it('does not flag at-risk from misses recorded at the new section for weeks before the trooper joined it', function () {
    $mover = xferTrooper($this->sectionA);
    xferMark($mover, $this->sectionA, 3, AttendanceStatus::Present);
    xferMark($mover, $this->sectionA, 2, AttendanceStatus::Present);
    // Rows the new leadership entered by hand for weeks the trooper was still in A.
    xferMark($mover, $this->sectionB, 3, AttendanceStatus::Missed);
    xferMark($mover, $this->sectionB, 2, AttendanceStatus::Missed);
    xferMove($this, $mover, $this->sectionB, 1);

    $row = $this->kpis->trooperAttendance($this->sectionB, xferWeek())[$mover->id];

    // The week of the move is their first at B, excused automatically while nobody marked it (2026-10-30).
    expect($row->missed)->toBe(0)
        ->and($row->atRisk())->toBeFalse()
        ->and($row->practice)->toEqual(new Ratio(2, 3));
});

it('carries the old section\'s misses with the trooper, so at-risk follows them', function () {
    $mover = xferTrooper($this->sectionA);
    xferMark($mover, $this->sectionA, 3, AttendanceStatus::Missed);
    xferMark($mover, $this->sectionA, 2, AttendanceStatus::Missed);
    xferMove($this, $mover, $this->sectionB, 1);

    $row = $this->kpis->trooperAttendance($this->sectionB, xferWeek())[$mover->id];

    expect($row->missed)->toBe(2)
        ->and($row->atRisk())->toBeTrue()
        ->and($row->missedWeeks)->toBe([xferWeek(3)->toDateString(), xferWeek(2)->toDateString()]);
});

it('reads the week of the move as one counting mark: the practice attended, and a signature at either section', function () {
    $mover = xferTrooper($this->sectionA);
    xferMark($mover, $this->sectionA, 1, AttendanceStatus::Missed, RollcallSignature::WillAttend);
    xferMove($this, $mover, $this->sectionB, 1);
    xferMark($mover, $this->sectionB, 1, AttendanceStatus::Present);

    $marks = app(AttendanceRepository::class)->countingMarks($this->sectionB, xferWeek(1), xferWeek(1))
        ->where('trooperId', $mover->id)->values();

    expect($marks)->toHaveCount(1)
        ->and($marks[0]->status)->toBe(AttendanceStatus::Present)
        ->and($marks[0]->rollcall)->toBe(RollcallSignature::WillAttend)
        ->and($marks[0]->sectionId)->toBe($this->sectionB->id);
});

it('names the section of each week\'s mark', function () {
    $mover = xferTrooper($this->sectionA);
    xferMark($mover, $this->sectionA, 3, AttendanceStatus::Present);
    xferMove($this, $mover, $this->sectionB, 1);

    $sections = app(AttendanceRepository::class)->countingMarks($this->sectionB, xferWeek(3), xferWeek())
        ->where('trooperId', $mover->id)
        ->mapWithKeys(fn ($mark) => [$mark->weekStart->toDateString() => $mark->sectionId])
        ->all();

    // A held nothing in week 2, so that week has no mark; from the move on, B's practices are theirs.
    expect($sections)->toBe([
        xferWeek(3)->toDateString() => $this->sectionA->id,
        xferWeek(1)->toDateString() => $this->sectionB->id,
        xferWeek(0)->toDateString() => $this->sectionB->id,
    ]);
});

it('charges a week moved away and back again to the section the trooper ended it in', function () {
    $mover = xferTrooper($this->sectionA);
    // Signed at A but no SP mark there: A's practice exists without being held. B (the stayer) held its practice.
    xferMark($mover, $this->sectionA, 0, rollcall: RollcallSignature::WillAttend);
    $this->travelTo(xferWeek()->addDays(4)->setTime(19, 0));
    app(TrooperRepository::class)->updatePrimarySections([$mover->cav_id => $this->sectionB->id]);
    $this->travelTo(xferWeek()->addDays(5)->setTime(19, 0));
    app(TrooperRepository::class)->updatePrimarySections([$mover->cav_id => $this->sectionA->id]);
    $this->travelBack();

    $mark = app(AttendanceRepository::class)->countingMarksOf([$mover->id], xferWeek(), xferWeek())->sole();

    expect($mark->sectionId)->toBe($this->sectionA->id)
        ->and($mark->held)->toBeFalse();
});

it('shows the new section\'s report the same mark its KPIs count in the week of the move', function () {
    $mover = xferTrooper($this->sectionA);
    xferMark($mover, $this->sectionA, 0, AttendanceStatus::Present, RollcallSignature::WillAttend);
    xferMove($this, $mover, $this->sectionB, 0);

    $row = null;
    $this->actingAs(User::factory()->admin()->create())
        ->get("/reports/{$this->sectionB->id}/".xferWeek()->toDateString())
        ->assertOk()
        ->assertInertia(function ($page) use (&$row, $mover) {
            $row = collect($page->toArray()['props']['troopers'])->firstWhere('id', $mover->id);
        });

    expect($row['status'])->toBe(AttendanceStatus::Present->value)
        ->and($row['rollcall'])->toBe(RollcallSignature::WillAttend->value);
});

it('counts a trooper\'s RC only over the weeks they were in a section', function () {
    $recruit = Trooper::factory()->create(['primary_section_id' => null]);
    xferMove($this, $recruit, $this->sectionB, 1);
    xferMark($recruit, $this->sectionB, 1, AttendanceStatus::Present, RollcallSignature::WillAttend);
    xferMark($recruit, $this->sectionB, 0, AttendanceStatus::Present, RollcallSignature::WillAttend);

    $row = $this->kpis->trooperAttendance($this->sectionB, xferWeek())[$recruit->id];

    expect($row->rollcall)->toEqual(new Ratio(2, 2))
        ->and($row->practice)->toEqual(new Ratio(2, 2));
});

it('takes LOA weeks from before the move out of the window as before', function () {
    $mover = xferTrooper($this->sectionA);
    xferMark($mover, $this->sectionA, 3, AttendanceStatus::Missed);
    xferMark($mover, $this->sectionA, 2, AttendanceStatus::Missed);
    Loa::factory()->create(['trooper_id' => $mover->id, 'starts_at' => xferWeek(3), 'ends_at' => xferWeek(2)->addDays(6)]);
    xferMove($this, $mover, $this->sectionB, 1);
    xferMark($mover, $this->sectionB, 1, AttendanceStatus::Present, RollcallSignature::WillAttend);
    xferMark($mover, $this->sectionB, 0, AttendanceStatus::Present, RollcallSignature::WillAttend);

    $row = $this->kpis->trooperAttendance($this->sectionB, xferWeek())[$mover->id];

    expect($row->missed)->toBe(0)
        ->and($row->rollcall)->toEqual(new Ratio(2, 2))
        ->and($row->practice)->toEqual(new Ratio(2, 2));
});

it('counts the trooper in their current section\'s KPIs with their earlier weeks, and no longer in the old one', function () {
    $mover = xferTrooper($this->sectionA);
    xferMark($mover, $this->sectionA, 3, AttendanceStatus::Present, RollcallSignature::WillAttend);
    xferMove($this, $mover, $this->sectionB, 1);

    $a = $this->kpis->forUnit($this->sectionA, xferWeek());
    $b = $this->kpis->forUnit($this->sectionB, xferWeek());
    $bySection = $this->kpis->forSectionsWithin($this->regiment, xferWeek());

    expect($a->strength)->toBe(0)
        ->and($b->strength)->toBe(2)
        // The stayer 4 of 4, the mover their week at A plus an unmarked held week at B: the week of the move is
        // excused automatically (2026-10-30).
        ->and($b->sp30d)->toEqual(new Ratio(5, 6))
        ->and($bySection[$this->sectionB->id])->toEqual($b)
        ->and($bySection->has($this->sectionA->id))->toBeFalse();
});

it('reads a regiment in the same number of queries with placement history as without', function () {
    foreach (range(1, 5) as $i) {
        $mover = xferTrooper($this->sectionA);
        xferMark($mover, $this->sectionA, 3, AttendanceStatus::Present);
        xferMove($this, $mover, $this->sectionB, 1);
    }

    DB::enableQueryLog();
    $this->kpis->forUnit($this->regiment, xferWeek());
    $queries = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($queries)->toBeLessThanOrEqual(4);
});

it('measures the JSAM own-section SP against the section the trooper was in that week', function () {
    $mover = xferTrooper($this->sectionA);
    xferMark($mover, $this->sectionA, 3, AttendanceStatus::Present);
    xferMark($this->stayer, $this->sectionA, 2, AttendanceStatus::Present); // A held week 2; the mover missed it
    xferMove($this, $mover, $this->sectionB, 1);
    xferMark($mover, $this->sectionB, 0, AttendanceStatus::Present);

    // Weeks 3 and 0 attended, week 2 missed; week 1, the move, is excused automatically (2026-10-30).
    expect($this->kpis->ownSectionPractice([$mover->id], xferWeek(), 4)[$mover->id])->toEqual(new Ratio(2, 3));
});
