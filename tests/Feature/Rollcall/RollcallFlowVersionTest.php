<?php

use App\Models\SectionPractice;
use App\Models\Unit;
use App\Models\UnitType;
use App\Models\User;
use App\Repositories\RollcallFlow;
use App\Repositories\SectionPracticeRepository;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;

// #703: the flow version seam. A week follows the flow it started under: weeks from the cutover Saturday on take the
// explicit cycle (2), earlier ones the three steps (1), and a week's practice row keeps its flow once it exists.

beforeEach(function () {
    Carbon::setTestNow(CarbonImmutable::parse('2026-11-25 16:20:00', 'UTC'));
    config(['portal.rollcall_flow_v2_from' => '2026-11-21']);
    $this->section = Unit::factory()->create(['type' => UnitType::Section, 'path' => '930/']);
    $this->leader = User::factory()->commanding($this->section)->create();
});

afterEach(fn () => Carbon::setTestNow());

it('puts a week on the new flow from the cutover Saturday, and earlier weeks on the three steps', function () {
    expect(RollcallFlow::forWeek(CarbonImmutable::parse('2026-11-14', 'UTC')))->toBe(RollcallFlow::V1)
        ->and(RollcallFlow::forWeek(CarbonImmutable::parse('2026-11-21', 'UTC')))->toBe(RollcallFlow::V2)
        ->and(RollcallFlow::forWeek(CarbonImmutable::parse('2026-11-27 23:59', 'UTC')))->toBe(RollcallFlow::V2);

    config(['portal.rollcall_flow_v2_from' => false]);
    expect(RollcallFlow::forWeek(CarbonImmutable::parse('2026-11-21', 'UTC')))->toBe(RollcallFlow::V1);
});

it('starts the new flow with the Saturday the release recorded, unless the setting names one', function () {
    config(['portal.rollcall_flow_v2_from' => null]);
    DB::table('rollcall_flow_cutovers')->update(['first_week' => '2026-11-28']);

    expect(RollcallFlow::forWeek(CarbonImmutable::parse('2026-11-21', 'UTC')))->toBe(RollcallFlow::V1)
        ->and(RollcallFlow::forWeek(CarbonImmutable::parse('2026-11-28', 'UTC')))->toBe(RollcallFlow::V2);

    // With nothing recorded, no week is on the new flow.
    DB::table('rollcall_flow_cutovers')->delete();
    expect(RollcallFlow::forWeek(CarbonImmutable::parse('2026-12-05', 'UTC')))->toBe(RollcallFlow::V1);
});

it('records the first Saturday after the migration runs, so the running week keeps the three steps', function () {
    // The migrations ran when this test process started: the recorded week starts after now, on a Saturday.
    $recorded = CarbonImmutable::parse(DB::table('rollcall_flow_cutovers')->value('first_week'), 'UTC');
    $migratedAt = CarbonImmutable::parse(DB::table('rollcall_flow_cutovers')->value('created_at'), 'UTC');

    expect($recorded->isSaturday())->toBeTrue()
        ->and($recorded->greaterThan($migratedAt))->toBeTrue()
        ->and($recorded->diffInDays($migratedAt, true))->toBeLessThanOrEqual(7);
});

it('stamps a new practice row with its week\'s flow, and leaves a found one as it is', function () {
    $practices = app(SectionPracticeRepository::class);

    expect($practices->findOrCreate($this->section->id, CarbonImmutable::parse('2026-11-21', 'UTC'))->flow_version)->toBe(2)
        ->and($practices->findOrCreate($this->section->id, CarbonImmutable::parse('2026-11-14', 'UTC'))->flow_version)->toBe(1);

    // A week already running when the cutover shipped has a row from before: it finishes on the three steps.
    $running = SectionPractice::factory()->create(['section_id' => Unit::factory()->create(['type' => UnitType::Section, 'path' => '931/'])->id, 'week_start' => '2026-11-21']);
    expect($running->refresh()->flow_version)->toBe(1)
        ->and($practices->findOrCreate($running->section_id, CarbonImmutable::parse('2026-11-21', 'UTC'))->flow_version)->toBe(1);
});

it('lets a caller pin the flow of the row it makes, as the tutorial does', function () {
    expect(app(SectionPracticeRepository::class)->findOrCreate($this->section->id, CarbonImmutable::parse('2026-11-21', 'UTC'), flowVersion: RollcallFlow::V1)->flow_version)->toBe(1);
});

it('tells the page which flow the week follows, with or without a practice row yet', function () {
    $this->actingAs($this->leader)->get("/rollcall/{$this->section->id}?week=2026-11-21")
        ->assertInertia(fn (AssertableInertia $page) => $page->where('flow', ['version' => 2, 'created' => null]));

    $this->actingAs($this->leader)->get("/rollcall/{$this->section->id}?week=2026-11-14")
        ->assertInertia(fn (AssertableInertia $page) => $page->where('flow.version', 1));

    SectionPractice::factory()->create(['section_id' => $this->section->id, 'week_start' => '2026-11-21', 'flow_version' => 1]);
    $this->actingAs($this->leader)->get("/rollcall/{$this->section->id}?week=2026-11-21")
        ->assertInertia(fn (AssertableInertia $page) => $page->where('flow.version', 1));
});
