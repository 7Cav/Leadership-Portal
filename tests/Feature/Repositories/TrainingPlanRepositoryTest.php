<?php

use App\Models\TrainingPlan;
use App\Models\TrainingPlanStatus;
use App\Models\TrainingPlanWeek;
use App\Models\Unit;
use App\Models\UnitType;
use App\Models\User;
use App\Repositories\TrainingPlanRepository;
use Illuminate\Support\Facades\DB;

// #380: the one writer of `training_plans` and `training_plan_weeks`.

beforeEach(function () {
    $this->repo = app(TrainingPlanRepository::class);
    $this->platoon = Unit::factory()->create(['type' => UnitType::Platoon]);
    $this->writer = User::factory()->create();
    $this->reviewer = User::factory()->create();
});

it('saves a plan with its weeks, pending, at revision 1', function () {
    $plan = $this->repo->save($this->platoon->id, '2026-11', ['2026-11-07' => 'Room clearing', '2026-11-21' => 'Radio procedure'], true, $this->writer->id);

    expect($plan->status)->toBe(TrainingPlanStatus::Pending)
        ->and($plan->enforced)->toBeTrue()
        ->and($plan->revision)->toBe(1)
        ->and($plan->written_by)->toBe($this->writer->id)
        ->and($plan->weeks->map(fn (TrainingPlanWeek $w) => [$w->week_start->toDateString(), $w->subject])->all())
        ->toBe([['2026-11-07', 'Room clearing'], ['2026-11-21', 'Radio procedure']]);
});

it('replaces the weeks on a second save, bumps the revision and clears the last review', function () {
    $plan = $this->repo->save($this->platoon->id, '2026-11', ['2026-11-07' => 'Room clearing'], false, $this->writer->id);
    $this->repo->sendBack($plan, 'Add a week on radios', $this->reviewer->id);

    $plan = $this->repo->save($this->platoon->id, '2026-11', ['2026-11-14' => 'Radio procedure'], false, $this->writer->id);

    expect($plan->status)->toBe(TrainingPlanStatus::Pending)
        ->and($plan->revision)->toBe(2)
        ->and($plan->reviewed_by)->toBeNull()
        ->and($plan->review_comment)->toBeNull()
        ->and($plan->reviewed_at)->toBeNull()
        ->and($plan->weeks->pluck('subject')->all())->toBe(['Radio procedure'])
        ->and(TrainingPlan::query()->count())->toBe(1)
        ->and(TrainingPlanWeek::query()->count())->toBe(1);
});

it('records an approval and a send-back with who reviewed it and when', function () {
    $plan = $this->repo->save($this->platoon->id, '2026-11', ['2026-11-07' => 'Room clearing'], false, $this->writer->id);

    $approved = $this->repo->approve($plan, $this->reviewer->id);
    expect($approved->status)->toBe(TrainingPlanStatus::Approved)
        ->and($approved->reviewer->id)->toBe($this->reviewer->id)
        ->and($approved->reviewed_at)->not->toBeNull();

    $returned = $this->repo->sendBack($approved, 'Not this month', $this->reviewer->id);
    expect($returned->fresh()->status)->toBe(TrainingPlanStatus::Returned)
        ->and($returned->review_comment)->toBe('Not this month');
});

it('reads one month\'s plans of many platoons at once, keyed by platoon', function () {
    $other = Unit::factory()->create(['type' => UnitType::Platoon]);
    $this->repo->save($this->platoon->id, '2026-11', ['2026-11-07' => 'Room clearing'], false, $this->writer->id);
    $this->repo->save($other->id, '2026-12', ['2026-12-05' => 'Radio procedure'], false, $this->writer->id);

    $plans = $this->repo->forUnits([$this->platoon->id, $other->id], '2026-11');

    expect($plans->keys()->all())->toBe([$this->platoon->id])
        ->and($plans->get($this->platoon->id)->weeks)->toHaveCount(1)
        ->and($this->repo->forUnits([], '2026-11'))->toBeEmpty();
});

it('refuses a week that is not a Saturday', function () {
    $this->repo->save($this->platoon->id, '2026-11', ['2026-11-08' => 'Room clearing'], false, $this->writer->id);
})->throws(InvalidArgumentException::class);

it('reads a plan under its lock, or null when there is none', function () {
    $this->repo->save($this->platoon->id, '2026-11', ['2026-11-07' => 'Room clearing'], false, $this->writer->id);

    expect(DB::transaction(fn () => $this->repo->lockFor($this->platoon->id, '2026-11'))?->weeks)->toHaveCount(1)
        ->and(DB::transaction(fn () => $this->repo->lockFor($this->platoon->id, '2026-11'))?->month)->toBe('2026-11')
        ->and(DB::transaction(fn () => $this->repo->lockFor($this->platoon->id, '2026-12')))->toBeNull();
});
