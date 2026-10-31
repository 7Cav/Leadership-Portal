<?php

use App\Commands\Capture\CaptureSectionPractice;
use App\Commands\Capture\CaptureServer;
use App\Commands\Capture\CaptureSource;
use App\Commands\Capture\CaptureSources;
use App\Integration\Crcon\Data\CrconPresence;
use App\Jobs\CaptureSectionPracticeJob;
use App\Models\SectionPractice;
use App\Models\SectionPracticeSlot;
use App\Models\SyncRun;
use App\Models\Unit;
use App\Models\UnitType;
use App\Models\WeeklyReport;
use App\Repositories\SectionPracticeRepository;
use App\Repositories\SyncRunRepository;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;

// #393: every ten minutes, queue a capture of each section practice whose one-hour slot window ended this Cav week and
// was not captured or tried yet, for exactly that window, as `system`.

beforeEach(function () {
    Queue::fake();
    Cache::flush();
    // A readable training server for every game, so "configured" is a test choice, not the env's.
    app()->instance(CaptureSources::class, new CaptureSources([], fn () => [
        new CaptureServer(1, 'Training', [new CaptureSource('crcon', 'crcon:1', fn ($s, $e) => new CrconPresence([], $s, $e, CarbonImmutable::now()->toDateTimeImmutable()), true, 1)]),
    ]));
    $this->section = sectionUnit(['callsign' => '1/1/B/2-7', 'game_id' => game('hll')->id]);
    $this->week = CarbonImmutable::parse('2026-10-24 00:00:00', 'UTC');
});

afterEach(fn () => Carbon::setTestNow());

/** A section at the root of the tree, its path its own id, so its game is read from its chain. */
function sectionUnit(array $attributes): Unit
{
    $unit = Unit::factory()->create(['type' => UnitType::Section, ...$attributes]);
    $unit->forceFill(['path' => "{$unit->id}/"])->save();

    return $unit;
}

function slotFor(Unit $section, int $weekday, int $startMinute): void
{
    SectionPracticeSlot::query()->create(['section_id' => $section->id, 'weekday' => $weekday, 'start_minute' => $startMinute]);
}

function captureAt(string $now): void
{
    Carbon::setTestNow(CarbonImmutable::parse($now, 'UTC'));
    test()->artisan('portal:capture-practices')->assertSuccessful();
}

function queuedCaptures(): array
{
    return Queue::pushed(CaptureSectionPracticeJob::class)->map(fn (CaptureSectionPracticeJob $job) => $job->command)->all();
}

it('is scheduled every ten minutes without overlapping', function () {
    test()->artisan('list');
    $event = collect(app(Schedule::class)->events())->first(fn ($event) => str_contains((string) $event->command, 'portal:capture-practices'));

    expect($event)->not->toBeNull()
        ->and($event->getExpression())->toBe('*/10 * * * *')
        ->and($event->withoutOverlapping)->toBeTrue();
});

it('queues the practice once its hour is over, for exactly that window, as system', function () {
    slotFor($this->section, 4, 120); // Thu 0200Z

    captureAt('2026-10-29 02:50:00');
    expect(queuedCaptures())->toBe([]);

    captureAt('2026-10-29 03:00:00');
    $queued = queuedCaptures();

    expect($queued)->toHaveCount(1)
        ->and($queued[0])->toBeInstanceOf(CaptureSectionPractice::class)
        ->and($queued[0]->triggeredBy)->toBe('system')
        ->and($queued[0]->gameId)->toBe(game('hll')->id)
        ->and(CarbonImmutable::instance($queued[0]->windowStart)->toIso8601ZuluString())->toBe('2026-10-29T02:00:00Z')
        ->and(CarbonImmutable::instance($queued[0]->windowEnd)->toIso8601ZuluString())->toBe('2026-10-29T03:00:00Z')
        ->and(SectionPractice::query()->find($queued[0]->sectionPracticeId)->week_start->equalTo($this->week))->toBeTrue();
});

it('does not queue the same practice again on the next tick, nor once it was attempted or captured', function () {
    slotFor($this->section, 4, 120);

    captureAt('2026-10-29 03:00:00');
    captureAt('2026-10-29 03:10:00');
    expect(queuedCaptures())->toHaveCount(1);

    // The run happened and failed: still not repeated.
    Cache::flush();
    $practice = SectionPractice::query()->sole();
    $run = app(SyncRunRepository::class)->open('capture', 'system', $practice->id);
    app(SyncRunRepository::class)->fail($run, 'telemetry unavailable');
    captureAt('2026-10-29 03:20:00');
    expect(queuedCaptures())->toHaveCount(1);

    // Captured: not repeated either.
    SyncRun::query()->delete();
    app(SectionPracticeRepository::class)->stampCapture($practice, 'srv', now(), 'crcon');
    captureAt('2026-10-29 03:30:00');
    expect(queuedCaptures())->toHaveCount(1);
});

it('places a Wednesday 2359Z practice ending Thursday in its own week', function () {
    slotFor($this->section, 3, 1439);

    captureAt('2026-10-29 00:58:00');
    expect(queuedCaptures())->toBe([]);

    captureAt('2026-10-29 01:00:00');
    expect(CarbonImmutable::instance(queuedCaptures()[0]->windowEnd)->toIso8601ZuluString())->toBe('2026-10-29T00:59:00Z');
});

it('captures a Friday 2330Z practice after the week turns, for the week it started in', function () {
    slotFor($this->section, 5, 23 * 60 + 30);

    captureAt('2026-10-31 00:40:00'); // Saturday: the new week
    $queued = queuedCaptures();

    expect($queued)->toHaveCount(1)
        ->and(CarbonImmutable::instance($queued[0]->windowStart)->toIso8601ZuluString())->toBe('2026-10-30T23:30:00Z')
        ->and(SectionPractice::query()->find($queued[0]->sectionPracticeId)->week_start->equalTo($this->week))->toBeTrue();
});

it('skips a cancelled week, uses a moved one, and skips a locked week, a section with no game and no slot', function () {
    slotFor($this->section, 4, 120);
    $practices = app(SectionPracticeRepository::class);
    $practices->changeWeek($practices->findOrCreate($this->section->id, $this->week), null, true);
    captureAt('2026-10-29 04:00:00');
    expect(queuedCaptures())->toBe([]);

    $practices->changeWeek(SectionPractice::query()->sole(), CarbonImmutable::parse('2026-10-29 05:00:00', 'UTC'), false);
    captureAt('2026-10-29 05:30:00');
    expect(queuedCaptures())->toBe([]);
    captureAt('2026-10-29 06:00:00');
    expect(CarbonImmutable::instance(queuedCaptures()[0]->windowStart)->toIso8601ZuluString())->toBe('2026-10-29T05:00:00Z');

    $locked = sectionUnit(['game_id' => game('hll')->id]);
    slotFor($locked, 4, 120);
    WeeklyReport::factory()->sent()->create(['unit_id' => $locked->id, 'week_start' => $this->week]);
    $gameless = sectionUnit(['game_id' => null]);
    slotFor($gameless, 4, 120);
    sectionUnit(['game_id' => game('hll')->id]); // no slot

    captureAt('2026-10-29 06:10:00');
    expect(queuedCaptures())->toHaveCount(1)
        ->and(SyncRun::query()->count())->toBe(0)
        ->and(SectionPractice::query()->whereIn('section_id', [$locked->id, $gameless->id])->count())->toBe(0);
});

it('skips a game nothing can be read for', function () {
    app()->instance(CaptureSources::class, new CaptureSources([], fn () => []));
    slotFor($this->section, 4, 120);

    captureAt('2026-10-29 04:00:00');
    expect(queuedCaptures())->toBe([]);
});

it('lists what it would queue and why others are skipped on --dry-run, and queues nothing', function () {
    slotFor($this->section, 4, 120);
    $later = sectionUnit(['callsign' => '1/2/B/2-7', 'game_id' => game('hll')->id]);
    slotFor($later, 5, 120);
    Carbon::setTestNow(CarbonImmutable::parse('2026-10-29 04:00:00', 'UTC'));

    $this->artisan('portal:capture-practices --dry-run')
        ->expectsOutputToContain('Would queue 1/1/B/2-7: Thu 29 Oct 0200Z to 0300Z')
        ->expectsOutputToContain('Skipped 1/2/B/2-7: practice not over yet, it ends Fri 0300Z')
        ->assertSuccessful();

    expect(queuedCaptures())->toBe([])
        ->and(SectionPractice::query()->count())->toBe(0);
});
