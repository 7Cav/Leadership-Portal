<?php

use App\Commands\Attendance\AttendanceMark;
use App\Commands\Attendance\ConfirmSectionPractice;
use App\Commands\Attendance\ConfirmSectionPracticeHandler;
use App\Commands\Attendance\InvalidAttendanceMarkException;
use App\Commands\Attendance\MarkAttendance;
use App\Commands\Attendance\MarkAttendanceHandler;
use App\Commands\Attendance\MarkRollcallPosted;
use App\Commands\Attendance\MarkRollcallPostedHandler;
use App\Commands\Capture\CaptureSources;
use App\Commands\WeeklyReports\InvalidPlatoonTrainingTopicException;
use App\Commands\WeeklyReports\InvalidReportContentException;
use App\Commands\WeeklyReports\SaveReportDraft;
use App\Commands\WeeklyReports\SaveReportDraftHandler;
use App\Commands\WeeklyReports\SendWeeklyReport;
use App\Commands\WeeklyReports\SendWeeklyReportHandler;
use App\Commands\WeeklyReports\SetPlatoonTrainingTopic;
use App\Commands\WeeklyReports\SetPlatoonTrainingTopicHandler;
use App\Jobs\CaptureSectionPracticeJob;
use App\Models\Attendance;
use App\Models\AttendanceStatus;
use App\Models\PlatoonTrainingTopic;
use App\Models\PracticeCapturePlayer;
use App\Models\SectionPractice;
use App\Models\SyncRun;
use App\Models\SyncRunStatus;
use App\Models\Trooper;
use App\Models\TrooperGameIdentity;
use App\Models\Unit;
use App\Models\UnitCommandMapping;
use App\Models\UnitType;
use App\Models\User;
use App\Models\WeeklyReport;
use App\Models\WeeklyReportStatus;
use App\Repositories\PlatoonTrainingTopicRepository;
use App\Support\Time\WeekOf;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Inertia\Testing\AssertableInertia as Assert;

// The week floor (`portal.week_floor_weeks`, pinned to 20 in phpunit.xml): no rollcall or report
// write reaches a week older than WeekOf::floor(), through a controller or straight through a
// Command handler. Thursday 2026-10-08 is in the week of 2026-10-03, so the floor is 2026-05-16.

const FLOOR_WEEK = '2026-05-16';
const BEFORE_FLOOR_WEEK = '2026-05-09';

beforeEach(function () {
    Carbon::setTestNow(CarbonImmutable::parse('2026-10-08 16:20:00', 'UTC'));
    config(['services.crcon' => ['base_url' => 'https://crcon.test', 'api_key' => 'k'], 'services.battlemetrics' => [], 'services.nightwatch' => []]);
    app()->forgetInstance(CaptureSources::class);

    $this->platoon = Unit::factory()->create(['type' => UnitType::Platoon, 'path' => '960/']);
    $this->section = Unit::factory()->create(['type' => UnitType::Section, 'parent_id' => $this->platoon->id, 'path' => '960/961/']);
    // The section leader by synced billet: command authority, which writes (a staff grant only reads).
    $this->leader = User::factory()->create(['cav_id' => Trooper::factory()->create([
        'cav_position_id' => UnitCommandMapping::factory()->create(['unit_id' => $this->section->id])->cav_position_id,
    ])->cav_id]);
    $this->trooper = Trooper::factory()->create();
    $this->trooper->forceFill(['primary_section_id' => $this->section->id])->save();
    TrooperGameIdentity::factory()->create(['trooper_id' => $this->trooper->id, 'game_id' => game('hll')->id, 'platform_id' => '7656119000000009']);
});

afterEach(fn () => Carbon::setTestNow());

function floorWeek(string $date): CarbonImmutable
{
    return CarbonImmutable::parse("{$date} 00:00:00", 'UTC');
}

function practiceWeeks(): array
{
    return SectionPractice::query()->pluck('week_start')->map->toDateString()->all();
}

it('puts the floor the configured number of weeks before the current week', function () {
    expect(WeekOf::floor()->toDateString())->toBe(FLOOR_WEEK)
        ->and(WeekOf::isBeforeFloor(floorWeek(BEFORE_FLOOR_WEEK)->addDays(6)))->toBeTrue()
        ->and(WeekOf::isBeforeFloor(floorWeek(FLOOR_WEEK)))->toBeFalse();

    config(['portal.week_floor_weeks' => 2]);

    expect(WeekOf::floor()->toDateString())->toBe('2026-09-19');
});

it('saves rollcall marks at the floor and refuses the week before it', function () {
    $marks = [['trooper_id' => $this->trooper->id, 'status' => 'present']];

    $this->actingAs($this->leader)->postJson("/rollcall/{$this->section->id}", ['week' => BEFORE_FLOOR_WEEK, 'marks' => $marks])->assertStatus(422);
    expect(practiceWeeks())->toBe([]);

    $this->actingAs($this->leader)->postJson("/rollcall/{$this->section->id}", ['week' => FLOOR_WEEK, 'marks' => $marks])->assertOk();
    expect(practiceWeeks())->toBe([FLOOR_WEEK]);
});

it('marks the rollcall posted at the floor and refuses the week before it', function () {
    $this->actingAs($this->leader)->postJson("/rollcall/{$this->section->id}/posted", ['week' => BEFORE_FLOOR_WEEK])->assertStatus(422);
    expect(practiceWeeks())->toBe([]);

    $this->actingAs($this->leader)->postJson("/rollcall/{$this->section->id}/posted", ['week' => FLOOR_WEEK])->assertOk();
    expect(practiceWeeks())->toBe([FLOOR_WEEK]);
});

it('confirms a practice at the floor and refuses the week before it', function () {
    $this->actingAs($this->leader)->postJson("/rollcall/{$this->section->id}/confirm", ['week' => BEFORE_FLOOR_WEEK])->assertStatus(422);
    expect(practiceWeeks())->toBe([]);

    $this->actingAs($this->leader)->postJson("/rollcall/{$this->section->id}/confirm", ['week' => FLOOR_WEEK])->assertOk();
    expect(practiceWeeks())->toBe([FLOOR_WEEK]);
});

it('queues a capture retry at the floor and refuses the week before it', function () {
    Queue::fake();
    RateLimiter::clear("practice-capture-retry:{$this->section->id}:".FLOOR_WEEK);
    // A failed capture at the floor, so "Try again" has something to retry.
    $practice = SectionPractice::factory()->create(['section_id' => $this->section->id, 'week_start' => floorWeek(FLOOR_WEEK)]);
    SyncRun::factory()->create([
        'source' => 'capture', 'status' => SyncRunStatus::Failure, 'started_at' => '2026-05-20 20:00:00', 'finished_at' => '2026-05-20 20:00:00',
        'section_practice_id' => $practice->id, 'error' => 'telemetry unavailable: crcon: unreachable',
    ]);

    $this->actingAs($this->leader)->postJson("/rollcall/{$this->section->id}/capture", ['week' => BEFORE_FLOOR_WEEK])->assertStatus(422);
    Queue::assertNothingPushed();
    expect(practiceWeeks())->toBe([FLOOR_WEEK]);

    $this->actingAs($this->leader)->postJson("/rollcall/{$this->section->id}/capture", ['week' => FLOOR_WEEK])->assertStatus(202);
    Queue::assertPushed(CaptureSectionPracticeJob::class, 1);
});

it('links an unmatched player at the floor and refuses the week before it, which would pre-mark that week', function () {
    $ortega = Trooper::factory()->create();
    $ortega->forceFill(['primary_section_id' => $this->section->id])->save();

    foreach ([BEFORE_FLOOR_WEEK, FLOOR_WEEK] as $week) {
        $practice = SectionPractice::factory()->create(['section_id' => $this->section->id, 'week_start' => floorWeek($week)]);
        PracticeCapturePlayer::query()->create([
            'section_practice_id' => $practice->id, 'game_id' => game('hll')->id, 'player_name' => 'Ortega', 'platform_id' => '7656119000000001',
        ]);
    }

    $link = fn (string $week) => $this->actingAs($this->leader)->postJson("/rollcall/{$this->section->id}/unmatched/link", [
        'week' => $week, 'platform' => 'hll', 'platform_id' => '7656119000000001', 'trooper_id' => $ortega->id,
    ]);

    $link(BEFORE_FLOOR_WEEK)->assertStatus(422)->assertJsonPath('message', 'The week of '.BEFORE_FLOOR_WEEK.' is too far back to change.');
    expect(Attendance::query()->count())->toBe(0)
        ->and(TrooperGameIdentity::query()->where('trooper_id', $ortega->id)->count())->toBe(0);

    $link(FLOOR_WEEK)->assertOk()->assertJsonPath('marked', true);
    expect(Attendance::query()->sole()->trooper_id)->toBe($ortega->id);
});

it('saves a report draft at the floor and answers 404 for the week before it', function () {
    $this->actingAs($this->leader)->put("/reports/{$this->section->id}/".BEFORE_FLOOR_WEEK, ['notes' => 'x'])->assertNotFound();
    expect(WeeklyReport::query()->count())->toBe(0);

    $this->actingAs($this->leader)->put("/reports/{$this->section->id}/".FLOOR_WEEK, ['notes' => 'x'])->assertRedirect();
    expect(WeeklyReport::query()->sole()->week_start->toDateString())->toBe(FLOOR_WEEK);
});

it('sends a report at the floor and answers 404 for the week before it', function () {
    $this->actingAs($this->leader)->post("/reports/{$this->section->id}/".BEFORE_FLOOR_WEEK.'/send', ['reason' => 'Late'])->assertNotFound();
    expect(WeeklyReport::query()->count())->toBe(0);

    $this->actingAs($this->leader)->post("/reports/{$this->section->id}/".FLOOR_WEEK.'/send', ['reason' => 'Late'])->assertRedirect();
    expect(WeeklyReport::query()->sole())
        ->week_start->toDateString()->toBe(FLOOR_WEEK)
        ->status->toBe(WeeklyReportStatus::Sent);
});

it('sets and clears a platoon training topic at the floor and answers 404 for the week before it', function () {
    $platoonLeader = User::factory()->create(['cav_id' => Trooper::factory()->create([
        'cav_position_id' => UnitCommandMapping::factory()->create(['unit_id' => $this->platoon->id])->cav_position_id,
    ])->cav_id]);
    app(PlatoonTrainingTopicRepository::class)->set($this->platoon->id, floorWeek(BEFORE_FLOOR_WEEK), 'Old topic', false, $platoonLeader->id);
    $url = fn (string $week) => "/reports/{$this->platoon->id}/{$week}/topic";

    $this->actingAs($platoonLeader)->put($url(BEFORE_FLOOR_WEEK), ['subject' => 'Room clearing'])->assertNotFound();
    $this->actingAs($platoonLeader)->delete($url(BEFORE_FLOOR_WEEK))->assertNotFound();
    expect(PlatoonTrainingTopic::query()->sole()->subject)->toBe('Old topic');

    $this->actingAs($platoonLeader)->get("/reports/{$this->platoon->id}/".BEFORE_FLOOR_WEEK)
        ->assertInertia(fn (Assert $page) => $page->where('canSetTopic', false));

    $this->actingAs($platoonLeader)->put($url(FLOOR_WEEK), ['subject' => 'Room clearing'])->assertRedirect()->assertSessionHasNoErrors();
    expect(PlatoonTrainingTopic::query()->where('week_start', floorWeek(FLOOR_WEEK))->sole()->subject)->toBe('Room clearing');

    $this->actingAs($platoonLeader)->delete($url(FLOOR_WEEK))->assertRedirect()->assertSessionHasNoErrors();
    expect(PlatoonTrainingTopic::query()->where('week_start', floorWeek(FLOOR_WEEK))->exists())->toBeFalse();
});

it('refuses a platoon training topic for a week before the floor in its handler, called directly', function () {
    expect(fn () => app(SetPlatoonTrainingTopicHandler::class)->handle(
        User::factory()->admin()->create(),
        new SetPlatoonTrainingTopic($this->platoon->id, floorWeek(BEFORE_FLOOR_WEEK), 'Room clearing', true),
    ))->toThrow(InvalidPlatoonTrainingTopicException::class, 'too far back');

    expect(PlatoonTrainingTopic::query()->exists())->toBeFalse();
});

it('shows a report below the floor read-only, a sent one as it was frozen, without creating a row', function () {
    $this->actingAs($this->leader)->get("/reports/{$this->section->id}/".BEFORE_FLOOR_WEEK)
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('week.beforeFloor', true)->where('week.previous', '2026-05-02')->where('report.status', 'none'));
    expect(WeeklyReport::query()->count())->toBe(0);

    $this->actingAs($this->leader)->get("/reports/{$this->section->id}/".FLOOR_WEEK)
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('week.beforeFloor', false)->where('week.previous', BEFORE_FLOOR_WEEK));

    // A report sent back when its week was writable stays readable once the floor passes it.
    $this->actingAs($this->leader)->post("/reports/{$this->section->id}/".FLOOR_WEEK.'/send', ['reason' => 'Late'])->assertRedirect();
    config(['portal.week_floor_weeks' => 2]);

    $this->actingAs($this->leader)->get("/reports/{$this->section->id}/".FLOOR_WEEK)
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('week.beforeFloor', true)->where('report.status', 'sent'));
});

it('shows a rollcall week below the floor read-only, without creating a row', function () {
    $this->actingAs($this->leader)->get("/rollcall/{$this->section->id}?week=".BEFORE_FLOOR_WEEK)
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('week.date', BEFORE_FLOOR_WEEK)->where('week.beforeFloor', true)->where('week.previous', '2026-05-02'));

    $this->actingAs($this->leader)->get("/rollcall/{$this->section->id}?week=".FLOOR_WEEK)
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('week.beforeFloor', false));

    expect(practiceWeeks())->toBe([]);
});

it('refuses a week before the floor in each rollcall handler, called directly', function (string $handler, Closure $command) {
    expect(fn () => app($handler)->handle($this->leader, $command($this->section->id, $this->trooper->id, floorWeek(BEFORE_FLOOR_WEEK))))
        ->toThrow(InvalidAttendanceMarkException::class, 'too far back');

    expect(practiceWeeks())->toBe([]);
})->with([
    'mark attendance' => [MarkAttendanceHandler::class, fn (int $s, int $t, CarbonImmutable $w) => new MarkAttendance($s, $w, new AttendanceMark($t, AttendanceStatus::Present, null))],
    'confirm practice' => [ConfirmSectionPracticeHandler::class, fn (int $s, int $t, CarbonImmutable $w) => new ConfirmSectionPractice($s, $w)],
    'mark rollcall posted' => [MarkRollcallPostedHandler::class, fn (int $s, int $t, CarbonImmutable $w) => new MarkRollcallPosted($s, $w)],
]);

it('refuses a week before the floor in each report handler, called directly', function (string $handler, Closure $command) {
    expect(fn () => app($handler)->handle($this->leader, $command($this->section->id, floorWeek(BEFORE_FLOOR_WEEK))))
        ->toThrow(InvalidReportContentException::class, 'too far back');

    expect(WeeklyReport::query()->count())->toBe(0);
})->with([
    'save draft' => [SaveReportDraftHandler::class, fn (int $u, CarbonImmutable $w) => new SaveReportDraft($u, $w, 'x')],
    'send' => [SendWeeklyReportHandler::class, fn (int $u, CarbonImmutable $w) => new SendWeeklyReport($u, $w, 'Late', 'x')],
]);
