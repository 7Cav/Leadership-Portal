<?php

use App\Commands\Attendance\MarkAttendanceHandler;
use App\Commands\Capture\CaptureSectionPractice;
use App\Commands\Capture\CaptureSectionPracticeHandler;
use App\Commands\Capture\CaptureSource;
use App\Commands\Capture\CaptureSources;
use App\Integration\Crcon\Data\CrconPlayer;
use App\Integration\Crcon\Data\CrconPresence;
use App\Kpi\Headcount;
use App\Kpi\KpiService;
use App\Models\Attendance;
use App\Models\AttendanceSource;
use App\Models\AttendanceStatus;
use App\Models\RollcallSignature;
use App\Models\SectionPractice;
use App\Models\Trooper;
use App\Models\TrooperGameIdentity;
use App\Models\Unit;
use App\Models\UnitType;
use App\Models\User;
use App\Repositories\AttendanceRepository;
use App\Repositories\SectionPracticeRepository;
use App\Repositories\TrooperPlacementRepository;
use App\Repositories\TrooperRepository;
use App\Repositories\UnitRepository;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;

// The 2026-10-29 audit, second half: once a week has ended, every surface that counts its rollcalls reads the roster
// the week ended with, the one its rollcall, marks, report and KPIs already read. The rollcall picker's progress, Home's
// step matrix and live roll-up rows, the report chain's sections, and a capture's auto-marks. The case is the closing
// week: the previous Cav week, whose report is still due on the Saturday a sync moves or drops someone.

function consistencyAt(string $moment): void
{
    Carbon::setTestNow(CarbonImmutable::parse($moment, 'UTC'));
}

/** Saturday 10OCT26 08:00, with the 03OCT26 reports due at 23:59: the mover goes from A to B, the leaver leaves the Cav. */
function consistencySaturday(object $test): void
{
    consistencyAt('2026-10-10 08:00:00');
    $test->troopers->updatePrimarySections([$test->mover->cav_id => $test->b->id]);
    $test->troopers->markDepartedExcept([], [$test->c->id]);
    consistencyAt('2026-10-10 12:00:00');
}

/** A capture of `$practice` that saw these troopers on the server, in the practice's own week. */
function consistencyCapture(SectionPractice $practice, Trooper ...$seen)
{
    $game = game('hll');
    $players = [];

    foreach ($seen as $trooper) {
        $platformId = sprintf('76561190%09d', $trooper->id);

        if (! TrooperGameIdentity::query()->where('trooper_id', $trooper->id)->exists()) {
            TrooperGameIdentity::factory()->create(['trooper_id' => $trooper->id, 'game_id' => $game->id, 'platform_id' => $platformId]);
        }

        $players[] = new CrconPlayer("Player {$trooper->id}", $platformId);
    }

    $start = CarbonImmutable::parse('2026-10-08 18:00:00', 'UTC')->toDateTimeImmutable();
    $end = CarbonImmutable::parse('2026-10-08 19:30:00', 'UTC')->toDateTimeImmutable();
    app()->instance(CaptureSources::class, new CaptureSources([$game->interface->value => [
        new CaptureSource('crcon', 'crcon-server-1', fn ($from, $to) => new CrconPresence($players, $from, $to, $end)),
    ]]));

    return app(CaptureSectionPracticeHandler::class)->handle(new CaptureSectionPractice($practice->id, $game->id, $start, $end, 'system'));
}

beforeEach(function () {
    $units = app(UnitRepository::class);
    $reg = $units->create(UnitType::Regiment, 'Reg', null, null);
    $bn = $units->create(UnitType::Battalion, 'Bn', '1-7', $reg->id);
    $this->company = $units->create(UnitType::Company, 'Alpha', 'A/1-7', $bn->id);
    $this->p1 = $units->create(UnitType::Platoon, '1st Platoon', '1/A/1-7', $this->company->id);
    $this->p2 = $units->create(UnitType::Platoon, '2nd Platoon', '2/A/1-7', $this->company->id);
    $this->a = $units->create(UnitType::Section, 'A', '1/1/A/1-7', $this->p1->id);
    $this->b = $units->create(UnitType::Section, 'B', '1/2/A/1-7', $this->p2->id);
    $this->c = $units->create(UnitType::Section, 'C', '2/2/A/1-7', $this->p2->id);
    $this->co = User::factory()->commanding($this->company)->create();
    $this->leaderA = User::factory()->commanding($this->a)->create();

    // Placed by the sync before the week of 03OCT26: the stayer and the mover in A, the leaver alone in C.
    consistencyAt('2026-09-30 12:00:00');
    $this->stayer = Trooper::factory()->create();
    $this->mover = Trooper::factory()->create();
    $this->leaver = Trooper::factory()->create();
    $this->troopers = app(TrooperRepository::class);
    $this->troopers->updatePrimarySections([
        $this->stayer->cav_id => $this->a->id,
        $this->mover->cav_id => $this->a->id,
        $this->leaver->cav_id => $this->c->id,
    ]);
    $this->week = CarbonImmutable::parse('2026-10-03', 'UTC');

    // Wednesday of that week: A practises, and its leader marks the stayer present.
    consistencyAt('2026-10-07 20:00:00');
    $this->actingAs($this->leaderA)->postJson("/rollcall/{$this->a->id}", ['week' => '2026-10-03', 'marks' => [['trooper_id' => $this->stayer->id, 'status' => 'present']]])->assertOk();
});

afterEach(fn () => Carbon::setTestNow());

it('counts the rollcall picker\'s progress over the roster the closing week ended with', function () {
    consistencySaturday($this);

    $sections = collect($this->actingAs($this->co)->get("/rollcall/{$this->a->id}?week=2026-10-03")->assertOk()->viewData('page')['props']['sections'])
        ->keyBy('id');

    // A ended the week with the stayer (marked) and the mover (not yet); B had nobody; C had the leaver.
    expect($sections[$this->a->id]['progress'])->toMatchArray(['strength' => 2, 'unmarked' => 1, 'unsigned' => 2, 'started' => true])
        ->and($sections[$this->b->id]['progress'])->toMatchArray(['strength' => 0, 'unmarked' => 0])
        ->and($sections[$this->c->id]['progress'])->toMatchArray(['strength' => 1, 'unmarked' => 1]);

    // The running week counts who is placed now (named: on this Saturday the bare URL opens the week still due).
    $running = collect($this->actingAs($this->co)->get("/rollcall/{$this->a->id}?week=2026-10-10")->assertOk()->viewData('page')['props']['sections'])->keyBy('id');
    expect($running[$this->a->id]['progress']['strength'])->toBe(1)
        ->and($running[$this->b->id]['progress']['strength'])->toBe(1)
        ->and($running[$this->c->id]['progress']['strength'])->toBe(0);
});

it('counts Home\'s step matrix and live roll-up rows over the roster the closing week ended with', function () {
    consistencySaturday($this);

    $props = $this->actingAs($this->co)->get('/home')->assertOk()->viewData('page')['props'];
    $steps = collect($props['steps'])->keyBy('id');
    $units = collect($props['units'])->keyBy('id');
    $kpis = app(KpiService::class);

    // The report still due is 03OCT26's: A and C take part (someone ended the week in them), B does not.
    expect($props['week']['start'])->toBe('2026-10-03T00:00:00+00:00')
        ->and($steps->keys()->sort()->values()->all())->toBe([$this->a->id, $this->c->id])
        ->and($steps[$this->a->id])->toMatchArray(['strength' => 2, 'marked' => 1, 'held' => true])
        ->and($steps[$this->c->id])->toMatchArray(['strength' => 1, 'marked' => 0]);

    // Each live platoon row is its subtree's KPIs for the week: the mover in 1st Platoon, the leaver in 2nd.
    expect($units[$this->p1->id]['live'])->toBeTrue()
        ->and($units[$this->p1->id]['kpis']['strength'])->toBe(2)
        ->and($units[$this->p1->id]['kpis']['strength'])->toBe($kpis->forUnit($this->p1, $this->week)->strength)
        ->and($units[$this->p1->id]['kpis']['sp'])->toBe(['numerator' => 1, 'denominator' => 2])
        ->and($units[$this->p2->id]['kpis']['strength'])->toBe(1)
        ->and($units[$this->p2->id]['kpis']['strength'])->toBe($kpis->forUnit($this->p2, $this->week)->strength);
});

it('waits in the report chain on exactly the sections whose closing-week rollcall lists someone', function () {
    consistencySaturday($this);

    $sections = collect([$this->a, $this->b, $this->c]);
    $reporting = app(Headcount::class)->reportingLineUnits($sections, $this->week)->pluck('id')->all();
    $withRoster = $sections->filter(fn (Unit $section) => $this->troopers->onRollcall($section, $this->week)->troopers->isNotEmpty())->pluck('id')->all();

    // The leaver's section still reports for the week he served; B, which the mover joined after it, does not.
    expect($reporting)->toBe([$this->a->id, $this->c->id])
        ->and($reporting)->toBe($withRoster)
        ->and(app(TrooperPlacementRepository::class)->unitIdsPlacedByWeek($this->week, $this->week)['2026-10-03'])
        ->toBe([$this->a->id, $this->c->id]);

    // He served the week he left in (10OCT26) too, and none after it.
    expect(app(TrooperPlacementRepository::class)->unitIdsPlacedByWeek($this->week->addWeek(), $this->week->addWeeks(2)))
        ->toBe(['2026-10-10' => [$this->a->id, $this->b->id, $this->c->id], '2026-10-17' => [$this->a->id, $this->b->id]]);
});

it('takes a section a trooper left during the week out of that week\'s chain, as its rollcall does', function () {
    // Thursday 08OCT26, mid-week: the leaver, alone in C, moves to A.
    consistencyAt('2026-10-08 09:00:00');
    $this->troopers->updatePrimarySections([$this->leaver->cav_id => $this->a->id]);
    consistencyAt('2026-10-10 12:00:00');

    expect($this->troopers->onRollcall($this->c, $this->week)->troopers)->toBeEmpty()
        ->and($this->troopers->onRollcall($this->a, $this->week)->joined)->toBe([$this->leaver->id])
        ->and(app(Headcount::class)->reportingLineUnits(collect([$this->a, $this->b, $this->c]), $this->week)->pluck('id')->all())->toBe([$this->a->id])
        ->and(collect($this->actingAs($this->co)->get('/home')->assertOk()->viewData('page')['props']['steps'])->pluck('id')->all())->toBe([$this->a->id]);
});

it('pre-marks a capture of the closing week\'s practice by that week\'s roster', function () {
    consistencySaturday($this);
    $practices = app(SectionPracticeRepository::class);
    $practiceA = $practices->find($this->a->id, $this->week);

    // A's practice: the mover, moved since, is A's that week and is marked; the stayer keeps his leader's mark.
    $result = consistencyCapture($practiceA, $this->mover, $this->stayer);
    $rowOf = fn (SectionPractice $practice, Trooper $trooper) => Attendance::query()->where(['section_practice_id' => $practice->id, 'trooper_id' => $trooper->id])->first();

    expect(collect($result->matched)->pluck('trooperId')->sort()->values()->all())->toBe([$this->stayer->id, $this->mover->id])
        ->and($result->otherSection)->toBe([])
        ->and($rowOf($practiceA, $this->mover)?->status)->toBe(AttendanceStatus::Present)
        ->and($rowOf($practiceA, $this->mover)?->source)->toBe(AttendanceSource::Auto)
        ->and($rowOf($practiceA, $this->stayer)?->source)->toBe(AttendanceSource::Manual);

    // C's practice: the leaver served that week, so the capture marks him though he has left since.
    $practiceC = $practices->findOrCreate($this->c->id, $this->week);
    consistencyCapture($practiceC, $this->leaver);
    expect($rowOf($practiceC, $this->leaver)?->status)->toBe(AttendanceStatus::Present);

    // B's practice: the mover was not on B's rollcall that week, so he is surfaced, never marked there.
    $practiceB = $practices->findOrCreate($this->b->id, $this->week);
    $onB = consistencyCapture($practiceB, $this->mover);
    expect($onB->matched)->toBe([])
        ->and($onB->otherSection)->toHaveCount(1)
        ->and(app(MarkAttendanceHandler::class)->preMark($practiceB, [$this->mover->id])->notPlaced)->toBe(1)
        ->and($rowOf($practiceB, $this->mover))->toBeNull();
});

it('pre-marks a capture of the running week by who is placed and serving now', function () {
    consistencySaturday($this);
    $practices = app(SectionPracticeRepository::class);
    $running = $this->week->addWeek();

    $onA = app(MarkAttendanceHandler::class)->preMark($practices->findOrCreate($this->a->id, $running), [$this->mover->id]);
    $onB = app(MarkAttendanceHandler::class)->preMark($practices->findOrCreate($this->b->id, $running), [$this->mover->id]);
    $onC = app(MarkAttendanceHandler::class)->preMark($practices->findOrCreate($this->c->id, $running), [$this->leaver->id]);

    expect($onA->notPlaced)->toBe(1)
        ->and($onB->marked)->toBe(1)
        ->and($onC->notPlaced)->toBe(1);
});

it('counts a given roster exactly as it counts who is placed now', function () {
    // Still the running week (Wednesday 07OCT26): the roster is who is placed now, so both ways must agree.
    $repo = app(AttendanceRepository::class);
    $practices = app(SectionPracticeRepository::class);
    $repo->signRollcall($practices->find($this->a->id, $this->week), $this->mover->id, RollcallSignature::WillAttend);
    $repo->markPractice($practices->findOrCreate($this->c->id, $this->week), $this->leaver->id, AttendanceStatus::Loa, AttendanceSource::Manual);
    $repo->markMakeupAuto($practices->findOrCreate($this->b->id, $this->week), $this->mover->id);
    $ids = [$this->a->id, $this->b->id, $this->c->id];
    $roster = $this->troopers->onRollcalls($ids, $this->week);

    foreach ([[], [$this->mover->id], [$this->stayer->id, $this->leaver->id]] as $excused) {
        expect($repo->weekProgress($ids, $this->week, $excused, $roster->sections))->toEqual($repo->weekProgress($ids, $this->week, $excused));
    }

    expect($roster->asPlaced)->toBeFalse()
        ->and($roster->sections)->toBe([$this->stayer->id => $this->a->id, $this->mover->id => $this->a->id, $this->leaver->id => $this->c->id]);
});
