<?php

namespace App\Repositories\CrossSectionSightingRepository;

use App\Models\CrossSectionSighting;
use App\Models\SectionPractice;
use App\Models\TrooperStatus;
use App\Models\Unit;
use App\Repositories\CrossSectionSightingRepository;
use App\Repositories\GuestVisits;
use App\Support\Time\WeekOf;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use DateTimeInterface;
use Illuminate\Support\Collection;

class Eloquent implements CrossSectionSightingRepository
{
    public function record(SectionPractice $hostPractice, array $trooperIds, DateTimeInterface $seenFrom, DateTimeInterface $seenUntil): int
    {
        $added = 0;
        $trooperIds = array_values(array_unique(array_map('intval', $trooperIds)));
        sort($trooperIds);

        $window = [
            'seen_from' => CarbonImmutable::instance($seenFrom)->utc(),
            'seen_until' => CarbonImmutable::instance($seenUntil)->utc(),
        ];

        foreach ($trooperIds as $trooperId) {
            $key = ['trooper_id' => $trooperId, 'section_practice_id' => $hostPractice->id];
            // createOrFirst inserts under a savepoint and, on the UNIQUE key, reads the row that is
            // already there instead: an earlier run's sighting is found and left untouched.
            $row = CrossSectionSighting::query()->createOrFirst(
                $key,
                ['week_start' => $hostPractice->week_start, 'source' => CrossSectionSighting::SOURCE_CAPTURE] + $window,
            );

            if ($row->wasRecentlyCreated) {
                $added++;

                continue;
            }

            // A leader's hand entry the server now confirms becomes the server's evidence, so an Undo can no longer
            // take it (or the makeup it supports) back. Conditional, so a racing upgrade or removal is never undone;
            // `recorded_by` stays, naming who added it first.
            CrossSectionSighting::query()
                ->where($key)
                ->where('source', CrossSectionSighting::SOURCE_MANUAL)
                ->update(['source' => CrossSectionSighting::SOURCE_CAPTURE] + $window);
        }

        return $added;
    }

    public function recordManual(SectionPractice $hostPractice, int $trooperId, int $recordedBy, ?int $recordedByAdmin = null, ?int $recordedAsUnit = null): ?CrossSectionSighting
    {
        // The same insert-or-find as a capture: a pair the capture (or another leader) already recorded is found and
        // left as it is, so a hand entry never overwrites what the server saw.
        $row = CrossSectionSighting::query()->createOrFirst(
            ['trooper_id' => $trooperId, 'section_practice_id' => $hostPractice->id],
            [
                'week_start' => $hostPractice->week_start,
                'source' => CrossSectionSighting::SOURCE_MANUAL,
                'recorded_by' => $recordedBy,
                'recorded_by_admin' => $recordedByAdmin,
                'recorded_as_unit' => $recordedAsUnit,
            ],
        );

        return $row->wasRecentlyCreated ? $row : null;
    }

    public function removeManual(int $hostPracticeId, int $trooperId): bool
    {
        return CrossSectionSighting::query()
            ->where('section_practice_id', $hostPracticeId)
            ->where('trooper_id', $trooperId)
            ->where('source', CrossSectionSighting::SOURCE_MANUAL)
            ->delete() > 0;
    }

    public function recordStaff(SectionPractice $hostPractice, int $trooperId, int $recordedBy, ?int $recordedByAdmin = null, ?int $recordedAsUnit = null): ?CrossSectionSighting
    {
        $row = CrossSectionSighting::query()->createOrFirst(
            ['trooper_id' => $trooperId, 'section_practice_id' => $hostPractice->id],
            [
                'week_start' => $hostPractice->week_start,
                'source' => CrossSectionSighting::SOURCE_STAFF,
                'recorded_by' => $recordedBy,
                'recorded_by_admin' => $recordedByAdmin,
                'recorded_as_unit' => $recordedAsUnit,
            ],
        );

        return $row->wasRecentlyCreated ? $row : null;
    }

    public function removeStaff(int $hostPracticeId, int $trooperId): bool
    {
        return CrossSectionSighting::query()
            ->where('section_practice_id', $hostPracticeId)
            ->where('trooper_id', $trooperId)
            ->where('source', CrossSectionSighting::SOURCE_STAFF)
            ->delete() > 0;
    }

    public function staffEntriesFor(int $trooperId): Collection
    {
        return CrossSectionSighting::query()
            ->with(['practice.section', 'recordedBy', 'recordedByAdmin', 'recordedAsUnit'])
            ->where('trooper_id', $trooperId)
            ->where('source', CrossSectionSighting::SOURCE_STAFF)
            ->orderByDesc('week_start')
            ->orderBy('id')
            ->get()
            ->toBase();
    }

    public function staffEntriesInWeek(int $trooperId, CarbonInterface $weekStart): Collection
    {
        return CrossSectionSighting::query()
            ->with(['practice.section', 'recordedBy', 'recordedByAdmin', 'recordedAsUnit'])
            ->where('trooper_id', $trooperId)
            ->where('source', CrossSectionSighting::SOURCE_STAFF)
            ->where('week_start', WeekOf::requireStart($weekStart))
            ->orderBy('id')
            ->get()
            ->toBase();
    }

    public function staffForHostPractice(int $hostPracticeId): Collection
    {
        return CrossSectionSighting::query()
            ->with(['trooper', 'recordedBy', 'recordedByAdmin', 'recordedAsUnit'])
            ->where('section_practice_id', $hostPracticeId)
            ->where('source', CrossSectionSighting::SOURCE_STAFF)
            ->orderBy('id')
            ->get()
            ->sortBy(fn (CrossSectionSighting $row) => [mb_strtolower((string) $row->trooper?->username), $row->trooper_id])
            ->values()
            ->toBase();
    }

    public function find(int $hostPracticeId, int $trooperId): ?CrossSectionSighting
    {
        return CrossSectionSighting::query()
            ->where('section_practice_id', $hostPracticeId)
            ->where('trooper_id', $trooperId)
            ->first();
    }

    public function forHostPractice(int $hostPracticeId): Collection
    {
        return CrossSectionSighting::query()
            ->with(['trooper', 'recordedBy', 'recordedByAdmin', 'recordedAsUnit'])
            ->where('section_practice_id', $hostPracticeId)
            ->whereIn('source', CrossSectionSighting::GUEST_SOURCES)
            ->orderBy('id')
            ->get()
            ->sortBy(fn (CrossSectionSighting $row) => [mb_strtolower((string) $row->trooper?->username), $row->trooper_id])
            ->values()
            ->toBase();
    }

    public function existsForTrooperInWeek(int $trooperId, CarbonInterface $weekStart): bool
    {
        return CrossSectionSighting::query()
            ->where('trooper_id', $trooperId)
            ->where('week_start', WeekOf::requireStart($weekStart))
            ->whereIn('source', CrossSectionSighting::GUEST_SOURCES)
            ->exists();
    }

    public function trooperIdsSeenInWeek(array $trooperIds, CarbonInterface $weekStart): array
    {
        $weekStart = WeekOf::requireStart($weekStart);
        $trooperIds = array_values(array_unique(array_map('intval', $trooperIds)));

        if ($trooperIds === []) {
            return [];
        }

        return CrossSectionSighting::query()
            ->whereIn('trooper_id', $trooperIds)
            ->where('week_start', $weekStart)
            ->whereIn('source', CrossSectionSighting::GUEST_SOURCES)
            ->orderBy('id')
            ->sharedLock()
            ->pluck('trooper_id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->sort()
            ->values()
            ->all();
    }

    public function visitsWithin(Unit $unit, CarbonInterface $firstWeek, CarbonInterface $lastWeek): Collection
    {
        $from = WeekOf::requireStart($firstWeek);
        $to = WeekOf::requireStart($lastWeek);

        // A host practice of a section under the unit, a guest whose own section is not under it. `count(*)` counts host
        // practices: the table holds one row per trooper and practice, so two sessions on the server, or a capture and a
        // leader's entry, are one visit.
        return CrossSectionSighting::query()
            ->join('section_practices', 'section_practices.id', '=', 'cross_section_sightings.section_practice_id')
            ->join('units as hosts', 'hosts.id', '=', 'section_practices.section_id')
            ->join('troopers', 'troopers.id', '=', 'cross_section_sightings.trooper_id')
            ->join('units as homes', 'homes.id', '=', 'troopers.primary_section_id')
            ->where('hosts.path', 'like', "{$unit->path}%")
            ->where('homes.path', 'not like', "{$unit->path}%")
            ->whereIn('troopers.status', TrooperStatus::servingValues())
            ->whereIn('cross_section_sightings.source', CrossSectionSighting::GUEST_SOURCES)
            ->where('cross_section_sightings.week_start', '>=', $from)
            ->where('cross_section_sightings.week_start', '<=', $to)
            ->groupBy('cross_section_sightings.trooper_id')
            ->orderBy('cross_section_sightings.trooper_id')
            ->selectRaw('cross_section_sightings.trooper_id as guest_id, count(*) as visits, max(cross_section_sightings.week_start) as last_week')
            ->toBase()
            ->get()
            ->mapWithKeys(fn (object $row) => [(int) $row->guest_id => new GuestVisits(
                trooperId: (int) $row->guest_id,
                visits: (int) $row->visits,
                lastWeek: CarbonImmutable::parse($row->last_week, 'UTC'),
            )]);
    }
}
