<?php

use App\Commands\Attendance\AttendanceMark;
use App\Commands\Attendance\MarkAttendance;
use App\Commands\Attendance\MarkAttendanceHandler;
use App\Commands\WeeklyReports\PracticeNotConfirmedException;
use App\Commands\WeeklyReports\ReopenWeeklyReport;
use App\Commands\WeeklyReports\ReopenWeeklyReportHandler;
use App\Commands\WeeklyReports\SendWeeklyReport;
use App\Commands\WeeklyReports\SendWeeklyReportHandler;
use App\Kpi\KpiService;
use App\Kpi\Ratio;
use App\Models\Attendance;
use App\Models\AttendanceSource;
use App\Models\AttendanceStatus;
use App\Models\AuditLogEntry;
use App\Models\SectionPractice;
use App\Models\SectionPracticeSlot;
use App\Models\Trooper;
use App\Models\Unit;
use App\Models\UnitType;
use App\Models\User;
use App\Models\WeeklyReport;
use App\Models\WeeklyReportStatus;
use App\Repositories\AttendanceRepository;
use App\Repositories\SectionPracticeRepository;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;

// #634: a practice counts as held only once its hour is over or its leader confirmed it; it is confirmed as held or not
// held, never before its hour ends; and a section's report waits on that confirmation.
// The Cav week is 03OCT26; the slot is Thursday 2000Z, so the practice ends Thu 08OCT26 2100Z.

beforeEach(function () {
    Carbon::setTestNow(CarbonImmutable::parse('2026-10-08 16:20:00', 'UTC'));
    $this->week = CarbonImmutable::parse('2026-10-03 00:00:00', 'UTC');
    $this->platoon = Unit::factory()->create(['type' => UnitType::Platoon, 'path' => '980/']);
    $this->section = Unit::factory()->create(['type' => UnitType::Section, 'parent_id' => $this->platoon->id, 'path' => '980/981/']);
    $this->leader = User::factory()->commanding($this->section)->create();
    $this->a = Trooper::factory()->create(['primary_section_id' => $this->section->id]);
    $this->b = Trooper::factory()->create(['primary_section_id' => $this->section->id]);
    SectionPracticeSlot::query()->create(['section_id' => $this->section->id, 'weekday' => 4, 'start_minute' => 1200]);
    $this->practice = app(SectionPracticeRepository::class)->findOrCreate($this->section->id, $this->week);
});

function ptMark(object $t, Trooper $trooper, AttendanceStatus $status): void
{
    app(AttendanceRepository::class)->markPractice($t->practice, $trooper->id, $status, AttendanceSource::Manual);
}

function ptConfirm(object $t, ?bool $held = null)
{
    return $t->actingAs($t->leader)->postJson("/rollcall/{$t->section->id}/confirm", ['week' => '2026-10-03', ...($held === null ? [] : ['held' => $held])]);
}

function ptSend(object $t): void
{
    app(SendWeeklyReportHandler::class)->handle($t->leader, new SendWeeklyReport($t->section->id, $t->week->toDateTimeImmutable(), 'Weekly report', 'Notes'));
}

function ptHeld(object $t): bool
{
    return app(AttendanceRepository::class)->weekProgress([$t->section->id], $t->week)->get($t->section->id)->held;
}

function ptSp(object $t, Trooper $trooper): Ratio
{
    return app(KpiService::class)->ownSectionPractice([$trooper->id], $t->week, 1)->get($trooper->id);
}

it('leaves marks out of the SP numbers and calls nothing held before the practice is over', function () {
    ptMark($this, $this->a, AttendanceStatus::Present);
    ptMark($this, $this->b, AttendanceStatus::Missed);

    $mark = app(AttendanceRepository::class)->countingMarksOf([$this->a->id], $this->week, $this->week)->sole();
    expect(ptHeld($this))->toBeFalse()
        ->and($mark->held)->toBeFalse()
        ->and($mark->status)->toBeNull()
        ->and($mark->recorded)->toBe(AttendanceStatus::Present)
        ->and(ptSp($this, $this->a))->toEqual(new Ratio(0, 0))
        ->and(ptSp($this, $this->b))->toEqual(new Ratio(0, 0));
});

it('counts the marks once the practice hour has ended', function () {
    ptMark($this, $this->a, AttendanceStatus::Present);
    ptMark($this, $this->b, AttendanceStatus::Missed);
    Carbon::setTestNow(CarbonImmutable::parse('2026-10-08 21:00:00', 'UTC'));

    expect(ptHeld($this))->toBeTrue()
        ->and(ptSp($this, $this->a))->toEqual(new Ratio(1, 1))
        ->and(ptSp($this, $this->b))->toEqual(new Ratio(0, 1));
});

it('counts a practice confirmed as held, and never one confirmed as not held', function () {
    ptMark($this, $this->a, AttendanceStatus::Missed);
    ptMark($this, $this->b, AttendanceStatus::Missed);
    $this->practice->forceFill(['confirmed_at' => now(), 'confirmed_by' => $this->leader->id])->save();

    expect(ptHeld($this))->toBeTrue()->and(ptSp($this, $this->a))->toEqual(new Ratio(0, 1));

    $this->practice->forceFill(['not_held' => true])->save();
    Carbon::setTestNow(CarbonImmutable::parse('2026-10-09 12:00:00', 'UTC'));

    expect(ptHeld($this))->toBeFalse()->and(ptSp($this, $this->a))->toEqual(new Ratio(0, 0));
});

it('refuses to confirm before the practice hour ends, and confirms once it has', function () {
    ptMark($this, $this->a, AttendanceStatus::Present);
    ptMark($this, $this->b, AttendanceStatus::Missed);

    ptConfirm($this)->assertStatus(422)->assertJsonPath('message', 'The practice is not over yet. Confirm it once it ends.');
    ptConfirm($this, false)->assertStatus(422);
    expect($this->practice->fresh()->confirmed_at)->toBeNull()
        ->and(AuditLogEntry::query()->where('action', 'practice.confirmed')->count())->toBe(0);

    Carbon::setTestNow(CarbonImmutable::parse('2026-10-08 21:00:00', 'UTC'));
    ptConfirm($this)->assertOk()->assertJsonPath('confirmation.held', true);
    expect($this->practice->fresh()->not_held)->toBeFalse();
});

it('shows the practice hour\'s end on the roll call, and none without a slot', function () {
    $this->actingAs($this->leader)->get("/rollcall/{$this->section->id}")
        ->assertInertia(fn (Assert $page) => $page->where('practice.endsAt', '2026-10-08T21:00:00+00:00'));

    SectionPracticeSlot::query()->delete();
    $this->actingAs($this->leader)->get("/rollcall/{$this->section->id}")
        ->assertInertia(fn (Assert $page) => $page->where('practice.endsAt', null));
});

it('confirms held only with every trooper marked, leave counting as marked', function () {
    Carbon::setTestNow(CarbonImmutable::parse('2026-10-08 21:00:00', 'UTC'));
    ptMark($this, $this->a, AttendanceStatus::Present);

    ptConfirm($this)->assertStatus(422)->assertJsonPath('message', 'One trooper has no practice mark yet. Mark everyone before you confirm the practice as held.');

    ptMark($this, $this->b, AttendanceStatus::Missed);
    ptConfirm($this)->assertOk();
});

it('confirms not held, refused while a trooper is marked attended, and a hand edit clears it', function () {
    Carbon::setTestNow(CarbonImmutable::parse('2026-10-08 21:00:00', 'UTC'));
    ptMark($this, $this->a, AttendanceStatus::Present);

    ptConfirm($this, false)->assertStatus(422)->assertJsonPath('message', 'A trooper is marked as attended, so the practice was held. Change that mark first, or confirm it as held.');

    $this->practice->attendance()->delete();
    ptConfirm($this, false)->assertOk()->assertJsonPath('confirmation.held', false);
    expect($this->practice->fresh())->not_held->toBeTrue()->confirmed_at->not->toBeNull();
    $entry = AuditLogEntry::query()->where('action', 'practice.confirmed')->sole();
    expect(collect($entry->details['changes'])->firstWhere('field', 'Not held'))->toMatchArray(['from' => 'No', 'to' => 'Yes']);

    $this->actingAs($this->leader)->postJson("/rollcall/{$this->section->id}", ['week' => '2026-10-03', 'marks' => [['trooper_id' => $this->b->id, 'status' => 'missed']]])->assertOk();
    expect($this->practice->fresh())->confirmed_at->toBeNull()->not_held->toBeFalse();
});

it('lets a section with no slot confirm held or not held any time in the week', function () {
    SectionPracticeSlot::query()->delete();
    ptConfirm($this, false)->assertOk()->assertJsonPath('confirmation.held', false);

    ptMark($this, $this->a, AttendanceStatus::Present);
    ptMark($this, $this->b, AttendanceStatus::Present);
    ptConfirm($this, true)->assertOk()->assertJsonPath('confirmation.held', true);
});

it('refuses to send a section\'s report until its practice is confirmed, writing nothing', function () {
    expect(fn () => ptSend($this))->toThrow(PracticeNotConfirmedException::class);
    expect(WeeklyReport::query()->count())->toBe(0)
        ->and(AuditLogEntry::query()->where('action', 'weekly_report.sent')->count())->toBe(0);

    $this->actingAs($this->leader)->post("/reports/{$this->section->id}/2026-10-03/send", ['reason' => 'Weekly report'])
        ->assertSessionHasErrors(['practice' => "Confirm on the roll call whether this week's practice was held before you send the report."]);
});

it('sends once the practice is confirmed, as held or as not held', function (bool $held) {
    $this->practice->forceFill(['confirmed_at' => now(), 'confirmed_by' => $this->leader->id, 'not_held' => ! $held])->save();

    ptSend($this);

    expect(WeeklyReport::query()->sole()->status)->toBe(WeeklyReportStatus::Sent);
})->with(['held' => [true], 'not held' => [false]]);

it('asks no confirmation of a section with nobody on its roll call, nor of a platoon', function () {
    $empty = Unit::factory()->create(['type' => UnitType::Section, 'parent_id' => $this->platoon->id, 'path' => '980/982/']);
    $leader = User::factory()->commanding($empty)->create();
    app(SendWeeklyReportHandler::class)->handle($leader, new SendWeeklyReport($empty->id, $this->week->toDateTimeImmutable(), 'Weekly report', null));

    $this->practice->forceFill(['confirmed_at' => now(), 'confirmed_by' => $this->leader->id])->save();
    ptSend($this);
    $platoonLeader = User::factory()->commanding($this->platoon)->create();
    app(SendWeeklyReportHandler::class)->handle($platoonLeader, new SendWeeklyReport($this->platoon->id, $this->week->toDateTimeImmutable(), 'Weekly report', null));

    expect(WeeklyReport::query()->where('status', WeeklyReportStatus::Sent)->count())->toBe(3)
        ->and(SectionPractice::query()->where('section_id', $empty->id)->exists())->toBeFalse();
});

it('tells the report page whether the practice is confirmed, and nothing once it is sent', function () {
    $page = fn () => $this->actingAs($this->leader)->get("/reports/{$this->section->id}/2026-10-03");

    $page()->assertInertia(fn (Assert $p) => $p->where('practice', ['confirmed' => false, 'held' => null]));

    $this->practice->forceFill(['confirmed_at' => now(), 'confirmed_by' => $this->leader->id, 'not_held' => true])->save();
    $page()->assertInertia(fn (Assert $p) => $p->where('practice', ['confirmed' => true, 'held' => false]));

    ptSend($this);
    $page()->assertInertia(fn (Assert $p) => $p->where('practice', null)->where('report.status', 'sent'));
});

it('keeps a sent week locked: its practice cannot be confirmed again', function () {
    $this->practice->forceFill(['confirmed_at' => now(), 'confirmed_by' => $this->leader->id])->save();
    ptSend($this);
    Carbon::setTestNow(CarbonImmutable::parse('2026-10-08 21:00:00', 'UTC'));

    ptConfirm($this, false)->assertStatus(409)->assertJsonPath('locked', true);
    expect($this->practice->fresh()->not_held)->toBeFalse();
});

it('keeps an old week never confirmed counting, and its sent report readable', function () {
    $old = app(SectionPracticeRepository::class)->findOrCreate($this->section->id, $this->week->subWeek());
    Attendance::factory()->create(['trooper_id' => $this->a->id, 'section_practice_id' => $old->id, 'status' => AttendanceStatus::Present, 'source' => AttendanceSource::Manual]);
    WeeklyReport::factory()->sent()->create(['unit_id' => $this->section->id, 'week_start' => $this->week->subWeek()]);

    $mark = app(AttendanceRepository::class)->countingMarksOf([$this->a->id], $this->week->subWeek(), $this->week->subWeek())->sole();
    expect($mark->held)->toBeTrue()->and($mark->status)->toBe(AttendanceStatus::Present);

    $this->actingAs($this->leader)->get("/reports/{$this->section->id}/".$this->week->subWeek()->toDateString())
        ->assertOk()->assertInertia(fn (Assert $p) => $p->where('report.status', 'sent')->where('practice', null));
});

it('asks a reopened report whose marks changed for the practice confirmation again before it is sent again', function () {
    $this->practice->forceFill(['confirmed_at' => now(), 'confirmed_by' => $this->leader->id])->save();
    ptSend($this);
    app(ReopenWeeklyReportHandler::class)->handle($this->leader, new ReopenWeeklyReport($this->section->id, $this->week->toDateTimeImmutable(), 'Wrong marks'));

    app(MarkAttendanceHandler::class)->handle($this->leader, new MarkAttendance($this->section->id, $this->week->toDateTimeImmutable(), new AttendanceMark($this->a->id, AttendanceStatus::Missed)));

    expect(fn () => ptSend($this))->toThrow(PracticeNotConfirmedException::class);

    $this->practice->fresh()->forceFill(['confirmed_at' => now(), 'confirmed_by' => $this->leader->id])->save();
    ptSend($this);
    expect(WeeklyReport::query()->sole()->status)->toBe(WeeklyReportStatus::Sent);
});
