<?php

use App\Models\TrainingPlanStatus;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// #380, AD-22: a platoon's monthly training plan and its weeks. Raw `DB::table()` writes bypass the model and the
// repository on purpose: these are about the data layer refusing a bad row.

function rawTrainingPlanRow(array $overrides = []): array
{
    return array_merge([
        'unit_id' => Unit::factory()->create()->id,
        'month' => '2026-11',
        'status' => 'pending',
        'written_by' => User::factory()->create()->id,
        'created_at' => now(),
        'updated_at' => now(),
    ], $overrides);
}

function uniqueIndexesOf(string $table): array
{
    return collect(Schema::getIndexes($table))
        ->filter(fn (array $index) => $index['unique'] && ! $index['primary'])
        ->map(fn (array $index) => $index['columns'])
        ->values()
        ->all();
}

function foreignKeysOf(string $table): array
{
    return collect(Schema::getForeignKeys($table))
        ->mapWithKeys(fn (array $fk) => [$fk['columns'][0] => [$fk['foreign_table'].'.'.$fk['foreign_columns'][0], strtolower($fk['on_delete'])]])
        ->sortKeys()
        ->all();
}

it('makes one plan per platoon per month and one subject per week of a plan', function () {
    expect(uniqueIndexesOf('training_plans'))->toContain(['unit_id', 'month'])
        ->and(uniqueIndexesOf('training_plan_weeks'))->toContain(['training_plan_id', 'week_start']);
});

it('points every reference where it belongs, with the delete rule each docblock gives', function () {
    expect(foreignKeysOf('training_plans'))->toBe([
        'reviewed_as_unit' => ['units.id', 'set null'],
        'reviewed_by' => ['users.id', 'restrict'],
        'reviewed_by_admin' => ['users.id', 'restrict'],
        'unit_id' => ['units.id', 'restrict'],
        'written_as_unit' => ['units.id', 'set null'],
        'written_by' => ['users.id', 'restrict'],
        'written_by_admin' => ['users.id', 'restrict'],
    ])->and(foreignKeysOf('training_plan_weeks'))->toBe([
        'training_plan_id' => ['training_plans.id', 'cascade'],
    ]);
});

it('accepts every status the model knows and refuses any other', function () {
    foreach (TrainingPlanStatus::cases() as $i => $status) {
        DB::table('training_plans')->insert(rawTrainingPlanRow(['status' => $status->value, 'month' => '2026-0'.($i + 1)]));
    }

    expect(fn () => DB::table('training_plans')->insert(rawTrainingPlanRow(['status' => 'draft'])))->toThrow(QueryException::class);
});

it('refuses a second plan for the same platoon and month', function () {
    $row = rawTrainingPlanRow();
    DB::table('training_plans')->insert($row);

    expect(fn () => DB::table('training_plans')->insert([...$row, 'written_by' => User::factory()->create()->id]))->toThrow(QueryException::class);
});

// A plan is review history: deleting its platoon, even around `DeleteUnit`, is refused rather than taking the plan
// and its weeks with it. The weeks still go with their plan.
it('keeps a plan and its weeks when its platoon is deleted, and removes the weeks with the plan', function () {
    $planId = DB::table('training_plans')->insertGetId(rawTrainingPlanRow());
    DB::table('training_plan_weeks')->insert(['training_plan_id' => $planId, 'week_start' => '2026-11-07 00:00:00', 'subject' => 'Room clearing', 'created_at' => now(), 'updated_at' => now()]);

    expect(fn () => Unit::query()->whereKey(DB::table('training_plans')->value('unit_id'))->delete())->toThrow(QueryException::class);
    expect(DB::table('training_plans')->count())->toBe(1)
        ->and(DB::table('training_plan_weeks')->count())->toBe(1);

    DB::table('training_plans')->delete();

    expect(DB::table('training_plan_weeks')->count())->toBe(0);
});
