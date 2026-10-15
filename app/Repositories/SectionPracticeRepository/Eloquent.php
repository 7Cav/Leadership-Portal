<?php

namespace App\Repositories\SectionPracticeRepository;

use App\Models\SectionPractice;
use App\Models\TrooperStatus;
use App\Models\Unit;
use App\Models\UnitType;
use App\Repositories\PracticeWeekStatus;
use App\Repositories\SectionPracticeRepository;
use App\Support\Time\WeekOf;
use Carbon\CarbonInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class Eloquent implements SectionPracticeRepository
{
    public function findOrCreate(int $sectionId, CarbonInterface $weekStart): SectionPractice
    {
        $weekStart = WeekOf::requireStart($weekStart);

        if (! Unit::query()->whereKey($sectionId)->where('type', UnitType::Section->value)->exists()) {
            throw new InvalidArgumentException("Unit {$sectionId} is not a section; practices are per section (AD-14).");
        }

        $key = ['section_id' => $sectionId, 'week_start' => $weekStart];

        try {
            $practice = SectionPractice::query()->createOrFirst($key);
        } catch (UniqueConstraintViolationException $e) {
            // createOrFirst's own re-read is a plain (snapshot) read: inside a MySQL REPEATABLE
            // READ transaction that has already read, it cannot see a row a racing submit committed
            // after that snapshot, so it rethrows. A locking read always sees the latest committed
            // row, so the insert-or-find holds inside the audited transaction too.
            $practice = SectionPractice::query()->where($key)->sharedLock()->first() ?? throw $e;
        }

        // Found a generated demo practice (#115): a real mark is about to hang off it, so it is
        // real from now on — a demo purge or regeneration must leave it and that week alone.
        if ($practice->is_demo) {
            $practice->forceFill(['is_demo' => false])->save();
        }

        return $practice;
    }

    public function find(int $sectionId, CarbonInterface $weekStart): ?SectionPractice
    {
        return SectionPractice::query()
            ->with(['rollcallPostedBy', 'confirmedBy'])
            ->where('section_id', $sectionId)
            ->where('week_start', WeekOf::requireStart($weekStart))
            ->first();
    }

    public function forSections(array $sectionIds, CarbonInterface $weekStart): Collection
    {
        $weekStart = WeekOf::requireStart($weekStart);

        if ($sectionIds === []) {
            return collect();
        }

        return SectionPractice::query()
            ->whereIn('section_id', $sectionIds)
            ->where('week_start', $weekStart)
            ->get()
            ->keyBy('section_id')
            ->toBase();
    }

    public function weekStatuses(int $sectionId, CarbonInterface $firstWeek, CarbonInterface $lastWeek): Collection
    {
        $firstWeek = WeekOf::requireStart($firstWeek);
        $lastWeek = WeekOf::requireStart($lastWeek);

        return SectionPractice::query()
            ->where('section_practices.section_id', $sectionId)
            ->whereBetween('section_practices.week_start', [$firstWeek, $lastWeek])
            ->leftJoin('attendance', 'attendance.section_practice_id', '=', 'section_practices.id')
            // Only marks of troopers placed in the section now count, the same population the
            // rollcall screen lists; any row's change still makes a posted rollcall stale.
            ->leftJoin('troopers', function ($join) {
                $join->on('troopers.id', '=', 'attendance.trooper_id')
                    ->on('troopers.primary_section_id', '=', 'section_practices.section_id')
                    ->where('troopers.status', '!=', TrooperStatus::Inactive->value);
            })
            ->groupBy('section_practices.id', 'section_practices.week_start', 'section_practices.rollcall_posted_at')
            ->withCasts(['marked' => 'integer', 'last_marked_at' => 'immutable_datetime'])
            ->get([
                'section_practices.id',
                'section_practices.week_start',
                'section_practices.rollcall_posted_at',
                DB::raw('coalesce(sum(case when troopers.id is not null and attendance.status is not null then 1 else 0 end), 0) as marked'),
                DB::raw('max(attendance.updated_at) as last_marked_at'),
            ])
            ->mapWithKeys(fn (SectionPractice $practice) => [$practice->week_start->toDateString() => new PracticeWeekStatus(
                weekStart: $practice->week_start,
                marked: $practice->marked,
                posted: $practice->rollcall_posted_at !== null
                    && ($practice->last_marked_at === null || $practice->last_marked_at->lessThanOrEqualTo($practice->rollcall_posted_at)),
            )])
            ->toBase();
    }

    public function markRollcallPosted(SectionPractice $practice, int $userId): SectionPractice
    {
        $practice->forceFill(['rollcall_posted_at' => now(), 'rollcall_posted_by' => $userId])->save();

        return $practice->load('rollcallPostedBy');
    }

    public function confirm(SectionPractice $practice, int $userId): SectionPractice
    {
        $practice->forceFill(['confirmed_at' => now(), 'confirmed_by' => $userId])->save();

        return $practice->load('confirmedBy');
    }

    public function unconfirm(SectionPractice $practice): SectionPractice
    {
        if ($practice->confirmed_at !== null) {
            $practice->forceFill(['confirmed_at' => null, 'confirmed_by' => null])->save();
        }

        return $practice;
    }

    public function findById(int $id): ?SectionPractice
    {
        return SectionPractice::query()->find($id);
    }

    public function lockById(int $id): ?SectionPractice
    {
        return SectionPractice::query()->whereKey($id)->lockForUpdate()->first();
    }

    public function stampCapture(SectionPractice $practice, string $serverRef, \DateTimeInterface $capturedAt, string $captureSource): SectionPractice
    {
        $practice->forceFill([
            'server_ref' => $serverRef,
            'captured_at' => $capturedAt,
            'capture_source' => $captureSource,
        ])->save();

        return $practice;
    }

    public function hasPracticesFor(Unit $unit): bool
    {
        return SectionPractice::query()->where('section_id', $unit->getKey())->exists();
    }
}
