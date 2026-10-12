<?php

namespace App\Repositories\AttendanceRepository;

use App\Models\Attendance;
use App\Models\AttendanceSource;
use App\Models\AttendanceStatus;
use App\Models\RollcallSignature;
use App\Models\SectionPractice;
use App\Models\Trooper;
use App\Models\TrooperStatus;
use App\Models\Unit;
use App\Repositories\AttendanceRepository;
use App\Repositories\CountingMark;
use App\Repositories\SectionWeekProgress;
use App\Support\Time\WeekOf;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class Eloquent implements AttendanceRepository
{
    public function markPractice(
        SectionPractice $practice,
        int $trooperId,
        AttendanceStatus $status,
        AttendanceSource $source,
    ): Attendance {
        return $this->upsert($practice, $trooperId, ['status' => $status, 'source' => $source]);
    }

    public function markPracticeAuto(SectionPractice $practice, int $trooperId): ?Attendance
    {
        $key = ['trooper_id' => $trooperId, 'section_practice_id' => $practice->id];
        $values = ['status' => AttendanceStatus::Present, 'source' => AttendanceSource::Auto];

        return DB::transaction(function () use ($key, $values) {
            $row = Attendance::query()->where($key)->lockForUpdate()->first();

            if ($row === null) {
                try {
                    return $this->realise(Attendance::query()->create($key + $values));
                } catch (UniqueConstraintViolationException $e) {
                    // A racing writer inserted the row first; a locking read sees it (cf. upsert()).
                    $row = Attendance::query()->where($key)->lockForUpdate()->first() ?? throw $e;
                }
            }

            if ($row->source === AttendanceSource::Manual || $row->status === AttendanceStatus::Loa) {
                return null;
            }

            $row->fill($values)->save();

            return $this->realise($row);
        });
    }

    public function signRollcall(
        SectionPractice $practice,
        int $trooperId,
        RollcallSignature $rollcall,
    ): Attendance {
        return $this->upsert($practice, $trooperId, ['rollcall' => $rollcall]);
    }

    public function forPractice(SectionPractice $practice): Collection
    {
        return Attendance::query()
            ->with('trooper')
            ->where('section_practice_id', $practice->id)
            ->orderBy('id')
            ->get();
    }

    public function countingMarks(Unit $unit, CarbonInterface $firstWeek, CarbonInterface $lastWeek, bool $includeDeparted = false): Collection
    {
        // A practice is held once any row on it carries a leader's SP mark. Signatures alone do
        // not hold it, and neither does `loa` — derived from LOA data, it says nothing about
        // whether the practice ran.
        $held = Attendance::query()
            ->select('section_practice_id')
            ->whereNotNull('status')
            ->where('status', '!=', AttendanceStatus::Loa->value)
            ->groupBy('section_practice_id');

        $population = $includeDeparted
            ? Trooper::query()->whereIn('troopers.primary_section_id', Unit::query()->select('id')->where('path', 'like', "{$unit->path}%"))
            : Trooper::query()->assignedWithin($unit);

        return $population
            ->join('section_practices', 'section_practices.section_id', '=', 'troopers.primary_section_id')
            ->leftJoinSub($held, 'held', 'held.section_practice_id', '=', 'section_practices.id')
            ->leftJoin('attendance', function ($join) {
                $join->on('attendance.section_practice_id', '=', 'section_practices.id')
                    ->on('attendance.trooper_id', '=', 'troopers.id');
            })
            ->whereBetween('section_practices.week_start', [
                WeekOf::requireStart($firstWeek),
                WeekOf::requireStart($lastWeek),
            ])
            ->orderBy('troopers.id')
            ->orderBy('section_practices.week_start')
            ->toBase()
            ->get([
                'troopers.id as trooper_id',
                'section_practices.week_start',
                'attendance.status',
                'attendance.rollcall',
                'attendance.source',
                DB::raw('held.section_practice_id is not null as held'),
            ])
            ->map(fn (object $row) => new CountingMark(
                trooperId: (int) $row->trooper_id,
                weekStart: CarbonImmutable::parse($row->week_start, 'UTC'),
                status: $row->status === null ? null : AttendanceStatus::from($row->status),
                // Null only when the left join found no attendance row: nobody signed for them.
                rollcall: $row->rollcall === null ? RollcallSignature::Unsigned : RollcallSignature::from($row->rollcall),
                held: (bool) $row->held,
                source: $row->source === null ? null : AttendanceSource::from($row->source),
            ));
    }

    /**
     * @param  array<string, mixed>  $values  only the columns this write owns
     */
    public function weekProgress(array $sectionIds, CarbonInterface $weekStart): Collection
    {
        $weekStart = WeekOf::requireStart($weekStart);

        if ($sectionIds === []) {
            return collect();
        }

        $unsigned = RollcallSignature::Unsigned->value;
        $loa = AttendanceStatus::Loa->value;

        return Trooper::query()
            ->where('troopers.status', '!=', TrooperStatus::Inactive->value)
            ->whereIn('troopers.primary_section_id', $sectionIds)
            ->leftJoin('section_practices', function ($join) use ($weekStart) {
                $join->on('section_practices.section_id', '=', 'troopers.primary_section_id')
                    ->where('section_practices.week_start', '=', $weekStart);
            })
            ->leftJoin('attendance', function ($join) {
                $join->on('attendance.section_practice_id', '=', 'section_practices.id')
                    ->on('attendance.trooper_id', '=', 'troopers.id');
            })
            ->groupBy('troopers.primary_section_id')
            ->toBase()
            ->get([
                'troopers.primary_section_id as section_id',
                DB::raw('count(troopers.id) as strength'),
                DB::raw("coalesce(sum(case when attendance.rollcall is not null and attendance.rollcall != '{$unsigned}' then 1 else 0 end), 0) as signed"),
                DB::raw('coalesce(sum(case when attendance.status is not null then 1 else 0 end), 0) as marked'),
                DB::raw("coalesce(sum(case when attendance.status is not null and attendance.status != '{$loa}' then 1 else 0 end), 0) as held_marks"),
            ])
            ->mapWithKeys(fn (object $row) => [(int) $row->section_id => new SectionWeekProgress(
                sectionId: (int) $row->section_id,
                strength: (int) $row->strength,
                signed: (int) $row->signed,
                marked: (int) $row->marked,
                held: (int) $row->held_marks > 0,
            )]);
    }

    private function upsert(SectionPractice $practice, int $trooperId, array $values): Attendance
    {
        $key = ['trooper_id' => $trooperId, 'section_practice_id' => $practice->id];

        try {
            $row = Attendance::query()->updateOrCreate($key, $values);
        } catch (UniqueConstraintViolationException $e) {
            // A racing submit inserted the row after this transaction's snapshot: a plain re-read
            // cannot see it under MySQL REPEATABLE READ, a locking read can (see
            // SectionPracticeRepository's findOrCreate). Update it in place, last writer wins.
            $row = Attendance::query()->where($key)->lockForUpdate()->first() ?? throw $e;
            $row->fill($values)->save();
        }

        return $this->realise($row);
    }

    /**
     * A real write over a generated demo row (#115) makes the row real, so a demo purge or
     * regeneration never deletes or overwrites what a leader recorded. `is_demo` is not
     * mass-assignable, hence the separate forced write.
     */
    private function realise(Attendance $row): Attendance
    {
        if ($row->is_demo) {
            $row->forceFill(['is_demo' => false])->save();
        }

        return $row;
    }
}
