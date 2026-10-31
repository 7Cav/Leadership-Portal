<?php

use App\Kpi\AutoExcused;
use App\Kpi\KpiService;
use App\Kpi\Ratio;
use App\Models\AttendanceSource;
use App\Models\AttendanceStatus;
use App\Models\AuditLogEntry;
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
use Illuminate\Support\Carbon;

// The roll call statuses of 2026-10-30. Excused counts like LOA: the week leaves the RC and SP rates, one rate at a
// time, and a trooper's first Cav week after a transfer into a section, or after joining, is excused automatically
// until a leader records otherwise. Make-up requested counts as signed, and the trooper owes a make-up for that week
// until one is recorded.
// The running week is 03OCT26 (Thursday 08OCT26 now); "weeks back" 3..0 are 12SEP26, 19SEP26, 26SEP26 and 03OCT26.

function rsWeek(int $back = 0): CarbonImmutable
{
    return CarbonImmutable::parse('2026-10-03 00:00:00', 'UTC')->subWeeks($back);
}

/** A trooper placed in `$section` before the placement history began, joined years ago. */
function rsTrooper(Unit $section, array $attributes = []): Trooper
{
    $trooper = Trooper::factory()->create(['tis' => '2015-03-01', ...$attributes]);
    $trooper->forceFill(['primary_section_id' => $section->id])->save();

    return $trooper;
}

function rsMark(Trooper $trooper, Unit $section, int $back, ?AttendanceStatus $status = null, ?RollcallSignature $rollcall = null, AttendanceSource $source = AttendanceSource::Manual): void
{
    $practice = app(SectionPracticeRepository::class)->findOrCreate($section->id, rsWeek($back));

    if ($status !== null) {
        app(AttendanceRepository::class)->markPractice($practice, $trooper->id, $status, $source);
    }

    if ($rollcall !== null) {
        app(AttendanceRepository::class)->signRollcall($practice, $trooper->id, $rollcall);
    }
}

function rsRow(Unit $section, Trooper $trooper)
{
    return app(KpiService::class)->trooperAttendance($section, rsWeek())[$trooper->id];
}

beforeEach(function () {
    Carbon::setTestNow(CarbonImmutable::parse('2026-10-08 16:20:00', 'UTC'));
    $units = app(UnitRepository::class);
    $regiment = $units->create(UnitType::Regiment, '7th Cavalry Regiment', null, null);
    $battalion = $units->create(UnitType::Battalion, '1st Battalion', '1-7', $regiment->id);
    $company = $units->create(UnitType::Company, 'Alpha', 'A/1-7', $battalion->id);
    $platoon = $units->create(UnitType::Platoon, '1st Platoon', '1/A/1-7', $company->id);
    $this->a = $units->create(UnitType::Section, 'Section A', '1/1/A/1-7', $platoon->id);
    $this->b = $units->create(UnitType::Section, 'Section B', '2/1/A/1-7', $platoon->id);
    $this->leaderB = User::factory()->commanding($this->b)->create();

    // A member of B who attends every week, so B's practices are held.
    $this->stayer = rsTrooper($this->b);
    foreach (range(0, 3) as $back) {
        rsMark($this->stayer, $this->b, $back, AttendanceStatus::Present, RollcallSignature::WillAttend);
    }
});

afterEach(fn () => Carbon::setTestNow());

describe('Excused', function () {
    it('leaves an excused practice and an excused signature out of the rates, neither met nor missed, like leave', function () {
        $trooper = rsTrooper($this->b);
        rsMark($trooper, $this->b, 3, AttendanceStatus::Present, RollcallSignature::WillAttend);
        rsMark($trooper, $this->b, 2, AttendanceStatus::Excused, RollcallSignature::Excused);
        rsMark($trooper, $this->b, 1, AttendanceStatus::Missed);
        rsMark($trooper, $this->b, 0, AttendanceStatus::Present, RollcallSignature::WillAttend);

        $row = rsRow($this->b, $trooper);

        expect($row->practice)->toEqual(new Ratio(2, 3))
            ->and($row->rollcall)->toEqual(new Ratio(2, 3))
            ->and($row->missed)->toBe(1)
            ->and($row->atRisk())->toBeFalse();
    });

    it('takes each half out on its own: an excused signature leaves RC only, an excused practice leaves SP only', function () {
        $signatureOnly = rsTrooper($this->b);
        rsMark($signatureOnly, $this->b, 0, AttendanceStatus::Missed, RollcallSignature::Excused);
        $practiceOnly = rsTrooper($this->b);
        rsMark($practiceOnly, $this->b, 0, AttendanceStatus::Excused, RollcallSignature::Unsigned);

        expect(rsRow($this->b, $signatureOnly)->rollcall)->toEqual(new Ratio(0, 3))
            ->and(rsRow($this->b, $signatureOnly)->practice)->toEqual(new Ratio(0, 4))
            ->and(rsRow($this->b, $practiceOnly)->rollcall)->toEqual(new Ratio(0, 4))
            ->and(rsRow($this->b, $practiceOnly)->practice)->toEqual(new Ratio(0, 3));
    });

    it('never holds a practice, as LOA does not', function () {
        $only = rsTrooper($this->a);
        rsMark($only, $this->a, 0, AttendanceStatus::Excused);
        $other = rsTrooper($this->a);

        $mark = app(AttendanceRepository::class)->countingMarksOf([$other->id, $only->id], rsWeek(), rsWeek())->keyBy('trooperId');

        expect($mark[$only->id]->held)->toBeFalse()
            ->and(rsRow($this->a, $other)->practice)->toEqual(new Ratio(0, 0));
    });
});

describe('the automatic Excused', function () {
    it('excuses a trooper\'s first week after a transfer into the section, on both rates', function () {
        $mover = rsTrooper($this->a);
        $this->travelTo(rsWeek(1)->addDays(4)->setTime(19, 0));
        app(TrooperRepository::class)->updatePrimarySections([$mover->cav_id => $this->b->id]);
        $this->travelTo(CarbonImmutable::parse('2026-10-08 16:20:00', 'UTC'));

        $row = rsRow($this->b, $mover);

        expect(app(AutoExcused::class)->forTroopersOverWeeks([$mover->id], rsWeek(3), 4))->toBe([$mover->id => [rsWeek(1)->toDateString() => AutoExcused::TRANSFER]])
            // B held weeks 1 and 0; the mover was marked in neither. Week 1 is excused, week 0 counts.
            ->and($row->practice)->toEqual(new Ratio(0, 1))
            ->and($row->rollcall)->toEqual(new Ratio(0, 3));
    });

    it('excuses a new recruit\'s first week, from their join date', function () {
        $recruit = rsTrooper($this->b, ['tis' => rsWeek()->addDays(2)->toDateString()]);
        $veteran = rsTrooper($this->b);

        expect(app(AutoExcused::class)->forTroopers([$recruit->id, $veteran->id], rsWeek()))->toBe([$recruit->id => AutoExcused::RECRUIT])
            ->and(rsRow($this->b, $recruit)->practice)->toEqual(new Ratio(0, 3))
            ->and(rsRow($this->b, $recruit)->rollcall)->toEqual(new Ratio(0, 3))
            ->and(rsRow($this->b, $veteran)->practice)->toEqual(new Ratio(0, 4))
            ->and(rsRow($this->b, $veteran)->rollcall)->toEqual(new Ratio(0, 4));
    });

    it('gives way to whatever a leader records, half by half', function () {
        $recruit = rsTrooper($this->b, ['tis' => rsWeek()->addDays(2)->toDateString()]);
        rsMark($recruit, $this->b, 0, AttendanceStatus::Missed, RollcallSignature::WillAttend);

        $row = rsRow($this->b, $recruit);

        expect($row->practice)->toEqual(new Ratio(0, 4))
            ->and($row->missed)->toBe(1)
            ->and($row->rollcall)->toEqual(new Ratio(1, 4));
    });

    it('is not overridden by an automatic miss, only by a leader or by attending', function () {
        $missed = rsTrooper($this->b, ['tis' => rsWeek()->addDays(2)->toDateString()]);
        rsMark($missed, $this->b, 0, AttendanceStatus::Missed, source: AttendanceSource::Auto);
        $attended = rsTrooper($this->b, ['tis' => rsWeek()->addDays(2)->toDateString()]);
        rsMark($attended, $this->b, 0, AttendanceStatus::Present, source: AttendanceSource::Auto);

        expect(rsRow($this->b, $missed)->practice)->toEqual(new Ratio(0, 3))
            ->and(rsRow($this->b, $missed)->missed)->toBe(0)
            ->and(rsRow($this->b, $attended)->practice)->toEqual(new Ratio(1, 4));
    });

    it('excuses nobody for the placement history\'s first rows, which have no start', function () {
        expect(app(AutoExcused::class)->forTroopersOverWeeks([$this->stayer->id], rsWeek(3), 4))->toBe([]);
    });

    it('shows on the roll call, on the roster and on the report, as the server reads it', function () {
        $recruit = rsTrooper($this->b, ['tis' => rsWeek()->addDays(2)->toDateString()]);

        $this->actingAs($this->leaderB)->get("/rollcall/{$this->b->id}?week=2026-10-03")->assertOk()
            ->assertInertia(fn ($page) => $page->where('troopers', fn ($rows) => collect($rows)->firstWhere('id', $recruit->id)['excused'] === 'recruit'
                && collect($rows)->firstWhere('id', $this->stayer->id)['excused'] === null));

        $roster = collect($this->actingAs($this->leaderB)->get("/roster/{$this->b->id}")->viewData('page')['props']['troopers'] ?? [])->firstWhere('id', $recruit->id);
        expect((array) ($roster['excusedWeeks'] ?? []))->toBe(['2026-10-03' => 'recruit']);

        $report = collect($this->actingAs($this->leaderB)->get("/reports/{$this->b->id}/2026-10-03")->assertOk()->viewData('page')['props']['troopers'])->firstWhere('id', $recruit->id);
        expect($report['excused'])->toBe('recruit');
    });
});

describe('Make-up requested', function () {
    it('counts as signed', function () {
        $trooper = rsTrooper($this->b);
        rsMark($trooper, $this->b, 1, AttendanceStatus::Missed, RollcallSignature::MakeupRequested);
        rsMark($trooper, $this->b, 0, AttendanceStatus::Present, RollcallSignature::CannotAttend);

        expect(rsRow($this->b, $trooper)->rollcall)->toEqual(new Ratio(2, 4));
    });

    it('lists the trooper as owing a make-up for that week until a make-up complete is recorded by hand', function () {
        $trooper = rsTrooper($this->b);
        rsMark($trooper, $this->b, 1, AttendanceStatus::Missed, RollcallSignature::MakeupRequested);
        rsMark($trooper, $this->b, 0, rollcall: RollcallSignature::MakeupRequested);

        expect(rsRow($this->b, $trooper)->makeupOwedWeeks)->toBe([rsWeek(1)->toDateString(), rsWeek(0)->toDateString()]);

        rsMark($trooper, $this->b, 1, AttendanceStatus::Makeup);

        expect(rsRow($this->b, $trooper)->makeupOwedWeeks)->toBe([rsWeek(0)->toDateString()])
            // Made up: it counts for SP as before.
            ->and(rsRow($this->b, $trooper)->practice)->toEqual(new Ratio(1, 4));
    });

    it('stops owing once the guest entry\'s automatic make-up lands on their own practice', function () {
        $trooper = rsTrooper($this->b);
        rsMark($trooper, $this->b, 0, rollcall: RollcallSignature::MakeupRequested);

        app(AttendanceRepository::class)->markMakeupAuto(app(SectionPracticeRepository::class)->findOrCreate($this->b->id, rsWeek()), $trooper->id);

        expect(rsRow($this->b, $trooper)->makeupOwedWeeks)->toBe([]);
    });

    it('settles when the trooper attends after all, is excused, or is on leave', function () {
        $attended = rsTrooper($this->b);
        rsMark($attended, $this->b, 0, AttendanceStatus::Present, RollcallSignature::MakeupRequested);
        $excused = rsTrooper($this->b);
        rsMark($excused, $this->b, 0, AttendanceStatus::Excused, RollcallSignature::MakeupRequested);

        expect(rsRow($this->b, $attended)->makeupOwedWeeks)->toBe([])
            ->and(rsRow($this->b, $excused)->makeupOwedWeeks)->toBe([]);
    });

    it('puts the owing trooper on the roll call page and in the save\'s answer', function () {
        $trooper = rsTrooper($this->b);
        rsMark($trooper, $this->b, 1, AttendanceStatus::Missed, RollcallSignature::MakeupRequested);

        $this->actingAs($this->leaderB)->get("/rollcall/{$this->b->id}?week=2026-10-03")->assertOk()
            ->assertInertia(fn ($page) => $page->where('makeupOwed', [['trooperId' => $trooper->id, 'weeks' => ['2026-09-26']]]));

        $this->actingAs($this->leaderB)->postJson("/rollcall/{$this->b->id}", [
            'week' => '2026-10-03',
            'marks' => [['trooper_id' => $trooper->id, 'rollcall' => 'makeup_requested']],
        ])->assertOk()->assertJsonPath('makeupOwed', [['trooperId' => $trooper->id, 'weeks' => ['2026-09-26', '2026-10-03']]]);
    });

    it('lists the owing trooper on Home\'s Needs attention, linked to their card on the roll call', function () {
        $trooper = rsTrooper($this->b);
        rsMark($trooper, $this->b, 0, AttendanceStatus::Missed, RollcallSignature::MakeupRequested);

        $this->actingAs($this->leaderB)->get('/home')->assertOk()->assertInertia(fn ($page) => $page
            ->where('attention.totals.makeupOwed', 1)
            ->where('attention.items', fn ($items) => collect($items)->contains(fn ($item) => $item['kind'] === 'makeup_owed'
                && $item['trooper']['id'] === $trooper->id
                && $item['weeks'] === ['2026-10-03']
                && $item['href'] === route('rollcall.show', ['unit' => $this->b->id, 'week' => '2026-10-03', 'focus' => $trooper->id]))));
    });
});

it('accepts Excused and Make-up requested from the roll call screen, and writes them through the audited command', function () {
    $trooper = rsTrooper($this->b);

    $this->actingAs($this->leaderB)->postJson("/rollcall/{$this->b->id}", [
        'week' => '2026-10-03',
        'marks' => [['trooper_id' => $trooper->id, 'status' => 'excused', 'rollcall' => 'makeup_requested']],
    ])->assertOk();

    $row = app(AttendanceRepository::class)->countingMarksOf([$trooper->id], rsWeek(), rsWeek())->sole();

    expect($row->status)->toBe(AttendanceStatus::Excused)
        ->and($row->rollcall)->toBe(RollcallSignature::MakeupRequested)
        ->and($row->source)->toBe(AttendanceSource::Manual)
        ->and(AuditLogEntry::query()->where('action', 'attendance.marked')->count())->toBe(1);
});
