<?php

namespace App\Repositories\SectionPracticeRepository;

use App\Models\SectionPractice;
use App\Models\Unit;
use App\Models\UnitType;
use App\Repositories\SectionPracticeRepository;
use App\Support\Time\WeekOf;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use InvalidArgumentException;

class Eloquent implements SectionPracticeRepository
{
    public function findOrCreate(int $sectionId, CarbonInterface $weekStart): SectionPractice
    {
        $weekStart = $this->assertWeekStart($weekStart);

        if (! Unit::query()->whereKey($sectionId)->where('type', UnitType::Section->value)->exists()) {
            throw new InvalidArgumentException("Unit {$sectionId} is not a section; practices are per section (AD-14).");
        }

        $key = ['section_id' => $sectionId, 'week_start' => $weekStart];

        try {
            return SectionPractice::query()->createOrFirst($key);
        } catch (UniqueConstraintViolationException $e) {
            // createOrFirst's own re-read is a plain (snapshot) read: inside a MySQL REPEATABLE
            // READ transaction that has already read, it cannot see a row a racing submit committed
            // after that snapshot, so it rethrows. A locking read always sees the latest committed
            // row, so the insert-or-find holds inside the audited transaction too.
            return SectionPractice::query()->where($key)->sharedLock()->first() ?? throw $e;
        }
    }

    public function find(int $sectionId, CarbonInterface $weekStart): ?SectionPractice
    {
        return SectionPractice::query()
            ->where('section_id', $sectionId)
            ->where('week_start', $this->assertWeekStart($weekStart))
            ->first();
    }

    /**
     * Validates rather than derives: `$weekStart` must already be the boundary `WeekOf` maps its
     * own instant to (AD-11), so the Saturday 00:00 UTC rule lives in that one helper. Returned
     * as that UTC instant, so the stored and queried value is the same on every driver.
     */
    private function assertWeekStart(CarbonInterface $weekStart): CarbonImmutable
    {
        $boundary = WeekOf::start($weekStart);

        if (! $boundary->equalTo($weekStart)) {
            throw new InvalidArgumentException(
                "week_start must be a Saturday 00:00 UTC (AD-11); got {$weekStart->toIso8601String()}.",
            );
        }

        return $boundary;
    }
}
