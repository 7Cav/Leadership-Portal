<?php

namespace App\Repositories\SyncRunRepository;

use App\Models\SyncRun;
use App\Models\SyncRunStatus;
use App\Repositories\SyncRunRepository;

class Eloquent implements SyncRunRepository
{
    public function open(string $source, int|string $triggeredBy): SyncRun
    {
        return SyncRun::query()->forceCreate([
            'source' => $source,
            'started_at' => now(),
            'status' => SyncRunStatus::InProgress,
            'triggered_by' => (string) $triggeredBy,
        ]);
    }

    public function complete(SyncRun $run, int $inserted, int $updated, int $inactivated, int $errored, ?string $error = null): void
    {
        $run->forceFill([
            'finished_at' => now(),
            'status' => $errored > 0 ? SyncRunStatus::Partial : SyncRunStatus::Success,
            'inserted' => $inserted,
            'updated' => $updated,
            'inactivated' => $inactivated,
            'errored' => $errored,
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

    public function latestSuccessful(string $source): ?SyncRun
    {
        return SyncRun::query()
            ->where('source', $source)
            ->where('status', SyncRunStatus::Success)
            ->orderByDesc('finished_at')
            ->orderByDesc('id')
            ->first();
    }
}
