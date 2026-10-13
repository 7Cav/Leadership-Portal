<?php

namespace App\Repositories\SyncRunRepository;

use App\Models\SyncRun;
use App\Models\SyncRunStatus;
use App\Repositories\SyncRunRepository;

class Eloquent implements SyncRunRepository
{
    public function open(string $source, int|string $triggeredBy, ?int $sectionPracticeId = null, ?int $unitId = null): SyncRun
    {
        return SyncRun::query()->forceCreate([
            'source' => $source,
            'started_at' => now(),
            'status' => SyncRunStatus::InProgress,
            'triggered_by' => (string) $triggeredBy,
            'section_practice_id' => $sectionPracticeId,
            'unit_id' => $unitId,
        ]);
    }

    public function complete(SyncRun $run, int $inserted, int $updated, int $inactivated, int $errored, ?string $error = null, int $needsReview = 0): void
    {
        $run->forceFill([
            'finished_at' => now(),
            'status' => $errored > 0 ? SyncRunStatus::Partial : SyncRunStatus::Success,
            'inserted' => $inserted,
            'updated' => $updated,
            'inactivated' => $inactivated,
            'errored' => $errored,
            'needs_review' => $needsReview,
            'error' => $error,
        ])->save();
    }

    public function fail(SyncRun $run, string $error): void
    {
        $run->forceFill([
            'finished_at' => now(),
            'status' => SyncRunStatus::Failure,
            'error' => $error,
        ])->save();
    }

    public function failOrphaned(string $source): int
    {
        return SyncRun::query()
            ->where('source', $source)
            ->where('status', SyncRunStatus::InProgress)
            ->update([
                'finished_at' => now(),
                'status' => SyncRunStatus::Failure,
                'error' => 'The run stopped before it could record a result (the worker was likely killed).',
            ]);
    }

    public function latestForUnit(string $source, int $unitId): ?SyncRun
    {
        return SyncRun::query()
            ->where('source', $source)
            ->where('unit_id', $unitId)
            ->whereNotNull('finished_at')
            ->where('status', '!=', SyncRunStatus::InProgress)
            ->orderByDesc('started_at')
            ->orderByDesc('id')
            ->first();
    }

    public function latestFinished(string $source): ?SyncRun
    {
        return SyncRun::query()
            ->where('source', $source)
            ->whereNotNull('finished_at')
            ->where('status', '!=', SyncRunStatus::InProgress)
            ->orderByDesc('finished_at')
            ->orderByDesc('id')
            ->first();
    }

    public function latestSuccessful(string $source): ?SyncRun
    {
        return SyncRun::query()
            ->where('source', $source)
            ->where('status', SyncRunStatus::Success)
            ->orderByDesc('finished_at')
            ->orderByDesc('id')
            ->first();
    }

    public function latestForPractice(string $source, int $sectionPracticeId): ?SyncRun
    {
        return SyncRun::query()
            ->where('source', $source)
            ->where('section_practice_id', $sectionPracticeId)
            ->whereNotNull('finished_at')
            ->where('status', '!=', SyncRunStatus::InProgress)
            ->orderByDesc('started_at')
            ->orderByDesc('id')
            ->first();
    }
}
