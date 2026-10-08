<?php

namespace App\Repositories\SectionPracticeRepository;

use App\Models\SectionPractice;
use App\Repositories\SectionPracticeRepository;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use InvalidArgumentException;

class Eloquent implements SectionPracticeRepository
{
    public function findOrCreate(int $sectionId, CarbonInterface $weekStart): SectionPractice
    {
        return SectionPractice::query()->createOrFirst([
            'section_id' => $sectionId,
            'week_start' => $this->assertWeekStart($weekStart),
        ]);
    }

    public function find(int $sectionId, CarbonInterface $weekStart): ?SectionPractice
    {
        return SectionPractice::query()
            ->where('section_id', $sectionId)
            ->where('week_start', $this->assertWeekStart($weekStart))
            ->first();
    }

    /**
     * Validates rather than derives: mapping an arbitrary time to its `week_start` is
     * `week_of()`'s job alone (AD-11). TODO(3.2): once the `week_of()` helper lands, replace this
     * check with `week_of($weekStart)->equalTo($weekStart)` so the boundary rule lives in one
     * place.
     */
    private function assertWeekStart(CarbonInterface $weekStart): CarbonImmutable
    {
        $utc = CarbonImmutable::instance($weekStart)->utc();

        if (! $utc->isSaturday() || ! $utc->equalTo($utc->startOfDay())) {
            throw new InvalidArgumentException(
                "week_start must be a Saturday 00:00 UTC (AD-11); got {$utc->toIso8601String()}.",
            );
        }

        return $utc;
    }
}
