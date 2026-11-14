<?php

use App\Commands\WeeklyReports\ReopenWeeklyReport;
use App\Commands\WeeklyReports\ReopenWeeklyReportHandler;
use App\Commands\WeeklyReports\SendWeeklyReport;
use App\Commands\WeeklyReports\SendWeeklyReportHandler;
use App\Models\Loa;
use App\Models\SectionPractice;
use App\Models\Trooper;
use App\Models\Unit;
use App\Models\UnitType;
use App\Models\User;
use App\Models\WeeklyReport;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia;

// #703: closing a week on the new flow is sending its weekly report, which locks signatures and marks (AD-12); an
// edit of the report reopens the roll call. A week on the three-step flow closes exactly as before.

beforeEach(function () {
    $this->platoon = Unit::factory()->create(['type' => UnitType::Platoon, 'callsign' => '1/A/1-7', 'path' => '810/']);
    $this->section = Unit::factory()->create(['type' => UnitType::Section, 'callsign' => '1/1/A/1-7', 'parent_id' => $this->platoon->id, 'path' => '810/811/']);
    $this->leader = User::factory()->commanding($this->section)->create();
    $this->trooper = Trooper::factory()->create(['primary_section_id' => $this->section->id]);
    $this->week = '2026-10-03';
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-07 18:00:00', 'UTC'));
});

afterEach(fn () => CarbonImmutable::setTestNow());

function rclSend(): void
{
    confirmPracticeHeld(test()->section, test()->week);
    app(SendWeeklyReportHandler::class)->handle(test()->leader, new SendWeeklyReport(test()->section->id, CarbonImmutable::parse(test()->week, 'UTC'), 'Weekly report', 'All good.'));
}

function rclPage(): AssertableInertia
{
    $page = null;
    test()->actingAs(test()->leader)->get('/rollcall/'.test()->section->id.'?week='.test()->week)
        ->assertInertia(function (AssertableInertia $inertia) use (&$page) {
            $page = $inertia;
        });

    return $page;
}

function rclMark()
{
    return test()->actingAs(test()->leader)->postJson('/rollcall/'.test()->section->id, ['week' => test()->week, 'marks' => [['trooper_id' => test()->trooper->id, 'status' => 'present']]]);
}

it('locks a closed new-flow week, and opens it again when the report is reopened', function () {
    config(['portal.rollcall_flow_v2_from' => '2026-10-03']);
    $this->actingAs($this->leader)->postJson("/rollcall/{$this->section->id}/create", ['week' => $this->week])->assertOk();

    rclSend();

    rclPage()->where('flow.version', 2)->where('flow.created.by', $this->leader->username)->where('locked', true)->where('report.sent', true);
    rclMark()->assertStatus(409)->assertJson(['locked' => true]);
    $this->actingAs($this->leader)->postJson("/rollcall/{$this->section->id}/posted", ['week' => $this->week])->assertStatus(409);
    $this->actingAs($this->leader)->postJson("/rollcall/{$this->section->id}/training-topic", ['week' => $this->week, 'subject' => 'Late'])->assertStatus(409);

    app(ReopenWeeklyReportHandler::class)->handle($this->leader, new ReopenWeeklyReport($this->section->id, CarbonImmutable::parse($this->week, 'UTC'), 'Wrong marks'));

    rclPage()->where('flow.version', 2)->where('flow.created.by', $this->leader->username)->where('locked', false);
    rclMark()->assertOk();
});

it('closes a three-step week exactly as before, with no create step', function () {
    config(['portal.rollcall_flow_v2_from' => '2026-10-10']);

    rclPage()->where('flow', ['version' => 1, 'created' => null])->where('locked', false);
    $this->actingAs($this->leader)->postJson("/rollcall/{$this->section->id}/create", ['week' => $this->week])->assertStatus(422);

    rclSend();

    expect(SectionPractice::query()->sole()->flow_version)->toBe(1);
    rclPage()->where('flow.version', 1)->where('locked', true);
    rclMark()->assertStatus(409);
});

it('gives the section report the roll call summary: the practice start and the forum thread (#703)', function () {
    config(['services.sevencav.base_url' => 'https://7cav.us']);
    $this->actingAs($this->leader)->postJson("/rollcall/{$this->section->id}/slot", ['weekday' => 3, 'start_minute' => 1380, 'week' => $this->week])->assertOk();
    $this->actingAs($this->leader)->postJson("/rollcall/{$this->section->id}/posted", ['week' => $this->week, 'thread_url' => 'https://7cav.us/threads/roll-call.9/'])->assertOk();

    $this->actingAs($this->leader)->get("/reports/{$this->section->id}/{$this->week}")
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('rollcall.trainingStart', '2026-10-07T23:00:00Z')
            ->where('rollcall.threadUrl', 'https://7cav.us/threads/roll-call.9/')
            ->where('rollcall.href', route('rollcall.show', ['unit' => $this->section->id, 'week' => $this->week])));

    $this->actingAs(User::factory()->admin()->create())->get("/reports/{$this->platoon->id}/{$this->week}")
        ->assertInertia(fn (AssertableInertia $page) => $page->where('rollcall', null));
});

it('records where a send started, for counting reports sent from the roll call (#703)', function () {
    confirmPracticeHeld($this->section, $this->week);

    $this->actingAs($this->leader)->post("/reports/{$this->section->id}/{$this->week}/send", ['reason' => 'Weekly report', 'from' => 'rollcall'])
        ->assertSessionHasNoErrors();
    expect(WeeklyReport::query()->sole()->sent_from)->toBe('rollcall');

    $this->actingAs($this->leader)->post("/reports/{$this->section->id}/{$this->week}/send", ['reason' => 'Weekly report', 'from' => 'somewhere'])
        ->assertSessionHasErrors('from');
});

it('gives a sent report\'s summary the marks it was sent with, which the page has no other copy of (#703)', function () {
    rclMark()->assertOk();
    rclSend();

    $this->actingAs($this->leader)->get("/reports/{$this->section->id}/{$this->week}")
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('troopers', null)
            ->where('rollcall.troopers', [['id' => $this->trooper->id, 'rollcall' => 'unsigned', 'status' => 'present', 'loa' => null, 'excused' => null]]));
});

it('tells the summary who is on leave that week, so it names them apart rather than as unsigned', function () {
    Loa::factory()->create(['trooper_id' => $this->trooper->id, 'starts_at' => '2026-10-01', 'ends_at' => '2026-10-20']);

    $this->actingAs($this->leader)->get("/reports/{$this->section->id}/{$this->week}")
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('rollcall.troopers.0.loa.state', 'covered')
            ->where('rollcall.troopers.0.excused', null));
});

it("gives the draft's trooper rows the leave that covers the week, as the roll call reads it, so the table reads LOA", function () {
    Loa::factory()->create(['trooper_id' => $this->trooper->id, 'starts_at' => '2026-10-05', 'ends_at' => '2026-10-19']);

    $this->actingAs($this->leader)->get("/reports/{$this->section->id}/{$this->week}")
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('troopers.0.onLoa', true)
            ->where('troopers.0.loa.state', 'covered')
            ->where('troopers.0.loa.endsAt', fn ($end) => str_starts_with((string) $end, '2026-10-19')));
});

it('leaves loa null on a trooper row with no leave that week', function () {
    $this->actingAs($this->leader)->get("/reports/{$this->section->id}/{$this->week}")
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('troopers.0.onLoa', false)
            ->where('troopers.0.loa', null));
});
