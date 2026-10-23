<?php

namespace App\Repositories\CrossSectionSightingRepository;

use App\Models\CrossSectionSighting;
use App\Models\SectionPractice;
use App\Repositories\CrossSectionSightingRepository;
use Carbon\CarbonImmutable;
use DateTimeInterface;

class Eloquent implements CrossSectionSightingRepository
{
    public function record(SectionPractice $hostPractice, array $trooperIds, DateTimeInterface $seenFrom, DateTimeInterface $seenUntil): int
    {
        $added = 0;
        $trooperIds = array_values(array_unique(array_map('intval', $trooperIds)));
        sort($trooperIds);

        foreach ($trooperIds as $trooperId) {
            // createOrFirst inserts under a savepoint and, on the UNIQUE key, reads the row that is
            // already there instead: an earlier run's sighting is found and left untouched.
            $row = CrossSectionSighting::query()->createOrFirst(
                ['trooper_id' => $trooperId, 'section_practice_id' => $hostPractice->id],
                [
                    'week_start' => $hostPractice->week_start,
                    'seen_from' => CarbonImmutable::instance($seenFrom)->utc(),
                    'seen_until' => CarbonImmutable::instance($seenUntil)->utc(),
                    'source' => CrossSectionSighting::SOURCE_CAPTURE,
                ],
            );

            $added += $row->wasRecentlyCreated ? 1 : 0;
        }

        return $added;
    }
}
