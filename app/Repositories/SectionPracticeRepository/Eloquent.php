<?php

namespace App\Repositories\SectionPracticeRepository;

use App\Models\SectionPractice;
use App\Models\TrooperStatus;
use App\Models\Unit;
use App\Models\UnitType;
use App\Repositories\PracticeWeekStatus;
use App\Repositories\SectionPracticeRepository;
use App\Support\Time\WeekOf;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use InvalidArgumentException;

class Eloquent implements SectionPracticeRepository
{
    public function findOrCreate(int $sectionId, CarbonInterface $weekStart, bool $keepDemo = false): SectionPractice
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
        if ($practice->is_demo && ! $keepDemo) {
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

    public function weekStatuses(int $sectionId, CarbonInterface $firstWeek, CarbonInterface $lastWeek, array $excused = []): Collection
    {
        $firstWeek = WeekOf::requireStart($firstWeek);
        $lastWeek = WeekOf::requireStart($lastWeek);

        // A mark of a trooper whose leave covers that week is not counted: the caller counts them once, mark or not.
        $excusedSql = [];
        $bindings = [];
        foreach ($excused as $date => $trooperIds) {
            if ($trooperIds === []) {
                continue;
            }
            $excusedSql[] = '(section_practices.week_start = ? and attendance.trooper_id in ('.implode(', ', array_fill(0, count($trooperIds), '?')).'))';
            $bindings = [...$bindings, WeekOf::requireStart(CarbonImmutable::parse($date, 'UTC'))->toDateTimeString(), ...array_map('intval', $trooperIds)];
        }
        $notExcused = $excusedSql === [] ? '' : ' and not ('.implode(' or ', $excusedSql).')';

        return SectionPractice::query()
            ->where('section_practices.section_id', $sectionId)
            ->whereBetween('section_practices.week_start', [$firstWeek, $lastWeek])
            ->leftJoin('attendance', 'attendance.section_practice_id', '=', 'section_practices.id')
            // Only marks of troopers placed in the section now count, the same population the
            // rollcall screen lists; any row's change still makes a posted rollcall stale.
            ->leftJoin('troopers', function ($join) {
                $join->on('troopers.id', '=', 'attendance.trooper_id')
                    ->on('troopers.primary_section_id', '=', 'section_practices.section_id')
                    ->whereIn('troopers.status', TrooperStatus::servingValues());
            })
            ->groupBy('section_practices.id', 'section_practices.week_start', 'section_practices.rollcall_posted_at')
            ->withCasts(['marked' => 'integer', 'last_marked_at' => 'immutable_datetime'])
            ->select(['section_practices.id', 'section_practices.week_start', 'section_practices.rollcall_posted_at'])
            ->selectRaw("coalesce(sum(case when troopers.id is not null and attendance.status is not null{$notExcused} then 1 else 0 end), 0) as marked", $bindings)
            ->selectRaw('max(attendance.updated_at) as last_marked_at')
            ->get()
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

    public function stampCapture(SectionPractice $practice, string $serverRef, \DateTimeInterface $capturedAt, string $captureSource, ?\DateTimeInterface $windowStart = null, ?\DateTimeInterface $windowEnd = null): SectionPractice
    {
        $practice->forceFill([
            'server_ref' => $serverRef,
            'captured_at' => $capturedAt,
            'capture_source' => $captureSource,
            'window_start' => $windowStart,
            'window_end' => $windowEnd,
        ])->save();

        return $practice;
    }

    public function changeWeek(SectionPractice $practice, ?\DateTimeInterface $movedTo, bool $cancelled): SectionPractice
    {
        $practice->forceFill([
            'slot_moved_to' => $cancelled ? null : $movedTo,
            'slot_cancelled' => $cancelled,
        ])->save();

        return $practice;
    }

    public function hasPracticesFor(Unit $unit): bool
    {
        return SectionPractice::query()->where('section_id', $unit->getKey())->exists();
    }

    public function deleteIfUnused(SectionPractice $practice): bool
    {
        $nothingIn = fn (string $table) => fn ($query) => $query->selectRaw('1')
            ->from($table)
            ->whereColumn("{$table}.section_practice_id", 'section_practices.id');

        return SectionPractice::query()
            ->whereKey($practice->id)
            ->whereNull('server_ref')
            ->whereNull('captured_at')
            ->whereNull('rollcall_posted_at')
            ->whereNull('confirmed_at')
            // A week whose practice was moved or cancelled keeps that (#392).
            ->whereNull('slot_moved_to')
            ->where('slot_cancelled', false)
            ->whereNotExists($nothingIn('attendance'))
            ->whereNotExists($nothingIn('cross_section_sightings'))
            ->whereNotExists($nothingIn('practice_capture_players'))
            ->whereNotExists($nothingIn('sync_runs'))
            ->delete() > 0;
    }
}
