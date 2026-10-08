<?php

use App\Kpi\KpiService;
use App\Kpi\Ratio;
use App\Kpi\TrooperAttendance;
use App\Kpi\UnitKpis;
use App\Models\AttendanceSource;
use App\Models\AttendanceStatus;
use App\Models\RollcallSignature;
use App\Models\Trooper;
use App\Models\TrooperStatus;
use App\Models\Unit;
use App\Models\UnitType;
use App\Repositories\AttendanceRepository;
use App\Repositories\SectionPracticeRepository;
use App\Repositories\UnitRepository;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

// 5.1 / AD-21: the six canonical KPIs, computed in one place over the trailing 4 whole Cav weeks
// ending with (and including) `week_start`. Fixture tree:
//   regiment ─ battalion ─┬─ 1st platoon ─ section A
//                         └─ 2nd platoon ─ section B
// `week_start` is 2026-06-27 (a Saturday); the window is 06-06, 06-13, 06-20, 06-27.

const KPI_WEEK = '2026-06-27';

function kpiWeek(int $weeksBack = 0): CarbonImmutable
{
    return CarbonImmutable::parse(KPI_WEEK.' 00:00:00', 'UTC')->subWeeks($weeksBack);
}

function kpiTrooper(Unit $section, TrooperStatus $status = TrooperStatus::Combat): Trooper
{
    $trooper = Trooper::factory()->create(['status' => $status]);
    $trooper->forceFill(['primary_section_id' => $section->id])->save();

    return $trooper;
}

/**
 * Records one trooper-week against `$section`'s practice: an optional SP mark and an optional
 * rollcall signature, through the same repositories 3.3's writer uses.
 */
function kpiMark(
    Trooper $trooper,
    Unit $section,
    int $weeksBack,
    ?AttendanceStatus $status = null,
    ?RollcallSignature $rollcall = null,
): void {
    $practice = app(SectionPracticeRepository::class)->findOrCreate($section->id, kpiWeek($weeksBack));

    if ($status !== null) {
        app(AttendanceRepository::class)->markPractice($practice, $trooper->id, $status, AttendanceSource::Manual);
    }

    if ($rollcall !== null) {
        app(AttendanceRepository::class)->signRollcall($practice, $trooper->id, $rollcall);
    }
}

/** A practice row for the section-week with nobody marked or signed against it — not yet held. */
function kpiPracticeRow(Unit $section, int $weeksBack): void
{
    app(SectionPracticeRepository::class)->findOrCreate($section->id, kpiWeek($weeksBack));
}

beforeEach(function () {
    $units = app(UnitRepository::class);

    $this->regiment = $units->create(UnitType::Regiment, '7th Cavalry Regiment', null, null);
    $this->battalion = $units->create(UnitType::Battalion, '1st Battalion', '1-7', $this->regiment->id);
    $platoon1 = $units->create(UnitType::Platoon, '1st Platoon', '1/A/1-7', $this->battalion->id);
    $platoon2 = $units->create(UnitType::Platoon, '2nd Platoon', '2/A/1-7', $this->battalion->id);
    $this->sectionA = $units->create(UnitType::Section, 'Section A', '1-1/A/1-7', $platoon1->id);
    $this->sectionB = $units->create(UnitType::Section, 'Section B', '2-1/A/1-7', $platoon2->id);

    $this->kpis = app(KpiService::class);
});

it('returns exactly the six canonical KPIs and nothing more', function () {
    $properties = array_map(
        fn (ReflectionProperty $p) => $p->getName(),
        (new ReflectionClass(UnitKpis::class))->getProperties(),
    );

    expect($properties)->toBe(['strength', 'rc30d', 'sp30d', 'atRisk', 'onLoa', 'makeups']);
});

it('counts strength as the active troopers placed anywhere in the subtree, LOA included', function () {
    kpiTrooper($this->sectionA);
    kpiTrooper($this->sectionA, TrooperStatus::Reserve);
    kpiTrooper($this->sectionB, TrooperStatus::Eloa);
    kpiTrooper($this->sectionB, TrooperStatus::Inactive);   // discharged / transferred out
    Trooper::factory()->create();                           // unplaced
    kpiTrooper(Unit::factory()->create());                  // outside the tree

    expect($this->kpis->forUnit($this->regiment, kpiWeek())->strength)->toBe(3)
        ->and($this->kpis->forUnit($this->sectionA, kpiWeek())->strength)->toBe(2)
        ->and($this->kpis->forUnit($this->sectionB, kpiWeek())->strength)->toBe(1);
});

it('keeps a trooper on LOA in strength', function () {
    $trooper = kpiTrooper($this->sectionA);
    kpiMark($trooper, $this->sectionA, 0, AttendanceStatus::Loa);

    $kpis = $this->kpis->forUnit($this->sectionA, kpiWeek());

    expect($kpis->strength)->toBe(1)->and($kpis->onLoa)->toBe(1);
});

it('computes SP % as attended ÷ held practices, crediting present, makeup and attached', function () {
    $trooper = kpiTrooper($this->sectionA);
    kpiMark($trooper, $this->sectionA, 3, AttendanceStatus::Present);
    kpiMark($trooper, $this->sectionA, 2, AttendanceStatus::Makeup);
    kpiMark($trooper, $this->sectionA, 1, AttendanceStatus::Attached);
    kpiMark($trooper, $this->sectionA, 0, AttendanceStatus::Missed);

    $sp = $this->kpis->forUnit($this->sectionA, kpiWeek())->sp30d;

    expect($sp->numerator)->toBe(3)
        ->and($sp->denominator)->toBe(4)
        ->and($sp->value())->toBe(0.75);
});

it('leaves a week the section held no practice out of the SP denominator', function () {
    $trooper = kpiTrooper($this->sectionA);
    kpiMark($trooper, $this->sectionA, 3, AttendanceStatus::Present);
    kpiMark($trooper, $this->sectionA, 0, AttendanceStatus::Present);

    $sp = $this->kpis->forUnit($this->sectionA, kpiWeek())->sp30d;

    expect([$sp->numerator, $sp->denominator])->toBe([2, 2]);
});

it('leaves a practice with only rollcall signatures out of SP, leaving RC unaffected', function () {
    $a = kpiTrooper($this->sectionA);
    $b = kpiTrooper($this->sectionA);
    kpiMark($a, $this->sectionA, 0, rollcall: RollcallSignature::WillAttend);
    kpiMark($b, $this->sectionA, 0, rollcall: RollcallSignature::CannotAttend);
    kpiPracticeRow($this->sectionA, 1);   // a row nobody has marked or signed

    $kpis = $this->kpis->forUnit($this->sectionA, kpiWeek());

    expect($kpis->sp30d)->toEqual(new Ratio(0, 0))
        ->and($kpis->sp30d->value())->toBeNull()
        ->and($kpis->rc30d)->toEqual(new Ratio(2, 8));
});

it('makes a practice eligible for every assigned trooper of the section once one is marked', function () {
    $marked = kpiTrooper($this->sectionA);
    $signedOnly = kpiTrooper($this->sectionA);
    $silent = kpiTrooper($this->sectionA);
    kpiMark($marked, $this->sectionA, 0, AttendanceStatus::Present);
    kpiMark($signedOnly, $this->sectionA, 0, rollcall: RollcallSignature::WillAttend);

    $byTrooper = $this->kpis->trooperAttendance($this->sectionA, kpiWeek());

    // The leader saw the practice happen: the unmarked are eligible but not attended — yet never
    // counted as a miss, so at-risk is not asserted from a missing mark.
    expect($byTrooper[$marked->id]->practice)->toEqual(new Ratio(1, 1))
        ->and($byTrooper[$signedOnly->id]->practice)->toEqual(new Ratio(0, 1))
        ->and($byTrooper[$silent->id]->practice)->toEqual(new Ratio(0, 1))
        ->and($byTrooper[$signedOnly->id]->missed)->toBe(0)
        ->and($byTrooper[$silent->id]->missed)->toBe(0)
        ->and($this->kpis->forUnit($this->sectionA, kpiWeek())->sp30d)->toEqual(new Ratio(1, 3));
});

it('computes RC % as signed rollcalls of either kind ÷ the 4 assigned weeks', function () {
    $trooper = kpiTrooper($this->sectionA);
    kpiMark($trooper, $this->sectionA, 3, rollcall: RollcallSignature::WillAttend);
    kpiMark($trooper, $this->sectionA, 2, rollcall: RollcallSignature::CannotAttend);
    kpiMark($trooper, $this->sectionA, 1, AttendanceStatus::Present);                // marked, never signed
    // week 0: no practice row at all — still an assigned week for RC

    $rc = $this->kpis->forUnit($this->sectionA, kpiWeek())->rc30d;

    expect([$rc->numerator, $rc->denominator])->toBe([2, 4])
        ->and($rc->value())->toBe(0.5);
});

it('only counts weeks inside the trailing 4-week window', function () {
    $trooper = kpiTrooper($this->sectionA);
    kpiMark($trooper, $this->sectionA, 4, AttendanceStatus::Missed, RollcallSignature::WillAttend);   // the week before the window
    kpiMark($trooper, $this->sectionA, -1, AttendanceStatus::Missed, RollcallSignature::WillAttend);  // the week after week_start
    kpiMark($trooper, $this->sectionA, 3, AttendanceStatus::Present, RollcallSignature::WillAttend);  // first week of the window
    kpiMark($trooper, $this->sectionA, 0, AttendanceStatus::Makeup, RollcallSignature::WillAttend);   // week_start itself

    $kpis = $this->kpis->forUnit($this->sectionA, kpiWeek());

    expect([$kpis->sp30d->numerator, $kpis->sp30d->denominator])->toBe([2, 2])
        ->and([$kpis->rc30d->numerator, $kpis->rc30d->denominator])->toBe([2, 4])
        ->and($kpis->atRisk)->toBe(0)
        ->and($kpis->makeups)->toBe(1);
});

it('counts only the trooper\'s primary-section practice — one counting SP per trooper-week', function () {
    $trooper = kpiTrooper($this->sectionA);
    kpiMark($trooper, $this->sectionA, 0, AttendanceStatus::Missed);
    kpiMark($trooper, $this->sectionB, 0, AttendanceStatus::Present, RollcallSignature::WillAttend); // a stray row elsewhere

    $kpis = $this->kpis->forUnit($this->regiment, kpiWeek());

    expect([$kpis->sp30d->numerator, $kpis->sp30d->denominator])->toBe([0, 1])
        ->and($kpis->rc30d->numerator)->toBe(0);
});

it('does not count a makeup done at another section\'s practice', function () {
    $trooper = kpiTrooper($this->sectionA);
    kpiMark($trooper, $this->sectionB, 0, AttendanceStatus::Makeup);

    expect($this->kpis->forUnit($this->regiment, kpiWeek())->makeups)->toBe(0);
});

it('measures a trooper who changed section mid-window against their current section only', function () {
    // No placement history in the cache: the earlier section's marks drop out and the new
    // section's earlier practices count as eligible. Pinned so a change here is deliberate.
    $trooper = kpiTrooper($this->sectionA);
    kpiMark($trooper, $this->sectionA, 3, AttendanceStatus::Missed);
    kpiMark($trooper, $this->sectionA, 2, AttendanceStatus::Missed);
    kpiMark(kpiTrooper($this->sectionB), $this->sectionB, 1, AttendanceStatus::Present);   // B held week 1
    $trooper->forceFill(['primary_section_id' => $this->sectionB->id])->save();

    $attendance = $this->kpis->trooperAttendance($this->sectionB, kpiWeek())[$trooper->id];

    expect($attendance->missed)->toBe(0)
        ->and($attendance->practice)->toEqual(new Ratio(0, 1))
        ->and($this->kpis->forUnit($this->sectionA, kpiWeek())->strength)->toBe(0);
});

it('suppresses at-risk for a trooper with a needs_review LOA (AD-4)')
    ->todo('Epic 4 — no LOA data exists yet; see KpiService::isLoaCovered()');

it('excludes an LOA-covered week from both denominators rather than counting it as a miss', function () {
    $trooper = kpiTrooper($this->sectionA);
    kpiMark($trooper, $this->sectionA, 3, AttendanceStatus::Present, RollcallSignature::WillAttend);
    kpiMark($trooper, $this->sectionA, 2, AttendanceStatus::Loa);
    kpiMark($trooper, $this->sectionA, 1, AttendanceStatus::Present, RollcallSignature::WillAttend);
    kpiMark($trooper, $this->sectionA, 0, AttendanceStatus::Present, RollcallSignature::WillAttend);

    $kpis = $this->kpis->forUnit($this->sectionA, kpiWeek());

    expect([$kpis->sp30d->numerator, $kpis->sp30d->denominator])->toBe([3, 3])
        ->and([$kpis->rc30d->numerator, $kpis->rc30d->denominator])->toBe([3, 3])
        ->and($kpis->atRisk)->toBe(0);
});

it('does not credit a rollcall signed during an LOA-covered week', function () {
    $trooper = kpiTrooper($this->sectionA);
    kpiMark($trooper, $this->sectionA, 0, AttendanceStatus::Loa, RollcallSignature::CannotAttend);

    $rc = $this->kpis->forUnit($this->sectionA, kpiWeek())->rc30d;

    expect([$rc->numerator, $rc->denominator])->toBe([0, 3]);
});

it('reports RC and SP as not-applicable when LOA covers all 4 weeks', function () {
    $trooper = kpiTrooper($this->sectionA);
    foreach ([0, 1, 2, 3] as $weeksBack) {
        kpiMark($trooper, $this->sectionA, $weeksBack, AttendanceStatus::Loa);
    }

    $kpis = $this->kpis->forUnit($this->sectionA, kpiWeek());

    expect($kpis->rc30d->denominator)->toBe(0)
        ->and($kpis->rc30d->value())->toBeNull()
        ->and($kpis->sp30d->denominator)->toBe(0)
        ->and($kpis->sp30d->value())->toBeNull()
        ->and($kpis->atRisk)->toBe(0);
});

it('reports not-applicable rather than 0% for a unit with nobody in it', function () {
    $kpis = $this->kpis->forUnit($this->sectionB, kpiWeek());

    expect($kpis->strength)->toBe(0)
        ->and($kpis->rc30d->value())->toBeNull()
        ->and($kpis->sp30d->value())->toBeNull();
});

it('flags a trooper at-risk at 2 explicit misses in the window, not at 1', function () {
    $twoMisses = kpiTrooper($this->sectionA);
    kpiMark($twoMisses, $this->sectionA, 3, AttendanceStatus::Missed);
    kpiMark($twoMisses, $this->sectionA, 0, AttendanceStatus::Missed);

    $oneMiss = kpiTrooper($this->sectionA);
    kpiMark($oneMiss, $this->sectionA, 3, AttendanceStatus::Missed);
    kpiMark($oneMiss, $this->sectionA, 0, AttendanceStatus::Present);

    $outsideWindow = kpiTrooper($this->sectionA);
    kpiMark($outsideWindow, $this->sectionA, 4, AttendanceStatus::Missed);
    kpiMark($outsideWindow, $this->sectionA, 0, AttendanceStatus::Missed);

    $byTrooper = $this->kpis->trooperAttendance($this->sectionA, kpiWeek());

    expect($byTrooper[$twoMisses->id]->atRisk())->toBeTrue()
        ->and($byTrooper[$oneMiss->id]->atRisk())->toBeFalse()
        ->and($byTrooper[$outsideWindow->id]->atRisk())->toBeFalse()
        ->and($this->kpis->forUnit($this->sectionA, kpiWeek())->atRisk)->toBe(1);
});

it('counts on-LOA and makeups for the week_start week only', function () {
    $loaNow = kpiTrooper($this->sectionA);
    kpiMark($loaNow, $this->sectionA, 0, AttendanceStatus::Loa);

    $loaEarlier = kpiTrooper($this->sectionA);
    kpiMark($loaEarlier, $this->sectionA, 1, AttendanceStatus::Loa);

    $makeupNow = kpiTrooper($this->sectionB);
    kpiMark($makeupNow, $this->sectionB, 0, AttendanceStatus::Makeup);

    $makeupEarlier = kpiTrooper($this->sectionB);
    kpiMark($makeupEarlier, $this->sectionB, 2, AttendanceStatus::Makeup);

    $kpis = $this->kpis->forUnit($this->regiment, kpiWeek());

    expect($kpis->onLoa)->toBe(1)->and($kpis->makeups)->toBe(1);
});

it('ignores marks belonging to inactive troopers', function () {
    $gone = kpiTrooper($this->sectionA, TrooperStatus::Inactive);
    kpiMark($gone, $this->sectionA, 0, AttendanceStatus::Makeup, RollcallSignature::WillAttend);
    kpiMark($gone, $this->sectionA, 1, AttendanceStatus::Missed);
    kpiMark($gone, $this->sectionA, 2, AttendanceStatus::Missed);

    $kpis = $this->kpis->forUnit($this->sectionA, kpiWeek());

    expect($kpis->strength)->toBe(0)
        ->and($kpis->makeups)->toBe(0)
        ->and($kpis->atRisk)->toBe(0)
        ->and($kpis->sp30d->denominator)->toBe(0);
});

it('rolls up additively — a parent equals the sum of its children\'s numerators and denominators', function () {
    $a1 = kpiTrooper($this->sectionA);
    $a2 = kpiTrooper($this->sectionA);
    $b1 = kpiTrooper($this->sectionB);
    kpiMark($a1, $this->sectionA, 0, AttendanceStatus::Present, RollcallSignature::WillAttend);
    kpiMark($a1, $this->sectionA, 1, AttendanceStatus::Missed);
    kpiMark($a2, $this->sectionA, 0, AttendanceStatus::Missed);
    kpiMark($a2, $this->sectionA, 1, AttendanceStatus::Missed, RollcallSignature::CannotAttend);
    kpiMark($b1, $this->sectionB, 2, AttendanceStatus::Loa);
    kpiMark($b1, $this->sectionB, 0, AttendanceStatus::Makeup, RollcallSignature::WillAttend);

    $parent = $this->kpis->forUnit($this->battalion, kpiWeek());
    $a = $this->kpis->forUnit($this->sectionA, kpiWeek());
    $b = $this->kpis->forUnit($this->sectionB, kpiWeek());

    expect($parent->strength)->toBe($a->strength + $b->strength)
        ->and($parent->rc30d)->toEqual(new Ratio($a->rc30d->numerator + $b->rc30d->numerator, $a->rc30d->denominator + $b->rc30d->denominator))
        ->and($parent->sp30d)->toEqual(new Ratio($a->sp30d->numerator + $b->sp30d->numerator, $a->sp30d->denominator + $b->sp30d->denominator))
        ->and($parent->atRisk)->toBe($a->atRisk + $b->atRisk)
        ->and($parent->onLoa)->toBe($a->onLoa + $b->onLoa)
        ->and($parent->makeups)->toBe($a->makeups + $b->makeups)
        ->and($parent->atRisk)->toBe(1);
});

it('is deterministic — the same unit and week computed later, with unchanged data, is identical', function () {
    $trooper = kpiTrooper($this->sectionA);
    kpiMark($trooper, $this->sectionA, 1, AttendanceStatus::Missed, RollcallSignature::WillAttend);
    kpiMark($trooper, $this->sectionA, 0, AttendanceStatus::Makeup);

    $first = $this->kpis->forUnit($this->regiment, kpiWeek());
    $this->travel(3)->weeks();   // a recompute must not depend on the clock
    $second = $this->kpis->forUnit($this->regiment, kpiWeek());

    expect($second)->toEqual($first)
        ->and(UnitKpis::fromArray($first->toArray()))->toEqual($first);
});

it('agrees with the per-trooper attendance the roster reuses', function () {
    $a = kpiTrooper($this->sectionA);
    $b = kpiTrooper($this->sectionA);
    kpiMark($a, $this->sectionA, 0, AttendanceStatus::Present, RollcallSignature::WillAttend);
    kpiMark($b, $this->sectionA, 0, AttendanceStatus::Missed);

    $byTrooper = $this->kpis->trooperAttendance($this->sectionA, kpiWeek());

    expect($byTrooper->keys()->all())->toBe([$a->id, $b->id])
        ->and($byTrooper->every(fn ($row) => $row instanceof TrooperAttendance))->toBeTrue()
        ->and($byTrooper[$a->id]->rollcall)->toEqual(new Ratio(1, 4))
        ->and($byTrooper[$a->id]->practice)->toEqual(new Ratio(1, 1))
        ->and($byTrooper[$b->id]->missed)->toBe(1)
        ->and($byTrooper[$b->id]->onLoa)->toBeFalse()
        ->and($byTrooper[$b->id]->makeups)->toBe(0);
});

it('lists an assigned trooper with no practice rows at all, with nothing held', function () {
    $trooper = kpiTrooper($this->sectionA);

    $attendance = $this->kpis->trooperAttendance($this->sectionA, kpiWeek())->sole();

    expect($attendance->trooperId)->toBe($trooper->id)
        ->and($attendance->rollcall)->toEqual(new Ratio(0, 4))
        ->and($attendance->practice)->toEqual(new Ratio(0, 0))
        ->and($attendance->atRisk())->toBeFalse();
});

it('accepts week_start given in another timezone as long as it is the same instant', function () {
    $trooper = kpiTrooper($this->sectionA);
    kpiMark($trooper, $this->sectionA, 0, AttendanceStatus::Makeup);

    $sameInstant = kpiWeek()->setTimezone('America/New_York');

    expect($this->kpis->forUnit($this->sectionA, $sameInstant)->makeups)->toBe(1);
});

it('refuses a week_start that is not a Saturday 00:00 UTC', function () {
    $this->kpis->forUnit($this->sectionA, kpiWeek()->addDay());
})->throws(InvalidArgumentException::class);

it('computes a regiment-sized subtree in a constant number of queries', function () {
    foreach (range(1, 30) as $i) {
        $trooper = kpiTrooper($i % 2 ? $this->sectionA : $this->sectionB);
        kpiMark($trooper, $i % 2 ? $this->sectionA : $this->sectionB, $i % 4, AttendanceStatus::Present, RollcallSignature::WillAttend);
    }

    DB::flushQueryLog();
    DB::enableQueryLog();
    $this->kpis->forUnit($this->regiment, kpiWeek());
    $queries = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($queries)->toBeLessThanOrEqual(2);
});
