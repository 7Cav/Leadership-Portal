<?php

namespace App\Repositories\AttendanceRepository;

use App\Models\Attendance;
use App\Models\AttendanceSource;
use App\Models\AttendanceStatus;
use App\Models\RollcallSignature;
use App\Models\SectionPractice;
use App\Models\Trooper;
use App\Models\Unit;
use App\Repositories\AttendanceRepository;
use Illuminate\Database\UniqueConstraintViolationException;
use App\Repositories\CountingMark;
use App\Support\Time\WeekOf;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
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

    public function countingMarks(Unit $unit, CarbonInterface $firstWeek, CarbonInterface $lastWeek): Collection
    {
        // A practice is held once any row on it carries a leader's SP mark. Signatures alone do
        // not hold it, and neither does `loa` — derived from LOA data, it says nothing about
        // whether the practice ran.
        $held = Attendance::query()
            ->select('section_practice_id')
            ->whereNotNull('status')
            ->where('status', '!=', AttendanceStatus::Loa->value)
            ->groupBy('section_practice_id');

        return Trooper::query()
            ->assignedWithin($unit)
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
                DB::raw('held.section_practice_id is not null as held'),
            ])
            ->map(fn (object $row) => new CountingMark(
                trooperId: (int) $row->trooper_id,
                weekStart: CarbonImmutable::parse($row->week_start, 'UTC'),
                status: $row->status === null ? null : AttendanceStatus::from($row->status),
                // Null only when the left join found no attendance row: nobody signed for them.
                rollcall: $row->rollcall === null ? RollcallSignature::Unsigned : RollcallSignature::from($row->rollcall),
                held: (bool) $row->held,
            ));
    }

    /**
     * @param  array<string, mixed>  $values  only the columns this write owns
     */
    private function upsert(SectionPractice $practice, int $trooperId, array $values): Attendance
    {
        $key = ['trooper_id' => $trooperId, 'section_practice_id' => $practice->id];

        try {
            return Attendance::query()->updateOrCreate($key, $values);
        } catch (UniqueConstraintViolationException $e) {
            // A racing submit inserted the row after this transaction's snapshot: a plain re-read
            // cannot see it under MySQL REPEATABLE READ, a locking read can (see
            // SectionPracticeRepository's findOrCreate). Update it in place, last writer wins.
            $row = Attendance::query()->where($key)->lockForUpdate()->first() ?? throw $e;
            $row->fill($values)->save();

            return $row;
        }
    }
}
